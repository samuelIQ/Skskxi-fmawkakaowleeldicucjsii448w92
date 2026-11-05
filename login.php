<?php
require_once 'config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(['success' => false, 'message' => 'POST required'], 405);
}

$data = input();
$username = trim($data['username'] ?? '');
$password = $data['password'] ?? '';
$deviceName = trim($data['device_name'] ?? 'mobile');

if ($username === '' || $password === '') {
    respond(['success' => false, 'message' => 'username and password are required'], 400);
}

$stmt = mysqli_prepare($conn, "SELECT * FROM users WHERE username = ? LIMIT 1");
mysqli_stmt_bind_param($stmt, 's', $username);
mysqli_stmt_execute($stmt);
$user = mysqli_stmt_get_result($stmt)->fetch_assoc();
mysqli_stmt_close($stmt);



// login.php, before checking the password
rateLimit($conn, 'login_ip_' . $_SERVER['REMOTE_ADDR'], 5, 900);
rateLimit($conn, 'login_user_' . strtolower($username), 5, 900);
if (!$user || !password_verify($password, $user['password'])) {
    respond(['success' => false, 'message' => 'Invalid username or password'], 401);
}
if ((int)($user['is_blocked'] ?? 0) === 1) {
    respond(['success' => false, 'message' => 'This account has been blocked'], 403);
}

$token = generateToken();
$tokenHash = hash('sha256', $token);
$expires = date('Y-m-d H:i:s', strtotime('+90 days'));

$stmt = mysqli_prepare($conn, "INSERT INTO api_tokens (user_id, token_hash, device_name, expires_at) VALUES (?, ?, ?, ?)");
mysqli_stmt_bind_param($stmt, 'isss', $user['user_id'], $tokenHash, $deviceName, $expires);
mysqli_stmt_execute($stmt);
mysqli_stmt_close($stmt);

unset($user['password']); // never return the hash

respond([
    'success' => true,
    'token' => $token,
    'user' => $user,
]);
