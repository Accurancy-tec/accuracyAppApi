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
            "message" => "Carteiras encontradas",
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
