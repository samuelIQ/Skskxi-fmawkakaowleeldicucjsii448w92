<?php


function regulationSettings(mysqli $conn): array {
    static $cache = null;
    if ($cache !== null) return $cache;
    $cache = ['posts_age' => false, 'range' => 5];
    try {
        $r = mysqli_query($conn, "SELECT setting_key, setting_value FROM site_settings WHERE setting_key IN ('reg_posts_age','reg_age_range')");
        while ($r && $row = mysqli_fetch_assoc($r)) {
            if ($row['setting_key'] === 'reg_posts_age') $cache['posts_age'] = ($row['setting_value'] === '1');
            if ($row['setting_key'] === 'reg_age_range') $cache['range'] = max(1, min(30, (int)$row['setting_value']));
        }
    } catch (Throwable $e) {

    }
    return $cache;
}

function viewerBirthYear(mysqli $conn, int $viewerId): ?int {
    static $cache = [];
    if (array_key_exists($viewerId, $cache)) return $cache[$viewerId];
    $by = null;
    try {
        $stmt = mysqli_prepare($conn, "SELECT birth_year FROM users WHERE user_id = ?");
        mysqli_stmt_bind_param($stmt, 'i', $viewerId);
        mysqli_stmt_execute($stmt);
        $row = mysqli_stmt_get_result($stmt)->fetch_assoc();
        mysqli_stmt_close($stmt);
        if ($row && $row['birth_year'] !== null) $by = (int)$row['birth_year'];
    } catch (Throwable $e) {
        // column missing: treat as no age
    }
    return $cache[$viewerId] = $by;
}


function postsAgeFilter(mysqli $conn, int $viewerId, string $authorCol): string {
    if ($viewerId <= 0) return '';
    $s = regulationSettings($conn);
    if (!$s['posts_age']) return '';

    $viewerId = (int)$viewerId;
    $always = " OR $authorCol = $viewerId OR $authorCol IN (SELECT user_id FROM users WHERE is_admin = 1)";
    $by = viewerBirthYear($conn, $viewerId);

    if ($by === null) {
        return " AND ($authorCol IN (SELECT user_id FROM users WHERE birth_year IS NULL)$always)";
    }
    $lo = $by - (int)$s['range'];
    $hi = $by + (int)$s['range'];
    return " AND ($authorCol IN (SELECT user_id FROM users WHERE birth_year BETWEEN $lo AND $hi)$always)";
}


function postsAgeAllowed(mysqli $conn, int $viewerId, int $authorId): bool {
    $filter = postsAgeFilter($conn, $viewerId, 'u.user_id');
    if ($filter === '') return true;
    $stmt = mysqli_prepare($conn, "SELECT 1 FROM users u WHERE u.user_id = ?" . $filter);
    mysqli_stmt_bind_param($stmt, 'i', $authorId);
    mysqli_stmt_execute($stmt);
    $ok = (bool) mysqli_stmt_get_result($stmt)->fetch_row();
    mysqli_stmt_close($stmt);
    return $ok;
}
