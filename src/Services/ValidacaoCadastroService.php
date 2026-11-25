<?php

// Regras dos dados pessoais opcionais (telefone e CPF). Funções puras, sem banco.

/**
 * Telefone brasileiro com DDD, só dígitos (10 ou 11), como é guardado no banco.
 * Devolve "" quando não foi informado e null quando é inválido. Aceita o +55 na frente.
 */
function telefoneSomenteDigitos(string $valor): ?string
{
    $digitos = preg_replace("/\D+/", "", $valor);

    if ($digitos === "") {
        return "";
    }

    if (strlen($digitos) > 11 && str_starts_with($digitos, "55")) {
        $digitos = substr($digitos, 2);
    }

    if (!preg_match('/^[1-9]{2}\d{8,9}$/', $digitos)) {
        return null;
    }

    return $digitos;
}

/** (11) 91234-5678 ou (11) 3123-4567 a partir dos dígitos guardados. */
function formatarTelefone(?string $digitos): ?string
{
    $digitos = preg_replace("/\D+/", "", (string) $digitos);

    if (strlen($digitos) === 11) {
        return sprintf("(%s) %s-%s", substr($digitos, 0, 2), substr($digitos, 2, 5), substr($digitos, 7));
    }

    if (strlen($digitos) === 10) {
        return sprintf("(%s) %s-%s", substr($digitos, 0, 2), substr($digitos, 2, 4), substr($digitos, 6));
    }

    return $digitos === "" ? null : $digitos;
}

/** Confere os dígitos verificadores do CPF (com ou sem máscara). */
function cpfValido(string $cpf): bool
{
    $cpf = preg_replace("/\D+/", "", $cpf);

    if (strlen($cpf) !== 11 || preg_match('/^(\d)\1{10}$/', $cpf)) {
        return false;
    }

    for ($posicao = 9; $posicao <= 10; $posicao++) {
        $soma = 0;
        for ($i = 0; $i < $posicao; $i++) {
            $soma += (int) $cpf[$i] * ($posicao + 1 - $i);
        }

        $digito = ($soma * 10) % 11 % 10;

        if ((int) $cpf[$posicao] !== $digito) {
            return false;
        }
    }

    return true;
}

/** CPF com o meio visível, para exibir sem expor o documento inteiro: ***.456.789-** */
function mascararCpf(?string $cpf): ?string
{
    $cpf = preg_replace("/\D+/", "", (string) $cpf);

    return strlen($cpf) === 11 ? "***." . substr($cpf, 3, 3) . "." . substr($cpf, 6, 3) . "-**" : null;
}
