<?php
/* Конструктор: опрос карточками и сборка схемы от шаблона каталога.
   Отдаёт: aiBuildGet(), aiBuildStart(), aiBuildAnswer(), aiBuildCreate(), aiBuildRun().
   Не делает: не зовёт модель в запросе человека — это фоновое задание (ai/jobs.php → aiBuildRun).

   Ход (метод для модели — instructions/ru/конструктор.md):
     цель → опрос: модель выбирает основу из каталога и задаёт вопрос карточкой (вопрос, варианты,
     зачем) — по одному, пока не хватит или человек не скажет «Хватит, собирай» →
     сборка: модель отдаёт разницу к основе (или схему с нуля), сервер сливает её в тело черновика
     (templateMerge), пробно разворачивает и сверяет с предпроверкой (templateCheck); помехи — до
     двух починок той же разницей → черновик с превью (catalogPreview) → «Создать» (templateDeploy).
   Черновик — тело заготовки: одна форма для каталога и конструктора. После черновика человек может
   попросить доработку словами — модель правит черновик той же разницей. */

declare(strict_types=1);

/** Вопросов опроса не больше: дальше сервер собирает по сказанному. */
const AI_BUILD_MAX = 7;
/** Починок черновика по предпроверке. */
const AI_BUILD_FIXES = 2;
/** Элементов в черновике не больше: схема крупнее — это несколько папок. */
const AI_BUILD_ELEMENTS = 200;

/* ── Запросы человека ─────────────────────────────────────────── */

/** GET ai.build.get[&session=N] — состояние опроса: история, карточка, черновик. */
function aiBuildGet(): void
{
    $project = requireProject(false);
    $chat = aiBuildChat($project, inputInt('session'));
    if (!$chat) reply(['session' => null]);
    aiSweep((int) $chat['id']);
    reply(aiBuildState(aiBuildChat($project, (int) $chat['id'])));
}

/** POST ai.build.start {goal} — цель; в ответ придёт первая карточка. */
function aiBuildStart(): void
{
    $project = requireProject(true);
    aiLimit((int) $project['id']);
    $goal = trim((string) (input('goal') ?? ''));
    if ($goal === '') throw new ApiError(t('agents.ai.say_what'));

    dbRun("INSERT INTO ai_chats (kind, project_id, project_key, user_id, requester, title, goal, state)
           VALUES ('constructor', ?, ?, ?, ?, ?, ?, 'questionnaire')",
        [$project['id'], $project['url_key'], caller()['user_id'],
         caller()['user_id'] ? 'user:' . caller()['user_id'] : clientIp(), mb_substr($goal, 0, 60), $goal]);
    $chatId = dbId();
    dbRun('INSERT INTO ai_messages (chat_id, role, body) VALUES (?,?,?)', [$chatId, 'user', $goal]);
    reply(['session' => $chatId, 'job' => aiBuildJob($chatId, 'questionnaire', (int) $project['id'])]);
}

/**
 * POST ai.build.answer {session, text?, build?, retry?} — ответ на карточку; build — «Хватит, собирай».
 * После черновика текст — просьба доработать: модель правит черновик. retry — повторить сорванное
 * задание тем же видом, без новой реплики: иначе пары «вопрос — ответ» сдвинулись бы.
 */
function aiBuildAnswer(): void
{
    $project = requireProject(true);
    aiLimit((int) $project['id']);
    $chat = aiBuildChat($project, inputInt('session'));
    if (!$chat) throw new ApiError(t('agents.ai.no_session'), 'not_found');
    if ($chat['state'] === 'done') throw new ApiError(t('agents.ai.already_built'), 'conflict');
    if (dbValue("SELECT 1 FROM ai_jobs WHERE chat_id = ? AND state IN ('queued','running') LIMIT 1", [$chat['id']])) {
        throw new ApiError(t('agents.ai.model_thinking'), 'conflict');
    }

    if (!empty(input('retry'))) {
        $last = dbRow('SELECT kind, state FROM ai_jobs WHERE chat_id = ? ORDER BY id DESC LIMIT 1', [$chat['id']]);
        if (!$last || $last['state'] !== 'failed') throw new ApiError(t('agents.ai.nothing_to_retry'), 'conflict');
        dbRun('UPDATE ai_chats SET state = ? WHERE id = ?', [$last['kind'] === 'diagram' ? 'building' : 'questionnaire', $chat['id']]);
        reply(['session' => (int) $chat['id'], 'job' => aiBuildJob((int) $chat['id'], (string) $last['kind'], (int) $project['id'])]);
    }

    $text = trim((string) (input('text') ?? ''));
    $build = !empty(input('build'));
    if ($text === '' && !$build) throw new ApiError(t('agents.ai.empty_answer'));
    // Черновик уже есть: доработка — только словами.
    if ($chat['state'] === 'ready' && $text === '') throw new ApiError(t('agents.ai.what_to_fix'));

    dbRun('INSERT INTO ai_messages (chat_id, role, body) VALUES (?,?,?)',
        [$chat['id'], 'user', $text !== '' ? $text : ta('agents.ai.enough_questions')]);
    $asked = count(aiBuildCards((int) $chat['id']));
    $kind = ($chat['state'] === 'ready' || $build || $asked >= AI_BUILD_MAX) ? 'diagram' : 'questionnaire';
    dbRun('UPDATE ai_chats SET state = ? WHERE id = ?', [$kind === 'diagram' ? 'building' : 'questionnaire', $chat['id']]);
    reply(['session' => (int) $chat['id'], 'job' => aiBuildJob((int) $chat['id'], $kind, (int) $project['id'])]);
}

/** POST ai.build.create {session, name?} — черновик в новую папку, один раз. */
function aiBuildCreate(): void
{
    // Журнал пишет разворот (запись «конструктор»).
    $project = requireProject(true);
    $chat = aiBuildChat($project, inputInt('session'));
    if (!$chat) throw new ApiError(t('agents.ai.no_draft'), 'conflict');

    /* Отметка done, разворот и папка в черновике — одной транзакцией под замком проекта (он первым,
       как везде: иначе цикл с project.delete через FK чата). Второй «Создать» ждёт замка и находит
       разговор уже done; сорвался разворот — разговор снова ready. */
    $made = engineLocked((int) $project['id'], static function () use ($project, $chat): array {
        if (!dbValue('SELECT id FROM projects WHERE id = ?', [$project['id']])) {
            throw new ApiError(t('server.project.not_found'), 'not_found');   // проект удалили, пока ждали замка
        }
        if (dbRun("UPDATE ai_chats SET state = 'done' WHERE id = ? AND state = 'ready'", [$chat['id']]) !== 1) {
            throw new ApiError(t('agents.ai.no_draft'), 'conflict');
        }
        $job = aiBuildDraftJob((int) $chat['id']);
        $draft = json_decode((string) ($job['preview'] ?? ''), true) ?: [];
        $body = json_decode((string) ($job['ops'] ?? ''), true) ?: [];
        if (!$body || !empty($draft['error'])) throw new ApiError(t('agents.ai.draft_failed', ['why' => $draft['error'] ?? t('agents.checks.empty')]), 'conflict');

        $name = trim((string) (input('name') ?? '')) ?: (string) ($draft['name'] ?? t('server.ai.new_scheme'));
        $made = templateDeploy($project, $body, ['name' => mb_substr($name, 0, 120),
            'label' => t('server.ai.journal_constructor'), 'via' => 'constructor']);
        $draft['folder'] = $made['folder'];
        dbRun('UPDATE ai_jobs SET preview = ? WHERE id = ?', [json_encode($draft, JSON_UNESCAPED_UNICODE), $job['id']]);
        return $made;
    });
    $made['problems'] = array_column(folderPrecheck(folderRow($made['folder'], (int) $project['id']))['problems'], 'say');
    reply($made);
}

/* ── Фоновое задание ──────────────────────────────────────────── */

/**
 * Задание конструктора: опрос — одна карточка; сборка — черновик с проверкой и починкой.
 * Зовёт aiJobRun (ai/jobs.php), когда сессия — конструктор; задание уже помечено running.
 */
function aiBuildRun(array $job, array $project): void
{
    $jobId = (int) $job['id'];
    $chatId = (int) $job['chat_id'];
    try {
        $chat = dbRow('SELECT * FROM ai_chats WHERE id = ?', [$chatId]);
        $cards = aiBuildCards($chatId);
        $lastCard = $cards ? end($cards) : [];
        $draftJob = aiBuildDraftJob($chatId);
        $words = implode("\n", array_column(dbAll("SELECT body FROM ai_messages WHERE chat_id = ? AND role = 'user' ORDER BY id", [$chatId]), 'body'));

        // Что модель знает сверх правил: похожие схемы, выбранная основа, режим и тело, которое правит.
        $picked = catalogPick($words);
        $lines = [ta('agents.ai.similar', ['list' => $picked
            ? implode('; ', array_map(static fn(array $one) => ta('agents.ai.similar_item', ['key' => $one['key'], 'title' => $one['title'], 'score' => $one['score']]), $picked))
            : ta('agents.ai.similar_none')])];
        $base = aiBuildBase($lastCard['base'] ?? null);
        if ($base) $lines[] = ta('agents.ai.picked_base', ['base' => $base]);

        $revise = $job['kind'] === 'diagram' && $draftJob && $chat['state'] === 'building' && aiBuildHasDraft($draftJob);
        if ($job['kind'] === 'questionnaire') {
            $lines[] = ta('agents.ai.mode_survey', ['n' => count($cards), 'max' => AI_BUILD_MAX]);
        } elseif ($revise) {
            $lines[] = ta('agents.ai.mode_revise', ['body' => (string) $draftJob['ops']]);
        } elseif ($base) {
            $lines[] = ta('agents.ai.mode_base', ['base' => $base, 'body' => (string) templateRow($base)['body']]);
        } else {
            $lines[] = ta('agents.ai.mode_scratch');
        }

        $messages = [['role' => 'system', 'content' => aiRules('constructor') . "\n\n" . implode("\n\n", $lines)]];
        foreach (dbAll('SELECT role, body FROM ai_messages WHERE chat_id = ? ORDER BY id', [$chatId]) as $one) {
            $messages[] = ['role' => $one['role'] === 'user' ? 'user' : 'assistant', 'content' => (string) $one['body']];
        }

        $answer = aiAsk($messages);
        $usage = [$answer['model'], $answer['usage']['prompt'], $answer['usage']['completion'],
                  $answer['usage']['total'], $answer['usage']['cached'], $answer['usage']['known'] ? 1 : 0];
        $think = $answer['think'] !== '' ? [$answer['think']] : [];
        if (!is_array($answer['data'])) throw new RuntimeException(t('agents.ai.no_json'));

        if ($job['kind'] === 'questionnaire') {
            $card = aiBuildCard($answer['data'], $base);
            $said = $card['say'] . ($card['question']
                ? "\n\n**" . $card['question']['text'] . '**' . ($card['question']['options'] ? "\n- " . implode("\n- ", $card['question']['options']) : '')
                : ($card['plan'] ? ta('agents.ai.will_build') . implode("\n- ", $card['plan']) : ''));
            $result = ['response' => $card, 'draft' => null, 'body' => null];
        } else {
            $start = $revise ? json_decode((string) $draftJob['ops'], true) : null;
            $result = aiBuildDraft($project, $answer, $messages, $start, $base, $usage, $think);
            $said = $result['draft']['say'];
        }

        /* Итог — одной транзакцией: замок проекта, затем строка задания. Человек успел закрыть
           опрос («Стоп») — только расход; иначе реплика, черновик и состояние разговора — вместе. */
        engineLocked((int) $project['id'], static function () use ($job, $jobId, $chatId, $said, $think, $usage, $result): void {
            if (!aiJobAlive($jobId, $usage, true)) return;
            dbRun('INSERT INTO ai_messages (chat_id, role, body, think) VALUES (?,?,?,?)',
                [$chatId, 'assistant', $said, implode("\n\n", $think) ?: null]);
            dbRun(
                "UPDATE ai_jobs SET state = 'done', response = ?, ops = ?, preview = ?, message_id = ?, model = ?,
                        tokens_prompt = ?, tokens_completion = ?, tokens_total = ?, tokens_cached = ?, usage_known = ?, finished_at = NOW(3)
                  WHERE id = ?",
                [json_encode($result['response'], JSON_UNESCAPED_UNICODE),
                 $result['body'] !== null ? json_encode($result['body'], JSON_UNESCAPED_UNICODE) : null,
                 $result['draft'] !== null ? json_encode($result['draft'], JSON_UNESCAPED_UNICODE) : null,
                 dbId(), ...$usage, $jobId]);
            dbRun('UPDATE ai_chats SET state = ?, updated_at = NOW(3) WHERE id = ?',
                [$job['kind'] === 'questionnaire' ? 'questionnaire' : 'ready', $chatId]);
        });
    } catch (Throwable $error) {
        // Сбой не затирает «Стоп»: отменённое задание остаётся отменённым.
        dbRun("UPDATE ai_jobs SET state = 'failed', error = ?, finished_at = NOW(3) WHERE id = ? AND state = 'running'",
            [mb_substr($error->getMessage(), 0, 500), $jobId]);
        // Сборка сорвалась: черновик уже был — он остаётся (доработку можно повторить),
        // не было — опрос открыт, человек может собрать ещё раз.
        dbRun("UPDATE ai_chats SET state = IF(state = 'building', ?, state) WHERE id = ?",
            [aiBuildDraftJob($chatId) ? 'ready' : 'questionnaire', $chatId]);
    }
}

/**
 * Черновик из ответа сборки: разница поверх основы (черновика, основы каталога или пустого тела),
 * пробный разворот и до AI_BUILD_FIXES починок. Отдаёт ['response', 'draft', 'body'].
 */
function aiBuildDraft(array $project, array $answer, array $messages, ?array $start, ?string $base,
                      array &$usage, array &$think): array
{
    $data = $answer['data'];
    $base = aiBuildBase($data['base'] ?? $base);
    $from = $start ?? ($base ? (json_decode((string) templateRow($base)['body'], true) ?: []) : ['folder' => [], 'elements' => []]);
    $body = templateMerge($from, (array) ($data['elements'] ?? []));
    $check = aiBuildCheck($project, $body);
    $raw = $answer['raw'];

    for ($fix = 0; $fix < AI_BUILD_FIXES && ($check['error'] !== null || $check['problems']); $fix++) {
        $why = $check['error'] !== null ? ta('agents.ai.draft_refused', ['error' => $check['error']])
            : ta('agents.ai.draft_problems', ['list' => implode('; ', $check['problems'])]);
        $think[] = ta('agents.ai.think_server', ['why' => $why]);
        $messages[] = ['role' => 'assistant', 'content' => $raw];
        $messages[] = ['role' => 'user', 'content' => ta('agents.ai.mode_fix', ['why' => $why, 'body' => json_encode($body, JSON_UNESCAPED_UNICODE)])];
        $fixed = aiAsk($messages);
        foreach ([1 => 'prompt', 2 => 'completion', 3 => 'total', 4 => 'cached'] as $at => $name) $usage[$at] += $fixed['usage'][$name];
        if ($fixed['think'] !== '') $think[] = ta('agents.ai.think_fix', ['think' => $fixed['think']]);
        if (!is_array($fixed['data'])) break;
        $raw = $fixed['raw'];
        $body = templateMerge($body, (array) ($fixed['data']['elements'] ?? []));
        $check = aiBuildCheck($project, $body);
    }

    // Шаги — как в карточке каталога: блоки без стартера
    $steps = count(array_filter($body['elements'], static fn(array $e) => ($e['type'] ?? '') === 'block' && !propOn($e['props']['start'] ?? false)));
    $say = trim((string) ($data['say'] ?? ''));
    $say .= ($say !== '' ? "\n\n" : '') . ($check['error'] !== null ? ta('agents.ai.say_failed', ['error' => $check['error']])
        : ($check['problems'] ? ta('agents.ai.say_problems', ['list' => implode("\n- ", $check['problems'])])
            : ta('agents.ai.say_ready', ['n' => $steps])));
    $draft = [
        'name'     => mb_substr(trim((string) ($data['name'] ?? '')) ?: t('server.ai.new_scheme'), 0, 120),
        'base'     => $base,
        'say'      => $say,
        'preview'  => catalogPreview($body),
        'problems' => $check['problems'],
        'error'    => $check['error'],
        'folder'   => null,
    ];
    return ['response' => $data, 'draft' => $draft, 'body' => $body];
}

/** Проверка черновика: предел размера, затем пробный разворот. */
function aiBuildCheck(array $project, array $body): array
{
    if (!$body['elements']) return ['error' => ta('agents.ai.no_elements'), 'problems' => []];
    if (count($body['elements']) > AI_BUILD_ELEMENTS) {
        return ['error' => ta('agents.ai.too_many', ['max' => AI_BUILD_ELEMENTS]), 'problems' => []];
    }
    return templateCheck($project, $body);
}

/* ── Помощники ────────────────────────────────────────────────── */

/** Сессия конструктора проекта: названная или последняя. */
function aiBuildChat(array $project, ?int $id = null): ?array
{
    $id = (int) $id;
    return dbRow("SELECT * FROM ai_chats WHERE project_id = ? AND kind = 'constructor'"
        . ($id ? ' AND id = ?' : ' ORDER BY id DESC LIMIT 1'), $id ? [$project['id'], $id] : [$project['id']]) ?: null;
}

/** Поставить задание в очередь и запустить. Отдаёт id задания. */
function aiBuildJob(int $chatId, string $kind, int $projectId): int
{
    dbRun('INSERT INTO ai_jobs (chat_id, kind, request_id, state, user_id, context) VALUES (?,?,?,?,?,?)',
        [$chatId, $kind, bin2hex(random_bytes(16)), 'queued', caller()['user_id'], aiJobContext(['project' => $projectId])]);
    $jobId = dbId();
    aiLaunch($jobId);
    return $jobId;
}

/** Карточки опроса по порядку — ответы модели в режиме опроса. */
function aiBuildCards(int $chatId): array
{
    return array_map(static fn(array $row) => json_decode((string) $row['response'], true) ?: [],
        dbAll("SELECT response FROM ai_jobs WHERE chat_id = ? AND kind = 'questionnaire' AND state = 'done'
                AND response IS NOT NULL ORDER BY id", [$chatId]));
}

/** Последнее задание сборки с черновиком. */
function aiBuildDraftJob(int $chatId): ?array
{
    return dbRow("SELECT * FROM ai_jobs WHERE chat_id = ? AND kind = 'diagram' AND state = 'done'
                   AND preview IS NOT NULL ORDER BY id DESC LIMIT 1", [$chatId]) ?: null;
}

function aiBuildHasDraft(array $job): bool
{
    return $job['ops'] !== null && $job['ops'] !== '' && $job['ops'] !== 'null';
}

/** Ключ основы, если такая схема есть в каталоге; иначе null. */
function aiBuildBase($key): ?string
{
    $key = is_string($key) ? trim($key, " `") : '';
    if ($key === '') return null;
    return dbValue('SELECT 1 FROM templates WHERE `key` = ? AND in_catalog = 1', [$key]) ? $key : null;
}

/** Карточка опроса из ответа модели: вопрос, 0–5 вариантов, зачем; готов — план сборки. */
function aiBuildCard(array $data, ?string $base): array
{
    $list = static fn($value, int $max) => array_slice(array_values(array_unique(array_filter(array_map(
        static fn($one) => mb_substr(trim((string) $one), 0, 120), is_array($value) ? $value : []), 'strlen'))), 0, $max);
    $q = is_array($data['question'] ?? null) ? $data['question'] : [];
    $text = trim((string) ($q['text'] ?? ''));
    $ready = !empty($data['ready']) || $text === '';
    return [
        'say'      => trim((string) ($data['say'] ?? '')),
        'base'     => aiBuildBase($data['base'] ?? null) ?? $base,
        'question' => $ready ? null : [
            'text'    => mb_substr($text, 0, 300),
            'why'     => mb_substr(trim((string) ($q['why'] ?? '')), 0, 200),
            'options' => $list($q['options'] ?? [], 5),
            'multi'   => !empty($q['multi']),
        ],
        'ready'    => $ready,
        'plan'     => $list($data['plan'] ?? [], 6),
    ];
}

/** Для человека: история вопросов и ответов, текущая карточка, черновик, задание. */
function aiBuildState(array $chat): array
{
    $chatId = (int) $chat['id'];
    $cards = aiBuildCards($chatId);
    $answers = array_slice(array_column(dbAll("SELECT body FROM ai_messages WHERE chat_id = ? AND role = 'user' ORDER BY id", [$chatId]), 'body'), 1);
    // Название основы читает человек — на языке его интерфейса.
    $title = static function (?string $key): ?array {
        if (!$key) return null;
        $row = dbRow('SELECT title, i18n FROM templates WHERE `key` = ?', [$key]);
        return ['key' => $key, 'title' => $row ? (string) catalogLocal($row, lang())['title'] : ''];
    };

    $history = [];
    foreach ($cards as $n => $card) {
        if (!isset($answers[$n])) break;
        $history[] = ['q' => $card['question']['text'] ?? ta('agents.ai.ask_build'), 'a' => $answers[$n]];
    }
    // Карточка открыта, если на неё ещё нет ответа и черновика нет.
    $card = count($answers) < count($cards) ? end($cards) : null;
    if ($card) $card['base'] = $title($card['base'] ?? null);

    $draftJob = aiBuildDraftJob($chatId);
    $draft = $draftJob ? json_decode((string) $draftJob['preview'], true) : null;
    if ($draft) $draft['base'] = $title($draft['base'] ?? null);

    $job = dbRow('SELECT id, kind, state, error FROM ai_jobs WHERE chat_id = ? ORDER BY id DESC LIMIT 1', [$chatId]);
    return [
        'session' => ['id' => $chatId, 'state' => (string) $chat['state'], 'goal' => (string) $chat['goal'],
                      'asked' => count($cards), 'max' => AI_BUILD_MAX],
        'history' => $history,
        'card'    => $card,
        'draft'   => $draft,
        'job'     => $job ? ['id' => (int) $job['id'], 'kind' => $job['kind'], 'state' => $job['state'], 'error' => $job['error']] : null,
    ];
}
