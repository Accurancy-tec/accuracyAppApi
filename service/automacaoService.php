<?php

require_once __DIR__ . "/cotacaoService.php";
require_once __DIR__ . "/posicaoService.php";


// Calcula a próxima data de execução do aporte recorrente
function calcularProximaExecucao($dataAtual, $frequencia)
{
    switch ($frequencia) {

        case "Diário":
            return date("Y-m-d", strtotime($dataAtual . " +1 day"));

        case "Semanal":
            return date("Y-m-d", strtotime($dataAtual . " +7 days"));

        case "Mensal":
            return date("Y-m-d", strtotime($dataAtual . " +1 month"));

        case "Anual":
            return date("Y-m-d", strtotime($dataAtual . " +1 year"));

        default:
            throw new Exception(
                "Frequência inválida: " . $frequencia
            );
    }
}

// Executa todos os aportes recorrentes que já chegaram à data
function executarAportesRecorrentes(PDO $conexao)
{
    $resultados = [
        "processados" => 0,
        "erros" => 0,
        "detalhes" => []
    ];

    $sql = "
        SELECT
            id_aporte_recorrente,
            id_carteira,
            id_ativo,
            valor_recorrente,
            frequencia_recorrente,
            proxima_execucao_recorrente
        FROM aporte_recorrente
        WHERE ativo_flag_recorrente = TRUE
         AND proxima_execucao_recorrente <= CURRENT_DATE
        ORDER BY proxima_execucao_recorrente
    ";

    $stmt = $conexao->prepare($sql);
    $stmt->execute();

    $aportes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($aportes as $aporteRecorrente) {

        try {

            $conexao->beginTransaction();

            $idAporteRecorrente =
                $aporteRecorrente["id_aporte_recorrente"];

            $idCarteira =
                $aporteRecorrente["id_carteira"];

            $idAtivo =
                $aporteRecorrente["id_ativo"];

            $valorRecorrente =
                (float) $aporteRecorrente["valor_recorrente"];

            $frequencia =
                $aporteRecorrente["frequencia_recorrente"];

            $dataExecucao =
                $aporteRecorrente["proxima_execucao_recorrente"];


            // Busca a cotação mais recente do ativo
            $cotacao = buscarUltimaCotacao(
                $conexao,
                $idAtivo
            );


            if (!$cotacao) {
                throw new Exception(
                    "Não existe cotação cadastrada para o ativo."
                );
            }


            $precoAtual =
                (float) $cotacao["preco_fechamento_cotacao"];


            if ($precoAtual <= 0) {
                throw new Exception(
                    "A cotação do ativo é inválida."
                );
            }

            // Calcula quantas unidades serão compradas
            $quantidade =
                $valorRecorrente / $precoAtual;

            if ($quantidade <= 0) {
                throw new Exception(
                    "Quantidade calculada inválida."
                );
            }

            //Cria o aporte.

            $sqlInsert = "
                INSERT INTO aporte (
                    id_carteira,
                    id_ativo,
                    tipo_aporte,
                    quantidade_aporte,
                    valor_aporte,
                    recorrencia_aporte,
                    observacao_aporte,
                    data_aporte
                )
                VALUES (
                    :carteira,
                    :ativo,
                    :tipo,
                    :quantidade,
                    :valor,
                    :recorrencia,
                    :observacao,
                    :data
                )
            ";

            $insert = $conexao->prepare($sqlInsert);

            $tipoAporte = "Compra";

            $observacao =
                "Aporte recorrente automático";


            $insert->execute([
                ":carteira" => $idCarteira,
                ":ativo" => $idAtivo,
                ":tipo" => $tipoAporte,
                ":quantidade" => $quantidade,
                ":valor" => $valorRecorrente,
                ":recorrencia" => $frequencia,
                ":observacao" => $observacao,
                ":data" => $dataExecucao
            ]);


            // Atualiza a posição da carteira
            $posicaoAtualizada = atualizarPosicao(
                $conexao,
                $idCarteira,
                $idAtivo,
                $tipoAporte,
                $quantidade,
                $valorRecorrente
            );


            if (!$posicaoAtualizada) {
                throw new Exception(
                    "Não foi possível atualizar a posição da carteira."
                );
            }


            // Calcula a próxima execução
            $proximaExecucao = calcularProximaExecucao(
                $dataExecucao,
                $frequencia
            );


            // Atualiza o aporte recorrente
            $sqlUpdate = "
                UPDATE aporte_recorrente
                SET proxima_execucao_recorrente = :proxima
                WHERE id_aporte_recorrente = :id
            ";

            $update = $conexao->prepare($sqlUpdate);

            $update->execute([
                ":proxima" => $proximaExecucao,
                ":id" => $idAporteRecorrente
            ]);


            $conexao->commit();


            $resultados["processados"]++;

            $resultados["detalhes"][] = [
                "id_aporte_recorrente" => $idAporteRecorrente,
                "status" => "sucesso",
                "valor" => $valorRecorrente,
                "quantidade" => $quantidade,
                "preco" => $precoAtual,
                "proxima_execucao" => $proximaExecucao
            ];
        } catch (Throwable $erro) {

            if ($conexao->inTransaction()) {
                $conexao->rollBack();
            }

            $resultados["erros"]++;

            $resultados["detalhes"][] = [
                "id_aporte_recorrente" => $aporteRecorrente["id_aporte_recorrente"],
                "status" => "erro",
                "mensagem" => $erro->getMessage()
            ];
        }
    }

    return $resultados;
}
