<?php
/*
 * Classroom data API.
 *
 * GET  data.php              -> everything the signed-in user is allowed to see
 * POST data.php {action:"sync", state:{...}} -> persist the user's own records
 *
 * The browser keeps a synchronous copy of this data for its UI; this endpoint
 * is the durable, server-side source of truth. Ownership is always derived
 * from the PHP session, never from fields in the request.
 */
require_once __DIR__ . '/db_connect.php';

$me    = require_login();
$uid   = (int)$me['id'];
$email = strtolower(trim($me['email']));

function data_clean_id($v): ?string {
    $s = (string)$v;
    return preg_match('/^[A-Za-z0-9_-]{1,64}$/', $s) ? $s : null;
}

function data_json($v): string {
    return json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function data_decode($v): array {
    $r = json_decode((string)$v, true);
    return is_array($r) ? $r : [];
}

/* ---------- GET: assemble the user's view ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $members = [];
    $st = $conn->prepare('SELECT m.class_client_id, u.email, m.name
                          FROM class_members m JOIN users u ON u.id = m.user_id');
    if ($st) {
        $st->execute();
        $mr = $st->get_result();
        while ($row = $mr->fetch_assoc()) {
            $members[$row['class_client_id']][] = [
                'email' => $row['email'],
                'name'  => $row['name'],
            ];
        }
        $st->close();
    }

    $state = ['classes' => [], 'quizzes' => [], 'materials' => [], 'results' => [], 'practice' => [], 'att' => []];

    $st = $conn->prepare('SELECT client_id, data FROM classes
                          WHERE owner_id = ? OR client_id IN
                                (SELECT class_client_id FROM class_members WHERE user_id = ?)
                          ORDER BY created_at');
    if ($st) {
        $st->bind_param('ii', $uid, $uid);
        $st->execute();
        $r = $st->get_result();
        while ($row = $r->fetch_assoc()) {
            $c = data_decode($row['data']);
            $c['id'] = $row['client_id'];
            $c['members'] = $members[$row['client_id']] ?? [];
            $state['classes'][] = $c;
        }
        $st->close();
    }

    $st = $conn->prepare('SELECT client_id, data FROM quizzes
                          WHERE owner_id = ? OR (published = 1 AND class_client_id IN
                                (SELECT class_client_id FROM class_members WHERE user_id = ?))
                          ORDER BY created_at');
    if ($st) {
        $st->bind_param('ii', $uid, $uid);
        $st->execute();
        $r = $st->get_result();
        while ($row = $r->fetch_assoc()) {
            $z = data_decode($row['data']);
            $z['id'] = $row['client_id'];
            $state['quizzes'][] = $z;
        }
        $st->close();
    }

    $st = $conn->prepare('SELECT client_id, data FROM materials
                          WHERE owner_id = ? OR class_client_id IN
                                (SELECT class_client_id FROM class_members WHERE user_id = ?)
                          ORDER BY created_at');
    if ($st) {
        $st->bind_param('ii', $uid, $uid);
        $st->execute();
        $r = $st->get_result();
        while ($row = $r->fetch_assoc()) {
            $m = data_decode($row['data']);
            $m['id'] = $row['client_id'];
            $state['materials'][] = $m;
        }
        $st->close();
    }

    if ($me['role'] === 'teacher') {
        $st = $conn->prepare('SELECT s.client_id, s.data FROM submissions s
                              JOIN quizzes q ON q.client_id = s.quiz_client_id
                              WHERE q.owner_id = ? ORDER BY s.created_at');
    } else {
        $st = $conn->prepare('SELECT client_id, data FROM submissions
                              WHERE student_id = ? ORDER BY created_at');
    }
    if ($st) {
        $st->bind_param('i', $uid);
        $st->execute();
        $r = $st->get_result();
        while ($row = $r->fetch_assoc()) {
            $res = data_decode($row['data']);
            $res['id'] = $row['client_id'];
            $state['results'][] = $res;
        }
        $st->close();
    }

    $st = $conn->prepare('SELECT practice, att FROM user_state WHERE user_id = ? LIMIT 1');
    if ($st) {
        $st->bind_param('i', $uid);
        $st->execute();
        $row = $st->get_result()->fetch_assoc();
        $st->close();
        if ($row) {
            $state['practice'] = data_decode($row['practice']);
            $state['att'] = data_decode($row['att']);
        }
    }

    echo json_encode(['success' => true, 'state' => $state]);
    exit;
}

/* ---------- POST: persist the user's own records ---------- */
require_method('POST');
if (stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== 0) {
    json_fail(415, 'Unsupported request type.');
}
$d = read_json_body();
if (($d['action'] ?? '') !== 'sync' || !is_array($d['state'] ?? null)) {
    json_fail(400, 'Invalid sync request.');
}
$s = $d['state'];
$warnings = [];

/* Upsert one collection and remove rows for records the browser no longer has.
   Only records owned by the signed-in account are ever touched. */
function data_sync_collection(mysqli $conn, string $table, array $items, int $uid, string $email, array $columns, array &$warnings): void {
    $allowed = ['code' => 's', 'name' => 's', 'class_client_id' => 's', 'published' => 'i'];
    $columns = array_intersect_key($columns, $allowed);
    $kept = [];

    foreach ($items as $item) {
        if (!is_array($item)) continue;
        $cid = data_clean_id($item['id'] ?? null);
        if (!$cid) continue;
        if (strtolower(trim((string)($item['owner'] ?? ''))) !== $email) continue;

        if (isset($columns['code'])) {
            $code = strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', (string)($item['code'] ?? '')), 0, 16));
            if ($code !== '') {
                $chk = $conn->prepare("SELECT owner_id FROM $table WHERE code = ? AND client_id <> ? LIMIT 1");
                if ($chk) {
                    $chk->bind_param('ss', $code, $cid);
                    $chk->execute();
                    $other = $chk->get_result()->fetch_assoc();
                    $chk->close();
                    if ($other && (int)$other['owner_id'] !== $uid) {
                        $warnings[] = 'Class code ' . $code . ' is already used by another class.';
                        continue;
                    }
                }
                $item['code'] = $code;
            }
        }

        $values = [];
        $types  = 'si';
        $params = [$cid, $uid];
        foreach ($columns as $col => $kind) {
            $v = null;
            if ($col === 'code') $v = (string)($item['code'] ?? '');
            elseif ($col === 'name') $v = substr((string)($item['name'] ?? $item['title'] ?? ''), 0, 150);
            elseif ($col === 'class_client_id') $v = data_clean_id($item['classId'] ?? null);
            elseif ($col === 'published') $v = empty($item['published']) ? 0 : 1;
            $values[] = $col;
            $types .= $kind === 'i' ? 'i' : ($v === null ? 's' : 's');
            $params[] = $v;
        }
        $types .= 's';
        $params[] = data_json($item);

        $colsSql = implode(', ', array_merge(['client_id', 'owner_id'], $values, ['data']));
        $marks   = implode(', ', array_fill(0, count($params), '?'));
        $updates = ['data = VALUES(data)'];
        foreach ($values as $col) $updates[] = $col . ' = VALUES(' . $col . ')';
        $sql = "INSERT INTO $table ($colsSql) VALUES ($marks)
                ON DUPLICATE KEY UPDATE " . implode(', ', $updates);

        $st = $conn->prepare($sql);
        if (!$st) { $warnings[] = 'Could not save one or more records.'; continue; }
        $st->bind_param($types, ...$params);
        if (!$st->execute()) { $warnings[] = 'Could not save one or more records.'; }
        $st->close();
        $kept[] = $cid;
    }

    if ($kept) {
        $marks = implode(', ', array_fill(0, count($kept), '?'));
        $sql = "DELETE FROM $table WHERE owner_id = ? AND client_id NOT IN ($marks)";
        $st = $conn->prepare($sql);
        if ($st) {
            $st->bind_param('i' . str_repeat('s', count($kept)), $uid, ...$kept);
            $st->execute();
            $st->close();
        }
    } else {
        $st = $conn->prepare("DELETE FROM $table WHERE owner_id = ?");
        if ($st) { $st->bind_param('i', $uid); $st->execute(); $st->close(); }
    }
}

if (isset($s['classes']) && is_array($s['classes'])) {
    data_sync_collection($conn, 'classes', $s['classes'], $uid, $email, ['code' => 's', 'name' => 's'], $warnings);
}
if (isset($s['quizzes']) && is_array($s['quizzes'])) {
    data_sync_collection($conn, 'quizzes', $s['quizzes'], $uid, $email, ['class_client_id' => 's', 'published' => 'i'], $warnings);
}
if (isset($s['materials']) && is_array($s['materials'])) {
    data_sync_collection($conn, 'materials', $s['materials'], $uid, $email, ['class_client_id' => 's'], $warnings);
}

$practice = isset($s['practice']) && is_array($s['practice']) ? $s['practice'] : [];
$att      = isset($s['att']) && is_array($s['att']) ? $s['att'] : [];
$st = $conn->prepare('INSERT INTO user_state (user_id, practice, att) VALUES (?, ?, ?)
                      ON DUPLICATE KEY UPDATE practice = VALUES(practice), att = VALUES(att)');
if ($st) {
    $p = data_json($practice); $a = data_json($att);
    $st->bind_param('iss', $uid, $p, $a);
    if (!$st->execute()) $warnings[] = 'Could not save your practice and progress.';
    $st->close();
}

/* Import results the browser already had before server persistence existed.
   Grades on other students' results are handled by submissions.php. */
if (isset($s['results']) && is_array($s['results'])) {
    foreach ($s['results'] as $r) {
        if (!is_array($r)) continue;
        $rid = data_clean_id($r['id'] ?? null);
        $qid = data_clean_id($r['quizId'] ?? null);
        if (!$rid || !$qid || strtolower((string)($r['email'] ?? '')) !== $email) continue;
        $chk = $conn->prepare('SELECT 1 FROM quizzes WHERE client_id = ? LIMIT 1');
        if (!$chk) continue;
        $chk->bind_param('s', $qid);
        $chk->execute();
        $exists = $chk->get_result()->fetch_row();
        $chk->close();
        if (!$exists) continue;
        $ins = $conn->prepare('INSERT IGNORE INTO submissions (client_id, quiz_client_id, student_id, data) VALUES (?, ?, ?, ?)');
        if ($ins) {
            $payload = data_json($r);
            $ins->bind_param('ssis', $rid, $qid, $uid, $payload);
            $ins->execute();
            $ins->close();
        }
    }
}

echo json_encode(['success' => true, 'warnings' => $warnings]);
$conn->close();
