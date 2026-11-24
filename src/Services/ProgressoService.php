<?php
require_once __DIR__ . '/../Repositories/ProgressoRepository.php';

function accuracyNormalizarProgresso(array $item): array
{
    foreach (['id_atividade', 'id_trilha', 'id_curso', 'ordem_atividade',
              'percentual_progresso', 'ultima_posicao_segundos'] as $campo) {
        if (isset($item[$campo])) {
            $item[$campo] = (int) $item[$campo];
        }
    }
    $item['concluido_progresso'] = ((int) $item['concluido_progresso'] === 1);
    return $item;
}

function accuracyProgressoService(PDO $conexao, int $idUsuario, ?int $idTrilha): array
{
    $itens = array_map('accuracyNormalizarProgresso', accuracyConsultarProgresso($conexao, $idUsuario, $idTrilha));
    $trilhas = [];
    foreach ($itens as $item) {
        $id = $item['id_trilha'];
        if (!isset($trilhas[$id])) {
            $trilhas[$id] = ['id_trilha' => $id, 'total_atividades' => 0, 'atividades_concluidas' => 0];
        }
        $trilhas[$id]['total_atividades']++;
        if ($item['concluido_progresso']) {
            $trilhas[$id]['atividades_concluidas']++;
        }
    }
    foreach ($trilhas as &$trilha) {
        $trilha['percentual_conclusao'] = (int) round(100 * $trilha['atividades_concluidas'] / $trilha['total_atividades']);
    }
    unset($trilha);
    return ['success' => true, 'progresso' => $itens, 'trilhas' => array_values($trilhas)];
}

function accuracySalvarProgressoService(PDO $conexao, int $idUsuario, int $idAtividade, int $percentual, int $posicao)
{
    $item = accuracyGravarProgresso($conexao, $idUsuario, $idAtividade, $percentual, $posicao);
    return $item === false ? null : accuracyNormalizarProgresso($item);
}