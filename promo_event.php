<?php
// Counts an ad view or click. Guests allowed.
require_once 'config.php';
require_once 'guest_auth.php';

optionalAuth($conn);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(['success' => false, 'message' => 'POST required'], 405);
}
$data = input();
$adId = (int)($data['ad_id'] ?? 0);
$type = $data['type'] ?? '';
if (!$adId || !in_array($type, ['view', 'click'], true)) {
    respond(['success' => false, 'message' => 'ad_id and type (view|click) required'], 400);
}

$col = $type === 'click' ? 'clicks' : 'impressions';   // fixed whitelist
$stmt = mysqli_prepare($conn, "UPDATE promo_ads SET $col = $col + 1 WHERE id = ? AND status = 'approved'");
mysqli_stmt_bind_param($stmt, 'i', $adId);
mysqli_stmt_execute($stmt);
mysqli_stmt_close($stmt);

respond(['success' => true]);
