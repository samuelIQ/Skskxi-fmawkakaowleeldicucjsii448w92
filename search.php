<?php
require_once 'config.php';
require_once 'age_filter.php';
require_once 'guest_auth.php';

$userId = optionalAuth($conn);

$q = trim($_GET['q'] ?? '');
if ($q === '') {
    respond(['success' => true, 'users' => [], 'posts' => [], 'hashtags' => []]);
}
$like = '%' . $q . '%';

// Users: fetch a larger batch than we show up front (10 in the carousel) so
// the in-app "Show more" list view can page through 20-at-a-time locally
// without extra round-trips for a typical search.
$stmt = mysqli_prepare($conn, "SELECT user_id, username, full_name, profile_image, is_pro,
    (SELECT COUNT(*) FROM followers WHERE user_id = users.user_id AND follower_user_id = ?) AS is_following
    FROM users WHERE (username LIKE ? OR full_name LIKE ?)" . blockedFilter('user_id', $userId) . " ORDER BY RAND() LIMIT 100");
mysqli_stmt_bind_param($stmt, 'iss', $userId, $like, $like);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$users = [];
while ($row = mysqli_fetch_assoc($result)) {
    $row['is_following'] = (int)$row['is_following'] > 0;
    $users[] = $row;
}
mysqli_stmt_close($stmt);

// Posts: same shape as the feed (likes/dislikes/comments counts + my_like/my_dislike)
// so results can be rendered with the exact same PostCard used in the feed.
$stmt = mysqli_prepare($conn, "SELECT p.post_id, p.user_id, p.content, p.image, p.category, p.is_boosted, p.created_at,
    u.username, u.full_name, u.profile_image, u.is_pro,
    (SELECT COUNT(*) FROM likes WHERE post_id = p.post_id) AS likes_count,
    (SELECT COUNT(*) FROM dislikes WHERE post_id = p.post_id) AS dislikes_count,
    (SELECT COUNT(*) FROM comments WHERE post_id = p.post_id) AS comments_count,
    EXISTS(SELECT 1 FROM likes WHERE post_id = p.post_id AND user_id = ?) AS my_like,
    EXISTS(SELECT 1 FROM dislikes WHERE post_id = p.post_id AND user_id = ?) AS my_dislike
    FROM posts p JOIN users u ON u.user_id = p.user_id
    WHERE p.content LIKE ?" . blockedFilter('p.user_id', $userId) . postsAgeFilter($conn, $userId, 'p.user_id') . " ORDER BY p.created_at DESC LIMIT 20");
mysqli_stmt_bind_param($stmt, 'iis', $userId, $userId, $like);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$posts = [];
while ($row = mysqli_fetch_assoc($result)) {
    $row['is_boosted'] = (bool)$row['is_boosted'];
    $row['is_pro'] = (bool)$row['is_pro'];
    $row['my_like'] = (bool)$row['my_like'];
    $row['my_dislike'] = (bool)$row['my_dislike'];
    $posts[] = $row;
}
mysqli_stmt_close($stmt);

$stmt = mysqli_prepare($conn, "SELECT title AS tag, hits AS count FROM trends WHERE title LIKE ? ORDER BY hits DESC LIMIT 10");
mysqli_stmt_bind_param($stmt, 's', $like);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$hashtags = [];
while ($row = mysqli_fetch_assoc($result)) {
    $hashtags[] = $row;
}
mysqli_stmt_close($stmt);

respond(['success' => true, 'users' => $users, 'posts' => $posts, 'hashtags' => $hashtags]);
