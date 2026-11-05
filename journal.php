<?php
require_once 'config.php';
require_once 'traders_helper.php';

$userId = requireAuth($conn);
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    // Viewing someone else's journal from their profile is read-only and
    // ignores the account filter - it's the whole public journal.
    $viewUserId = (int)($_GET['user_id'] ?? 0);
    $targetUserId = $viewUserId ?: $userId;
    $accountId = $viewUserId ? '' : trim($_GET['trading_account_id'] ?? '');

    if ($accountId !== '') {
        $stmt = mysqli_prepare($conn, "SELECT * FROM journal WHERE user_id = ? AND trading_account_id = ? ORDER BY trade_date DESC, id DESC");
        mysqli_stmt_bind_param($stmt, 'is', $targetUserId, $accountId);
    } else {
        $stmt = mysqli_prepare($conn, "SELECT * FROM journal WHERE user_id = ? ORDER BY trade_date DESC, id DESC");
        mysqli_stmt_bind_param($stmt, 'i', $targetUserId);
    }
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    $entries = [];
    $totalPL = 0;
    while ($row = mysqli_fetch_assoc($result)) {
        $totalPL += (float)$row['profit_loss'];
        $entries[] = $row;
    }
    mysqli_stmt_close($stmt);

    respond(['success' => true, 'entries' => $entries, 'total_profit_loss' => $totalPL, 'is_own' => !$viewUserId || $viewUserId === $userId]);
}

if ($method === 'POST') {
    // Two kinds of POST: create a trade (multipart, has 'symbol'), or a JSON
    // action ('delete_entry'). Account add/delete now lives in traders.php.
    $isMultipart = str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'multipart/form-data');

    if (!$isMultipart) {
        $data = input();
        $action = $data['action'] ?? '';

        if ($action === 'delete_entry') {
            $id = (int)($data['id'] ?? 0);
            if (!$id) {
                respond(['success' => false, 'message' => 'id is required'], 400);
            }

            // Remove the chart image file from disk too, if there is one
            $stmt = mysqli_prepare($conn, "SELECT chart_image FROM journal WHERE id = ? AND user_id = ?");
            mysqli_stmt_bind_param($stmt, 'ii', $id, $userId);
            mysqli_stmt_execute($stmt);
            $existing = mysqli_stmt_get_result($stmt)->fetch_assoc();
            mysqli_stmt_close($stmt);

            if ($existing && !empty($existing['chart_image'])) {
                $path = __DIR__ . '/../' . $existing['chart_image'];
                if (is_file($path)) {
                    @unlink($path);
                }
            }

            $stmt = mysqli_prepare($conn, "DELETE FROM journal WHERE id = ? AND user_id = ?");
            mysqli_stmt_bind_param($stmt, 'ii', $id, $userId);
            mysqli_stmt_execute($stmt);
            $deleted = mysqli_stmt_affected_rows($stmt) > 0;
            mysqli_stmt_close($stmt);
            respond(['success' => $deleted]);
        }

        if ($action === 'update_entry') {
            $id = (int)($data['id'] ?? 0);
            if (!$id) {
                respond(['success' => false, 'message' => 'id is required'], 400);
            }
            $required = ['trading_account_id', 'symbol', 'trade_type', 'entry_price', 'exit_price', 'shares', 'profit_loss'];
            foreach ($required as $field) {
                if (!isset($data[$field]) || trim((string)$data[$field]) === '') {
                    respond(['success' => false, 'message' => "Missing field: $field"], 400);
                }
            }
            if (!in_array($data['trade_type'], ['long', 'short'], true)) {
                respond(['success' => false, 'message' => 'trade_type must be long or short'], 400);
            }
            if (!is_numeric($data['entry_price']) || !is_numeric($data['exit_price']) || !is_numeric($data['profit_loss'])) {
                respond(['success' => false, 'message' => 'Prices and profit/loss must be numbers'], 400);
            }
            $symbol = trim($data['symbol']);
            $lots = trim((string)$data['shares']);
            if (mb_strlen($symbol) > 20 || strlen($lots) > 5) {
                respond(['success' => false, 'message' => 'Symbol or lot size is too long'], 400);
            }
            $accountId = trim($data['trading_account_id']);

            // it must be one of MY trades
            $stmt = mysqli_prepare($conn, "SELECT trading_account_id FROM journal WHERE id = ? AND user_id = ?");
            mysqli_stmt_bind_param($stmt, 'ii', $id, $userId);
            mysqli_stmt_execute($stmt);
            $existing = mysqli_stmt_get_result($stmt)->fetch_assoc();
            mysqli_stmt_close($stmt);
            if (!$existing) {
                respond(['success' => false, 'message' => 'Trade not found'], 404);
            }

            // moving it to another journal tab: that tab must be one of my slots
            if ($accountId !== $existing['trading_account_id']) {
                $stmt = mysqli_prepare($conn, "SELECT username FROM users WHERE user_id = ?");
                mysqli_stmt_bind_param($stmt, 'i', $userId);
                mysqli_stmt_execute($stmt);
                $username = mysqli_stmt_get_result($stmt)->fetch_assoc()['username'] ?? '';
                mysqli_stmt_close($stmt);
                $trader = getOrCreateTrader($conn, $userId, $username);
                [$registered, $registerMessage] = ensureAccountRegistered($conn, $trader, $accountId);
                if (!$registered) {
                    respond(['success' => false, 'message' => $registerMessage], 400);
                }
            }

            $strategy = trim((string)($data['strategy'] ?? ''));
            $strategy = $strategy === '' ? null : $strategy;
            $notes = trim((string)($data['notes'] ?? ''));
            $notes = $notes === '' ? null : $notes;
            $tradeType = $data['trade_type'];
            $entryPrice = (float)$data['entry_price'];
            $exitPrice = (float)$data['exit_price'];
            $profitLoss = (float)$data['profit_loss'];

            // the chart image is never changed here
            $stmt = mysqli_prepare($conn, "UPDATE journal SET trading_account_id = ?, symbol = ?, trade_type = ?, entry_price = ?, exit_price = ?, shares = ?, profit_loss = ?, strategy = ?, notes = ? WHERE id = ? AND user_id = ?");
            mysqli_stmt_bind_param($stmt, 'sssddsdssii', $accountId, $symbol, $tradeType, $entryPrice, $exitPrice, $lots, $profitLoss, $strategy, $notes, $id, $userId);
            if (!mysqli_stmt_execute($stmt)) {
                respond(['success' => false, 'message' => 'Failed to update trade'], 500);
            }
            mysqli_stmt_close($stmt);

            $stmt = mysqli_prepare($conn, "SELECT * FROM journal WHERE id = ? AND user_id = ?");
            mysqli_stmt_bind_param($stmt, 'ii', $id, $userId);
            mysqli_stmt_execute($stmt);
            $entry = mysqli_stmt_get_result($stmt)->fetch_assoc();
            mysqli_stmt_close($stmt);
            respond(['success' => true, 'entry' => $entry]);
        }

        respond(['success' => false, 'message' => 'Unknown action'], 400);
    }

    // --- Create a new trade (multipart form so it can include an image) ---
    $required = ['trading_account_id', 'trade_date', 'symbol', 'trade_type', 'entry_price', 'exit_price', 'shares', 'profit_loss'];
    foreach ($required as $field) {
        if (!isset($_POST[$field]) || $_POST[$field] === '') {
            respond(['success' => false, 'message' => "Missing field: $field"], 400);
        }
    }
    if (!in_array($_POST['trade_type'], ['long', 'short'], true)) {
        respond(['success' => false, 'message' => 'trade_type must be long or short'], 400);
    }

    $accountId = trim($_POST['trading_account_id']);

    $chartImage = null; // full relative path stored in DB, e.g. uploads/journal/abc123.jpg
    if (isset($_FILES['chart_image']) && $_FILES['chart_image']['error'] === UPLOAD_ERR_OK) {
        $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        $ext = strtolower(pathinfo($_FILES['chart_image']['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed, true)) {
            respond(['success' => false, 'message' => 'Invalid chart image type'], 400);
        }
        $filename = bin2hex(random_bytes(8)) . '.' . $ext;
        $destDir = __DIR__ . '/../uploads/journal/';
        if (!is_dir($destDir)) {
            mkdir($destDir, 0755, true);
        }
        if (!move_uploaded_file($_FILES['chart_image']['tmp_name'], $destDir . $filename)) {
            respond(['success' => false, 'message' => 'Chart image upload failed'], 500);
        }
        $chartImage = 'uploads/journal/' . $filename;
    }

    $stmt = mysqli_prepare($conn, "SELECT username FROM users WHERE user_id = ?");
    mysqli_stmt_bind_param($stmt, 'i', $userId);
    mysqli_stmt_execute($stmt);
    $username = mysqli_stmt_get_result($stmt)->fetch_assoc()['username'] ?? '';
    mysqli_stmt_close($stmt);

    // Make sure this account occupies one of the trader's 12 slots
    $trader = getOrCreateTrader($conn, $userId, $username);
    [$registered, $registerMessage] = ensureAccountRegistered($conn, $trader, $accountId);
    if (!$registered) {
        respond(['success' => false, 'message' => $registerMessage], 400);
    }
    $traderId = $trader['id'];

    $strategy = $_POST['strategy'] ?? null;
    $notes = $_POST['notes'] ?? null;

    $stmt = mysqli_prepare($conn, "INSERT INTO journal
        (user_id, trader_id, username, trading_account_id, trade_date, symbol, trade_type, entry_price, exit_price, shares, profit_loss, strategy, notes, chart_image)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    mysqli_stmt_bind_param(
        $stmt,
        'iisssssddsdsss',
        $userId, $traderId, $username, $accountId, $_POST['trade_date'], $_POST['symbol'], $_POST['trade_type'],
        $_POST['entry_price'], $_POST['exit_price'], $_POST['shares'], $_POST['profit_loss'], $strategy, $notes, $chartImage
    );

    if (!mysqli_stmt_execute($stmt)) {
        respond(['success' => false, 'message' => 'Failed to save trade'], 500);
    }
    $id = mysqli_insert_id($conn);
    mysqli_stmt_close($stmt);

    respond(['success' => true, 'id' => $id, 'chart_image' => $chartImage]);
}

respond(['success' => false, 'message' => 'Method not allowed'], 405);