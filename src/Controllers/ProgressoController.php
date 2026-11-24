<?php
require_once __DIR__ . '/../Services/ProgressoService.php';
require_once __DIR__ . '/../Middleware/Authentication.php';

function accuracyRespostaProgresso(int $status, array $dados): void
{
    http_response_code($status);
    echo json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
}

function accuracyIdPositivo($valor): ?int
{
    if (!is_int($valor) && !is_string($valor)) {
        return null;
    }
    $id = filter_var($valor, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 2147483647]]);
    return $id === false ? null : $id;
}

function accuracyCursosProgressoController(string $acao): void
{
    global $conexao;
    header('Cache-Control: no-store');
    $metodo = $acao === 'salvar-progresso' ? 'POST' : 'GET';
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== $metodo) {
        header('Allow: ' . $metodo);
        accuracyRespostaProgresso(405, ['success' => false, 'message' => 'Metodo nao permitido']);
        return;
    }
    $idUsuario = null;
    if ($acao !== 'atividades') {
        $idUsuario = accuracyIdPositivo(autenticar());
        if ($idUsuario === null) {
            accuracyRespostaProgresso(401, ['success' => false, 'message' => 'Usuario invalido']);
            return;
        }
    }
    try {
        if ($metodo === 'GET') {
            $idTrilha = null;
            if (array_key_exists('id_trilha', $_GET)) {
                $idTrilha = accuracyIdPositivo($_GET['id_trilha']);
                if ($idTrilha === null) {
                    accuracyRespostaProgresso(400, ['success' => false, 'message' => 'ID da trilha invalido']);
                    return;
                }
            }
            if ($acao === 'atividades') {
                accuracyRespostaProgresso(200, ['success' => true, 'atividades' => accuracyListarAtividades($conexao, $idTrilha)]);
            } else {
                accuracyRespostaProgresso(200, accuracyProgressoService($conexao, $idUsuario, $idTrilha));
            }
            return;
        }
        $dados = json_decode(file_get_contents('php://input'), true);
        if (!is_array($dados) || json_last_error() !== JSON_ERROR_NONE) {
            accuracyRespostaProgresso(400, ['success' => false, 'message' => 'JSON invalido']);
            return;
        }
        $idAtividade = accuracyIdPositivo($dados['id_atividade'] ?? null);
        $percentual = $dados['percentual_progresso'] ?? null;
        $posicao = $dados['ultima_posicao_segundos'] ?? null;
        if ($idAtividade === null || !is_int($percentual) || $percentual < 0 || $percentual > 100
            || !is_int($posicao) || $posicao < 0 || $posicao > 2147483647) {
            accuracyRespostaProgresso(400, ['success' => false, 'message' => 'Informe id_atividade, percentual de 0 a 100 e posicao inteira em segundos']);
            return;
        }
        $item = accuracySalvarProgressoService($conexao, $idUsuario, $idAtividade, $percentual, $posicao);
        if ($item === null) {
            accuracyRespostaProgresso(404, ['success' => false, 'message' => 'Atividade nao encontrada']);
            return;
        }
        accuracyRespostaProgresso(200, ['success' => true, 'progresso' => $item]);
    } catch (PDOException $erro) {
        error_log('Accuracy cursos progresso SQLSTATE: ' . $erro->getCode());
        accuracyRespostaProgresso(500, ['success' => false, 'message' => 'Nao foi possivel acessar o progresso']);
    }
}