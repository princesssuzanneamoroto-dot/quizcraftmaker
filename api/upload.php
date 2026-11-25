<?php
require_once __DIR__ . '/db_connect.php';
$autoload = __DIR__ . '/../vendor/autoload.php';
if (file_exists($autoload)) { require_once $autoload; }
require_method('POST');
require_login();

function upload_clean($value): string {
    $s = str_replace(["\r\n", "\r", "\u{00A0}"], ["\n", "\n", ' '], (string)$value);
    $s = preg_replace('/\s+/u', ' ', $s);
    return trim($s);
}

function upload_xml_text($xml): string {
    $xml = preg_replace('/<(?:w:br|a:br|br)\b[^>]*>/iu', "\n", (string)$xml);
    $xml = preg_replace('/<\/(?:w:p|a:p|p|div|tr|li|h[1-6])>/iu', "\n", (string)$xml);
    $xml = strip_tags($xml);
    $xml = html_entity_decode($xml, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return upload_clean($xml);
}

function upload_docx(string $path): string {
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) { return ''; }
    $xml = $zip->getFromName('word/document.xml');
    $zip->close();
    return $xml === false ? '' : upload_xml_text($xml);
}

function upload_pptx(string $path): string {
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) { return ''; }
    $slides = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);
        if ($name !== false && preg_match('#^ppt/slides/slide\d+\.xml$#', $name)) { $slides[] = $name; }
    }
    usort($slides, function ($a, $b) {
        preg_match('/(\d+)/', $a, $ma);
        preg_match('/(\d+)/', $b, $mb);
        return (int)($ma[1] ?? 0) <=> (int)($mb[1] ?? 0);
    });
    $text = '';
    foreach ($slides as $slide) {
        $xml = $zip->getFromName($slide);
        if ($xml !== false) { $text .= upload_xml_text($xml) . "\n"; }
    }
    $zip->close();
    return upload_clean($text);
}

function upload_txt(string $path): string {
    $raw = file_get_contents($path);
    if ($raw === false) { return ''; }
    $enc = mb_detect_encoding($raw, ['UTF-8', 'UTF-16LE', 'WINDOWS-1252', 'ISO-8859-1'], true);
    if ($enc) { $raw = mb_convert_encoding($raw, 'UTF-8', $enc); }
    return upload_clean($raw);
}

function upload_pdf(string $path): ?string {
    if (!class_exists('Smalot\PdfParser\Parser')) { return null; }
    try {
        $parser = new Smalot\PdfParser\Parser();
        return upload_clean($parser->parseFile($path)->getText());
    } catch (Throwable $e) {
        return '';
    }
}

function upload_remove_dir(string $dir): void {
    if (!is_dir($dir)) { return; }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $item) {
        $p = $item->getPathname();
        if ($item->isDir()) { @rmdir($p); } else { @unlink($p); }
    }
    @rmdir($dir);
}

function upload_ini_bytes($value): int {
    $s = trim((string)$value);
    if ($s === '') { return 0; }
    $unit = strtolower(substr($s, -1));
    $n = (float)$s;
    if ($unit === 'g') { $n *= 1024 * 1024 * 1024; }
    elseif ($unit === 'm') { $n *= 1024 * 1024; }
    elseif ($unit === 'k') { $n *= 1024; }
    return (int)$n;
}

function upload_list($raw): array {
    if (!is_array($raw) || !isset($raw['name'])) { return []; }
    if (!is_array($raw['name'])) {
        return [['name' => $raw['name'], 'tmp_name' => $raw['tmp_name'] ?? '', 'error' => $raw['error'] ?? UPLOAD_ERR_OK, 'size' => $raw['size'] ?? 0]];
    }
    $out = [];
    foreach ($raw['name'] as $i => $name) {
        $out[] = ['name' => $name, 'tmp_name' => $raw['tmp_name'][$i] ?? '', 'error' => $raw['error'][$i] ?? UPLOAD_ERR_OK, 'size' => $raw['size'][$i] ?? 0];
    }
    return $out;
}
$contentLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
$postLimit = upload_ini_bytes(ini_get('post_max_size'));
if ($contentLength > 0 && $postLimit > 0 && $contentLength > $postLimit) { json_fail(413, 'The total upload size is too large.'); }
$uploads = upload_list($_FILES['files'] ?? ($_FILES['files[]'] ?? null));
if (!$uploads) { json_fail(400, 'No files were uploaded.'); }
$maxFiles = 10;
if (count($uploads) > $maxFiles) { json_fail(400, 'Upload at most 10 files at a time.'); }
$allow = [
    'txt' => ['text/plain', 'text/markdown', 'application/octet-stream'],
    'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip', 'application/octet-stream'],
    'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', 'application/zip', 'application/octet-stream'],
    'pdf' => ['application/pdf', 'application/octet-stream'],
];
$maxBytes = 10 * 1024 * 1024;
$invalidMessage = 'Each file must be a supported format and cannot exceed 10 MB.';
$total = 0;
foreach ($uploads as $file) {
    $size = (int)($file['size'] ?? 0);
    if ((int)($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK || $size <= 0 || $size > $maxBytes) { json_fail(400, $invalidMessage); }
    $total += $size;
}
if ($total > $maxFiles * $maxBytes) { json_fail(400, 'The total upload size is too large.'); }

$dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'quizcraft_' . bin2hex(random_bytes(12));
if (!mkdir($dir, 0700, true)) { json_fail(500, 'Could not prepare a secure upload area.'); }
$finfo = new finfo(FILEINFO_MIME_TYPE);
$files = [];
$errors = [];

try {
    foreach ($uploads as $index => $file) {
        $name = basename((string)($file['name'] ?? ''));
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $tmp = (string)($file['tmp_name'] ?? '');
        $size = (int)($file['size'] ?? 0);
        if (!isset($allow[$ext])) { $errors[] = $name . ': ' . $invalidMessage; continue; }
        $mime = strtolower((string)$finfo->file($tmp));
        if (!in_array($mime, $allow[$ext], true)) { $errors[] = $name . ': ' . $invalidMessage; continue; }
        $safe = bin2hex(random_bytes(16)) . '.' . $ext;
        $dest = $dir . DIRECTORY_SEPARATOR . $safe;
        if (!move_uploaded_file($tmp, $dest)) { $errors[] = $name . ': could not be stored safely.'; continue; }
        if ($ext === 'docx') { $text = upload_docx($dest); }
        elseif ($ext === 'pptx') { $text = upload_pptx($dest); }
        elseif ($ext === 'txt') { $text = upload_txt($dest); }
        else { $text = upload_pdf($dest); }
        if ($text === null) { $errors[] = $name . ': PDF text extraction is not configured on this server. Paste the text instead.'; continue; }
        if ($text === '') { $errors[] = $name . ': no readable text was found. Paste the text instead.'; continue; }
        $files[] = ['index' => $index, 'name' => $name, 'ext' => $ext, 'size' => $size, 'text' => $text];
    }
} finally {
    upload_remove_dir($dir);
}

if (!$files && $errors) { json_fail(422, implode(' ', $errors)); }
echo json_encode(['success' => true, 'files' => $files, 'errors' => $errors], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
