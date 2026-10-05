<?php

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . "/../vendor/autoload.php";

function enviarCodigo($email, $codigo)
{
    $mail = new PHPMailer(true);

    $debug = "";

    try {

        $mail->isSMTP();

        $mail->SMTPDebug = 2;

        $mail->Debugoutput = function ($str, $level) use (&$debug) {
            $debug .= $str . "\n";
        };

        $mail->Host = "smtp.gmail.com";
        $mail->SMTPAuth = true;

        $mail->Username = "accuracytcc@gmail.com";

        // SENHA DE APP
        $mail->Password = "hipt docm qhid zipj";

        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = 587;

        $mail->setFrom(
            "accuracytcc@gmail.com",
            "Accuracy"
        );

        $mail->addAddress($email);

        $mail->isHTML(true);

        $mail->Subject = "Verificacao de email - Accuracy";

        $mail->Body = "
            <h2>Verificação de e-mail</h2>

            <p>Olá!</p>

            <p>Seu código de verificação é:</p>

            <h1>$codigo</h1>

            <p>Esse código é válido por 10 minutos.</p>
        ";

        $mail->send();

        return [
            "sucesso" => true,
            "debug" => $debug
        ];

    } catch (\Exception $erro) {

        return [
            "sucesso" => false,
            "erro" => $erro->getMessage(),
            "debug" => $debug
        ];
    }
}