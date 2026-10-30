<?php
require_once __DIR__ . "/../Repositories/CarteiraRepository.php";

function patrimonioCarteirasService(PDO $conexao,$idUsuario): array
{
    try {
        // 1. Buscar as posições de ações do usuário no banco.
        $posicoes = buscarPosicoesAcoes($conexao, $idUsuario);

        // Resposta padrão para carteiras sem ações.
        $resumoVazio = [
            'total_investido' => 0,
            'patrimonio_atual' => 0,
            'lucro_prejuizo' => 0,
            'rentabilidade_percentual' => 0
        ];

        if (empty($posicoes)) {
            return [
                'success' => true,
                'message' => 'O usuário não possui ações na carteira.',
                'acoes' => [],
                'resumo' => $resumoVazio
            ];
        }

        // 2. Organizar os tickers e as posições.
        $tickers = [];
        $posicoesPorTicker = [];

        foreach ($posicoes as $posicao) {
            $ticker = strtoupper(
                trim((string) ($posicao['ticker'] ?? ''))
            );

            if (
                $ticker === '' ||
                !preg_match('/^[A-Z0-9.^_-]{1,24}$/', $ticker)
            ) {
                continue;
            }

            $quantidade = $posicao['quantidade'] ?? null;
            $precoMedio = $posicao['preco_medio'] ?? null;

            if (
                !is_numeric($quantidade) ||
                !is_numeric($precoMedio)
            ) {
                continue;
            }

            $posicao['ticker'] = $ticker;
            $posicao['quantidade'] = (float) $quantidade;
            $posicao['preco_medio'] = (float) $precoMedio;

            $tickers[] = $ticker;
            $posicoesPorTicker[$ticker][] = $posicao;
        }

        $tickers = array_values(array_unique($tickers));

        if (empty($tickers)) {
            return [
                'success' => true,
                'message' => 'Nenhum ticker válido encontrado.',
                'acoes' => [],
                'resumo' => $resumoVazio
            ];
        }

        // 3. Consultar as cotações na brapi.
        $resultadoBrapi = brapiRequest(
            'v2/stocks/quote',
            [
                'symbols' => implode(',', $tickers)
            ]
        );

        if (empty($resultadoBrapi['ok'])) {
            error_log(
                'Erro ao consultar patrimônio na brapi: ' .
                ($resultadoBrapi['error'] ?? 'Erro desconhecido')
            );

            return [
                'success' => false,
                'message' => 'Não foi possível consultar as cotações das ações.',
                'http_status' => 502
            ];
        }

        // 4. Decodificar a resposta da brapi.
        $dadosBrapi = json_decode(
            $resultadoBrapi['body'] ?? '',
            true
        );

        if (!is_array($dadosBrapi)) {
            return [
                'success' => false,
                'message' => 'A brapi retornou um JSON inválido.',
                'http_status' => 502
            ];
        }

        $cotacoes = $dadosBrapi['results']
            ?? $dadosBrapi['result']
            ?? [];

        if (!is_array($cotacoes)) {
            $cotacoes = [];
        }

        // 5. Indexar as cotações pelo ticker.
        $cotacoesPorTicker = [];

        foreach ($cotacoes as $cotacao) {
            if (!is_array($cotacao)) {
                continue;
            }

            $tickerCotacao = strtoupper(
                trim((string) ($cotacao['symbol'] ?? ''))
            );

            if ($tickerCotacao !== '') {
                $cotacoesPorTicker[$tickerCotacao] = $cotacao;
            }
        }

        // 6. Calcular o patrimônio de cada ação.
        $acoes = [];

        $totalInvestido = 0.0;
        $patrimonioAtual = 0.0;

        foreach ($posicoesPorTicker as $ticker => $listaPosicoes) {
            $cotacao = $cotacoesPorTicker[$ticker] ?? null;

            $precoAtual = null;

            if (
                is_array($cotacao) &&
                isset($cotacao['regularMarketPrice']) &&
                is_numeric($cotacao['regularMarketPrice'])
            ) {
                $precoAtual = (float) $cotacao['regularMarketPrice'];
            }

            $quantidadeTotal = 0.0;
            $valorInvestidoAcao = 0.0;

            foreach ($listaPosicoes as $posicao) {
                $quantidade = $posicao['quantidade'];
                $precoMedio = $posicao['preco_medio'];

                $quantidadeTotal += $quantidade;

                $valorInvestidoAcao +=
                    $quantidade * $precoMedio;
            }

            // Calcular preço médio ponderado.
            $precoMedioPonderado = $quantidadeTotal > 0
                ? $valorInvestidoAcao / $quantidadeTotal
                : 0.0;

            $nomeAtivo = $listaPosicoes[0]['nome_ativo']
                ?? $ticker;

            // Se não houver cotação, não inventar um preço atual.
            if ($precoAtual === null) {
                $acoes[] = [
                    'ticker' => $ticker,
                    'nome_ativo' => $nomeAtivo,
                    'quantidade' => $quantidadeTotal,
                    'preco_medio' => round($precoMedioPonderado, 4),
                    'preco_atual' => null,
                    'valor_investido' => round($valorInvestidoAcao, 2),
                    'valor_atual' => null,
                    'lucro_prejuizo' => null,
                    'rentabilidade_percentual' => null,
                    'cotacao_disponivel' => false
                ];

                // Não incluir essa ação nos cálculos do patrimônio atual.
                continue;
            }

            $valorAtualAcao = $quantidadeTotal * $precoAtual;

            $lucroPrejuizo =
                $valorAtualAcao - $valorInvestidoAcao;

            $rentabilidade = $valorInvestidoAcao > 0
                ? ($lucroPrejuizo / $valorInvestidoAcao) * 100
                : 0.0;

            $acoes[] = [
                'ticker' => $ticker,
                'nome_ativo' => $nomeAtivo,
                'quantidade' => $quantidadeTotal,
                'preco_medio' => round($precoMedioPonderado, 4),
                'preco_atual' => $precoAtual,
                'valor_investido' => round($valorInvestidoAcao, 2),
                'valor_atual' => round($valorAtualAcao, 2),
                'lucro_prejuizo' => round($lucroPrejuizo, 2),
                'rentabilidade_percentual' => round($rentabilidade, 2),
                'cotacao_disponivel' => true
            ];

            // Somar somente ações com cotação disponível.
            $totalInvestido += $valorInvestidoAcao;
            $patrimonioAtual += $valorAtualAcao;
        }

        // 7. Calcular o resumo do patrimônio.
        $lucroTotal = $patrimonioAtual - $totalInvestido;

        $rentabilidadeTotal = $totalInvestido > 0
            ? ($lucroTotal / $totalInvestido) * 100
            : 0.0;

        // 8. Retornar os dados para o Controller.
        return [
            'success' => true,
            'message' => 'Patrimônio consultado com sucesso.',
            'acoes' => $acoes,
            'resumo' => [
                'total_investido' => round($totalInvestido, 2),
                'patrimonio_atual' => round($patrimonioAtual, 2),
                'lucro_prejuizo' => round($lucroTotal, 2),
                'rentabilidade_percentual' => round($rentabilidadeTotal, 2)
            ]
        ];

    } catch (Throwable $erro) {
        error_log(
            'Erro em patrimonioCarteirasService: ' .
            $erro->getMessage()
        );

        return [
            'success' => false,
            'message' => 'Erro ao consultar o patrimônio das ações.',
            'http_status' => 500
        ];
    }
}