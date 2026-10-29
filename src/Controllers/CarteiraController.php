<?php
header("Content-Type: application/json; charset=UTF-8");

require "../database/conexao.php";
require_once __DIR__ . "/../../config/cors.php";
require_once __DIR__ . "/../Middleware/Authentication.php";
require_once __DIR__ . "/../Services/CarteiraService.php";
require_once __DIR__ . "/../Services/PosicaoService.php";

function criarCarteira()
{
    try {
        global $conexao;

        $dados = json_decode(file_get_contents("php://input"), true);

        $idUser = autenticar();
        $nameWallet = $dados['nome_carteira'];
        $typeWallet = $dados['tipo_carteira'];

        criarCarteiraService($conexao, $idUser, $nameWallet, $typeWallet);

        echo json_encode([
            "success" => true,
            "message" => "carteira cadastrado com sucesso"
        ]);
    } catch (PDOException $erro) {
        echo json_encode([
            "success" => false,
            "message" => "Erro ao salvar: " . $erro->getMessage()
        ]);
    }
}

function buscarCarteiras()
{
    global $conexao;

    try {
        $id_usuario = autenticar();

        $resultado = getCarteirasDoUsuarioService($conexao, $id_usuario);

        echo json_encode([
            "success" => true,
            "carteiras" => $resultado
        ]);
    } catch (PDOException $e) {
        echo json_encode([
            "success" => false,
            "message" => "Não foi possível buscar as carteiras" . $e->getMessage()
        ]);
    }
}

function itemInvestimento()
{
    global $conexao;

    autenticar();

    $idCarteira = $_GET['id_carteira'] ?? null;

    if (!$idCarteira) {
        echo json_encode([
            "sucesso" => false,
            "mensagem" => "ID da carteira não fornecido."
        ]);
        exit;
    }

    try {
        $posicoes = buscarPosicoesService($conexao, $idCarteira);

        echo json_encode([
            "sucesso" => true,
            "posicoes" => $posicoes
        ]);
    } catch (PDOException $erro) {
        echo json_encode([
            "sucesso" => false,
            "mensagem" => "Erro ao buscar posições: " . $erro->getMessage()
        ]);
    }
}

function distribuicaoCarteira()
{
    global $conexao;

    try {
        $idUsuario = autenticar();

        $idCarteira = isset($_GET["id_carteira"])
            ? (int) $_GET["id_carteira"]
            : null;

        $condicaoCarteira = "";
        $parametros = [":usuario" => $idUsuario];

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

            $condicaoCarteira = "AND i.id_carteira = :carteira";
            $parametros[":carteira"] = $idCarteira;
        }

        $sql = "
            SELECT
                a.categoria_ativo AS categoria,
                SUM(i.quantidade_item * COALESCE(cot.preco, i.preco_medio_item)) AS valor
            FROM item_investimento i
            INNER JOIN carteira c
                ON c.id_carteira = i.id_carteira
            INNER JOIN ativo a
                ON a.id_ativo = i.id_ativo
            -- LEFT: ativo sem cotação (Cripto, Renda Fixa, Internacional) não pode
            -- sumir do donut; nesse caso vale o preço médio pago.
            LEFT JOIN LATERAL (
                SELECT
                    cota.preco_fechamento_cotacao AS preco
                FROM cotacao_ativo cota
                WHERE cota.id_ativo = i.id_ativo
                ORDER BY cota.data_cotacao DESC
                LIMIT 1
            ) cot ON TRUE
            WHERE c.id_usuario = :usuario
              AND i.quantidade_item > 0
              $condicaoCarteira
            GROUP BY a.categoria_ativo
            HAVING SUM(i.quantidade_item * COALESCE(cot.preco, i.preco_medio_item)) > 0
            ORDER BY valor DESC
        ";

        $consulta = $conexao->prepare($sql);
        $consulta->execute($parametros);

        $linhas = $consulta->fetchAll(PDO::FETCH_ASSOC);
        $total = 0.0;

        foreach ($linhas as $linha) {
            $total += (float) $linha["valor"];
        }

        $distribuicao = [];

        foreach ($linhas as $linha) {
            $valor = (float) $linha["valor"];

            $distribuicao[] = [
                "categoria" => $linha["categoria"],
                "valor" => $valor,
                "percentual" => $total > 0
                    ? round(($valor / $total) * 100, 2)
                    : 0.0
            ];
        }

        echo json_encode([
            "sucesso" => true,
            "distribuicao" => $distribuicao,
            "total" => $total
        ]);
    } catch (Throwable $erro) {
        http_response_code(500);

        echo json_encode([
            "sucesso" => false,
            "mensagem" => "Erro ao gerar distribuição da carteira: " . $erro->getMessage(),
            "distribuicao" => []
        ]);
    }
}

function buscarPosicaoAtivoUsuario(): void
{
    global $conexao;

    header('Content-Type: application/json; charset=UTF-8');

    try {
        $idUsuario = autenticar();
        $symbol = strtoupper(trim((string) ($_GET['symbol'] ?? '')));

        if ($symbol === '' || !preg_match('/^[A-Z0-9.^_-]{1,24}$/', $symbol)) {
            http_response_code(400);

            echo json_encode([
                'success' => false,
                'sucesso' => false,
                'mensagem' => 'Ticker ausente ou inválido.'
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            return;
        }

        $stmt = $conexao->prepare("
            SELECT
                COALESCE(SUM(ii.quantidade_item), 0) AS quantidade,
                CASE
                    WHEN COALESCE(SUM(ii.quantidade_item), 0) > 0
                    THEN SUM(ii.quantidade_item * ii.preco_medio_item)
                         / SUM(ii.quantidade_item)
                    ELSE NULL
                END AS preco_medio
            FROM carteira c
            INNER JOIN item_investimento ii
                ON ii.id_carteira = c.id_carteira
            INNER JOIN ativo a
                ON a.id_ativo = ii.id_ativo
            WHERE c.id_usuario = :id_usuario
              AND UPPER(a.simbolo_ativo) = :symbol
              AND ii.quantidade_item > 0
        ");

        $stmt->execute([
            ':id_usuario' => $idUsuario,
            ':symbol' => $symbol
        ]);

        $linha = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $quantidade = max(
            0.0,
            (float) ($linha['quantidade'] ?? 0)
        );

        $precoMedioRaw = $linha['preco_medio'] ?? null;

        $precoMedio = $quantidade > 0 && is_numeric($precoMedioRaw)
            ? (float) $precoMedioRaw
            : null;

        echo json_encode([
            'success' => true,
            'sucesso' => true,
            'symbol' => $symbol,
            'quantidade' => $quantidade,
            'precoMedio' => $precoMedio,
            'hasPosition' => $quantidade > 0 && $precoMedio !== null
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } catch (Throwable $erro) {
        error_log(
            'Failed to retrieve asset position: ' . $erro->getMessage()
        );

        http_response_code(500);

        echo json_encode([
            'success' => false,
            'sucesso' => false,
            'mensagem' => 'Não foi possível consultar a posição do ativo.'
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
