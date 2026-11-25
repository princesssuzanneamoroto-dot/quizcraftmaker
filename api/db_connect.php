<?php
/*
 * Shared bootstrap for every API file: JSON output, DB connection, secure
 * session start, and the server-side auth helpers.
 * Place this file in the same folder as login.php, signup.php, etc. (api/).
 */

header('Content-Type: application/json; charset=utf-8');
// Authentication responses must never be cached by the browser or a proxy.
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
header('X-Content-Type-Options: nosniff');

/* ---------- Database ---------- */
$host = getenv('QUIZCRAFT_DB_HOST') ?: 'localhost';
$user = getenv('QUIZCRAFT_DB_USER') ?: 'root';
$pass = getenv('QUIZCRAFT_DB_PASS');
if ($pass === false) {
    $pass = '';
}
$db   = getenv('QUIZCRAFT_DB_NAME') ?: 'quizcraft';

// Suppress default HTML error output to guarantee valid JSON responses on failure
mysqli_report(MYSQLI_REPORT_OFF);
ini_set('display_errors', '0');

$conn = @new mysqli($host, $user, $pass, $db);
if ($conn->connect_error) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed.']);
    exit;
}
$conn->set_charset('utf8mb4');

function ensure_user_avatar_column(mysqli $conn): void {
    $existing = @$conn->query("SHOW COLUMNS FROM users LIKE 'avatar'");
    if ($existing && $existing->num_rows > 0) {
        $existing->close();
        return;
    }
    if ($existing) $existing->close();
    @$conn->query('ALTER TABLE users ADD COLUMN avatar VARCHAR(40) NULL DEFAULT NULL AFTER role');
}
ensure_user_avatar_column($conn);

function ensure_username_unique_index(mysqli $conn): void {
    $index = @$conn->query("SHOW INDEX FROM users WHERE Key_name = 'idx_users_name'");
    if ($index && $index->num_rows > 0) {
        $index->close();
        return;
    }
    if ($index) $index->close();
    $dupes = @$conn->query('SELECT name FROM users GROUP BY name HAVING COUNT(*) > 1 LIMIT 1');
    if ($dupes && $dupes->num_rows > 0) {
        $dupes->close();
        return;
    }
    if ($dupes) $dupes->close();
    @$conn->query('ALTER TABLE users ADD UNIQUE KEY idx_users_name (name)');
}
ensure_username_unique_index($conn);

/* ---------- Session ---------- */
// The front end and the API are served from the same origin, so no CORS headers
// are needed (and a permissive CORS policy would be unsafe with cookies).
if (session_status() === PHP_SESSION_NONE) {
    $qcHttps = !empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off';
    if (!$qcHttps && !empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
        $qcProto = explode(',', (string)$_SERVER['HTTP_X_FORWARDED_PROTO'])[0];
        $qcHttps = strtolower(trim($qcProto)) === 'https';
    }
    session_name('quizcraft_sid');
    session_set_cookie_params([
        'lifetime' => 0,          // session cookie: dies when the browser closes
        'path'     => '/',
        'httponly' => true,       // JavaScript can never read the session id
        'samesite' => 'Lax',
        'secure'   => $qcHttps,
    ]);
    session_start();
}

/* ---------- Helpers ---------- */
function json_fail(int $code, string $message): void {
    http_response_code($code);
    echo json_encode(['error' => $message]);
    exit;
}

function read_json_body(): array {
    $data = json_decode(file_get_contents('php://input'), true);
    return is_array($data) ? $data : [];
}

function require_method(string $method): void {
    if ($_SERVER['REQUEST_METHOD'] !== $method) {
        header('Allow: ' . $method);
        json_fail(405, 'Method not allowed.');
    }
}

function normalize_role($role): string {
    return strtolower(trim((string)$role));
}

/** Throw away the current session completely (data, cookie, server file). */
function destroy_session(): void {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires'  => time() - 42000,
            'path'     => $p['path'],
            'domain'   => $p['domain'],
            'secure'   => $p['secure'],
            'httponly' => $p['httponly'],
            'samesite' => 'Lax',
        ]);
    }
    session_destroy();
}

/**
 * Log a verified user in. Only the user ID is stored in the session; the role
 * is always re-read from the database, so it can never go stale or be spoofed.
 */
function start_login_session(int $userId): void {
    $_SESSION = [];                 // drop anything left over from another account
    session_regenerate_id(true);    // new session ID, old one deleted
    $_SESSION['uid'] = $userId;
}

/** The currently logged-in user, loaded fresh from the database, or null. */
function current_user(): ?array {
    global $conn;
    $uid = $_SESSION['uid'] ?? null;
    if (!is_int($uid) || $uid <= 0) {
        return null;
    }
    $stmt = $conn->prepare('SELECT id, name, email, role, avatar FROM users WHERE id = ? LIMIT 1');
    if (!$stmt) {
        return null;
    }
    $stmt->bind_param('i', $uid);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) {
        // The account no longer exists: kill the session.
        destroy_session();
        return null;
    }
    $row['id']   = (int)$row['id'];
    $row['role'] = normalize_role($row['role']);
    return $row;
}

/** Use at the top of any protected endpoint/page. */
function require_login(): array {
    $u = current_user();
    if (!$u) {
        json_fail(401, 'You are not logged in.');
    }
    return $u;
}

function require_role(string $role): array {
    $u = require_login();
    if ($u['role'] !== normalize_role($role)) {
        json_fail(403, 'This area is only for ' . $role . ' accounts.');
    }
    return $u;
}
