<?php
// Active ads for the app. Works for guests and members.
//   guests  -> home_banner, welcome_post, crib_banner
//   members -> crib_banner, member_post
// An ad is "active" only when: approved + slot enabled + started + not expired.
require_once 'config.php';
require_once 'guest_auth.php';

$userId = optionalAuth($conn);
$audience = $userId > 0 ? 'members' : 'guests';

header('Cache-Control: private, max-age=120');

// Shared 60-second cache so the database is hit at most once a minute per audience.
$cacheFile = sys_get_temp_dir() . "/nothyp_promo_$audience.json";
if (is_file($cacheFile) && (time() - filemtime($cacheFile)) < 60) {
    echo file_get_contents($cacheFile);
    exit;
}

$stmt = mysqli_prepare($conn,
    "SELECT a.id, a.slot_key, a.advertiser_name, a.headline, a.body, a.image, a.logo,
            a.cta_text, a.cta_url, UNIX_TIMESTAMP(a.expires_at) AS expires_ts
     FROM promo_ads a
     JOIN promo_slots s ON s.slot_key = a.slot_key
     WHERE a.status = 'approved' AND s.is_enabled = 1
       AND (a.starts_at IS NULL OR a.starts_at <= NOW())
       AND a.expires_at > NOW()
       AND (s.audience = 'everyone' OR s.audience = ?)
     ORDER BY a.id ASC");
mysqli_stmt_bind_param($stmt, 's', $audience);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);

$bySlot = [];
while ($row = mysqli_fetch_assoc($result)) {
    $slot = $row['slot_key'];
    if (count($bySlot[$slot] ?? []) >= 5) continue;   // max 5 per space
    $row['id'] = (int)$row['id'];
    // make sure every ad link is a full web address
    $url = trim($row['cta_url']);
    if (!preg_match('~^https?://~i', $url)) $url = 'https://' . ltrim($url, '/');
    $row['cta_url'] = $url;
    $row['expires_ts'] = (int)$row['expires_ts'];
    $bySlot[$slot][] = $row;
}
mysqli_stmt_close($stmt);

$json = json_encode(['success' => true, 'ads' => $bySlot ?: new stdClass()]);
@file_put_contents($cacheFile, $json, LOCK_EX);
echo $json;
