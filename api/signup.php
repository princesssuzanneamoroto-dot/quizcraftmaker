<?php
require_once __DIR__ . '/db_connect.php';
require_method('POST');

$data = read_json_body();

$name     = trim((string)($data['name'] ?? ''));
$email    = strtolower(trim((string)($data['email'] ?? '')));
$password = (string)($data['password'] ?? '');
$role     = normalize_role($data['role'] ?? '');

$nameLen = function_exists('mb_strlen') ? mb_strlen($name, 'UTF-8') : strlen($name);
if ($name === '' || $nameLen > 60 || $email === '' || strlen($email) > 100 || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 8
    || !in_array($role, ['teacher', 'student'], true)) {
    json_fail(400, 'Invalid input data. Username must be 1 to 60 characters, password at least 8 characters, and a valid role selected.');
}

// Check if email already exists
$stmt = $conn->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
if (!$stmt) {
    json_fail(500, 'Database query preparation failed');
}
$stmt->bind_param('s', $email);
$stmt->execute();
$stmt->store_result();
if ($stmt->num_rows > 0) {
    $stmt->close();
    json_fail(409, 'Email already exists');
}
$stmt->close();

$stmt = $conn->prepare('SELECT id FROM users WHERE name = ? LIMIT 1');
if (!$stmt) {
    json_fail(500, 'Database query preparation failed');
}
$stmt->bind_param('s', $name);
$stmt->execute();
$stmt->store_result();
if ($stmt->num_rows > 0) {
    $stmt->close();
    json_fail(409, 'Username already taken');
}
$stmt->close();

$hash = password_hash($password, PASSWORD_DEFAULT);
$stmt = $conn->prepare('INSERT INTO users (name, email, password_hash, role) VALUES (?, ?, ?, ?)');
if (!$stmt) {
    json_fail(500, 'Database statement preparation failed');
}

$stmt->bind_param('ssss', $name, $email, $hash, $role);
if ($stmt->execute()) {
    $newId = (int)$conn->insert_id;
    $stmt->close();
    // New account = new session (wipes any previous account's state)
    start_login_session($newId);
    echo json_encode([
        'success' => true,
        'name'    => $name,
        'email'   => $email,
        'role'    => $role,
        'avatar'  => null,
    ]);
} else {
    // 1062 = duplicate key (race between the check above and the insert)
    $code = $stmt->errno;
    $stmt->close();
    if ($code === 1062) {
        json_fail(409, 'Email already exists');
    }
    json_fail(500, 'Failed to create account in database');
}
$conn->close();
