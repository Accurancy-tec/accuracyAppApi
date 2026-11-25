<?php

// Patrimônio das carteiras do usuário: busca as posições, precifica cada ativo
// e consolida os números. É a única fonte desses valores para o site e o app.

require_once __DIR__ . "/../Repositories/DetalheAporteRepository.php";
require_once __DIR__ . "/../Repositories/PatrimonioRepository.php";
require_once __DIR__ . "/CalculoPatrimonioService.php";
require_once __DIR__ . "/IndicadoresService.php";
require_once __DIR__ . "/../Controllers/BrapiController.php";
require_once __DIR__ . "/../Controllers/CriptoController.php";
require_once __DIR__ . "/../Controllers/CambioController.php";
require_once __DIR__ . "/../Controllers/AutomacaoController.php";

const PATRIMONIO_LOTE_COTACOES = 10;

/** Tickers da B3 (ações, FIIs, BDRs e ETFs): 4 letras seguidas de 1 ou 2 dígitos, com F opcional. */
function simboloNegociadoNaB3(string $simbolo): bool
{
    return preg_match('/^[A-Z0-9]{4}\d{1,2}F?$/', $simbolo) === 1;
}

/** Lê o preço de um item da brapi (o formato muda entre /quote e /v2/stocks/quote). */
function precoDoItemBrapi(array $item): ?float
{
    $dados = isset($item["data"]) && is_array($item["data"]) ? $item["data"] : $item;
    $preco = $dados["regularMarketPrice"] ?? null;

    return is_numeric($preco) && (float) $preco > 0 ? (float) $preco : null;
}

/** Cotações atuais de ações, FIIs e BDRs. Devolve [SIMBOLO => preço]; quem falhar fica de fora. */
function cotacoesAtuaisB3(array $simbolos): array
{
    $simbolos = array_values(array_unique(array_filter($simbolos, "simboloNegociadoNaB3")));
    $precos = [];

    $consultar = static function (array $lote) use (&$precos): bool {
        $resposta = brapiRequest("quote/" . implode(",", $lote));

        if (!$resposta["ok"]) {
            return false;
        }

        $json = json_decode((string) $resposta["body"], true);

        foreach ($json["results"] ?? [] as $item) {
            if (!is_array($item)) {
                continue;
            }

            $simbolo = strtoupper((string) ($item["symbol"] ?? $item["requestedSymbol"] ?? ""));
            $preco = precoDoItemBrapi($item);

            if ($simbolo !== "" && $preco !== null) {
                $precos[$simbolo] = $preco;
            }
        }

        return true;
    };

    foreach (array_chunk($simbolos, PATRIMONIO_LOTE_COTACOES) as $lote) {
        // Um ticker inválido derruba o lote inteiro na brapi: nesse caso tenta um por um.
        if (!$consultar($lote) && count($lote) > 1) {
            foreach ($lote as $simbolo) {
                $consultar([$simbolo]);
            }
        }
    }

    return $precos;
}

/** Moedas estrangeiras (a categoria Internacional também guarda USD, EUR...): preço em reais. */
function cotacoesAtuaisMoedas(array $codigos): array
{
    $precos = [];

    foreach (cambioCotacoes($codigos)["mapa"] as $codigo => $cotacao) {
        $precos[$codigo] = (float) $cotacao["preco"];
    }

    return $precos;
}

function cotacoesAtuaisCripto(array $simbolos): array
{
    $precos = [];

    foreach (criptoBuscarCotacoes(array_values(array_unique($simbolos))) as $simbolo => $item) {
        $preco = precoDoItemBrapi($item);

        if ($preco !== null) {
            $precos[$simbolo] = $preco;
        }
    }

    return $precos;
}

/**
 * Valor estimado hoje de cada posição em renda fixa, somando o rendimento de cada aplicação.
 *
 * @return array ["id_carteira:id_ativo" => valor]
 */
function valoresAtuaisRendaFixa(array $aplicacoes, string $hoje): array
{
    if (!$aplicacoes) {
        return [];
    }

    $indicadores = indicadoresEconomicos();
    $valores = [];

    foreach ($aplicacoes as $aplicacao) {
        $chave = (int) $aplicacao["id_carteira"] . ":" . (int) $aplicacao["id_ativo"];

        $valores[$chave] = ($valores[$chave] ?? 0) + valorAtualRendaFixa(
            (float) $aplicacao["valor_aporte"],
            (string) $aplicacao["data_aporte"],
            $aplicacao["vencimento"] ?? null,
            (string) $aplicacao["indexador"],
            (float) $aplicacao["taxa"],
            $indicadores,
            $hoje
        );
    }

    return $valores;
}

/**
 * Define preco_atual e origem_preco de cada posição.
 * Ordem: cotação ao vivo -> rendimento estimado (renda fixa) -> último fechamento -> preço médio.
 */
function precificarPosicoes(PDO $conexao, int $idUsuario, ?int $idCarteira, array $posicoes, bool $cotacaoAoVivo): array
{
    $simbolosB3 = [];
    $simbolosCripto = [];
    $moedas = [];

    foreach ($posicoes as $posicao) {
        $simbolo = strtoupper((string) $posicao["simbolo_ativo"]);
        $categoria = $posicao["categoria_ativo"];

        if (in_array($categoria, CATEGORIAS_COM_COTACAO_DE_MERCADO, true) && simboloNegociadoNaB3($simbolo)) {
            $simbolosB3[] = $simbolo;
        } elseif ($categoria === "Internacional" && cambioCodigoValido($simbolo)) {
            $moedas[] = $simbolo;
        } elseif ($categoria === "Cripto") {
            $simbolosCripto[] = $simbolo;
        }
    }

    $aoVivo = [];

    if ($cotacaoAoVivo) {
        $aoVivo = cotacoesAtuaisB3($simbolosB3)
            + cotacoesAtuaisCripto($simbolosCripto)
            + ($moedas ? cotacoesAtuaisMoedas($moedas) : []);
    }

    $rendaFixa = valoresAtuaisRendaFixa(
        buscarAplicacoesRendaFixa($conexao, $idUsuario, $idCarteira),
        dataHojeCron()
    );

    foreach ($posicoes as &$posicao) {
        $simbolo = strtoupper((string) $posicao["simbolo_ativo"]);
        $quantidade = (float) $posicao["quantidade_item"];
        $chaveRendaFixa = (int) $posicao["id_carteira"] . ":" . (int) $posicao["id_ativo"];

        if (isset($aoVivo[$simbolo])) {
            $posicao["preco_atual"] = $aoVivo[$simbolo];
            $posicao["origem_preco"] = "mercado";
        } elseif ($posicao["categoria_ativo"] === CATEGORIA_RENDA_FIXA && isset($rendaFixa[$chaveRendaFixa]) && $quantidade > 0) {
            $posicao["preco_atual"] = $rendaFixa[$chaveRendaFixa] / $quantidade;
            $posicao["origem_preco"] = "estimado";
        } elseif (is_numeric($posicao["ultimo_fechamento"]) && (float) $posicao["ultimo_fechamento"] > 0) {
            $posicao["preco_atual"] = (float) $posicao["ultimo_fechamento"];
            $posicao["origem_preco"] = "fechamento";
        } else {
            $posicao["preco_atual"] = (float) $posicao["preco_medio_item"];
            $posicao["origem_preco"] = "custo";
        }
    }
    unset($posicao);

    return $posicoes;
}

/**
 * Patrimônio completo do usuário (ou de uma carteira dele).
 *
 * Opções: id_carteira (int|null), cotacao_ao_vivo (bool, padrão true; o cron usa false
 * para trabalhar só com os fechamentos já gravados).
 */
function montarPatrimonioUsuario(PDO $conexao, int $idUsuario, array $opcoes = []): array
{
    $idCarteira = isset($opcoes["id_carteira"]) ? (int) $opcoes["id_carteira"] : null;
    $cotacaoAoVivo = $opcoes["cotacao_ao_vivo"] ?? true;

    $carteiras = buscarCarteirasDetalhadas($conexao, $idUsuario, $idCarteira);
    $posicoes = precificarPosicoes(
        $conexao,
        $idUsuario,
        $idCarteira,
        buscarPosicoesAbertas($conexao, $idUsuario, $idCarteira),
        $cotacaoAoVivo
    );
    $realizado = calcularResultadoRealizado(buscarOperacoesCronologicas($conexao, $idUsuario, $idCarteira));

    $posicoesPorCarteira = [];
    foreach ($posicoes as $posicao) {
        $posicoesPorCarteira[(int) $posicao["id_carteira"]][] = $posicao;
    }

    $consolidadas = [];
    foreach ($carteiras as $carteira) {
        $id = (int) $carteira["id_carteira"];
        $consolidadas[] = consolidarCarteira($carteira, $posicoesPorCarteira[$id] ?? [], $realizado[$id] ?? []);
    }

    $reais = array_values(array_filter($consolidadas, static fn($c) => $c["tipo_carteira"] !== "Simulada"));

    return array_merge(
        resumirCarteiras($consolidadas, buscarSaldoGeralUsuario($conexao, $idUsuario)),
        [
            "carteiras" => $consolidadas,
            "distribuicao" => distribuicaoPorCategoria($idCarteira !== null ? $consolidadas : $reais),
            "atualizado_em" => (new DateTimeImmutable("now", new DateTimeZone("America/Sao_Paulo")))->format(DATE_ATOM),
        ]
    );
}
