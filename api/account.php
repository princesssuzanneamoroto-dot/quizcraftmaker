<?php
/*
 * Profile + Settings API for the logged-in user.
 *
 *   GET  account.php                       -> profile + saved preferences
 *   POST account.php {action:"update_profile", name}
 *   POST account.php {action:"save_settings", theme?, notify_assign?, notify_deadline?, notify_updates?}
 *   POST account.php {action:"change_password", current_password, new_password}
 *   POST account.php {action:"send_feedback", message}
 *   POST account.php {action:"delete_account", password, confirm:"DELETE"}
 *
 * Security rules:
 *  - the user is ALWAYS taken from the PHP session (require_login); no user id,
 *    email or role is ever read from the request;
 *  - the role can never be changed here;
 *  - every query is a prepared statement;
 *  - POSTs must be application/json (a cross-site HTML form cannot send that),
 *    and the session cookie is SameSite=Lax.
 */
require_once __DIR__ . '/db_connect.php';

$me  = require_login();          // 401 JSON if there is no valid session
$uid = $me['id'];

/* ---------- helpers ---------- */
function load_settings(mysqli $conn, int $uid): array {
    $out = ['theme' => null, 'notify_assign' => true, 'notify_deadline' => true, 'notify_updates' => true];
    $stmt = $conn->prepare('SELECT theme, notify_assign, notify_deadline, notify_updates FROM user_settings WHERE user_id = ? LIMIT 1');
    if (!$stmt) {
        json_fail(500, 'Settings tables are missing. Import setup.sql in phpMyAdmin first.');
    }
    $stmt->bind_param('i', $uid);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($row) {
        $out['theme']           = in_array($row['theme'], ['light', 'dark'], true) ? $row['theme'] : null;
        $out['notify_assign']   = (bool)$row['notify_assign'];
        $out['notify_deadline'] = (bool)$row['notify_deadline'];
        $out['notify_updates']  = (bool)$row['notify_updates'];
    }
    return $out;
}

function profile_payload(mysqli $conn, array $me): array {
    return [
        'success'  => true,
        'name'     => $me['name'],
        'email'    => $me['email'] !== '' ? $me['email'] : null,
        'role'     => $me['role'],                 // read-only, from the database
        'avatar'   => $me['avatar'] ?? null,
        'auth'     => 'password',
        'settings' => load_settings($conn, $me['id']),
    ];
}

function clean_text(string $s): string {
    $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $s) ?? '';
    return trim($s);
}

function valid_avatar(string $v): bool {
    return in_array($v, ['cat', 'bear', 'bunny', 'panda', 'fox', 'penguin', 'puppy', 'koala', 'owl', 'frog', 'turtle', 'unicorn', 'lion', 'monkey', 'deer', 'duck', 'hedgehog', 'raccoon', 'sloth', 'chick'], true);
}

function username_taken(mysqli $conn, string $name, int $uid): bool {
    $stmt = $conn->prepare('SELECT id FROM users WHERE name = ? AND id <> ? LIMIT 1');
    if (!$stmt) json_fail(500, 'Database statement preparation failed');
    $stmt->bind_param('si', $name, $uid);
    $stmt->execute();
    $stmt->store_result();
    $taken = $stmt->num_rows > 0;
    $stmt->close();
    return $taken;
}

function email_taken(mysqli $conn, string $email, int $uid): bool {
    $stmt = $conn->prepare('SELECT id FROM users WHERE email = ? AND id <> ? LIMIT 1');
    if (!$stmt) json_fail(500, 'Database statement preparation failed');
    $stmt->bind_param('si', $email, $uid);
    $stmt->execute();
    $stmt->store_result();
    $taken = $stmt->num_rows > 0;
    $stmt->close();
    return $taken;
}

function json_flags(): int {
    return JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
}

function rewrite_owned_email_json(mysqli $conn, string $table, int $uid, string $old, string $new, string $name): bool {
    $st = $conn->prepare("SELECT client_id, data FROM $table WHERE owner_id = ?");
    if (!$st) return false;
    $st->bind_param('i', $uid);
    if (!$st->execute()) { $st->close(); return false; }
    $result = $st->get_result();
    $rows = [];
    while ($row = $result->fetch_assoc()) $rows[] = $row;
    $st->close();

    foreach ($rows as $row) {
        $data = json_decode((string)$row['data'], true);
        if (!is_array($data)) continue;
        $changed = false;
        if (isset($data['owner']) && strtolower(trim((string)$data['owner'])) === $old) {
            $data['owner'] = $new;
            $changed = true;
        }
        if (array_key_exists('ownerName', $data)) {
            $data['ownerName'] = $name;
            $changed = true;
        }
        if (isset($data['members']) && is_array($data['members'])) {
            foreach ($data['members'] as &$member) {
                if (is_array($member) && isset($member['email']) && strtolower(trim((string)$member['email'])) === $old) {
                    $member['email'] = $new;
                    $changed = true;
                }
            }
            unset($member);
        }
        if (!$changed) continue;
        $json = json_encode($data, json_flags());
        if ($json === false) return false;
        $up = $conn->prepare("UPDATE $table SET data = ? WHERE client_id = ? AND owner_id = ?");
        if (!$up) return false;
        $up->bind_param('ssi', $json, $row['client_id'], $uid);
        $ok = $up->execute();
        $up->close();
        if (!$ok) return false;
    }
    return true;
}

function rewrite_submission_emails(mysqli $conn, int $uid, string $old, string $new): bool {
    $st = $conn->prepare('SELECT s.client_id, s.data FROM submissions s JOIN quizzes q ON q.client_id = s.quiz_client_id WHERE s.student_id = ? OR q.owner_id = ?');
    if (!$st) return false;
    $st->bind_param('ii', $uid, $uid);
    if (!$st->execute()) { $st->close(); return false; }
    $result = $st->get_result();
    $rows = [];
    while ($row = $result->fetch_assoc()) $rows[] = $row;
    $st->close();

    foreach ($rows as $row) {
        $data = json_decode((string)$row['data'], true);
        if (!is_array($data) || !isset($data['email']) || strtolower(trim((string)$data['email'])) !== $old) continue;
        $data['email'] = $new;
        $json = json_encode($data, json_flags());
        if ($json === false) return false;
        $up = $conn->prepare('UPDATE submissions SET data = ? WHERE client_id = ?');
        if (!$up) return false;
        $up->bind_param('ss', $json, $row['client_id']);
        $ok = $up->execute();
        $up->close();
        if (!$ok) return false;
    }
    return true;
}

function rewrite_user_state_emails(mysqli $conn, int $uid, string $old, string $new): bool {
    $st = $conn->prepare('SELECT practice, att FROM user_state WHERE user_id = ? LIMIT 1');
    if (!$st) return false;
    $st->bind_param('i', $uid);
    if (!$st->execute()) { $st->close(); return false; }
    $row = $st->get_result()->fetch_assoc();
    $st->close();
    if (!$row) return true;

    $practice = json_decode((string)$row['practice'], true);
    if (is_array($practice)) {
        foreach ($practice as &$item) {
            if (is_array($item) && isset($item['email']) && strtolower(trim((string)$item['email'])) === $old) {
                $item['email'] = $new;
            }
        }
        unset($item);
    } else {
        $practice = [];
    }

    $att = json_decode((string)$row['att'], true);
    if (!is_array($att)) $att = [];
    $prefix = $old . '|';
    $nextAtt = [];
    foreach ($att as $key => $value) {
        if (is_string($key) && strpos($key, $prefix) === 0) {
            $nextKey = $new . '|' . substr($key, strlen($prefix));
            if (isset($nextAtt[$nextKey]) && is_array($nextAtt[$nextKey]) && is_array($value)) {
                $nextAtt[$nextKey] = array_merge($nextAtt[$nextKey], $value);
            } else {
                $nextAtt[$nextKey] = $value;
            }
        } else {
            $nextAtt[$key] = $value;
        }
    }

    $practiceJson = json_encode($practice, json_flags());
    $attJson = json_encode($nextAtt, json_flags());
    if ($practiceJson === false || $attJson === false) return false;
    $up = $conn->prepare('UPDATE user_state SET practice = ?, att = ? WHERE user_id = ?');
    if (!$up) return false;
    $up->bind_param('ssi', $practiceJson, $attJson, $uid);
    $ok = $up->execute();
    $up->close();
    return $ok;
}

function password_ok(mysqli $conn, int $uid, string $plain): bool {
    $stmt = $conn->prepare('SELECT password_hash FROM users WHERE id = ? LIMIT 1');
    if (!$stmt) json_fail(500, 'Database query preparation failed');
    $stmt->bind_param('i', $uid);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return !empty($row['password_hash']) && $plain !== '' && password_verify($plain, $row['password_hash']);
}

/* ---------- GET: profile ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    echo json_encode(profile_payload($conn, $me));
    exit;
}

require_method('POST');
if (stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== 0) {
    json_fail(415, 'Unsupported request type.');
}
$d      = read_json_body();
$action = (string)($d['action'] ?? '');

switch ($action) {

    /* ----- username ----- */
    case 'update_profile':
    case 'update_username': {
        $name = clean_text((string)($d['username'] ?? $d['name'] ?? ''));
        $len  = function_exists('mb_strlen') ? mb_strlen($name, 'UTF-8') : strlen($name);
        if ($len < 1 || $len > 60) {
            json_fail(400, 'Username must be 1 to 60 characters.');
        }
        if (username_taken($conn, $name, $uid)) {
            json_fail(409, 'That username is already taken.');
        }
        $stmt = $conn->prepare('UPDATE users SET name = ? WHERE id = ?');
        if (!$stmt) json_fail(500, 'Database statement preparation failed');
        $stmt->bind_param('si', $name, $uid);
        if (!$stmt->execute()) { $stmt->close(); json_fail(500, 'Could not save your profile.'); }
        $stmt->close();
        $me['name'] = $name;
        echo json_encode(profile_payload($conn, $me));
        break;
    }

    case 'update_email': {
        $email = strtolower(trim(clean_text((string)($d['email'] ?? ''))));
        if ($email === '' || strlen($email) > 100 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            json_fail(400, 'Enter a valid email address.');
        }
        if (!password_ok($conn, $uid, (string)($d['current_password'] ?? ''))) {
            json_fail(403, 'Your current password is incorrect.');
        }
        $old = strtolower(trim((string)$me['email']));
        if ($email === $old) {
            json_fail(400, 'That is already your email address.');
        }
        if (email_taken($conn, $email, $uid)) {
            json_fail(409, 'That email address is already in use.');
        }

        if (!$conn->begin_transaction()) json_fail(500, 'Could not save your email address.');
        try {
            $stmt = $conn->prepare('UPDATE users SET email = ? WHERE id = ?');
            if (!$stmt) throw new RuntimeException('prepare');
            $stmt->bind_param('si', $email, $uid);
            if (!$stmt->execute()) throw new RuntimeException('update');
            $stmt->close();

            if (!rewrite_owned_email_json($conn, 'classes', $uid, $old, $email, (string)$me['name'])) throw new RuntimeException('classes');
            if (!rewrite_owned_email_json($conn, 'quizzes', $uid, $old, $email, (string)$me['name'])) throw new RuntimeException('quizzes');
            if (!rewrite_owned_email_json($conn, 'materials', $uid, $old, $email, (string)$me['name'])) throw new RuntimeException('materials');
            if (!rewrite_submission_emails($conn, $uid, $old, $email)) throw new RuntimeException('submissions');
            if (!rewrite_user_state_emails($conn, $uid, $old, $email)) throw new RuntimeException('state');

            $conn->commit();
        } catch (Throwable $e) {
            $errno = $conn->errno;
            $conn->rollback();
            if ($errno === 1062) json_fail(409, 'That email address is already in use.');
            json_fail(500, 'Could not save your email address. Please try again.');
        }

        session_regenerate_id(true);
        $me['email'] = $email;
        echo json_encode(profile_payload($conn, $me));
        break;
    }

    case 'update_avatar': {
        $avatar = (string)($d['avatar'] ?? '');
        if (!valid_avatar($avatar)) json_fail(400, 'Choose one of the available avatars.');
        $stmt = $conn->prepare('UPDATE users SET avatar = ? WHERE id = ?');
        if (!$stmt) json_fail(500, 'Database statement preparation failed');
        $stmt->bind_param('si', $avatar, $uid);
        if (!$stmt->execute()) { $stmt->close(); json_fail(500, 'Could not save your avatar.'); }
        $stmt->close();
        $me['avatar'] = $avatar;
        echo json_encode(profile_payload($conn, $me));
        break;
    }

    /* ----- theme + notification preferences ----- */
    case 'save_settings': {
        $s = load_settings($conn, $uid);
        if (array_key_exists('theme', $d)) {
            if (!in_array($d['theme'], ['light', 'dark'], true)) json_fail(400, 'Invalid theme.');
            $s['theme'] = $d['theme'];
        }
        foreach (['notify_assign', 'notify_deadline', 'notify_updates'] as $k) {
            if (array_key_exists($k, $d)) {
                if (!is_bool($d[$k])) json_fail(400, 'Invalid value for ' . $k . '.');
                $s[$k] = $d[$k];
            }
        }
        $theme = $s['theme'];
        $a = (int)$s['notify_assign']; $b = (int)$s['notify_deadline']; $c = (int)$s['notify_updates'];
        $stmt = $conn->prepare(
            'INSERT INTO user_settings (user_id, theme, notify_assign, notify_deadline, notify_updates)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE theme = VALUES(theme), notify_assign = VALUES(notify_assign),
                                     notify_deadline = VALUES(notify_deadline), notify_updates = VALUES(notify_updates)'
        );
        if (!$stmt) json_fail(500, 'Database statement preparation failed');
        $stmt->bind_param('isiii', $uid, $theme, $a, $b, $c);
        if (!$stmt->execute()) { $stmt->close(); json_fail(500, 'Could not save your settings.'); }
        $stmt->close();
        echo json_encode(['success' => true, 'settings' => load_settings($conn, $uid)]);
        break;
    }

    /* ----- change password (needs the current one) ----- */
    case 'change_password': {
        $cur = (string)($d['current_password'] ?? '');
        $new = (string)($d['new_password'] ?? '');
        if (strlen($new) < 8)  json_fail(400, 'New password must be at least 8 characters.');
        if (strlen($new) > 200) json_fail(400, 'New password is too long.');
        if (!password_ok($conn, $uid, $cur)) json_fail(403, 'Your current password is incorrect.');
        if (hash_equals($cur, $new)) json_fail(400, 'Choose a password different from the current one.');
        $hash = password_hash($new, PASSWORD_DEFAULT);
        $stmt = $conn->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
        if (!$stmt) json_fail(500, 'Database statement preparation failed');
        $stmt->bind_param('si', $hash, $uid);
        if (!$stmt->execute()) { $stmt->close(); json_fail(500, 'Could not change your password.'); }
        $stmt->close();
        session_regenerate_id(true);       // new session id after a credential change
        echo json_encode(['success' => true]);
        break;
    }

    /* ----- feedback (stored in the feedback table) ----- */
    case 'send_feedback': {
        $msg = clean_text((string)($d['message'] ?? ''));
        $len = function_exists('mb_strlen') ? mb_strlen($msg, 'UTF-8') : strlen($msg);
        if ($len < 10 || $len > 2000) json_fail(400, 'Feedback must be 10 to 2000 characters.');

        // simple abuse limit: 5 messages per hour per account
        $stmt = $conn->prepare('SELECT COUNT(*) FROM feedback WHERE user_id = ? AND created_at > (NOW() - INTERVAL 1 HOUR)');
        if (!$stmt) json_fail(500, 'Feedback table is missing. Import setup.sql in phpMyAdmin first.');
        $stmt->bind_param('i', $uid);
        $stmt->execute();
        $stmt->bind_result($recent);
        $stmt->fetch();
        $stmt->close();
        if ($recent >= 5) json_fail(429, 'You have sent a lot of feedback recently. Please try again later.');

        $role = $me['role'];
        $stmt = $conn->prepare('INSERT INTO feedback (user_id, role, message) VALUES (?, ?, ?)');
        if (!$stmt) json_fail(500, 'Database statement preparation failed');
        $stmt->bind_param('iss', $uid, $role, $msg);
        if (!$stmt->execute()) { $stmt->close(); json_fail(500, 'Could not send your feedback.'); }
        $stmt->close();
        echo json_encode(['success' => true]);
        break;
    }

    /* ----- delete own account (password + typed confirmation) ----- */
    case 'delete_account': {
        if (($d['confirm'] ?? '') !== 'DELETE') json_fail(400, 'Type DELETE to confirm.');
        if (!password_ok($conn, $uid, (string)($d['password'] ?? ''))) json_fail(403, 'Your password is incorrect.');

        foreach (['DELETE FROM user_settings WHERE user_id = ?', 'DELETE FROM feedback WHERE user_id = ?'] as $sql) {
            $st = $conn->prepare($sql);
            if ($st) { $st->bind_param('i', $uid); $st->execute(); $st->close(); }
        }
        $stmt = $conn->prepare('DELETE FROM users WHERE id = ? LIMIT 1');
        if (!$stmt) json_fail(500, 'Database statement preparation failed');
        $stmt->bind_param('i', $uid);
        $ok = $stmt->execute() && $stmt->affected_rows === 1;
        $stmt->close();
        if (!$ok) json_fail(500, 'Could not delete the account.');
        destroy_session();
        echo json_encode(['success' => true]);
        break;
    }

    default:
        json_fail(400, 'Unknown action.');
}
$conn->close();
