<?php
// mobile-api/config.php
// Shared bootstrap for every mobile-api endpoint.

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '0');

define('DB_HOST', 'localhost');
define('DB_USER', '...');
define('DB_PASS', '...');
define('DB_NAME', '....');

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Authorization, Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$conn = mysqli_connect(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if (!$conn) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database connection failed']);
    exit;
}
mysqli_set_charset($conn, 'utf8mb4');
mysqli_query($conn, "SET time_zone = '+00:00'");

/** Send a JSON response and stop execution. */
function respond($data, int $code = 200): void {
    http_response_code($code);
    echo json_encode($data);
    exit;
}

/** Read JSON or form-encoded body into an assoc array. */
function input(): array {
    $raw = file_get_contents('php://input');
    $json = json_decode($raw, true);
    if (is_array($json)) {
        return $json;
    }
    return $_POST;
}

/**
 * Require a valid Bearer token. Returns the authenticated user_id.
 * Sends a 401 JSON response and exits if missing/invalid/expired.
 */
function requireAuth(mysqli $conn): int {
    $headers = function_exists('getallheaders') ? getallheaders() : [];
    $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? ($_SERVER['HTTP_AUTHORIZATION'] ?? '');

    if (!$authHeader || stripos($authHeader, 'Bearer ') !== 0) {
        respond(['success' => false, 'message' => 'Missing or invalid Authorization header'], 401);
    }

    $token = trim(substr($authHeader, 7));
    $tokenHash = hash('sha256', $token);

    $stmt = mysqli_prepare($conn, "SELECT user_id, expires_at FROM api_tokens WHERE token_hash = ? LIMIT 1");
    mysqli_stmt_bind_param($stmt, 's', $tokenHash);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $row = mysqli_fetch_assoc($result);
    mysqli_stmt_close($stmt);

    if (!$row) {
        respond(['success' => false, 'message' => 'Invalid or expired token'], 401);
    }
    if ($row['expires_at'] !== null && strtotime($row['expires_at']) < time()) {
        respond(['success' => false, 'message' => 'Token expired, please log in again'], 401);
    }

    return (int) $row['user_id'];
}

/** Generate a random opaque API token (the raw value, only ever shown once). */
function generateToken(): string {
    return bin2hex(random_bytes(32));
}





function rateLimit(mysqli $conn, string $key, int $max, int $seconds): void {
    $window = intdiv(time(), $seconds) * $seconds;
    $stmt = mysqli_prepare($conn,
      "INSERT INTO rate_limits (rl_key, window_start, hits) VALUES (?, ?, 1)
       ON DUPLICATE KEY UPDATE hits = hits + 1");
    mysqli_stmt_bind_param($stmt, 'si', $key, $window);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);

    $stmt = mysqli_prepare($conn,
      "SELECT hits FROM rate_limits WHERE rl_key = ? AND window_start = ?");
    mysqli_stmt_bind_param($stmt, 'si', $key, $window);
    mysqli_stmt_execute($stmt);
    $row = mysqli_stmt_get_result($stmt)->fetch_assoc();
    mysqli_stmt_close($stmt);

    if ($row && (int)$row['hits'] > $max) {
        header('Retry-After: ' . $seconds);
        respond(['success' => false, 'message' => 'Too many requests, try again later'], 429);
    }
}



function blockedFilter(string $column, int $uid): string {
    $uid = (int)$uid;
    if ($uid <= 0) return '';
    return " AND $column NOT IN (SELECT blocked_id FROM user_blocks WHERE blocker_id = $uid"
         . " UNION SELECT blocker_id FROM user_blocks WHERE blocked_id = $uid)";
}

/** True if either user has blocked the other. */
function isBlockedBetween(mysqli $conn, int $a, int $b): bool {
    $stmt = mysqli_prepare($conn,
        "SELECT 1 FROM user_blocks WHERE (blocker_id = ? AND blocked_id = ?) OR (blocker_id = ? AND blocked_id = ?) LIMIT 1");
    mysqli_stmt_bind_param($stmt, 'iiii', $a, $b, $b, $a);
    mysqli_stmt_execute($stmt);
    $found = (bool) mysqli_stmt_get_result($stmt)->fetch_row();
    mysqli_stmt_close($stmt);
    return $found;
}
