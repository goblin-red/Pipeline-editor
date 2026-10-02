<?php
/* Ассеты: материалы проекта. Живут сами по себе и крепятся к чему угодно.
   Отдаёт: assetRow(), assetShape(), assetGet(), assetCreate(), assetUpdate(), assetDelete().
   Не делает: не привязывает (assets/links.php) и не отдаёт байты (assets/files.php).

   Тип (kind) — обычная строка из config.txt [asset_kinds]: новый тип не требует миграции.
   Источник ровно один: адрес (uri), загруженные байты (file_key) или текст (body). */

declare(strict_types=1);

function assetRow(int $id, int $projectId): array
{
    $row = dbRow('SELECT * FROM assets WHERE id = ?', [$id]);
    return sameProject($row, $projectId, 'asset');
}

function assetShape(array $row, bool $withBody = false): array
{
    $out = [
        'id'    => (int) $row['id'],
        'kind'  => (string) $row['kind'],
        'title' => (string) $row['title'],
        'rev'   => (int) $row['rev'],
        'createdAt' => (string) $row['created_at'],
    ];
    if ($row['uri'] !== null)      $out['uri'] = (string) $row['uri'];
    // Файл на диске убрали или переименовали мимо Гоблина — материал смотрит в пустоту.
    if ($row['file_key'] === null && str_starts_with((string) $row['uri'], '/') && !is_file((string) $row['uri'])) {
        $out['missing'] = true;
    }
    if ($row['file_key'] !== null) $out['file'] = true;
    // Путь от папки схемы — файл у агента в вебе (assetAtAgent): байтов на сервере нет.
    if (assetAtAgentUri($row['file_key'], $row['uri'])) $out['agent'] = true;
    if ($row['mime'] !== '')       $out['mime'] = (string) $row['mime'];
    if ($row['bytes'] !== null)    $out['bytes'] = (int) $row['bytes'];
    if ($row['original_name'])     $out['name'] = (string) $row['original_name'];
    if ($row['body'] !== null)     $out['text'] = $withBody ? (string) $row['body'] : true;
    return $out;
}

/** GET asset.get — библиотека проекта или один материал с текстом. */
function assetGet(): void
{
    $project = requireProject(false);
    $projectId = (int) $project['id'];

    if ($id = inputInt('asset')) {
        $row = assetRow($id, $projectId);
        fileAllowed($row);   // worker — только материалы своего шага, как asset.file (Б6)
        reply(['asset' => assetShape($row, true), 'links' => assetLinksOf($id)]);
    }

    // Галерея папки включает её вложения, элементы и результаты прогонов.
    // EXISTS оставляет один материал одной строкой при нескольких привязках.
    if (input('scope') === 'library') {
        $args = [$projectId];
        $where = '';
        if ($folderId = inputInt('folder')) {
            folderRow($folderId, $projectId);
            $where = ' AND EXISTS (
                SELECT 1 FROM asset_links l
                LEFT JOIN elements e ON e.id = l.element_id
                LEFT JOIN run_steps s ON s.id = l.step_id
                LEFT JOIN runs r ON r.id = COALESCE(l.run_id, s.run_id)
                WHERE l.asset_id = a.id AND l.project_id = a.project_id
                  AND (l.folder_id = ? OR e.folder_id = ? OR r.folder_id = ?)
            )';
            array_push($args, $folderId, $folderId, $folderId);
        }
        $rows = dbAll("SELECT a.* FROM assets a WHERE a.project_id = ?$where ORDER BY a.updated_at DESC, a.id DESC", $args);
        $assets = array_map(static fn(array $row) => assetShape($row), $rows);
        if (!$folderId) {
            // Весь проект: у каждого материала — папки, где он привязан (разделы в панели).
            $in = [];
            $links = dbAll('SELECT DISTINCT l.asset_id, COALESCE(l.folder_id, e.folder_id, r.folder_id) AS folder
                FROM asset_links l
                LEFT JOIN elements e ON e.id = l.element_id
                LEFT JOIN run_steps s ON s.id = l.step_id
                LEFT JOIN runs r ON r.id = COALESCE(l.run_id, s.run_id)
                WHERE l.project_id = ?', [$projectId]);
            foreach ($links as $link) {
                if ($link['folder'] !== null) $in[(int) $link['asset_id']][(int) $link['folder']] = true;
            }
            foreach ($assets as &$asset) $asset['folders'] = array_keys($in[$asset['id']] ?? []);
            unset($asset);
        }
        reply(['assets' => $assets]);
    }

    // Материалы одного владельца: ?element= или ?folder=, можно с ролью.
    $ownerSql = '';
    $args = [$projectId];
    foreach (['element' => 'element_id', 'folder' => 'folder_id', 'run' => 'run_id', 'step' => 'step_id'] as $name => $column) {
        if ($owner = inputInt($name)) {
            $ownerSql = " AND l.$column = ?";
            $args[] = $owner;
            break;
        }
    }
    if ($ownerSql !== '') {
        $role = (string) (input('role') ?? '');
        if ($role !== '') {
            $ownerSql .= ' AND l.role = ?';
            $args[] = $role;
        }
        $rows = dbAll(
            "SELECT a.*, l.role, l.output, l.id AS link_id
               FROM asset_links l JOIN assets a ON a.id = l.asset_id
              WHERE l.project_id = ?$ownerSql ORDER BY l.sort, l.id",
            $args
        );
        $withBody = $role === 'spec' || !empty(input('text'));
        reply(['assets' => array_map(
            static fn(array $row) => assetShape($row, $withBody) + ['role' => $row['role'], 'output' => $row['output'], 'link' => (int) $row['link_id']],
            $rows
        )]);
    }

    $rows = dbAll('SELECT * FROM assets WHERE project_id = ? ORDER BY updated_at DESC LIMIT 500', [$projectId]);
    reply(['assets' => array_map(static fn(array $row) => assetShape($row), $rows)]);
}

/**
 * Адрес материала: ссылка наружу (http, s3 и прочее) — как есть, путь на диске — только
 * из рабочих папок этого проекта. Иначе материалом можно было бы назвать любой файл сервера
 * или чужого проекта и скачать его через `asset.file`.
 */
function assetUri(string $uri, int $projectId): string
{
    $uri = trim($uri);
    if ($uri === '' || !str_starts_with($uri, '/')) return $uri;
    return pathMustBeInProject($uri, $projectId, 'asset_path');
}

/** Операция asset.create — текст, ссылка или путь. Байты приходят через asset.upload. */
function assetCreate(array $op, array &$ctx): array
{
    $projectId = $ctx['projectId'];
    $kind = assetKind((string) ($op['kind'] ?? 'file'));

    $uri = isset($op['uri']) ? assetUri((string) $op['uri'], $projectId) : null;
    $body = isset($op['text']) ? (string) $op['text'] : null;
    if ($uri === null && $body === null) throw new ApiError(t('server.asset.no_source'));
    /* Файл у человека (веб, галочка проекта «Хранить на сервере» снята): браузер положил его в in/ папки схемы,
       сюда приходит только путь от неё. Вид и тип — по расширению, байтов на сервере нет. */
    $local = $body === null && $kind !== 'link' && $uri !== null && assetAtAgentUri(null, $uri);
    if ($local) {
        if (str_contains($uri, '..') || str_contains($uri, '\\')) throw new ApiError(t('server.asset.bad_local_path'));
        $kind = fileKindOf($uri);
    }

    // Файл с диска, на который запись уже есть (загружен или уже прикрепляли), — та же запись.
    if ($body === null && str_starts_with((string) $uri, '/')) {
        $same = dbValue('SELECT id FROM assets WHERE project_id = ? AND uri = ? ORDER BY id LIMIT 1', [$projectId, $uri]);
        if ($same) {
            if (!empty($op['link'])) assetLink(array_merge((array) $op['link'], ['asset' => (int) $same]), $ctx);
            return ['id' => (int) $same];
        }
    }

    dbRun(
        'INSERT INTO assets (project_id, kind, title, uri, body, mime, bytes, rev) VALUES (?,?,?,?,?,?,?,?)',
        [$projectId, $kind, mb_substr((string) ($op['title'] ?? ''), 0, 255), $uri, $body,
         (string) ($op['mime'] ?? ($local ? (MIME_BY_EXT[strtolower(pathinfo((string) $uri, PATHINFO_EXTENSION))] ?? '') : '')),
         $local && isset($op['bytes']) ? max(0, (int) $op['bytes']) : null, $ctx['rev']]
    );
    $id = dbId();

    // Сразу прицепить, если сказано куда.
    if (!empty($op['link'])) {
        assetLink(array_merge((array) $op['link'], ['asset' => $id]), $ctx);
    }
    return ['id' => $id];
}

/** Операция asset.update. Правка текста ТЗ двигает ревизию папок, где он висит. */
function assetUpdate(array $op, array &$ctx): array
{
    $projectId = $ctx['projectId'];
    $row = assetRow((int) ($op['id'] ?? 0), $projectId);
    activeGuardAsset($projectId, (int) $row['id'], 'edit_asset');

    $set = ['rev = ?'];
    $args = [$ctx['rev']];
    $prev = [];

    foreach (['title' => 'title', 'uri' => 'uri', 'mime' => 'mime'] as $from => $column) {
        if (!array_key_exists($from, $op)) continue;
        $prev[$from] = $row[$column];
        $set[] = "$column = ?";
        $args[] = $op[$from] === null ? null
            : ($from === 'uri' ? assetUri((string) $op[$from], $projectId) : (string) $op[$from]);
    }
    if (array_key_exists('kind', $op)) {
        $prev['kind'] = $row['kind'];
        $set[] = 'kind = ?';
        $args[] = assetKind((string) $op['kind']);
    }
    if (array_key_exists('text', $op)) {
        $prev['text'] = mb_strimwidth((string) $row['body'], 0, 200, '…');
        $set[] = 'body = ?';
        $args[] = $op['text'] === null ? null : (string) $op['text'];
    }
    $args[] = (int) $row['id'];
    dbRun('UPDATE assets SET ' . implode(', ', $set) . ' WHERE id = ?', $args);

    foreach (assetFolders((int) $row['id']) as $folderId) $ctx['folders'][] = $folderId;
    return ['id' => (int) $row['id'], 'prev' => $prev ?: null];
}

/** Операция asset.delete. Используемый материал удаляется только с force. */
function assetDelete(array $op, array &$ctx): array
{
    $row = assetRow((int) ($op['id'] ?? 0), $ctx['projectId']);
    activeGuardAsset((int) $ctx['projectId'], (int) $row['id'], 'delete_asset');
    $used = (int) dbValue('SELECT COUNT(*) FROM asset_links WHERE asset_id = ?', [$row['id']]);
    if ($used > 0 && empty($op['force'])) {
        throw new ApiError(tn('server.asset.attached', (int) $used), 'conflict');
    }
    $folders = assetFolders((int) $row['id']);
    dbRun('DELETE FROM assets WHERE id = ?', [$row['id']]);
    markDeleted($ctx['projectId'], 'asset', (int) $row['id'], $ctx['rev'], ['title' => $row['title']]);

    if ($row['file_key']) {
        // Байты стираем после фиксации: откатят пачку — материал должен остаться с файлом.
        $file = config()['files_dir'] . '/' . $row['file_key'] . '.bin';
        dbAfterCommit(static function () use ($file) { if (is_file($file)) @unlink($file); });
    }
    foreach ($folders as $folderId) $ctx['folders'][] = $folderId;
    return ['id' => (int) $row['id'], 'deleted' => true];
}

function assetKind(string $kind): string
{
    if (!listHas('asset_kinds', $kind)) throw new ApiError(t('server.asset.no_kind', ['kind' => $kind]));
    return $kind === '' ? 'file' : $kind;
}

/** В каких папках этот материал показывается — их содержимое считается изменённым. */
function assetFolders(int $assetId): array
{
    $rows = dbAll(
        'SELECT DISTINCT COALESCE(e.folder_id, l.folder_id) AS folder_id
           FROM asset_links l LEFT JOIN elements e ON e.id = l.element_id
          WHERE l.asset_id = ?',
        [$assetId]
    );
    return array_values(array_filter(array_map(static fn($r) => (int) $r['folder_id'], $rows)));
}
