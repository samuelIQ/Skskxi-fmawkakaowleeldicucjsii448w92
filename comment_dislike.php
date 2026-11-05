<?php
require_once 'config.php';

$userId = requireAuth($conn);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(['success' => false, 'message' => 'POST required'], 405);
}

$data = input();
$commentId = (int)($data['comment_id'] ?? 0);
if (!$commentId) {
    respond(['success' => false, 'message' => 'Invalid comment'], 400);
}

$stmt = mysqli_prepare($conn, "SELECT comment_dislike_id FROM comment_dislikes WHERE comment_id = ? AND user_id = ?");
mysqli_stmt_bind_param($stmt, 'ii', $commentId, $userId);
mysqli_stmt_execute($stmt);
$existing = mysqli_stmt_get_result($stmt)->fetch_assoc();
mysqli_stmt_close($stmt);

if ($existing) {
    $stmt = mysqli_prepare($conn, "DELETE FROM comment_dislikes WHERE comment_dislike_id = ?");
    mysqli_stmt_bind_param($stmt, 'i', $existing['comment_dislike_id']);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    $action = 'removed';
} else {
    $stmt = mysqli_prepare($conn, "DELETE FROM comment_likes WHERE comment_id = ? AND user_id = ?");
    mysqli_stmt_bind_param($stmt, 'ii', $commentId, $userId);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);

    $stmt = mysqli_prepare($conn, "INSERT INTO comment_dislikes (user_id, comment_id) VALUES (?, ?)");
    mysqli_stmt_bind_param($stmt, 'ii', $userId, $commentId);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    $action = 'added';
}

$stmt = mysqli_prepare($conn, "SELECT
    (SELECT COUNT(*) FROM comment_likes WHERE comment_id = ?) AS likes_count,
    (SELECT COUNT(*) FROM comment_dislikes WHERE comment_id = ?) AS dislikes_count");
mysqli_stmt_bind_param($stmt, 'ii', $commentId, $commentId);
mysqli_stmt_execute($stmt);
$counts = mysqli_stmt_get_result($stmt)->fetch_assoc();
mysqli_stmt_close($stmt);

respond(['success' => true, 'action' => $action] + $counts);
