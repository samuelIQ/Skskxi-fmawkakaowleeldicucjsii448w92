<?php
// mobile-api/chat_conversations.php
// GET  -> my Gist inbox: conversations sorted by most recent activity,
//         with the other participant's info, online status, last
//         message preview, and my unread count.
// POST -> action=start        (user_id)             get-or-create a conversation with someone
//         action=delete_for_me       (conversation_id)     hide the chat from just my inbox
//         action=delete_for_both     (conversation_id)     wipe it for both users
require_once 'config.php';

$userId = requireAuth($conn);

/// These three wrappers turn a bad query/execute/result at any point into
/// a clean JSON error response instead of a raw PHP fatal error that
/// would corrupt the response body while still returning HTTP 200.
function chatPrepare(mysqli $conn, string $sql) {
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        respond(['success' => false, 'message' => 'DB error (prepare): ' . mysqli_error($conn)], 500);
    }
    return $stmt;
}

function chatExec($stmt): void {
    if (!mysqli_stmt_execute($stmt)) {
        respond(['success' => false, 'message' => 'DB error (execute): ' . mysqli_stmt_error($stmt)], 500);
    }
}

function chatResult($stmt) {
    $result = mysqli_stmt_get_result($stmt);
    if ($result === false) {
        respond(['success' => false, 'message' => 'DB error (result): ' . mysqli_stmt_error($stmt)], 500);
    }
    return $result;
}

function chatEnsureRegistered(mysqli $conn, int $userId): void {
    $stmt = chatPrepare($conn, "SELECT chat_user_id FROM chat_users WHERE user_id = ?");
    mysqli_stmt_bind_param($stmt, 'i', $userId);
    chatExec($stmt);
    $row = chatResult($stmt)->fetch_assoc();
    mysqli_stmt_close($stmt);
    if (!$row) {
        respond(['success' => false, 'message' => 'Register with Nothyp Gist first'], 403);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    chatEnsureRegistered($conn, $userId);

    $sql = "SELECT c.conversation_id, c.updated_at,
            IF(c.user_one_id = ?, u2.user_id, u1.user_id) AS other_user_id,
            IF(c.user_one_id = ?, u2.username, u1.username) AS other_username,
            IF(c.user_one_id = ?, u2.full_name, u1.full_name) AS other_full_name,
            IF(c.user_one_id = ?, u2.profile_image, u1.profile_image) AS other_profile_image,
            IF(c.user_one_id = ?, cu2.is_online, cu1.is_online) AS other_is_online,
            IF(c.user_one_id = ?, cu2.last_seen, cu1.last_seen) AS other_last_seen,
            m.content AS last_message_content, m.image AS last_message_image,
            m.voice_note AS last_message_voice_note, m.sticker AS last_message_sticker,
            m.sender_id AS last_message_sender_id, m.created_at AS last_message_at,
            s.unread_count
            FROM chat_conversations c
            JOIN chat_conversation_state s ON s.conversation_id = c.conversation_id AND s.user_id = ?
            JOIN users u1 ON u1.user_id = c.user_one_id
            JOIN users u2 ON u2.user_id = c.user_two_id
            LEFT JOIN chat_users cu1 ON cu1.user_id = c.user_one_id
            LEFT JOIN chat_users cu2 ON cu2.user_id = c.user_two_id
            LEFT JOIN chat_messages m ON m.message_id = c.last_message_id
            WHERE (c.user_one_id = ? OR c.user_two_id = ?) AND s.is_deleted = 0" . blockedFilter('IF(c.user_one_id = ' . (int)$userId . ', c.user_two_id, c.user_one_id)', $userId) . "
            ORDER BY c.updated_at DESC";
    $stmt = chatPrepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, 'iiiiiiiii', $userId, $userId, $userId, $userId, $userId, $userId, $userId, $userId, $userId);
    chatExec($stmt);
    $result = chatResult($stmt);

    $conversations = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $row['other_is_online'] = (bool)$row['other_is_online'];
        $row['unread_count'] = (int)$row['unread_count'];
        $conversations[] = $row;
    }
    mysqli_stmt_close($stmt);

    respond(['success' => true, 'conversations' => $conversations]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    chatEnsureRegistered($conn, $userId);
    $data = input();
    $action = $data['action'] ?? '';

    if ($action === 'start') {
        $targetUserId = (int)($data['user_id'] ?? 0);
        if (!$targetUserId || $targetUserId === $userId) {
            respond(['success' => false, 'message' => 'A valid user_id is required'], 400);
        }
        if (isBlockedBetween($conn, $userId, $targetUserId)) {
            respond(['success' => false, 'message' => "You can't message this user"], 403);
        }

        $stmt = chatPrepare($conn, "SELECT chat_user_id FROM chat_users WHERE user_id = ?");
        mysqli_stmt_bind_param($stmt, 'i', $targetUserId);
        chatExec($stmt);
        $targetRegistered = chatResult($stmt)->fetch_assoc();
        mysqli_stmt_close($stmt);
        if (!$targetRegistered) {
            respond(['success' => false, 'message' => 'That user is not on Gist yet'], 400);
        }

        $userOne = min($userId, $targetUserId);
        $userTwo = max($userId, $targetUserId);

        $stmt = chatPrepare($conn, "SELECT conversation_id FROM chat_conversations WHERE user_one_id = ? AND user_two_id = ?");
        mysqli_stmt_bind_param($stmt, 'ii', $userOne, $userTwo);
        chatExec($stmt);
        $existing = chatResult($stmt)->fetch_assoc();
        mysqli_stmt_close($stmt);

        if ($existing) {
            $conversationId = (int)$existing['conversation_id'];
        } else {
            $stmt = chatPrepare($conn, "INSERT INTO chat_conversations (user_one_id, user_two_id) VALUES (?, ?)");
            mysqli_stmt_bind_param($stmt, 'ii', $userOne, $userTwo);
            chatExec($stmt);
            $conversationId = mysqli_insert_id($conn);
            mysqli_stmt_close($stmt);
        }

        // Make sure both sides have a state row, and un-hide it for me if
        // I'd previously deleted it (I'm reopening it by messaging again).
        foreach ([$userId, $targetUserId] as $participant) {
            $stmt = chatPrepare($conn, "INSERT INTO chat_conversation_state (conversation_id, user_id) VALUES (?, ?)
                ON DUPLICATE KEY UPDATE conversation_id = conversation_id");
            mysqli_stmt_bind_param($stmt, 'ii', $conversationId, $participant);
            chatExec($stmt);
            mysqli_stmt_close($stmt);
        }
        $stmt = chatPrepare($conn, "UPDATE chat_conversation_state SET is_deleted = 0 WHERE conversation_id = ? AND user_id = ?");
        mysqli_stmt_bind_param($stmt, 'ii', $conversationId, $userId);
        chatExec($stmt);
        mysqli_stmt_close($stmt);

        respond(['success' => true, 'conversation_id' => $conversationId]);
    }

    if ($action === 'delete_for_me' || $action === 'delete_for_both') {
        $conversationId = (int)($data['conversation_id'] ?? 0);
        if (!$conversationId) {
            respond(['success' => false, 'message' => 'conversation_id is required'], 400);
        }

        $stmt = chatPrepare($conn, "SELECT user_one_id, user_two_id FROM chat_conversations WHERE conversation_id = ?");
        mysqli_stmt_bind_param($stmt, 'i', $conversationId);
        chatExec($stmt);
        $conv = chatResult($stmt)->fetch_assoc();
        mysqli_stmt_close($stmt);

        if (!$conv || ((int)$conv['user_one_id'] !== $userId && (int)$conv['user_two_id'] !== $userId)) {
            respond(['success' => false, 'message' => 'Conversation not found'], 404);
        }

        if ($action === 'delete_for_both') {
            $stmt = chatPrepare($conn, "DELETE FROM chat_conversations WHERE conversation_id = ?");
            mysqli_stmt_bind_param($stmt, 'i', $conversationId);
            chatExec($stmt);
            mysqli_stmt_close($stmt);
            // chat_conversation_state and chat_messages cascade-delete via FK.
        } else {
            $stmt = chatPrepare($conn, "UPDATE chat_conversation_state SET is_deleted = 1, deleted_at = NOW(), cleared_before_message_id =
                (SELECT last_message_id FROM chat_conversations WHERE conversation_id = ?)
                WHERE conversation_id = ? AND user_id = ?");
            mysqli_stmt_bind_param($stmt, 'iii', $conversationId, $conversationId, $userId);
            chatExec($stmt);
            mysqli_stmt_close($stmt);
        }

        respond(['success' => true]);
    }

    respond(['success' => false, 'message' => 'Unknown action'], 400);
}

respond(['success' => false, 'message' => 'Method not allowed'], 405);
