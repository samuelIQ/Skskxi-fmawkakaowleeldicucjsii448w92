<?php
// mobile-api/chat_register.php
// First-time "Register with Nothyp Gist" action. Adds the user to
// chat_users so they become messageable and get a Gist inbox.
require_once 'config.php';

$userId = requireAuth($conn);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(['success' => false, 'message' => 'POST required'], 405);
}

$stmt = mysqli_prepare($conn, "SELECT chat_user_id FROM chat_users WHERE user_id = ?");
mysqli_stmt_bind_param($stmt, 'i', $userId);
mysqli_stmt_execute($stmt);
$existing = mysqli_stmt_get_result($stmt)->fetch_assoc();
mysqli_stmt_close($stmt);

if (!$existing) {
    $stmt = mysqli_prepare($conn, "INSERT INTO chat_users (user_id, is_online, last_seen) VALUES (?, 1, NOW())");
    mysqli_stmt_bind_param($stmt, 'i', $userId);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
}

respond(['success' => true, 'registered' => true]);
