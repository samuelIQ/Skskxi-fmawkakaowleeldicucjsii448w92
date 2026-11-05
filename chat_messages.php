<?php
// mobile-api/chat_messages.php
// GET  -> messages in a conversation, oldest-to-newest (chat order),
//         paginated like comments.php. Also marks them read for me.
// POST -> send a message: text (JSON), or image/voice_note (multipart),
//         or a sticker (JSON, sticker = path from chat_stickers).
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

function chatAssertParticipant(mysqli $conn, int $conversationId, int $userId): array {
    $stmt = chatPrepare($conn, "SELECT user_one_id, user_two_id FROM chat_conversations WHERE conversation_id = ?");
    mysqli_stmt_bind_param($stmt, 'i', $conversationId);
    chatExec($stmt);
    $conv = chatResult($stmt)->fetch_assoc();
    mysqli_stmt_close($stmt);

    if (!$conv || ((int)$conv['user_one_id'] !== $userId && (int)$conv['user_two_id'] !== $userId)) {
        respond(['success' => false, 'message' => 'Conversation not found'], 404);
    }
    $otherId = (int)$conv['user_one_id'] === $userId ? (int)$conv['user_two_id'] : (int)$conv['user_one_id'];
    return ['other_id' => $otherId];
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $conversationId = (int)($_GET['conversation_id'] ?? 0);
    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = min(100, max(1, (int)($_GET['limit'] ?? 30)));
    $offset = ($page - 1) * $limit;

    if (!$conversationId) {
        respond(['success' => false, 'message' => 'conversation_id is required'], 400);
    }
    chatAssertParticipant($conn, $conversationId, $userId);

    // Respect this user's "delete for me" clear point, if any.
    $stmt = chatPrepare($conn, "SELECT cleared_before_message_id FROM chat_conversation_state WHERE conversation_id = ? AND user_id = ?");
    mysqli_stmt_bind_param($stmt, 'ii', $conversationId, $userId);
    chatExec($stmt);
    $clearedBefore = chatResult($stmt)->fetch_assoc()['cleared_before_message_id'] ?? null;
    mysqli_stmt_close($stmt);

    if ($clearedBefore) {
        $stmt = chatPrepare($conn, "SELECT message_id, sender_id, content, image, voice_note, sticker, reply_to_message_id,
            is_deleted_for_everyone, created_at FROM chat_messages
            WHERE conversation_id = ? AND message_id > ?
            ORDER BY created_at ASC LIMIT ? OFFSET ?");
        mysqli_stmt_bind_param($stmt, 'iiii', $conversationId, $clearedBefore, $limit, $offset);
    } else {
        $stmt = chatPrepare($conn, "SELECT message_id, sender_id, content, image, voice_note, sticker, reply_to_message_id,
            is_deleted_for_everyone, created_at FROM chat_messages
            WHERE conversation_id = ?
            ORDER BY created_at ASC LIMIT ? OFFSET ?");
        mysqli_stmt_bind_param($stmt, 'iii', $conversationId, $limit, $offset);
    }
    chatExec($stmt);
    $result = chatResult($stmt);

    $messages = [];
    $lastId = null;
    while ($row = mysqli_fetch_assoc($result)) {
        $row['is_deleted_for_everyone'] = (bool)$row['is_deleted_for_everyone'];
        $messages[] = $row;
        $lastId = $row['message_id'];
    }
    mysqli_stmt_close($stmt);

    // Mark read up to the newest message we just returned, and zero my
    // unread count for this conversation.
    if ($lastId) {
        $stmt = chatPrepare($conn, "UPDATE chat_conversation_state SET last_read_message_id = ?, unread_count = 0 WHERE conversation_id = ? AND user_id = ?");
        mysqli_stmt_bind_param($stmt, 'iii', $lastId, $conversationId, $userId);
        chatExec($stmt);
        mysqli_stmt_close($stmt);
    }

    respond(['success' => true, 'messages' => $messages, 'page' => $page, 'limit' => $limit, 'has_more' => count($messages) === $limit]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $isMultipart = str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'multipart/form-data');
    $src = $isMultipart ? $_POST : input();

    if (($src['action'] ?? '') === 'delete_message') {
        $messageId = (int)($src['message_id'] ?? 0);
        $forEveryone = ($src['for_everyone'] ?? false) == true;
        if (!$messageId) {
            respond(['success' => false, 'message' => 'message_id is required'], 400);
        }
        $stmt = chatPrepare($conn, "SELECT sender_id FROM chat_messages WHERE message_id = ?");
        mysqli_stmt_bind_param($stmt, 'i', $messageId);
        chatExec($stmt);
        $owner = chatResult($stmt)->fetch_assoc();
        mysqli_stmt_close($stmt);
        if (!$owner || (int)$owner['sender_id'] !== $userId) {
            respond(['success' => false, 'message' => 'You can only delete your own messages'], 403);
        }
        // Always a full delete - no "This message was deleted" placeholder.
        // ($forEveryone kept for future per-user hide-only support.)
        $stmt = chatPrepare($conn, "DELETE FROM chat_messages WHERE message_id = ?");
        mysqli_stmt_bind_param($stmt, 'i', $messageId);
        chatExec($stmt);
        mysqli_stmt_close($stmt);
        respond(['success' => true]);
    }

    $conversationId = (int)($src['conversation_id'] ?? 0);
    $content = trim($src['content'] ?? '');
    $sticker = trim($src['sticker'] ?? '');
    $replyToMessageId = isset($src['reply_to_message_id']) && $src['reply_to_message_id'] !== '' ? (int)$src['reply_to_message_id'] : null;

    if (!$conversationId) {
        respond(['success' => false, 'message' => 'conversation_id is required'], 400);
    }
    $participant = chatAssertParticipant($conn, $conversationId, $userId);
    $otherId = $participant['other_id'];
    if (isBlockedBetween($conn, $userId, $otherId)) {
        respond(['success' => false, 'message' => "You can't message this user"], 403);
    }

    $imagePath = '';
    $voiceNotePath = '';
    $hasImage = isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK;
    $hasVoiceNote = isset($_FILES['voice_note']) && $_FILES['voice_note']['error'] === UPLOAD_ERR_OK;

    if ($hasImage || $hasVoiceNote) {
        $destDir = __DIR__ . '/../uploads/chat/';
        if (!is_dir($destDir) && !@mkdir($destDir, 0755, true) && !is_dir($destDir)) {
            respond(['success' => false, 'message' => 'Server could not create the upload folder (uploads/chat/) - check folder permissions'], 500);
        }
        if ($hasImage) {
            $allowedImageExt = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
            $ext = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, $allowedImageExt, true)) {
                respond(['success' => false, 'message' => 'Invalid image type'], 400);
            }
            $filename = bin2hex(random_bytes(8)) . '.' . $ext;
            if (!move_uploaded_file($_FILES['image']['tmp_name'], $destDir . $filename)) {
                respond(['success' => false, 'message' => 'Image upload failed'], 500);
            }
            $imagePath = 'uploads/chat/' . $filename;
        }
        if ($hasVoiceNote) {
            $allowedAudioExt = ['m4a', 'mp3', 'aac', 'wav', 'ogg'];
            $ext = strtolower(pathinfo($_FILES['voice_note']['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, $allowedAudioExt, true)) {
                respond(['success' => false, 'message' => 'Invalid audio type'], 400);
            }
            $filename = bin2hex(random_bytes(8)) . '.' . $ext;
            if (!move_uploaded_file($_FILES['voice_note']['tmp_name'], $destDir . $filename)) {
                respond(['success' => false, 'message' => 'Voice note upload failed'], 500);
            }
            $voiceNotePath = 'uploads/chat/' . $filename;
        }
    }

    if ($content === '' && $imagePath === '' && $voiceNotePath === '' && $sticker === '') {
        respond(['success' => false, 'message' => 'Message cannot be empty'], 400);
    }

    $stmt = chatPrepare($conn, "INSERT INTO chat_messages (conversation_id, sender_id, content, image, voice_note, sticker, reply_to_message_id) VALUES (?, ?, ?, ?, ?, ?, ?)");
    mysqli_stmt_bind_param($stmt, 'iissssi', $conversationId, $userId, $content, $imagePath, $voiceNotePath, $sticker, $replyToMessageId);
    chatExec($stmt);
    $messageId = mysqli_insert_id($conn);
    mysqli_stmt_close($stmt);

    $stmt = chatPrepare($conn, "UPDATE chat_conversations SET last_message_id = ?, updated_at = NOW() WHERE conversation_id = ?");
    mysqli_stmt_bind_param($stmt, 'ii', $messageId, $conversationId);
    chatExec($stmt);
    mysqli_stmt_close($stmt);

    // Bump the recipient's unread count and un-hide the chat for them if
    // they'd deleted it - a new message should bring it back into their
    // inbox, same as WhatsApp/Telegram.
    $stmt = chatPrepare($conn, "UPDATE chat_conversation_state SET unread_count = unread_count + 1, is_deleted = 0 WHERE conversation_id = ? AND user_id = ?");
    mysqli_stmt_bind_param($stmt, 'ii', $conversationId, $otherId);
    chatExec($stmt);
    mysqli_stmt_close($stmt);

    respond([
        'success' => true,
        'message_id' => $messageId,
        'image' => $imagePath,
        'voice_note' => $voiceNotePath,
    ]);
}

respond(['success' => false, 'message' => 'Method not allowed'], 405);
