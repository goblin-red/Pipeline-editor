<?php
/* Подготовка папки к новому прогону: статусы прошлых прогонов уходят с холста.
   Отдаёт: runPrepare(), runPrepareDo(), runCoversDrop(), workDirClean(), resultsOutsideOut(), rmTree().
   Не делает: не заводит прогон и не трогает входы в рабочей папке. */

declare(strict_types=1);

/**
 * POST run.prepare — подготовить папку к новому прогону.
 *
 * История прогонов остаётся в базе навсегда, а с холста и из рабочей папки
 * убирается всё, что осталось от прошлого раза:
 *   • статусы прошлых прогонов больше не рисуются (метка preparedRun);
 *   • обложки, которые поставил прогон, снимаются — возвращается та,
 *     которую ставил человек при сборке схемы;
 *   • с флагом `files` — рабочая папка: `service/steps` чистится, файлы, сданные мимо `out/`,
 *     переезжают в `out/rN/` своего прогона; сами `out/rN/` копятся и не трогаются.
 *
 * Простой путь leader делает это сам при каждом `begin`.
 *
 * Входы (`pic/`, `in.txt` и прочее, что лежало до прогона) не трогаем никогда.
 */
function runPrepare(): void
{
    $project = requireProject(true);
    $projectId = (int) $project['id'];

    // Под замком: пока папку готовят, прогон в ней никто не заведёт. Диск — уже после замка.
    $folder = null;
    $out = engineLocked($projectId, static function () use ($projectId, &$folder): array {
        $folderId = inputInt('folder');
        if (!$folderId) throw new ApiError(ta('agents.precheck.no_folder'));
        $folder = folderRow($folderId, $projectId);
        if (runActive((int) $folder['id'])) throw new ApiError(ta('agents.prepare.stop_first'), 'conflict');

        $rev  = bumpRev($projectId);
        $done = runPrepareDo($folder, $projectId, $rev, false);

        return ['ok' => true, 'folder' => folderShape(folderRow((int) $folder['id'], $projectId)), 'prepared' => $done];
    });

    if (!empty(input('files'))) {
        $out['prepared']['filesCleaned'] = workDirClean((string) $folder['work_dir'], (int) $folder['id']);
    }
    reply($out);
}

/**
 * Сама работа по подготовке. `$files` чистит диск: в пачке правок ИИ (замок держит её транзакция) —
 * сразу после фиксации; команды прогона зовут с false и после замка — workDirClean().
 * Отдельно от операции, потому что то же самое
 * делает встроенный ИИ внутри пачки правок.
 */
function runPrepareDo(array $folder, int $projectId, int $rev, bool $files): array
{
    $done = [];

    // 1. Статусы прошлых прогонов уходят с холста.
    $last = (int) dbValue('SELECT COALESCE(MAX(id),0) FROM runs WHERE project_id = ? AND folder_id = ?',
        [$projectId, (int) $folder['id']]);
    $style = json_decode((string) $folder['style'], true) ?: [];
    $style['preparedRun'] = $last;
    dbRun('UPDATE folders SET style = ?, rev = ?, content_rev = ? WHERE id = ?',
        [json_encode($style, JSON_UNESCAPED_UNICODE), $rev, $rev, $folder['id']]);
    $done['runsHidden'] = $last;
    // Ярлык рядом с id: человеку нужен rN, а не ключ строки. Без этого
    // утилита печатала «r1098» там, где прогон зовут r79.
    $done['runsHiddenNo'] = $last
        ? (int) dbValue('SELECT `no` FROM runs WHERE id = ?', [$last])
        : 0;

    /* 2. Всё, что повесил на схему прогон, снимаем: обложки-результаты и те же
          картинки, разжалованные в обычные вложения. ТЗ, входы и образцы —
          оформление схемы — остаются: их ставил человек. */
    $mine = dbAll(
        "SELECT l.id, l.role, l.element_id FROM asset_links l
           JOIN elements e ON e.id = l.element_id
          WHERE e.folder_id = ? AND l.role NOT IN ('spec','input','reference')
            AND (l.made_by_run IS NOT NULL
                 OR l.asset_id IN (SELECT asset_id FROM asset_links
                                    WHERE role = 'result' AND made_by_run IS NOT NULL))",
        [(int) $folder['id']]
    );

    // Заодно снимаем мёртвые привязки: файл прошлого прогона уже стёрт,
    // а картинка-призрак висит в материалах блока и путает.
    $dead = dbAll(
        "SELECT l.id, l.role, l.element_id, a.uri FROM asset_links l
           JOIN elements e ON e.id = l.element_id
           JOIN assets a ON a.id = l.asset_id
          WHERE e.folder_id = ? AND l.role NOT IN ('spec','input','reference')
            AND a.uri <> '' AND a.file_key IS NULL",
        [(int) $folder['id']]
    );
    foreach ($dead as $row) {
        $uri = (string) $row['uri'];
        // Ссылку в сеть проверить нечем — её не трогаем: это материал схемы.
        if ($uri === '' || $uri[0] !== '/') continue;
        if (is_file($uri)) continue;
        $mine[] = $row;
    }

    foreach ($mine as $row) {
        dbRun('DELETE FROM asset_links WHERE id = ?', [(int) $row['id']]);
        elementTouched((int) $row['element_id'], $rev);
    }
    $done['coversDropped'] = count(array_filter($mine, static fn($r) => $r['role'] === 'cover'));
    $done['linksDropped']  = count($mine);

    // 3. Рабочая папка: убираем то, что сделал прогон, а не то, что ему дали. В пачке ИИ — после
    //    фиксации (dbAfterCommit): откат пачки файлы не вернул бы, а примерка диск не трогает вовсе.
    $clean = static fn(): array => workDirClean((string) $folder['work_dir'], (int) $folder['id']);
    $done['filesCleaned'] = null;
    if ($files && dbInTransaction()) {
        dbAfterCommit($clean);
        $done['filesCleaned'] = ['planned' => true];
    } elseif ($files) {
        $done['filesCleaned'] = $clean();
    }

    return $done;
}

/**
 * Снять с блоков то, что повесил один прогон: его обложки и разжалованные ими вложения.
 * Как шаг 2 подготовки, только по своему прогону: так живой сброс (runResetDo) не оставляет
 * на карточках картинки прошлой жизни. ТЗ, входы и образцы человека не трогаются. Только под замком.
 */
function runCoversDrop(array $run, int $rev): int
{
    $rows = dbAll(
        "SELECT id, element_id FROM asset_links
          WHERE made_by_run = ? AND element_id IS NOT NULL AND role NOT IN ('spec','input','reference')",
        [(int) $run['id']]
    );
    foreach ($rows as $row) {
        dbRun('DELETE FROM asset_links WHERE id = ?', [(int) $row['id']]);
        elementTouched((int) $row['element_id'], $rev);
    }
    if ($rows) touchFolder((int) $run['folder_id'], $rev);
    return count($rows);
}

/**
 * Подготовить рабочую папку к новому прогону.
 *
 * Устройство рабочей папки: `in/` — материалы человека, `out/` — результаты,
 * у каждого прогона своя подпапка `out/rN` (runOutDir), `service/` — служебное.
 * Результаты копятся: ничего не удаляем и в архив не уносим — новый прогон
 * пишет в свою `out/rN` и чужие не трогает. Файлы, которые прогон сдал мимо
 * `out/`, переезжают в `out/rN` того прогона, что их сделал.
 * `service/steps` (пакеты утилиты) чистится как раньше. Входы (`in/`, `pic/`,
 * всё, что человек приложил к схеме) не трогаем. Старые резервы
 * `service/archive/out_oldN` (до 22.09.2026) остаются как есть.
 */
function workDirClean(string $workDir, int $folderId = 0): array
{
    // Пустая рабочая папка — не «текущий каталог процесса»: realpath('') вернул бы именно его.
    if (trim($workDir) === '') return ['skipped' => ta('agents.prepare.no_work_dir')];
    $real = pathInsideRoots($workDir);
    if (!$real || !is_dir($real)) return ['skipped' => ta('agents.prepare.work_dir_missing')];

    $gone = [];

    // out/ — результаты копятся по прогонам (out/rN); ссылку вместо папки убираем.
    $out = $real . '/out';
    if (is_link($out)) @unlink($out);            // то, на что она вела, не трогаем
    if (!is_dir($out)) @mkdir($out, 0777, true);

    $steps = $real . '/service/steps';
    if (is_link($steps)) { @unlink($steps); @mkdir($steps, 0777, true); }
    elseif (is_dir($steps)) { $gone['service/steps'] = rmTree($steps); @mkdir($steps, 0777, true); }

    /* Задания worker прошлых прогонов (service/tasks/rN) копятся как журнал.
       Автоуборка — по выключателю tasks_autoclean в config.php (пока выключена). */
    $tasks = $real . '/service/tasks';
    if (!empty(config()['tasks_autoclean'])) {
        if (is_link($tasks)) @unlink($tasks);
        elseif (is_dir($tasks)) $gone['service/tasks'] = rmTree($tasks);
    }

    if ($folderId) {
        $moved = 0;
        foreach (resultsOutsideOut($real, $folderId) as $path => $runNo) {
            $to = $out . '/r' . $runNo . '/' . substr($path, strlen($real) + 1);
            @mkdir(dirname($to), 0777, true);
            $moved += @rename($path, $to) ? 1 : 0;
        }
        if ($moved) $gone[ta('agents.prepare.strays')] = ta('agents.prepare.strays_moved', ['n' => $moved]);
    }
    return $gone;
}

/**
 * Файлы, которые прогон сделал мимо `out/`: путь => номер прогона. Их переносит
 * workDirClean в `out/rN` того прогона.
 *
 * Сдать результатом можно и то, что лежало в папке до прогона: worker первого
 * шага честно сдаёт исходник, и такой файл — вход, а не работа. Различает их
 * пометка, поставленная при сдаче: `made_by_run` есть только у файлов, которые
 * шаг действительно создал.
 */
function resultsOutsideOut(string $workDir, int $folderId): array
{
    $rows = dbAll(
        "SELECT a.uri, MAX(r.`no`) AS run_no
           FROM asset_links l
           JOIN assets a ON a.id = l.asset_id
           JOIN run_steps s ON s.id = l.step_id
           JOIN runs r ON r.id = s.run_id
          WHERE l.role = 'result' AND l.made_by_run IS NOT NULL
            AND r.folder_id = ? AND a.uri <> ''
          GROUP BY a.uri",
        [$folderId]
    );

    $found = [];
    foreach ($rows as $row) {
        $path = realpath((string) $row['uri']);
        if (!$path || !is_file($path) || isset($found[$path])) continue;
        if (!str_starts_with($path, $workDir . '/')) continue;              // чужое — не наше дело
        if (str_starts_with($path, $workDir . '/out/')) continue;           // уже в out/
        if (str_starts_with($path, $workDir . '/service/')) continue;       // служебное и резерв — не результаты
        if (str_starts_with($path, $workDir . '/pic/')
            || str_starts_with($path, $workDir . '/in/')) continue;         // входы не трогаем

        /* Файл, который где-то приложен как вход или образец, — тоже вход.
           И любой файл, который человек сам прикрепил к схеме (вложение,
           обложка — привязка без пометки прогона): это его материал. */
        $needed = dbValue(
            "SELECT l.id FROM asset_links l JOIN assets a ON a.id = l.asset_id
              WHERE a.uri = ? AND (l.role IN ('input','reference','spec') OR l.made_by_run IS NULL)
              LIMIT 1",
            [(string) $row['uri']]
        );
        if ($needed) continue;
        $found[$path] = (int) $row['run_no'];
    }
    return $found;
}

/** Стереть папку со всем содержимым; вернуть, сколько файлов убрали. */
function rmTree(string $path): int
{
    $count = 0;
    foreach (scandir($path) ?: [] as $name) {
        if ($name === '.' || $name === '..') continue;
        $full = $path . '/' . $name;
        if (is_dir($full) && !is_link($full)) {
            $count += rmTree($full);
            @rmdir($full);
        } else {
            $count += @unlink($full) ? 1 : 0;
        }
    }
    return $count;
}
