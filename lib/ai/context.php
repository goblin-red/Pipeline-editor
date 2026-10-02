<?php
/* Что модель знает о проекте и схеме.
   Отдаёт: aiContext(), aiSchemaHint().
   Не делает: не зовёт модель и ничего не пишет.

   Папка открыта — её схема рабочим видом (первичное без оформления), к блокам —
   текст ТЗ с номером материала (без него ТЗ не поправить), файлы из in/ рабочей
   папки, точная предпроверка, последний прогон по шагам и что выделено на холсте.
   Весь проект — коротко все папки. */

declare(strict_types=1);

/** Предел текста одного ТЗ в запросе: длинное обрезаем, чтобы не раздувать запрос. */
const AI_SPEC_CHARS = 3000;

function aiContext(array $project, ?int $folderId, array $extra = []): array
{
    $projectId = (int) $project['id'];
    $out = [
        'project' => ['key' => $project['url_key'], 'title' => $project['title']],
        'folders' => array_map(
            static fn(array $f) => ['id' => (int) $f['id'], 'name' => $f['name'], 'parent' => $f['parent_id'] ? (int) $f['parent_id'] : null],
            dbAll('SELECT id, name, parent_id FROM folders WHERE project_id = ? ORDER BY sort', [$projectId])
        ),
        'agents' => array_map(
            static fn(array $a) => ['id' => (int) $a['id'], 'name' => $a['name'], 'role' => $a['role']],
            dbAll('SELECT id, name, role FROM agents WHERE project_id = ?', [$projectId])
        ),
    ];

    if ($folderId) {
        $folder = folderRow($folderId, $projectId);
        $out['folder'] = (int) $folderId;
        $out['scheme'] = aiWithSpecs(folderScheme($folderId, VIEW_WORK), $folderId);
        $out['workDir'] = (string) $folder['work_dir'];
        $out['materials'] = aiMaterials((string) $folder['work_dir']);
        // Точная предпроверка прогона — опора для аудита: модель не гадает, что помешает.
        $out['precheck'] = array_column(folderPrecheck($folder)['problems'], 'say');
        $out['latestRun'] = aiLastRun($projectId, $folderId);
        if (!empty($extra['selected'])) $out['selected'] = array_values(array_map('intval', (array) $extra['selected']));
    } elseif (!empty($extra['whole'])) {
        // Весь проект — каждая папка коротко: номер, тип, название и стрелки.
        $out['schemes'] = [];
        foreach ($out['folders'] as $one) {
            $out['schemes'][$one['id']] = array_map(static fn(array $e) => array_filter([
                'no' => $e['no'], 'type' => $e['type'], 'title' => $e['title'] ?? '',
                'from' => $e['from'] ?? null, 'to' => $e['to'] ?? null,
            ], static fn($v) => $v !== null && $v !== ''), folderScheme($one['id'], VIEW_WORK));
        }
    }
    return $out;
}

/**
 * Последний прогон папки — для разбора: где встал, что сорвалось, где долго.
 * Шаг: номер, круг, состояние, исполнитель, секунды, ответ и ошибка (коротко).
 */
function aiLastRun(int $projectId, int $folderId): ?array
{
    $run = dbRow('SELECT id, `no`, state, wait_for, started_at, finished_at FROM runs
                   WHERE project_id = ? AND folder_id = ? ORDER BY id DESC LIMIT 1', [$projectId, $folderId]);
    if (!$run) return null;
    $steps = dbAll('SELECT s.element_no, s.element_title, s.attempt, s.state, s.result, s.error, a.name AS agent,
                           TIMESTAMPDIFF(SECOND, s.opened_at, COALESCE(s.finished_at, s.submitted_at, NOW(3))) AS seconds
                      FROM run_steps s LEFT JOIN agents a ON a.id = s.agent_id
                     WHERE s.run_id = ? ORDER BY s.id LIMIT 200', [$run['id']]);
    return [
        'no' => 'r' . $run['no'], 'state' => $run['state'], 'waitFor' => $run['wait_for'],
        'startedAt' => $run['started_at'], 'finishedAt' => $run['finished_at'],
        'steps' => array_map(static fn(array $s) => array_filter([
            'no' => (int) $s['element_no'], 'title' => $s['element_title'], 'round' => (int) $s['attempt'],
            'state' => $s['state'], 'agent' => $s['agent'], 'seconds' => (int) $s['seconds'],
            'result' => mb_substr((string) $s['result'], 0, 200), 'error' => mb_substr((string) $s['error'], 0, 200),
        ], static fn($v) => $v !== null && $v !== ''), $steps),
    ];
}

/** Текст ТЗ к элементам: {asset, text}, и место на холсте: style {x, y, width, height, color, shape}. */
function aiWithSpecs(array $scheme, int $folderId): array
{
    $specs = [];
    $rows = dbAll("SELECT l.element_id, a.id, a.body FROM asset_links l
                     JOIN assets a ON a.id = l.asset_id
                     JOIN elements e ON e.id = l.element_id
                    WHERE e.folder_id = ? AND l.role = 'spec'", [$folderId]);
    foreach ($rows as $row) {
        $specs[(int) $row['element_id']] = ['asset' => (int) $row['id'],
            'text' => mb_substr((string) $row['body'], 0, AI_SPEC_CHARS)];
    }
    // Место на холсте: без него просьбы «разложи ровно» и «подгони рамку» ИИ выполнял вслепую.
    $boxes = [];
    foreach (dbAll("SELECT id, style FROM elements WHERE folder_id = ? AND type <> 'arrow'", [$folderId]) as $row) {
        $style = json_decode((string) $row['style'], true) ?: [];
        $box = array_intersect_key($style, array_flip(['x', 'y', 'width', 'height', 'color', 'shape']));
        if ($box) $boxes[(int) $row['id']] = $box;
    }
    foreach ($scheme as &$element) {
        if (isset($specs[(int) $element['id']])) $element['spec'] = $specs[(int) $element['id']];
        if (isset($boxes[(int) $element['id']])) $element['style'] = $boxes[(int) $element['id']];
    }
    unset($element);
    return $scheme;
}

/** Материалы — файлы из in/ рабочей папки, путями от неё: in/фото.png. */
function aiMaterials(string $workDir): array
{
    $dir = pathInsideRoots($workDir);
    if (!$dir || !is_dir($dir . '/in')) return [];
    $out = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir . '/in', FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if (!$file->isFile() || str_starts_with($file->getFilename(), '.')) continue;
        $out[] = substr($file->getPathname(), strlen($dir) + 1);
        if (count($out) >= 100) break;
    }
    sort($out);
    return $out;
}

/** Короткая памятка о формате ответа — её модель видит в каждом запросе. */
function aiSchemaHint(): string
{
    return ta('agents.ai.schema_hint');
}
