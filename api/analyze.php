<?php
require_once __DIR__ . '/db_connect.php';
require_method('POST');
require_login();

$b = read_json_body();
$text = (string)($b['text'] ?? '');
$text = preg_replace('/\s+/u', ' ', trim($text));
$length = function_exists('mb_strlen') ? mb_strlen($text) : strlen($text);

$empty = function (string $reason = '') {
    echo json_encode([
        'success' => true,
        'maximum' => 0,
        'breakdown' => ['sentences' => 0, 'concepts' => 0, 'definitions' => 0, 'topics' => 0],
        'reason' => $reason,
    ]);
    exit;
};

if ($length < 40) { $empty('Not enough study material to analyze.'); }
if ($length > 200000) { $text = function_exists('mb_substr') ? mb_substr($text, 0, 200000) : substr($text, 0, 200000); }

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
    $count = count($words);
    if ($count >= 2 && $count <= 10 && !preg_match('/[.!?]$/u', $line)) { $addTerm($line); }
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

$maximum = count($units) + count($concepts) + count($definitions) + count($topics);
$maximum = min(200, $maximum);
if ($maximum === 0) { $empty('No distinct topics, definitions, or concepts were found.'); }

echo json_encode([
    'success' => true,
    'maximum' => $maximum,
    'breakdown' => [
        'sentences' => count($units),
        'concepts' => count($concepts),
        'definitions' => count($definitions),
        'topics' => count($topics),
    ],
]);
