<?php
require_once 'config.php';

$userId = requireAuth($conn);

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $stmt = mysqli_prepare($conn, "SELECT s.story_id, s.user_id, s.type, s.image, s.text_content, s.background_color,
        s.video_trim_start, s.video_trim_end, s.created_at, s.expires_at,
        u.username, u.full_name, u.profile_image,
        EXISTS(
            SELECT 1 FROM story_views
            WHERE story_id = s.story_id AND viewer_id = ?
        ) AS viewed
        FROM stories s
        JOIN users u ON u.user_id = s.user_id
        WHERE s.expires_at > NOW()
        ORDER BY s.created_at DESC");

    mysqli_stmt_bind_param($stmt, 'i', $userId);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    $grouped = [];

    while ($row = mysqli_fetch_assoc($result)) {
        $row['viewed'] = (bool)$row['viewed'];
        $uid = $row['user_id'];

        if (!isset($grouped[$uid])) {
            $grouped[$uid] = [
                'user_id' => $uid,
                'username' => $row['username'],
                'full_name' => $row['full_name'],
                'profile_image' => $row['profile_image'],
                'has_unviewed' => false,
                'stories' => [],
            ];
        }

        if (!$row['viewed']) {
            $grouped[$uid]['has_unviewed'] = true;
        }

        $grouped[$uid]['stories'][] = [
            'story_id' => $row['story_id'],
            'type' => $row['type'], // 'image' | 'video' | 'text'
            'image' => $row['image'], // uploads/stories/xxx.ext - image OR video file, null for text stories
            'text_content' => $row['text_content'],
            'background_color' => $row['background_color'],
            'video_trim_start' => $row['video_trim_start'] !== null ? (float)$row['video_trim_start'] : null,
            'video_trim_end' => $row['video_trim_end'] !== null ? (float)$row['video_trim_end'] : null,
            'created_at' => $row['created_at'],
            'viewed' => $row['viewed'],
        ];
    }

    mysqli_stmt_close($stmt);

    respond([
        'success' => true,
        'stories' => array_values($grouped)
    ]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $isMultipart = str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'multipart/form-data');

    // Mark story as viewed
    if (!$isMultipart) {
        $data = input();

        if (($data['action'] ?? '') === 'view') {
            $storyId = (int)($data['story_id'] ?? 0);

            if (!$storyId) {
                respond(['success' => false, 'message' => 'story_id is required'], 400);
            }

            $stmt = mysqli_prepare($conn, "INSERT IGNORE INTO story_views (story_id, viewer_id) VALUES (?, ?)");
            mysqli_stmt_bind_param($stmt, 'ii', $storyId, $userId);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);

            respond(['success' => true]);
        }

        respond(['success' => false, 'message' => 'Unknown action'], 400);
    }

    // Upload new story - type-driven (image | video | text)
    $type = $_POST['type'] ?? 'image';
    if (!in_array($type, ['image', 'video', 'text'], true)) $type = 'image';

    // Filename convention: {username}_nothyps_story_{ddMMyyyyHHmm}
    // e.g. Samuel_nothyps_story_260720261804
    $stmt = mysqli_prepare($conn, "SELECT username FROM users WHERE user_id = ?");
    mysqli_stmt_bind_param($stmt, 'i', $userId);
    mysqli_stmt_execute($stmt);
    $username = mysqli_stmt_get_result($stmt)->fetch_assoc()['username'] ?? 'user' . $userId;
    mysqli_stmt_close($stmt);
    $safeUsername = preg_replace('/[^A-Za-z0-9_-]/', '', $username);
    $baseName = $safeUsername . '_nothyps_story_' . date('dmYHi');

    $dbPath = null;
    $textContent = null;
    $backgroundColor = null;
    $trimStart = null;
    $trimEnd = null;

    if ($type === 'text') {
        $textContent = trim($_POST['text_content'] ?? '');
        $backgroundColor = trim($_POST['background_color'] ?? '#000000');
        if ($textContent === '') {
            respond(['success' => false, 'message' => 'Text content is required'], 400);
        }
    } else {
        // image or video - both arrive under the "image" file field for a single upload path
        if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
            respond(['success' => false, 'message' => 'A file is required'], 400);
        }

        $imageExts = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        $videoExts = ['mp4', 'mov', 'm4v', 'webm'];
        $ext = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
        $allowed = $type === 'video' ? $videoExts : $imageExts;

        if (!in_array($ext, $allowed, true)) {
            respond(['success' => false, 'message' => 'Invalid file type for ' . $type . ' story'], 400);
        }

        $uploadFolder = 'uploads/stories/';
        $destDir = __DIR__ . '/../' . $uploadFolder;
        if (!is_dir($destDir)) mkdir($destDir, 0755, true);

        $filename = $baseName . '.' . $ext;
        $dbPath = $uploadFolder . $filename;

        if (!move_uploaded_file($_FILES['image']['tmp_name'], $destDir . $filename)) {
            respond(['success' => false, 'message' => 'Upload failed'], 500);
        }

        if ($type === 'video') {
            $trimStart = isset($_POST['trim_start']) ? (float)$_POST['trim_start'] : 0;
            $trimEnd = isset($_POST['trim_end']) ? (float)$_POST['trim_end'] : null;
        }
    }

    $stmt = mysqli_prepare($conn, "INSERT INTO stories
        (user_id, type, image, text_content, background_color, video_trim_start, video_trim_end, expires_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 24 HOUR))");
    mysqli_stmt_bind_param($stmt, 'issssdd', $userId, $type, $dbPath, $textContent, $backgroundColor, $trimStart, $trimEnd);

    if (!mysqli_stmt_execute($stmt)) {
        respond(['success' => false, 'message' => 'Failed to create story'], 500);
    }

    $storyId = mysqli_insert_id($conn);
    mysqli_stmt_close($stmt);

    respond([
        'success' => true,
        'story_id' => $storyId,
        'type' => $type,
        'image' => $dbPath,
    ]);
}

respond(['success' => false, 'message' => 'Method not allowed'], 405);
