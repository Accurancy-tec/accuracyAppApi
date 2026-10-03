<?php
    // Confere se a carteira pertence mesmo ao usuário autenticado,
    // pra ninguém criar aporte recorrente em carteira de outra pessoa
    function criarCarteira(PDO $conexao, $idUsuario, $nomeCarteira, $tipoCarteira, $saldoLivre){
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

    function carteiraPertenceAoUsuario(PDO $conexao, $idCarteira, $idUsuario)
    {
        $sql = "SELECT id_carteira FROM carteira WHERE id_carteira = ? AND id_usuario = ?";
        $stmt = $conexao->prepare($sql);
        $stmt->execute([$idCarteira, $idUsuario]);
 
        return $stmt->fetch(PDO::FETCH_ASSOC) !== false;
    }

    function getCarteirasDoUsuario(PDO $conexao, $id_usuario){
        $sql = $conexao->prepare("SELECT nome_carteira, tipo_carteira FROM carteira WHERE id_usuario = :id_usuario ORDER BY id_carteira DESC");

        $sql->execute([":id_usuario" => $id_usuario]);

        return $sql->fetchAll(PDO::FETCH_ASSOC);
    }
?>