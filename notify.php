<?php
// mobile-api/notify.php - include this, don't call directly.

/**
 * Insert a notification for $toUserId, unless it's the user notifying themselves.
 */
function createNotification(mysqli $conn, int $toUserId, int $fromUserId, string $type, ?int $postId = null, ?int $commentId = null): void {
    if ($toUserId === $fromUserId) {
        return; // don't notify yourself
    }
    $stmt = mysqli_prepare($conn, "INSERT INTO notifications (user_id, from_user_id, type, post_id, comment_id) VALUES (?, ?, ?, ?, ?)");
    mysqli_stmt_bind_param($stmt, 'iisii', $toUserId, $fromUserId, $type, $postId, $commentId);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
}
