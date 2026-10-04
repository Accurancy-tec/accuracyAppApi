<?php

require_once __DIR__ . "/../../database/conexao.php";

function calcularProximaExecucao($dataAtual, $frequencia)
{
    switch ($frequencia) {

        case "Diário":
            return date("Y-m-d", strtotime($dataAtual . " +1 day"));

        case "Semanal":
            return date("Y-m-d", strtotime($dataAtual . " +7 days"));

        case "Mensal":
            return date("Y-m-d", strtotime($dataAtual . " +1 month"));

        case "Anual":
            return date("Y-m-d", strtotime($dataAtual . " +1 year"));

        default:
            throw new Exception(
                "Frequência inválida: " . $frequencia
            );
    }
}

function execAportesRecorrentes(PDO $conexao)
{
    global $conexao;

    $resultados = [
        "processados" => 0,
        "erros" => 0,
        "detalhes" => []
    ];

    $aportes = getAportesRecorrentesService($conexao);

    foreach ($aportes as $aporteRecorrente) {

        try {

            $conexao->beginTransaction();

            $idAporteRecorrente =
                $aporteRecorrente["id_aporte_recorrente"];

            $idCarteira =
                $aporteRecorrente["id_carteira"];

            $idAtivo =
                $aporteRecorrente["id_ativo"];

            $valorRecorrente =
                (float) $aporteRecorrente["valor_recorrente"];

            $frequencia =
                $aporteRecorrente["frequencia_recorrente"];

            $dataExecucao =
                $aporteRecorrente["proxima_execucao_recorrente"];


            // Busca a cotação mais recente do ativo
            $cotacao = getUltimaCotacaoService($conexao, $idAtivo);


            if (!$cotacao) {
                throw new Exception(
                    "Não existe cotação cadastrada para o ativo."
                );
            }


            $precoAtual = (float) $cotacao["preco_fechamento_cotacao"];


            if ($precoAtual <= 0) {
                throw new Exception(
                    "A cotação do ativo é inválida."
                );
            }

            // Calcula quantas unidades serão compradas
            $quantidade =
                $valorRecorrente / $precoAtual;

            if ($quantidade <= 0) {
                throw new Exception(
                    "Quantidade calculada inválida."
                );
            }

            //Cria o aporte.

            $tipoAporte = "Compra";
            $observacacao = "Aporte recorrente automatico";

            $insert = criarAporteService($conexao, $idCarteira, $idAtivo, $tipoAporte, $quantidade, $valorRecorrente, $frequencia, $observacacao, $dataExecucao);


            // Atualiza a posição da carteira
            $posicaoAtualizada = atualizarPosicaoService(
                $conexao,
                $idCarteira,
                $idAtivo,
                $tipoAporte,
                $quantidade,
                $valorRecorrente
            );


            if (!$posicaoAtualizada) {
                throw new Exception(
                    "Não foi possível atualizar a posição da carteira."
                );
            }

            // Calcula a próxima execução
            $proximaExecucao = calcularProximaExecucao($dataExecucao, $frequencia);

            // Atualiza o aporte recorrente
            $update = updateAporteRecorrenteService($conexao, $proximaExecucao, $idAporteRecorrente);

            $conexao->commit();

            $resultados["processados"]++;

            $resultados["detalhes"][] = [
                "id_aporte_recorrente" => $idAporteRecorrente,
                "status" => "sucesso",
                "valor" => $valorRecorrente,
                "quantidade" => $quantidade,
                "preco" => $precoAtual,
                "proxima_execucao" => $proximaExecucao
            ];
        } catch (Throwable $erro) {

            if ($conexao->inTransaction()) {
                $conexao->rollBack();
            }

            $resultados["erros"]++;

            $resultados["detalhes"][] = [
                "id_aporte_recorrente" => $aporteRecorrente["id_aporte_recorrente"],
                "status" => "erro",
                "mensagem" => $erro->getMessage()
            ];
        }
    }

    return $resultados;
}

function realizarAportesRecorrentes()
{
    global $conexao;
    try {

        $cronKey = $_SERVER["HTTP_X_CRON_KEY"] ?? "";

        if (
            empty($_ENV["CRON_SECRET"]) ||
            !hash_equals($_ENV["CRON_SECRET"], $cronKey)
        ) {
            http_response_code(401);

            echo json_encode([
                "sucesso" => false,
                "mensagem" => "Chave do cron inválida"
            ]);

            exit;
        }


        if ($_SERVER["REQUEST_METHOD"] !== "POST") {

            http_response_code(405);

            echo json_encode([
                "sucesso" => false,
                "mensagem" => "Método não permitido"
            ]);

            exit;
        }


        $resultado = execAportesRecorrentes($conexao);


        echo json_encode([
            "sucesso" => true,
            "mensagem" => "Aportes recorrentes processados",
            "resultado" => $resultado
        ]);
    } catch (Throwable $erro) {

        http_response_code(500);

        echo json_encode([
            "sucesso" => false,
            "mensagem" => "Erro interno: " . $erro->getMessage()
        ]);
    }
}

const CRON_FUSO = "America/Sao_Paulo";

// Ativo com cotação automática não pode ser comprado com preço mais velho que isso
const CRON_MAX_IDADE_COTACAO_DIAS = 3;

// Limite de execuções atrasadas por aporte recorrente em uma rodada (segurança)
const CRON_MAX_EXECUCOES_POR_RODADA = 62;

// Quantos tickers vão em cada chamada para a Brapi
const CRON_LOTE_BRAPI = 10;


// Data de hoje no horário de Brasília (o CURRENT_DATE do banco usa UTC)
function dataHojeCron()
{
    return (new DateTimeImmutable("now", new DateTimeZone(CRON_FUSO)))->format("Y-m-d");
}


// Aceita "Diário", "diario", "Mensal", "monthly"... e devolve um nome padrão
function normalizarFrequenciaCron($frequencia)
{
    $texto = strtr((string) $frequencia, [
        "Á" => "a",
        "À" => "a",
        "Ã" => "a",
        "Â" => "a",
        "á" => "a",
        "à" => "a",
        "ã" => "a",
        "â" => "a",
        "É" => "e",
        "é" => "e",
        "Ê" => "e",
        "ê" => "e",
        "Í" => "i",
        "í" => "i",
        "Ó" => "o",
        "ó" => "o",
        "Õ" => "o",
        "õ" => "o",
        "Ô" => "o",
        "ô" => "o",
        "Ú" => "u",
        "ú" => "u",
        "Ç" => "c",
        "ç" => "c"
    ]);

    $texto = strtolower(trim($texto));

    $mapa = [
        "diario" => "diario",
        "daily"   => "diario",
        "semanal" => "semanal",
        "weekly"  => "semanal",
        "mensal" => "mensal",
        "monthly" => "mensal",
        "anual" => "anual",
        "yearly"  => "anual",
        "annual" => "anual"
    ];

    if (!isset($mapa[$texto])) {
        throw new Exception("Frequência inválida: " . $frequencia);
    }

    return $mapa[$texto];
}


// Próxima data do aporte recorrente.
// Mensal usa o dia_referencia (se o mês for curto, cai no último dia do mês), por isso
// 31/01 -> 28/02 -> 31/03. O strtotime("+1 month") antigo fazia 31/01 -> 03/03 e o dia ficava "preso".
function proximaDataRecorrenteCron($dataBase, $frequencia, $diaReferencia = null)
{
    $tipo = normalizarFrequenciaCron($frequencia);
    $base = new DateTimeImmutable($dataBase);

    if ($tipo === "diario") {
        return $base->modify("+1 day")->format("Y-m-d");
    }

    if ($tipo === "semanal") {
        return $base->modify("+7 days")->format("Y-m-d");
    }

    $ano = (int) $base->format("Y");
    $mes = (int) $base->format("n");
    $dia = (int) $base->format("j");

    if ($tipo === "mensal") {

        $mes++;

        if ($mes > 12) {
            $mes = 1;
            $ano++;
        }

        $diaDesejado = ($diaReferencia !== null && (int) $diaReferencia >= 1 && (int) $diaReferencia <= 31)
            ? (int) $diaReferencia
            : $dia;
    } else {
        // anual
        $ano++;
        $diaDesejado = $dia;
    }

    $ultimoDia = (int) (new DateTimeImmutable(sprintf("%04d-%02d-01", $ano, $mes)))->format("t");

    return sprintf("%04d-%02d-%02d", $ano, $mes, min($diaDesejado, $ultimoDia));
}


// Busca o preço de vários tickers na Brapi. Devolve [ "PETR4" => 38.5, ... ].
// Não usa o BrapiService.php porque ele chama die() quando o curl falha, e isso mataria o cron inteiro.
function buscarPrecosBrapiCron(array $simbolos)
{
    $base = $_ENV["BRAPI_BASE_URL"] ?? getenv("BRAPI_BASE_URL");
    $token = $_ENV["BRAPI_TOKEN"] ?? getenv("BRAPI_TOKEN");

    if (!$base || !$token) {
        throw new Exception("BRAPI_BASE_URL ou BRAPI_TOKEN não configurados.");
    }

    // mesmo endpoint que o getQuote.php já usa
    $url = rtrim($base, "/") . "/v2/stocks/quote?symbols=" . urlencode(implode(",", $simbolos));

    $curl = curl_init($url);

    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => [
            "Authorization: Bearer " . $token,
            "Accept: application/json"
        ]
    ]);

    $resposta = curl_exec($curl);
    $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $erroCurl = curl_error($curl);
    curl_close($curl);

    if ($resposta === false) {
        throw new Exception("Falha ao chamar a Brapi: " . $erroCurl);
    }

    if ($status !== 200) {
        throw new Exception("Brapi respondeu HTTP " . $status);
    }

    $json = json_decode($resposta, true);

    if (!is_array($json) || !isset($json["results"]) || !is_array($json["results"])) {
        throw new Exception("Resposta inesperada da Brapi.");
    }

    // formato igual ao que o app Android lê: results[].requestedSymbol e results[].data.regularMarketPrice
    $precos = [];

    foreach ($json["results"] as $item) {

        $simbolo = $item["requestedSymbol"] ?? ($item["symbol"] ?? null);
        $preco = $item["data"]["regularMarketPrice"] ?? null;

        if ($simbolo !== null && is_numeric($preco)) {
            $precos[strtoupper($simbolo)] = (float) $preco;
        }
    }

    return $precos;
}


// Passo 1: grava a cotação de hoje dos ativos marcados como cotacao_automatica_ativo.
// Só busca os que importam (têm aporte recorrente ativo ou posição na carteira) para economizar a cota da Brapi.
function atualizarCotacoesAutomaticasCron(PDO $conexao)
{
    $resultado = [
        "atualizados" => 0,
        "erros" => 0,
        "detalhes" => []
    ];

    $hoje = dataHojeCron();

    $sql = "
        SELECT a.id_ativo, a.simbolo_ativo
        FROM ativo a
        WHERE a.cotacao_automatica_ativo::int = 1
          AND (
                EXISTS (
                    SELECT 1 FROM aporte_recorrente r
                    WHERE r.id_ativo = a.id_ativo
                      AND r.ativo_flag_recorrente::int = 1
                )
                OR EXISTS (
                    SELECT 1 FROM item_investimento i
                    WHERE i.id_ativo = a.id_ativo
                      AND i.quantidade_item > 0
                )
              )
        ORDER BY a.id_ativo
    ";

    $ativos = $conexao->query($sql)->fetchAll(PDO::FETCH_ASSOC);

    foreach (array_chunk($ativos, CRON_LOTE_BRAPI) as $lote) {

        $simbolos = array_column($lote, "simbolo_ativo");

        try {
            $precos = buscarPrecosBrapiCron($simbolos);
        } catch (Throwable $erro) {

            foreach ($lote as $ativo) {
                $resultado["erros"]++;
                $resultado["detalhes"][] = [
                    "ativo" => $ativo["simbolo_ativo"],
                    "status" => "erro",
                    "mensagem" => $erro->getMessage()
                ];
            }

            continue;
        }

        foreach ($lote as $ativo) {

            $chave = strtoupper($ativo["simbolo_ativo"]);
            $preco = $precos[$chave] ?? null;

            if ($preco === null || $preco <= 0) {
                $resultado["erros"]++;
                $resultado["detalhes"][] = [
                    "ativo" => $ativo["simbolo_ativo"],
                    "status" => "erro",
                    "mensagem" => "Brapi não devolveu um preço válido."
                ];
                continue;
            }

            try {
                salvarCotacaoService($conexao, $ativo["id_ativo"], $hoje, $preco);
                $resultado["atualizados"]++;
            } catch (Throwable $erro) {
                $resultado["erros"]++;
                $resultado["detalhes"][] = [
                    "ativo" => $ativo["simbolo_ativo"],
                    "status" => "erro",
                    "mensagem" => $erro->getMessage()
                ];
            }
        }
    }

    return $resultado;
}


// Passo 2: executa os aportes recorrentes que já chegaram na data.
function processarAportesRecorrentesCron(PDO $conexao)
{
    $resultado = [
        "processados" => 0,
        "aportes_criados" => 0,
        "ignorados" => 0,
        "erros" => 0,
        "detalhes" => []
    ];

    $hoje = dataHojeCron();

    // ::int funciona tanto se a coluna for boolean quanto smallint/integer
    $busca = $conexao->prepare("
        SELECT id_aporte_recorrente
        FROM aporte_recorrente
        WHERE ativo_flag_recorrente::int = 1
          AND proxima_execucao_recorrente <= :hoje
        ORDER BY proxima_execucao_recorrente, id_aporte_recorrente
    ");
    $busca->execute([":hoje" => $hoje]);

    $ids = $busca->fetchAll(PDO::FETCH_COLUMN);

    foreach ($ids as $idRecorrente) {

        try {

            $conexao->beginTransaction();

            // Trava a linha. Se outra execução do cron estiver processando a mesma linha,
            // o SKIP LOCKED pula e o aporte não é criado em duplicidade.
            // A condição de data é conferida de novo aqui, já com a linha travada.
            $stmt = $conexao->prepare("
                SELECT
                    r.id_aporte_recorrente,
                    r.id_carteira,
                    r.id_ativo,
                    r.valor_recorrente,
                    r.frequencia_recorrente,
                    r.dia_referencia_recorrente,
                    r.proxima_execucao_recorrente,
                    a.cotacao_automatica_ativo::int AS cotacao_automatica
                FROM aporte_recorrente r
                JOIN ativo a ON a.id_ativo = r.id_ativo
                WHERE r.id_aporte_recorrente = :id
                  AND r.ativo_flag_recorrente::int = 1
                  AND r.proxima_execucao_recorrente <= :hoje
                FOR UPDATE OF r SKIP LOCKED
            ");
            $stmt->execute([":id" => $idRecorrente, ":hoje" => $hoje]);

            $recorrente = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$recorrente) {
                $conexao->rollBack();
                $resultado["ignorados"]++;
                continue;
            }

            $idCarteira = $recorrente["id_carteira"];
            $idAtivo = $recorrente["id_ativo"];
            $valor = round((float) $recorrente["valor_recorrente"], 2);
            $frequencia = $recorrente["frequencia_recorrente"];
            $diaReferencia = $recorrente["dia_referencia_recorrente"];
            $dataExecucao = $recorrente["proxima_execucao_recorrente"];

            if ($valor <= 0) {
                throw new Exception("Valor do aporte recorrente inválido.");
            }

            // Cotação mais recente
            $cotacao = getUltimaCotacaoService($conexao, $idAtivo);

            if (!$cotacao) {
                throw new Exception("Não existe cotação cadastrada para o ativo.");
            }

            $preco = (float) $cotacao["preco_fechamento_cotacao"];

            if ($preco <= 0) {
                throw new Exception("A cotação do ativo é inválida.");
            }

            // Ativo automático com cotação velha = a atualização falhou. Melhor adiar do que comprar no preço errado.
            if ((int) $recorrente["cotacao_automatica"] === 1) {

                $idadeDias = (strtotime($hoje) - strtotime($cotacao["data_cotacao"])) / 86400;

                if ($idadeDias > CRON_MAX_IDADE_COTACAO_DIAS) {
                    throw new Exception(
                        "Cotação desatualizada (última em " . $cotacao["data_cotacao"] . "). Aporte adiado."
                    );
                }
            }

            $quantidade = round($valor / $preco, 8);

            if ($quantidade <= 0) {
                throw new Exception("Quantidade calculada inválida.");
            }

            $insert = $conexao->prepare("
                INSERT INTO aporte (
                    id_carteira,
                    id_ativo,
                    tipo_aporte,
                    quantidade_aporte,
                    valor_aporte,
                    recorrencia_aporte,
                    observacao_aporte,
                    data_aporte
                )
                VALUES (
                    :carteira,
                    :ativo,
                    :tipo,
                    :quantidade,
                    :valor,
                    :recorrencia,
                    :observacao,
                    :data
                )
            ");

            // Se o servidor/cron ficou parado, executa cada data atrasada (uma por vez),
            // cada aporte com a sua data, até alcançar hoje.
            $criados = 0;

            while ($dataExecucao <= $hoje && $criados < CRON_MAX_EXECUCOES_POR_RODADA) {

                $insert->execute([
                    ":carteira" => $idCarteira,
                    ":ativo" => $idAtivo,
                    ":tipo" => "Compra",
                    ":quantidade" => $quantidade,
                    ":valor" => $valor,
                    ":recorrencia" => $frequencia,
                    ":observacao" => "Aporte recorrente automático",
                    ":data" => $dataExecucao
                ]);

                $posicaoAtualizada = atualizarPosicaoService(
                    $conexao,
                    $idCarteira,
                    $idAtivo,
                    "Compra",
                    $quantidade,
                    $valor
                );

                if (!$posicaoAtualizada) {
                    throw new Exception("Não foi possível atualizar a posição da carteira.");
                }

                $dataExecucao = proximaDataRecorrenteCron($dataExecucao, $frequencia, $diaReferencia);
                $criados++;
            }

            $update = $conexao->prepare("
                UPDATE aporte_recorrente
                SET proxima_execucao_recorrente = :proxima
                WHERE id_aporte_recorrente = :id
            ");
            $update->execute([":proxima" => $dataExecucao, ":id" => $idRecorrente]);

            $conexao->commit();

            $resultado["processados"]++;
            $resultado["aportes_criados"] += $criados;
            $resultado["detalhes"][] = [
                "id_aporte_recorrente" => $idRecorrente,
                "status" => "sucesso",
                "aportes_criados" => $criados,
                "valor" => $valor,
                "preco" => $preco,
                "proxima_execucao" => $dataExecucao
            ];
        } catch (Throwable $erro) {

            if ($conexao->inTransaction()) {
                $conexao->rollBack();
            }

            $resultado["erros"]++;
            $resultado["detalhes"][] = [
                "id_aporte_recorrente" => $idRecorrente,
                "status" => "erro",
                "mensagem" => $erro->getMessage()
            ];
        }
    }

    return $resultado;
}


// Roda tudo na ordem certa: primeiro as cotações, depois os aportes (que usam essas cotações).
// Se a atualização de cotações falhar, os aportes ainda rodam e a trava de cotação velha protege o preço.
function executarAutomacoesCron(PDO $conexao)
{
    try {
        $cotacoes = atualizarCotacoesAutomaticasCron($conexao);
    } catch (Throwable $erro) {
        $cotacoes = [
            "atualizados" => 0,
            "erros" => 1,
            "detalhes" => [["status" => "erro", "mensagem" => $erro->getMessage()]]
        ];
    }

    $aportes = processarAportesRecorrentesCron($conexao);

    return [
        "sucesso" => ($cotacoes["erros"] === 0 && $aportes["erros"] === 0),
        "cotacoes" => $cotacoes,
        "aportes_recorrentes" => $aportes
    ];
}

function executarAutomacoes()
{
    global $conexao;
    // A rodada pode demorar (Brapi + vários aportes) e o padrão do Apache é 30s.
    // ignore_user_abort: se quem chamou desistir, a rodada termina mesmo assim.
    set_time_limit(120);
    ignore_user_abort(true);

    try {

        $cronKey = $_SERVER["HTTP_X_CRON_KEY"] ?? "";

        if (
            empty($_ENV["CRON_SECRET"]) ||
            !hash_equals($_ENV["CRON_SECRET"], $cronKey)
        ) {
            http_response_code(401);

            echo json_encode([
                "sucesso" => false,
                "mensagem" => "Chave do cron inválida"
            ]);

            exit;
        }

        if ($_SERVER["REQUEST_METHOD"] !== "POST") {
            http_response_code(405);

            echo json_encode([
                "sucesso" => false,
                "mensagem" => "Método não permitido"
            ]);

            exit;
        }

        // "sucesso" vem false se algum ativo ou aporte deu erro, assim o GitHub Actions fica vermelho
        echo json_encode(executarAutomacoesCron($conexao));
    } catch (Throwable $erro) {

        http_response_code(500);

        echo json_encode([
            "sucesso" => false,
            "mensagem" => "Erro interno: " . $erro->getMessage()
        ]);
    }
}
