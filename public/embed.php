<?php
/** Read-only public illustration, scoped to a signed folder. No tasks, paths or credentials. */
declare(strict_types=1);
header('Access-Control-Allow-Origin: *');
header('X-Content-Type-Options: nosniff');
require __DIR__ . '/../lib/boot.php';
// Чужая страница куки не шлёт: язык подписей — из &lang= (его ставит сниппет), иначе русский.
if (in_array((string) ($_GET['lang'] ?? ''), LANGS, true)) $_COOKIE['goblin_lang'] = (string) $_GET['lang'];
if (isset($_GET['renderer'])) {
    header('Content-Type: text/javascript; charset=utf-8');
    // Словарь подписей renderer'а кладётся перед модулем — он читает его из globalThis.GOBLIN_I18N.
    $dict = array_filter(langDict(), static fn(string $key) => str_starts_with($key, 'editor.embed.'), ARRAY_FILTER_USE_KEY);
    echo 'globalThis.GOBLIN_I18N ??= ', json_encode(['lang' => lang(), 'dict' => $dict], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ";\n";
    readfile(__DIR__ . '/src/embed/render.js');
    exit;
}
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
try {
    $id = (int) ($_GET['folder'] ?? 0);
    $folder = dbRow('SELECT * FROM folders WHERE id = ?', [$id]);
    $project = $folder ? dbRow('SELECT url_key FROM projects WHERE id = ?', [(int) $folder['project_id']]) : null;
    $signature = (string) ($_GET['signature'] ?? '');
    if (!$project || !hash_equals(hash_hmac('sha256', 'goblin-embed-v1:' . $id, (string) $project['url_key']), $signature)) {
        http_response_code(404);
        echo json_encode(['error' => t('server.embed.unavailable')], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $nodes = []; $edges = [];
    foreach (folderScheme($id) as $item) {
        if (in_array($item['type'], ['block', 'decision', 'gateway'], true)) {
            $nodes[] = ['id' => $item['id'], 'type' => $item['type'],
                'title' => $item['title'] ?? '', 'description' => $item['description'] ?? '',
                'start' => !empty($item['props']['start'])];
        } elseif ($item['type'] === 'arrow') {
            $edges[] = ['from' => $item['from'], 'to' => $item['to'], 'back' => !empty($item['back']),
                'label' => ($item['title'] ?? '') ?: (($item['branch'] ?? '') === 'yes' ? t('server.embed.yes') : (($item['branch'] ?? '') === 'no' ? t('server.embed.no') : ''))];
        }
    }
    echo json_encode(['title' => $folder['name'], 'nodes' => $nodes, 'edges' => $edges], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => t('server.embed.load_failed')], JSON_UNESCAPED_UNICODE);
}
