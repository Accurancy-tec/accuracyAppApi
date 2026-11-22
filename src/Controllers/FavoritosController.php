<?php
header("Content-Type: application/json; charset=UTF-8");

require_once __DIR__ . "/../../database/conexao.php";
require_once __DIR__ . "/../../config/cors.php";
require_once __DIR__ . "/../Middleware/Authentication.php";
require_once __DIR__ . "/../Repositories/FavoritoRepository.php";

const FAVORITOS_CATEGORIAS = ['Ações', 'FIIs', 'Cripto', 'Futuros', 'Internacional'];

// Overridable so tests can feed a request body without php://input.
if (!function_exists('favoritosLerCorpo')) {
    function favoritosLerCorpo(): array
    {
        $dados = json_decode(file_get_contents('php://input'), true);
        return is_array($dados) ? $dados : [];
    }
}

function favoritosResponder(int $status, array $corpo): void
{
    http_response_code($status);
    echo json_encode($corpo, JSON_UNESCAPED_UNICODE);
}

/** Maps "acoes", "AÇÕES", "fii", "cripto"... to the stored category label, or null if unknown. */
function favoritosNormalizarCategoria($valor): ?string
{
    if (!is_string($valor)) {
        return null;
    }
    $chave = mb_strtolower(trim($valor), 'UTF-8');
    $chave = strtr($chave, ['ã' => 'a', 'õ' => 'o', 'ç' => 'c', 'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u']);
    $mapa = [
        'acoes' => 'Ações',
        'fiis' => 'FIIs',
        'fii' => 'FIIs',
        'cripto' => 'Cripto',
        'futuros' => 'Futuros',
        'internacional' => 'Internacional',
    ];
    return $mapa[$chave] ?? null;
}

function favoritosNormalizarSimbolo($valor): ?string
{
    if (!is_string($valor)) {
        return null;
    }
    $simbolo = strtoupper(trim($valor));
    return preg_match('/^[A-Z0-9.\-=^_]{1,30}$/', $simbolo) === 1 ? $simbolo : null;
}

function listarFavoritosController(): void
{
    global $conexao;

    try {
        $id_usuario = (int) autenticar();

        $categoria = null;
        if (isset($_GET['categoria']) && $_GET['categoria'] !== '') {
            $categoria = favoritosNormalizarCategoria($_GET['categoria']);
            if ($categoria === null) {
                favoritosResponder(400, ['success' => false, 'message' => 'Categoria inválida']);
                return;
            }
        }

        $linhas = listarFavoritosRepository($conexao, $id_usuario, $categoria);
        favoritosResponder(200, ['success' => true, 'favoritos' => $linhas]);
    } catch (Throwable $e) {
        error_log('Erro ao listar favoritos: ' . $e->getMessage());
        favoritosResponder(500, ['success' => false, 'message' => 'Não foi possível carregar os favoritos']);
    }
}

function adicionarFavoritoController(): void
{
    global $conexao;

    try {
        $id_usuario = (int) autenticar();
        $dados = favoritosLerCorpo();

        $simbolo = favoritosNormalizarSimbolo($dados['symbol'] ?? null);
        $categoria = favoritosNormalizarCategoria($dados['category'] ?? null);
        $nome = trim((string) ($dados['name'] ?? ''));
        if ($simbolo === null || $categoria === null) {
            favoritosResponder(400, ['success' => false, 'message' => 'Ativo ou categoria inválidos']);
            return;
        }
        if ($nome === '') {
            $nome = $simbolo;
        }
        $nome = mb_substr($nome, 0, 150, 'UTF-8');

        $jaExistia = false;
        if (contarFavoritosRepository($conexao, $id_usuario) >= FAVORITOS_LIMITE_POR_USUARIO) {
            // Re-adding something already favorited must still succeed at the limit.
            $existentes = listarFavoritosRepository($conexao, $id_usuario, $categoria);
            foreach ($existentes as $linha) {
                if ($linha['simbolo_ativo'] === $simbolo) {
                    $jaExistia = true;
                    break;
                }
            }
            if (!$jaExistia) {
                favoritosResponder(422, [
                    'success' => false,
                    'message' => 'Você atingiu o limite de ' . FAVORITOS_LIMITE_POR_USUARIO . ' favoritos',
                ]);
                return;
            }
        }

        $criado = $jaExistia ? false : adicionarFavoritoRepository($conexao, $id_usuario, $simbolo, $nome, $categoria);
        favoritosResponder($criado ? 201 : 200, [
            'success' => true,
            'favorito' => true,
            'message' => $criado ? 'Ativo adicionado aos favoritos' : 'Ativo já estava nos favoritos',
        ]);
    } catch (Throwable $e) {
        error_log('Erro ao favoritar ativo: ' . $e->getMessage());
        favoritosResponder(500, ['success' => false, 'message' => 'Não foi possível favoritar o ativo']);
    }
}

function removerFavoritoController(): void
{
    global $conexao;

    try {
        $id_usuario = (int) autenticar();
        $dados = favoritosLerCorpo();

        $simbolo = favoritosNormalizarSimbolo($dados['symbol'] ?? null);
        $categoria = favoritosNormalizarCategoria($dados['category'] ?? null);
        if ($simbolo === null || $categoria === null) {
            favoritosResponder(400, ['success' => false, 'message' => 'Ativo ou categoria inválidos']);
            return;
        }

        $removido = removerFavoritoRepository($conexao, $id_usuario, $simbolo, $categoria);
        favoritosResponder(200, [
            'success' => true,
            'favorito' => false,
            'message' => $removido ? 'Ativo removido dos favoritos' : 'Ativo não estava nos favoritos',
        ]);
    } catch (Throwable $e) {
        error_log('Erro ao remover favorito: ' . $e->getMessage());
        favoritosResponder(500, ['success' => false, 'message' => 'Não foi possível remover o favorito']);
    }
}
