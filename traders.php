<?php
require_once 'config.php';
require_once 'traders_helper.php';

$userId = requireAuth($conn);

$stmt = mysqli_prepare($conn, "SELECT username FROM users WHERE user_id = ?");
mysqli_stmt_bind_param($stmt, 'i', $userId);
mysqli_stmt_execute($stmt);
$username = mysqli_stmt_get_result($stmt)->fetch_assoc()['username'] ?? '';
mysqli_stmt_close($stmt);

$trader = getOrCreateTrader($conn, $userId, $username);

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    respond(['success' => true, 'accounts' => traderAccountNames($trader)]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = input();
    $action = $data['action'] ?? '';
    $accountName = trim($data['trading_account_id'] ?? '');

    if ($accountName === '') {
        respond(['success' => false, 'message' => 'trading_account_id is required'], 400);
    }

    if ($action === 'add') {
        [$ok, $message] = ensureAccountRegistered($conn, $trader, $accountName);
        if (!$ok) {
            respond(['success' => false, 'message' => $message], 400);
        }
        respond(['success' => true, 'accounts' => traderAccountNames($trader)]);
    }

    if ($action === 'delete') {
        clearAccountSlot($conn, $trader, $accountName);

        // Cascade: remove every trade logged under this account
        $stmt = mysqli_prepare($conn, "DELETE FROM journal WHERE user_id = ? AND trading_account_id = ?");
        mysqli_stmt_bind_param($stmt, 'is', $userId, $accountName);
        mysqli_stmt_execute($stmt);
        $deletedTrades = mysqli_stmt_affected_rows($stmt);
        mysqli_stmt_close($stmt);

        respond(['success' => true, 'accounts' => traderAccountNames($trader), 'deleted_trades' => $deletedTrades]);
    }

    respond(['success' => false, 'message' => 'Unknown action'], 400);
}

respond(['success' => false, 'message' => 'Method not allowed'], 405);
