<?php

// Foreign currency endpoints (value of one unit in BRL). Data comes from MercadoExternoService
// (AwesomeAPI, free). Responses reuse the stock JSON shapes so the Android screens are shared.

require_once __DIR__ . '/../Services/MercadoExternoService.php';

function listarMoedas(): void
{
    extListar('moeda');
}

function getCurrencyQuote(): void
{
    extCotacaoAtivo('moeda');
}

function historicoMoedas(): void
{
    extHistorico('moeda');
}
