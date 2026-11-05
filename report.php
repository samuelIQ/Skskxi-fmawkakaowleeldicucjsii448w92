<?php
// Report a post, comment or user.
require_once 'config.php';

$userId = requireAuth($conn);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(['success' => false, 'message' => 'POST required'], 405);
}
$data = input();
$type = $data['type'] ?? '';
$targetId = (int)($data['id'] ?? 0);
$reason = $data['reason'] ?? '';
$details = mb_substr(trim($data['details'] ?? ''), 0, 500);

$reasons = ['spam', 'harassment', 'hate', 'violence', 'nudity', 'scam', 'impersonation', 'other'];
if (!in_array($type, ['post', 'comment', 'user'], true) || !$targetId || !in_array($reason, $reasons, true)) {
    respond(['success' => false, 'message' => 'Invalid report'], 400);
}

// Work out who owns the thing being reported
if ($type === 'post') {
    $stmt = mysqli_prepare($conn, "SELECT user_id FROM posts WHERE post_id = ?");
} elseif ($type === 'comment') {
    $stmt = mysqli_prepare($conn, "SELECT user_id FROM comments WHERE comment_id = ?");
} else {
    $stmt = mysqli_prepare($conn, "SELECT user_id FROM users WHERE user_id = ?");
}
mysqli_stmt_bind_param($stmt, 'i', $targetId);
mysqli_stmt_execute($stmt);
$row = mysqli_stmt_get_result($stmt)->fetch_assoc();
mysqli_stmt_close($stmt);

if (!$row) {
    respond(['success' => false, 'message' => 'Nothing to report'], 404);
}
$targetUserId = (int)$row['user_id'];
if ($targetUserId === $userId) {
    respond(['success' => false, 'message' => "You can't report yourself"], 400);
}

// One report per person per item (repeat reports are quietly accepted)
$stmt = mysqli_prepare($conn,
    "INSERT IGNORE INTO reports (reporter_id, target_type, target_id, target_user_id, reason, details)
     VALUES (?, ?, ?, ?, ?, ?)");
mysqli_stmt_bind_param($stmt, 'isiiss', $userId, $type, $targetId, $targetUserId, $reason, $details);
mysqli_stmt_execute($stmt);
mysqli_stmt_close($stmt);

respond(['success' => true, 'message' => 'Thanks. We will review this report.']);
