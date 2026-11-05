<?php
// Lets read-only endpoints work for guests (no login).
// Returns the user id for a valid token, or 0 for a guest.
// A token that IS sent but is invalid/expired still gets 401 from requireAuth().
require_once 'config.php';

function optionalAuth(mysqli $conn): int {
    $headers = function_exists('getallheaders') ? getallheaders() : [];
    $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
    if (!$authHeader) return 0;
    return requireAuth($conn);
}
