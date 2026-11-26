<?php

// Indicadores econômicos usados para estimar o rendimento da renda fixa.
// A Selic e o IPCA mudam pouco, então ficam guardados por 12 horas.

require_once __DIR__ . "/../Controllers/BrapiController.php";

const INDICADORES_CACHE_TTL = 43200;
const INDICADORES_CACHE_ANTIGO_MAX = 604800;

// O CDI acompanha a Selic, normalmente 0,10 ponto percentual abaixo.
const DIFERENCA_SELIC_CDI = 0.10;

function indicadoresArquivoCache(): string
{
    return rtrim(sys_get_temp_dir(), "/\\") . DIRECTORY_SEPARATOR . "accurancy_indicadores.json";
}

function indicadoresLerCache(int $idadeMaxima): ?array
{
    $arquivo = indicadoresArquivoCache();

    if (!is_file($arquivo) || time() - (int) @filemtime($arquivo) > $idadeMaxima) {
        return null;
    }

    $dados = json_decode((string) @file_get_contents($arquivo), true);

    return is_array($dados) ? $dados : null;
}

/** Primeiro valor numérico da série devolvida pela brapi (ela vem da mais recente para a mais antiga). */
function indicadorMaisRecente(string $endpoint, string $campo): ?float
{
    $resposta = brapiRequest($endpoint, ["country" => "brazil", "sortBy" => "date", "sortOrder" => "desc"]);

    if (!$resposta["ok"]) {
        return null;
    }

    $json = json_decode((string) $resposta["body"], true);
    $valor = $json[$campo][0]["value"] ?? null;

    return is_numeric($valor) ? (float) $valor : null;
}

/** ['selic' => 13.75, 'cdi' => 13.65, 'ipca' => 4.63] em % ao ano; vazio se não houver dados. */
function indicadoresEconomicos(): array
{
    $recente = indicadoresLerCache(INDICADORES_CACHE_TTL);

    if ($recente !== null) {
        return $recente;
    }

    $selic = indicadorMaisRecente("v2/prime-rate", "prime-rate");
    $ipca = indicadorMaisRecente("v2/inflation", "inflation");

    if ($selic === null && $ipca === null) {
        return indicadoresLerCache(INDICADORES_CACHE_ANTIGO_MAX) ?? [];
    }

    $indicadores = array_filter([
        "selic" => $selic,
        "cdi" => $selic !== null ? max(0.0, $selic - DIFERENCA_SELIC_CDI) : null,
        "ipca" => $ipca,
    ], static fn($valor) => $valor !== null);

    @file_put_contents(indicadoresArquivoCache(), json_encode($indicadores), LOCK_EX);

    return $indicadores;
}
