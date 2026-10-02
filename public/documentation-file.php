<?php
declare(strict_types=1);
$root = dirname(__DIR__) . '/md_backend/';
$files = [
    'run-comparison.html' => [$root . 'документация/на_будущее/run-comparison.html', 'text/html'],
    'run-improvements.html' => [$root . 'документация/на_будущее/run-improvements.html', 'text/html'],
    'goblin-run-explained.html' => [$root . 'документация/на_будущее/goblin-run-explained.html', 'text/html'],
    'future-ideas.md' => [$root . 'ru/на_будущее.md', 'text/plain'],
];
$file = $_GET['file'] ?? '';
if (!is_string($file) || !isset($files[$file])) {
    http_response_code(404);
    exit;
}
[$path, $contentType] = $files[$file];
if (!is_file($path)) {
    http_response_code(404);
    exit;
}
header('Content-Type: ' . $contentType . '; charset=utf-8');
header('X-Content-Type-Options: nosniff');
readfile($path);
