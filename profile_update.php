<?php
require_once 'config.php';

$userId = requireAuth($conn);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(['success' => false, 'message' => 'POST required'], 405);
}

// current values
$stmt = mysqli_prepare($conn, "SELECT username, email, password, birth_year, age_changed_at, gender FROM users WHERE user_id = ?");
mysqli_stmt_bind_param($stmt, 'i', $userId);
mysqli_stmt_execute($stmt);
$current = mysqli_stmt_get_result($stmt)->fetch_assoc();
mysqli_stmt_close($stmt);
if (!$current) {
    respond(['success' => false, 'message' => 'Account not found'], 404);
}

$bio = trim($_POST['bio'] ?? '');
$fullName = trim($_POST['full_name'] ?? '');
$newUsername = trim($_POST['username'] ?? '');
$newEmail = trim($_POST['email'] ?? '');

$updates = [];
$types = '';
$params = [];

if ($bio !== '' || isset($_POST['bio'])) {
    $updates[] = 'bio = ?';
    $types .= 's';
    $params[] = $bio;
}
if ($fullName !== '') {
    $updates[] = 'full_name = ?';
    $types .= 's';
    $params[] = $fullName;
}

// ---- age (kept as a birth year) ----
// Age decides which posts someone sees, so it can't be flipped back and forth:
// the first time it is set is free, then it can be corrected, then 30 days apart.
if (isset($_POST['age']) && $_POST['age'] !== '') {
    $age = (int)$_POST['age'];
    if ($age < 13 || $age > 100) {
        respond(['success' => false, 'message' => 'Age must be between 13 and 100'], 400);
    }
    $newBirthYear = (int)date('Y') - $age;
    if ($current['birth_year'] === null || (int)$current['birth_year'] !== $newBirthYear) {
        $locked = $current['birth_year'] !== null && $current['age_changed_at'] && strtotime($current['age_changed_at']) > strtotime('-30 days');
        if ($locked) {
            respond(['success' => false, 'message' => 'You can change your age again on ' . date('M j, Y', strtotime($current['age_changed_at'] . ' +30 days'))], 403);
        }
        $updates[] = 'birth_year = ?';
        $types .= 'i';
        $params[] = $newBirthYear;
        if ($current['birth_year'] !== null) {   // a real change (not the first time it was set)
            $updates[] = 'age_changed_at = NOW()';
        }
    }
}

// ---- gender (Male or Female only) ----
if (isset($_POST['gender']) && $_POST['gender'] !== '') {
    $gender = strtolower(trim($_POST['gender']));
    if (!in_array($gender, ['male', 'female'], true)) {
        respond(['success' => false, 'message' => 'Choose Male or Female'], 400);
    }
    $updates[] = 'gender = ?';
    $types .= 's';
    $params[] = $gender;
}

// ---- username change ----
$usernameChanged = false;
if ($newUsername !== '' && $newUsername !== $current['username']) {
    if (!preg_match('/^[A-Za-z0-9_]{3,30}$/', $newUsername)) {
        respond(['success' => false, 'message' => 'Username must be 3-30 letters, numbers or underscores'], 400);
    }
    $stmt = mysqli_prepare($conn, "SELECT 1 FROM users WHERE username = ? AND user_id <> ? LIMIT 1");
    mysqli_stmt_bind_param($stmt, 'si', $newUsername, $userId);
    mysqli_stmt_execute($stmt);
    $taken = (bool) mysqli_stmt_get_result($stmt)->fetch_row();
    mysqli_stmt_close($stmt);
    if ($taken) {
        respond(['success' => false, 'message' => 'That username is already taken'], 409);
    }
    $updates[] = 'username = ?';
    $types .= 's';
    $params[] = $newUsername;
    $usernameChanged = true;
}

// ---- email change (needs the current password) ----
$emailChanged = false;
if ($newEmail !== '' && strcasecmp($newEmail, (string)$current['email']) !== 0) {
    if (!filter_var($newEmail, FILTER_VALIDATE_EMAIL) || strlen($newEmail) > 120) {
        respond(['success' => false, 'message' => 'Enter a valid email address'], 400);
    }
    $pw = (string)($_POST['current_password'] ?? '');
    if ($pw === '' || !password_verify($pw, $current['password'])) {
        respond(['success' => false, 'message' => 'Enter your current password to change your email'], 403);
    }
    $stmt = mysqli_prepare($conn, "SELECT 1 FROM users WHERE email = ? AND user_id <> ? LIMIT 1");
    mysqli_stmt_bind_param($stmt, 'si', $newEmail, $userId);
    mysqli_stmt_execute($stmt);
    $used = (bool) mysqli_stmt_get_result($stmt)->fetch_row();
    mysqli_stmt_close($stmt);
    if ($used) {
        respond(['success' => false, 'message' => 'That email is already in use'], 409);
    }
    $updates[] = 'email = ?';
    $types .= 's';
    $params[] = $newEmail;
    $emailChanged = true;
}

function saveUpload(string $field): ?string {
    if (!isset($_FILES[$field]) || $_FILES[$field]['error'] !== UPLOAD_ERR_OK) {
        return null;
    }
    $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    $ext = strtolower(pathinfo($_FILES[$field]['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed, true)) {
        respond(['success' => false, 'message' => "Invalid image type for $field"], 400);
    }
    // must really be an image, not just named like one
    if (!@getimagesize($_FILES[$field]['tmp_name'])) {
        respond(['success' => false, 'message' => "That file is not a valid image ($field)"], 400);
    }
    $filename = bin2hex(random_bytes(8)) . '.' . $ext;
    $destDir = __DIR__ . '/../uploads/';
    if (!is_dir($destDir)) {
        mkdir($destDir, 0755, true);
    }
    if (!move_uploaded_file($_FILES[$field]['tmp_name'], $destDir . $filename)) {
        respond(['success' => false, 'message' => "Failed to upload $field"], 500);
    }
    return $filename;
}

$profileImage = saveUpload('profile_image');
if ($profileImage) {
    $updates[] = 'profile_image = ?';
    $types .= 's';
    $params[] = $profileImage;
}

$coverImage = saveUpload('cover_image');
if ($coverImage) {
    $updates[] = 'cover_image = ?';
    $types .= 's';
    $params[] = $coverImage;
}

if (empty($updates)) {
    respond(['success' => false, 'message' => 'Nothing to update'], 400);
}

$types .= 'i';
$params[] = $userId;

$sql = "UPDATE users SET " . implode(', ', $updates) . " WHERE user_id = ?";
$stmt = mysqli_prepare($conn, $sql);
mysqli_stmt_bind_param($stmt, $types, ...$params);

if (!mysqli_stmt_execute($stmt)) {
    respond(['success' => false, 'message' => 'Update failed'], 500);
}
mysqli_stmt_close($stmt);

respond([
    'success' => true,
    'profile_image' => $profileImage,
    'cover_image' => $coverImage,
    'username' => $usernameChanged ? $newUsername : $current['username'],
    'email' => $emailChanged ? $newEmail : $current['email'],
]);
