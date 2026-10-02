<?php
/* Журнал: кто, чем и что изменил — со значениями «до» и «после».
   Отдаёт: journalOpen(), journalOp(), journalWarn(), journalFindRepeat(), markDeleted().
   Не делает: не решает, можно ли операцию, и не считает дельту (journal/changes.php). */

declare(strict_types=1);

/** Сколько текста храним в значениях «до»: длиннее — только длина. */
const JOURNAL_VALUE_LIMIT = 4096;

/** Откуда правка — значения ENUM journal.via; прочее из запроса пишется как api. */
const JOURNAL_VIA = ['editor', 'api', 'assistant', 'constructor', 'tool', 'system', 'legacy'];

/**
 * Начать запись пачки. Возвращает id строки журнала.
 * op_scope — пространство ключа повтора: номер прогона или пусто для обычной правки.
 * user_id — автор, если правку делает не вызывающий (фоновое задание ИИ пишет от того, кто спросил).
 */
function journalOpen(int $projectId, int $rev, ?string $operationId, string $via, array $extra = []): int
{
    $who = caller();
    dbRun(
        'INSERT INTO journal (project_id, rev, run_id, op_scope, operation_id, request_hash, via, user_id, token_id, agent_id, label)
         VALUES (?,?,?,?,?,?,?,?,?,?,?)',
        [
            $projectId, $rev,
            $extra['run_id'] ?? $who['run_id'] ?? null,
            $extra['op_scope'] ?? '',
            $operationId !== '' ? $operationId : null,
            $extra['request_hash'] ?? null,
            in_array($via, JOURNAL_VIA, true) ? $via : 'api',
            $extra['user_id'] ?? $who['user_id'], $who['token_id'], $who['agent_id'],
            mb_substr((string) ($extra['label'] ?? ''), 0, 64),
        ]
    );
    return dbId();
}

/** Одна операция пачки: как прислана, что было до, что сервер ответил. */
function journalOp(int $journalId, int $n, string $op, array $args = [], ?array $prev = null, ?array $result = null, ?int $folderId = null, ?int $targetId = null, ?int $targetNo = null): void
{
    dbRun(
        'INSERT INTO journal_ops (journal_id, n, op, target_id, target_no, folder_id, args, prev, result)
         VALUES (?,?,?,?,?,?,?,?,?)',
        [
            $journalId, $n, $op, $targetId, $targetNo, $folderId,
            journalJson($args), journalJson($prev), journalJson($result),
        ]
    );
}

function journalWarn(int $journalId, array $warnings): void
{
    if (!$warnings) return;
    dbRun('UPDATE journal SET warnings = ? WHERE id = ?', [journalJson(array_values($warnings)), $journalId]);
}

/** Длинный текст в журнале заменяется его длиной: журнал не библиотека. */
function journalJson(?array $data): ?string
{
    if ($data === null) return null;
    $data = journalCleanSecrets($data);
    array_walk_recursive($data, static function (&$v) {
        if (is_string($v) && strlen($v) > JOURNAL_VALUE_LIMIT) {
            $v = [t('server.journal.long_text') => t('server.journal.bytes', ['n' => strlen($v)])];
        }
    });
    return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/** Удалить секретные поля на любой глубине: step.token не должен пережить ответ. */
function journalCleanSecrets(array $data): array
{
    foreach ($data as $key => $value) {
        if (is_string($key) && in_array(strtolower($key), ['token', 'howto'], true)) {
            unset($data[$key]);
            continue;
        }
        if (is_array($value)) $data[$key] = journalCleanSecrets($value);
        elseif (is_string($value)) {
            $data[$key] = (string) preg_replace(
                ['/\b(gbs|gbr|gba|gbl)_[A-Za-z0-9]+/', '/\bapikey_[A-Za-z0-9_]+/', '/\bsk-[A-Za-z0-9\-]{10,}/'],
                ['$1_…', 'apikey_…', 'sk-…'],
                $value
            );
        }
    }
    return $data;
}

/** Есть ли в результате секрет, который нельзя воспроизводить из receipt. */
function journalHasSecrets(array $data): bool
{
    foreach ($data as $key => $value) {
        if (is_string($key) && in_array(strtolower($key), ['token', 'howto'], true)) return true;
        if (is_array($value) && journalHasSecrets($value)) return true;
    }
    return false;
}

/**
 * Точный безопасный receipt команды. В отличие от обычного аудита его нельзя
 * обрезать: повтор обязан получить исход целиком или явный отказ для секрета.
 */
function journalCommandOp(int $journalId, string $op, array $args, array $receipt): void
{
    dbRun(
        'INSERT INTO journal_ops (journal_id, n, op, args, result) VALUES (?,0,?,?,?)',
        [
            $journalId,
            $op,
            json_encode(journalCleanSecrets($args), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            json_encode($receipt, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]
    );
}

/**
 * Тот же ключ повтора уже применялся?
 *   • то же тело — вернуть прежний ответ;
 *   • другое тело — 409, это уже другая команда под тем же именем.
 */
function journalFindRepeat(int $projectId, string $opScope, string $operationId, string $requestHash): ?array
{
    if ($operationId === '') return null;

    $row = dbRow(
        'SELECT * FROM journal WHERE project_id = ? AND op_scope = ? AND operation_id = ?',
        [$projectId, $opScope, $operationId]
    );
    if (!$row) return null;

    if ($row['request_hash'] && $row['request_hash'] !== $requestHash) {
        throw new ApiError(t('server.journal.repeat_key_taken'), 'conflict');
    }

    $results = dbAll('SELECT result FROM journal_ops WHERE journal_id = ? ORDER BY n', [$row['id']]);
    return [
        'rev'     => (int) $row['rev'],
        'results' => array_map(static function (array $r) {
            if (!$r['result']) return null;
            $decoded = json_decode($r['result'], true);
            return is_array($decoded) ? journalCleanSecrets($decoded) : null;
        }, $results),
        // Повтор отвечает как первый раз — с теми же предупреждениями (journalWarn).
        'warnings' => json_decode((string) $row['warnings'], true) ?: [],
    ];
}

/* ── Одиночные команды прогона ────────────────────────────────
   Команды run.* и step.* идут не пачкой, но в журнале должны быть
   так же: кто, чем и что сделал. Имя команды запоминается роутером,
   проект — при проверке прав, запись делается при ответе. */

function journalSingleStart(string $op, array $args): void
{
    $GLOBALS['goblin_single'] = ['op' => $op, 'args' => $args];
}

function journalSingleProject(array $project): void
{
    if (isset($GLOBALS['goblin_single'])) $GLOBALS['goblin_single']['project'] = $project;
}

/** Зовётся из reply(): ответ уже готов, значит команда состоялась. */
function journalSingleWrite(array $body): void
{
    $single = $GLOBALS['goblin_single'] ?? null;
    if (!$single || empty($single['project'])) return;
    unset($GLOBALS['goblin_single']);

    $projectId = (int) $single['project']['id'];
    // Проект удалили, пока запрос ждал (долгий step.take, run.state): писать некуда.
    // Без этой проверки запись падала по внешнему ключу, и готовый ответ превращался в 500.
    $rev = dbValue('SELECT rev FROM projects WHERE id = ?', [$projectId]);
    if ($rev === null || $rev === false) return;
    $rev = (int) $rev;

    // Пропуска в журнал не попадают: там секреты.
    $args = journalCleanSecrets($single['args']);
    $result = journalCleanSecrets($body);

    $journalId = journalOpen($projectId, $rev, null, 'api', ['label' => $single['op']]);
    journalOp($journalId, 0, $single['op'], $args, null, $result,
        isset($args['folder']) ? (int) $args['folder'] : null,
        isset($result['step']['element']) ? (int) $result['step']['element'] : null,
        isset($result['step']['no']) ? (int) $result['step']['no'] : null);
}

/** Надгробие: клиент узнаёт об удалении только по нему. */
function markDeleted(int $projectId, string $entity, int $entityId, int $rev, array $extra = []): void
{
    dbRun(
        'INSERT INTO deletions (project_id, entity, entity_id, `no`, folder_id, title, rev) VALUES (?,?,?,?,?,?,?)',
        [$projectId, $entity, $entityId, $extra['no'] ?? null, $extra['folder_id'] ?? null,
         mb_substr((string) ($extra['title'] ?? ''), 0, 512), $rev]
    );
}
