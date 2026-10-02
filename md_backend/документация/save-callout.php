<?php
/* php save-callout.php --page=frontend --key=idea-1 --kind=future_plan --file=/path/text.html --author=Codex */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__, 2) . '/lib/core/config.php';
require_once dirname(__DIR__, 2) . '/lib/core/db.php';
require_once __DIR__ . '/callouts.php';
$args = getopt('', ['page:', 'key:', 'kind:', 'file:', 'author:', 'order:']);
try {
    foreach (['page','key','kind','file'] as $name) {
        if (!isset($args[$name]) || !is_string($args[$name]) || $args[$name] === '') throw new InvalidArgumentException('Нужен --' . $name);
    }
    $html = file_get_contents($args['file']);
    if ($html === false) throw new RuntimeException('Не удалось прочитать файл');
    $id = docSaveCallout($args['page'], $args['key'], $args['kind'], $html, (string) ($args['author'] ?? ''), (int) ($args['order'] ?? 0));
    echo json_encode(['id' => $id, 'page' => $args['page'], 'kind' => $args['kind']], JSON_UNESCAPED_UNICODE) . "\n";
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
