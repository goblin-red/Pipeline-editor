<?php
/* Привязки: где материал прикреплён и зачем.
   Отдаёт: assetLink(), assetRole(), assetUnlink(), assetLinksOf(), assetLinksOfFolder(),
           LINK_ROLES.
   Не делает: не хранит сам материал (assets.php).

   Роль ограничивает не тип элемента, а смысл: ТЗ — одно на владельца,
   результат и превью принадлежат шагу прогона, остальное можно вешать куда угодно. */

declare(strict_types=1);

const LINK_ROLES = [
    'spec'       => 'ТЗ — что нужно сделать',
    'code'       => 'код рядом с блоком',
    'input'      => 'вход, исходник',
    'reference'  => 'образец или справка',
    'cover'      => 'обложка карточки',
    'attachment' => 'всё прочее',
    'result'     => 'результат работы',
    'preview'    => 'превью результата',
];

/** Операция asset.link. Повторная привязка того же в то же место ничего не ломает. */
function assetLink(array $op, array &$ctx): array
{
    if (!dbInTransaction()) {
        return dbTransaction(static function () use ($op, &$ctx): array {
            dbRow('SELECT id FROM projects WHERE id = ? FOR UPDATE', [(int) $ctx['projectId']]);
            return assetLink($op, $ctx);
        });
    }
    $projectId = $ctx['projectId'];
    $asset = assetRow((int) resolveAssetRef($op['asset'] ?? null, $ctx), $projectId);
    $role = (string) ($op['role'] ?? 'attachment');
    if (!isset(LINK_ROLES[$role])) throw new ApiError(t('server.link.unknown_role', ['role' => $role]));

    [$column, $ownerId, $folderId] = linkOwner($op, $ctx);
    if (in_array($role, ['spec', 'input', 'reference'], true) && $folderId) {
        activeGuardFolders($projectId, [$folderId], 'edit_assets');
    }
    if ($role === 'cover' && $column === 'element_id' && $folderId
        && !activeGuardRuntimeCover($ctx, $op, $ownerId)) {
        activeGuardFolders($projectId, [$folderId], 'edit_cover');
    }

    // Результат и превью принадлежат прогону и шагу, а не схеме.
    if (in_array($role, ['result', 'preview'], true) && !in_array($column, ['run_id', 'step_id'], true)) {
        throw new ApiError(t('server.link.result_step'));
    }
    // Ромб ничего не производит: у развилки бывает только ТЗ — критерий выбора.
    if ($column === 'element_id' && $role !== 'spec'
        && (string) dbValue('SELECT type FROM elements WHERE id = ?', [$ownerId]) === 'decision') {
        throw new ApiError(t('server.link.decision_spec_only'));
    }
    // ТЗ ровно одно на владельца: два разных задания у одного блока — это путаница.
    if ($role === 'spec') {
        $old = dbRow("SELECT id, asset_id FROM asset_links WHERE project_id = ? AND role = 'spec' AND $column = ?", [$projectId, $ownerId]);
        if ($old) {
            dbRun('DELETE FROM asset_links WHERE id = ?', [$old['id']]);
            // Прежнее ТЗ не пропадает: оно остаётся в хранилище папки.
            keepInFolder($projectId, (int) $old['asset_id'], $folderId);
        }
    }
    /* Обложка одна — но на каждую сторону своя.

       У блока бывают две разные обложки: оформление схемы, которое поставил
       человек, и картинка-результат, которую принёс прогон. Раньше вторая
       вытесняла первую в обычные вложения, и после нового прогона исходное
       оформление было уже не найти. Теперь они не спорят: своя обложка
       вытесняет только свою, а холст показывает прогонную, пока она есть. */
    if ($role === 'cover') {
        $mine = !empty($op['madeByRun']) ? 'made_by_run IS NOT NULL' : 'made_by_run IS NULL';
        dbRun("UPDATE asset_links SET role = 'attachment'
                WHERE project_id = ? AND role = 'cover' AND $column = ? AND $mine",
              [$projectId, $ownerId]);
    }

    $same = dbRow(
        "SELECT id FROM asset_links WHERE project_id = ? AND asset_id = ? AND role = ? AND output = ? AND $column = ?",
        [$projectId, $asset['id'], $role, (string) ($op['output'] ?? ''), $ownerId]
    );
    if ($same) return ['id' => (int) $same['id'], 'repeat' => true, 'folder_id' => $folderId];

    $sort = isset($op['sort']) ? (int) $op['sort']
        : (int) dbValue("SELECT COALESCE(MAX(sort), -1) + 1 FROM asset_links WHERE $column = ?", [$ownerId]);

    dbRun(
        "INSERT INTO asset_links (project_id, asset_id, role, output, sort, $column, made_by_run)
         VALUES (?,?,?,?,?,?,?)",
        /* Последнее поле — пометка происхождения: эту привязку сделал прогон,
           а не человек. Ключ отдельный (`madeByRun`, не `run`): `run` в этой
           операции означает владельца, и пометка отбирала бы владение у шага. */
        [$projectId, $asset['id'], $role, (string) ($op['output'] ?? ''), $sort, $ownerId,
         !empty($op['madeByRun']) ? (int) $op['madeByRun'] : null]
    );
    if ($folderId) $ctx['folders'][] = $folderId;
    if ($column === 'element_id') elementTouched((int) $ownerId, $ctx['rev']);
    return ['id' => dbId(), 'folder_id' => $folderId];
}

/**
 * Операция asset.role — сменить роль готовой привязки, не открепляя материал.
 * Ею же картинку делают заглавной: обложка у владельца одна, прежняя
 * становится обычным вложением.
 */
function assetRole(array $op, array &$ctx): array
{
    if (!dbInTransaction()) {
        return dbTransaction(static function () use ($op, &$ctx): array {
            dbRow('SELECT id FROM projects WHERE id = ? FOR UPDATE', [(int) $ctx['projectId']]);
            return assetRole($op, $ctx);
        });
    }
    $projectId = $ctx['projectId'];
    $row = dbRow('SELECT * FROM asset_links WHERE id = ?', [(int) ($op['id'] ?? 0)]);
    sameProject($row, $projectId, 'link');

    $role = (string) ($op['role'] ?? '');
    if (!isset(LINK_ROLES[$role])) throw new ApiError(t('server.link.unknown_role', ['role' => $role]));
    if (in_array($role, ['result', 'preview'], true) && !$row['run_id'] && !$row['step_id']) {
        throw new ApiError(t('server.link.result_step'));
    }

    $column = $row['element_id'] ? 'element_id'
        : ($row['folder_id'] ? 'folder_id' : ($row['run_id'] ? 'run_id' : 'step_id'));
    $folderId = activeGuardLinkFolder($row);
    if ($folderId && (in_array((string) $row['role'], ['spec', 'input', 'reference', 'cover'], true)
        || in_array($role, ['spec', 'input', 'reference', 'cover'], true))) {
        activeGuardFolders($projectId, [$folderId], 'edit_asset_role');
    }

    // Обложка и ТЗ — по одному на владельца.
    if (in_array($role, ['cover', 'spec'], true)) {
        dbRun(
            "UPDATE asset_links SET role = 'attachment'
              WHERE project_id = ? AND role = ? AND $column = ? AND id <> ?",
            [$projectId, $role, $row[$column], $row['id']]
        );
    }
    dbRun('UPDATE asset_links SET role = ? WHERE id = ?', [$role, $row['id']]);

    $folderId = $row['element_id']
        ? (int) dbValue('SELECT folder_id FROM elements WHERE id = ?', [$row['element_id']])
        : (int) ($row['folder_id'] ?? 0);
    if ($folderId) $ctx['folders'][] = $folderId;
    if ($row['element_id']) elementTouched((int) $row['element_id'], $ctx['rev']);

    return ['id' => (int) $row['id'], 'prev' => ['role' => (string) $row['role']]];
}

/** Операция asset.unlink — материал остаётся в библиотеке. */
function assetUnlink(array $op, array &$ctx): array
{
    if (!dbInTransaction()) {
        return dbTransaction(static function () use ($op, &$ctx): array {
            dbRow('SELECT id FROM projects WHERE id = ? FOR UPDATE', [(int) $ctx['projectId']]);
            return assetUnlink($op, $ctx);
        });
    }
    $projectId = $ctx['projectId'];
    if (!empty($op['asset']) && !empty($op['folder'])) {
        $asset = assetRow((int) $op['asset'], $projectId);
        $folder = folderRow((int) $op['folder'], $projectId);
        $folderId = (int) $folder['id'];
        $links = dbAll(
            'SELECT l.id FROM asset_links l
             LEFT JOIN elements e ON e.id = l.element_id
             LEFT JOIN run_steps s ON s.id = l.step_id
             LEFT JOIN runs r ON r.id = COALESCE(l.run_id, s.run_id)
             WHERE l.project_id = ? AND l.asset_id = ?
               AND (l.folder_id = ? OR e.folder_id = ? OR r.folder_id = ?)',
            [$projectId, $asset['id'], $folderId, $folderId, $folderId]
        );
        // Здесь материал из папки убирают совсем — обратно её не вешаем.
        foreach ($links as $link) assetUnlink(['id' => $link['id'], 'keep' => false], $ctx);
        $ctx['folders'][] = $folderId;
        return ['id' => (int) $asset['id'], 'folder_id' => $folderId, 'unlinked' => count($links)];
    }
    $row = dbRow('SELECT * FROM asset_links WHERE id = ?', [(int) ($op['id'] ?? 0)]);
    sameProject($row, $projectId, 'link');
    $guardFolder = activeGuardLinkFolder($row);
    if ($guardFolder && in_array((string) $row['role'], ['spec', 'input', 'reference', 'cover'], true)) {
        activeGuardFolders($projectId, [$guardFolder], 'unlink_asset');
    }

    dbRun('DELETE FROM asset_links WHERE id = ?', [$row['id']]);
    $folderId = $row['element_id']
        ? (int) dbValue('SELECT folder_id FROM elements WHERE id = ?', [$row['element_id']])
        : (int) ($row['folder_id'] ?? 0);
    /* Открепили от блока — материал остаётся в хранилище папки. Оно собирается
       из привязок, поэтому последняя снятая привязка уносила материал из папки
       целиком. Вешаем его на саму папку: из списка папки он никуда не денется,
       а убрать его оттуда можно кнопкой «Удалить из папки». */
    $keep = !array_key_exists('keep', $op) || !empty($op['keep']);
    if ($keep) keepInFolder($projectId, (int) $row['asset_id'], $folderId);
    if ($folderId) $ctx['folders'][] = $folderId;
    if ($row['element_id']) elementTouched((int) $row['element_id'], $ctx['rev']);
    return ['id' => (int) $row['id'], 'deleted' => true];
}

/**
 * Оставить материал в хранилище папки.
 *
 * Хранилище папки собирается из привязок, поэтому снятая последняя привязка
 * уносила материал из папки целиком. Если в папке от него ничего не осталось,
 * вешаем его на саму папку — убрать оттуда можно кнопкой «Удалить из папки».
 */
function keepInFolder(int $projectId, int $assetId, int $folderId): void
{
    if (!$folderId || assetInFolder($projectId, $assetId, $folderId)) return;
    $sort = (int) dbValue('SELECT COALESCE(MAX(sort), -1) + 1 FROM asset_links WHERE folder_id = ?', [$folderId]);
    dbRun(
        "INSERT INTO asset_links (project_id, asset_id, role, output, sort, folder_id)
         VALUES (?,?,'attachment','',?,?)",
        [$projectId, $assetId, $sort, $folderId]
    );
}

/** Есть ли материал в хранилище папки: своя привязка, её элемент или прогон. */
function assetInFolder(int $projectId, int $assetId, int $folderId): bool
{
    return (bool) dbValue(
        'SELECT l.id FROM asset_links l
         LEFT JOIN elements e ON e.id = l.element_id
         LEFT JOIN run_steps s ON s.id = l.step_id
         LEFT JOIN runs r ON r.id = COALESCE(l.run_id, s.run_id)
         WHERE l.project_id = ? AND l.asset_id = ?
           AND (l.folder_id = ? OR e.folder_id = ? OR r.folder_id = ?)
         LIMIT 1',
        [$projectId, $assetId, $folderId, $folderId, $folderId]
    );
}

/** Кому крепим: ровно один владелец. */
function linkOwner(array $op, array &$ctx): array
{
    $projectId = $ctx['projectId'];
    if (isset($op['element'])) {
        $id = resolveRef($op['element'], $ctx);
        $row = elementRow($id, $projectId);
        return ['element_id', $id, (int) $row['folder_id']];
    }
    if (isset($op['folder'])) {
        $row = folderRow((int) $op['folder'], $projectId);
        return ['folder_id', (int) $row['id'], (int) $row['id']];
    }
    if (isset($op['run'])) {
        $row = dbRow('SELECT id, project_id FROM runs WHERE id = ?', [(int) $op['run']]);
        sameProject($row, $projectId, 'run');
        return ['run_id', (int) $row['id'], 0];
    }
    if (isset($op['step'])) {
        $row = dbRow('SELECT id, project_id FROM run_steps WHERE id = ?', [(int) $op['step']]);
        sameProject($row, $projectId, 'step');
        return ['step_id', (int) $row['id'], 0];
    }
    throw new ApiError(t('server.link.no_target'));
}

function resolveAssetRef($value, array &$ctx): int
{
    if (is_string($value) && isset($ctx['refs'][$value])) return $ctx['refs'][$value];
    if (!is_numeric($value)) throw new ApiError(t('server.link.no_asset'), 'not_found');
    return (int) $value;
}

/** Где висит один материал. */
function assetLinksOf(int $assetId): array
{
    $rows = dbAll('SELECT id, role, output, element_id, folder_id, run_id, step_id FROM asset_links WHERE asset_id = ? ORDER BY id', [$assetId]);
    return array_map(static fn(array $r) => [
        'id'      => (int) $r['id'],
        'role'    => $r['role'],
        'output'  => $r['output'],
        'element' => $r['element_id'] ? (int) $r['element_id'] : null,
        'folder'  => $r['folder_id'] ? (int) $r['folder_id'] : null,
        'run'     => $r['run_id'] ? (int) $r['run_id'] : null,
        'step'    => $r['step_id'] ? (int) $r['step_id'] : null,
    ], $rows);
}

/**
 * Материалы элемента сменились — двигаем его ревизию.
 * Дельта отдаёт только то, у чего выросла ревизия, и без этого прикреплённая
 * картинка появлялась на холсте лишь после перезагрузки страницы.
 */
function elementTouched(int $elementId, int $rev): void
{
    dbRun('UPDATE elements SET rev = ? WHERE id = ?', [$rev, $elementId]);
}

/** Все привязки элементов одной папки: element_id → список значков. */
function assetLinksOfFolder(int $folderId): array
{
    $rows = dbAll(
        "SELECT l.id, l.role, l.output, l.element_id, l.made_by_run,
                a.id AS asset_id, a.kind, a.title, a.uri, a.file_key, a.bytes, a.mime
           FROM asset_links l
           JOIN assets a ON a.id = l.asset_id
           JOIN elements e ON e.id = l.element_id
          WHERE e.folder_id = ?
          ORDER BY (l.role = 'cover' AND l.made_by_run IS NOT NULL) DESC, l.sort, l.id",
        [$folderId]
    );
    $out = [];
    foreach ($rows as $row) {
        $out[(int) $row['element_id']][] = [
            'link'    => (int) $row['id'],
            'asset'   => (int) $row['asset_id'],
            'role'    => $row['role'],
            // Пришло из прогона (результат шага), а не из оформления схемы.
            'fromRun' => $row['made_by_run'] ? (int) $row['made_by_run'] : null,
            'output' => $row['output'],
            'kind'   => $row['kind'],
            'title'  => $row['title'],
            'uri'    => $row['uri'],
            'file'   => $row['file_key'] !== null,
            'bytes'  => $row['bytes'] !== null ? (int) $row['bytes'] : null,
        ] + ($row['file_key'] === null && str_starts_with((string) $row['uri'], '/') && !is_file((string) $row['uri'])
            ? ['missing' => true] : [])
          // Путь от папки схемы — файл у агента в вебе (assetAtAgent): браузер берёт его из подключённой папки.
          + (assetAtAgentUri($row['file_key'], $row['uri']) ? ['agent' => true] : []);
    }
    return $out;
}

/** Текст ТЗ владельца: то, что уходит worker дословно. */
function specText(string $column, int $ownerId): ?array
{
    $row = dbRow(
        "SELECT a.id, a.title, a.body FROM asset_links l JOIN assets a ON a.id = l.asset_id
          WHERE l.role = 'spec' AND l.$column = ? LIMIT 1",
        [$ownerId]
    );
    // Пустой текст — считаем, что ТЗ нет: иначе карточка показывает MD, а открывать нечего.
    if (!$row || $row['body'] === null || trim((string) $row['body']) === '') return null;
    return ['id' => (int) $row['id'], 'title' => (string) $row['title'], 'text' => (string) $row['body']];
}
