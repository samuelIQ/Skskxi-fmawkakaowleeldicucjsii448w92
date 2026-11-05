<?php
// mobile-api/trending_helper.php - include this, don't call directly.
// Ported from the website's plug/trending.php, using prepared statements
// instead of string-escaped queries, but the exact same stopword/phrase logic.

function calculateTrend(mysqli $conn, string $term): void {
    if ($term === '') {
        return;
    }
    $stmt = mysqli_prepare($conn, "SELECT id FROM trends WHERE title = ? LIMIT 1");
    mysqli_stmt_bind_param($stmt, 's', $term);
    mysqli_stmt_execute($stmt);
    $existing = mysqli_stmt_get_result($stmt)->fetch_assoc();
    mysqli_stmt_close($stmt);

    if ($existing) {
        $stmt = mysqli_prepare($conn, "UPDATE trends SET hits = hits + 1 WHERE id = ?");
        mysqli_stmt_bind_param($stmt, 'i', $existing['id']);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
    } else {
        $stmt = mysqli_prepare($conn, "INSERT INTO trends (title, hits, created_at) VALUES (?, 1, NOW())");
        mysqli_stmt_bind_param($stmt, 's', $term);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
    }
}

function updateTrendingFromPost(mysqli $conn, string $content): void {
    $stopWords = "1 2 3 4 5 6 7 8 9 0 a about above across after again against all almost alone along
        already also although always among am an and another any anybody anyone anything anywhere
        are area areas around as ask asked asking asks at away back backed backing backs be became
        because become becomes been before began behind being beings best better between big both
        but by came can cannot case cases certain certainly clear clearly come could d did differ
        different differently do does done down during e each early either end ended ending ends
        enough entry exit even evenly ever every everybody everyone everything everywhere f face faces fact
        facts far felt few find finds first for four from full fully further furthered furthering
        furthers g gave general generally get gets give given gives go going good goods got great
        greater greatest group grouped grouping groups h had has have having he her here herself
        high him himself his how however i if im in interest interested interesting interests into
        is it its itself just k keep keeps kind knew know known knows large largely last later
        latest long least less let lets like likely long Longentry longer longest lots lot m made make making man many may
        me member members men might more most mostly mr mrs much must my myself n necessary need
        needed needing needs never new newer newest next no nobody non noone not nothing now
        nowhere number numbers o of off often old older oldest on once one only open opened
        opening opens or order ordered ordering orders other others our out over p part parted
        parting parts per perhaps place places point pointed pointing points possible present
        presented presenting presents problem problems put puts q quite r rather really right room
        rooms s said same saw say says second seconds see seem seemed seeming seems sees several
        shall short she should show showed showing shows side sides since small smaller smallest so some
        somebody someone something somewhere state states still strategy such sure t take taken than that
        the their them then there therefore these they thing think thinks this those though thought
        thoughts three through thus to today together too took toward turn turned turning turns two
        u under until up upon us use used uses v very w want wanted wanting wants was way ways we
        well wells went were what when where whether which while who whole whose why will with
        within without work worked working works would x y year years yet you young younger youngest
        your yours z lol haha omg hey ill iframe wonder else hate sleepy reason little yes bye
        choose br post posts trading trader forex crypto bitcoin ethereum trade account funded
        challenge prop firm funding payout withdrawal
        im ive dont doesnt didnt cant couldnt wont wouldnt shouldnt isnt arent wasnt werent
        thats theres heres whats whos lets youre theyre wereve youve
        pls please thanks thank you okay ok yeah yup nah bro guys guy people person
        today tomorrow yesterday morning afternoon night days week weeks month months
        time times thing things stuff post posted posting comment comments
        link links image images video videos photo photos
        na buy sell buying selling market price prices chart charts candle candles
        tp sl entry entries profit loss risk reward pips lot lots Shortentry 
        Exit lots Lotsstrategy Shortentryexit tps
        ";

    $stopWords = preg_split("/[\s,]+/", $stopWords);
    $stopWords = array_filter($stopWords);
    $stopWordsLower = array_map('strtolower', $stopWords);

    $no_punctuation = preg_replace("/[^a-zA-Z ]+/", "", $content);
    $no_punctuation = trim(preg_replace("/\s+/", " ", $no_punctuation));

    if (strpos($no_punctuation, "height") !== false ||
        strpos($no_punctuation, "width") !== false ||
        strpos($no_punctuation, "http") !== false ||
        strpos($no_punctuation, "youtube") !== false ||
        strpos($no_punctuation, "iframe") !== false) {
        return; // skip trending for media/embed posts
    }

    $keywords = preg_split("/[\s,]+/", $no_punctuation);
    $keywords = array_filter($keywords);
    $keywords = array_values($keywords);

    $phrases = [];
    foreach ($keywords as $i => $keyword) {
        if (strlen($keyword) >= 3) {
            $phrases[] = $keyword;
        }
        if (isset($keywords[$i + 1]) && strlen($keyword . ' ' . $keywords[$i + 1]) >= 6) {
            $phrases[] = $keyword . ' ' . $keywords[$i + 1];
        }
    }

    foreach ($phrases as $phrase) {
        $phrase_lower = strtolower(trim($phrase));
        if ($phrase_lower === '' || in_array($phrase_lower, $stopWordsLower, true)) {
            continue;
        }
        calculateTrend($conn, ucfirst($phrase_lower));
    }
}

function getTrendingTitles(mysqli $conn, int $limit = 14): array {
    $limit = max(1, min(50, $limit));
    $result = mysqli_query($conn, "SELECT title, hits FROM trends 
    WHERE CHAR_LENGTH(title) > 6
    ORDER BY hits DESC LIMIT $limit");
    $trends = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $trends[] = $row;
    }
    return $trends;
}

