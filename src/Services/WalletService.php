<?php

require_once __DIR__ . "/../Repositories/AporteRepository.php";
require_once __DIR__ . "/../Repositories/PosicaoRepository.php";
require_once __DIR__ . "/FormatService.php";
require_once __DIR__ . "/PatrimonioService.php";

const WALLET_NAME_MAX_LENGTH = 60;
const WALLET_DELETION_SALE_NOTE = "Venda automática ao excluir a carteira";

/** A business rule prevents the deletion; the message is shown to the user. */
final class WalletDeletionBlockedException extends RuntimeException
{
}

function normalizeWalletName(string $name): string
{
    return preg_replace("/\s+/u", " ", trim($name));
}

function isValidWalletName(string $name): bool
{
    return $name !== "" && mb_strlen($name, "UTF-8") <= WALLET_NAME_MAX_LENGTH;
}

/** Returns false when the wallet does not exist, belongs to someone else or was deleted. */
function renameWallet(PDO $connection, int $userId, int $walletId, string $name): bool
{
    $statement = $connection->prepare("
        UPDATE carteira SET nome_carteira = :name
         WHERE id_carteira = :wallet_id AND id_usuario = :user_id AND deleted_at IS NULL
    ");
    $statement->execute([":name" => $name, ":wallet_id" => $walletId, ":user_id" => $userId]);

    return $statement->rowCount() > 0;
}

/**
 * Sells every open position at the current price and hides the wallet. Its operations stay in the
 * history. A real wallet with fixed income is kept: the bonds cannot be redeemed before maturity.
 *
 * Returns ["sold" => number of assets sold, "amount" => total of the sales].
 */
function deleteWalletSellingPositions(PDO $connection, int $userId, int $walletId): array
{
    $netWorth = montarPatrimonioUsuario($connection, $userId, ["id_carteira" => $walletId]);
    $wallet = $netWorth["carteiras"][0] ?? null;
    if ($wallet === null || (int) $wallet["id_carteira"] !== $walletId) {
        throw new WalletDeletionBlockedException("Carteira não encontrada.");
    }

    $simulated = $wallet["tipo_carteira"] === "Simulada";
    $positions = array_filter($wallet["ativos"] ?? [], static fn(array $asset): bool => (float) $asset["quantidade"] > 0);
    $fixedIncome = array_filter($positions, static fn(array $asset): bool => $asset["categoria_ativo"] === CATEGORIA_RENDA_FIXA);

    if (!$simulated && $fixedIncome) {
        throw new WalletDeletionBlockedException(
            "Esta carteira tem renda fixa aplicada. No simulador os títulos ficam até o vencimento "
            . "e não podem ser vendidos, por isso uma carteira real com renda fixa não pode ser excluída."
        );
    }

    $today = (new DateTimeImmutable("now", new DateTimeZone("America/Sao_Paulo")))->format("Y-m-d");
    $sold = 0;
    $total = 0.0;

    $connection->beginTransaction();
    try {
        foreach ($positions as $asset) {
            if ($asset["categoria_ativo"] === CATEGORIA_RENDA_FIXA) {
                continue;
            }
            $quantity = (float) $asset["quantidade"];
            $price = (float) $asset["preco_atual"] > 0 ? (float) $asset["preco_atual"] : (float) $asset["preco_medio"];
            $amount = round($quantity * $price, 2);
            if ($amount <= 0) {
                continue;
            }

            $created = criarAporte($connection, $walletId, (int) $asset["id_ativo"], "Venda", $quantity, $amount, "Único", WALLET_DELETION_SALE_NOTE, $today);
            if (!atualizarPosicao($connection, $walletId, (int) $asset["id_ativo"], "Venda", $quantity, $amount)) {
                throw new RuntimeException("Position of asset {$asset["id_ativo"]} could not be closed.");
            }
            $contributionId = (int) ($created[0]["id_aporte"] ?? 0);
            if ($contributionId > 0) {
                registrarDetalheRendaVariavel($connection, $contributionId, "Venda", $quantity, $amount, $price);
            }

            $sold++;
            $total += $amount;
        }

        $connection->prepare("UPDATE aporte_recorrente SET ativo_flag_recorrente = FALSE WHERE id_carteira = :wallet_id")
            ->execute([":wallet_id" => $walletId]);

        $statement = $connection->prepare("
            UPDATE carteira SET deleted_at = NOW()
             WHERE id_carteira = :wallet_id AND id_usuario = :user_id AND deleted_at IS NULL
        ");
        $statement->execute([":wallet_id" => $walletId, ":user_id" => $userId]);
        if ($statement->rowCount() === 0) {
            throw new WalletDeletionBlockedException("Carteira não encontrada.");
        }

        $connection->commit();
    } catch (Throwable $error) {
        if ($connection->inTransaction()) {
            $connection->rollBack();
        }
        throw $error;
    }

    return ["sold" => $sold, "amount" => round($total, 2)];
}

function walletDeletedMessage(bool $simulated, array $result): string
{
    if ($result["sold"] === 0) {
        return $simulated ? "Simulação excluída." : "Carteira excluída.";
    }

    $sales = formatCount($result["sold"], "ativo foi vendido", "ativos foram vendidos") . " por " . formatCurrency($result["amount"]);
    return $simulated
        ? "Simulação excluída. $sales com dinheiro fictício."
        : "Carteira excluída. $sales e o valor voltou para o seu saldo. As operações continuam no histórico.";
}
