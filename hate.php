<?php
require_once 'config.php';

$userId = requireAuth($conn);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(['success' => false, 'message' => 'POST required'], 405);
}

$data = input();
$targetUserId = (int)($data['user_id'] ?? 0);

if (!$targetUserId) {
    respond(['success' => false, 'message' => 'user_id is required'], 400);
}
if ($targetUserId === $userId) {
    respond(['success' => false, 'message' => "You can't hate yourself"], 400);
}

$stmt = mysqli_prepare($conn, "SELECT hater_id FROM haters WHERE user_id = ? AND hater_user_id = ?");
mysqli_stmt_bind_param($stmt, 'ii', $targetUserId, $userId);
mysqli_stmt_execute($stmt);
$existing = mysqli_stmt_get_result($stmt)->fetch_assoc();
mysqli_stmt_close($stmt);

if ($existing) {
    $stmt = mysqli_prepare($conn, "DELETE FROM haters WHERE hater_id = ?");
    mysqli_stmt_bind_param($stmt, 'i', $existing['hater_id']);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    $hating = false;
} else {
    // Hating is mutually exclusive with following - clear any follow first
    $stmt = mysqli_prepare($conn, "DELETE FROM followers WHERE user_id = ? AND follower_user_id = ?");
    mysqli_stmt_bind_param($stmt, 'ii', $targetUserId, $userId);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);

    $stmt = mysqli_prepare($conn, "INSERT INTO haters (user_id, hater_user_id) VALUES (?, ?)");
    mysqli_stmt_bind_param($stmt, 'ii', $targetUserId, $userId);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    $hating = true;
}

$stmt = mysqli_prepare($conn, "SELECT COUNT(*) AS c FROM followers WHERE user_id = ?");
mysqli_stmt_bind_param($stmt, 'i', $targetUserId);
mysqli_stmt_execute($stmt);
$followersCount = mysqli_stmt_get_result($stmt)->fetch_assoc()['c'];
mysqli_stmt_close($stmt);

respond(['success' => true, 'is_hating' => $hating, 'is_following' => false, 'followers_count' => (int)$followersCount]);
