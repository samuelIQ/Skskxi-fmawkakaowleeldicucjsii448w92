<?php
require_once 'config.php';

// Public on purpose: guests can browse Crib without an account.

function fetchAll(mysqli $conn, string $sql): array {
    $res = mysqli_query($conn, $sql);
    return $res ? mysqli_fetch_all($res, MYSQLI_ASSOC) : [];
}

$cols = "id, name, icon, url, users_count";

// Top 5 trending, in the order you set
$trending = fetchAll($conn, "SELECT $cols FROM games
    WHERE is_active = 1 AND is_trending = 1
    ORDER BY trend_order ASC, id ASC LIMIT 5");

$trendingIds = array_map(fn($g) => (int)$g['id'], $trending);
$exclude = $trendingIds ? 'AND id NOT IN (' . implode(',', $trendingIds) . ')' : '';

// Everything else
$games = fetchAll($conn, "SELECT $cols FROM games
    WHERE is_active = 1 $exclude
    ORDER BY sort_order ASC, id DESC");

$streams = fetchAll($conn, "SELECT id, name, image AS icon, url FROM streams
    WHERE is_active = 1 ORDER BY sort_order ASC, id ASC");

header('Cache-Control: public, max-age=300'); // phones/CDN can reuse for 5 min
respond(['success' => true, 'trending' => $trending, 'games' => $games, 'streams' => $streams]);
