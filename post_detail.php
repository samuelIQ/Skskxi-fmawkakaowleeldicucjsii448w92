<?php
require_once 'config.php';
require_once 'age_filter.php';
require_once 'guest_auth.php';

$userId = optionalAuth($conn);

$postId = (int)($_GET['id'] ?? 0);
if (!$postId) {
    respond(['success' => false, 'message' => 'id is required'], 400);
}

$stmt = mysqli_prepare($conn, "SELECT p.post_id, p.user_id, p.content, p.image, p.category, p.is_boosted, p.created_at,
    u.username, u.full_name, u.profile_image, u.is_pro,
    (SELECT COUNT(*) FROM likes WHERE post_id = p.post_id) AS likes_count,
    (SELECT COUNT(*) FROM dislikes WHERE post_id = p.post_id) AS dislikes_count,
    (SELECT COUNT(*) FROM comments WHERE post_id = p.post_id) AS comments_count,
    EXISTS(SELECT 1 FROM likes WHERE post_id = p.post_id AND user_id = ?) AS my_like,
    EXISTS(SELECT 1 FROM dislikes WHERE post_id = p.post_id AND user_id = ?) AS my_dislike
    FROM posts p JOIN users u ON u.user_id = p.user_id
    WHERE p.post_id = ? LIMIT 1");
mysqli_stmt_bind_param($stmt, 'iii', $userId, $userId, $postId);
mysqli_stmt_execute($stmt);
$post = mysqli_stmt_get_result($stmt)->fetch_assoc();
mysqli_stmt_close($stmt);

if (!$post || ($userId > 0 && (isBlockedBetween($conn, $userId, (int)$post['user_id']) || !postsAgeAllowed($conn, $userId, (int)$post['user_id'])))) {
    respond(['success' => false, 'message' => 'Post not found'], 404);
}

$post['is_boosted'] = (bool)$post['is_boosted'];
$post['is_pro'] = (bool)$post['is_pro'];
$post['my_like'] = (bool)$post['my_like'];
$post['my_dislike'] = (bool)$post['my_dislike'];

respond(['success' => true, 'post' => $post]);
