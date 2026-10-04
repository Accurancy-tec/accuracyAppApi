<?php

// cria a carteira do usuario

// e retorna o id dela
function criarCarteira(PDO $conexao, $idUsuario, $nomeCarteira, $tipoCarteira, $saldoLivre)
{
    $sql = "INSERT INTO carteira (id_usuario, nome_carteira, tipo_carteira, saldo_livre_carteira) VALUES (?, ?, ?, ?) RETURNING id_carteira";
    $insert = $conexao->prepare($sql);
    $insert->bindParam(1, $idUsuario);
    $insert->bindParam(2, $nomeCarteira);
    $insert->bindParam(3, $tipoCarteira);
    $insert->bindParam(4, $saldoLivre);
    $insert->execute();

    $nova = $insert->fetch(PDO::FETCH_ASSOC);

    return $nova["id_carteira"];
}

function resolverCarteiraDoUsuario(PDO $conexao, $idUsuario, $idCarteiraInformada = null)
{
    if ($idCarteiraInformada !== null && $idCarteiraInformada !== "") {

        $stmt = $conexao->prepare("SELECT id_carteira FROM carteira WHERE id_carteira = ? AND id_usuario = ?");
        $stmt->execute([$idCarteiraInformada, $idUsuario]);
        $achada = $stmt->fetchColumn();

        return $achada === false ? null : (int) $achada;
    }

    $stmt = $conexao->prepare("SELECT id_carteira FROM carteira WHERE id_usuario = ? ORDER BY id_carteira ASC LIMIT 1");
    $stmt->execute([$idUsuario]);
    $existente = $stmt->fetchColumn();

    if ($existente !== false) {
        return (int) $existente;
    }

    return (int) criarCarteira($conexao, $idUsuario, "Carteira principal", "Real", 0);
}
?>