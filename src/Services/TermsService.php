<?php

// Version of the Terms of Use and Privacy Policy published on the site. Changing it asks
// every user to accept the documents again.
const TERMS_VERSION = "2026-11-26";

function termsStatus(PDO $connection, int $userId): array
{
    $statement = $connection->prepare("SELECT terms_accepted_at, terms_version FROM usuario WHERE id_usuario = :user_id");
    $statement->execute([":user_id" => $userId]);
    $row = $statement->fetch(PDO::FETCH_ASSOC) ?: ["terms_accepted_at" => null, "terms_version" => null];

    return [
        "version" => TERMS_VERSION,
        "accepted_at" => $row["terms_accepted_at"],
        "pending" => $row["terms_accepted_at"] === null || $row["terms_version"] !== TERMS_VERSION,
    ];
}

function acceptTerms(PDO $connection, int $userId): void
{
    $connection->prepare("UPDATE usuario SET terms_accepted_at = NOW(), terms_version = :version WHERE id_usuario = :user_id")
        ->execute([":version" => TERMS_VERSION, ":user_id" => $userId]);
}
