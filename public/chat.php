<?php
/* Разговор с помощником целиком — отдельной страницей: вопросы, ответы, ход мысли, расход токенов.
   Отдаёт: страницу chat.php?project=КЛЮЧ&chat=N — ссылку даёт кнопка «Ссылка» в чате (panel/ai.js)
   и строка разговора в кабинете (раздел «ИИ»).
   Не делает: ничего не меняет — только чтение; рисует реплики модуль shell/chatpage.js (тот же вид, что в чате). */

declare(strict_types=1);

require __DIR__ . '/../lib/boot.php';
require __DIR__ . '/../lib/web/page.php';

try {
    $project = requireProject(false);
    $chat = dbRow('SELECT c.*, f.name AS folder FROM ai_chats c LEFT JOIN folders f ON f.id = c.folder_id
                    WHERE c.id = ? AND c.project_id = ?', [inputInt('chat'), $project['id']]);
    if (!$chat) throw new ApiError(t('agents.ai.no_chat'), 'not_found');
} catch (ApiError $error) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo $error->getMessage();
    exit;
}

// Реплики и расход каждого ответа — как в ai.chat.get.
$messages = dbAll('SELECT id, role, body, think, created_at FROM ai_messages WHERE chat_id = ? ORDER BY id', [(int) $chat['id']]);
$spent = [];
foreach (dbAll('SELECT message_id, model, tokens_prompt, tokens_completion, tokens_cached FROM ai_jobs
                 WHERE chat_id = ? AND message_id IS NOT NULL', [(int) $chat['id']]) as $job) {
    $spent[(int) $job['message_id']] = ['in' => (int) $job['tokens_prompt'], 'out' => (int) $job['tokens_completion'],
                                        'cached' => (int) $job['tokens_cached'], 'model' => (string) $job['model']];
}
$data = [
    'title'    => (string) ($chat['title'] ?: t('account.ai.chat_default')),
    'project'  => (string) ($project['title'] ?: $project['url_key']),
    'key'      => (string) $project['url_key'],
    'folder'   => (string) ($chat['folder'] ?? ''),
    'at'       => substr((string) $chat['created_at'], 0, 16),
    'messages' => array_map(static fn(array $m) => [
        'role' => (string) $m['role'], 'body' => (string) $m['body'], 'think' => (string) ($m['think'] ?? ''),
        'at' => substr((string) $m['created_at'], 0, 16), 'tokens' => $spent[(int) $m['id']] ?? null,
    ], $messages),
];

$root = dirname(__DIR__);
$version = editorVersion($root);
header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html>
<html lang="<?= h(lang()) ?>" data-theme="dark">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<!-- Тема — как в редакторе и админке: выбор человека в этом браузере (goblin-theme), до первой отрисовки. -->
<script>try { const theme = localStorage.getItem('goblin-theme'); if (theme) document.documentElement.dataset.theme = theme; } catch {}</script>
<title><?= h($data['title']) ?> · Goblin</title>
<link rel="stylesheet" href="css/tokens.css?v=<?= $version ?>">
<link rel="stylesheet" href="css/base.css?v=<?= $version ?>">
<link rel="stylesheet" href="css/panel.css?v=<?= $version ?>">
<style>
  /* base.css держит страницу редактора без прокрутки — здесь обычная длинная страница. */
  html,body{height:auto;overscroll-behavior:auto}
  body{background:var(--bg);color:var(--ink);margin:0;overflow:auto}
  .chat-page{max-width:860px;margin:0 auto;padding:32px 20px 60px}
  .chat-page h1{font-size:var(--f-xl);margin:0 0 6px}
  .chat-page .chat-meta{color:var(--ink-dim);font-size:var(--f-sm);margin:0 0 22px}
  .chat-page .chat-meta a{color:inherit}
  .chat-page .ai-log{overflow:visible;padding:0;gap:14px}
  .chat-page .chat-at{display:block;color:var(--ink-faint);font-size:var(--f-xs);margin-top:4px}
</style>
<?= langScript(['common', 'editor']) ?>
<script type="importmap"><?= importMap($root, $version) ?></script>
</head>
<body>
<main class="chat-page">
  <h1><?= h($data['title']) ?></h1>
  <p class="chat-meta">
    <?= h(t('editor.chatpage.meta', ['project' => $data['project'], 'folder' => $data['folder'] ?: '—', 'at' => $data['at']])) ?>
    · <a href="index.php#p=<?= h(rawurlencode($data['key'])) ?>"><?= h(t('editor.chatpage.open_scheme')) ?></a>
  </p>
  <div class="ai-log" id="chat-log"></div>
</main>
<script>window.GOBLIN_CHAT = <?= json_encode($data, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;</script>
<script type="module">import 'goblin/shell/chatpage.js';</script>
</body>
</html>
