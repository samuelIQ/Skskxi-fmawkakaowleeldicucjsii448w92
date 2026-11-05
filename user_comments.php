<?php
// mobile-api/user_comments.php
//
// GET a user's comments joined with the post each comment was made on.
// This TEST version does NOT require authentication.
//
// Params:
//   user_id        (required) target user's ID
//   limit          (optional, default 30, max 50)
//   cursor_id      (optional) comment_id boundary
//   cursor_dir     (optional)
//                  older = comments older than cursor_id (default)
//                  newer = comments newer than cursor_id
//
// Example:
//   user_comments.php?user_id=1
//   user_comments.php?user_id=1&limit=30
//   user_comments.php?user_id=1&cursor_id=50&cursor_dir=older
//

require_once '../includes/config.php';

header('Content-Type: application/json; charset=utf-8');


// ---------------------------------------------------------
// Method check
// ---------------------------------------------------------

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    respond([
        'success' => false,
        'message' => 'Method not allowed'
    ], 405);
}


// ---------------------------------------------------------
// Parameters
// ---------------------------------------------------------

$targetUserId = (int)($_GET['user_id'] ?? 0);

$limit = min(
    50,
    max(1, (int)($_GET['limit'] ?? 30))
);

if (!$targetUserId) {
    respond([
        'success' => false,
        'message' => 'user_id is required'
    ], 400);
}


// ---------------------------------------------------------
// IMPORTANT:
// No authentication.
// No requireAuth().
// No isLoggedIn().
// No session requirement.
//
// For testing, use targetUserId as viewerId as well.
// This allows the API to calculate the "my_like" fields.
// ---------------------------------------------------------

$viewerId = $targetUserId;


// ---------------------------------------------------------
// Cursor parameters
// ---------------------------------------------------------

$cursorId = (
    isset($_GET['cursor_id']) &&
    $_GET['cursor_id'] !== ''
)
    ? (int)$_GET['cursor_id']
    : null;

$cursorDir = (
    ($_GET['cursor_dir'] ?? 'older') === 'newer'
)
    ? 'newer'
    : 'older';


// ---------------------------------------------------------
// Fetch comments
// ---------------------------------------------------------

function fetchUserComments(
    mysqli $conn,
    int $targetUserId,
    int $viewerId,
    int $limit,
    ?int $cursorId,
    string $cursorOp,
    string $order
): array {

    $where = "c.user_id = ?";


    // -----------------------------------------------------
    // SQL parameter order:
    //
    // 1. comment like viewer
    // 2. comment dislike viewer
    // 3. post like viewer
    // 4. post dislike viewer
    // 5. target user
    // 6. cursor, if supplied
    // 7. limit
    // -----------------------------------------------------

    $types = 'iiii';

    $params = [
        $viewerId,
        $viewerId,
        $viewerId,
        $viewerId
    ];


    // Target user

    $types .= 'i';
    $params[] = $targetUserId;


    // Cursor

    if ($cursorId !== null) {

        if ($cursorOp === '>') {
            $where .= " AND c.comment_id > ?";
        } else {
            $where .= " AND c.comment_id < ?";
        }

        $types .= 'i';
        $params[] = $cursorId;
    }


    // Limit

    $types .= 'i';
    $params[] = $limit;


    // -----------------------------------------------------
    // SQL
    // -----------------------------------------------------

    $sql = "
        SELECT

            c.comment_id,
            c.content AS comment_content,
            c.image AS comment_image,
            c.voice_note AS comment_voice_note,
            c.parent_comment_id,
            c.created_at AS comment_created_at,

            (
                SELECT COUNT(*)
                FROM comment_likes
                WHERE comment_id = c.comment_id
            ) AS comment_likes_count,

            (
                SELECT COUNT(*)
                FROM comment_dislikes
                WHERE comment_id = c.comment_id
            ) AS comment_dislikes_count,

            EXISTS(
                SELECT 1
                FROM comment_likes
                WHERE comment_id = c.comment_id
                AND user_id = ?
            ) AS comment_my_like,

            EXISTS(
                SELECT 1
                FROM comment_dislikes
                WHERE comment_id = c.comment_id
                AND user_id = ?
            ) AS comment_my_dislike,


            p.post_id,
            p.content AS post_content,
            p.image AS post_image,
            p.voice_note AS post_voice_note,
            p.created_at AS post_created_at,


            pu.user_id AS post_user_id,
            pu.username AS post_username,
            pu.full_name AS post_full_name,
            pu.profile_image AS post_profile_image,
            pu.is_pro AS post_is_pro,


            (
                SELECT COUNT(*)
                FROM likes
                WHERE post_id = p.post_id
            ) AS post_likes_count,

            (
                SELECT COUNT(*)
                FROM dislikes
                WHERE post_id = p.post_id
            ) AS post_dislikes_count,


            EXISTS(
                SELECT 1
                FROM likes
                WHERE post_id = p.post_id
                AND user_id = ?
            ) AS post_my_like,

            EXISTS(
                SELECT 1
                FROM dislikes
                WHERE post_id = p.post_id
                AND user_id = ?
            ) AS post_my_dislike,


            (
                SELECT COUNT(*)
                FROM comments
                WHERE post_id = p.post_id
            ) AS post_comments_count


        FROM comments c


        JOIN posts p
            ON p.post_id = c.post_id


        JOIN users pu
            ON pu.user_id = p.user_id


        WHERE $where


        ORDER BY c.comment_id $order


        LIMIT ?
    ";


    // -----------------------------------------------------
    // Prepare
    // -----------------------------------------------------

    $stmt = mysqli_prepare($conn, $sql);

    if (!$stmt) {
        respond([
            'success' => false,
            'message' => 'DB error (prepare)',
            'error' => mysqli_error($conn)
        ], 500);
    }


    // -----------------------------------------------------
    // Bind parameters
    // -----------------------------------------------------

    if (!mysqli_stmt_bind_param(
        $stmt,
        $types,
        ...$params
    )) {

        respond([
            'success' => false,
            'message' => 'DB error (bind)',
            'error' => mysqli_stmt_error($stmt)
        ], 500);
    }


    // -----------------------------------------------------
    // Execute
    // -----------------------------------------------------

    if (!mysqli_stmt_execute($stmt)) {

        respond([
            'success' => false,
            'message' => 'DB error (execute)',
            'error' => mysqli_stmt_error($stmt)
        ], 500);
    }


    // -----------------------------------------------------
    // Get result
    // -----------------------------------------------------

    $result = mysqli_stmt_get_result($stmt);

    if ($result === false) {

        respond([
            'success' => false,
            'message' => 'DB error (get_result)',
            'error' => mysqli_stmt_error($stmt)
        ], 500);
    }


    // -----------------------------------------------------
    // Build response rows
    // -----------------------------------------------------

    $rows = [];

    while ($row = mysqli_fetch_assoc($result)) {

        $row['comment_my_like'] =
            (bool)$row['comment_my_like'];

        $row['comment_my_dislike'] =
            (bool)$row['comment_my_dislike'];

        $row['post_my_like'] =
            (bool)$row['post_my_like'];

        $row['post_my_dislike'] =
            (bool)$row['post_my_dislike'];

        $rows[] = $row;
    }


    mysqli_stmt_close($stmt);


    return $rows;
}


// ---------------------------------------------------------
// Fetch requested direction
// ---------------------------------------------------------

if ($cursorDir === 'newer') {

    $rows = fetchUserComments(
        $conn,
        $targetUserId,
        $viewerId,
        $limit,
        $cursorId,
        '>',
        'ASC'
    );

    // Convert to newest-first order.
    $rows = array_reverse($rows);

} else {

    $rows = fetchUserComments(
        $conn,
        $targetUserId,
        $viewerId,
        $limit,
        $cursorId,
        '<',
        'DESC'
    );
}


// ---------------------------------------------------------
// Final JSON response
// ---------------------------------------------------------

respond([
    'success' => true,
    'comments' => $rows,
    'has_more' => count($rows) === $limit
]);