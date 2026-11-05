<?php
require_once 'config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(['success' => false, 'message' => 'POST required'], 405);
}

$data = input();
$username  = trim($data['username'] ?? '');
$password  = $data['password'] ?? '';
$full_name = trim($data['full_name'] ?? '');
$category  = trim($data['category'] ?? 'forex');

if ($username === '' || $password === '' || $full_name === '') {
    respond(['success' => false, 'message' => 'username, password and full_name are required'], 400);
}
if (strlen($password) < 6) {
    respond(['success' => false, 'message' => 'Password must be at least 6 characters'], 400);
}

// Check for existing username
$stmt = mysqli_prepare($conn, "SELECT user_id FROM users WHERE username = ? LIMIT 1");
mysqli_stmt_bind_param($stmt, 's', $username);
mysqli_stmt_execute($stmt);
if (mysqli_stmt_get_result($stmt)->fetch_assoc()) {
    respond(['success' => false, 'message' => 'Username already taken'], 409);
}
mysqli_stmt_close($stmt);

$hash = password_hash($password, PASSWORD_DEFAULT);
$email = ''; // matches the website's own registration flow, which never collects email either

$stmt = mysqli_prepare($conn, "INSERT INTO users (username, email, password, full_name, category) VALUES (?, ?, ?, ?, ?)");
mysqli_stmt_bind_param($stmt, 'sssss', $username, $email, $hash, $full_name, $category);

if (!mysqli_stmt_execute($stmt)) {
    respond(['success' => false, 'message' => 'Registration failed'], 500);
}
$userId = mysqli_insert_id($conn);
mysqli_stmt_close($stmt);

// Log them straight in: issue a token
$token = generateToken();
$tokenHash = hash('sha256', $token);
$deviceName = trim($data['device_name'] ?? 'mobile');
$expires = date('Y-m-d H:i:s', strtotime('+90 days'));

$stmt = mysqli_prepare($conn, "INSERT INTO api_tokens (user_id, token_hash, device_name, expires_at) VALUES (?, ?, ?, ?)");
mysqli_stmt_bind_param($stmt, 'isss', $userId, $tokenHash, $deviceName, $expires);
mysqli_stmt_execute($stmt);
mysqli_stmt_close($stmt);

respond([
    'success' => true,
    'token' => $token,
    'user' => [
        'user_id' => $userId,
        'username' => $username,
        'email' => $email,
        'full_name' => $full_name,
        'category' => $category,
    ],
]);
