<?php
/* Join a class using the code shared by its teacher. */
require_once __DIR__ . '/db_connect.php';

$me = require_login();
$uid = (int)$me['id'];
require_method('POST');
if (stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== 0) {
    json_fail(415, 'Unsupported request type.');
}
$d = read_json_body();
if (($d['action'] ?? '') !== 'join') json_fail(400, 'Unknown action.');
$code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)($d['code'] ?? '')));
if ($code === '' || strlen($code) > 16) json_fail(400, 'Enter a valid class code.');

$st = $conn->prepare('SELECT client_id, owner_id, data FROM classes WHERE code = ? LIMIT 1');
$st->bind_param('s', $code);
$st->execute();
$row = $st->get_result()->fetch_assoc();
$st->close();
if (!$row) json_fail(404, 'That class code is not valid. Check it with your teacher.');

$cid = $row['client_id'];
$st = $conn->prepare('SELECT 1 FROM class_members WHERE class_client_id = ? AND user_id = ? LIMIT 1');
$st->bind_param('si', $cid, $uid);
$st->execute();
$already = $st->get_result()->fetch_row();
$st->close();
if (!$already) {
    $st = $conn->prepare('INSERT INTO class_members (class_client_id, user_id, name) VALUES (?, ?, ?)');
    $name = substr((string)$me['name'], 0, 150);
    $st->bind_param('sis', $cid, $uid, $name);
    if (!$st->execute()) { $st->close(); json_fail(500, 'Could not join that class.'); }
    $st->close();
}

$class = json_decode($row['data'], true);
if (!is_array($class)) $class = [];
$class['id'] = $cid;
$members = [];
$st = $conn->prepare('SELECT m.class_client_id, u.email, m.name FROM class_members m
                      JOIN users u ON u.id = m.user_id WHERE m.class_client_id = ?');
$st->bind_param('s', $cid);
$st->execute();
$r = $st->get_result();
while ($m = $r->fetch_assoc()) {
    $members[] = ['email' => $m['email'], 'name' => $m['name']];
}
$st->close();
$class['members'] = $members;

echo json_encode(['success' => true, 'class' => $class]);
