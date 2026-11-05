<?php
require_once 'config.php';

$userId = requireAuth($conn);

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $stmt = mysqli_prepare($conn, "SELECT n.notification_id, n.type, n.post_id, n.is_read, n.created_at,
        u.username AS from_username, u.full_name AS from_full_name, u.profile_image AS from_profile_image
        FROM notifications n JOIN users u ON u.user_id = n.from_user_id
        WHERE n.user_id = ?" . blockedFilter('n.from_user_id', $userId) . " ORDER BY n.created_at DESC LIMIT 100");
    mysqli_stmt_bind_param($stmt, 'i', $userId);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    $notifications = [];
    $unread = 0;
    while ($row = mysqli_fetch_assoc($result)) {
        $row['is_read'] = (bool)$row['is_read'];
        if (!$row['is_read']) {
            $unread++;
        }
        $notifications[] = $row;
    }
    mysqli_stmt_close($stmt);

    respond(['success' => true, 'notifications' => $notifications, 'unread_count' => $unread]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Mark all as read, or a single notification if notification_id is provided
    $data = input();
    $notificationId = (int)($data['notification_id'] ?? 0);

    if ($notificationId) {
        $stmt = mysqli_prepare($conn, "UPDATE notifications SET is_read = 1 WHERE notification_id = ? AND user_id = ?");
        mysqli_stmt_bind_param($stmt, 'ii', $notificationId, $userId);
    } else {
        $stmt = mysqli_prepare($conn, "UPDATE notifications SET is_read = 1 WHERE user_id = ?");
        mysqli_stmt_bind_param($stmt, 'i', $userId);
    }
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);

    respond(['success' => true]);
}

respond(['success' => false, 'message' => 'Method not allowed'], 405);
