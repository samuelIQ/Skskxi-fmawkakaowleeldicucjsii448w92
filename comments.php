<?php
require_once 'config.php';
require_once 'notify.php';

$userId = requireAuth($conn);

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $postId = (int)($_GET['post_id'] ?? 0);
    $parentCommentId = isset($_GET['parent_comment_id']) ? (int)$_GET['parent_comment_id'] : null;
    $page = max(1, (int)($_GET['page'] ?? 1));
    $limit = min(100, max(1, (int)($_GET['limit'] ?? 30)));
    $offset = ($page - 1) * $limit;

    if (!$postId && !$parentCommentId) {
        respond(['success' => false, 'message' => 'post_id or parent_comment_id is required'], 400);
    }

    if ($parentCommentId) {
        // Replies to one specific comment - chronological, like a chat thread.
        $stmt = mysqli_prepare($conn, "SELECT c.comment_id, c.user_id, c.post_id, c.parent_comment_id, c.content, c.image, c.voice_note, c.created_at,
            u.username, u.full_name, u.profile_image, u.is_pro,
            (SELECT COUNT(*) FROM comment_likes WHERE comment_id = c.comment_id) AS likes_count,
            (SELECT COUNT(*) FROM comment_dislikes WHERE comment_id = c.comment_id) AS dislikes_count,
            EXISTS(SELECT 1 FROM comment_likes WHERE comment_id = c.comment_id AND user_id = ?) AS my_like,
            EXISTS(SELECT 1 FROM comment_dislikes WHERE comment_id = c.comment_id AND user_id = ?) AS my_dislike
            FROM comments c JOIN users u ON u.user_id = c.user_id
            WHERE c.parent_comment_id = ?" . blockedFilter('c.user_id', $userId) . " ORDER BY c.created_at ASC LIMIT ? OFFSET ?");
        mysqli_stmt_bind_param($stmt, 'iiiii', $userId, $userId, $parentCommentId, $limit, $offset);
    } else {
        // Top-level comments on a post only (no replies mixed in).
        // Chronological (not random) so pagination and live-refresh behave
        // predictably, like a real chat thread.
        $stmt = mysqli_prepare($conn, "SELECT c.comment_id, c.user_id, c.post_id, c.parent_comment_id, c.content, c.image, c.voice_note, c.created_at,
            u.username, u.full_name, u.profile_image, u.is_pro,
            (SELECT COUNT(*) FROM comment_likes WHERE comment_id = c.comment_id) AS likes_count,
            (SELECT COUNT(*) FROM comment_dislikes WHERE comment_id = c.comment_id) AS dislikes_count,
            EXISTS(SELECT 1 FROM comment_likes WHERE comment_id = c.comment_id AND user_id = ?) AS my_like,
            EXISTS(SELECT 1 FROM comment_dislikes WHERE comment_id = c.comment_id AND user_id = ?) AS my_dislike,
            (SELECT COUNT(*) FROM comments r WHERE r.parent_comment_id = c.comment_id) AS reply_count
            FROM comments c JOIN users u ON u.user_id = c.user_id
            WHERE c.post_id = ? AND c.parent_comment_id IS NULL" . blockedFilter('c.user_id', $userId) . " ORDER BY c.created_at ASC LIMIT ? OFFSET ?");
        mysqli_stmt_bind_param($stmt, 'iiiii', $userId, $userId, $postId, $limit, $offset);
    }

    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    $comments = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $row['my_like'] = (bool)$row['my_like'];
        $row['my_dislike'] = (bool)$row['my_dislike'];
        $comments[] = $row;
    }
    mysqli_stmt_close($stmt);

    respond(['success' => true, 'comments' => $comments, 'page' => $page, 'limit' => $limit, 'has_more' => count($comments) === $limit]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $isMultipart = str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'multipart/form-data');

    if (!$isMultipart) {
        $data = input();
        if (($data['action'] ?? '') === 'delete_comment') {
            $commentId = (int)($data['comment_id'] ?? 0);
            if (!$commentId) {
                respond(['success' => false, 'message' => 'comment_id is required'], 400);
            }

            $stmt = mysqli_prepare($conn, "SELECT user_id FROM comments WHERE comment_id = ?");
            mysqli_stmt_bind_param($stmt, 'i', $commentId);
            mysqli_stmt_execute($stmt);
            $owner = mysqli_stmt_get_result($stmt)->fetch_assoc();
            mysqli_stmt_close($stmt);

            if (!$owner) {
                respond(['success' => false, 'message' => 'Comment not found'], 404);
            }
            if ((int)$owner['user_id'] !== $userId) {
                respond(['success' => false, 'message' => 'You can only delete your own comments'], 403);
            }

            // Cascade: replies to this comment, and every comment's own reactions
            $stmt = mysqli_prepare($conn, "SELECT comment_id FROM comments WHERE parent_comment_id = ?");
            mysqli_stmt_bind_param($stmt, 'i', $commentId);
            mysqli_stmt_execute($stmt);
            $replyIds = array_column(mysqli_stmt_get_result($stmt)->fetch_all(MYSQLI_ASSOC), 'comment_id');
            mysqli_stmt_close($stmt);

            $allIds = array_merge([$commentId], $replyIds);
            foreach ($allIds as $id) {
                $stmt = mysqli_prepare($conn, "DELETE FROM comment_likes WHERE comment_id = ?");
                mysqli_stmt_bind_param($stmt, 'i', $id);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);

                $stmt = mysqli_prepare($conn, "DELETE FROM comment_dislikes WHERE comment_id = ?");
                mysqli_stmt_bind_param($stmt, 'i', $id);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_close($stmt);
            }

            $stmt = mysqli_prepare($conn, "DELETE FROM comments WHERE comment_id = ? OR parent_comment_id = ?");
            mysqli_stmt_bind_param($stmt, 'ii', $commentId, $commentId);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);

            respond(['success' => true]);
        }
        respond(['success' => false, 'message' => 'Unknown action'], 400);
    }

    $postId = (int)($_POST['post_id'] ?? 0);
    $parentCommentId = isset($_POST['parent_comment_id']) && $_POST['parent_comment_id'] !== '' ? (int)$_POST['parent_comment_id'] : null;
    $content = trim($_POST['content'] ?? '');

    if (!$postId) {
        respond(['success' => false, 'message' => 'post_id is required'], 400);
    }

    $allowedImageExt = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    $allowedAudioExt = ['m4a', 'mp3', 'aac', 'wav', 'ogg'];
    $destDir = __DIR__ . '/../uploads/comments/';
    if (!is_dir($destDir)) {
        mkdir($destDir, 0755, true);
    }

    $imagePath = '';
    if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
        $ext = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowedImageExt, true)) {
            respond(['success' => false, 'message' => 'Invalid image type'], 400);
        }
        $filename = bin2hex(random_bytes(8)) . '.' . $ext;
        if (!move_uploaded_file($_FILES['image']['tmp_name'], $destDir . $filename)) {
            respond(['success' => false, 'message' => 'Image upload failed'], 500);
        }
        $imagePath = 'uploads/comments/' . $filename;
    }

    $voiceNotePath = '';
    if (isset($_FILES['voice_note']) && $_FILES['voice_note']['error'] === UPLOAD_ERR_OK) {
        $ext = strtolower(pathinfo($_FILES['voice_note']['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowedAudioExt, true)) {
            respond(['success' => false, 'message' => 'Invalid audio type'], 400);
        }
        $filename = bin2hex(random_bytes(8)) . '.' . $ext;
        if (!move_uploaded_file($_FILES['voice_note']['tmp_name'], $destDir . $filename)) {
            respond(['success' => false, 'message' => 'Voice note upload failed'], 500);
        }
        $voiceNotePath = 'uploads/comments/' . $filename;
    }

    if ($content === '' && $imagePath === '' && $voiceNotePath === '') {
        respond(['success' => false, 'message' => 'Comment cannot be empty'], 400);
    }

    $stmt = mysqli_prepare($conn, "INSERT INTO comments (user_id, post_id, parent_comment_id, content, image, voice_note) VALUES (?, ?, ?, ?, ?, ?)");
    mysqli_stmt_bind_param($stmt, 'iiisss', $userId, $postId, $parentCommentId, $content, $imagePath, $voiceNotePath);
    if (!mysqli_stmt_execute($stmt)) {
        respond(['success' => false, 'message' => 'Failed to add comment'], 500);
    }
    $commentId = mysqli_insert_id($conn);
    mysqli_stmt_close($stmt);

    if ($parentCommentId) {
        $stmt = mysqli_prepare($conn, "SELECT user_id FROM comments WHERE comment_id = ?");
        mysqli_stmt_bind_param($stmt, 'i', $parentCommentId);
        mysqli_stmt_execute($stmt);
        $parentOwnerId = mysqli_stmt_get_result($stmt)->fetch_assoc()['user_id'] ?? null;
        mysqli_stmt_close($stmt);
        if ($parentOwnerId) {
            createNotification($conn, (int)$parentOwnerId, $userId, 'reply', $postId, $commentId);
        }
    } else {
        $stmt = mysqli_prepare($conn, "SELECT user_id FROM posts WHERE post_id = ?");
        mysqli_stmt_bind_param($stmt, 'i', $postId);
        mysqli_stmt_execute($stmt);
        $ownerId = mysqli_stmt_get_result($stmt)->fetch_assoc()['user_id'] ?? null;
        mysqli_stmt_close($stmt);
        if ($ownerId) {
            createNotification($conn, (int)$ownerId, $userId, 'comment', $postId, $commentId);
        }
    }

    if (preg_match_all('/@(\w+)/', $content, $matches)) {
        $mentioned = array_unique($matches[1]);
        foreach ($mentioned as $mentionedUsername) {
            $stmt = mysqli_prepare($conn, "SELECT user_id FROM users WHERE username = ?");
            mysqli_stmt_bind_param($stmt, 's', $mentionedUsername);
            mysqli_stmt_execute($stmt);
            $mentionedUser = mysqli_stmt_get_result($stmt)->fetch_assoc();
            mysqli_stmt_close($stmt);
            if ($mentionedUser) {
                createNotification($conn, (int)$mentionedUser['user_id'], $userId, 'mention', $postId, $commentId);
            }
        }
    }

    respond(['success' => true, 'comment_id' => $commentId, 'image' => $imagePath, 'voice_note' => $voiceNotePath]);
}

respond(['success' => false, 'message' => 'Method not allowed'], 405);
