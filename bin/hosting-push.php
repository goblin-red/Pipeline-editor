#!/usr/bin/env php
<?php
/* Залить файлы Гоблина на хостинг (canvas.goblin.red) по FTP из настроек «Публикация на хостинг».
   FTP хостинга пишет не во все папки (в lib/ — отказ 550), поэтому файлы едут в _up/, а на место их
   кладёт разовый public/_mv-<токен>.php — его исполняет PHP сайта, у которого права есть. Потом всё
   временное удаляется. Инструкция — md_backend/ru/хостинг.md.

   php bin/hosting-push.php [--site https://canvas.goblin.red] путь/от/корня …
   Пути — от корня Гоблина: lib/projects/projects.php public/src/api/sync.js … */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/lib/core/config.php';

$root = dirname(__DIR__);
$args = array_slice($argv, 1);
$site = 'https://canvas.goblin.red';
if (($args[0] ?? '') === '--site') { $site = rtrim((string) $args[1], '/'); $args = array_slice($args, 2); }
if (!$args) { echo "нужны пути файлов от корня Гоблина\n"; exit(1); }

$c = config();
$ftp = sprintf('%s://%s:%d/%s', $c['ftp_protocol'] ?: 'ftp', $c['ftp_host'], (int) $c['ftp_port'], trim((string) $c['ftp_root'], '/'));
$ftp = rtrim($ftp, '/') . '/';

/** Один запрос FTP: залить файл ($body) или выполнить команды ($commands). */
function ftp(string $url, ?string $body = null, array $commands = []): bool
{
    global $c;
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_USERPWD => $c['ftp_user'] . ':' . $c['ftp_pass'], CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 120, CURLOPT_FTP_USE_EPSV => false, CURLOPT_FTP_CREATE_MISSING_DIRS => true]);
    if (!$c['ftp_passive']) curl_setopt($ch, CURLOPT_FTPPORT, '-');
    if ($body !== null) {
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, $body);
        rewind($stream);
        curl_setopt_array($ch, [CURLOPT_UPLOAD => true, CURLOPT_INFILE => $stream, CURLOPT_INFILESIZE => strlen($body)]);
    }
    if ($commands) curl_setopt_array($ch, [CURLOPT_POSTQUOTE => $commands, CURLOPT_NOBODY => true]);
    $ok = curl_exec($ch) !== false;
    if (!$ok) fwrite(STDERR, 'FTP: ' . curl_error($ch) . "\n");
    return $ok;
}

// Файлы — в _up/ под простыми именами; куда их положить — map.json.
$map = [];
foreach ($args as $i => $path) {
    $path = ltrim($path, './');
    if (!is_file($root . '/' . $path)) { echo "нет файла: $path\n"; exit(1); }
    if (!ftp($ftp . "_up/f$i.bin", (string) file_get_contents($root . '/' . $path))) exit(1);
    $map["f$i.bin"] = $path;
}
ftp($ftp . '_up/map.json', json_encode($map, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

// Разовый переносчик: пишет PHP сайта; без токена отвечает 404.
$token = bin2hex(random_bytes(16));
$mover = <<<PHP
<?php
header('Content-Type: text/plain; charset=utf-8');
if ((\$_GET['t'] ?? '') !== '$token') { http_response_code(404); exit; }
\$root = dirname(__DIR__);
foreach (json_decode((string) file_get_contents(\$root . '/_up/map.json'), true) as \$from => \$to) {
    if (!is_dir(dirname(\$root . '/' . \$to))) @mkdir(dirname(\$root . '/' . \$to), 0775, true);
    \$ok = @copy(\$root . '/_up/' . \$from, \$root . '/' . \$to);
    if (\$ok && function_exists('opcache_invalidate')) opcache_invalidate(\$root . '/' . \$to, true);
    echo (\$ok ? 'ok   ' : 'FAIL ') . \$to . "\\n";
}
PHP;
ftp($ftp . "public/_mv-$token.php", $mover);
echo (string) file_get_contents("$site/_mv-$token.php?t=$token");

// Уборка временного.
$clean = array_map(static fn(string $name) => "DELE _up/$name", array_keys($map));
ftp($ftp, null, [...$clean, 'DELE _up/map.json', 'RMD _up', "DELE public/_mv-$token.php"]);
echo "временное убрано\n";
