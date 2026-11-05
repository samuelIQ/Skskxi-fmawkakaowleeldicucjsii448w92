<?php
// Permanently deletes the signed-in user's account and everything they made.
// POST {password}. Cannot be undone.
require_once 'config.php';

$userId = requireAuth($conn);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(['success' => false, 'message' => 'POST required'], 405);
}
$data = input();
$password = (string)($data['password'] ?? '');

// ---- confirm identity ----
$stmt = mysqli_prepare($conn, "SELECT password, is_admin, profile_image, cover_image FROM users WHERE user_id = ?");
mysqli_stmt_bind_param($stmt, 'i', $userId);
mysqli_stmt_execute($stmt);
$me = mysqli_stmt_get_result($stmt)->fetch_assoc();
mysqli_stmt_close($stmt);

if (!$me) {
    respond(['success' => false, 'message' => 'Account not found'], 404);
}
if (!empty($me['is_admin'])) {
    respond(['success' => false, 'message' => 'Admin accounts cannot be deleted from the app'], 403);
}
if ($password === '' || !password_verify($password, $me['password'])) {
    respond(['success' => false, 'message' => 'Wrong password'], 403);
}

// ---- helpers ----
function ids(mysqli $conn, string $sql, string $types = '', array $params = []): array {
    $stmt = mysqli_prepare($conn, $sql);
    if ($types !== '') mysqli_stmt_bind_param($stmt, $types, ...$params);
    mysqli_stmt_execute($stmt);
    $out = [];
    $res = mysqli_stmt_get_result($stmt);
    while ($r = mysqli_fetch_row($res)) $out[] = $r[0];
    mysqli_stmt_close($stmt);
    return $out;
}

/** Runs a DELETE. A table that doesn't exist (error 1146) is skipped; anything else aborts. */
function del(mysqli $conn, string $sql, string $types = '', array $params = []): void {
    try {
        $stmt = mysqli_prepare($conn, $sql);
        if ($types !== '') mysqli_stmt_bind_param($stmt, $types, ...$params);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
    } catch (mysqli_sql_exception $e) {
        if ((int)$e->getCode() !== 1146) throw $e;
    }
}

function inList(array $list): string {
    $list = array_map('intval', $list);
    return $list ? implode(',', $list) : '0';
}

// ---- collect files to remove after the database is clean ----
$files = [$me['profile_image'], $me['cover_image']];
foreach ([
    ["SELECT image FROM posts WHERE user_id = ?"],
    ["SELECT image FROM comments WHERE user_id = ?"],
    ["SELECT voice_note FROM comments WHERE user_id = ?"],
    ["SELECT image FROM stories WHERE user_id = ?"],
    ["SELECT chart_image FROM journal WHERE user_id = ?"],
    ["SELECT image FROM chat_messages WHERE sender_id = ?"],
    ["SELECT voice_note FROM chat_messages WHERE sender_id = ?"],
    ["SELECT image FROM promo_ads WHERE user_id = ?"],
    ["SELECT logo FROM promo_ads WHERE user_id = ?"],
] as [$q]) {
    try { $files = array_merge($files, ids($conn, $q, 'i', [$userId])); } catch (mysqli_sql_exception $e) { /* table missing: skip */ }
}

// ---- delete everything in one transaction ----
mysqli_begin_transaction($conn);
try {
    $postIds    = ids($conn, "SELECT post_id FROM posts WHERE user_id = ?", 'i', [$userId]);
    $storyIds   = ids($conn, "SELECT story_id FROM stories WHERE user_id = ?", 'i', [$userId]);
    $postList   = inList($postIds);

    // my comments + everyone's comments on my posts + replies to those
    $commentIds = ids($conn, "SELECT comment_id FROM comments WHERE user_id = ? OR post_id IN ($postList)", 'i', [$userId]);
    $replyIds   = ids($conn, "SELECT comment_id FROM comments WHERE parent_comment_id IN (" . inList($commentIds) . ")");
    $allComments = array_values(array_unique(array_merge($commentIds, $replyIds)));
    $commentList = inList($allComments);

    del($conn, "DELETE FROM comment_likes WHERE user_id = ? OR comment_id IN ($commentList)", 'i', [$userId]);
    del($conn, "DELETE FROM comment_dislikes WHERE user_id = ? OR comment_id IN ($commentList)", 'i', [$userId]);
    del($conn, "DELETE FROM comments WHERE comment_id IN ($commentList)");

    del($conn, "DELETE FROM likes WHERE user_id = ? OR post_id IN ($postList)", 'i', [$userId]);
    del($conn, "DELETE FROM dislikes WHERE user_id = ? OR post_id IN ($postList)", 'i', [$userId]);
    del($conn, "DELETE FROM shares WHERE user_id = ? OR post_id IN ($postList)", 'i', [$userId]);
    del($conn, "DELETE FROM notifications WHERE user_id = ? OR from_user_id = ? OR post_id IN ($postList)", 'ii', [$userId, $userId]);
    del($conn, "DELETE FROM post_hashtags WHERE post_id IN ($postList)");
    del($conn, "DELETE FROM posts WHERE user_id = ?", 'i', [$userId]);

    del($conn, "DELETE FROM story_views WHERE viewer_id = ? OR story_id IN (" . inList($storyIds) . ")", 'i', [$userId]);
    del($conn, "DELETE FROM stories WHERE user_id = ?", 'i', [$userId]);

    del($conn, "DELETE FROM followers WHERE user_id = ? OR follower_user_id = ?", 'ii', [$userId, $userId]);
    del($conn, "DELETE FROM haters WHERE user_id = ? OR hater_user_id = ?", 'ii', [$userId, $userId]);
    del($conn, "DELETE FROM user_blocks WHERE blocker_id = ? OR blocked_id = ?", 'ii', [$userId, $userId]);
    del($conn, "DELETE FROM reports WHERE reporter_id = ? OR target_user_id = ?", 'ii', [$userId, $userId]);

    del($conn, "DELETE FROM journal WHERE user_id = ?", 'i', [$userId]);
    del($conn, "DELETE FROM traders WHERE user_id = ?", 'i', [$userId]);

    del($conn, "DELETE FROM chat_conversations WHERE user_one_id = ? OR user_two_id = ?", 'ii', [$userId, $userId]);
    del($conn, "DELETE FROM chat_users WHERE user_id = ?", 'i', [$userId]);

    del($conn, "DELETE FROM promo_ads WHERE user_id = ?", 'i', [$userId]);
    del($conn, "DELETE FROM ads WHERE user_id = ?", 'i', [$userId]);
    del($conn, "DELETE FROM orders WHERE user_id = ?", 'i', [$userId]);
    del($conn, "DELETE FROM pro_applications WHERE user_id = ?", 'i', [$userId]);
    del($conn, "DELETE FROM products WHERE user_id = ?", 'i', [$userId]);          // purchases/reviews/favourites cascade
    del($conn, "DELETE FROM store_payouts WHERE seller_user_id = ?", 'i', [$userId]);
    del($conn, "DELETE FROM style WHERE userId = ?", 'i', [$userId]);
    del($conn, "DELETE FROM api_tokens WHERE user_id = ?", 'i', [$userId]);

    del($conn, "DELETE FROM users WHERE user_id = ?", 'i', [$userId]);

    mysqli_commit($conn);
} catch (Throwable $e) {
    mysqli_rollback($conn);
    respond(['success' => false, 'message' => 'Could not delete the account. Please try again.'], 500);
}

// ---- remove uploaded files (best effort, only inside /uploads) ----
$uploadsRoot = realpath(__DIR__ . '/../uploads');
if ($uploadsRoot) {
    foreach (array_unique(array_filter($files)) as $f) {
        $f = (string)$f;
        if ($f === '' || stripos($f, 'default') !== false || strpos($f, '..') !== false) continue;
        $rel = strpos($f, 'uploads/') === 0 ? substr($f, strlen('uploads/')) : $f;
        $path = realpath($uploadsRoot . '/' . ltrim($rel, '/'));
        if ($path && strpos($path, $uploadsRoot . DIRECTORY_SEPARATOR) === 0 && is_file($path)) {
            @unlink($path);
        }
    }
}

respond(['success' => true, 'message' => 'Your account has been permanently deleted.']);
