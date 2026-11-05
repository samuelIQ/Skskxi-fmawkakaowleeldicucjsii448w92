<?php
// GET  -> list of people I blocked
// POST -> {user_id, action: "block" | "unblock"}
require_once 'config.php';

$userId = requireAuth($conn);

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $stmt = mysqli_prepare($conn,
        "SELECT u.user_id, u.username, u.full_name, u.profile_image
         FROM user_blocks b JOIN users u ON u.user_id = b.blocked_id
         WHERE b.blocker_id = ? ORDER BY b.created_at DESC");
    mysqli_stmt_bind_param($stmt, 'i', $userId);
    mysqli_stmt_execute($stmt);
    $users = mysqli_stmt_get_result($stmt)->fetch_all(MYSQLI_ASSOC);
    mysqli_stmt_close($stmt);
    respond(['success' => true, 'users' => $users]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(['success' => false, 'message' => 'GET or POST required'], 405);
}

$data = input();
$targetId = (int)($data['user_id'] ?? 0);
$action = $data['action'] ?? 'block';

if (!$targetId) {
    respond(['success' => false, 'message' => 'Could not find that user'], 400);
}
if ($targetId === $userId) {
    respond(['success' => false, 'message' => "You can't block yourself"], 400);
}

if ($action === 'unblock') {
    $stmt = mysqli_prepare($conn, "DELETE FROM user_blocks WHERE blocker_id = ? AND blocked_id = ?");
    mysqli_stmt_bind_param($stmt, 'ii', $userId, $targetId);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    respond(['success' => true, 'blocked' => false]);
}

// Make sure the person exists
$stmt = mysqli_prepare($conn, "SELECT user_id FROM users WHERE user_id = ?");
mysqli_stmt_bind_param($stmt, 'i', $targetId);
mysqli_stmt_execute($stmt);
$exists = mysqli_stmt_get_result($stmt)->fetch_row();
mysqli_stmt_close($stmt);
if (!$exists) {
    respond(['success' => false, 'message' => 'User not found'], 404);
}

$stmt = mysqli_prepare($conn, "INSERT IGNORE INTO user_blocks (blocker_id, blocked_id) VALUES (?, ?)");
mysqli_stmt_bind_param($stmt, 'ii', $userId, $targetId);
mysqli_stmt_execute($stmt);
mysqli_stmt_close($stmt);

// Blocking also ends any follow in both directions
foreach ([[$userId, $targetId], [$targetId, $userId]] as [$a, $b]) {
    $stmt = mysqli_prepare($conn, "DELETE FROM followers WHERE user_id = ? AND follower_user_id = ?");
    mysqli_stmt_bind_param($stmt, 'ii', $a, $b);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
}

respond(['success' => true, 'blocked' => true]);
