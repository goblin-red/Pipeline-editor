<?php
/* Замок и версия прогона: одно правило на все команды прогона.
   Отдаёт: engineLocked(), engineCommand(), runTouch().
   Не делает: не отвечает клиенту — reply() зовёт тот, кто взял замок, уже после фиксации.

   Команда прогона = одна транзакция под блокировкой строки проекта. Тот же замок
   берёт runBatch() для правок схемы, поэтому порядок блокировок всегда один
   и взаимных блокировок нет. Ожидания и запросы в сеть — только вне замка. */

declare(strict_types=1);

/**
 * Выполнить работу под замком проекта. Отдаёт то, что вернула работа.
 *
 * Внутри работы reply() звать нельзя: он завершает скрипт, и транзакция
 * не зафиксируется. Работа возвращает данные, отвечает вызывающий.
 */
function engineLocked(int $projectId, callable $work)
{
    return dbTransaction(static function () use ($projectId, $work) {
        dbRow('SELECT id FROM projects WHERE id = ? FOR UPDATE', [$projectId]);
        return $work();
    });
}

/**
 * Команда прогона целиком: проект запроса, замок, работа. Ответ — у вызывающего.
 *
 * С ключом повтора `commandId` команда делается один раз: тот же ключ и то же тело
 * отдают прежний исход и ничего не выполняют, другое тело под тем же ключом — отказ.
 * Оборвалась связь — повторить команду безопасно.
 */
function engineCommand(callable $work, ?bool &$repeated = null)
{
    $project = requireProject(true);
    $projectId = (int) $project['id'];
    $repeated = false;

    $commandId = trim((string) (input('commandId') ?? ''));
    if ($commandId === '') return engineLocked($projectId, $work);

    $req = request();
    $body = $req['body'] ?: $req['query'];
    unset($body['token']);
    $who = caller();

    return engineLocked($projectId, static function () use (
        $projectId, $who, $commandId, $req, $body, $work, &$repeated
    ) {
        $target = engineCommandTarget($projectId, $who, $body);
        $scope = engineCommandScope($who, $target);
        $hashBody = $body;
        if (($who['role'] ?? '') === 'worker') unset($hashBody['run'], $hashBody['step']);
        $hash = hash('sha256', (string) json_encode([
            'op' => (string) $req['op'], 'body' => $hashBody, 'target' => $target,
        ], JSON_UNESCAPED_UNICODE));

        // Проверка повтора только после project FOR UPDATE: lookup, действие и
        // receipt видят один и тот же сериализованный порядок команд проекта.
        $before = journalFindRepeat($projectId, $scope, $commandId, $hash);
        if ($before) {
            $repeated = true;
            $stored = $before['results'][0] ?? [];
            if (is_array($stored) && isset($stored['_commandReceipt'])) {
                $receipt = (array) $stored['_commandReceipt'];
                if (!empty($receipt['secret'])) {
                    throw new ApiError(
                        ta('agents.lock.receipt'),
                        'conflict',
                        [
                            'code2' => 'replay_secret_unavailable',
                            'committed' => true,
                            'repeat' => true,
                            'receipt' => (array) ($receipt['output'] ?? []),
                            'recovery' => (array) ($receipt['recovery'] ?? []),
                        ]
                    );
                }
                return (array) ($receipt['output'] ?? []);
            }
            // Старые receipt не содержали envelope; оставляем их читаемыми.
            return is_array($stored) ? $stored : [];
        }

        $out = $work();
        $arrayOut = is_array($out) ? $out : ['out' => $out];
        $safe = journalCleanSecrets($arrayOut);
        $identifiers = engineCommandIdentifiers($safe) + $target;
        $receipt = ['_commandReceipt' => [
            'output' => $safe,
            'secret' => journalHasSecrets($arrayOut),
            'identifiers' => $identifiers,
            'recovery' => engineCommandRecovery((string) $req['op'], $identifiers),
        ]];

        $rev = (int) dbValue('SELECT rev FROM projects WHERE id = ?', [$projectId]);
        $journalId = journalOpen($projectId, $rev, $commandId, 'api', [
            'run_id' => $identifiers['run'] ?? null,
            'op_scope' => $scope,
            'request_hash' => $hash,
            'label' => (string) $req['op'],
        ]);
        journalCommandOp($journalId, (string) $req['op'], $body, $receipt);
        return $out;
    });
}

/** Стабильная область повтора: не даёт другому caller читать receipt по одному commandId. */
function engineCommandScope(array $who, array $target): string
{
    if (!empty($who['user_id'])) {
        $principal = ['role' => $who['role'], 'user' => (int) $who['user_id']];
    } elseif (!empty($who['agent_id'])) {
        // Агентный пропуск должен видеть receipt команды того же worker/lead после
        // отзыва короткого step/run token, поэтому token_id сюда не входит.
        $principal = ['role' => $who['role'], 'agent' => (int) $who['agent_id']];
    } elseif (!empty($who['token_id'])) {
        $principal = ['role' => $who['role'], 'token' => (int) $who['token_id']];
    } else {
        $principal = ['role' => $who['role'], 'ip' => clientIp()];
    }
    $principal['run'] = $target['run'] ?? null;
    $principal['step'] = $target['step'] ?? null;
    return 'command:' . substr(hash('sha256', (string) json_encode($principal)), 0, 32);
}

/** Целевой run/step входит в auth scope и не даёт смешать попытки одного агента. */
function engineCommandTarget(int $projectId, array $who, array $body): array
{
    $target = [];
    if (!empty($who['run_id'])) $target['run'] = (int) $who['run_id'];
    if (!empty($who['step_id'])) $target['step'] = (int) $who['step_id'];

    if (($who['role'] ?? '') === 'worker' && !empty($who['agent_id'])) {
        $asked = isset($body['step']) && is_numeric($body['step']) ? (int) $body['step'] : 0;
        if (!isset($target['step'])) {
            if (!$asked) {
                throw new ApiError(ta('agents.lock.need_exact_step'), 'invalid');
            }
            $row = dbRow(
                'SELECT id, run_id, agent_id FROM run_steps WHERE id = ? AND project_id = ? AND agent_id = ?',
                [$asked, $projectId, (int) $who['agent_id']]
            );
            if (!$row) throw new ApiError(ta('agents.steps.not_your_step'), 'scope');
            $target['step'] = (int) $row['id'];
            $target['run'] = (int) $row['run_id'];
            if (isset($body['run'])) {
                $askedRun = trim((string) $body['run']);
                if (ctype_digit($askedRun) && (int) $askedRun !== (int) $row['run_id']) {
                    throw new ApiError(ta('agents.steps.other_run'), 'scope');
                }
            }
        }
    }

    if (($who['role'] ?? '') === 'worker' && isset($target['step'])) {
        $targetStep = dbRow(
            'SELECT id, run_id, agent_id FROM run_steps WHERE id = ? AND project_id = ?',
            [(int) $target['step'], $projectId]
        );
        if (!$targetStep) throw new ApiError(ta('agents.lock.step_not_found'), 'not_found');
        workerAuthorityFresh($who, $projectId, $targetStep);
    }

    if (!isset($target['run']) && isset($body['run'])) {
        $asked = trim((string) $body['run']);
        if (preg_match('/^r(\d+)$/ui', $asked, $found)) {
            $target['run'] = (int) dbValue(
                'SELECT id FROM runs WHERE project_id = ? AND `no` = ?', [$projectId, (int) $found[1]]
            );
        } elseif (ctype_digit($asked)) {
            $target['run'] = (int) $asked;
        }
    }
    return array_filter($target, static fn(int $id): bool => $id > 0);
}

/** Безопасные ссылки, достаточные для восстановления после secret replay. */
function engineCommandIdentifiers(array $out): array
{
    $ids = [];
    if (isset($out['run']['id'])) $ids['run'] = (int) $out['run']['id'];
    if (isset($out['step']['run'])) $ids['run'] = (int) $out['step']['run'];
    if (isset($out['step']['id'])) $ids['step'] = (int) $out['step']['id'];
    if (isset($out['step']['no'])) $ids['no'] = (int) $out['step']['no'];
    if (isset($out['step']['attempt'])) $ids['attempt'] = (int) $out['step']['attempt'];
    return $ids;
}

function engineCommandRecovery(string $op, array $identifiers): array
{
    $recoveryOp = match ($op) {
        'run.start', 'run.attach' => 'run.attach',
        'step.open', 'step.reissue' => 'step.reissue',
        default => '',
    };
    if ($recoveryOp === '') return [];
    return array_filter([
        'op' => $recoveryOp,
        'run' => $identifiers['run'] ?? null,
        'step' => $identifiers['step'] ?? null,
    ], static fn($value): bool => $value !== null && $value !== '');
}

/** Прогон изменился — ждущие должны проснуться. Звать только после настоящей записи. */
function runTouch(int $runId): void
{
    dbRun('UPDATE runs SET version = version + 1 WHERE id = ?', [$runId]);
}
