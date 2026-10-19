<?php

namespace App\Repositories;

use PDO;

class OAuthRepository
{
    /*
     * AJUSTE AQUI se o seu banco usar outros nomes.
     * Confira com:
     *   SELECT column_name, data_type, is_nullable
     *   FROM information_schema.columns WHERE table_name = 'usuario';
     */
    private const TABELA_USUARIO  = 'usuario';
    private const COL_ID          = 'id_usuario';
    private const COL_NOME        = 'nome_usuario';
    private const COL_EMAIL       = 'email_usuario';
    private const COL_SENHA       = 'senha_usuario';

    /*
     * Colunas extras que o usuário vindo do Google precisa ter para o
     * login normal aceitá-lo (por exemplo, e-mail já verificado) ou porque
     * são NOT NULL. Exemplo:
     *   ['email_verificado' => true]
     * Deixe vazio [] se não houver nenhuma.
     */
    private const USUARIO_CAMPOS_EXTRA = [];

    public function __construct(private PDO $pdo) {}

    public function buscarUsuarioPorVinculo(string $provedor, string $idExterno): ?string
    {
        $stmt = $this->pdo->prepare(
            'SELECT id_usuario FROM oauth
             WHERE provedor_oauth = ? AND id_externo_oauth = ?'
        );
        $stmt->execute([$provedor, $idExterno]);

        $id = $stmt->fetchColumn();

        return $id !== false ? (string) $id : null;
    }

    public function buscarUsuarioPorEmail(string $email): ?string
    {
        $sql = sprintf(
            'SELECT %s FROM %s WHERE %s = ? LIMIT 1',
            self::COL_ID,
            self::TABELA_USUARIO,
            self::COL_EMAIL
        );

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$email]);

        $id = $stmt->fetchColumn();

        return $id !== false ? (string) $id : null;
    }

    public function criarUsuario(string $nome, string $email): string
    {
        $senha = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);

        $colunas = [self::COL_NOME, self::COL_EMAIL, self::COL_SENHA];
        $valores = [$nome, $email, $senha];

        foreach (self::USUARIO_CAMPOS_EXTRA as $coluna => $valor) {
            $colunas[] = $coluna;
            $valores[] = $valor;
        }

        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s) RETURNING %s',
            self::TABELA_USUARIO,
            implode(', ', $colunas),
            implode(', ', array_fill(0, count($colunas), '?')),
            self::COL_ID
        );

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($valores);

        return (string) $stmt->fetchColumn();
    }

    public function criarVinculo(string $idUsuario, string $provedor, string $idExterno, string $email): void
    {
        $this->pdo->prepare(
            'INSERT INTO oauth (id_usuario, provedor_oauth, id_externo_oauth, email_oauth)
             VALUES (?, ?, ?, ?)'
        )->execute([$idUsuario, $provedor, $idExterno, $email]);
    }

    /** Dados que o site guarda em localStorage["usuario"]. */
    public function buscarDadosUsuario(string $idUsuario): ?array
    {
        $sql = sprintf(
            'SELECT %s AS id_usuario, %s AS nome_usuario, %s AS email_usuario
             FROM %s WHERE %s = ? LIMIT 1',
            self::COL_ID,
            self::COL_NOME,
            self::COL_EMAIL,
            self::TABELA_USUARIO,
            self::COL_ID
        );

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$idUsuario]);

        $linha = $stmt->fetch(PDO::FETCH_ASSOC);

        return $linha ?: null;
    }

    public function transacao(callable $fn): mixed
    {
        $this->pdo->beginTransaction();

        try {
            $resultado = $fn();
            $this->pdo->commit();
            return $resultado;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }
}