<?php
/* Tells the browser who (if anyone) the SERVER thinks is logged in. */
require_once __DIR__ . '/db_connect.php';
require_method('GET');

$u = current_user();
if (!$u) {
    echo json_encode(['authenticated' => false]);
    exit;
}

echo json_encode([
    'authenticated' => true,
    'user' => ['name' => $u['name'], 'email' => $u['email'], 'role' => $u['role'], 'avatar' => $u['avatar'] ?? null],
]);
