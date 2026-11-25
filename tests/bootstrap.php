<?php

require_once __DIR__ . '/../vendor/autoload.php';

/**
 * Conexão para os testes de integração.
 *
 * Só conecta quando DB_HOST aponta para um banco local (ex.: o Postgres do Docker),
 * para que os testes nunca gravem dados no banco de produção configurado no .env.
 */
function conexaoBancoDeTeste(): ?PDO
{
    $host = getenv('DB_HOST') ?: '';

    if (!in_array($host, ['127.0.0.1', 'localhost', '::1'], true)) {
        return null;
    }

    return new PDO(
        sprintf('pgsql:host=%s;port=%s;dbname=%s', $host, getenv('DB_PORT') ?: '5432', getenv('DB_NAME') ?: 'postgres'),
        getenv('DB_USER') ?: 'postgres',
        getenv('DB_PASSWORD') ?: '',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
}
