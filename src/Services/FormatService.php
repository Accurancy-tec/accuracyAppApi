<?php

function formatCurrency(float $value): string
{
    return "R$ " . number_format($value, 2, ",", ".");
}

/** 3.42 -> "3,42%"; 78.5 -> "78,5%"; 60 -> "60%". */
function formatPercent(float $value, bool $signed = false): string
{
    $text = rtrim(rtrim(number_format(abs($value), 2, ",", "."), "0"), ",") . "%";
    if (!$signed) {
        return $text;
    }
    return ($value > 0 ? "+" : ($value < 0 ? "-" : "")) . $text;
}

function formatCount(int $count, string $singular, string $plural): string
{
    return $count . " " . ($count === 1 ? $singular : $plural);
}
