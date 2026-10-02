<?php
/* Установщик в браузере: одна страница — проверки сервера, вопросы, кнопка «Установить».
   Работает, пока Гоблин не поставлен (lib/install/install.php: installNeeded), потом — 404.
   Та же установка из Терминала: php bin/server.php install. */

declare(strict_types=1);

require dirname(__DIR__) . '/lib/boot.php';
require dirname(__DIR__) . '/lib/web/page.php';   // h()
require dirname(__DIR__) . '/lib/install/install.php';

if (!installNeeded()) {
    http_response_code(404);
    exit;
}
// Язык страницы — ссылкой ?lang=, дальше помнит cookie (как у всего Гоблина).
if (in_array($_GET['lang'] ?? '', LANGS, true)) {
    setcookie('goblin_lang', $_GET['lang'], ['expires' => time() + 31536000, 'path' => '/', 'samesite' => 'Lax']);
    $_COOKIE['goblin_lang'] = $_GET['lang'];
}

$checks = installChecks();
$ready = !in_array(false, array_column($checks, 'ok'), true);
$in = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : [];
$done = null;
$error = '';
if ($in && $ready) {
    try {
        $done = installRun($in + ['lang' => lang()]);
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}
$v = static fn(string $key, string $or = '') => h((string) ($in[$key] ?? $or));
$on = static fn(string $key, string $value, bool $default = false) => (($in[$key] ?? null) === $value || (!isset($in[$key]) && $default)) ? ' checked' : '';
// Под Apache страница видна как /goblin/public/install.php, а адрес Гоблина — /goblin/ (корневой .htaccess).
$base = (string) preg_replace('~/public$~', '', rtrim(dirname($_SERVER['SCRIPT_NAME']), '/'));
// Полный адрес установки — его и показываем: по одному «/» не понять, куда идти.
$site = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http') . '://'
    . preg_replace('/[^\w.:\[\]-]/', '', (string) ($_SERVER['HTTP_HOST'] ?? 'localhost')) . $base;
?><!doctype html>
<html lang="<?= h(lang()) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h(t('install.title')) ?></title>
<link rel="stylesheet" href="css/tokens.css">
<link rel="stylesheet" href="css/base.css">
<style>
  body{background:var(--bg);color:var(--ink);font:14px/1.5 var(--sans,system-ui);margin:0}
  main{max-width:680px;margin:0 auto;padding:32px 16px 64px}
  h1{font-size:24px;margin:0 0 4px}
  .lead{color:var(--ink-dim);margin:0 0 20px}
  .card{border:1px solid var(--line);border-radius:12px;background:var(--surface);padding:16px 18px;margin-bottom:14px}
  .card h2{font-size:14px;margin:0 0 10px}
  .checks{display:grid;gap:4px;margin:0;padding:0;list-style:none;font-size:13px}
  .checks li::before{content:"✓ ";color:var(--ok)}
  .checks li.bad::before{content:"✗ ";color:var(--bad)}
  .checks small{color:var(--ink-faint)}
  .choice{display:flex;gap:14px;flex-wrap:wrap;margin-bottom:8px}
  .choice label{display:flex;gap:6px;align-items:center;cursor:pointer}
  .field{display:grid;gap:4px;margin:8px 0}
  .field span{font-size:12px;color:var(--ink-dim)}
  .row{display:grid;grid-template-columns:2fr 1fr;gap:10px}
  .hint{font-size:12px;color:var(--ink-faint);margin:6px 0 0}
  .err{border-color:var(--bad);color:var(--bad)}
  .ok{border-color:var(--ok)}
  code{font-family:var(--mono);font-size:12px;background:var(--surface-2);padding:1px 5px;border-radius:4px}
  .langs{float:right;font-size:13px}
  .langs a{color:var(--ink-dim);margin-left:8px}
  .card a{color:var(--accent)}
  .go{width:100%;height:42px;font-size:15px}
  [hidden]{display:none!important}
  @media (max-width:520px){.row{grid-template-columns:1fr}}
</style>
</head>
<body>
<main>
  <nav class="langs"><a href="?lang=ru">Русский</a><a href="?lang=en">English</a></nav>
  <h1><?= h(t('install.title')) ?></h1>
  <p class="lead"><?= h(t('install.lead')) ?></p>

<?php if ($done !== null): ?>
  <section class="card ok">
    <h2><?= h(t('install.done.title')) ?></h2>
    <p><?= h(t('install.done.editor')) ?> <a href="<?= h($site . '/') ?>"><?= h($site . '/') ?></a></p>
    <p><?= h(t('install.done.admin')) ?> <a href="<?= h($site . '/admin.php') ?>"><?= h($site . '/admin.php') ?></a></p>
    <?php if (($in['where'] ?? '') === 'local' && ($in['serve'] ?? '') === 'php'): ?>
      <p class="hint"><?= h(t('install.done.serve')) ?> <code>php bin/server.php serve</code></p>
    <?php endif; ?>
    <?php foreach ($done['notes'] as $note): ?><p class="hint"><?= h($note) ?></p><?php endforeach; ?>
  </section>
<?php else: ?>
  <section class="card<?= $ready ? '' : ' err' ?>">
    <h2><?= h(t('install.checks')) ?></h2>
    <ul class="checks">
      <?php foreach ($checks as $c): ?>
        <li class="<?= $c['ok'] ? '' : 'bad' ?>"><?= h($c['what']) ?> <small><?= h($c['say']) ?></small></li>
      <?php endforeach; ?>
    </ul>
    <?php if (!$ready): ?><p class="hint"><?= h(t('install.checks_fix')) ?></p><?php endif; ?>
  </section>

  <?php if ($error !== ''): ?><section class="card err"><?= h($error) ?></section><?php endif; ?>

  <form method="post" id="install"<?= $ready ? '' : ' hidden' ?>>
    <section class="card">
      <h2><?= h(t('install.where')) ?></h2>
      <div class="choice">
        <label><input type="radio" name="where" value="local"<?= $on('where', 'local', true) ?>> <?= h(t('install.where_local')) ?></label>
        <label><input type="radio" name="where" value="web"<?= $on('where', 'web') ?>> <?= h(t('install.where_web')) ?></label>
      </div>
      <div data-show="local">
        <div class="choice">
          <label><input type="radio" name="serve" value="php"<?= $on('serve', 'php', true) ?>> <?= h(t('install.serve_php')) ?></label>
          <label><input type="radio" name="serve" value="apache"<?= $on('serve', 'apache') ?>> <?= h(t('install.serve_apache')) ?></label>
        </div>
        <p class="hint"><?= h(t('install.serve_hint')) ?></p>
      </div>
    </section>

    <section class="card">
      <h2><?= h(t('install.db')) ?></h2>
      <div class="choice">
        <label><input type="radio" name="driver" value="sqlite"<?= $on('driver', 'sqlite', true) ?>> SQLite</label>
        <label><input type="radio" name="driver" value="mysql"<?= $on('driver', 'mysql') ?>> MySQL / MariaDB</label>
      </div>
      <div data-show="sqlite">
        <label class="field"><span><?= h(t('install.sqlite_file')) ?></span>
          <input class="input" name="sqlite_file" value="<?= $v('sqlite_file', 'data/goblin.sqlite') ?>"></label>
        <p class="hint"><?= h(t('install.sqlite_hint')) ?></p>
      </div>
      <div data-show="mysql">
        <div class="row">
          <label class="field"><span><?= h(t('install.host')) ?></span><input class="input" name="host" value="<?= $v('host', 'localhost') ?>"></label>
          <label class="field"><span><?= h(t('install.port')) ?></span><input class="input" name="port" value="<?= $v('port', '3306') ?>"></label>
        </div>
        <label class="field"><span><?= h(t('install.name')) ?></span><input class="input" name="name" value="<?= $v('name', 'goblin') ?>"></label>
        <div class="row">
          <label class="field"><span><?= h(t('install.user')) ?></span><input class="input" name="user" value="<?= $v('user', 'root') ?>" autocomplete="off"></label>
          <label class="field"><span><?= h(t('install.pass')) ?></span><input class="input" type="password" name="pass" autocomplete="new-password"></label>
        </div>
        <label class="choice"><span><input type="checkbox" name="create" value="1"<?= !$in || !empty($in['create']) ? ' checked' : '' ?>> <?= h(t('install.create')) ?></span></label>
      </div>
    </section>

    <section class="card">
      <h2><?= h(t('install.admin')) ?></h2>
      <div class="row">
        <label class="field"><span><?= h(t('install.admin_login')) ?></span><input class="input" name="admin_login" value="<?= $v('admin_login', 'admin') ?>" required></label>
        <label class="field"><span><?= h(t('install.admin_pass')) ?></span><input class="input" type="password" name="admin_pass" minlength="8" required autocomplete="new-password"></label>
      </div>
      <p class="hint"><?= h(t('install.admin_hint')) ?></p>
    </section>

    <section class="card">
      <h2><?= h(t('install.keys')) ?></h2>
      <p class="hint" style="margin:0 0 6px"><?= h(t('install.keys_hint')) ?></p>
      <label class="field"><span>DeepSeek</span><input class="input" name="ai_key_deepseek" value="<?= $v('ai_key_deepseek') ?>" placeholder="—" autocomplete="off"></label>
      <label class="field"><span>OpenRouter</span><input class="input" name="ai_key_openrouter" value="<?= $v('ai_key_openrouter') ?>" placeholder="—" autocomplete="off"></label>
      <label class="field"><span><?= h(t('install.key_voice')) ?></span><input class="input" name="voice_api_key" value="<?= $v('voice_api_key') ?>" placeholder="—" autocomplete="off"></label>
      <label class="field"><span>Jev (TypeSafe)</span><input class="input" name="jev_api_key" value="<?= $v('jev_api_key') ?>" placeholder="—" autocomplete="off"></label>
    </section>

    <button class="btn btn-accent go" type="submit"><?= h(t('install.go')) ?></button>
  </form>
<?php endif; ?>
</main>
<script>
  // Видны только поля выбранного: «на этом компьютере» — как запускать; база — свои поля.
  // По умолчанию: на этом компьютере — SQLite, на сайте — MySQL (пока человек не выбрал базу сам).
  const form = document.getElementById('install');
  let pickedDb = <?= isset($in['driver']) ? 'true' : 'false' ?>;
  const sync = () => {
    if (!form) return;
    const where = form.where.value;
    form.querySelectorAll('[data-show]').forEach((box) => {
      const want = box.dataset.show;
      box.hidden = want === 'local' ? where !== 'local' : form.driver.value !== want;
    });
  };
  form?.addEventListener('change', (event) => {
    if (event.target.name === 'driver') pickedDb = true;
    if (event.target.name === 'where' && !pickedDb) form.driver.value = form.where.value === 'web' ? 'mysql' : 'sqlite';
    sync();
  });
  sync();
</script>
</body>
</html>
