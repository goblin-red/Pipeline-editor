<?php
/* Пачка правок: одна транзакция, одна ревизия, один ключ повтора.
   Отдаёт: runBatch(), bumpRev(), touchFolder(), resolveRef(), batchGuard().
   Не делает: не знает ни одной операции по имени — их список в api/router.php,
              а правила — в доменах.

   Правила пачки:
     • всё применяется целиком или не применяется вовсе;
     • ревизия проекта растёт ровно на единицу за пачку;
     • новые объекты несут временную ссылку ref, в ответе приходит id и номер;
     • повтор с тем же ключом и телом отдаёт прежний ответ, с другим — 409. */

declare(strict_types=1);

/**
 * Пробный прогон пачки (проверка правок ИИ до показа): всё в транзакции, которую
 * потом откатят, — поэтому ничего не делаем на диске. Отдаёт текущее значение.
 */
function batchDryRun(?bool $on = null): bool
{
    static $dry = false;
    if ($on !== null) $dry = $on;
    return $dry;
}

/**
 * Выполнить пачку операций.
 *
 * @param array    $project строка проекта
 * @param array    $ops     список операций как прислан
 * @param callable $apply   fn(array $op, array $ctx): array — применяет одну операцию
 * @param array    $journal подпись журнала, когда правку делает не вызывающий (фоновое задание ИИ):
 *                          via, label, user_id; нет — из запроса и caller()
 */
function runBatch(array $project, array $ops, callable $apply, bool $respond = true, array $journal = []): ?array
{
    $projectId = (int) $project['id'];
    $opId = trim((string) (input('opId') ?? ''));
    $opScope = (string) (input('opScope') ?? '');
    $hash = hash('sha256', json_encode($ops, JSON_UNESCAPED_UNICODE));
    $ifRev = inputInt('ifRev');
    $ifFolder = input('ifFolderRev');
    $sign = [
        'via'     => (string) ($journal['via'] ?? input('via') ?? 'api'),
        'label'   => (string) ($journal['label'] ?? input('label') ?? ''),
        'user_id' => $journal['user_id'] ?? null,
    ];

    $out = dbTransaction(static function () use ($project, $projectId, $ops, $apply, $opId, $opScope, $hash, $ifRev, $ifFolder, $sign) {
        // Проект под замком: пока идёт пачка, ревизию не двигает никто другой.
        $locked = dbRow('SELECT * FROM projects WHERE id = ? FOR UPDATE', [$projectId]);

        // Повтор той же пачки после обрыва связи — не ошибка. Ключ сверяется под замком и раньше
        // ревизии: одновременные повторы видят исход первой пачки, а не падают на её ключе.
        $repeat = journalFindRepeat($projectId, $opScope, $opId, $hash);
        if ($repeat) return $repeat + ['repeat' => true];
        batchGuard($locked, $ifRev, $ifFolder);

        $rev = bumpRev($projectId);
        $journalId = journalOpen($projectId, $rev, $opId, $sign['via'], [
            'op_scope'     => $opScope,
            'request_hash' => $hash,
            'label'        => $sign['label'],
            'user_id'      => $sign['user_id'],
        ]);

        $ctx = [
            'project'   => $locked,
            'projectId' => $projectId,
            'rev'       => $rev,
            'journalId' => $journalId,
            'refs'      => [],       // ref → id, живёт до конца пачки
            'folders'   => [],       // папки, чьё содержимое тронули
            'warnings'  => [],
        ];

        $results = [];
        foreach ($ops as $n => $op) {
            if (!is_array($op) || !isset($op['op'])) {
                throw new ApiError(t('server.batch.op_unnamed'));
            }
            $result = $apply($op, $ctx);
            if (isset($op['ref']) && isset($result['id'])) {
                $ctx['refs'][(string) $op['ref']] = (int) $result['id'];
                $result['ref'] = (string) $op['ref'];
            }
            $clean = $result;
            unset($clean['prev'], $clean['folder_id'], $clean['no_journal']);
            if (empty($result['no_journal'])) journalOp(
                $journalId, $n, (string) $op['op'],
                $op, $result['prev'] ?? null, $clean,
                $result['folder_id'] ?? null,
                isset($result['id']) ? (int) $result['id'] : null,
                isset($result['no']) ? (int) $result['no'] : null
            );
            if (isset($result['folder_id'])) $ctx['folders'][] = (int) $result['folder_id'];
            $results[] = $clean;
        }

        foreach (array_unique($ctx['folders']) as $folderId) {
            touchFolder((int) $folderId, $rev);
        }
        journalWarn($journalId, $ctx['warnings']);

        return ['rev' => $rev, 'results' => $results, 'warnings' => $ctx['warnings']];
    });

    if (!$respond) return $out;
    reply(['rev' => $out['rev'], 'results' => $out['results']] + (empty($out['repeat']) ? [] : ['repeat' => true]), $out['warnings']);
}

/** Сторожа: ревизия проекта и ревизия содержимого папки. */
function batchGuard(array $project, ?int $ifRev, $ifFolder): void
{
    if ($ifRev !== null && (int) $project['rev'] !== $ifRev) {
        throw new ApiError(t('server.batch.project_changed'), 'conflict', ['rev' => (int) $project['rev']]);
    }
    if (is_array($ifFolder) && isset($ifFolder['folder'])) {
        $folder = dbRow('SELECT id, content_rev FROM folders WHERE id = ? AND project_id = ?',
            [(int) $ifFolder['folder'], (int) $project['id']]);
        if (!$folder) throw new ApiError(t('server.not_found.folder'), 'not_found');
        $want = (int) ($ifFolder['rev'] ?? -1);
        if ((int) $folder['content_rev'] !== $want) {
            throw new ApiError(t('server.batch.folder_changed'), 'conflict',
                ['contentRev' => (int) $folder['content_rev']]);
        }
    }
}

/** Ревизия проекта растёт один раз за пачку — по ней клиент забирает дельту. */
function bumpRev(int $projectId): int
{
    dbRun('UPDATE projects SET rev = rev + 1 WHERE id = ?', [$projectId]);
    return (int) dbValue('SELECT rev FROM projects WHERE id = ?', [$projectId]);
}

/** Содержимое папки изменилось: сторож ifFolderRev должен это заметить. */
function touchFolder(int $folderId, int $rev): void
{
    dbRun('UPDATE folders SET content_rev = ?, rev = ? WHERE id = ?', [$rev, $rev, $folderId]);
}

/**
 * Ссылка на объект: число — это id, строка — временная ссылка ref внутри пачки.
 * Здесь же проверяется, что объект из того же проекта.
 */
function resolveRef($value, array &$ctx, string $what = 'element'): int
{
    if (is_string($value) && isset($ctx['refs'][$value])) return $ctx['refs'][$value];
    if (!is_numeric($value)) throw new ApiError(t('server.batch.bad_ref', ['what' => entityLabel($what)]), 'not_found');

    $id = (int) $value;
    $row = dbRow('SELECT id, project_id FROM elements WHERE id = ?', [$id]);
    sameProject($row, $ctx['projectId'], $what);
    return $id;
}
