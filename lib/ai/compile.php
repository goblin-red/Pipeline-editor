<?php
/* Ответ модели → операции, которые сервер согласен применить.
   Отдаёт: aiCompile(), aiSensitive(), aiPreview(), aiSummary().
   Не делает: не применяет — применение идёт обычной пачкой после «Применить».

   Белый список: чего в нём нет, то отбрасывается, а человеку говорится, что
   именно отброшено. Одна лишняя операция не губит весь ответ. */

declare(strict_types=1);

const AI_ALLOWED = [
    'run.prepare', 'element.create', 'element.update', 'element.delete',
    'folder.create', 'folder.update',
    'asset.create', 'asset.update', 'asset.link', 'asset.unlink',
];

/** Опасное всегда ждёт «Применить», даже если подтверждение выключено. */
const AI_SENSITIVE = ['run.prepare', 'element.delete', 'asset.unlink', 'asset.update', 'folder.update'];

/** Поля элемента, которые модель может прислать. Свойства прогона — внутрь props. */
const AI_ELEMENT_FIELDS = ['op','id','ref','folder','type','title','description','style','props','in','agent','target','branch','back','from','to','no'];
const AI_PROP_FIELDS = ['join', 'max_attempts', 'outputs', 'paid_calls', 'timeout', 'expr', 'cond', 'answer', 'accept', 'reject', 'start'];

/**
 * Отдаёт ['ops' => годные операции, 'dropped' => что отброшено — словами для человека].
 * Элемент без папки ложится в открытую папку, а в конструкторе (открытой нет) —
 * в папку, которую модель создала в этом же ответе (folder.create с ref).
 */
function aiCompile(array $changes, int $folderId): array
{
    $ops = [];
    $dropped = [];

    $newFolder = null;
    foreach ($changes as $change) {
        if (is_array($change) && ($change['op'] ?? '') === 'folder.create' && isset($change['ref'])) {
            $newFolder = (string) $change['ref'];
            break;
        }
    }
    $home = $folderId ?: $newFolder;

    foreach ($changes as $change) {
        if (!is_array($change)) { $dropped[] = ta('agents.ai.dropped_unclear'); continue; }
        $name = (string) ($change['op'] ?? '');
        if (!in_array($name, AI_ALLOWED, true)) { $dropped[] = ta('agents.ai.dropped_op', ['name' => $name ?: ta('agents.ai.no_name')]); continue; }

        if (in_array($name, ['element.create', 'element.update'], true)) {
            foreach (AI_PROP_FIELDS as $key) {
                if (array_key_exists($key, $change)) {
                    $change['props'][$key] = $change[$key];
                    unset($change[$key]);
                }
            }
            $unknown = array_diff(array_keys($change), AI_ELEMENT_FIELDS);
            if ($unknown) {
                $dropped[] = ta('agents.ai.dropped_fields', ['list' => implode(', ', $unknown)]);
                $change = array_intersect_key($change, array_flip(AI_ELEMENT_FIELDS));
            }
            if ($name === 'element.update' && !array_diff(array_keys($change), ['op','id','ref','type','no'])) {
                $dropped[] = ta('agents.ai.dropped_nochange', ['id' => $change['id'] ?? '?']);
                continue;
            }
        }
        if (!isset($change['folder']) && $home && in_array($name, ['element.create', 'run.prepare'], true)) {
            $change['folder'] = $home;
        }
        $ops[] = $change;
    }
    return ['ops' => $ops, 'dropped' => $dropped];
}

function aiSensitive(array $ops): bool
{
    foreach ($ops as $op) if (in_array((string) ($op['op'] ?? ''), AI_SENSITIVE, true)) return true;
    return false;
}

/**
 * Человеческий пересказ плана — строка на правку: что и с чем.
 * Элементы называются номером и названием: новые — из этого же плана по ref,
 * существующие — из базы.
 */
function aiPreview(array $ops, int $projectId = 0): array
{
    $kinds = [];
    foreach (['block', 'decision', 'gateway', 'arrow', 'group', 'area', 'note', 'table', 'link'] as $type) $kinds[$type] = ta('agents.kinds.' . $type);
    $byRef = [];
    foreach ($ops as $op) {
        if (isset($op['ref'])) $byRef[(string) $op['ref']] = (string) ($op['title'] ?? $op['name'] ?? $op['ref']);
    }
    $name = static function ($id) use ($byRef, $projectId): string {
        if (is_string($id) && isset($byRef[$id])) return '«' . $byRef[$id] . '»';
        $row = $projectId && is_numeric($id)
            ? dbRow('SELECT `no`, title FROM elements WHERE id = ? AND project_id = ?', [(int) $id, $projectId]) : null;
        return $row ? $row['no'] . ' «' . $row['title'] . '»' : '?';
    };

    $out = [];
    foreach ($ops as $op) {
        $what = match ((string) ($op['op'] ?? '')) {
            'element.create' => ($op['type'] ?? '') === 'arrow'
                ? ta('agents.ai.pv_arrow', ['from' => $name($op['from'] ?? null), 'to' => $name($op['to'] ?? null)])
                    . (!empty($op['branch']) ? ' (' . ta($op['branch'] === 'yes' ? 'agents.advance.yes' : 'agents.advance.no') . ')' : '')
                    . (!empty($op['back']) ? ta('agents.ai.pv_back') : '')
                : ta('agents.ai.pv_add', ['kind' => $kinds[$op['type'] ?? ''] ?? ta('agents.ai.pv_element'), 'title' => $op['title'] ?? ''])
                    . (propOn($op['props']['start'] ?? false) ? ta('agents.ai.pv_starter') : ''),
            'element.update' => ta('agents.ai.pv_change', ['name' => $name($op['id'] ?? null), 'list' => implode(', ', array_diff(array_keys($op), ['op', 'id', 'ref', 'type', 'no']))]),
            'element.delete' => ta('agents.ai.pv_delete', ['name' => $name($op['id'] ?? null)]),
            'asset.create' => ($op['kind'] ?? '') === 'spec'
                ? ta('agents.ai.pv_spec', ['name' => $name($op['link']['element'] ?? null)]) : ta('agents.ai.pv_asset', ['title' => $op['title'] ?? '']),
            'asset.update' => ta('agents.ai.pv_rewrite', ['name' => aiAssetName((int) ($op['id'] ?? 0), $projectId)]),
            'asset.link' => ta('agents.ai.pv_link', ['name' => $name($op['element'] ?? null)]),
            'asset.unlink' => ta('agents.ai.pv_unlink'),
            'folder.create' => ta('agents.ai.pv_folder', ['name' => $op['name'] ?? '']),
            'folder.update' => ta('agents.ai.pv_folder_settings'),
            'run.prepare' => ta('agents.ai.pv_prepare'),
            default => (string) ($op['op'] ?? ''),
        };
        $out[] = $what;
    }
    return $out;
}

function aiAssetName(int $assetId, int $projectId): string
{
    $title = $projectId ? dbValue('SELECT title FROM assets WHERE id = ? AND project_id = ?', [$assetId, $projectId]) : null;
    return $title ? '«' . $title . '»' : ta('agents.ai.asset_default');
}

/** Короткий итог применённого: «блоков 6, стрелок 8, ТЗ 7». */
function aiSummary(array $ops): string
{
    $count = [];
    $add = static function (string $word) use (&$count) { $count[$word] = ($count[$word] ?? 0) + 1; };
    foreach ($ops as $op) {
        $name = (string) ($op['op'] ?? '');
        if ($name === 'element.create') $add(ta(['arrow' => 'agents.ai.sum_arrows', 'decision' => 'agents.ai.sum_decisions', 'block' => 'agents.ai.sum_blocks'][$op['type'] ?? ''] ?? 'agents.ai.sum_elements'));
        elseif ($name === 'element.update') $add(ta('agents.ai.sum_edits'));
        elseif ($name === 'element.delete') $add(ta('agents.ai.sum_deleted'));
        elseif ($name === 'asset.create' || $name === 'asset.update') $add(ta('agents.ai.sum_specs'));
        elseif ($name === 'folder.create') $add(ta('agents.ai.sum_folders'));
        else $add(ta('agents.ai.sum_other'));
    }
    return implode(', ', array_map(static fn($word, $n) => "$word $n", array_keys($count), $count));
}


/** Применение ИИ использует тот же журнал и транзакцию, что обычные правки. */
function aiApplyOp(array $op, array &$ctx): array
{
    if (!in_array($op['op'], AI_ALLOWED, true)) throw new ApiError(t('agents.ai.op_forbidden'), 'scope');
    if ($op['op'] === 'run.prepare') return aiPrepareRun($op, $ctx);
    $rule = opRule($op['op']);
    if ($rule[3] !== 'batch') throw new ApiError(t('agents.ai.op_not_edit'));
    return $rule[1]($op, $ctx);
}

/** Подготовка не исполняет шаги и не уничтожает результаты прошлых прогонов.
    Маркер хранится в папке: после обновления страницы старые статусы не вернутся.
    Новый прогон имеет больший id и автоматически появится на холсте. */
function aiPrepareRun(array $op, array &$ctx): array
{
    $folder = folderRow((int) ($op['folder'] ?? 0), $ctx['projectId']);
    if (runActive((int) $folder['id'])) throw new ApiError(t('agents.prepare.stop_first'), 'conflict');

    $prev = json_decode((string) $folder['style'], true) ?: [];
    // Файлы рабочей папки ИИ не трогает без явной просьбы: стереть чужую
    // работу дороже, чем оставить лишнее.
    $done = runPrepareDo($folder, $ctx['projectId'], $ctx['rev'], !empty($op['files']));

    $say = ta('agents.ai.prepared');
    if ($done['coversDropped']) $say .= ta('agents.ai.covers_dropped', ['n' => $done['coversDropped']]);
    if (!empty($done['filesCleaned'])) $say .= ta('agents.ai.work_dir_cleaned');

    return ['id' => (int) $folder['id'], 'folder_id' => (int) $folder['id'], 'prepared' => true,
        'prev' => ['style' => $prev], 'message' => $say];
}

