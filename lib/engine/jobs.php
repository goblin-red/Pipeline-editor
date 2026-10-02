<?php
/* Заявки во внешние сервисы: Krea и любые другие платные вызовы.
   Отдаёт: stepJob(), jobsOfStep().
   Не делает: сам никуда не ходит — только ведёт учёт того, что сделал worker.

   Заявка пишется ДО отправки и обновляется сразу после ответа сервиса.
   Поэтому выдуманный job_id виден: заявка без номера не пускает сдачу. */

declare(strict_types=1);

/** POST step.job — add (до отправки) или set (после ответа). */
function stepJob(): void
{
    // Под замком: лимит платных вызовов и сквозной номер заявки не разъедутся
    // при двух одновременных add. Состояние шага не меняется — версия не растёт.
    reply(engineCommand(static function (): array {
        [$project, $run, $step] = stepOwn(false);
        $projectId = (int) $project['id'];
        $action = (string) (input('action') ?? 'add');

        if ($action === 'add') {
            $paid = input('paid');
            $limit = $step['element_id'] ? (int) runProp((int) $step['element_id'], 'paid_calls', 1) : 1;
            $spent = (int) dbValue("SELECT COUNT(*) FROM run_jobs WHERE step_id = ? AND paid = 1 AND state <> 'rejected'", [$step['id']]);
            if (($paid === null || $paid) && $spent >= $limit) {
                throw new ApiError(ta('agents.jobs.paid_limit', ['limit' => $limit]), 'conflict', ['code2' => 'paid_limit']);
            }

            // Номер сквозной по прогону: раньше каждый шаг начинал с j1, и worker
            // не мог отличить свою новую заявку от прошлой.
            $ref = 'j' . (1 + (int) dbValue('SELECT COUNT(*) FROM run_jobs WHERE run_id = ?', [(int) $run['id']]));
            dbRun(
                'INSERT INTO run_jobs (project_id, run_id, step_id, ref, provider, tool, model, request, paid)
                 VALUES (?,?,?,?,?,?,?,?,?)',
                [$projectId, (int) $run['id'], (int) $step['id'], $ref,
                 (string) (input('provider') ?? ''), (string) (input('tool') ?? ''), (string) (input('model') ?? ''),
                 json_encode(input('request') ?? new stdClass(), JSON_UNESCAPED_UNICODE),
                 ($paid === null || $paid) ? 1 : 0]
            );
            $job = dbRow('SELECT * FROM run_jobs WHERE id = ?', [dbId()]);
            eventStep($step, 'job',
                ta('agents.jobs.ev_request', ['ref' => $job['ref']]) . trim("{$job['provider']} {$job['tool']} {$job['model']}")
                . (($job['paid'] ?? 0) ? ta('agents.jobs.paid') : ta('agents.jobs.free')),
                input('request'), ['actor' => 'worker']);

            return ['job' => jobShape($job)];
        }

        $ref = (string) (input('ref') ?? '');
        $row = dbRow('SELECT * FROM run_jobs WHERE step_id = ? AND ref = ?', [$step['id'], $ref]);
        if (!$row) throw new ApiError(ta('agents.jobs.no_such', ['ref' => $ref]), 'not_found');

        $set = [];
        $args = [];
        if (($jobId = (string) (input('jobId') ?? '')) !== '') { $set[] = 'job_id = ?'; $args[] = $jobId; }
        if (($state = (string) (input('state') ?? '')) !== '') {
            if (!in_array($state, ['sent', 'done', 'failed', 'rejected'], true)) throw new ApiError(ta('agents.jobs.states'));
            $set[] = 'state = ?';
            $args[] = $state;
            // Отказ сервиса не считается платным вызовом.
            if ($state === 'rejected') $set[] = 'paid = 0';
        }
        if (($error = (string) (input('error') ?? '')) !== '') { $set[] = 'error = ?'; $args[] = mb_substr($error, 0, 500); }
        if ($response = input('responseAsset')) { $set[] = 'response_asset_id = ?'; $args[] = (int) $response; }
        if (!$set) throw new ApiError(ta('agents.jobs.nothing_to_change'));

        $args[] = (int) $row['id'];
        dbRun('UPDATE run_jobs SET ' . implode(', ', $set) . ' WHERE id = ?', $args);
        $after = dbRow('SELECT * FROM run_jobs WHERE id = ?', [(int) $row['id']]);
        eventStep($step, 'job-done',
            ta('agents.jobs.ev_done', ['ref' => $after['ref'], 'state' => $after['state']])
            . (($after['job_id'] ?? '') !== '' ? ta('agents.jobs.ev_job', ['id' => $after['job_id']]) : '')
            . (($after['error'] ?? '') !== '' ? " · {$after['error']}" : ''),
            input('response'), ['actor' => 'worker']);

        return ['job' => jobShape($after)];
    }));
}

function jobShape(array $row): array
{
    return [
        'id'       => (int) $row['id'],
        'ref'      => (string) $row['ref'],
        'provider' => (string) $row['provider'],
        'tool'     => (string) $row['tool'],
        'model'    => (string) $row['model'],
        'jobId'    => (string) $row['job_id'],
        'state'    => (string) $row['state'],
        'paid'     => (int) $row['paid'] === 1,
        'error'    => (string) $row['error'],
        'at'       => $row['created_at'],
    ];
}

/** Заявки шага — их видит и worker, и leader при приёмке. */
function jobsOfStep(int $stepId): array
{
    return array_map('jobShape', dbAll('SELECT * FROM run_jobs WHERE step_id = ? ORDER BY id', [$stepId]));
}
