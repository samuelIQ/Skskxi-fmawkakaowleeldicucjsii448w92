<?php
require_once 'config.php';
require_once 'notify.php';

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
    respond(['success' => false, 'message' => "You can't follow yourself"], 400);
}
if (isBlockedBetween($conn, $userId, $targetUserId)) {
    respond(['success' => false, 'message' => "You can't follow this user"], 403);
}

$stmt = mysqli_prepare($conn, "SELECT follower_id FROM followers WHERE user_id = ? AND follower_user_id = ?");
mysqli_stmt_bind_param($stmt, 'ii', $targetUserId, $userId);
mysqli_stmt_execute($stmt);
$existing = mysqli_stmt_get_result($stmt)->fetch_assoc();
mysqli_stmt_close($stmt);

if ($existing) {
    $stmt = mysqli_prepare($conn, "DELETE FROM followers WHERE follower_id = ?");
    mysqli_stmt_bind_param($stmt, 'i', $existing['follower_id']);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    $following = false;
} else {
    // Following is mutually exclusive with hating - clear any hate first
    $stmt = mysqli_prepare($conn, "DELETE FROM haters WHERE user_id = ? AND hater_user_id = ?");
    mysqli_stmt_bind_param($stmt, 'ii', $targetUserId, $userId);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);

    $stmt = mysqli_prepare($conn, "INSERT INTO followers (user_id, follower_user_id) VALUES (?, ?)");
    mysqli_stmt_bind_param($stmt, 'ii', $targetUserId, $userId);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    $following = true;
    createNotification($conn, $targetUserId, $userId, 'follow');
}

$stmt = mysqli_prepare($conn, "SELECT COUNT(*) AS c FROM followers WHERE user_id = ?");
mysqli_stmt_bind_param($stmt, 'i', $targetUserId);
mysqli_stmt_execute($stmt);
$count = mysqli_stmt_get_result($stmt)->fetch_assoc()['c'];
mysqli_stmt_close($stmt);

respond(['success' => true, 'is_following' => $following, 'followers_count' => (int)$count, 'is_hating' => false]);
