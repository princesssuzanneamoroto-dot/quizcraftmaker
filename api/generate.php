<?php
require_once __DIR__ . '/db_connect.php';
require_method('POST');
$me = require_login();

function qc_ai_env(string $name): string {
    $v = getenv($name);
    if ($v === false || $v === '') { $v = $_SERVER[$name] ?? ''; }
    if ($v === false || $v === '') { $v = $_ENV[$name] ?? ''; }
    return $v === false ? '' : trim((string)$v);
}

function qc_ai_ca_bundle(): string {
    $configured = trim((string)(ini_get('curl.cainfo') ?: ini_get('openssl.cafile') ?: ''));
    if ($configured !== '' && is_file($configured)) { return $configured; }
    $env = qc_ai_env('QUIZCRAFT_AI_CA_BUNDLE');
    if ($env !== '' && is_file($env)) { return $env; }
    foreach (glob(dirname(__DIR__, 3) . '\\bin\\php\\php*\\extras\\ssl\\cacert.pem') ?: [] as $candidate) {
        if (is_file($candidate)) { return $candidate; }
    }
    return '';
}

function qc_ai_config(): ?array {
    $key = qc_ai_env('QUIZCRAFT_AI_API_KEY');
    $model = qc_ai_env('QUIZCRAFT_AI_MODEL');
    if ($key === '' || $model === '') { return null; }
    $base = qc_ai_env('QUIZCRAFT_AI_BASE_URL') ?: 'https://api.openai.com/v1';
    $base = rtrim($base, '/');
    return ['key' => $key, 'model' => $model, 'base' => $base, 'url' => $base . '/chat/completions'];
}

function qc_ai_clean_questions($value, int $count, array $types): array {
    if (!is_array($value)) { return []; }
    if (isset($value['questions']) && is_array($value['questions'])) { $value = $value['questions']; }
    if (!array_is_list($value)) { return []; }
    $out = [];
    $seen = [];
    foreach ($value as $q) {
        if (!is_array($q)) { continue; }
        $type = (string)($q['type'] ?? '');
        $qtext = trim((string)($q['q'] ?? ''));
        if (!in_array($type, $types, true) || $qtext === '') { continue; }
        $fp = function_exists('mb_strtolower') ? mb_strtolower(preg_replace('/\s+/u', ' ', $qtext)) : strtolower($qtext);
        if (isset($seen[$fp])) { continue; }
        $seen[$fp] = true;
        $explain = trim((string)($q['e'] ?? ''));
        if ($type === 'mc') {
            $opts = $q['o'] ?? [];
            if (!is_array($opts) || count($opts) !== 4) { continue; }
            $opts = array_values(array_map(function ($v) { return trim((string)$v); }, $opts));
            $lower = array_map(function ($v) { return function_exists('mb_strtolower') ? mb_strtolower($v) : strtolower($v); }, $opts);
            if (count(array_unique($lower)) !== 4 || in_array('', $opts, true)) { continue; }
            $a = filter_var($q['a'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 3]]);
            if ($a === false) { continue; }
            $out[] = ['type' => 'mc', 'q' => $qtext, 'o' => $opts, 'a' => (int)$a, 'e' => $explain];
        } elseif ($type === 'enum') {
            $answers = $q['a'] ?? [];
            if (!is_array($answers)) { continue; }
            $answers = array_values(array_filter(array_map(function ($v) { return trim((string)$v); }, $answers), function ($v) { return $v !== ''; }));
            if (count($answers) < 2 || count($answers) > 10) { continue; }
            $out[] = ['type' => 'enum', 'q' => $qtext, 'a' => $answers, 'e' => $explain];
        } else {
            $answer = trim((string)($q['a'] ?? ''));
            if ($answer === '') { continue; }
            $out[] = ['type' => $type, 'q' => $qtext, 'a' => $answer, 'e' => $explain];
        }
        if (count($out) === $count) { break; }
    }
    return $out;
}

function qc_ai_generate(array $cfg, string $text, string $title, int $count, array $types, int $limit, string $diff, array $materials): array {
    $maxChars = (int)(qc_ai_env('QUIZCRAFT_AI_MAX_CHARS') ?: 30000);
    if (function_exists('mb_strlen') && mb_strlen($text) > $maxChars) { json_fail(413, 'The submitted material is too long for AI generation. Paste a shorter selection and try again.'); }
    $system = 'You generate quiz questions only from submitted study material. Never invent facts or use outside knowledge. Avoid duplicate or repetitive questions. Cover the important topics and concepts in the material. Every answer must be supported by the submitted text. Return exactly the requested number of questions, using only the requested types. Output strict JSON as {"questions":[...]}. For mc use {"type":"mc","q":"...","o":["a","b","c","d"],"a":0,"e":"..."}; for essay use {"type":"essay","q":"...","a":"sample answer","e":"..."}; for enum use {"type":"enum","q":"List N ...","a":["item1","item2"],"e":"..."}; for id use {"type":"id","q":"Identify the term described by ...","a":"term","e":"..."}.';
    $payload = [
        'material' => $text,
        'title' => $title,
        'requestedCount' => $count,
        'questionTypes' => $types,
        'timeLimitMinutes' => $limit,
        'difficulty' => $diff,
        'sourceFiles' => $materials,
    ];
    $body = json_encode([
        'model' => $cfg['model'],
        'messages' => [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)],
        ],
        'temperature' => 0.2,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $ch = curl_init($cfg['url']);
    $options = [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json', 'Authorization: Bearer ' . $cfg['key']],
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ];
    $caBundle = qc_ai_ca_bundle();
    if ($caBundle !== '') { $options[CURLOPT_CAINFO] = $caBundle; }
    curl_setopt_array($ch, $options);
    $raw = curl_exec($ch);
    $errno = (int)curl_errno($ch);
    $error = (string)curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $sslVerify = (int)curl_getinfo($ch, CURLINFO_SSL_VERIFYRESULT);
    curl_close($ch);
    if ($errno !== 0 || $error !== '' || $status < 200 || $status >= 300) {
        $parts = ['AI generation failed'];
        if ($errno !== 0 || $error !== '') { $parts[] = 'cURL error ' . $errno . ': ' . ($error !== '' ? $error : 'unknown error'); }
        $parts[] = $status > 0 ? 'HTTP ' . $status : 'HTTP status unavailable';
        $snippet = preg_replace('/\s+/', ' ', trim((string)$raw));
        if (is_string($snippet) && $snippet !== '') { $parts[] = 'response ' . mb_substr($snippet, 0, 300); }
        $detail = implode('; ', $parts);
        error_log('QuizCraft AI request failed: ' . $detail . '; url=' . $cfg['url'] . '; model=' . $cfg['model'] . '; sslVerifyResult=' . $sslVerify . '; caBundle=' . ($caBundle !== '' ? 'configured' : 'missing'));
        json_fail(502, $detail);
    }
    $response = json_decode((string)$raw, true);
    $content = trim((string)($response['choices'][0]['message']['content'] ?? ''));
    if (preg_match('/```(?:json)?\s*(.*?)```/s', $content, $m)) { $content = trim($m[1]); }
    $data = json_decode($content, true);
    $questions = qc_ai_clean_questions($data, $count, $types);
    if (count($questions) !== $count) { json_fail(502, 'AI returned fewer valid questions than requested.'); }
    return $questions;
}

$b = read_json_body();
$text = (string)($b['text'] ?? '');
$text = preg_replace('/\s+/u', ' ', trim($text));
$len = function_exists('mb_strlen') ? mb_strlen($text) : strlen($text);
if ($len < 60) { json_fail(400, 'Add valid study materials before generating a quiz.'); }
if ($len > 200000) { $text = function_exists('mb_substr') ? mb_substr($text, 0, 200000) : substr($text, 0, 200000); }

$count = filter_var($b['count'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 200]]);
if ($count === false) { json_fail(400, 'The requested number of questions is invalid.'); }
$estimated = filter_var($b['estimatedMaximum'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 200]]);
if ($estimated === false) { json_fail(400, 'The estimated maximum is missing or invalid.'); }
$allowedTypes = ['mc', 'essay', 'enum', 'id'];
$types = [];
foreach ((array)($b['types'] ?? []) as $t) {
    $t = (string)$t;
    if (in_array($t, $allowedTypes, true) && !in_array($t, $types, true)) { $types[] = $t; }
}
if (!$types) { json_fail(400, 'Choose at least one question type.'); }
$limit = filter_var($b['limit'] ?? 0, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 60]]);
if ($limit === false || !in_array($limit, [0, 10, 15, 20, 30, 45, 60], true)) { json_fail(400, 'The selected time limit is invalid.'); }
$diff = (string)($b['difficulty'] ?? 'medium');
if (!in_array($diff, ['easy', 'medium', 'hard'], true)) { $diff = 'medium'; }
$title = trim((string)($b['title'] ?? ''));
if ($title === '') { $title = 'Practice quiz'; }
if (function_exists('mb_strlen') && mb_strlen($title) > 120) { $title = mb_substr($title, 0, 120); }

$materials = [];
foreach ((array)($b['materials'] ?? []) as $m) {
    $m = is_array($m) ? $m : [];
    $name = trim((string)($m['name'] ?? ''));
    if ($name === '') { continue; }
    if (function_exists('mb_strlen') && mb_strlen($name) > 180) { $name = mb_substr($name, 0, 180); }
    $ext = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', (string)($m['ext'] ?? '')));
    $size = max(0, (int)($m['size'] ?? 0));
    $materials[] = ['name' => $name, 'ext' => $ext, 'size' => $size];
    if (count($materials) >= 10) { break; }
}

$stop = ['a', 'about', 'above', 'after', 'again', 'against', 'all', 'also', 'am', 'an', 'and', 'any', 'are', 'as', 'at', 'be', 'because', 'been', 'before', 'being', 'below', 'between', 'both', 'but', 'by', 'can', 'could', 'did', 'do', 'does', 'doing', 'down', 'during', 'each', 'few', 'for', 'from', 'further', 'had', 'has', 'have', 'having', 'he', 'her', 'here', 'hers', 'him', 'his', 'how', 'i', 'if', 'in', 'into', 'is', 'it', 'its', 'itself', 'just', 'lesson', 'like', 'me', 'more', 'most', 'my', 'no', 'nor', 'not', 'now', 'of', 'off', 'on', 'once', 'only', 'or', 'other', 'our', 'out', 'over', 'own', 's', 'same', 'she', 'should', 'so', 'some', 'such', 'than', 'that', 'the', 'their', 'theirs', 'them', 'then', 'there', 'these', 'they', 'this', 'those', 'through', 'to', 'too', 'under', 'until', 'up', 'very', 'was', 'we', 'were', 'what', 'when', 'where', 'which', 'while', 'who', 'whom', 'why', 'will', 'with', 'would', 'you', 'your', 'chapter', 'student', 'teacher', 'note', 'notes', 'read', 'reading', 'page'];
$stopLookup = array_fill_keys($stop, true);
$norm = function ($value) use ($stopLookup) {
    $v = function_exists('mb_strtolower') ? mb_strtolower(trim((string)$value)) : strtolower(trim((string)$value));
    $v = preg_replace('/[^\p{L}\p{N}\s-]/u', ' ', $v);
    $v = preg_replace('/\s+/u', ' ', $v);
    $words = preg_split('/\s+/u', $v, -1, PREG_SPLIT_NO_EMPTY);
    $kept = [];
    foreach ($words as $word) {
        $word = trim($word, "-'");
        if ($word === '' || isset($stopLookup[$word])) { continue; }
        $kept[] = $word;
    }
    return implode(' ', $kept);
};

$parts = preg_split('/(?<=[.!?])\s+|\R+/u', $text, -1, PREG_SPLIT_NO_EMPTY);
$units = [];
foreach ($parts as $part) {
    $sentence = trim(preg_replace('/^\s*(?:[-•*]|\d+[.)])\s*/u', '', $part));
    $words = preg_split('/\s+/u', $sentence, -1, PREG_SPLIT_NO_EMPTY);
    if (count($words) < 5) { continue; }
    $key = $norm($sentence);
    if (strlen($key) < 20) { continue; }
    $units[$key] = $sentence;
}
$concepts = [];
$definitions = [];
$topicHits = [];
$addTerm = function ($term) use ($norm, &$concepts, &$topicHits) {
    $key = $norm($term);
    if ($key === '' || strlen($key) < 4) { return; }
    $words = preg_split('/\s+/u', $key, -1, PREG_SPLIT_NO_EMPTY);
    $topicHits[$key] = ($topicHits[$key] ?? 0) + 1;
    if (count($words) > 1 || strlen($key) >= 5) { $concepts[$key] = true; }
};
foreach ($units as $sentence) {
    if (preg_match_all('/\b([A-Za-z][A-Za-z\s-]{2,60}?)\s+(?:is|are|means|refers to|defined as|can be defined as)\b/i', $sentence, $m)) {
        foreach ($m[1] as $term) {
            $k = $norm($term);
            if ($k !== '' && strlen($k) >= 3) { $definitions[$k] = true; }
        }
    }
    if (preg_match('/^\s*([A-Za-z][A-Za-z\s-]{2,60}?)\s*:\s*(.+)$/u', $sentence, $m)) {
        $k = $norm($m[1]);
        if ($k !== '' && strlen($k) >= 3) { $definitions[$k] = true; }
    }
    if (preg_match_all('/["“]([^"”]{3,60})["”]/u', $sentence, $m)) { foreach ($m[1] as $term) { $addTerm($term); } }
    if (preg_match_all('/\(([^)]{3,60})\)/u', $sentence, $m)) { foreach ($m[1] as $term) { $addTerm($term); } }
    if (preg_match_all('/\b([A-Z][A-Za-z0-9&\'\-]*(?:\s+[A-Z][A-Za-z0-9&\'\-]*){0,4})\b/u', $sentence, $m)) { foreach ($m[1] as $term) { $addTerm($term); } }
}
$lines = preg_split('/\R+/u', $text, -1, PREG_SPLIT_NO_EMPTY);
foreach ($lines as $line) {
    $line = trim($line);
    $words = preg_split('/\s+/u', $line, -1, PREG_SPLIT_NO_EMPTY);
    $n = count($words);
    if ($n >= 2 && $n <= 10 && !preg_match('/[.!?]$/u', $line)) { $addTerm($line); }
}
foreach (array_keys($topicHits) as $key) {
    $times = $topicHits[$key];
    if (count(preg_split('/\s+/u', $key, -1, PREG_SPLIT_NO_EMPTY)) > 1 || $times >= 2) { $concepts[$key] = true; }
}
foreach (array_keys($definitions) as $key) { unset($concepts[$key]); }
$topics = [];
foreach (array_keys($concepts) as $key) {
    if (($topicHits[$key] ?? 0) >= 2) { $topics[$key] = true; }
}
$maximum = min(200, count($units) + count($concepts) + count($definitions) + count($topics));
if ($maximum === 0) { json_fail(422, 'No distinct topics, definitions, or concepts were found.'); }
if ($estimated !== $maximum) { json_fail(409, 'The estimate is out of date. Wait for analysis to finish and try again.'); }
if ($count > $maximum) { json_fail(400, 'Requested count exceeds the estimated maximum of ' . $maximum . ' questions.'); }

$sentences = array_values($units);
usort($sentences, function ($a, $b) use ($diff) {
    if ($diff === 'hard') { return strlen($b) <=> strlen($a); }
    if ($diff === 'easy') { return strlen($a) <=> strlen($b); }
    return 0;
});
$rawTokens = function ($s) {
    preg_match_all('/[A-Za-z][A-Za-z\'-]{3,}/', $s, $m);
    return array_values(array_unique($m[0] ?? []));
};
$words = function ($s) use ($rawTokens, $stopLookup) {
    $w = array_values(array_filter($rawTokens($s), function ($v) use ($stopLookup) { return !isset($stopLookup[strtolower($v)]); }));
    usort($w, function ($a, $b) { return strlen($b) <=> strlen($a); });
    return $w;
};
$all = [];
foreach ($sentences as $sentence) { foreach ($words($sentence) as $w) { $all[strtolower($w)] = $w; } }
$all = array_values($all);
$shuffle = function ($a) {
    $a = array_values($a);
    for ($i = count($a) - 1; $i > 0; $i--) { $j = random_int(0, $i); [$a[$i], $a[$j]] = [$a[$j], $a[$i]]; }
    return $a;
};

$cfg = qc_ai_config();
if ($cfg !== null) {
    $questions = qc_ai_generate($cfg, $text, $title, $count, $types, $limit, $diff, $materials);
    $mode = 'ai';
    $aiConfigured = true;
    $notice = '';
} else {
    $questions = [];
    for ($i = 0, $guard = 0; count($questions) < $count && $guard < $count * 10; $guard++, $i++) {
        $t = $types[$i % count($types)];
        $s = $sentences[$i % count($sentences)];
        $w = $words($s);
        if (!$w) { continue; }
        $key = $w[0];
        $blank = preg_replace('/' . preg_quote($key, '/') . '/u', '_____', $s, 1);
        if ($t === 'essay') {
            $questions[] = ['type' => 'essay', 'q' => 'In your own words, explain this idea from the lesson: "' . $s . '"', 'a' => $s, 'e' => 'Sample answer: ' . $s];
            continue;
        }
        if ($t === 'enum') {
            $items = array_slice(count($w) >= 2 ? $w : $rawTokens($s), 0, 5);
            if (count($items) >= 2) { $questions[] = ['type' => 'enum', 'q' => 'List ' . count($items) . ' key items from this statement: "' . $s . '"', 'a' => $items, 'e' => 'Expected items: ' . implode(', ', $items)]; }
            continue;
        }
        if ($t === 'mc') {
            $pool = [];
            foreach ($shuffle($all) as $candidate) { if (strtolower($candidate) !== strtolower($key) && abs(strlen($candidate) - strlen($key)) <= 4) { $pool[] = $candidate; } }
            if (count($pool) < 3) { foreach ($shuffle($all) as $candidate) { if (strtolower($candidate) !== strtolower($key)) { $pool[] = $candidate; } } }
            $d = array_slice($pool, 0, 3);
            if (count($d) === 3) { $o = $shuffle(array_merge([$key], $d)); $questions[] = ['type' => 'mc', 'q' => 'Choose the term that completes the statement: ' . $blank, 'o' => $o, 'a' => array_search($key, $o, true), 'e' => $s]; }
            continue;
        }
        if ($t === 'id') { $questions[] = ['type' => 'id', 'q' => 'Identify the term or concept described here: "' . $blank . '"', 'a' => $key, 'e' => $s]; }
    }
    if (!$questions) { json_fail(422, 'The study material could not produce questions for the selected types.'); }
    $mode = 'rule_based';
    $aiConfigured = false;
    $notice = 'AI question generation is not configured; these questions were built locally from the submitted material.';
}

echo json_encode([
    'success' => true,
    'questions' => array_slice($questions, 0, $count),
    'count' => min($count, count($questions)),
    'maximum' => $maximum,
    'title' => $title,
    'limit' => $limit,
    'difficulty' => $diff,
    'materials' => $materials,
    'owner' => $me['email'],
    'mode' => $mode,
    'aiConfigured' => $aiConfigured,
    'notice' => $notice,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
