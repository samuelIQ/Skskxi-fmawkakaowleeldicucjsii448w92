<?php
require_once 'config.php';

$userId = requireAuth($conn);

$stmt = mysqli_prepare($conn, "SELECT user_id, username, email, full_name, bio, profile_image, cover_image,
    is_pro, pro_expires_at, is_admin, category, created_at, birth_year, gender, age_changed_at,
    (SELECT COUNT(*) FROM followers WHERE user_id = users.user_id) AS followers_count,
    (SELECT COUNT(*) FROM followers WHERE follower_user_id = users.user_id) AS following_count,
    (SELECT COUNT(*) FROM posts WHERE posts.user_id = users.user_id) AS posts_count
    FROM users WHERE user_id = ? LIMIT 1");
mysqli_stmt_bind_param($stmt, 'i', $userId);
mysqli_stmt_execute($stmt);
$user = mysqli_stmt_get_result($stmt)->fetch_assoc();
mysqli_stmt_close($stmt);

if (!$user) {
    respond(['success' => false, 'message' => 'User not found'], 404);
}

// age is kept as a birth year so it stays correct every year
$user['age'] = $user['birth_year'] !== null ? (int)date('Y') - (int)$user['birth_year'] : null;
// after a change, the age can't be changed again for 30 days
$user['age_locked_until'] = ($user['age_changed_at'] && strtotime($user['age_changed_at']) > strtotime('-30 days'))
    ? date('M j, Y', strtotime($user['age_changed_at'] . ' +30 days')) : null;
unset($user['birth_year'], $user['age_changed_at']);

respond(['success' => true, 'user' => $user]);
