<?php
require_once 'config.php';
require_once 'age_filter.php';
require_once 'guest_auth.php';
require_once 'trending_helper.php';

$viewerId = optionalAuth($conn);

$tag = trim($_GET['tag'] ?? '');

if ($tag === '') {
    respond(['success' => true, 'hashtags' => getTrendingTitles($conn, 9)]);
}

// Trend detail: posts containing ALL words in the trending phrase,
// regardless of their order in the post.
$words = preg_split('/\s+/', trim($tag));
$words = array_filter($words, function ($word) {
    return $word !== '';
});

if (empty($words)) {
    respond(['success' => true, 'tag' => $tag, 'posts' => []]);
}

// Build:
// WHERE p.content LIKE ? AND p.content LIKE ? AND ...
$whereParts = [];
$params = [];
$types = '';

foreach ($words as $word) {
    $whereParts[] = "p.content LIKE ?";
    $params[] = '%' . $word . '%';
    $types .= 's';
}

if ($viewerId > 0) {   // hide people the viewer blocked, and people who blocked the viewer
    $whereParts[] = '1=1' . blockedFilter('p.user_id', $viewerId) . postsAgeFilter($conn, $viewerId, 'p.user_id');
}
$whereSql = implode(' AND ', $whereParts);

$sql = "SELECT p.post_id, p.user_id, p.content, p.image, p.category, p.created_at,
    u.username, u.full_name, u.profile_image, u.is_pro,
    (SELECT COUNT(*) FROM likes WHERE post_id = p.post_id) AS likes_count,
    (SELECT COUNT(*) FROM dislikes WHERE post_id = p.post_id) AS dislikes_count,
    (SELECT COUNT(*) FROM comments WHERE post_id = p.post_id) AS comments_count,
    EXISTS(SELECT 1 FROM likes WHERE post_id = p.post_id AND user_id = ?) AS my_like,
    EXISTS(SELECT 1 FROM dislikes WHERE post_id = p.post_id AND user_id = ?) AS my_dislike
    FROM posts p
    JOIN users u ON u.user_id = p.user_id
    WHERE $whereSql
    ORDER BY p.created_at DESC
    LIMIT 50";

$stmt = mysqli_prepare($conn, $sql);

$paramsWithViewer = [$viewerId, $viewerId];
$typesWithViewer = 'ii' . $types;

foreach ($params as $param) {
    $paramsWithViewer[] = $param;
}

mysqli_stmt_bind_param(
    $stmt,
    $typesWithViewer,
    ...$paramsWithViewer
);

mysqli_stmt_execute($stmt);

$result = mysqli_stmt_get_result($stmt);

$posts = [];

while ($row = mysqli_fetch_assoc($result)) {
    $row['is_pro'] = (bool)$row['is_pro'];
    $row['my_like'] = (bool)$row['my_like'];
    $row['my_dislike'] = (bool)$row['my_dislike'];
    $posts[] = $row;
}

mysqli_stmt_close($stmt);

respond([
    'success' => true,
    'tag' => $tag,
    'posts' => $posts
]);