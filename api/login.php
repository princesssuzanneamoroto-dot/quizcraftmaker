<?php
require_once __DIR__ . '/db_connect.php';
require_method('POST');

$data = read_json_body();

$email         = strtolower(trim((string)($data['email'] ?? '')));
$password      = (string)($data['password'] ?? '');
$requestedRole = normalize_role($data['role'] ?? '');   // the login MODE the user picked

if ($email === '' || $password === '') {
    json_fail(400, 'Email and password are required');
}
if (!in_array($requestedRole, ['teacher', 'student'], true)) {
    json_fail(400, 'Choose Teacher or Student login first.');
}

// 1. Retrieve the account by email
$stmt = $conn->prepare('SELECT id, name, email, password_hash, role, avatar FROM users WHERE email = ? LIMIT 1');
if (!$stmt) {
    json_fail(500, 'Database query preparation failed');
}
$stmt->bind_param('s', $email);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

// 2. Verify the password hash
if (!$user || !password_verify($password, $user['password_hash'])) {
    json_fail(401, 'Invalid email or password');
}

// 3. Role comes ONLY from the database record
$actualRole = normalize_role($user['role']);
if (!in_array($actualRole, ['teacher', 'student'], true)) {
    json_fail(403, 'This account has no valid role. Contact support.');
}

// 4. Compare registered role with the selected login mode; reject on mismatch.
//    (Checked after the password so the role of an account is never revealed
//    to someone who does not know its password.)
if ($actualRole !== $requestedRole) {
    json_fail(403, 'This account is registered as a ' . ucfirst($actualRole)
        . '. Use the ' . ucfirst($actualRole) . ' login.');
}

// 5. Only now create the session (fresh ID, previous account state wiped)
start_login_session((int)$user['id']);

if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
    $newHash = password_hash($password, PASSWORD_DEFAULT);
    $up = $conn->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
    if ($up) {
        $id = (int)$user['id'];
        $up->bind_param('si', $newHash, $id);
        $up->execute();
        $up->close();
    }
}

// 6. Respond with the verified database role (the front end routes from this)
echo json_encode([
    'success' => true,
    'name'    => $user['name'],
    'email'   => $user['email'],
    'role'    => $actualRole,
    'avatar'  => $user['avatar'] ?? null,
]);
$conn->close();
