<?php
/* Чат-помощник: человек говорит словами, схема меняется операциями.
   Отдаёт: aiChatGet(), aiChatSend(), aiChatApply(), aiChatCancel(), aiChatDelete().
   Не делает: не зовёт модель сам — это фоновое задание (ai/jobs.php).

   Правки не применяются молча: сначала план, потом «Применить». */

declare(strict_types=1);

/** GET ai.chat.get */
function aiChatGet(): void
{
    $project = requireProject(false);
    $chatId = inputInt('chat');

    if (!$chatId) {
        /* История — только разговоры открытой папки: разговор помнит папку, где его начали.
           Без папки — все разговоры проекта, как раньше. */
        $folderId = inputInt('folder');
        $rows = $folderId
            ? dbAll('SELECT * FROM ai_chats WHERE project_id = ? AND folder_id = ? AND kind = ?
                      ORDER BY updated_at DESC LIMIT 50', [$project['id'], $folderId, 'assistant'])
            : dbAll('SELECT * FROM ai_chats WHERE project_id = ? AND kind = ? ORDER BY updated_at DESC LIMIT 30',
                [$project['id'], 'assistant']);
        reply(['chats' => array_map('aiChatShape', $rows)]);
    }

    $chat = dbRow('SELECT * FROM ai_chats WHERE id = ? AND project_id = ?', [$chatId, $project['id']]);
    if (!$chat) throw new ApiError(t('agents.ai.no_chat'), 'not_found');
    aiSweep($chatId);

    $messages = dbAll('SELECT id, role, body, think, created_at FROM ai_messages WHERE chat_id = ? ORDER BY id', [$chatId]);

    /* Счёт по каждому ответу: задание знает, какое сообщение им написано.
       Общая сумма по разговору есть ниже, но по одной реплике видно,
       во что обошёлся именно этот ответ. */
    $perMessage = [];
    foreach (dbAll('SELECT message_id, model, tokens_prompt, tokens_completion, tokens_cached
                      FROM ai_jobs WHERE chat_id = ? AND message_id IS NOT NULL', [$chatId]) as $job) {
        $perMessage[(int) $job['message_id']] = [
            'in'     => (int) $job['tokens_prompt'],
            'out'    => (int) $job['tokens_completion'],
            'cached' => (int) $job['tokens_cached'],
            'model'  => (string) $job['model'],
        ];
    }
    foreach ($messages as &$message) {
        $message['tokens'] = $perMessage[(int) $message['id']] ?? null;
    }
    unset($message);
    $jobs = dbAll('SELECT id, state, kind, ops, preview, error FROM ai_jobs WHERE chat_id = ? ORDER BY id DESC LIMIT 5', [$chatId]);

    // Счёт токенов по разговору: их видно прямо под полем ввода.
    $spent = dbRow(
        'SELECT COALESCE(SUM(tokens_prompt),0) AS `in`, COALESCE(SUM(tokens_completion),0) AS `out`
           FROM ai_jobs WHERE chat_id = ?',
        [$chatId]
    ) ?: ['in' => 0, 'out' => 0];

    reply([
        'chat' => aiChatShape($chat),
        'tokens' => ['in' => (int) $spent['in'], 'out' => (int) $spent['out']],
        'messages' => $messages,
        'jobs' => array_map(static fn(array $j) => [
            'id' => (int) $j['id'], 'state' => $j['state'], 'kind' => $j['kind'],
            'ops' => $j['ops'] ? json_decode($j['ops'], true) : null,
            'preview' => $j['preview'] ? json_decode($j['preview'], true) : null,
            'error' => $j['error'],
        ], $jobs),
    ]);
}

function aiChatShape(array $row): array
{
    return ['id' => (int) $row['id'], 'title' => $row['title'], 'state' => $row['state'], 'at' => $row['updated_at']];
}

/** POST ai.chat.send — вопрос уходит в очередь, ответ придёт фоном. */
function aiChatSend(): void
{
    $project = requireProject(true);
    $projectId = (int) $project['id'];
    aiLimit($projectId);

    $text = trim((string) (input('text') ?? ''));
    if ($text === '') throw new ApiError(t('agents.ai.empty_question'));

    // Чат и папка — только из этого проекта: чужое отвечает «не найдено».
    $chatId = inputInt('chat');
    if ($chatId) {
        $chat = dbRow('SELECT id FROM ai_chats WHERE id = ? AND project_id = ?', [$chatId, $projectId]);
        if (!$chat) throw new ApiError(t('agents.ai.no_chat'), 'not_found');
    }
    $folderId = inputInt('folder');
    if ($folderId) folderRow($folderId, $projectId);
    /* «Весь проект»: модель смотрит все папки, а разговор всё равно живёт в папке,
       где его начали, — в её истории он и виден. */
    $whole = !empty(input('whole'));

    if (!$chatId) {
        dbRun('INSERT INTO ai_chats (kind, project_id, project_key, folder_id, user_id, requester, title) VALUES (?,?,?,?,?,?,?)',
            ['assistant', $projectId, $project['url_key'], $folderId ?: null, caller()['user_id'],
             caller()['user_id'] ? 'user:' . caller()['user_id'] : clientIp(),
             mb_substr($text, 0, 60)]);
        $chatId = dbId();
    }
    dbRun('INSERT INTO ai_messages (chat_id, role, body) VALUES (?,?,?)', [$chatId, 'user', $text]);

    dbRun('INSERT INTO ai_jobs (chat_id, kind, request_id, state, user_id, context) VALUES (?,?,?,?,?,?)', [
        $chatId, 'reply', bin2hex(random_bytes(16)), 'queued', caller()['user_id'],
        aiJobContext(['folder' => $whole ? null : $folderId, 'project' => $projectId,
                      // Что выделено на холсте (номера) и «смотреть весь проект».
                      'selected' => array_slice(array_map('intval', (array) (input('selected') ?? [])), 0, 50),
                      'whole' => $whole]),
    ]);
    $jobId = dbId();
    aiLaunch($jobId);

    reply(['chat' => $chatId, 'job' => $jobId]);
}

/** POST ai.chat.apply — человек нажал «Применить». */
function aiChatApply(): void
{
    $project = requireProject(true);
    $job = dbRow('SELECT j.*, c.project_id FROM ai_jobs j JOIN ai_chats c ON c.id = j.chat_id WHERE j.id = ?', [inputInt('job')]);
    if (!$job || (int) $job['project_id'] !== (int) $project['id']) throw new ApiError(t('agents.ai.no_job'), 'not_found');
    if ($job['state'] !== 'proposal') throw new ApiError(t('agents.ai.job_done'), 'conflict');

    $ops = json_decode((string) $job['ops'], true) ?: [];
    if (!$ops) throw new ApiError(t('agents.ai.nothing_to_apply'));

    // Замок проекта первым, потом строка задания — тот же порядок, что у завершения задания и пачки.
    $result = engineLocked((int) $project['id'], static function () use ($project, $job, $ops) {
        $locked = dbRow('SELECT state FROM ai_jobs WHERE id = ? FOR UPDATE', [$job['id']]);
        if ($locked['state'] !== 'proposal') throw new ApiError(t('agents.ai.job_done'), 'conflict');
        $context = json_decode((string) $job['context'], true) ?: [];
        $compiled = aiCompile($ops, (int) ($context['folder'] ?? 0));
        if (!$compiled['ops']) throw new ApiError(t('agents.ai.nothing_to_apply'));
        $result = runBatch($project, $compiled['ops'], 'aiApplyOp', false,
            ['via' => 'assistant', 'label' => t('server.ai.journal_job', ['job' => (int) $job['id']])]);
        dbRun("UPDATE ai_jobs SET state = 'applied', error = NULL WHERE id = ?", [$job['id']]);
        dbRun('INSERT INTO ai_messages (chat_id, role, body) VALUES (?,?,?)',
            [$job['chat_id'], 'assistant', ta('agents.ai.applied') . implode(' ', array_column($result['results'], 'message'))]);
        return $result;
    });
    reply($result);
}

function aiChatCancel(): void
{
    $project = requireProject(true);
    $job = dbRow('SELECT j.*, c.project_id FROM ai_jobs j JOIN ai_chats c ON c.id = j.chat_id WHERE j.id = ?', [inputInt('job')]);
    if (!$job || (int) $job['project_id'] !== (int) $project['id']) throw new ApiError(t('agents.ai.no_job'), 'not_found');
    // Отменить можно то, что ещё не случилось: ждёт модель или ждёт «Применить».
    dbRun("UPDATE ai_jobs SET state = 'cancelled' WHERE id = ? AND state IN ('queued', 'running', 'proposal')", [$job['id']]);
    reply(['job' => (int) $job['id'], 'cancelled' => true]);
}

function aiChatDelete(): void
{
    $project = requireProject(true);
    $chat = dbRow('SELECT * FROM ai_chats WHERE id = ? AND project_id = ?', [inputInt('chat'), $project['id']]);
    if (!$chat) throw new ApiError(t('agents.ai.no_chat'), 'not_found');
    dbRun('DELETE FROM ai_chats WHERE id = ?', [$chat['id']]);
    reply(['chat' => (int) $chat['id'], 'deleted' => true]);
}
