<?php
// mobile-api/chat_stickers.php
// GET -> the sticker catalog for the sticker-picker screen, grouped by
// pack. Seed chat_stickers with your own images uploaded to
// /uploads/chat/stickers/.
require_once 'config.php';

requireAuth($conn);

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    respond(['success' => false, 'message' => 'Method not allowed'], 405);
}

$stmt = mysqli_prepare($conn, "SELECT sticker_id, pack_name, image FROM chat_stickers ORDER BY pack_name ASC, sort_order ASC, sticker_id ASC");
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

$stickers = [];
while ($row = mysqli_fetch_assoc($result)) {
    $stickers[] = $row;
}
mysqli_stmt_close($stmt);

respond(['success' => true, 'stickers' => $stickers]);
