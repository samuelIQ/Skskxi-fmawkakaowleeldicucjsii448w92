<?php
// mobile-api/chat_contacts.php
// GET -> "New chat" contact list: people I follow who have also joined
// Gist, A-Z by name. (Phone-number matching will be added later once
// phone verification is in - for now this is follow-graph based.)
require_once 'config.php';

$userId = requireAuth($conn);

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    respond(['success' => false, 'message' => 'Method not allowed'], 405);
}

$search = trim($_GET['search'] ?? '');

$sql = "SELECT u.user_id, u.username, u.full_name, u.profile_image, u.is_pro,
        cu.is_online, cu.last_seen,
        c.conversation_id
        FROM followers f
        JOIN users u ON u.user_id = f.user_id
        JOIN chat_users cu ON cu.user_id = u.user_id
        LEFT JOIN chat_conversations c ON
            (c.user_one_id = LEAST(?, u.user_id) AND c.user_two_id = GREATEST(?, u.user_id))
        WHERE f.follower_user_id = ?" . blockedFilter('u.user_id', $userId) . "";
$types = 'iii';
$params = [$userId, $userId, $userId];

if ($search !== '') {
    $sql .= " AND (u.username LIKE ? OR u.full_name LIKE ?)";
    $like = "%$search%";
    $types .= 'ss';
    $params[] = $like;
    $params[] = $like;
}

$sql .= " ORDER BY u.full_name ASC, u.username ASC";

$stmt = mysqli_prepare($conn, $sql);
mysqli_stmt_bind_param($stmt, $types, ...$params);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

$contacts = [];
while ($row = mysqli_fetch_assoc($result)) {
    $row['is_online'] = (bool)$row['is_online'];
    $row['conversation_id'] = $row['conversation_id'] !== null ? (int)$row['conversation_id'] : null;
    $contacts[] = $row;
}
mysqli_stmt_close($stmt);

respond(['success' => true, 'contacts' => $contacts]);
