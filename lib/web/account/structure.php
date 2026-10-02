<?php
/* Утилита: дерево проектов и карточка выбранного объекта.
   Отдаёт: structureAct().
   Не делает: не пишет в базу напрямую — только через операции API,
              поэтому у каждой правки есть журнал и ревизия.

   Устроена как прежняя утилита: слева дерево «проект → папка → элемент»,
   справа всё об отмеченном объекте, и там же правится. */

declare(strict_types=1);

/** Правки из форм утилиты. Возвращает строку-сообщение. */
function structureAct(array $post): string
{
    $do = (string) ($post['do'] ?? '');
    $key = (string) ($post['project'] ?? '');
    if ($do === '' || $key === '') return '';

    $project = dbRow('SELECT * FROM projects WHERE url_key = ?', [$key]);
    if (!$project) throw new ApiError(t('account.err.no_project'), 'not_found');
    structureOwn($project);

    return match ($do) {
        'project-save'  => structureProjectSave($project, $post),
        'folder-save'   => structureFolderSave($project, $post),
        'folder-add'    => structureFolderAdd($project, $post),
        'folder-drop'   => structureFolderDrop($project, $post),
        'folder-move'   => structureFolderMove($project, $post),
        'element-save'  => structureElementSave($project, $post),
        'element-drop'  => structureElementDrop($project, $post),
        'spec-save'     => structureSpecSave($project, $post),
        default         => '',
    };
}

function structureOwn(array $project): void
{
    $user = currentUser();
    if (!$user) throw new ApiError(t('account.err.need_login'), 'forbidden');
    if ($project['owner_id'] && (int) $project['owner_id'] === (int) $user['id']) return;
    /* Хозяина нет или он другой: писать можно только в открытый проект — то же правило,
       что у requireWrite() в API. Иначе кабинет обходил бы запрет владельца. */
    if ((int) $project['guest_write'] === 1) return;
    throw new ApiError(t('account.err.foreign_project'), 'forbidden');
}

/** Одна операция как в API: с ревизией, журналом и проверками. */
function structureOp(array $project, array $ops): void
{
    $projectId = (int) $project['id'];
    dbTransaction(static function () use ($projectId, $project, $ops) {
        dbRow('SELECT id FROM projects WHERE id = ? FOR UPDATE', [$projectId]);
        $rev = bumpRev($projectId);
        $journalId = journalOpen($projectId, $rev, null, 'tool', ['label' => t('account.structure.journal_label')]);
        $ctx = ['project' => $project, 'projectId' => $projectId, 'rev' => $rev,
                'journalId' => $journalId, 'refs' => [], 'folders' => [], 'warnings' => []];
        foreach ($ops as $n => $op) {
            $rule = opRule((string) $op['op']);
            $result = $rule[1]($op, $ctx);
            $clean = $result;
            unset($clean['prev'], $clean['folder_id'], $clean['no_journal']);
            if (empty($result['no_journal'])) {
                journalOp($journalId, $n, (string) $op['op'], $op, $result['prev'] ?? null, $clean);
            }
        }
        foreach (array_unique($ctx['folders']) as $folderId) touchFolder((int) $folderId, $rev);
    });
}

/* ── Правки ───────────────────────────────────────────────────── */

function structureProjectSave(array $project, array $post): string
{
    structureOp($project, [[
        'op' => 'project.update',
        'title' => (string) ($post['title'] ?? ''),
        'guestWrite' => !empty($post['guestWrite']),
        'aiConfirm' => !empty($post['aiConfirm']),
        'strictChecks' => !empty($post['strictChecks']),
    ]]);
    return t('account.structure.project_saved');
}

function structureFolderSave(array $project, array $post): string
{
    $op = [
        'op' => 'folder.update',
        'id' => (int) $post['folder'],
        'name' => (string) ($post['name'] ?? ''),
        'workDir' => (string) ($post['workDir'] ?? ''),
        'workUrl' => (string) ($post['workUrl'] ?? ''),
        'roleScheme' => (string) ($post['roleScheme'] ?? ''),
        'shareScheme' => !empty($post['shareScheme']),
    ];
    if (array_key_exists('runEnv', $post)) $op['runEnv'] = (string) $post['runEnv'];
    structureOp($project, [$op]);
    return t('account.structure.folder_saved');
}

function structureFolderAdd(array $project, array $post): string
{
    structureOp($project, [[
        'op' => 'folder.create',
        'name' => (string) ($post['name'] ?? t('account.structure.folder_default')),
        'parent' => !empty($post['parent']) ? (int) $post['parent'] : null,
    ]]);
    return t('account.structure.folder_added');
}

function structureFolderDrop(array $project, array $post): string
{
    structureOp($project, [['op' => 'folder.delete', 'id' => (int) $post['folder']]]);
    return t('account.structure.folder_deleted');
}

/** Перенос папки в другой проект — тем же ядром, что folder.transfer; здесь только «оба проекта — мои». */
function structureFolderMove(array $project, array $post): string
{
    $target = dbRow('SELECT * FROM projects WHERE url_key = ?', [(string) ($post['to'] ?? '')]);
    if (!$target) throw new ApiError(t('account.err.no_project'), 'not_found');
    $user = currentUser();
    foreach ([$project, $target] as $one) {
        if (!$one['owner_id']) throw new ApiError(t('account.err.no_owner'), 'forbidden');
        if ((int) $one['owner_id'] !== (int) $user['id']) throw new ApiError(t('account.err.foreign_project'), 'forbidden');
    }
    folderTransferDo($project, $target, (int) $post['folder']);
    return t('account.structure.folder_moved');
}

function structureElementSave(array $project, array $post): string
{
    $ops = [[
        'op' => 'element.update',
        'id' => (int) $post['element'],
        'title' => (string) ($post['title'] ?? ''),
        'description' => (string) ($post['description'] ?? ''),
    ]];
    if (array_key_exists('agent', $post)) {
        $ops[0]['agent'] = $post['agent'] === '' ? null : (int) $post['agent'];
    }
    $props = structurePropsChanged((int) $post['element'], $post);
    if ($props) $ops[0]['props'] = $props;
    structureOp($project, $ops);
    return t('account.structure.element_saved');
}

/**
 * Из формы — только изменённые свойства, и в их прежнем типе.
 * Форма отдаёт всё строками: раньше каждое сохранение переписывало флажок
 * стартера, числа и списки критериев простым текстом, а неизменённые
 * свойства будили защиту живой схемы. Пустое значение — удалить свойство.
 */
function structurePropsChanged(int $elementId, array $post): array
{
    $stored = [];
    foreach (dbAll('SELECT name, type, value FROM props WHERE element_id = ?', [$elementId]) as $row) {
        $stored[$row['name']] = $row;
    }
    $posted = is_array($post['props'] ?? null) ? $post['props'] : [];
    $newName = trim((string) ($post['propNewName'] ?? ''));
    if ($newName !== '') $posted[$newName] = (string) ($post['propNewValue'] ?? '');

    $out = [];
    foreach ($posted as $name => $text) {
        $name = (string) $name;
        $text = trim((string) $text);
        $row = $stored[$name] ?? null;
        if ($row && $text === (string) $row['value']) continue;   // не трогали
        if (!$row && $text === '') continue;                      // пустое новое — нечего писать
        $out[$name] = $text === '' ? null : structurePropTyped($text, $row['type'] ?? '');
    }
    return $out;
}

/** Строку из формы — в тип свойства; у нового свойства тип угадывается по виду. */
function structurePropTyped(string $text, string $type)
{
    $json = static function (string $text) {
        $value = json_decode($text, true);
        if (!is_array($value)) throw new ApiError(t('account.structure.need_json', ['text' => mb_strimwidth($text, 0, 40, '…')]));
        return $value;
    };
    return match ($type) {
        'bool'         => in_array(mb_strtolower($text), ['1', 'true', 'да', 'yes'], true),
        'number'       => is_numeric($text) ? 0 + $text : throw new ApiError(t('account.structure.need_number', ['text' => $text])),
        'list', 'json' => $json($text),
        'text'         => $text,
        default        => match (true) {
            in_array($text, ['true', 'false'], true) => $text === 'true',
            is_numeric($text)                        => 0 + $text,
            str_starts_with($text, '[') || str_starts_with($text, '{') => $json($text),
            default                                  => $text,
        },
    };
}

function structureElementDrop(array $project, array $post): string
{
    structureOp($project, [['op' => 'element.delete', 'id' => (int) $post['element']]]);
    return t('account.structure.element_deleted');
}

/** ТЗ — это ассет с ролью spec: нет его — заводим, есть — правим. */
function structureSpecSave(array $project, array $post): string
{
    $elementId = (int) $post['element'];
    $text = (string) ($post['spec'] ?? '');
    $spec = specText('element_id', $elementId);

    structureOp($project, $spec
        ? [['op' => 'asset.update', 'id' => $spec['id'], 'text' => $text]]
        : [['op' => 'asset.create', 'kind' => 'spec', 'title' => t('account.structure.spec_title'),
            'text' => $text, 'link' => ['element' => $elementId, 'role' => 'spec']]]);
    return t('account.structure.spec_saved');
}
