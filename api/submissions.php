<?php
/*
 * Quiz submissions. Students submit once; teachers of the owning quiz may
 * grade essay/manual items and remove submissions when a quiz is deleted.
 */
require_once __DIR__ . '/db_connect.php';

$me = require_login();
$uid = (int)$me['id'];
require_method('POST');
if (stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== 0) {
    json_fail(415, 'Unsupported request type.');
}
$d = read_json_body();

function sub_id($v): ?string {
    $s = (string)$v;
    return preg_match('/^[A-Za-z0-9_-]{1,64}$/', $s) ? $s : null;
}

$action = (string)($d['action'] ?? '');

if ($action === 'submit') {
    if ($me['role'] !== 'student') json_fail(403, 'Only students can submit quizzes.');
    $r = $d['result'] ?? null;
    if (!is_array($r)) json_fail(400, 'Invalid submission.');
    $rid  = sub_id($r['id'] ?? null);
    $qid  = sub_id($r['quizId'] ?? null);
    if (!$rid || !$qid) json_fail(400, 'Invalid submission.');

    $st = $conn->prepare('SELECT q.published, q.class_client_id FROM quizzes q WHERE q.client_id = ? LIMIT 1');
    if (!$st) json_fail(500, 'Database query preparation failed');
    $st->bind_param('s', $qid);
    $st->execute();
    $quiz = $st->get_result()->fetch_assoc();
    $st->close();
    if (!$quiz || !(int)$quiz['published']) json_fail(404, 'That quiz is not available.');

    if ($quiz['class_client_id'] !== null) {
        $st = $conn->prepare('SELECT 1 FROM class_members WHERE class_client_id = ? AND user_id = ? LIMIT 1');
        $st->bind_param('si', $quiz['class_client_id'], $uid);
        $st->execute();
        $joined = $st->get_result()->fetch_row();
        $st->close();
        if (!$joined) json_fail(403, 'You are not in the class this quiz belongs to.');
    }

    $payload = json_encode($r, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $st = $conn->prepare('INSERT IGNORE INTO submissions (client_id, quiz_client_id, student_id, data) VALUES (?, ?, ?, ?)');
    if (!$st) json_fail(500, 'Database query preparation failed');
    $st->bind_param('ssis', $rid, $qid, $uid, $payload);
    if (!$st->execute()) { $st->close(); json_fail(500, 'Could not save your submission.'); }
    $st->close();
    echo json_encode(['success' => true]);
    exit;
}

if ($action === 'grade') {
    $sid = sub_id($d['id'] ?? null);
    $man = $d['man'] ?? null;
    if (!$sid || !is_array($man)) json_fail(400, 'Invalid grading request.');

    $st = $conn->prepare('SELECT s.data, q.owner_id FROM submissions s
                          JOIN quizzes q ON q.client_id = s.quiz_client_id
                          WHERE s.client_id = ? LIMIT 1');
    if (!$st) json_fail(500, 'Database query preparation failed');
    $st->bind_param('s', $sid);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    if (!$row || (int)$row['owner_id'] !== $uid) json_fail(403, 'You cannot grade this submission.');

    $r = json_decode($row['data'], true);
    if (!is_array($r)) json_fail(500, 'Stored submission is invalid.');
    $r['man'] = $man;
    $payload = json_encode($r, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $st = $conn->prepare('UPDATE submissions SET data = ? WHERE client_id = ?');
    $st->bind_param('ss', $payload, $sid);
    if (!$st->execute()) { $st->close(); json_fail(500, 'Could not save grades.'); }
    $st->close();
    echo json_encode(['success' => true]);
    exit;
}

if ($action === 'delete_for_quiz') {
    $qid = sub_id($d['quizId'] ?? null);
    if (!$qid) json_fail(400, 'Invalid quiz id.');
    $st = $conn->prepare('SELECT 1 FROM quizzes WHERE client_id = ? AND owner_id = ? LIMIT 1');
    $st->bind_param('si', $qid, $uid);
    $st->execute();
    $own = $st->get_result()->fetch_row();
    $st->close();
    if (!$own) json_fail(403, 'You cannot change submissions for this quiz.');
    $st = $conn->prepare('DELETE FROM submissions WHERE quiz_client_id = ?');
    $st->bind_param('s', $qid);
    $st->execute();
    $st->close();
    echo json_encode(['success' => true]);
    exit;
}

json_fail(400, 'Unknown action.');
