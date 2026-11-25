<?php

function registrarCarteira(PDO $conexao, $idUsuario, $nomeCarteira, $tipoCarteira, $estilo_carteira, $meta_carteira)
{
    $sql = "INSERT INTO carteira (id_usuario, nome_carteira, estilo_carteira, tipo_carteira, meta_carteira) VALUES (?, ?, ?, ?, ?) RETURNING id_carteira";
    $insert = $conexao->prepare($sql);
    $insert->bindParam(1, $idUsuario);
    $insert->bindParam(2, $nomeCarteira);
    $insert->bindParam(3, $estilo_carteira);
    $insert->bindParam(4, $tipoCarteira);
    $insert->bindParam(5, $meta_carteira);
    $insert->execute();

    $nova = $insert->fetch(PDO::FETCH_ASSOC);

    return $nova["id_carteira"];
}

// Confere se a carteira pertence mesmo ao usuário autenticado,
// pra ninguém operar em carteira de outra pessoa
function carteiraPertenceAoUsuario(PDO $conexao, $idCarteira, $idUsuario)
{
    $sql = "SELECT id_carteira FROM carteira WHERE id_carteira = ? AND id_usuario = ?";
    $stmt = $conexao->prepare($sql);
    $stmt->execute([$idCarteira, $idUsuario]);

    return $stmt->fetch(PDO::FETCH_ASSOC) !== false;
}

function buscarCarteiraDoUsuario(PDO $conexao, int $idCarteira, int $idUsuario): ?array
{
    $stmt = $conexao->prepare("
        SELECT id_carteira, nome_carteira, tipo_carteira::text AS tipo_carteira,
               saldo_livre_carteira, capital_simulado_carteira
        FROM carteira
        WHERE id_carteira = :carteira AND id_usuario = :usuario
    ");
    $stmt->execute([":carteira" => $idCarteira, ":usuario" => $idUsuario]);

    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function contarPosicoesAbertas(PDO $conexao, int $idCarteira): int
{
    $stmt = $conexao->prepare("SELECT COUNT(*) FROM item_investimento WHERE id_carteira = ? AND quantidade_item > 0");
    $stmt->execute([$idCarteira]);

    return (int) $stmt->fetchColumn();
}

/** Registra um depósito fictício no histórico (id_carteira só para depósitos de simulação). */
function registrarDeposito(PDO $conexao, int $idUsuario, ?int $idCarteira, float $valor, string $descricao): void
{
    $stmt = $conexao->prepare("
        INSERT INTO transacao_saldo (id_usuario, id_carteira, tipo_transacao, valor, descricao)
        VALUES (:usuario, :carteira, 'Deposito', :valor, :descricao)
    ");
    $stmt->bindValue(":usuario", $idUsuario, PDO::PARAM_INT);
    $stmt->bindValue(":carteira", $idCarteira, $idCarteira === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
    $stmt->bindValue(":valor", number_format($valor, 2, ".", ""));
    $stmt->bindValue(":descricao", $descricao);
    $stmt->execute();
}

/** Soma dinheiro fictício ao saldo e ao capital de uma carteira simulada. Devolve o novo saldo. */
function depositarNaCarteiraSimulada(PDO $conexao, int $idUsuario, int $idCarteira, float $valor, string $descricao): string
{
    $stmt = $conexao->prepare("
        UPDATE carteira
           SET saldo_livre_carteira = saldo_livre_carteira + CAST(:valor AS NUMERIC),
               capital_simulado_carteira = capital_simulado_carteira + CAST(:valor AS NUMERIC)
         WHERE id_carteira = :carteira
           AND id_usuario = :usuario
           AND tipo_carteira = 'Simulada'
        RETURNING saldo_livre_carteira
    ");
    $stmt->execute([
        ":valor" => number_format($valor, 2, ".", ""),
        ":carteira" => $idCarteira,
        ":usuario" => $idUsuario,
    ]);

    $saldo = $stmt->fetchColumn();

    if ($saldo === false) {
        throw new RuntimeException("Carteira simulada $idCarteira não encontrada para o usuário $idUsuario.");
    }

    registrarDeposito($conexao, $idUsuario, $idCarteira, $valor, $descricao);

    return (string) $saldo;
}

function deletarCarteira(PDO $conexao, $id_usuario, $id_carteira) {

    try {

        // Inicia a transação
        $conexao->beginTransaction();

        // Verifica se a carteira pertence ao usuário
        $sql = "SELECT id_carteira
                FROM carteira
                WHERE id_carteira = :id_carteira
                AND id_usuario = :id_usuario";

        $stmt = $conexao->prepare($sql);

        $stmt->execute([
            ':id_carteira' => $id_carteira,
            ':id_usuario' => $id_usuario
        ]);

        if (!$stmt->fetch(PDO::FETCH_ASSOC)) {
            throw new Exception("Carteira não encontrada.");
        }

        // Exclui os aportes
        $sql = "DELETE FROM aporte
                WHERE id_carteira = :id_carteira";

        $stmt = $conexao->prepare($sql);

        $stmt->execute([
            ':id_carteira' => $id_carteira
        ]);

        // Exclui as posições dos investimentos
        $sql = "DELETE FROM item_investimento
                WHERE id_carteira = :id_carteira";

        $stmt = $conexao->prepare($sql);

        $stmt->execute([
            ':id_carteira' => $id_carteira
        ]);

        // Exclui a carteira
        $sql = "DELETE FROM carteira
                WHERE id_carteira = :id_carteira
                AND id_usuario = :id_usuario";

        $stmt = $conexao->prepare($sql);

        $stmt->execute([
            ':id_carteira' => $id_carteira,
            ':id_usuario' => $id_usuario
        ]);

        // Confirma todas as operações
        $conexao->commit();

        return true;

    } catch (Exception $e) {

        // Desfaz todas as operações caso ocorra algum erro
        if ($conexao->inTransaction()) {
            $conexao->rollBack();
        }
        throw $e;
    }
}

/** Read-only: current balance of the user (the same column the balance trigger maintains). */
function buscarSaldoRepository(PDO $conexao, int $id_usuario): ?string
{
    $stmt = $conexao->prepare('SELECT saldo FROM usuario WHERE id_usuario = :id_usuario');
    $stmt->bindValue(':id_usuario', $id_usuario, PDO::PARAM_INT);
    $stmt->execute();
    $resultado = $stmt->fetch(PDO::FETCH_ASSOC);

    return $resultado ? (string) $resultado['saldo'] : null;
}

function adicionarSaldoRepository(PDO $conexao, int $id_usuario, string $valor): ?string
{
    $sql = "
        UPDATE usuario
        SET saldo = saldo + CAST(:valor AS NUMERIC)
        WHERE id_usuario = :id_usuario
        RETURNING saldo
    ";

    $stmt = $conexao->prepare($sql);

    $stmt->bindValue(':valor', $valor);
    $stmt->bindValue(':id_usuario', $id_usuario, PDO::PARAM_INT);

    $stmt->execute();

    $resultado = $stmt->fetch(PDO::FETCH_ASSOC);

    return $resultado ? $resultado['saldo'] : null;
}
