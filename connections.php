<?php
require_once 'config.php';

$viewerId = requireAuth($conn);

$profileUserId = (int)($_GET['user_id'] ?? 0);
$type = trim($_GET['type'] ?? 'followers');

if (!$profileUserId) {
    respond(['success' => false, 'message' => 'user_id is required'], 400);
}
if (!in_array($type, ['followers', 'following', 'haters'], true)) {
    respond(['success' => false, 'message' => 'Invalid type'], 400);
}

if ($type === 'followers') {
    // People who follow this profile
    $sql = "SELECT u.user_id, u.username, u.full_name, u.profile_image, u.is_pro,
            EXISTS(SELECT 1 FROM followers f2 WHERE f2.user_id = u.user_id AND f2.follower_user_id = ?) AS is_following
            FROM followers f
            JOIN users u ON u.user_id = f.follower_user_id
            WHERE f.user_id = ?
            ORDER BY f.created_at DESC";
} elseif ($type === 'following') {
    // People this profile follows
    $sql = "SELECT u.user_id, u.username, u.full_name, u.profile_image, u.is_pro,
            EXISTS(SELECT 1 FROM followers f2 WHERE f2.user_id = u.user_id AND f2.follower_user_id = ?) AS is_following
            FROM followers f
            JOIN users u ON u.user_id = f.user_id
            WHERE f.follower_user_id = ?
            ORDER BY f.created_at DESC";
} else {
    // People who hate this profile (mutually exclusive with following)
    $sql = "SELECT u.user_id, u.username, u.full_name, u.profile_image, u.is_pro,
            EXISTS(SELECT 1 FROM followers f2 WHERE f2.user_id = u.user_id AND f2.follower_user_id = ?) AS is_following
            FROM haters h
            JOIN users u ON u.user_id = h.hater_user_id
            WHERE h.user_id = ?
            ORDER BY h.created_at DESC";
}

$stmt = mysqli_prepare($conn, $sql);
mysqli_stmt_bind_param($stmt, 'ii', $viewerId, $profileUserId);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

$users = [];
while ($row = mysqli_fetch_assoc($result)) {
    $row['is_following'] = (bool)$row['is_following'];
    $users[] = $row;
}
mysqli_stmt_close($stmt);

respond(['success' => true, 'type' => $type, 'users' => $users]);
