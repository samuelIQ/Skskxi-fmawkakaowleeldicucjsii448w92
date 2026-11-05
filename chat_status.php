<?php
// mobile-api/chat_status.php
// GET  -> without user_id: am I registered on Gist yet? (drives the
//         "register with Gist" prompt the first time the tab is opened)
//         with ?user_id=X: that user's online/last_seen status, for the
//         conversation header ("Online" / "Last seen ...").
// POST -> online/offline heartbeat. Call on app foreground/open Gist
//         with action=online, and on app background/logout with
//         action=offline. Ping every ~30-60s while the Gist screen or a
//         conversation is open to keep last_seen fresh.
require_once 'config.php';

$userId = requireAuth($conn);

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $targetUserId = (int)($_GET['user_id'] ?? 0);
    $lookupId = $targetUserId ?: $userId;

    $stmt = mysqli_prepare($conn, "SELECT is_online, last_seen FROM chat_users WHERE user_id = ?");
    mysqli_stmt_bind_param($stmt, 'i', $lookupId);
    mysqli_stmt_execute($stmt);
    $row = mysqli_stmt_get_result($stmt)->fetch_assoc();
    mysqli_stmt_close($stmt);

    if ($targetUserId) {
        respond(['success' => true, 'is_online' => (bool)($row['is_online'] ?? false), 'last_seen' => $row['last_seen'] ?? null]);
    }

    respond(['success' => true, 'registered' => (bool)$row, 'is_online' => (bool)($row['is_online'] ?? false)]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = input();
    $online = ($data['action'] ?? 'online') !== 'offline';

    $stmt = mysqli_prepare($conn, "UPDATE chat_users SET is_online = ?, last_seen = NOW() WHERE user_id = ?");
    $onlineInt = $online ? 1 : 0;
    mysqli_stmt_bind_param($stmt, 'ii', $onlineInt, $userId);
    mysqli_stmt_execute($stmt);
    $affected = mysqli_stmt_affected_rows($stmt);
    mysqli_stmt_close($stmt);

    if ($affected === 0) {
        respond(['success' => false, 'message' => 'Not registered on Gist yet'], 404);
    }

    respond(['success' => true]);
}

respond(['success' => false, 'message' => 'Method not allowed'], 405);
