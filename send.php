<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method'], JSON_UNESCAPED_UNICODE);
    exit;
}

$contentLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
if ($contentLength > 200000) {
    http_response_code(413);
    echo json_encode(['ok' => false, 'error' => 'payload'], JSON_UNESCAPED_UNICODE);
    exit;
}

function clean_value($value): string {
    if (is_array($value)) {
        $value = implode(', ', array_map('strval', $value));
    }
    $value = trim(strip_tags((string)$value));
    $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? '';
    if (function_exists('mb_substr')) return mb_substr($value, 0, 3000, 'UTF-8');
    return substr($value, 0, 3000);
}

$contentType = strtolower((string)($_SERVER['CONTENT_TYPE'] ?? ''));
$incoming = null;
if (strpos($contentType, 'application/json') !== false) {
    $decoded = json_decode((string)file_get_contents('php://input'), true);
    if (is_array($decoded)) $incoming = $decoded;
}
if (!is_array($incoming)) $incoming = $_POST;

$fields = [];
foreach ($incoming as $key => $value) {
    $key = clean_value($key);
    $value = clean_value($value);
    if ($key === '' || $value === '') continue;
    $fields[$key] = $value;
}

if (!$fields) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'empty'], JSON_UNESCAPED_UNICODE);
    exit;
}

$kind = $fields['_form_kind'] ?? 'callback';
$page = $fields['_page'] ?? 'SPIROS';
$formLabel = $kind === 'quiz' ? 'Квиз' : 'Обратная связь';
$timestamp = date('d.m.Y H:i:s');

$lines = [
    'Новая заявка с сайта SPIROS',
    'Тип: ' . $formLabel,
    'Страница: ' . $page,
    'Дата: ' . $timestamp,
    ''
];

foreach ($fields as $key => $value) {
    if (isset($key[0]) && $key[0] === '_') continue;
    $lines[] = $key . ': ' . $value;
}

if (!empty($fields['_url'])) {
    $lines[] = '';
    $lines[] = 'URL: ' . $fields['_url'];
}
if (!empty($fields['_form_id'])) {
    $lines[] = 'Форма: ' . $fields['_form_id'];
}

$body = implode("\r\n", $lines);
$to = 'spiros@yandex.ru';
$subjectText = 'SPIROS — новая заявка: ' . $formLabel;
$subject = function_exists('mb_encode_mimeheader')
    ? mb_encode_mimeheader($subjectText, 'UTF-8', 'B', "\r\n")
    : $subjectText;

$host = preg_replace('/:\d+$/', '', (string)($_SERVER['HTTP_HOST'] ?? 'visa-spiros.ru'));
$host = preg_replace('/[^a-zA-Z0-9.\-]/', '', $host) ?: 'visa-spiros.ru';
$headers = "MIME-Version: 1.0\r\n";
$headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
$headers .= "From: SPIROS Website <no-reply@{$host}>\r\n";

$mailSent = false;
if (function_exists('mail')) {
    $mailSent = @mail($to, $subject, $body, $headers);
}

// Backup every lead, even if the host mail service is temporarily unavailable.
$storage = __DIR__ . DIRECTORY_SEPARATOR . 'storage';
if (!is_dir($storage)) @mkdir($storage, 0700, true);
$record = [
    'time' => date(DATE_ATOM),
    'mail_sent' => $mailSent,
    'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
    'fields' => $fields
];
$logFile = $storage . DIRECTORY_SEPARATOR . 'leads-' . date('Y-m') . '.jsonl';
$logged = @file_put_contents(
    $logFile,
    json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL,
    FILE_APPEND | LOCK_EX
) !== false;

if (!$mailSent && !$logged) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'delivery'], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode([
    'ok' => true,
    'mail_sent' => $mailSent,
    'backup_saved' => $logged
], JSON_UNESCAPED_UNICODE);
