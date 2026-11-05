<?php
require_once 'config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(['success' => false, 'message' => 'POST required'], 405);
}

$headers = function_exists('getallheaders') ? getallheaders() : [];
$authHeader = $headers['Authorization'] ?? ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
$token = stripos($authHeader, 'Bearer ') === 0 ? trim(substr($authHeader, 7)) : '';

if ($token !== '') {
    $tokenHash = hash('sha256', $token);
    $stmt = mysqli_prepare($conn, "DELETE FROM api_tokens WHERE token_hash = ?");
    mysqli_stmt_bind_param($stmt, 's', $tokenHash);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
}

respond(['success' => true, 'message' => 'Logged out']);
