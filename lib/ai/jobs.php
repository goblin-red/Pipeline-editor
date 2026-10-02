<?php
/* Фоновые задания ИИ: очередь, запуск worker и само выполнение.
   Отдаёт: aiLaunch(), aiExecAllowed(), aiJobRun(), aiJobAlive(), aiJobContext(), aiJobLang(), aiSweep(), aiDryRun().
   Конструктор ведёт свой ход — aiBuildRun (ai/build.php); здесь чат-помощник.
   Не делает: не решает, что модель может менять — это ai/compile.php.

   Web-запрос не ждёт модель: он кладёт задание в очередь и отвечает сразу.
   Язык человека (cookie) задание уносит с собой в context.lang: у процесса CLI cookie нет. */

declare(strict_types=1);

/**
 * Запустить задание в фоне. Есть php-cli и exec — отдельным процессом (путь к php — config.php).
 * Нет (хостинг без exec) — в этом же запросе, уже после ответа браузеру (решение хозяина 30.09.2026).
 */
function aiLaunch(int $jobId): void
{
    $php = (string) (config()['php_cli'] ?? '');
    if ($php !== '' && is_file($php) && aiExecAllowed()) {
        $script = dirname(__DIR__, 2) . '/bin/server.php';
        $command = escapeshellarg($php) . ' ' . escapeshellarg($script) . ' ai-job ' . (int) $jobId . ' > /dev/null 2>&1 &';
        @exec($command);
        return;
    }
    register_shutdown_function(static function () use ($jobId): void {
        aiAfterReply();
        aiJobRun($jobId);
    });
}

/** Можно ли запускать процессы: exec есть и не выключен в disable_functions. */
function aiExecAllowed(): bool
{
    if (!function_exists('exec')) return false;
    $off = array_map('trim', explode(',', (string) ini_get('disable_functions')));
    return !in_array('exec', $off, true);
}

/** Отпустить браузер: ответ уже отдан целиком, дальше запрос работает без него. */
function aiAfterReply(): void
{
    ignore_user_abort(true);
    @set_time_limit(300);
    if (function_exists('fastcgi_finish_request')) { fastcgi_finish_request(); return; }
    if (function_exists('litespeed_finish_request')) { litespeed_finish_request(); return; }
    while (ob_get_level() > 0) @ob_end_flush();
    flush();
}

/**
 * Контекст задания в очередь: что прислал запрос и язык человека — на нём задание пишет ошибки
 * и подписи журнала (t()), и на нём же говорит модель (ta(), правила) — его ставит aiJobRun.
 */
function aiJobContext(array $context): string
{
    return json_encode($context + ['lang' => lang()], JSON_UNESCAPED_UNICODE);
}

/** Выполнить задание: собрать запрос, спросить модель, разобрать ответ. */
function aiJobRun(int $jobId): void
{
    $job = dbRow('SELECT j.*, c.project_id, c.kind AS chat_kind, c.user_id AS chat_user FROM ai_jobs j JOIN ai_chats c ON c.id = j.chat_id WHERE j.id = ?', [$jobId]);
    if (!$job) return;
    // Захват атомарный: второй процесс с тем же заданием не изменит строку и уйдёт.
    if (dbRun("UPDATE ai_jobs SET state = 'running', started_at = NOW(3) WHERE id = ? AND state = 'queued'", [$jobId]) !== 1) return;

    $project = dbRow('SELECT * FROM projects WHERE id = ?', [$job['project_id']]);
    $context = json_decode((string) $job['context'], true) ?: [];
    /* Встроенный ИИ говорит на языке интерфейса того, кто поставил задание (решение хозяина 01.10.2026):
       схема может быть русской, а человек — в английском интерфейсе. Язык проекта — только запасной
       (старые задания без языка в контексте). Правила модели, подсказки и ответ — на этом языке. */
    $lang = (string) ($context['lang'] ?? '');
    langAgents(in_array($lang, LANGS, true) ? $lang : (string) ($project['lang'] ?? 'ru'));
    $asker = ($job['user_id'] ?? $job['chat_user']) ? (int) ($job['user_id'] ?: $job['chat_user']) : null;
    aiAccessUse($asker);   // доступ к модели — того, кто спросил
    apiCallAbout($job['project_id'] ? (int) $job['project_id'] : null, (string) $job['kind']);   // журнал вызовов
    aiJobLang($context);
    $folderId = $context['folder'] ?? null;

    // Конструктор — свой ход: опрос карточками и черновик (ai/build.php).
    if ($job['chat_kind'] === 'constructor') {
        aiBuildRun($job, $project);
        return;
    }

    try {
        $history = dbAll('SELECT role, body FROM ai_messages WHERE chat_id = ? ORDER BY id DESC LIMIT 12', [$job['chat_id']]);
        $history = array_reverse($history);

        $messages = [[
            'role' => 'system',
            'content' => aiRules('assistant') . "\n\n" . aiSchemaHint()
                . ta('agents.ai.scheme_now', ['scheme' => json_encode(aiContext($project, $folderId, $context), JSON_UNESCAPED_UNICODE)]),
        ]];
        foreach ($history as $item) {
            $messages[] = ['role' => $item['role'] === 'user' ? 'user' : 'assistant', 'content' => (string) $item['body']];
        }

        $answer = aiAsk($messages);
        $usage = [$answer['model'], $answer['usage']['prompt'], $answer['usage']['completion'],
                  $answer['usage']['total'], $answer['usage']['cached'], $answer['usage']['known'] ? 1 : 0];
        /* Ход мысли для человека: сначала то, что модель думала про себя,
           дальше — что сделал сервер с её правками. Показывается сворачиваемым блоком. */
        $think = $answer['think'] !== '' ? [$answer['think']] : [];

        // «Стоп», пока модель думала, — дальше не работаем (окончательно решает итог под замком).
        if (!aiJobAlive($jobId, $usage)) return;

        $data = $answer['data'] ?? null;
        if (!$data) throw new RuntimeException(t('agents.ai.no_json'));

        $say = (string) ($data['say'] ?? $data['answer'] ?? '');
        $changes = (array) ($data['changes'] ?? []);
        $compiled = aiCompile($changes, (int) ($folderId ?: 0));

        /* Правки проверяем до показа: пробная пачка в откатываемой транзакции.
           Сервер отказал или после правок прогон встанет (новая помеха предпроверки) —
           модель получает причину и исправляется один раз. Отказ остался — не предлагаем
           то, что не применится; осталась помеха — предлагаем, но с предупреждением. */
        $check = aiDryRun($project, $compiled['ops'], $folderId ? (int) $folderId : null);
        if ($compiled['ops']) {
            $think[] = ta('agents.ai.think_check', ['n' => count($compiled['ops']),
                'result' => $check['error'] !== null ? ta('agents.ai.check_refused', ['error' => $check['error']])
                    : ($check['new'] ? ta('agents.ai.check_problems', ['list' => implode('; ', $check['new'])]) : ta('agents.ai.check_passes')),
                'dropped' => $compiled['dropped'] ? ta('agents.ai.think_dropped', ['list' => implode('; ', $compiled['dropped'])]) : '']);
        }
        if ($check['error'] !== null || $check['new']) {
            $why = $check['error'] !== null ? ta('agents.ai.edits_refused', ['error' => $check['error']])
                : ta('agents.ai.edits_stall', ['list' => implode('; ', $check['new'])]);
            $messages[] = ['role' => 'assistant', 'content' => $answer['raw']];
            $messages[] = ['role' => 'user', 'content' => ta('agents.ai.fix_and_return', ['why' => $why])];
            $retry = aiAsk($messages);
            if ($retry['think'] !== '') $think[] = ta('agents.ai.think_retry', ['think' => $retry['think']]);
            // Расход повтора — к общему: [модель, вход, выход, всего, из кэша, известен ли].
            $usage[1] += $retry['usage']['prompt'];
            $usage[2] += $retry['usage']['completion'];
            $usage[3] += $retry['usage']['total'];
            $usage[4] += $retry['usage']['cached'];
            if (is_array($retry['data'])) {
                $data = $retry['data'];
                $say = (string) ($data['say'] ?? $say);
                $compiled = aiCompile((array) ($data['changes'] ?? []), (int) ($folderId ?: 0));
                $check = aiDryRun($project, $compiled['ops'], $folderId ? (int) $folderId : null);
            }
            if ($check['error'] !== null) {
                $say = ($say !== '' ? $say . "\n\n" : '') . ta('agents.ai.say_refused', ['error' => $check['error']]);
                $compiled['ops'] = [];
            } elseif ($check['new']) {
                $say = ($say !== '' ? $say . "\n\n" : '') . ta('agents.ai.say_stall', ['list' => implode("\n- ", $check['new'])]);
            }
        }

        // Объяснение модели остаётся, план правок — списком под ним.
        if ($compiled['ops']) $say = ($say !== '' ? $say . "\n\n" : '') . ta('agents.ai.changes_head') . "\n- " . implode("\n- ", aiPreview($compiled['ops'], (int) $project['id']));
        if ($compiled['dropped']) $say .= ta('agents.ai.say_dropped', ['list' => implode('; ', $compiled['dropped'])]);

        // Опасное (удаления, правка ТЗ и материалов) всегда ждёт «Применить»; прочее — по настройке проекта.
        $needsOk = $compiled['ops'] && (aiSensitive($compiled['ops']) || (int) $project['ai_confirm'] === 1);
        $preview = aiPreview($compiled['ops'], (int) $project['id']);

        /* Итог — одной транзакцией: замок проекта, затем строка задания. «Стоп» успел раньше —
           только расход: ни ответа, ни правок схемы. Иначе ответ, правки и состояние — вместе. */
        engineLocked((int) $project['id'], static function () use ($job, $jobId, $project, $compiled, $data, $say, $think, $usage, $needsOk, $preview, $asker): void {
            if (!aiJobAlive($jobId, $usage, true)) return;
            dbRun('INSERT INTO ai_messages (chat_id, role, body, think) VALUES (?,?,?,?)',
                [$job['chat_id'], 'assistant', $say . ($needsOk ? ta('agents.ai.press_apply') : ''), implode("\n\n", $think) ?: null]);
            $messageId = dbId();
            if ($compiled['ops'] && !$needsOk) {
                // В журнал — от помощника и от того, кто спросил: у процесса CLI вызывающий — гость.
                runBatch($project, $compiled['ops'], 'aiApplyOp', false, [
                    'via' => 'assistant', 'label' => t('server.ai.journal_job', ['job' => $jobId]), 'user_id' => $asker,
                ]);
                // Объяснение модели остаётся, список правок сворачивается в короткий итог.
                dbRun('UPDATE ai_messages SET body = ? WHERE id = ?',
                    [trim(preg_replace('/\n\n' . preg_quote(ta('agents.ai.changes_head'), '/') . '.*$/s', '', $say)) . ta('agents.ai.say_applied', ['summary' => aiSummary($compiled['ops'])]), $messageId]);
            }
            // Чем кончилось — последней строкой рассуждений.
            if ($compiled['ops']) {
                dbRun('UPDATE ai_messages SET think = CONCAT(COALESCE(think, \'\'), ?) WHERE id = ?',
                    ["\n" . ($needsOk ? ta('agents.ai.think_wait') : ta('agents.ai.think_now')), $messageId]);
            }
            dbRun(
                'UPDATE ai_jobs SET state = ?, response = ?, ops = ?, preview = ?, message_id = ?, model = ?,
                        tokens_prompt = ?, tokens_completion = ?, tokens_total = ?, tokens_cached = ?, usage_known = ?, finished_at = NOW(3)
                  WHERE id = ?',
                [
                    $compiled['ops'] ? ($needsOk ? 'proposal' : 'applied') : 'done',
                    json_encode($data, JSON_UNESCAPED_UNICODE),
                    json_encode($compiled['ops'], JSON_UNESCAPED_UNICODE),
                    json_encode($preview, JSON_UNESCAPED_UNICODE),
                    $messageId, ...$usage, $jobId,
                ]
            );
            dbRun('UPDATE ai_chats SET updated_at = NOW(3) WHERE id = ?', [$job['chat_id']]);
        });
    } catch (Throwable $error) {
        // Сбой не затирает «Стоп»: отменённое остаётся отменённым.
        dbRun("UPDATE ai_jobs SET state = 'failed', error = ?, finished_at = NOW(3) WHERE id = ? AND state = 'running'",
            [mb_substr($error->getMessage(), 0, 500), $jobId]);
    }
}

/**
 * Задание всё ещё в работе? Пока модель думала, человек мог нажать «Стоп»: тогда только расход
 * (за него уже заплачено) и false. $lock — строка задания под замком: звать в итоговой транзакции.
 */
function aiJobAlive(int $jobId, array $usage, bool $lock = false): bool
{
    if (dbValue('SELECT state FROM ai_jobs WHERE id = ?' . ($lock ? ' FOR UPDATE' : ''), [$jobId]) === 'running') return true;
    dbRun('UPDATE ai_jobs SET model = ?, tokens_prompt = ?, tokens_completion = ?, tokens_total = ?,
                  tokens_cached = ?, usage_known = ?, finished_at = NOW(3) WHERE id = ?', [...$usage, $jobId]);
    return false;
}

/** Язык человека для t() в процессе задания — тот, с которым задание поставили (aiJobContext). */
function aiJobLang(array $context): void
{
    $lang = (string) ($context['lang'] ?? '');
    if (in_array($lang, LANGS, true)) $_COOKIE['goblin_lang'] = $lang;   // lang() читает cookie
}

/**
 * Зависшие задания закрываем сами: упал процесс или не нашёлся php-cli —
 * иначе «думает…» висело бы вечно. Десять минут — больше, чем ждёт модель.
 * Без разговора — во всей установке (админка). Отдаёт, сколько закрыто.
 */
function aiSweep(?int $chatId = null): int
{
    return dbRun("UPDATE ai_jobs SET state = 'failed', error = ?, finished_at = NOW(3)
                   WHERE (? IS NULL OR chat_id = ?) AND state IN ('queued', 'running')
                     AND created_at < NOW(3) - INTERVAL 10 MINUTE",
        [t('server.ai.no_answer'), $chatId, $chatId]);
}

/**
 * Пробный прогон правок: та же пачка, что при «Применить», в транзакции, которую
 * откатываем. Отдаёт ['error' => отказ сервера или null, 'new' => помехи прогона,
 * которых до правок не было]. run.prepare не пробуем: он трогает файлы и всё равно
 * ждёт «Применить».
 */
function aiDryRun(array $project, array $ops, ?int $folderId = null): array
{
    $ok = ['error' => null, 'new' => []];
    if (!$ops || in_array('run.prepare', array_column($ops, 'op'), true)) return $ok;
    $problems = static fn() => array_column(folderPrecheck(folderRow($folderId, (int) $project['id']))['problems'], 'say');
    $before = $folderId ? $problems() : [];
    dbBegin();
    $GLOBALS['dbUndo'] = [];
    batchDryRun(true);
    try {
        runBatch($project, $ops, 'aiApplyOp', false);
        return ['error' => null, 'new' => $folderId ? array_values(array_diff($problems(), $before)) : []];
    } catch (Throwable $error) {
        return ['error' => $error->getMessage(), 'new' => []];
    } finally {
        batchDryRun(false);
        dbRollback();
        dbUndoDisk();
    }
}
