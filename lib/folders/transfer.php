<?php
/* Перенос папки в другой проект — вместе с содержимым и историей.
   Отдаёт: folderTransfer(), folderTransferDo(), transferRows(), transferAddresses(), transferAssets().
   Не делает: не копирует, а переносит: в старом проекте папки больше нет;
              не решает, чьи проекты, — права проверяет вход (API или кабинет).

   Переезжает всё, что к папке привязано: элементы, состав, свойства, материалы,
   прогоны с шагами, заданиями, метками и событиями. Номера блоков и прогонов
   выдаются заново — они уникальны в пределах проекта, и в новом доме старые
   могут быть заняты; шаги и события идут за новыми номерами блоков.
   Агенты живут в проекте, а не в папке, — с блоков и шагов назначения снимаются.
   В старом проекте остаются надгробия, у переехавших строк — ревизия нового. */

declare(strict_types=1);

/** POST folder.transfer {folder, to} — папку с веткой и историей в проект с ключом to. */
function folderTransfer(): void
{
    $project = requireProject(true);
    $folder = folderRow((int) inputInt('folder'), (int) $project['id']);
    $toKey = trim((string) (input('to') ?? ''));
    if ($toKey === '') throw new ApiError(t('server.transfer.no_target'));

    $target = dbRow('SELECT * FROM projects WHERE url_key = ?', [$toKey]);
    if (!$target) throw new ApiError(t('server.transfer.target_not_found'), 'not_found');
    requireWrite($target);
    reply(folderTransferDo($project, $target, (int) $folder['id']));
}

/**
 * Перенос ветки папок из проекта $from в $to — одно ядро для API и кабинета.
 * Права на оба проекта проверяет вызывающий. Отдаёт ['folder' => id, 'folders' => сколько папок, 'to' => ключ].
 */
function folderTransferDo(array $from, array $to, int $folderId): array
{
    $fromId = (int) $from['id'];
    $toId = (int) $to['id'];
    $folder = folderRow($folderId, $fromId);
    if ($toId === $fromId) throw new ApiError(t('server.transfer.same_project'));

    $moved = 0;
    dbTransaction(static function () use ($fromId, $toId, $folder, &$moved) {
        dbAll('SELECT id FROM projects WHERE id IN (?,?) ORDER BY id FOR UPDATE', [$fromId, $toId]);
        $lockedFolder = folderRow((int) $folder['id'], $fromId);
        $branch = folderBranch((int) $lockedFolder['id']);   // под lock: папка и все её потомки
        $moved = count($branch);
        activeGuardFolders($fromId, $branch, 'transfer_folder');

        // Связи через границу переезда рвутся: разные проекты ярлык и цель шлюза не связывают.
        $gone = bumpRev($fromId);
        linksCutAcross($branch, $fromId, $gone);
        gatesCutAcross($branch, $fromId, $gone);

        transferRows($branch, $fromId, $toId, $gone, bumpRev($toId));
        // Верхняя папка переезда становится корневой в новом проекте:
        // её прежний родитель остался в старом доме.
        dbRun('UPDATE folders SET parent_id = NULL WHERE id = ?', [(int) $lockedFolder['id']]);
    });

    return ['folder' => (int) $folder['id'], 'folders' => $moved, 'to' => (string) $to['url_key']];
}

/**
 * Строки ветки папок переезжают из $fromId в $toId. $gone — ревизия старого проекта
 * (надгробия), $rev — нового (переехавшие строки).
 */
function transferRows(array $branch, int $fromId, int $toId, int $gone, int $rev): void
{
    $in = implode(',', array_map('intval', $branch));

    foreach (dbAll("SELECT id, name FROM folders WHERE id IN ($in)") as $row) {
        markDeleted($fromId, 'folder', (int) $row['id'], $gone, ['title' => $row['name']]);
    }
    dbRun("UPDATE folders SET project_id = ?, content_rev = ?, rev = ? WHERE id IN ($in)", [$toId, $rev, $rev]);

    // Блоки: новые номера. Агент остался в старом проекте — назначение снимается.
    $numbers = [];   // старый номер → новый
    foreach (dbAll("SELECT id, `no`, title, folder_id FROM elements WHERE folder_id IN ($in) ORDER BY `no`") as $row) {
        markDeleted($fromId, 'element', (int) $row['id'], $gone,
            ['no' => (int) $row['no'], 'folder_id' => (int) $row['folder_id'], 'title' => $row['title']]);
        $numbers[(int) $row['no']] = nextNo($toId);
        dbRun('UPDATE elements SET project_id = ?, `no` = ?, agent_id = NULL, rev = ? WHERE id = ?',
            [$toId, $numbers[(int) $row['no']], $rev, (int) $row['id']]);
    }

    // Прогоны: новые номера, и всё, что к ним привязано, переезжает следом.
    $runIds = [];
    foreach (dbAll("SELECT id, `no`, title, folder_id FROM runs WHERE folder_id IN ($in) ORDER BY `no`") as $row) {
        markDeleted($fromId, 'run', (int) $row['id'], $gone,
            ['no' => (int) $row['no'], 'folder_id' => (int) $row['folder_id'], 'title' => $row['title']]);
        dbRun('UPDATE runs SET project_id = ?, `no` = ?, lead_agent_id = NULL, rev = ? WHERE id = ?',
            [$toId, nextRunNo($toId), $rev, (int) $row['id']]);
        $runIds[] = (int) $row['id'];
    }
    if ($runIds) {
        $runIn = implode(',', $runIds);
        dbRun("UPDATE run_steps SET project_id = ?, agent_id = NULL, rev = ? WHERE run_id IN ($runIn)", [$toId, $rev]);
        foreach (['run_jobs', 'run_marks', 'run_events', 'tokens'] as $table) {
            dbRun("UPDATE $table SET project_id = ? WHERE run_id IN ($runIn)", [$toId]);
        }
        transferAddresses($numbers, $runIn, $toId);
    }

    transferAssets($in, $runIds, $fromId, $toId, $gone, $rev);
}

/**
 * Адрес шага — номер блока: блоки получили новые номера, шаги и события идут за ними.
 * У истории удалённых блоков элемента нет, но адрес тоже нужен свой: иначе прежний номер 11
 * совпал бы с новым номером 11 живого блока (uq_steps_attempt) — такой номер берём из счётчика
 * приёмника. Номера шагов сначала уводим в запас, иначе старые и новые столкнутся по дороге.
 */
function transferAddresses(array $numbers, string $runIn, int $toId): void
{
    $history = dbAll("SELECT element_no FROM run_steps WHERE run_id IN ($runIn)
                      UNION SELECT element_no FROM run_events WHERE run_id IN ($runIn) AND element_no IS NOT NULL
                      ORDER BY element_no");
    foreach (array_column($history, 'element_no') as $old) {
        if (!isset($numbers[(int) $old])) $numbers[(int) $old] = nextNo($toId);
    }
    if (!$numbers) return;
    $shift = 2147483648;
    $when = '';
    foreach ($numbers as $old => $new) $when .= " WHEN $old THEN $new";

    dbRun("UPDATE run_steps SET element_no = element_no + $shift WHERE run_id IN ($runIn)");
    dbRun("UPDATE run_steps SET element_no = CASE element_no - $shift $when ELSE element_no - $shift END
            WHERE run_id IN ($runIn)");
    dbRun("UPDATE run_events SET element_no = CASE element_no $when ELSE element_no END
            WHERE run_id IN ($runIn) AND element_no IS NOT NULL");
}

/**
 * Материалы: переезжают привязки папок, их блоков, прогонов и шагов (результаты — тоже).
 * Материал, который держится ещё и за остающееся, не переносят, а копируют:
 * иначе там остались бы привязки к чужому проекту.
 */
function transferAssets(string $in, array $runIds, int $fromId, int $toId, int $gone, int $rev): void
{
    $owned = ["l.folder_id IN ($in)", "l.element_id IN (SELECT id FROM elements WHERE folder_id IN ($in))"];
    if ($runIds) {
        $runIn = implode(',', $runIds);
        $owned[] = "l.run_id IN ($runIn)";
        $owned[] = "l.step_id IN (SELECT id FROM run_steps WHERE run_id IN ($runIn))";
    }
    $links = dbAll('SELECT l.id, l.asset_id FROM asset_links l WHERE ' . implode(' OR ', $owned));
    if (!$links) return;

    $mine = implode(',', array_column($links, 'id'));
    foreach (array_unique(array_column($links, 'asset_id')) as $assetId) {
        $asset = dbRow('SELECT id, title FROM assets WHERE id = ?', [$assetId]);
        $shared = (int) dbValue("SELECT COUNT(*) FROM asset_links WHERE asset_id = ? AND id NOT IN ($mine)", [$assetId]);
        if (!$shared) {
            dbRun('UPDATE assets SET project_id = ?, rev = ? WHERE id = ?', [$toId, $rev, $assetId]);
            markDeleted($fromId, 'asset', (int) $assetId, $gone, ['title' => $asset['title']]);
            continue;
        }
        $copy = dbRun(
            'INSERT INTO assets (project_id, kind, title, uri, body, mime, bytes, sha256, original_name, rev)
             SELECT ?, kind, title, uri, body, mime, bytes, sha256, original_name, ? FROM assets
              WHERE id = ? AND file_key IS NULL',
            [$toId, $rev, $assetId]
        );
        // Байты по file_key у копии были бы общими — такие материалы раздваивать нельзя.
        if (!$copy) throw new ApiError(t('server.transfer.asset_shared', ['title' => $asset['title']]), 'conflict');
        dbRun("UPDATE asset_links SET asset_id = ? WHERE asset_id = ? AND id IN ($mine)", [dbId(), $assetId]);
    }
    dbRun("UPDATE asset_links SET project_id = ? WHERE id IN ($mine)", [$toId]);
}
