<?php
/* Инструкции прогона: сервер собирает их под настройки папки.
   Отдаёт: instrScenario(), leaderDoc(), workerDoc(), instrPart().
   Не делает: не пишет файлов и не ведёт прогон — текст отдают docs.run,
              docs.get и begin (файл worker — goWorkerFile в simple.php).

   Зачем собирать, а не держать один MD: агент получает только свой сценарий —
   состав команды (solo или команда) и среду (субагенты, Орка, SendMessage).
   Лишнее в инструкции агент пытается исполнить. Тексты — частями в instructions/<язык>/прогон.md
   (язык проекта, langAgents); здесь только выбор частей. Прежние MD — instructions/old/simple/. */

declare(strict_types=1);

/**
 * Сценарий папки: состав команды, действующая среда, действуют ли карточки.
 * solo — ведущий один, среды нет. Команда без выбранной среды — субагенты.
 */
function instrScenario(array $folder): array
{
    $roles = (string) ($folder['role_scheme'] ?? 'solo') ?: 'solo';
    $env = folderActiveRunEnv($folder);
    return [
        'roles' => $roles,
        'solo'  => $roles === 'solo',
        'env'   => $env,
        'cards' => in_array($env, ['orca', 'sendmessage'], true),
        'soon'  => !empty(ROLE_SCHEMES[$roles]['soon']),
        'title' => isset(ROLE_SCHEMES[$roles]) ? ta('agents.role.' . $roles) : $roles,
        'envTitle' => $env !== '' ? ta('agents.env.' . $env) : '',
    ];
}

/** Инструкция leader для папки — одним Markdown-текстом. */
function leaderDoc(array $project, array $folder, string $api): string
{
    $s = instrScenario($folder);
    $parts = [
        instrLeadHead($s),
        instrLeadBefore($s),
        $s['solo'] ? instrLeadRulesSolo() : instrLeadRulesTeam($s),
        instrLeadOrder($s),
        instrLeadBegin($s),
        $s['solo'] ? instrLeadLoopSolo() : instrLeadLoopTeam($s),
        instrSelfWork($s),
        instrLeadDiamond(),
        instrLeadWatch($s),
        $s['solo'] ? '' : instrLeadEnv($s['env']),
        instrLeadOther($s),
        instrLeadReplies($s),
    ];
    $remote = agentsRemote();
    return strtr(implode("\n", array_filter($parts)), [
        '{G}'      => $api . '?project=' . $project['url_key'],
        '{KEY}'    => (string) $project['url_key'],
        '{FOLDER}' => (string) (int) $folder['id'],
        // Сторож ходит по тому же адресу, что и ведущий: не только localhost. Агент в вебе
        // скрипта на диске сервера не видит — берёт его по ссылке (docs.get&file=lead-watch).
        '{WATCH}'  => 'GOBLIN_URL="' . $api . '" ' . ($remote
            ? 'bash <(curl -s "' . $api . '?op=docs.get&file=lead-watch&project=' . $project['url_key'] . '")'
            : dirname(__DIR__, 2) . '/bin/lead-watch.sh'),
        // Агент в вебе называет при begin свою текущую папку: в ней сервер разложит папку схемы.
        '{HERE}'   => $remote ? ' -G --data-urlencode "here=$PWD"' : '',
    ]);
}

/** Как сдавать ответ; агенту в вебе — ещё и файлы строками files (сервер их не видит). */
function instrLeadPost(): string
{
    return instrPart('lead_post') . (agentsRemote() ? "\n" . instrPart('lead_post_web') : '');
}

/** Инструкция worker прогона. $run — null, пока прогона нет (номер — rN). */
function workerDoc(array $folder, ?array $run): string
{
    $s = instrScenario($folder);
    $no = $run ? 'r' . (int) $run['no'] : 'rN';
    $parts = [
        instrWorkHead($s, $folder, $no, $run),
        instrWorkBody(),
        instrWorkEnv($s['env']),
    ];
    return strtr(implode("\n", array_filter($parts)), ['{RUN}' => $no]);
}

/** Часть инструкции: от метки <!-- part:имя --> до следующей; {{имя}} — подстановки. Нет в переводе — русская. */
function instrPart(string $name, array $vars = []): string
{
    static $files = [];
    $dir = config()['instructions_dir'];
    $mark = '<!-- part:' . $name . " -->\n";
    foreach (array_unique([langFile($dir, 'прогон.md', langAgents()), $dir . '/ru/прогон.md']) as $file) {
        $text = $files[$file] ??= (string) @file_get_contents($file);
        $start = strpos($text, $mark);
        if ($start === false) continue;
        $start += strlen($mark);
        $end = strpos($text, "\n<!-- part:", $start);
        $body = $end === false ? substr($text, $start) : substr($text, $start, $end - $start);
        foreach ($vars as $key => $value) $body = str_replace('{{' . $key . '}}', (string) $value, $body);
        return $body;
    }
    return '';
}

// ---------------------------------------------------------------- leader

function instrLeadHead(array $s): string
{
    if ($s['solo']) return instrPart('lead_head_solo');
    return instrPart('lead_head_team', [
        'title' => $s['title'],
        'env'   => $s['envTitle'],
        'who'   => instrPart($s['env'] === 'subagents' ? 'lead_who_subagents' : 'lead_who_cards'),
        'soon'  => $s['soon'] ? instrPart('lead_soon', ['title' => $s['title']]) : '',
    ]);
}

function instrLeadBefore(array $s): string
{
    return instrPart('lead_before', ['model' => $s['solo'] ? '' : instrPart('lead_model')]);
}

function instrLeadRulesSolo(): string
{
    return instrPart('lead_rules_solo');
}

function instrLeadRulesTeam(array $s): string
{
    return instrPart('lead_rules_team', ['self' => instrPart($s['env'] === 'subagents' ? 'lead_self_subagents' : 'lead_self_cards')]);
}

function instrLeadOrder(array $s): string
{
    return instrPart('lead_order', ['mid' => instrPart($s['solo'] ? 'lead_order_solo' : 'lead_order_team')]);
}

function instrLeadBegin(array $s): string
{
    return instrPart('lead_begin', ['self' => $s['solo'] ? instrPart('lead_begin_self') : '']);
}

function instrLeadLoopSolo(): string
{
    return instrPart('lead_loop_solo', ['post' => instrLeadPost()]);
}

function instrLeadLoopTeam(array $s): string
{
    return instrPart('lead_loop_team', [
        'or'   => instrPart($s['env'] === 'subagents' ? 'lead_loop_or_subagents' : 'lead_loop_or_cards'),
        'post' => instrLeadPost(),
    ]);
}

/** Как делать блок самому: в solo — всегда, в команде — для своих блоков. */
function instrSelfWork(array $s): string
{
    return instrPart('self_work', ['intro' => $s['solo'] ? '' : instrPart('self_work_intro')]);
}

function instrLeadDiamond(): string
{
    return instrPart('lead_diamond');
}

function instrLeadWatch(array $s): string
{
    return instrPart('lead_watch', [
        'why'  => instrPart($s['solo'] ? 'lead_watch_why_solo' : 'lead_watch_why_team'),
        'rows' => $s['solo'] ? '' : instrPart('lead_watch_rows_team'),
    ]);
}

/** Связь с worker — только выбранная среда. */
function instrLeadEnv(string $env): string
{
    return match ($env) {
        'orca'        => instrOrcaCommon() . "\n" . instrOrcaLead(),
        'sendmessage' => instrSendLead(),
        default       => instrSubLead(),
    };
}

function instrSubLead(): string
{
    return instrPart('lead_sub');
}

function instrSendLead(): string
{
    return instrPart('lead_send');
}

/** Общее для обеих ролей в Орке: одна строка, конец хода, квитанция. */
function instrOrcaCommon(): string
{
    return instrPart('orca_common');
}

function instrOrcaLead(): string
{
    return instrPart('lead_orca');
}

function instrLeadOther(array $s): string
{
    return instrPart('lead_other', ['wait' => $s['solo'] ? '' : instrPart('lead_other_wait')]);
}

/**
 * Ответы сервера: строки, которые бывают только с worker (замечание, ожидание ответа), — только команде.
 * В solo нет и op=wait (его нет в «Прочих командах»): строку «пока нечего делать → op=wait» убираем
 * здесь — одна строка таблицы с действием `op=wait`, на любом языке md.
 */
function instrLeadReplies(array $s): string
{
    $text = instrPart('lead_replies', [
        'team'  => $s['solo'] ? '' : instrPart('lead_replies_team'),
        'note'  => $s['solo'] ? '' : instrPart('lead_replies_note'),
        'wait'  => $s['solo'] ? '' : instrPart('lead_replies_wait'),
        'stuck' => instrPart($s['solo'] ? 'lead_replies_stuck_solo' : 'lead_replies_stuck_team'),
        'self'  => $s['env'] === 'subagents' ? '' : instrPart('lead_replies_self'),
    ]);
    return $s['solo'] ? (string) preg_replace('/^\|[^\n]*\| `op=wait` \|\n/mu', '', $text) : $text;
}

// ---------------------------------------------------------------- worker

function instrWorkHead(array $s, array $folder, string $no, ?array $run = null): string
{
    return instrPart('work_head', [
        'no'   => $no,
        'name' => $folder['name'],
        'env'  => $s['envTitle'],
        // Агент в вебе — папка у него (agent_dir прогона), иначе — на диске сервера.
        'dir'  => ($run ? runWorkShown($run) : (string) $folder['work_dir']) ?: instrPart('work_dir_default'),
    ]);
}

function instrWorkBody(): string
{
    return instrPart('work_body');
}

/** Как доставить ответ — только своя среда. */
function instrWorkEnv(string $env): string
{
    return match ($env) {
        'orca'        => instrOrcaCommon() . instrPart('work_env_orca'),
        'sendmessage' => instrPart('work_env_send'),
        default       => instrPart('work_env_sub'),
    };
}
