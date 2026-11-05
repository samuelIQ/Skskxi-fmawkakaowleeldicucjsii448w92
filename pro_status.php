<?php
require_once 'config.php';

$userId = requireAuth($conn);

$stmt = mysqli_prepare($conn, "SELECT is_pro, pro_expires_at FROM users WHERE user_id = ?");
mysqli_stmt_bind_param($stmt, 'i', $userId);
mysqli_stmt_execute($stmt);
$row = mysqli_stmt_get_result($stmt)->fetch_assoc();
mysqli_stmt_close($stmt);

if (!$row) {
    respond(['success' => false, 'message' => 'User not found'], 404);
}

$isActive = (bool)$row['is_pro'] && ($row['pro_expires_at'] === null || strtotime($row['pro_expires_at']) > time());

respond([
    'success' => true,
    'is_pro' => $isActive,
    'pro_expires_at' => $row['pro_expires_at'],
]);
