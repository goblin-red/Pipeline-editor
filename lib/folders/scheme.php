<?php
/* Схема папки одним ответом: элементы, состав, допы, ассеты, агенты.
   Отдаёт: folderGet(), folderScheme(), gateLinks().
   Не делает: не считает подсветку — она выводится из шагов прогона на клиенте.

   Вид ответа решает shape.php: leader и worker уходит рабочий вид,
   браузеру — полный.

   Ярлыки: folder.get отдаёт и свои ярлыки папки, и входящие — из других
   папок к объектам этой. Остальные вызовы folderScheme() входящих не берут:
   иначе в выгрузке всего проекта один ярлык попал бы в две папки. */

declare(strict_types=1);

/** GET folder.get */
function folderGet(): void
{
    $project = requireProject(false);
    $id = inputInt('folder');
    if (!$id) throw new ApiError(t('server.folder.not_given'), 'not_found');

    $folder = folderRow($id, (int) $project['id']);
    $view = askedView();

    reply([
        'project' => projectShape($project),
        'folder'  => folderShape($folder, $view),
        'scheme'  => array_merge(folderScheme($id, $view, true), gateLinks($id)),
    ]);
}

/**
 * Содержимое одной папки.
 * $incoming — добавить ярлыки из других папок, leader к объектам этой.
 */
function folderScheme(int $folderId, string $view = VIEW_FULL, bool $incoming = false): array
{
    $rows = $incoming
        ? dbAll(
            "SELECT * FROM elements
              WHERE folder_id = ?
                 OR (type = 'link' AND to_id IN (SELECT id FROM elements WHERE folder_id = ?))
              ORDER BY `no`",
            [$folderId, $folderId]
        )
        : dbAll('SELECT * FROM elements WHERE folder_id = ? ORDER BY `no`', [$folderId]);
    $members = membersMap($folderId);
    $props = propsMap($folderId);          // правила прогона нужны обоим видам
    $assets = $view === VIEW_FULL ? assetLinksOfFolder($folderId) : [];
    $specs = specFlags($folderId);
    $ends = linkEndsMap($rows);            // концы всех ярлыков — одним запросом

    $out = [];
    foreach ($rows as $row) {
        $id = (int) $row['id'];
        $out[] = elementShape($row, $view, [
            'in'      => $members[$id] ?? [],
            'props'   => $props[$id] ?? [],
            'assets'  => $assets[$id] ?? [],
            'hasSpec' => !empty($specs[$id]),
            'ends'    => $ends,
        ]);
    }
    return $out;
}

/** У каких элементов папки есть ТЗ. Текст ТЗ приходит отдельно — он длинный.
    Пустое ТЗ — всё равно что его нет: значок MD на карточке врать не должен. */
function specFlags(int $folderId): array
{
    $rows = dbAll(
        "SELECT l.element_id FROM asset_links l
           JOIN elements e ON e.id = l.element_id
           JOIN assets a ON a.id = l.asset_id
          WHERE e.folder_id = ? AND l.role = 'spec' AND TRIM(COALESCE(a.body, '')) <> ''",
        [$folderId]
    );
    $out = [];
    foreach ($rows as $row) $out[(int) $row['element_id']] = true;
    return $out;
}

/** Ограниченная ссылка для иллюстрации: только одна папка, без ключа проекта. */
function folderEmbed(): void
{
    $project = requireProject(false);
    $folder = folderRow(inputInt('folder'), (int) $project['id']);
    $folderId = (int) $folder['id'];
    $signature = hash_hmac('sha256', 'goblin-embed-v1:' . $folderId, (string) $project['url_key']);
    reply(['source' => 'embed.php?folder=' . $folderId . '&signature=' . $signature]);
}

/**
 * Шлюзы между папками — ярлыками «шлюз → вход папки-цели» (стартер, а без него — первый
 * блок): в папке шлюза бирка на шлюзе, в папке-цели — на входе; щелчок — переход.
 * Своей записи нет — только вид; `gate` отличает их от настоящих ярлыков, id отрицательный.
 */
function gateLinks(int $folderId): array
{
    $out = [];
    foreach (dbAll("SELECT id, `no`, title, folder_id, target_folder_id FROM elements
                     WHERE type = 'gateway' AND folder_id <> target_folder_id
                       AND (target_folder_id = ? OR folder_id = ?)", [$folderId, $folderId]) as $g) {
        $target = (int) $g['target_folder_id'];
        $entry = runEntries($target)[0] ?? null;
        if (!$entry) continue;
        $out[] = ['id' => -(int) $g['id'], 'no' => (int) $g['no'], 'type' => 'link', 'title' => '', 'folder' => (int) $g['folder_id'],
                  'from' => (int) $g['id'], 'fromFolder' => (int) $g['folder_id'], 'fromNo' => (int) $g['no'],
                  'fromType' => 'gateway', 'fromTitle' => (string) $g['title'],
                  'to' => (int) $entry['id'], 'toFolder' => $target, 'toNo' => (int) $entry['no'],
                  'toType' => 'block', 'toTitle' => (string) $entry['title'], 'gate' => true];
    }
    return $out;
}
