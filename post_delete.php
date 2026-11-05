<?php
require_once 'config.php';

$userId = requireAuth($conn);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(['success' => false, 'message' => 'POST required'], 405);
}

$data = input();
$postId = (int)($data['post_id'] ?? 0);
if (!$postId) {
    respond(['success' => false, 'message' => 'post_id is required'], 400);
}

$stmt = mysqli_prepare($conn, "SELECT user_id FROM posts WHERE post_id = ?");
mysqli_stmt_bind_param($stmt, 'i', $postId);
mysqli_stmt_execute($stmt);
$post = mysqli_stmt_get_result($stmt)->fetch_assoc();
mysqli_stmt_close($stmt);

if (!$post) {
    respond(['success' => false, 'message' => 'Post not found'], 404);
}
if ((int)$post['user_id'] !== $userId) {
    respond(['success' => false, 'message' => 'You can only delete your own posts'], 403);
}

// Cascade: every comment's reactions, then comments, then the post's own
// reactions, then the post itself.
$stmt = mysqli_prepare($conn, "SELECT comment_id FROM comments WHERE post_id = ?");
mysqli_stmt_bind_param($stmt, 'i', $postId);
mysqli_stmt_execute($stmt);
$commentIds = array_column(mysqli_stmt_get_result($stmt)->fetch_all(MYSQLI_ASSOC), 'comment_id');
mysqli_stmt_close($stmt);

foreach ($commentIds as $commentId) {
    $stmt = mysqli_prepare($conn, "DELETE FROM comment_likes WHERE comment_id = ?");
    mysqli_stmt_bind_param($stmt, 'i', $commentId);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);

    $stmt = mysqli_prepare($conn, "DELETE FROM comment_dislikes WHERE comment_id = ?");
    mysqli_stmt_bind_param($stmt, 'i', $commentId);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
}

$stmt = mysqli_prepare($conn, "DELETE FROM comments WHERE post_id = ?");
mysqli_stmt_bind_param($stmt, 'i', $postId);
mysqli_stmt_execute($stmt);
mysqli_stmt_close($stmt);

$stmt = mysqli_prepare($conn, "DELETE FROM likes WHERE post_id = ?");
mysqli_stmt_bind_param($stmt, 'i', $postId);
mysqli_stmt_execute($stmt);
mysqli_stmt_close($stmt);

$stmt = mysqli_prepare($conn, "DELETE FROM dislikes WHERE post_id = ?");
mysqli_stmt_bind_param($stmt, 'i', $postId);
mysqli_stmt_execute($stmt);
mysqli_stmt_close($stmt);

$stmt = mysqli_prepare($conn, "DELETE FROM posts WHERE post_id = ?");
mysqli_stmt_bind_param($stmt, 'i', $postId);
mysqli_stmt_execute($stmt);
mysqli_stmt_close($stmt);

respond(['success' => true]);
