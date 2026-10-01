<?php 
    function salvarCotacao(PDO $conexao, $idAtivo, $dataCotacao, $precoAtivo){

        $busca = $conexao->prepare("SELECT id_cotacao FROM cotacao_ativo WHERE id_ativo = ? AND data_cotacao = ?");
        $busca->bindParam(1, $idAtivo);
        $busca->bindParam(2, $dataCotacao);
        $busca->execute();
        $existente = $busca->fetch(PDO::FETCH_ASSOC);
 
        if ($existente) {
            $idCotacao = $existente["id_cotacao"];
 
            $update = $conexao->prepare("UPDATE cotacao_ativo SET preco_fechamento_cotacao = ? WHERE id_cotacao = ?");
            $update->bindParam(1, $precoAtivo);
            $update->bindParam(2, $idCotacao);
            $update->execute();
 
            return $idCotacao;
        }
 
        $insert = $conexao->prepare("INSERT INTO cotacao_ativo (id_ativo, data_cotacao, preco_fechamento_cotacao) VALUES (?, ?, ?) RETURNING id_cotacao");
        $insert->bindParam(1, $idAtivo);
        $insert->bindParam(2, $dataCotacao);
        $insert->bindParam(3, $precoAtivo);
        $insert->execute();
 
        $nova = $insert->fetch(PDO::FETCH_ASSOC);
 
        return $nova["id_cotacao"];
    }

    function buscarUltimaCotacao(PDO $conexao, $idAtivo){
        $sql = $conexao->prepare(
            "SELECT data_cotacao, preco_fechamento_cotacao
            FROM cotacao_ativo
            WHERE id_ativo = ?
            ORDER BY data_cotacao DESC
            LIMIT 1"
        );
        $sql->bindParam(1, $idAtivo);
        $sql->execute();
 
        $cotacao = $sql->fetch(PDO::FETCH_ASSOC);
 
        return $cotacao ?: null;
    }
?>