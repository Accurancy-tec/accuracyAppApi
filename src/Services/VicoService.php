<?php

header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . "/../Middleware/Authentication.php";
require_once __DIR__ . "/../../database/conexao.php";


function periodoGraficoEmMeses($periodo)
{
    switch (strtoupper(trim((string) $periodo))) {
        case "1M":
            return 1;

        case "1A":
        case "12M":
            return 12;

        case "6M":
        default:
            return 6;
    }
}

/**
 * GET /vico/evolucao-carteira?periodo=1M|6M|1A[&id_carteira=][&tipo=Real|Simulada|todas]
 *
 * Valor diário das posições (quantidade do dia x último fechamento conhecido).
 * Sem carteira, considera as carteiras reais (as simuladas não entram no patrimônio).
 * Em uma carteira simulada, soma também o dinheiro livre da simulação em cada dia,
 * reconstruído a partir do saldo atual e das movimentações posteriores.
 */
function evolucaoCarteira()
{
    global $conexao;

    try {
        $idUsuario = autenticar();
        $periodo = strtoupper(trim($_GET["periodo"] ?? "6M"));
        $meses = periodoGraficoEmMeses($periodo);

        $agora = new DateTimeImmutable("now", new DateTimeZone("America/Sao_Paulo"));
        $dataFim = $agora->format("Y-m-d");
        $dataInicio = $meses === 1
            ? $agora->modify("-30 days")->format("Y-m-d")
            : $agora->modify("first day of this month")->modify("-" . ($meses - 1) . " months")->format("Y-m-d");

        $idCarteira = isset($_GET["id_carteira"]) ? (int) $_GET["id_carteira"] : null;
        $tipo = (string) ($_GET["tipo"] ?? "Real");

        $parametros = [":usuario" => $idUsuario, ":inicio" => $dataInicio, ":fim" => $dataFim];
        $condicaoCarteira = "";
        $carteiraSimulada = null;

        if ($idCarteira !== null && $idCarteira > 0) {
            $confere = $conexao->prepare(
                "SELECT tipo_carteira::text AS tipo, saldo_livre_carteira
                 FROM carteira WHERE id_carteira = :carteira AND id_usuario = :usuario AND deleted_at IS NULL"
            );
            $confere->execute([":carteira" => $idCarteira, ":usuario" => $idUsuario]);
            $carteira = $confere->fetch(PDO::FETCH_ASSOC);

            if (!$carteira) {
                http_response_code(403);
                echo json_encode(["sucesso" => false, "mensagem" => "A carteira não pertence ao usuário"]);
                return;
            }

            $condicaoCarteira = "AND ap.id_carteira = :carteira";
            $parametros[":carteira"] = $idCarteira;

            if ($carteira["tipo"] === "Simulada") {
                $carteiraSimulada = $carteira;
            }
        } elseif (in_array($tipo, ["Real", "Simulada"], true)) {
            $condicaoCarteira = "AND c.tipo_carteira = CAST(:tipo AS tipo_carteira_enum)";
            $parametros[":tipo"] = $tipo;
        }

        $sql = "
            WITH dias AS (
                SELECT generate_series(CAST(:inicio AS date), CAST(:fim AS date), interval '1 day')::date AS data
            ),
            ativos AS (
                SELECT DISTINCT ap.id_carteira, ap.id_ativo
                FROM aporte ap
                INNER JOIN carteira c ON c.id_carteira = ap.id_carteira
                WHERE c.id_usuario = :usuario
                  AND ap.status_aporte = 'Concluída'
                  $condicaoCarteira
            )
            SELECT
                d.data,
                COALESCE(SUM(GREATEST(COALESCE(qtd.quantidade, 0), 0) * COALESCE(cot.preco, apr.preco, 0)), 0) AS valor
            FROM dias d
            LEFT JOIN ativos ativo ON TRUE
            LEFT JOIN LATERAL (
                SELECT COALESCE(SUM(
                    CASE
                        WHEN ap.tipo_aporte = 'Venda' THEN -COALESCE(ap.quantidade_aporte, 0)
                        WHEN ap.tipo_aporte = 'Compra' THEN COALESCE(ap.quantidade_aporte, 0)
                        ELSE 0
                    END
                ), 0) AS quantidade
                FROM aporte ap
                WHERE ap.id_carteira = ativo.id_carteira
                  AND ap.id_ativo = ativo.id_ativo
                  AND ap.status_aporte = 'Concluída'
                  AND ap.data_aporte <= d.data
            ) qtd ON TRUE
            LEFT JOIN LATERAL (
                SELECT cota.preco_fechamento_cotacao AS preco
                FROM cotacao_ativo cota
                WHERE cota.id_ativo = ativo.id_ativo
                  AND cota.data_cotacao <= d.data
                ORDER BY cota.data_cotacao DESC
                LIMIT 1
            ) cot ON TRUE
            -- Sem cotação (cripto, renda fixa ou antes do 1º fechamento gravado): usa o preço
            -- unitário do último aporte até aquele dia, em vez de contar o ativo como R$ 0.
            LEFT JOIN LATERAL (
                SELECT ult.valor_aporte / NULLIF(ult.quantidade_aporte, 0) AS preco
                FROM aporte ult
                WHERE ult.id_carteira = ativo.id_carteira
                  AND ult.id_ativo = ativo.id_ativo
                  AND ult.status_aporte = 'Concluída'
                  AND ult.tipo_aporte IN ('Compra', 'Venda')
                  AND ult.quantidade_aporte > 0
                  AND ult.data_aporte <= d.data
                ORDER BY ult.data_aporte DESC, ult.id_aporte DESC
                LIMIT 1
            ) apr ON TRUE
            GROUP BY d.data
            ORDER BY d.data ASC
        ";

        $consulta = $conexao->prepare($sql);
        $consulta->execute($parametros);
        $linhas = $consulta->fetchAll(PDO::FETCH_ASSOC);

        $caixaPorDia = $carteiraSimulada !== null
            ? caixaDiarioDaSimulacao($conexao, (int) $idCarteira, (float) $carteiraSimulada["saldo_livre_carteira"], array_column($linhas, "data"))
            : [];

        $pontos = [];
        foreach ($linhas as $linha) {
            $pontos[] = [
                "data" => $linha["data"],
                "valor" => round((float) $linha["valor"] + ($caixaPorDia[$linha["data"]] ?? 0), 2)
            ];
        }

        echo json_encode(["sucesso" => true, "periodo" => $periodo, "pontos" => $pontos]);
    } catch (Throwable $erro) {
        error_log("Erro ao gerar evolução da carteira: " . $erro->getMessage());
        http_response_code(500);

        echo json_encode([
            "sucesso" => false,
            "mensagem" => "Erro ao gerar evolução da carteira.",
            "pontos" => []
        ]);
    }
}

/**
 * Dinheiro livre de uma carteira simulada no fim de cada dia: parte do saldo de hoje
 * e desfaz as movimentações feitas depois daquele dia (depósitos, compras, vendas e dividendos).
 */
function caixaDiarioDaSimulacao(PDO $conexao, int $idCarteira, float $saldoAtual, array $dias): array
{
    $stmt = $conexao->prepare("
        SELECT data_aporte AS data,
               CASE WHEN tipo_aporte = 'Compra' THEN -valor_aporte ELSE valor_aporte END AS fluxo
        FROM aporte
        WHERE id_carteira = :carteira AND status_aporte = 'Concluída'
        UNION ALL
        SELECT data_transacao::date, valor
        FROM transacao_saldo
        WHERE id_carteira = :carteira_deposito AND tipo_transacao = 'Deposito'
    ");
    $stmt->execute([":carteira" => $idCarteira, ":carteira_deposito" => $idCarteira]);
    $movimentos = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $caixa = [];
    foreach ($dias as $dia) {
        $depois = 0.0;
        foreach ($movimentos as $movimento) {
            if ($movimento["data"] > $dia) {
                $depois += (float) $movimento["fluxo"];
            }
        }
        $caixa[$dia] = max(0.0, $saldoAtual - $depois);
    }

    return $caixa;
}

function gerarGrafico()
{
    global $conexao;

    try {
        $id_usuario = autenticar();

        $sql = $conexao->prepare("
            SELECT
                at.simbolo_ativo AS ativo_aporte,
                a.valor_aporte AS preco_aporte
            FROM Aporte a
            INNER JOIN Carteira c
                ON c.id_carteira = a.id_carteira
            INNER JOIN Ativo at
                ON at.id_ativo = a.id_ativo
            WHERE c.id_usuario = ?
            ORDER BY a.id_aporte ASC
            LIMIT 4
        ");

        $sql->execute([$id_usuario]);

        $resposta = $sql->fetchAll(PDO::FETCH_ASSOC);
        $dados = [];

        foreach ($resposta as $row) {
            $dados[] = [
                "ativo_aporte" => $row["ativo_aporte"],
                "preco_aporte" => (float) $row["preco_aporte"]
            ];
        }

        echo json_encode(["dados" => $dados]);
    } catch (Throwable $e) {
        echo json_encode([
            "dados" => [],
            "erro" => $e->getMessage()
        ]);
    }
}