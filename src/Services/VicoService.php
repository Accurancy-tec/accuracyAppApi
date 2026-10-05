<?php

header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . "/../Middleware/Authentication.php";
require_once __DIR__ . "/../../database/conexao.php";

function periodoGraficoEmDias($periodo)
{
    switch (strtoupper(trim((string) $periodo))) {
        case "1M":
            return 30;

        case "1A":
        case "12M":
            return 365;

        case "6M":
        default:
            return 180;
    }
}

function evolucaoCarteira()
{
    global $conexao;

    try {
        $idUsuario = autenticar();
        $periodo = strtoupper(trim($_GET["periodo"] ?? "6M"));
        $dias = periodoGraficoEmDias($periodo);

        $agora = new DateTimeImmutable(
            "now",
            new DateTimeZone("America/Sao_Paulo")
        );

        $dataFim = $agora->format("Y-m-d");
        $dataInicio = $agora
            ->modify("-" . ($dias - 1) . " days")
            ->format("Y-m-d");

        $idCarteira = isset($_GET["id_carteira"])
            ? (int) $_GET["id_carteira"]
            : null;

        $condicaoCarteira = "";
        $parametros = [
            ":usuario" => $idUsuario,
            ":inicio" => $dataInicio,
            ":fim" => $dataFim
        ];

        if ($idCarteira !== null && $idCarteira > 0) {
            $confere = $conexao->prepare(
                "SELECT 1
                 FROM carteira
                 WHERE id_carteira = :carteira
                   AND id_usuario = :usuario
                 LIMIT 1"
            );
            $confere->execute([
                ":carteira" => $idCarteira,
                ":usuario" => $idUsuario
            ]);

            if (!$confere->fetchColumn()) {
                http_response_code(403);
                echo json_encode([
                    "sucesso" => false,
                    "mensagem" => "A carteira não pertence ao usuário"
                ]);
                return;
            }

            $condicaoCarteira = "AND ap.id_carteira = :carteira";
            $parametros[":carteira"] = $idCarteira;
        }

        $sql = "
            WITH dias AS (
                SELECT generate_series(
                    CAST(:inicio AS date),
                    CAST(:fim AS date),
                    interval '1 day'
                )::date AS data
            ),
            ativos AS (
                SELECT DISTINCT
                    ap.id_carteira,
                    ap.id_ativo
                FROM aporte ap
                INNER JOIN carteira c
                    ON c.id_carteira = ap.id_carteira
                WHERE c.id_usuario = :usuario
                  AND ap.status_aporte = 'Concluída'
                  $condicaoCarteira
            )
            SELECT
                d.data,
                COALESCE(
                    SUM(
                        GREATEST(
                            COALESCE(qtd.quantidade, 0),
                            0
                        ) * COALESCE(cot.preco, 0)
                    ),
                    0
                ) AS valor
            FROM dias d
            LEFT JOIN ativos ativo
                ON TRUE
            LEFT JOIN LATERAL (
                SELECT
                    COALESCE(
                        SUM(
                            CASE
                                WHEN ap.tipo_aporte = 'Venda'
                                    THEN -COALESCE(ap.quantidade_aporte, 0)
                                WHEN ap.tipo_aporte = 'Compra'
                                    THEN COALESCE(ap.quantidade_aporte, 0)
                                ELSE 0
                            END
                        ),
                        0
                    ) AS quantidade
                FROM aporte ap
                WHERE ap.id_carteira = ativo.id_carteira
                  AND ap.id_ativo = ativo.id_ativo
                  AND ap.status_aporte = 'Concluída'
                  AND ap.data_aporte <= d.data
            ) qtd ON TRUE
            LEFT JOIN LATERAL (
                SELECT
                    cota.preco_fechamento_cotacao AS preco
                FROM cotacao_ativo cota
                WHERE cota.id_ativo = ativo.id_ativo
                  AND cota.data_cotacao <= d.data
                ORDER BY cota.data_cotacao DESC
                LIMIT 1
            ) cot ON TRUE
            GROUP BY d.data
            ORDER BY d.data ASC
        ";

        $consulta = $conexao->prepare($sql);
        $consulta->execute($parametros);

        $pontos = [];

        foreach ($consulta->fetchAll(PDO::FETCH_ASSOC) as $linha) {
            $pontos[] = [
                "data" => $linha["data"],
                "valor" => (float) $linha["valor"]
            ];
        }

        echo json_encode([
            "sucesso" => true,
            "periodo" => $periodo,
            "pontos" => $pontos
        ]);
    } catch (Throwable $erro) {
        http_response_code(500);

        echo json_encode([
            "sucesso" => false,
            "mensagem" => "Erro ao gerar evolução da carteira: " . $erro->getMessage(),
            "pontos" => []
        ]);
    }
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
