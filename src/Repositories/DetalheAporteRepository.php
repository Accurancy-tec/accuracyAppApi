<?php

// Detalhes de aporte por tipo de investimento.
//
//  - fixed_income_contribution_details    -> condicoes contratadas de uma aplicacao em renda fixa
//  - variable_income_contribution_details -> quantidade e preco unitario de uma operacao de renda variavel
//
// O valor total continua em aporte.valor_aporte. Estas tabelas sao opcionais em tempo de
// execucao: enquanto a migracao SQL nao for aplicada, o fluxo de acoes e FIIs continua igual.

const CATEGORIA_RENDA_FIXA = 'Renda Fixa';

// Mensagem segura para o usuario (diferente de erros tecnicos, que ficam apenas no log).
class AporteValidacaoException extends InvalidArgumentException
{
}

function tabelasDetalheAporteExistem(PDO $conexao): bool
{
    static $existem = null;

    if ($existem === null) {
        $sql = "SELECT CASE
                    WHEN to_regclass('public.fixed_income_contribution_details') IS NOT NULL
                     AND to_regclass('public.variable_income_contribution_details') IS NOT NULL
                    THEN 1 ELSE 0 END";
        $existem = ((int) $conexao->query($sql)->fetchColumn()) === 1;
    }

    return $existem;
}

function buscarTituloRendaFixaAtivo(PDO $conexao, int $idTitulo): ?array
{
    $stmt = $conexao->prepare(
        "SELECT id_titulo, nome_titulo, rentabilidade, indexador,
                aplicacao_minima, vencimento, liquidez
         FROM titulos_renda_fixa
         WHERE id_titulo = :id AND ativo = TRUE"
    );
    $stmt->execute([':id' => $idTitulo]);
    $titulo = $stmt->fetch(PDO::FETCH_ASSOC);

    return $titulo ?: null;
}

function simboloAtivoRendaFixa(int $idTitulo): string
{
    return 'RF-' . $idTitulo;
}

// Valida a aplicacao em renda fixa e devolve os dados que a API deve gravar.
// Simbolo, nome e condicoes vem SEMPRE do cadastro do titulo, nunca do aplicativo.
//
// Modelagem da posicao: 1 unidade = R$ 1,00 aplicado (quantidade = valor, preco unitario = 1).
// Assim item_investimento, o donut da carteira e o grafico de patrimonio (que usam
// quantidade x preco) continuam somando exatamente o valor aplicado.
function prepararAporteRendaFixa(PDO $conexao, array $dados, string $hoje): array
{
    if (!tabelasDetalheAporteExistem($conexao)) {
        error_log('Renda fixa bloqueada: tabelas de detalhes de aporte nao existem (migracao SQL pendente).');
        throw new AporteValidacaoException('Renda fixa indisponível no momento. Tente novamente mais tarde.');
    }

    $idTitulo = filter_var($dados['id_titulo'] ?? null, FILTER_VALIDATE_INT);
    if ($idTitulo === false || $idTitulo === null || $idTitulo <= 0) {
        throw new AporteValidacaoException('Selecione um título de renda fixa válido.');
    }

    if (($dados['tipo_aporte'] ?? null) !== 'Compra') {
        throw new AporteValidacaoException('Nesta versão só é possível aplicar em renda fixa.');
    }

    $recorrencia = $dados['recorrencia_aporte'] ?? 'Único';
    if ($recorrencia !== 'Único') {
        throw new AporteValidacaoException('Aplicações recorrentes em renda fixa ainda não estão disponíveis.');
    }

    $valorInformado = $dados['valor_aporte'] ?? null;
    if (!is_numeric($valorInformado) || !is_finite((float) $valorInformado)) {
        throw new AporteValidacaoException('Informe um valor válido para a aplicação.');
    }
    $valor = round((float) $valorInformado, 2);
    if ($valor <= 0) {
        throw new AporteValidacaoException('Informe um valor maior que zero.');
    }

    $titulo = buscarTituloRendaFixaAtivo($conexao, $idTitulo);
    if ($titulo === null) {
        throw new AporteValidacaoException('Título de renda fixa não encontrado ou indisponível.');
    }

    if ((string) $titulo['vencimento'] <= $hoje) {
        throw new AporteValidacaoException('Este título já venceu e não aceita novas aplicações.');
    }

    $minimo = (float) $titulo['aplicacao_minima'];
    if ($valor + 0.00001 < $minimo) {
        throw new AporteValidacaoException(
            'O valor mínimo para este título é R$ ' . number_format($minimo, 2, ',', '.') . '.'
        );
    }

    return [
        'simbolo' => simboloAtivoRendaFixa($idTitulo),
        'nome' => (string) $titulo['nome_titulo'],
        'categoria' => CATEGORIA_RENDA_FIXA,
        'quantidade' => $valor,
        'valor' => $valor,
        'titulo' => $titulo,
    ];
}

function registrarDetalheRendaFixa(PDO $conexao, int $idAporte, array $titulo): void
{
    $stmt = $conexao->prepare(
        "INSERT INTO fixed_income_contribution_details
            (contribution_id, fixed_income_title_id, contracted_rate, rate_index, maturity_date, liquidity_terms)
         VALUES (:aporte, :titulo, :taxa, :indexador, :vencimento, :liquidez)"
    );
    $stmt->execute([
        ':aporte' => $idAporte,
        ':titulo' => (int) $titulo['id_titulo'],
        ':taxa' => $titulo['rentabilidade'],
        ':indexador' => $titulo['indexador'],
        ':vencimento' => $titulo['vencimento'],
        ':liquidez' => $titulo['liquidez'],
    ]);
}

// Preco unitario efetivo = valor total / quantidade (regra usada pela posicao da carteira).
// Se o app enviar o preco cotado, ele so e aceito quando coerente com o total (tolerancia de 2%
// para as taxas estimadas que o app soma ao total); caso contrario usa o preco efetivo.
function calcularPrecoUnitarioRendaVariavel($quantidade, $valorTotal, $precoInformado): ?float
{
    $qtd = is_numeric($quantidade) ? (float) $quantidade : 0.0;
    $total = is_numeric($valorTotal) ? (float) $valorTotal : 0.0;

    if ($qtd <= 0 || $total <= 0) {
        return null;
    }

    $efetivo = $total / $qtd;

    if (is_numeric($precoInformado) && (float) $precoInformado > 0) {
        $preco = (float) $precoInformado;
        if (abs($preco - $efetivo) <= $efetivo * 0.02) {
            return round($preco, 8);
        }
        error_log('preco_unitario divergente do total/quantidade; usando preco efetivo.');
    }

    return round($efetivo, 8);
}

// Detalhe de renda variavel e complementar: se falhar, a operacao principal nao e perdida
// (SAVEPOINT) e o problema fica registrado no log do servidor.
function registrarDetalheRendaVariavel(PDO $conexao, int $idAporte, string $tipoAporte, $quantidade, $valorTotal, $precoInformado): void
{
    if (!tabelasDetalheAporteExistem($conexao)) {
        return;
    }

    $ehOperacao = in_array($tipoAporte, ['Compra', 'Venda'], true);
    $preco = $ehOperacao ? calcularPrecoUnitarioRendaVariavel($quantidade, $valorTotal, $precoInformado) : null;
    $qtd = ($ehOperacao && $preco !== null) ? (float) $quantidade : null;

    if ($ehOperacao && $preco === null) {
        error_log("Detalhe de renda variavel ignorado (aporte $idAporte): quantidade/valor invalidos.");
        return;
    }

    $conexao->exec('SAVEPOINT detalhe_renda_variavel');
    try {
        $stmt = $conexao->prepare(
            "INSERT INTO variable_income_contribution_details (contribution_id, quantity, unit_price)
             VALUES (:aporte, :quantidade, :preco)"
        );
        $stmt->execute([':aporte' => $idAporte, ':quantidade' => $qtd, ':preco' => $preco]);
        $conexao->exec('RELEASE SAVEPOINT detalhe_renda_variavel');
    } catch (Throwable $erro) {
        $conexao->exec('ROLLBACK TO SAVEPOINT detalhe_renda_variavel');
        error_log("Detalhe de renda variavel nao gravado (aporte $idAporte): " . $erro->getMessage());
    }
}

// Anexa os detalhes a uma lista de aportes (cada item precisa de id_aporte).
// Qualquer falha aqui apenas omite os detalhes; o historico nunca deixa de carregar.
function anexarDetalhesAportes(PDO $conexao, array $aportes): array
{
    if (!$aportes) {
        return $aportes;
    }

    try {
        if (!tabelasDetalheAporteExistem($conexao)) {
            return $aportes;
        }

        $ids = array_values(array_unique(array_map('intval', array_column($aportes, 'id_aporte'))));
        if (!$ids) {
            return $aportes;
        }
        $marcadores = implode(',', array_fill(0, count($ids), '?'));

        $stmt = $conexao->prepare(
            "SELECT contribution_id, fixed_income_title_id, contracted_rate, rate_index, maturity_date, liquidity_terms
             FROM fixed_income_contribution_details WHERE contribution_id IN ($marcadores)"
        );
        $stmt->execute($ids);
        $rendaFixa = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $linha) {
            $rendaFixa[(int) $linha['contribution_id']] = [
                'id_titulo' => (int) $linha['fixed_income_title_id'],
                'taxa_contratada' => (float) $linha['contracted_rate'],
                'indexador' => $linha['rate_index'],
                'vencimento' => $linha['maturity_date'],
                'liquidez' => $linha['liquidity_terms'],
            ];
        }

        $stmt = $conexao->prepare(
            "SELECT contribution_id, quantity, unit_price
             FROM variable_income_contribution_details WHERE contribution_id IN ($marcadores)"
        );
        $stmt->execute($ids);
        $rendaVariavel = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $linha) {
            $rendaVariavel[(int) $linha['contribution_id']] = [
                'quantidade' => $linha['quantity'] === null ? null : (float) $linha['quantity'],
                'preco_unitario' => $linha['unit_price'] === null ? null : (float) $linha['unit_price'],
            ];
        }

        foreach ($aportes as &$aporte) {
            $id = (int) ($aporte['id_aporte'] ?? 0);
            if (isset($rendaFixa[$id])) {
                $aporte['detalhe_renda_fixa'] = $rendaFixa[$id];
            }
            if (isset($rendaVariavel[$id])) {
                $aporte['detalhe_renda_variavel'] = $rendaVariavel[$id];
            }
        }
        unset($aporte);
    } catch (Throwable $erro) {
        error_log('Falha ao anexar detalhes de aportes: ' . $erro->getMessage());
    }

    return $aportes;
}
