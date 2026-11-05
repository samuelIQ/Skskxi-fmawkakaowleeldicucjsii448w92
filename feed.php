<?php
require_once 'config.php';
require_once 'age_filter.php';
require_once 'guest_auth.php';

$userId = optionalAuth($conn);

// ---------- GUEST FEED ----------
// Guests only see the latest 2 posts from each account below (max 10 posts),
// one page only, cached for 60 seconds so guests barely touch the database.
if ($userId === 0) {
    $GUEST_ACCOUNT_IDS = [1, 20, 21, 23, 24];   // <- edit this list to change who guests see
    $POSTS_PER_ACCOUNT = 2;
    $CACHE_FILE = sys_get_temp_dir() . '/nothyp_guest_feed.json';
    $CACHE_SECONDS = 60;

    if ((int)($_GET['page'] ?? 1) > 1) {
        respond(['success' => true, 'page' => 2, 'limit' => 10, 'posts' => []]);
    }

    if (is_file($CACHE_FILE) && (time() - filemtime($CACHE_FILE)) < $CACHE_SECONDS) {
        echo file_get_contents($CACHE_FILE);
        exit;
    }

    $cols = "p.post_id, p.user_id, p.content, p.image, p.category, p.is_boosted, p.created_at,
             u.username, u.full_name, u.profile_image, u.is_pro,
             (SELECT COUNT(*) FROM likes WHERE post_id = p.post_id) AS likes_count,
             (SELECT COUNT(*) FROM dislikes WHERE post_id = p.post_id) AS dislikes_count,
             (SELECT COUNT(*) FROM comments WHERE post_id = p.post_id) AS comments_count";

    // One small indexed query per account: newest 2 posts.
    $parts = [];
    $types = '';
    $params = [];
    foreach ($GUEST_ACCOUNT_IDS as $id) {
        $parts[] = "(SELECT $cols FROM posts p JOIN users u ON u.user_id = p.user_id
                     WHERE p.user_id = ? ORDER BY p.created_at DESC LIMIT $POSTS_PER_ACCOUNT)";
        $types .= 'i';
        $params[] = $id;
    }
    $sql = implode(' UNION ALL ', $parts) . ' ORDER BY created_at DESC';

    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, $types, ...$params);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    $posts = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $row['is_boosted'] = (bool)$row['is_boosted'];
        $row['is_pro'] = (bool)$row['is_pro'];
        $row['my_like'] = false;
        $row['my_dislike'] = false;
        $row['connected_comments'] = [];
        $posts[] = $row;
    }
    mysqli_stmt_close($stmt);

    $json = json_encode(['success' => true, 'page' => 1, 'limit' => count($posts), 'posts' => $posts]);
    @file_put_contents($CACHE_FILE, $json, LOCK_EX);
    echo $json;
    exit;
}

$page = max(1, (int)($_GET['page'] ?? 1));
$limit = min(50, max(1, (int)($_GET['limit'] ?? 15)));
$offset = ($page - 1) * $limit;
$category = trim($_GET['category'] ?? '');
$onlyFollowing = ($_GET['only_following'] ?? '') === '1';

$conditions = [];
$params = [];
$types = '';

if ($userId > 0) {   // hide blocked people (both directions)
    $conditions[] = '1=1' . blockedFilter('p.user_id', $userId) . postsAgeFilter($conn, $userId, 'p.user_id');
}

if ($category !== '') {
    $conditions[] = 'p.category = ?';
    $params[] = $category;
    $types .= 's';
}

if ($onlyFollowing) {
    $conditions[] = '(p.user_id = ? OR p.user_id IN (SELECT user_id FROM followers WHERE follower_user_id = ?))';
    $params[] = $userId;
    $params[] = $userId;
    $types .= 'ii';
}

$where = empty($conditions) ? '' : 'WHERE ' . implode(' AND ', $conditions);

$sql = "SELECT p.post_id, p.user_id, p.content, p.image, p.category, p.is_boosted, p.created_at,
        u.username, u.full_name, u.profile_image, u.is_pro,
        (SELECT COUNT(*) FROM likes WHERE post_id = p.post_id) AS likes_count,
        (SELECT COUNT(*) FROM dislikes WHERE post_id = p.post_id) AS dislikes_count,
        (SELECT COUNT(*) FROM comments WHERE post_id = p.post_id) AS comments_count,
        EXISTS(SELECT 1 FROM likes WHERE post_id = p.post_id AND user_id = ?) AS my_like,
        EXISTS(SELECT 1 FROM dislikes WHERE post_id = p.post_id AND user_id = ?) AS my_dislike
        FROM posts p
        JOIN users u ON u.user_id = p.user_id
        $where
        ORDER BY p.created_at DESC
        LIMIT ? OFFSET ?";

$types = 'ii' . $types . 'ii';
$params = array_merge([$userId, $userId], $params, [$limit, $offset]);

$stmt = mysqli_prepare($conn, $sql);
mysqli_stmt_bind_param($stmt, $types, ...$params);
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

// For each post, attach "connected comments": comments made by the current
// user or by anyone the current user follows, so they surface in the feed
// like a stacked reply directly under the original post.
if (!empty($posts)) {
    $postIds = array_column($posts, 'post_id');
    $placeholders = implode(',', array_fill(0, count($postIds), '?'));

    $sql = "SELECT c.comment_id, c.post_id, c.user_id, c.content, c.image, c.voice_note, c.created_at,
            u.username, u.full_name, u.profile_image, u.is_pro,
            (SELECT COUNT(*) FROM comment_likes WHERE comment_id = c.comment_id) AS likes_count,
            (SELECT COUNT(*) FROM comment_dislikes WHERE comment_id = c.comment_id) AS dislikes_count,
            EXISTS(SELECT 1 FROM comment_likes WHERE comment_id = c.comment_id AND user_id = ?) AS my_like,
            EXISTS(SELECT 1 FROM comment_dislikes WHERE comment_id = c.comment_id AND user_id = ?) AS my_dislike
            FROM comments c
            JOIN users u ON u.user_id = c.user_id
            WHERE c.post_id IN ($placeholders)
            AND (c.user_id = ? OR c.user_id IN (SELECT user_id FROM followers WHERE follower_user_id = ?))
            " . blockedFilter('c.user_id', $userId) . "
            ORDER BY c.created_at ASC";

    $types = 'ii' . str_repeat('i', count($postIds)) . 'ii';
    $params = array_merge([$userId, $userId], $postIds, [$userId, $userId]);

    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, $types, ...$params);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    $commentsByPost = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $row['is_pro'] = (bool)$row['is_pro'];
        $row['my_like'] = (bool)$row['my_like'];
        $row['my_dislike'] = (bool)$row['my_dislike'];
        $commentsByPost[$row['post_id']][] = $row;
    }
    mysqli_stmt_close($stmt);

    foreach ($posts as &$post) {
        // Cap at 5 connected comments per post to keep payloads reasonable
        $post['connected_comments'] = array_slice($commentsByPost[$post['post_id']] ?? [], 0, 5);
    }
    unset($post);
}

respond(['success' => true, 'page' => $page, 'limit' => $limit, 'posts' => $posts]);
