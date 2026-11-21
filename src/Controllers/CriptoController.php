<?php

// Cryptocurrency market endpoints. Data comes from MercadoExternoService (AwesomeAPI, free):
// the brapi crypto endpoints need a paid plan and answer HTTP 403 on the free one.
// Responses reuse the same JSON shapes as the stock endpoints so the Android screens are shared.

require_once __DIR__ . '/../Services/MercadoExternoService.php';

function listarCriptomoedas(): void
{
    extListar('cripto');
}

function getCryptoQuote(): void
{
    extCotacaoAtivo('cripto');
}

function historicoCriptomoedas(): void
{
    extHistorico('cripto');
}
