<?php

require_once '../includes/config.php';   // same config the web api/ folder uses -> provides $conn
header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true);
$email = trim(is_array($input) && isset($input['email']) ? $input['email'] : '');

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'message' => 'Enter a valid email address.']);
    exit;
}

$generic = ['success' => true, 'message' => 'If that email is registered, a reset link is on its way.'];

$stmt = mysqli_prepare($conn, "SELECT user_id, username, is_blocked FROM users WHERE email = ? LIMIT 1");
mysqli_stmt_bind_param($stmt, 's', $email);
mysqli_stmt_execute($stmt);
$user = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
mysqli_stmt_close($stmt);

if (!$user || (int)$user['is_blocked'] === 1) {
    echo json_encode($generic);
    exit;
}

$uid = (int)$user['user_id'];

// Rate limit: max 3 requests per hour per account
$r = mysqli_query($conn, "SELECT COUNT(*) c FROM password_resets WHERE user_id = $uid AND created_at > (NOW() - INTERVAL 1 HOUR)");
$row = mysqli_fetch_assoc($r);
if ((int)$row['c'] >= 3) {
    echo json_encode($generic);
    exit;
}

// Remove older unused links, then create a new one
mysqli_query($conn, "DELETE FROM password_resets WHERE user_id = $uid AND used_at IS NULL");

$token = bin2hex(random_bytes(32));
$hash  = hash('sha256', $token);

$stmt = mysqli_prepare($conn, "INSERT INTO password_resets (user_id, token_hash, expires_at) VALUES (?, ?, NOW() + INTERVAL 1 HOUR)");
mysqli_stmt_bind_param($stmt, 'is', $uid, $hash);
mysqli_stmt_execute($stmt);
mysqli_stmt_close($stmt);

$link    = 'https://nothyp.hostingersite.com/reset_password.php?token=' . $token;
$subject = 'Reset your nothyp password';
$body    = "Hi " . $user['username'] . ",\n\n"
         . "Use this link to choose a new password (valid for 1 hour):\n$link\n\n"
         . "If you didn't ask for this, you can ignore this email.\n\n- nothyp";
$headers = "From: nothyp <noreply@nothyp.com>\r\n"
         . "Content-Type: text/plain; charset=UTF-8\r\n";

@mail($email, $subject, $body, $headers);

echo json_encode($generic);
