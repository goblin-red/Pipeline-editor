<?php
/* Байты материалов: загрузка (в in/ рабочей папки), отдача, список рабочей папки и обзор папок.
   Отдаёт: assetUpload(), assetFile(), assetWorkfiles(), assetWorkfile(),
           assetWorkfileRename(), assetWorkfileDelete(), dirList(), filesGc().
   Не делает: не решает, к чему материал прикреплён (assets/links.php).

   Ссылка больше не пропуск: отдача файла проверяет проект и область токена.
   worker видит только входы и выходы своего шага. */

declare(strict_types=1);

const UPLOAD_LIMIT = 104857600;   // 100 МБ

/** POST asset.upload (multipart): байты ложатся в in/ рабочей папки, запись — в assets. */
function assetUpload(): void
{
    $project = requireProject(true);
    $projectId = (int) $project['id'];
    $files = request()['files'];
    if (!$files || empty($files['tmp_name'])) throw new ApiError(t('server.file.not_received'));

    $names = (array) ($files['name'] ?? []);
    $tmps  = (array) ($files['tmp_name'] ?? []);
    if (!is_array($files['name'])) { $names = [$files['name']]; $tmps = [$files['tmp_name']]; }

    // Читаем присланное до замка: под замком остаётся только запись.
    $items = [];
    foreach ($tmps as $i => $tmp) {
        if (!is_uploaded_file($tmp)) continue;
        $size = filesize($tmp);
        if ($size > UPLOAD_LIMIT) throw new ApiError(t('server.file.too_big'));
        $items[] = ['tmp' => $tmp, 'name' => (string) ($names[$i] ?? 'файл'), 'size' => $size,
                    'mime' => @mime_content_type($tmp) ?: '', 'sha' => hash_file('sha256', $tmp)];
    }
    if (!$items) throw new ApiError(t('server.file.none_saved'));
    filesLimitCheck($project, $items);

    // Ревизия и записи — под замком проекта, как у пачки: параллельная правка не обгонит дельту.
    $done = engineLocked($projectId, static function () use ($projectId, $items) {
        $rev = bumpRev($projectId);
        // Файл ложится только в in/ рабочей папки папки схемы.
        $dir = uploadDir($projectId, $rev);

        $out = [];
        foreach ($items as $item) {
            $path = freeFileName($dir, $item['name']);
            if (!move_uploaded_file($item['tmp'], $path)) throw new ApiError(t('server.file.save_failed'));
            dbOnRollback(static fn() => @unlink($path));   // пачку откатили — файл тоже

            dbRun(
                'INSERT INTO assets (project_id, kind, title, uri, file_key, mime, bytes, sha256, original_name, rev)
                 VALUES (?,?,?,?,?,?,?,?,?,?)',
                [$projectId, fileKindOf($item['name']), $item['name'], $path, null, $item['mime'],
                 $item['size'], $item['sha'], $item['name'], $rev]
            );
            $id = dbId();

            // Сразу прицепить, если сказано куда. Грузили в рабочую папку без привязки
            // (материалы проекта) — файл там и лежит: цепляем к этой папке схемы.
            $link = input('link');
            if (!$link && ($hint = (int) (input('folder') ?: 0))) $link = ['folder' => $hint, 'role' => 'attachment'];
            if ($link) {
                $ctx = ['projectId' => $projectId, 'rev' => $rev, 'refs' => [], 'folders' => [], 'warnings' => []];
                assetLink(array_merge((array) $link, ['asset' => $id]), $ctx);
                foreach (array_unique($ctx['folders']) as $folderId) touchFolder((int) $folderId, $rev);
            }
            $out[] = assetShape(dbRow('SELECT * FROM assets WHERE id = ?', [$id]));
        }
        return ['rev' => $rev, 'assets' => $out];
    });
    reply($done);
}

/** Лимиты материалов на сервере бесплатного аккаунта (веб): ['count' => файлов на человека, 'kb' => размер]; 0 — без лимита. */
function filesLimit(): array
{
    $c = config();
    return ['count' => max(0, (int) ($c['files_limit_count'] ?? 0)), 'kb' => max(0, (int) ($c['files_limit_kb'] ?? 0))];
}

/**
 * Загрузка на сервер в вебе: файл не больше лимита, файлов у человека (владельца проекта; у гостя — в проекте)
 * не больше лимита. Сверх — отказ со словами, что на платном аккаунте лимит снимается (платных пока нет,
 * md_backend/ru/на_будущее.md). Локальная установка — без лимитов: сервер и есть компьютер человека.
 */
function filesLimitCheck(array $project, array $items): void
{
    if (!agentsRemote()) return;
    $limit = filesLimit();
    foreach ($items as $item) {
        if ($limit['kb'] && $item['size'] > $limit['kb'] * 1024) {
            throw new ApiError(t('server.file.over_kb', ['name' => $item['name'], 'kb' => $limit['kb']]), 'conflict');
        }
    }
    if (!$limit['count']) return;
    $base = rtrim((string) (realpath((string) config()['workfiles_dir']) ?: config()['workfiles_dir']), '/') . '/';
    $owner = $project['owner_id'] ? (int) $project['owner_id'] : null;
    $have = (int) dbValue(
        'SELECT COUNT(*) FROM assets a JOIN projects p ON p.id = a.project_id
          WHERE ' . ($owner ? 'p.owner_id = ?' : 'p.id = ?') . ' AND a.body IS NULL AND a.uri LIKE ?',
        [$owner ?: (int) $project['id'], str_replace(['%', '_'], ['\\%', '\\_'], $base) . '%']);
    if ($have + count($items) > $limit['count']) {
        throw new ApiError(t('server.file.over_count', ['n' => $limit['count']]), 'conflict');
    }
}

/**
 * Куда класть загруженное: <рабочая папка>/in/ той папки схемы, к которой
 * файл крепят (link.folder, папка элемента link.element) или откуда грузят (folder).
 * Рабочей папки нет — заводим workspace/<имя папки схемы> и прописываем её папке.
 * Папку схемы не узнать — отказ: файлы живут только в рабочих папках.
 */
function uploadDir(int $projectId, int $rev): string
{
    $link = (array) (input('link') ?: []);
    $folderId = (int) ($link['folder'] ?? 0);
    if (!$folderId && !empty($link['element'])) {
        $folderId = (int) dbValue('SELECT folder_id FROM elements WHERE id = ? AND project_id = ?',
            [(int) $link['element'], $projectId]);
    }
    if (!$folderId) $folderId = (int) (input('folder') ?: 0);
    if (!$folderId) throw new ApiError(t('server.file.open_folder'));

    $folder = folderRow($folderId, $projectId);
    $work = trim((string) $folder['work_dir']);
    if ($work === '') {
        $work = workDirFor($folder);
        dbRun('UPDATE folders SET work_dir = ?, rev = ? WHERE id = ?', [$work, $rev, $folderId]);
    }
    $work = pathMustBeInsideRoots($work, 'work_dir');
    $dir = $work . '/in';
    if (!is_dir($dir) && !mkdir($dir, 0775, true)) throw new ApiError(t('server.file.mkdir_failed', ['dir' => $dir]));
    return $dir;
}

/** Свободное имя файла в папке: «имя.png», занято — «имя (2).png». */
function freeFileName(string $dir, string $name): string
{
    $name = trim(str_replace(['/', '\\', "\0"], '_', basename($name)), ' .') ?: 'файл';
    $ext = pathinfo($name, PATHINFO_EXTENSION);
    $stem = $ext !== '' ? substr($name, 0, -strlen($ext) - 1) : $name;
    $path = $dir . '/' . $name;
    for ($n = 2; file_exists($path); $n++) {
        $path = $dir . '/' . $stem . ' (' . $n . ')' . ($ext !== '' ? '.' . $ext : '');
    }
    return $path;
}

/** GET asset.file&asset=ID — отдать байты с поддержкой докачки. */
function assetFile(): void
{
    $project = requireProject(false);
    $projectId = (int) $project['id'];
    $asset = assetRow((int) inputInt('asset'), $projectId);
    fileAllowed($asset);

    if ($asset['file_key']) {
        $path = config()['files_dir'] . '/' . $asset['file_key'] . '.bin';
    } elseif ($asset['uri'] && is_file((string) $asset['uri'])) {
        // Путь в материале мог записать кто угодно, кто пишет в проект: отдаём только из своих папок.
        $path = pathMustBeInsideRoots((string) $asset['uri'], 'asset_file');
    } else {
        throw new ApiError(t('server.file.no_bytes'), 'not_found');
    }
    if (!is_file($path)) throw new ApiError(t('server.file.lost'), 'not_found');

    fileSend($path, (string) ($asset['original_name'] ?: $asset['title']), (string) $asset['mime']);
}

/** worker видит только входы и выходы своего шага. */
function fileAllowed(array $asset): void
{
    $who = caller();
    if ($who['role'] !== 'worker') return;

    // Пропуск шага знает свой шаг; у долгого пропуска агента step_id пуст —
    // шаг находим так же, как step.get: открытый шаг этого агента.
    $stepId = (int) ($who['step_id'] ?: stepOfAgent((int) $who['project_id'], (int) $who['agent_id']));
    if (!$stepId) throw new ApiError(t('server.file.no_step'), 'scope');

    $ok = dbValue(
        'SELECT l.id FROM asset_links l
          WHERE l.asset_id = ?
            AND (l.step_id = ?
                 OR l.element_id = (SELECT element_id FROM run_steps WHERE id = ?))',
        [$asset['id'], $stepId, $stepId]
    );
    if (!$ok) throw new ApiError(t('server.file.not_your_step'), 'scope');
}

/** Отдача файла: Range, ETag, картинки и видео открываются в браузере. */
function fileSend(string $path, string $name, string $mime): void
{
    $size = filesize($path);
    $etag = '"' . substr(hash_file('crc32b', $path) . dechex($size), 0, 24) . '"';
    $mime = $mime ?: (@mime_content_type($path) ?: 'application/octet-stream');
    /* Прямо в окне показываем только то, что окно не исполняет. HTML и SVG умеют
       выполнить скрипт в адресе самого Гоблина, поэтому уходят файлом на диск. */
    $inline = preg_match('#^(image/(png|jpe?g|gif|webp|avif|bmp|x-icon)|video/|audio/)#', $mime)
        || $mime === 'application/pdf'
        || $mime === 'text/plain';

    header('Content-Type: ' . $mime);
    header('X-Content-Type-Options: nosniff');
    // Файл человека — не часть приложения: своих скриптов и рамок у него быть не может.
    // Видео и звуку sandbox не ставим: плеер Chrome докачивает файл по тому же адресу,
    // а у песочницы нет своего адреса — media-src 'self' такую докачку запрещает.
    $sandbox = preg_match('#^(video|audio)/#', $mime) ? '' : 'sandbox; ';
    header("Content-Security-Policy: {$sandbox}default-src 'none'; img-src 'self' data:; media-src 'self'");
    header('Accept-Ranges: bytes');
    header('ETag: ' . $etag);
    // Сверяться с сервером каждый раз (по ETag это дёшево): иначе удалённый файл ещё долго виден из кэша.
    header('Cache-Control: private, no-cache');
    header(sprintf('Content-Disposition: %s; filename="%s"', $inline ? 'inline' : 'attachment', rawurlencode($name)));

    if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
        http_response_code(304);
        exit;
    }

    $start = 0;
    $end = $size - 1;
    if ($range = (string) ($_SERVER['HTTP_RANGE'] ?? '')) {
        if (!preg_match('/bytes=(\d*)-(\d*)/', $range, $m)) {
            http_response_code(416);
            header("Content-Range: bytes */$size");
            exit;
        }
        $start = $m[1] === '' ? max(0, $size - (int) $m[2]) : (int) $m[1];
        $end = ($m[2] === '' || $m[1] === '') ? $size - 1 : min((int) $m[2], $size - 1);
        if ($start > $end) {
            http_response_code(416);
            header("Content-Range: bytes */$size");
            exit;
        }
        http_response_code(206);
        header("Content-Range: bytes $start-$end/$size");
    }
    header('Content-Length: ' . ($end - $start + 1));

    $fh = fopen($path, 'rb');
    fseek($fh, $start);
    $left = $end - $start + 1;
    while ($left > 0 && !feof($fh)) {
        echo fread($fh, (int) min(262144, $left));
        $left -= 262144;
        flush();
    }
    fclose($fh);
    exit;
}

/** GET asset.workfiles&folder=ID[&sub=in] — что лежит в рабочей папке прогона
    (или только в её подпапке sub: материалы — это in/). dir — сама рабочая папка. */
function assetWorkfiles(): void
{
    $project = requireProject(false);
    $folder = folderRow((int) inputInt('folder'), (int) $project['id']);
    $dir = pathInsideRoots((string) $folder['work_dir']);
    if (!$dir || !is_dir($dir)) reply(['files' => [], 'dir' => (string) $folder['work_dir']]);

    $from = $dir;
    if (($sub = trim((string) input('sub', ''), '/')) !== '') {
        $from = realpath($dir . '/' . $sub);
        if ($from === false || !str_starts_with($from, $dir . '/') || !is_dir($from)) reply(['files' => [], 'dir' => $dir]);
    }

    $out = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($from, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if (!$file->isFile() || count($out) >= 500 || workServicePath($file->getPathname(), $dir)) continue;   // service/ не показываем (Б6)
        $out[] = [
            'path'  => $file->getPathname(),
            'name'  => $file->getFilename(),
            'bytes' => $file->getSize(),
            'kind'  => fileKindOf($file->getFilename()),
            'at'    => dbMoment($file->getMTime()),   // по часам базы, как прочие даты
        ];
    }
    usort($out, static fn($a, $b) => strcmp($b['at'], $a['at']));
    reply(['dir' => $dir, 'files' => $out]);
}

/**
 * GET asset.workfile&folder=ID&path=… — файл рабочей папки: превью и открытие в панели.
 * Только внутри рабочей папки этой папки схемы; служебное прогона (service/) не отдаём —
 * там задания и пропуска шагов.
 */
function assetWorkfile(): void
{
    [, , $path] = workfileTarget(false);
    fileSend($path, basename($path), (string) (@mime_content_type($path) ?: 'application/octet-stream'));
}

/** Служебное прогона (service/: задания и пропуска шагов) — без учёта регистра: на macOS SERVICE/ — та же папка. */
function workServicePath(string $path, string $dir): bool
{
    return str_starts_with(mb_strtolower($path) . '/', mb_strtolower($dir) . '/service/');
}

/**
 * Файл рабочей папки из запроса (folder, path — от рабочей папки): [проект, папка схемы, путь].
 * Только внутри рабочей папки; service/ не трогаем — там задания и пропуска шагов.
 */
function workfileTarget(bool $forWrite): array
{
    $project = requireProject($forWrite);
    $folder = folderRow((int) inputInt('folder'), (int) $project['id']);
    $dir = pathInsideRoots((string) $folder['work_dir']);
    if (!$dir || !is_dir($dir)) throw new ApiError(t('server.file.no_work_dir'), 'not_found');

    $rel = ltrim((string) input('path', ''), '/');
    $path = realpath($dir . '/' . $rel);
    if ($path === false || !str_starts_with($path, $dir . '/') || !is_file($path) || workServicePath($path, $dir)) {
        throw new ApiError(t('server.file.no_such_file'), 'not_found');
    }
    return [$project, $folder, $path];
}

/**
 * POST asset.workfile.rename {folder, path, name} — переименовать файл рабочей папки
 * на месте. Материалы, которые смотрят на этот файл, переезжают вместе с ним.
 */
function assetWorkfileRename(): void
{
    [$project, $folder, $path] = workfileTarget(true);
    $projectId = (int) $project['id'];
    $name = trim(str_replace(['/', '\\', "\0"], '_', (string) input('name', '')), ' ');
    if ($name === '' || $name === '.' || $name === '..') throw new ApiError(t('server.file.empty_name'));
    $to = dirname($path) . '/' . $name;
    if ($to === $path) reply(['path' => $path]);
    if (file_exists($to)) throw new ApiError(t('server.file.name_taken', ['name' => $name]), 'conflict');

    $rev = engineLocked($projectId, static function () use ($projectId, $folder, $path, $to, $name) {
        activeGuardFolders($projectId, [(int) $folder['id']], 'rename_file');
        if (!rename($path, $to)) throw new ApiError(t('server.file.rename_failed'));
        $rev = bumpRev($projectId);
        foreach (dbAll('SELECT id, title FROM assets WHERE project_id = ? AND uri = ?', [$projectId, $path]) as $row) {
            $title = $row['title'] === basename($path) ? $name : $row['title'];
            dbRun('UPDATE assets SET uri = ?, title = ?, rev = ? WHERE id = ?', [$to, $title, $rev, $row['id']]);
            foreach (assetFolders((int) $row['id']) as $folderId) touchFolder((int) $folderId, $rev);
        }
        return $rev;
    });
    reply(['rev' => $rev, 'path' => $to]);
}

/**
 * POST asset.workfile.delete {folder, path} — удалить файл рабочей папки с диска.
 * Материалы на этот файл удаляются вместе с привязками: иначе блоки смотрели бы в пустоту.
 */
function assetWorkfileDelete(): void
{
    [$project, $folder, $path] = workfileTarget(true);
    $projectId = (int) $project['id'];

    $rev = engineLocked($projectId, static function () use ($projectId, $folder, $path) {
        activeGuardFolders($projectId, [(int) $folder['id']], 'delete_file');
        $rev = bumpRev($projectId);
        foreach (dbAll('SELECT id, title FROM assets WHERE project_id = ? AND uri = ?', [$projectId, $path]) as $row) {
            $folders = assetFolders((int) $row['id']);
            dbRun('DELETE FROM assets WHERE id = ?', [$row['id']]);
            markDeleted($projectId, 'asset', (int) $row['id'], $rev, ['title' => $row['title']]);
            foreach ($folders as $folderId) touchFolder((int) $folderId, $rev);
        }
        if (!unlink($path)) throw new ApiError(t('server.file.delete_failed'));
        return $rev;
    });
    reply(['rev' => $rev, 'deleted' => true]);
}

/**
 * GET dir.list[&path=…] — папки для выбора рабочей папки (кнопка «Обзор…»).
 * Только внутри разрешённых корней (file_roots): без path — сами корни,
 * с path — его подпапки. Скрытые (с точки) и ссылки наружу не показываем.
 */
function dirList(): void
{
    requireProject(false);
    $roots = fileRoots();
    $path = trim((string) input('path', ''));
    if ($path === '') {
        reply(['path' => '', 'parent' => null,
               'dirs' => array_map(static fn(string $root) => ['name' => $root, 'path' => $root], $roots)]);
    }

    $dir = pathMustBeInsideRoots($path, 'folder');
    if (!is_dir($dir)) throw new ApiError(t('server.file.no_such_folder', ['path' => $path]), 'not_found');

    $dirs = [];
    foreach (new DirectoryIterator($dir) as $item) {
        $name = $item->getFilename();
        if ($item->isDot() || !$item->isDir() || str_starts_with($name, '.')) continue;
        if (pathInsideRoots($dir . '/' . $name) === null) continue;
        $dirs[] = ['name' => $name, 'path' => $dir . '/' . $name];
        if (count($dirs) >= 500) break;
    }
    usort($dirs, static fn($a, $b) => strnatcasecmp($a['name'], $b['name']));
    // Выше корня не идём: оттуда — обратно к списку корней.
    reply(['path' => $dir, 'parent' => in_array($dir, $roots, true) ? '' : dirname($dir), 'dirs' => $dirs]);
}

/**
 * Байты в data/files, на которые не ссылается ни один материал: сколько их и сколько места.
 * $apply — удалить. Одна уборка для админки и `bin/server.php files-gc`.
 */
function filesGc(bool $apply): array
{
    $used = array_flip(array_column(dbAll('SELECT file_key FROM assets WHERE file_key IS NOT NULL'), 'file_key'));
    $out = ['files' => 0, 'bytes' => 0];
    foreach (glob(config()['files_dir'] . '/*.bin') ?: [] as $file) {
        if (isset($used[basename($file, '.bin')])) continue;
        $out['files']++;
        $out['bytes'] += filesize($file);
        if ($apply) unlink($file);
    }
    return $out;
}
