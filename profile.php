<?php
require_once 'config.php';
require_once 'age_filter.php';

$viewerId = requireAuth($conn);

$username = trim($_GET['username'] ?? '');
$userId = (int)($_GET['user_id'] ?? 0);

if ($username === '' && !$userId) {
    $userId = $viewerId; // default: my own profile
}

if ($username !== '') {
    $stmt = mysqli_prepare($conn, "SELECT user_id, username, full_name, bio, profile_image, cover_image,
        is_pro, is_admin, category, created_at FROM users WHERE username = ? LIMIT 1");
    mysqli_stmt_bind_param($stmt, 's', $username);
} else {
    $stmt = mysqli_prepare($conn, "SELECT user_id, username, full_name, bio, profile_image, cover_image,
        is_pro, is_admin, category, created_at FROM users WHERE user_id = ? LIMIT 1");
    mysqli_stmt_bind_param($stmt, 'i', $userId);
}
mysqli_stmt_execute($stmt);
$user = mysqli_stmt_get_result($stmt)->fetch_assoc();
mysqli_stmt_close($stmt);

if (!$user) {
    respond(['success' => false, 'message' => 'User not found'], 404);
}
$profileUserId = (int)$user['user_id'];
if ($viewerId !== $profileUserId && isBlockedBetween($conn, $viewerId, $profileUserId)) {
    respond(['success' => false, 'message' => 'User not found'], 404);
}

$stmt = mysqli_prepare($conn, "SELECT
    (SELECT COUNT(*) FROM posts WHERE user_id = ?) AS posts_count,
    (SELECT COUNT(*) FROM followers WHERE user_id = ?) AS followers_count,
    (SELECT COUNT(*) FROM followers WHERE follower_user_id = ?) AS following_count,
    
    (SELECT COUNT(*) FROM followers WHERE user_id = ? AND follower_user_id = ?) AS is_following,
    (SELECT COUNT(*) FROM followers WHERE user_id = ? AND follower_user_id = ?) AS follows_me,
    (SELECT COUNT(*) FROM haters WHERE user_id = ? AND hater_user_id = ?) AS is_hating");
mysqli_stmt_bind_param($stmt, 'iiiiiiiii', $profileUserId, $profileUserId, $profileUserId, $profileUserId, $viewerId, $viewerId, $profileUserId, $profileUserId, $viewerId);
mysqli_stmt_execute($stmt);
$stats = mysqli_stmt_get_result($stmt)->fetch_assoc();
mysqli_stmt_close($stmt);

$stmt = mysqli_prepare($conn, "SELECT post_id, content, image, category, created_at,
    (SELECT COUNT(*) FROM likes WHERE post_id = posts.post_id) AS likes_count,
    (SELECT COUNT(*) FROM dislikes WHERE post_id = posts.post_id) AS dislikes_count,
    (SELECT COUNT(*) FROM comments WHERE post_id = posts.post_id) AS comments_count,
    EXISTS(SELECT 1 FROM likes WHERE post_id = posts.post_id AND user_id = ?) AS my_like,
    EXISTS(SELECT 1 FROM dislikes WHERE post_id = posts.post_id AND user_id = ?) AS my_dislike
    FROM posts WHERE user_id = ?" . ($viewerId !== $profileUserId ? postsAgeFilter($conn, $viewerId, 'user_id') : '') . " ORDER BY created_at DESC LIMIT 30");
mysqli_stmt_bind_param($stmt, 'iii', $viewerId, $viewerId, $profileUserId);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$posts = [];
while ($row = mysqli_fetch_assoc($result)) {
    $row['my_like'] = (bool)$row['my_like'];
    $row['my_dislike'] = (bool)$row['my_dislike'];
    $posts[] = $row;
}
mysqli_stmt_close($stmt);

respond([
    'success' => true,
    'user' => $user,
    'posts_count' => (int)$stats['posts_count'],
    'followers_count' => (int)$stats['followers_count'],
    'following_count' => (int)$stats['following_count'],
    'is_following' => (int)$stats['is_following'] > 0,
    'follows_me' => (int)$stats['follows_me'] > 0,
    'is_hating' => (int)$stats['is_hating'] > 0,
    'is_own_profile' => $profileUserId === $viewerId,
    'posts' => $posts,
]);