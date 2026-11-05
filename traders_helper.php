<?php
// mobile-api/traders_helper.php - include this, don't call directly.

const TRADER_ACCOUNT_SLOTS = 12;

/**
 * Fetch the traders row for this user, creating an empty one if it doesn't
 * exist yet. Returns the full row as an assoc array.
 */
function getOrCreateTrader(mysqli $conn, int $userId, string $username): array {
    $stmt = mysqli_prepare($conn, "SELECT * FROM traders WHERE user_id = ? LIMIT 1");
    mysqli_stmt_bind_param($stmt, 'i', $userId);
    mysqli_stmt_execute($stmt);
    $trader = mysqli_stmt_get_result($stmt)->fetch_assoc();
    mysqli_stmt_close($stmt);

    if ($trader) {
        return $trader;
    }

    $stmt = mysqli_prepare($conn, "INSERT INTO traders (user_id, username) VALUES (?, ?)");
    mysqli_stmt_bind_param($stmt, 'is', $userId, $username);
    mysqli_stmt_execute($stmt);
    $newId = mysqli_insert_id($conn);
    mysqli_stmt_close($stmt);

    $stmt = mysqli_prepare($conn, "SELECT * FROM traders WHERE id = ? LIMIT 1");
    mysqli_stmt_bind_param($stmt, 'i', $newId);
    mysqli_stmt_execute($stmt);
    $trader = mysqli_stmt_get_result($stmt)->fetch_assoc();
    mysqli_stmt_close($stmt);

    return $trader;
}

/** Returns the non-empty account names currently registered for this trader row. */
function traderAccountNames(array $trader): array {
    $names = [];
    for ($i = 1; $i <= TRADER_ACCOUNT_SLOTS; $i++) {
        $val = $trader["trading_account_id{$i}"] ?? null;
        if ($val !== null && $val !== '') {
            $names[] = $val;
        }
    }
    return $names;
}

/**
 * Make sure $accountName occupies one of the trader's 12 slots. If it's
 * already registered, does nothing. If not, fills the first empty slot.
 * Returns [ok, message] - ok=false with a message when all 12 slots are full.
 */
function ensureAccountRegistered(mysqli $conn, array &$trader, string $accountName): array {

    // Already registered?
    if (in_array($accountName, traderAccountNames($trader), true)) {
        return [true, null];
    }

    // Check if user is Pro
    $stmt = mysqli_prepare($conn, "SELECT is_pro FROM users WHERE user_id = ? LIMIT 1");
    mysqli_stmt_bind_param($stmt, 'i', $trader['user_id']);
    mysqli_stmt_execute($stmt);

    $result = mysqli_stmt_get_result($stmt);
    $user = mysqli_fetch_assoc($result);
    mysqli_stmt_close($stmt);

    $isPro = (int)($user['is_pro'] ?? 0);

    // Free users = 4 accounts, Pro users = 12 accounts
    $maxSlots = $isPro ? 12 : 4;

    // Find first available slot
    for ($i = 1; $i <= $maxSlots; $i++) {
        $col = "trading_account_id{$i}";

        if (empty($trader[$col])) {
            $stmt = mysqli_prepare($conn, "UPDATE traders SET `$col` = ? WHERE id = ?");
            mysqli_stmt_bind_param($stmt, 'si', $accountName, $trader['id']);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);

            $trader[$col] = $accountName;
            return [true, null];
        }
    }

    // No slots left
    if (!$isPro) {
        return [false, 'Maximum Account (4) reached. Upgrade to Pro to add up to 12 accounts.'];
    }

    return [false, 'Maximum of 12 trading accounts reached. Delete one before adding another. Hold to trading account tab to delete .'];
}
/** Clears whichever slot holds $accountName (if any). Returns true if a slot was cleared. */
function clearAccountSlot(mysqli $conn, array &$trader, string $accountName): bool {
    for ($i = 1; $i <= TRADER_ACCOUNT_SLOTS; $i++) {
        $col = "trading_account_id{$i}";
        if (($trader[$col] ?? null) === $accountName) {
            $stmt = mysqli_prepare($conn, "UPDATE traders SET `$col` = NULL WHERE id = ?");
            mysqli_stmt_bind_param($stmt, 'i', $trader['id']);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
            $trader[$col] = null;
            return true;
        }
    }
    return false;
}
