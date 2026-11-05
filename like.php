<?php
require_once 'config.php';
require_once 'notify.php';

$userId = requireAuth($conn);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(['success' => false, 'message' => 'POST required'], 405);
}

$data = input();
$postId = (int)($data['post_id'] ?? 0);
if (!$postId) {
    respond(['success' => false, 'message' => 'Invalid post'], 400);
}

$stmt = mysqli_prepare($conn, "SELECT like_id FROM likes WHERE post_id = ? AND user_id = ?");
mysqli_stmt_bind_param($stmt, 'ii', $postId, $userId);
mysqli_stmt_execute($stmt);
$existing = mysqli_stmt_get_result($stmt)->fetch_assoc();
mysqli_stmt_close($stmt);

if ($existing) {
    $stmt = mysqli_prepare($conn, "DELETE FROM likes WHERE like_id = ?");
    mysqli_stmt_bind_param($stmt, 'i', $existing['like_id']);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    $action = 'removed';
} else {
    // Liking removes any existing dislike (mutually exclusive reactions)
    $stmt = mysqli_prepare($conn, "DELETE FROM dislikes WHERE post_id = ? AND user_id = ?");
    mysqli_stmt_bind_param($stmt, 'ii', $postId, $userId);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);

    $stmt = mysqli_prepare($conn, "INSERT INTO likes (user_id, post_id, type) VALUES (?, ?, 'like')");
    mysqli_stmt_bind_param($stmt, 'ii', $userId, $postId);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    $action = 'added';

    $stmt = mysqli_prepare($conn, "SELECT user_id FROM posts WHERE post_id = ?");
    mysqli_stmt_bind_param($stmt, 'i', $postId);
    mysqli_stmt_execute($stmt);
    $ownerId = mysqli_stmt_get_result($stmt)->fetch_assoc()['user_id'] ?? null;
    mysqli_stmt_close($stmt);
    if ($ownerId) {
        createNotification($conn, (int)$ownerId, $userId, 'like', $postId);
    }
}

$stmt = mysqli_prepare($conn, "SELECT
    (SELECT COUNT(*) FROM likes WHERE post_id = ?) AS likes_count,
    (SELECT COUNT(*) FROM dislikes WHERE post_id = ?) AS dislikes_count");
mysqli_stmt_bind_param($stmt, 'ii', $postId, $postId);
mysqli_stmt_execute($stmt);
$counts = mysqli_stmt_get_result($stmt)->fetch_assoc();
mysqli_stmt_close($stmt);

respond(['success' => true, 'action' => $action] + $counts);
