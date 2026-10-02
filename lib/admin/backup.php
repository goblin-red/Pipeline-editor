<?php
/* Бэкап из админки — скачать кнопкой, без командной строки (на хостинге её нет).
   Отдаёт: backupSendDb(), backupSendSqlite(), backupSendFiles().
   Не делает: не хранит снимков на сервере — только отдаёт файл браузеру. Снимок в папку backup/
   по-прежнему делает `php bin/server.php backup` (md_backend/ru/backup.md).

   База — дамп SQL средствами PHP (mysqldump на хостинге недоступен), сжатый gzip на лету; у SQLite — копия файла.
   Файлы — zip: материалы (data/files), рабочие папки (public/workfiles), настройки из админки и config.txt.
   secrets.php в архив не входит — как и в снимок. */

declare(strict_types=1);

/** Один INSERT — не больше стольких строк и байт: у сервера базы предел пакета (max_allowed_packet, часто 1 МБ). */
const BACKUP_ROWS = 300;
const BACKUP_BYTES = 512 * 1024;

/** Дамп базы: структура и данные всех таблиц, gzip потоком. Восстановить: gunzip | mariadb имя_базы. */
function backupSendDb(): void
{
    @set_time_limit(0);
    if (dbDriver() === 'sqlite') {
        backupSendSqlite();
        return;
    }
    $name = (string) config()['name'];
    header('Content-Type: application/gzip');
    header('Content-Disposition: attachment; filename="goblin-' . $name . '-' . date('Y-m-d_H-i') . '.sql.gz"');
    header('Cache-Control: no-store');
    while (ob_get_level() > 0) ob_end_clean();

    $zip = deflate_init(ZLIB_ENCODING_GZIP, ['level' => 6]);
    $out = static function (string $text, bool $last = false) use ($zip): void {
        echo deflate_add($zip, $text, $last ? ZLIB_FINISH : ZLIB_NO_FLUSH);
    };

    $out("-- Гоблин: дамп базы «$name» из админки, " . date('d.m.Y H:i') . "\n"
        . "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\n"
        // Строки, пережившие каскад ON DELETE SET NULL, проверку CHECK уже не проходят — как и у mysqldump, не проверяем.
        . "/*M!100201 SET check_constraint_checks = 0 */;\n\n");
    $pdo = db();
    foreach ($pdo->query('SHOW FULL TABLES WHERE Table_type = "BASE TABLE"')->fetchAll(PDO::FETCH_NUM) as [$table]) {
        $create = $pdo->query('SHOW CREATE TABLE `' . $table . '`')->fetch(PDO::FETCH_NUM)[1];
        $out("DROP TABLE IF EXISTS `$table`;\n$create;\n\n");

        $rows = [];
        $bytes = 0;
        $query = $pdo->query('SELECT * FROM `' . $table . '`');
        while ($row = $query->fetch(PDO::FETCH_NUM)) {
            $one = '(' . implode(',', array_map(static fn($v) => $v === null ? 'NULL' : $pdo->quote((string) $v), $row)) . ')';
            if ($rows && (count($rows) >= BACKUP_ROWS || $bytes + strlen($one) > BACKUP_BYTES)) {
                $out("INSERT INTO `$table` VALUES\n" . implode(",\n", $rows) . ";\n");
                $rows = [];
                $bytes = 0;
            }
            $rows[] = $one;
            $bytes += strlen($one);
        }
        if ($rows) $out("INSERT INTO `$table` VALUES\n" . implode(",\n", $rows) . ";\n");
        $out("\n");
    }
    $out("SET FOREIGN_KEY_CHECKS = 1;\n/*M!100201 SET check_constraint_checks = 1 */;\n", true);
    exit;
}

/** SQLite: снимок файла базы целиком (VACUUM INTO — на ходу, без остановки), gzip. Восстановить: gunzip и положить на место. */
function backupSendSqlite(): void
{
    $tmp = tempnam(sys_get_temp_dir(), 'goblin-db');
    @unlink($tmp);   // VACUUM INTO пишет только в несуществующий файл
    db()->exec('VACUUM INTO ' . db()->quote($tmp));
    header('Content-Type: application/gzip');
    header('Content-Disposition: attachment; filename="goblin-' . date('Y-m-d_H-i') . '.sqlite.gz"');
    header('Cache-Control: no-store');
    while (ob_get_level() > 0) ob_end_clean();
    $zip = deflate_init(ZLIB_ENCODING_GZIP, ['level' => 6]);
    $in = fopen($tmp, 'rb');
    while (!feof($in)) echo deflate_add($zip, (string) fread($in, 1 << 20), ZLIB_NO_FLUSH);
    echo deflate_add($zip, '', ZLIB_FINISH);
    fclose($in);
    @unlink($tmp);
    exit;
}

/** Файлы установки одним zip: материалы, рабочие папки, настройки. Собирается во временный файл и отдаётся. */
function backupSendFiles(): void
{
    if (!class_exists('ZipArchive')) throw new ApiError(t('admin.backup.no_zip'));
    @set_time_limit(0);
    $root = dirname(__DIR__, 2);
    $c = config();
    $tmp = $root . '/storage/backup-' . bin2hex(random_bytes(6)) . '.zip';
    $zip = new ZipArchive();
    if ($zip->open($tmp, ZipArchive::CREATE) !== true) throw new ApiError(t('admin.backup.no_zip'));

    // Папка на диске → место в архиве. Материалы и рабочие папки могут жить вне проекта (настройки путей).
    $dirs = ['data/files' => (string) $c['files_dir'], 'public/workfiles' => (string) $c['workfiles_dir']];
    foreach ($dirs as $inside => $dir) {
        if (!is_dir($dir)) continue;
        $walk = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
        foreach ($walk as $file) {
            if (!$file->isFile() || $file->getFilename() === '.DS_Store') continue;
            $zip->addFile($file->getPathname(), $inside . substr($file->getPathname(), strlen(rtrim($dir, '/'))));
        }
    }
    foreach (['config_admin.php', 'config.txt'] as $one) {
        if (is_file($root . '/' . $one)) $zip->addFile($root . '/' . $one, $one);
    }
    $zip->close();

    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="goblin-files-' . date('Y-m-d_H-i') . '.zip"');
    header('Content-Length: ' . filesize($tmp));
    header('Cache-Control: no-store');
    while (ob_get_level() > 0) ob_end_clean();
    readfile($tmp);
    @unlink($tmp);
    exit;
}
