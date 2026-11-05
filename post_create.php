<?php
require_once 'config.php';
require_once 'trending_helper.php';

$userId = requireAuth($conn);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(['success' => false, 'message' => 'POST required'], 405);
}

$content = trim($_POST['content'] ?? '');
$category = trim($_POST['category'] ?? 'Career');

if ($content === '') {
    respond(['success' => false, 'message' => 'Content cannot be empty'], 400);
}

$imagePath = null;
if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
    $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    $ext = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed, true)) {
        respond(['success' => false, 'message' => 'Invalid image type'], 400);
    }
    $filename = bin2hex(random_bytes(8)) . '.' . $ext;
    $destDir = __DIR__ . '/../uploads/';
    if (!is_dir($destDir)) {
        mkdir($destDir, 0755, true);
    }
    if (!move_uploaded_file($_FILES['image']['tmp_name'], $destDir . $filename)) {
        respond(['success' => false, 'message' => 'Image upload failed'], 500);
    }
    $imagePath = $filename;
}

$stmt = mysqli_prepare($conn, "INSERT INTO posts (user_id, content, image, category) VALUES (?, ?, ?, ?)");
mysqli_stmt_bind_param($stmt, 'isss', $userId, $content, $imagePath, $category);

if (!mysqli_stmt_execute($stmt)) {
    respond(['success' => false, 'message' => 'Failed to create post'], 500);
}

$postId = mysqli_insert_id($conn);
mysqli_stmt_close($stmt);

updateTrendingFromPost($conn, $content);

respond(['success' => true, 'post_id' => $postId, 'image' => $imagePath]);
