<?php

require_once __DIR__ . "/../../database/conexao.php";
require_once __DIR__ . "/../Repositories/NoticiaRepository.php";
require_once __DIR__ . "/../Services/NoticiaService.php";

const NOTICIAS_LIMITE_PADRAO = 12;
const NOTICIAS_LIMITE_MAXIMO = 30;

// GET /noticias/listar?limite=12
// Pública: são notícias de mercado, sem nenhum dado do usuário.
function listarNoticias(): void
{
    global $conexao;

    $limite = filter_var($_GET["limite"] ?? NOTICIAS_LIMITE_PADRAO, FILTER_VALIDATE_INT);
    $limite = $limite === false ? NOTICIAS_LIMITE_PADRAO : max(1, min($limite, NOTICIAS_LIMITE_MAXIMO));

    $internas = [];
    try {
        $internas = array_map("noticiaDaEquipe", buscarNoticiasPublicadas($conexao, $limite));
    } catch (Throwable $erro) {
        error_log("Erro ao buscar notícias da equipe: " . $erro->getMessage());
    }

    $noticias = combinarNoticias($internas, noticiasExternas(), $limite);

    // Navegador e app podem reaproveitar a resposta por alguns minutos.
    header("Cache-Control: public, max-age=300");

    echo json_encode([
        "success" => true,
        "quantidade" => count($noticias),
        "noticias" => $noticias,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
