<?php
/* Шаги прогона. leader открывает, принимает и возвращает; worker только выполняет.
   Отдаёт: stepShape(), stepRow(), stepOfAgent(), stepMine(), stepOpen(), stepAccept(),
           stepReturn(), stepDecide(), stepPass(), stepCancel(), stepReissue(), stepGet(),
           stepNote(), stepSubmit(), stepFail(), stepOwn(), stepClose(), stepsCancelAll(),
           stepState(), stepReset(), runReset(), stepCoverToElement(), stepInputRows(), runElement(),
           stepInsertAccepted(), stepCancelDo(), stepNoAsked(), stepRanOn(), stepWhoSave(), stepLeadSelf(),
           stepExecutor(), stepLeadNow(), stepAgentNames(), runResetDo().
   Не делает: не считает жетоны (engine/marks.php), не проверяет файлы (engine/checks/)
              и не собирает пакет задания (engine/package.php).

   Разделение ролей обеспечивает сервер: пропуск шага открывает ровно четыре команды. */

declare(strict_types=1);

/** run_steps.result — TEXT: ответ шага больше 65535 байт не ложится. Одна граница на все входы (и простой путь). */
const STEP_RESULT_BYTES = 65535;

/** Имя исполнителя шага от ведущего (op=task&who=…): 1–60 знаков — буквы, цифры, пробел, _ . - */
const STEP_WHO_RX = '/^[\p{L}\p{N} _.\-]{1,60}$/u';

/** Картинка среди файлов-результатов (a — assets): по виду ассета или по расширению. */
const STEP_PICTURE_SQL = "(a.kind = 'image' OR a.uri REGEXP '\\.(png|jpe?g|gif|webp|avif)$')";

function stepShape(array $row): array
{
    $out = [
        'id'      => (int) $row['id'],
        'run'     => (int) $row['run_id'],
        'element' => $row['element_id'] ? (int) $row['element_id'] : null,
        'no'      => (int) $row['element_no'],
        'title'   => (string) $row['element_title'],
        'attempt' => (int) $row['attempt'],
        'state'   => (string) $row['state'],
        'agent'   => $row['agent_id'] ? (int) $row['agent_id'] : null,
        'via'     => $row['via_edge_id'] ? (int) $row['via_edge_id'] : null,
        'chosen'  => $row['chosen_edge_id'] ? (int) $row['chosen_edge_id'] : null,
        'opened'  => $row['opened_at'],
        'started' => $row['started_at'],
        'sent'    => $row['submitted_at'],
        'closed'  => $row['finished_at'],
        'rev'     => (int) $row['rev'],
    ];
    if ($row['result'] !== null && $row['result'] !== '') $out['result'] = (string) $row['result'];
    if ($row['error'] !== null && $row['error'] !== '')   $out['error'] = (string) $row['error'];
    if ($row['note'] !== '')    $out['note'] = (string) $row['note'];
    if ($row['ran_on'] !== '')  $out['ranOn'] = (string) $row['ran_on'];
    if (($row['who'] ?? '') !== '') $out['who'] = (string) $row['who'];
    if ($row['notes'])          $out['marks'] = json_decode((string) $row['notes'], true);
    return $out;
}

/** Номер элемента из запроса: не назван — внятный отказ 422, а не TypeError в runElement(). */
function stepNoAsked(): int
{
    $no = inputInt('no');
    if (!$no) throw new ApiError(ta('agents.steps.no_number'));
    return $no;
}

/**
 * Кто выполнял шаг — по слову ведущего (run_steps.who): лента времени кладёт шаг в строку
 * этого исполнителя. Пусто — ничего не делаем; не по правилу STEP_WHO_RX — отказ 422.
 * Отдаёт, записано ли новое имя. Версию не двигает — это дело вызывающего. Только под замком.
 */
function stepWhoSave(int $stepId, string $who): bool
{
    $who = trim($who);
    if ($who === '') return false;
    if (!preg_match(STEP_WHO_RX, $who)) throw new ApiError(ta('agents.steps.who_bad', ['max' => 60]));
    $step = dbRow('SELECT * FROM run_steps WHERE id = ?', [$stepId]);
    if (!$step || (string) $step['who'] === $who) return false;
    dbRun('UPDATE run_steps SET who = ? WHERE id = ?', [$who, $stepId]);
    // Имя поправили у уже названного шага — это правка, а не передача работы: след в ленте.
    if ((string) $step['who'] !== '') {
        eventStep($step, 'who', ta('agents.steps.ev_who_changed', ['task' => "{$step['element_no']}.{$step['attempt']}",
            'was' => $step['who'], 'now' => $who]), null, ['actor' => eventActor(caller()),
            'meta' => ['was' => (string) $step['who'], 'now' => $who]]);
    }
    return true;
}

/**
 * Делает ли попытку ведущий сам — факт в миг выдачи (run_steps.lead_self): solo,
 * или среда с карточками (Орка, SendMessage), а worker на блоке нет. Иначе работа у worker.
 */
function stepLeadSelf(int $folderId, ?int $agentId): int
{
    $folder = dbRow('SELECT role_scheme, run_env FROM folders WHERE id = ?', [$folderId]);
    if (!$folder) return 0;
    $solo = ((string) $folder['role_scheme'] ?: 'solo') === 'solo';
    $cards = in_array(folderActiveRunEnv($folder), ['orca', 'sendmessage'], true);
    return $solo || ($cards && !$agentId) ? 1 : 0;
}

/**
 * Кто выполнял попытку — один ответ для ленты времени и панели шага (run.state):
 * карточка worker → имя от ведущего (who) → факт выдачи (lead_self) → запасное правило.
 * Запасное правило — у старых попыток без факта: нынешняя папка ($leadNow), `guess: true`.
 * $names — id агента → имя. Отдаёт ['kind' => agent|who|lead|worker, 'title', 'agentId', 'guess'].
 */
function stepExecutor(array $step, array $names, bool $leadNow): array
{
    $agentId = $step['agent_id'] ? (int) $step['agent_id'] : null;
    if ($agentId) return ['kind' => 'agent', 'title' => (string) ($names[$agentId] ?? t('server.timeline.worker')),
                          'agentId' => $agentId, 'guess' => false];
    if ((string) ($step['who'] ?? '') !== '') {
        return ['kind' => 'who', 'title' => (string) $step['who'], 'agentId' => null, 'guess' => false];
    }
    $known = ($step['lead_self'] ?? null) !== null;
    $lead = $known ? (int) $step['lead_self'] === 1 : $leadNow;
    return ['kind' => $lead ? 'lead' : 'worker', 'title' => t($lead ? 'server.timeline.lead' : 'server.timeline.worker'),
            'agentId' => null, 'guess' => !$known];
}

/** Запасное правило для попыток без факта: делал бы ведущий сам при нынешних составе и среде папки. */
function stepLeadNow(array $folder): bool
{
    return ((string) ($folder['role_scheme'] ?? '') ?: 'solo') === 'solo'
        || in_array(folderActiveRunEnv($folder), ['orca', 'sendmessage'], true);
}

/** Имена карточек агентов у шагов: id → имя, одним запросом. */
function stepAgentNames(array $steps, array $more = []): array
{
    $ids = array_values(array_unique(array_filter(array_merge(
        array_map(static fn(array $s): int => (int) ($s['agent_id'] ?? 0), $steps), $more))));
    if (!$ids) return [];
    return array_column(dbAll('SELECT id, name FROM agents WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')',
        $ids), 'name', 'id');
}

/** Где работал worker (ranOn) — по длине колонки ran_on, varchar(120). */
function stepRanOn(): string
{
    return mb_substr((string) (input('ranOn') ?? ''), 0, 120);
}

/** Элемент по номеру — только из папки прогона: чужая папка шаг не откроет. */
function runElement(array $run, int $no): ?array
{
    return dbRow('SELECT * FROM elements WHERE folder_id = ? AND `no` = ?', [(int) $run['folder_id'], $no]);
}

function stepRow(int $id, int $projectId): array
{
    $row = dbRow('SELECT * FROM run_steps WHERE id = ?', [$id]);
    return sameProject($row, $projectId, 'step');
}

/**
 * Открытый шаг этого агента — по нему работает пропуск агента.
 *
 * Берём самый ранний невзятый: пока прогон идёт, у worker почти всегда
 * открыт ровно один шаг, а если leader открыл два, он делает их по очереди.
 */
function stepOfAgent(int $projectId, int $agentId, ?int $runId = null): int
{
    if (!$agentId) return 0;
    $runWhere = $runId ? ' AND s.run_id = ?' : '';
    $args = [$projectId, $agentId];
    if ($runId) $args[] = $runId;
    return (int) dbValue(
        "SELECT s.id FROM run_steps s JOIN runs r ON r.id = s.run_id
          WHERE s.project_id = ? AND s.agent_id = ? AND s.state IN ('issued','running')
            $runWhere
            AND r.state IN ('running','paused')
          ORDER BY (s.state = 'running') DESC, s.id LIMIT 1",
        $args
    );
}

/**
 * GET step.mine — worker спрашивает: есть ли для меня работа?
 *
 * Этим кончается переписка «leader прислал пропуск»: шаг открывает leader,
 * а worker сам его забирает. С параметром `wait` команда не опрашивает сервер
 * вхолостую, а ждёт до появления шага или до конца ожидания.
 */
function stepMine(): void
{
    $who = caller();
    if ($who['role'] !== 'worker') throw new ApiError(ta('agents.steps.worker_only'), 'scope');
    $project = requireProject(false);
    $projectId = (int) $project['id'];
    $agentId = (int) $who['agent_id'];
    if (!$agentId) throw new ApiError(ta('agents.steps.token_no_agent'), 'scope');
    $askedRun = input('run') !== null ? runAsked($projectId) : 0;
    if ($askedRun) runRow($askedRun, $projectId);

    $wait = min(120, max(0, inputInt('wait') ?: 0));
    $until = microtime(true) + $wait;
    do {
        $stepId = stepOfAgent($projectId, $agentId, $askedRun ?: null);
        if ($stepId) {
            $step = stepRow($stepId, $projectId);
            reply([
                'step'    => stepShape($step),
                'address' => $step['element_no'] . '.' . $step['attempt'],
                'ready'   => true,
            ]);
        }
        if ($wait) usleep(1500000);
    } while (microtime(true) < $until);

    // Работы нет: скажем, идёт ли ещё прогон — worker пора расходиться или ждать.
    $live = $askedRun
        ? (int) dbValue("SELECT COUNT(*) FROM runs r WHERE r.project_id = ? AND r.id = ? AND r.state IN ('running','paused')",
            [$projectId, $askedRun])
        : (int) dbValue("SELECT COUNT(*) FROM runs r WHERE r.project_id = ? AND r.state IN ('running','paused')",
            [$projectId]);
    reply(['ready' => false, 'running' => $live > 0]);
}

/** Единственный открытый шаг прогона: чтобы leader не называл номер, когда он один. */
function stepTheOnlyOpen(int $runId): array
{
    $rows = dbAll("SELECT * FROM run_steps WHERE run_id = ? AND state IN ('issued','running','submitted') ORDER BY (state = 'submitted') DESC, id", [$runId]);
    if (!$rows) throw new ApiError(ta('agents.steps.no_open'), 'not_found');
    if (count($rows) > 1) {
        throw new ApiError(ta('agents.steps.several_open'), 'conflict',
            ['steps' => array_map(static fn(array $r) => (int) $r['element_no'], $rows)]);
    }
    return $rows[0];
}

/** Шаг, названный номером элемента: «14» — последняя попытка элемента 14. */
function stepByNo(int $runId, int $no): array
{
    $row = dbRow('SELECT * FROM run_steps WHERE run_id = ? AND element_no = ? ORDER BY attempt DESC LIMIT 1', [$runId, $no]);
    if (!$row) throw new ApiError(ta('agents.steps.no_such_step'), 'not_found');
    return $row;
}

/* ── Команды leader ─────────────────────────────────────────── */

/** POST step.open — выдать шаг worker. Возвращает пропуск шага. */
function stepOpen(): void
{
    $runId = 0;
    $repeated = false;
    $out = engineCommand(static function () use (&$runId): array {
        [$project, $run] = runOwn(true);
        $runId = (int) $run['id'];
        $projectId = (int) $project['id'];
        runMustBeLive($run, true);

        $no = stepNoAsked();
        $element = runElement($run, $no);
        if (!$element) throw new ApiError(ta('agents.steps.no_such_element'), 'not_found');
        if (!kindRule((string) $element['type'])['step']) {
            throw new ApiError(ta('agents.steps.step_kinds'));
        }
        // Ромб и шлюз забирают жетон своей командой. Если открыть их через `open`,
        // жетон уйдёт в пустой шаг, и решение станет невозможным — это грабли,
        // на которые наступали и человек, и leader.
        if ($element['type'] === 'decision') {
            throw new ApiError(ta('agents.steps.use_decide'), 'conflict', ['code2' => 'use_decide']);
        }
        if ($element['type'] === 'gateway') {
            throw new ApiError(ta('agents.steps.use_pass'), 'conflict', ['code2' => 'use_pass']);
        }
        // Под замком: из двух одновременных выдач вторая увидит первую.
        if (stepsOpenCount((int) $run['id'], (int) $element['id']) > 0) {
            throw new ApiError(ta('agents.steps.already_open'), 'conflict', ['code2' => 'already_open']);
        }

        $via = inputInt('via');
        if ($via) {
            $arrow = dbRow('SELECT id FROM elements WHERE id = ? AND project_id = ?', [$via, $projectId]);
            if (!$arrow) throw new ApiError(ta('agents.steps.arrow_not_found'), 'not_found');
        }
        // Жетоны входа: выбрать сейчас, забрать после записи шага.
        $enter = marksEnter($run, $element, $via ?: null);
        $via = $enter['via'];

        /* Агент-worker: из команды или — где карточки действуют (Орка, SendMessage) —
           из блока. Нет его или не worker (например ведущий) — шаг без агента: его
           делает ведущий сам. В solo worker нет вовсе, как и в advanceIssue. */
        $agentId = null;
        $folder = dbRow('SELECT role_scheme, run_env FROM folders WHERE id = ?', [(int) $run['folder_id']]);
        $solo = !$folder || (string) $folder['role_scheme'] === 'solo';
        if ($element['type'] === 'block' && !$solo) {
            $card = folderUsesAgentCards((int) $run['folder_id']) && $element['agent_id'] ? (int) $element['agent_id'] : null;
            $agentId = inputInt('agent') ?: $card;
            if ($agentId && agentRow($agentId, $projectId)['role'] !== 'worker') $agentId = null;
        }

        // Задание обязательно — ТЗ или описание: работать по одному названию блока — это как раз то, что ломало прогоны.
        $spec = specText('element_id', (int) $element['id']);
        if (kindRule((string) $element['type'])['spec'] && $element['type'] !== 'decision' && taskText((int) $element['id']) === '') {
            throw new ApiError(ta('agents.steps.no_spec'), 'conflict', ['code2' => 'no_spec']);
        }

        $attempt = stepNextAttempt((int) $run['id'], $no);
        $max = graphMaxAttempts(engineGraph((int) $element['folder_id']), (int) $element['id']);
        if (stepOverLimit((int) $run['id'], $no, $max)) throw new ApiError(ta('agents.steps.over_limit', ['max' => $max]), 'conflict');

        $fresh = stepIssue($run, $element, $enter, $agentId, $spec, $attempt,
            (string) (input('note') ?? ''), leadActor());

        $token = tokenIssue('step', [
            'project_id' => $projectId, 'run_id' => (int) $run['id'],
            'step_id' => (int) $fresh['id'], 'agent_id' => $agentId,
        ], 'шаг ' . $no . '.' . $attempt);

        return [
            'step'  => stepShape($fresh),
            'token' => $token['secret'],
            'howto' => sprintf('GOBLIN_URL=%s GOBLIN_PROJECT=%s GOBLIN_STEP=%s goblin task',
                runBaseUrl(), $project['url_key'], $token['secret']),
        ];
    }, $repeated);

    if (!$repeated && ($move = advanceAfter($runId))) $out['advance'] = $move;
    reply($out);
}

/** POST step.accept — принять работу. Без результата принять нельзя. */
function stepAccept(): void
{
    $runId = 0;
    $repeated = false;
    [$body, $warnings] = engineCommand(static function () use (&$runId): array {
        [$project, $run, $step] = stepOwn(true);
        $runId = (int) $run['id'];
        if ($step['state'] !== 'submitted') {
            throw new ApiError(ta('agents.steps.only_submitted'), 'conflict', ['code2' => 'not_submitted']);
        }
        $projectId = (int) $project['id'];
        stepAcceptDo($run, $step, (string) (input('why') ?? ''), leadActor(),
            ['in' => inputInt('tokensIn'), 'out' => inputInt('tokensOut')]);

        $warnings = [];
        if (specChanged($step)) $warnings[] = ta('agents.steps.warn_spec_changed');
        if (stepTooFast($step)) $warnings[] = ta('agents.steps.warn_too_fast');

        return [['step' => stepShape(stepRow((int) $step['id'], $projectId))], $warnings];
    }, $repeated);

    if (!$repeated && ($move = advanceAfter($runId))) $body['advance'] = $move;
    reply($body, $warnings);
}

/** POST step.return — вернуть на доработку. Причина обязательна. */
function stepReturn(): void
{
    $runId = 0;
    $repeated = false;
    $out = engineCommand(static function () use (&$runId): array {
        [$project, $run, $step] = stepOwn(true);
        $runId = (int) $run['id'];
        $why = trim((string) (input('why') ?? ''));
        if ($why === '') throw new ApiError(ta('agents.steps.need_why'));
        if (!in_array($step['state'], ['submitted', 'running', 'issued'], true)) {
            throw new ApiError(ta('agents.steps.step_closed_already'), 'conflict', ['code2' => 'step_closed']);
        }
        stepReturnDo($run, $step, $why, leadActor());

        return ['step' => stepShape(stepRow((int) $step['id'], (int) $project['id']))];
    }, $repeated);

    if (!$repeated && ($move = advanceAfter($runId))) $out['advance'] = $move;
    reply($out);
}

/** POST step.decide — ромб: leader выбирает ветку. Шага у worker тут нет. */
function stepDecide(): void
{
    $runId = 0;
    $repeated = false;
    $out = engineCommand(static function () use (&$runId): array {
        [$project, $run] = runOwn(true);
        $projectId = (int) $project['id'];
        $runId = (int) $run['id'];
        runMustBeLive($run, true);

        $no = stepNoAsked();
        $element = runElement($run, $no);
        if (!$element || $element['type'] !== 'decision') throw new ApiError(ta('agents.steps.not_decision'), 'not_found');

        $branch = (string) (input('branch') ?? '');
        if (!in_array($branch, ['yes', 'no'], true)) throw new ApiError(ta('agents.steps.branch_values'));
        $arrow = dbRow("SELECT * FROM elements WHERE type = 'arrow' AND from_id = ? AND branch = ?", [$element['id'], $branch]);
        if (!$arrow) throw new ApiError(ta('agents.steps.no_exit', ['branch' => $branch]), 'conflict');

        $enter = marksEnter($run, $element, inputInt('via') ?: null);
        $made = stepDecideDo($run, $element, $arrow, $branch, trim((string) (input('why') ?? '')), $enter, leadActor());

        return ['step' => stepShape($made)];
    }, $repeated);

    if (!$repeated && ($move = advanceAfter($runId))) $out['advance'] = $move;
    reply($out);
}

/** POST step.pass — шлюз: прогон переходит в другую папку. */
function stepPass(): void
{
    $runId = 0;
    $repeated = false;
    $out = engineCommand(static function () use (&$runId): array {
        [$project, $run] = runOwn(true);
        $projectId = (int) $project['id'];
        $runId = (int) $run['id'];
        runMustBeLive($run, true);

        $no = stepNoAsked();
        $element = runElement($run, $no);
        if (!$element || $element['type'] !== 'gateway') throw new ApiError(ta('agents.steps.not_gateway'), 'not_found');
        $made = stepPassDo($run, $element, inputInt('via') ?: null);

        return ['step' => stepShape($made), 'folder' => (int) $element['target_folder_id']];
    }, $repeated);

    if (!$repeated && ($move = advanceAfter($runId))) $out['advance'] = $move;
    reply($out);
}

/** Переход шлюза внутри уже открытой транзакции: step.pass и done простого пути. */
function stepPassDo(array $run, array $element, ?int $via = null): array
{
    $target = (int) $element['target_folder_id'];
    if (!$target) throw new ApiError(ta('agents.steps.gateway_no_target'), 'conflict');

    $other = runActive($target);
    if ($other && (int) $other['id'] !== (int) $run['id']) {
        throw new ApiError(ta('agents.steps.target_busy'), 'conflict');
    }

    $enter = marksEnter($run, $element, $via);
    return stepInsertAccepted($run, $element, $enter, ['result' => ta('agents.steps.pass_result', ['target' => $target])],
        ['pass', ta('agents.steps.pass_title', ['no' => $element['no'], 'target' => $target]),
         ['actor' => leadActor(), 'meta' => ['target_folder' => $target]]]);
}

/** POST step.cancel — отменить выданный шаг. */
function stepCancel(): void
{
    reply(engineCommand(static function (): array {
        [$project, $run, $step] = stepOwn(true);
        // Закрытый шаг не отменяют: принятый уже отдал жетоны дальше, и отмена вернула бы
        // на стрелку потраченный жетон. Переиграть принятое — это step.reset.
        if (!in_array((string) $step['state'], ['issued', 'running', 'submitted'], true)) {
            throw new ApiError(ta('agents.steps.cancel_closed'),
                'conflict', ['code2' => 'step_closed']);
        }
        stepCancelDo($step, (int) $project['id'], (string) (input('why') ?? ta('agents.steps.cancelled_by_leader')), eventActor(caller()));
        runTouch((int) $run['id']);
        return ['step' => stepShape(stepRow((int) $step['id'], (int) $project['id']))];
    }));
}

/** POST step.reissue — та же попытка, новый пропуск: worker оборвался. Состояние шага не меняется. */
function stepReissue(): void
{
    reply(engineCommand(static function (): array {
        [$project, $run, $step] = stepOwn(true);
        if (!in_array($step['state'], ['issued', 'running'], true)) {
            throw new ApiError(ta('agents.steps.reissue_open_only'), 'conflict');
        }
        tokenRevokeFor('step_id', (int) $step['id']);
        // Явная перевыдача leader снимает claim прежнего процесса worker.
        dbRun("UPDATE run_steps SET session = '' WHERE id = ?", [(int) $step['id']]);
        $token = tokenIssue('step', [
            'project_id' => (int) $project['id'], 'run_id' => (int) $run['id'],
            'step_id' => (int) $step['id'], 'agent_id' => $step['agent_id'] ? (int) $step['agent_id'] : null,
        ], 'шаг ' . $step['element_no'] . '.' . $step['attempt']);

        return ['step' => stepShape($step), 'token' => $token['secret']];
    }));
}

/* ── Команды worker ──────────────────────────────────────────── */

/** GET step.get — пакет задания. Первый вызов переводит шаг в работу. */
function stepGet(): void
{
    $who = caller();
    if ($who['role'] !== 'worker') throw new ApiError(ta('agents.steps.worker_only'), 'scope');

    $project = requireProject(false);
    $projectId = (int) $project['id'];

    $package = engineLocked($projectId, static function () use ($who, $projectId): array {
        $peek = !empty(input('peek'));
        [$run, $step] = workerStepAuthority($who, $projectId);
        if (!in_array($step['state'], ['issued', 'running'], true)) {
            throw new ApiError(ta('agents.steps.step_closed'), 'conflict', ['code2' => 'step_closed']);
        }

        /* Заглянуть в пакет, не берясь за работу: этим пользуется `goblin launch`,
           когда собирает задание. Иначе шаг вставал «в работе» ещё до того,
           как worker его увидел, и подсветка врала. */
        if ($step['state'] === 'issued' && !$peek
            && (int) ($run['engine'] ?? 1) === 2 && empty($who['step_id'])) {
            throw new ApiError(ta('agents.steps.use_take'), 'conflict', [
                'code2' => 'use_step_take', 'run' => (int) $run['id'], 'step' => (int) $step['id'],
            ]);
        }
        workerSessionAuthority($who, $run, $step, $peek);

        // Взял ли worker работу именно сейчас, или просто перечитывает пакет
        // уже начатого шага: в ленте это разные события.
        $took = $step['state'] === 'issued' && !$peek;
        if ($took) {
            $rev = bumpRev($projectId);
            dbRun("UPDATE run_steps SET state = 'running', started_at = NOW(3), ran_on = ?, rev = ? WHERE id = ?",
                [stepRanOn(), $rev, $step['id']]);
            runTouch((int) $step['run_id']);
            $step = stepRow((int) $step['id'], $projectId);
        }

        /* Повторное чтение пакета писали как новое взятие, и в ленте выходило
           два «worker взял задание» подряд — leader читал это как сбой. */
        if (!$peek) {
            eventStep($step, 'task', $took
                ? ta('agents.steps.ev_task_taken')
                : ta('agents.steps.ev_task_reread'), null, ['actor' => 'worker']);
        }
        return stepPackage($step, $projectId);
    });

    reply($package);
}

/**
 * POST step.take — worker берёт свою работу.
 *
 * Пропуск агента знает только агента, шаг он находит сам. Выданный шаг переводится
 * в работу одним UPDATE: изменилась строка — шаг наш, не изменилась — его уже забрали.
 * Шаг «в работе» значит повтор после обрыва: прежний пропуск гаснет, выдаётся новый,
 * пакет тот же, в ленте — «перевыдан». Работы нет — ждём до `wait` секунд.
 */
function stepTake(): void
{
    $who = caller();
    if ($who['role'] !== 'worker') throw new ApiError(ta('agents.steps.worker_only'), 'scope');

    $project = requireProject(false);
    $projectId = (int) $project['id'];
    $agentId = (int) ($who['agent_id'] ?? 0);
    if (!$agentId && !$who['step_id']) throw new ApiError(ta('agents.steps.token_no_agent'), 'scope');
    $session = trim((string) (input('session') ?? ''));
    if (strlen($session) > 120) throw new ApiError(ta('agents.steps.session_long'));
    $askedRun = input('run') !== null ? runAsked($projectId) : 0;
    if ($askedRun) runRow($askedRun, $projectId);
    $askedStep = inputInt('step') ?: 0;
    if ($who['step_id'] && $askedStep && (int) $who['step_id'] !== $askedStep) {
        throw new ApiError(ta('agents.steps.token_other_step'), 'scope');
    }
    if (!$askedStep && $who['step_id']) $askedStep = (int) $who['step_id'];
    if ($askedStep) {
        $askedRow = stepRow($askedStep, $projectId);
        if ($agentId && (int) ($askedRow['agent_id'] ?? 0) !== $agentId) {
            throw new ApiError(ta('agents.steps.not_your_step'), 'scope');
        }
        if ($askedRun && (int) $askedRow['run_id'] !== $askedRun) {
            throw new ApiError(ta('agents.steps.other_run'), 'scope');
        }
        $askedRun = (int) $askedRow['run_id'];
    } elseif (!$askedRun && $who['run_id']) {
        $askedRun = (int) $who['run_id'];
    }

    $wait = max(0, min(120, (int) (inputInt('wait') ?? 0)));
    $until = microtime(true) + $wait;

    while (true) {
        $out = engineLocked(
            $projectId,
            static fn(): ?array => stepTakeOnce(
                $projectId, $agentId, $who, $session, $askedRun, $askedStep
            )
        );
        if ($out !== null) reply($out);
        if (microtime(true) >= $until) break;
        usleep(500000);
    }

    // Работы нет: скажем, идёт ли ещё прогон — worker пора ждать или расходиться.
    if ($askedRun) {
        $live = (int) dbValue(
            "SELECT COUNT(*) FROM runs WHERE project_id = ? AND id = ? AND state IN ('running','paused')",
            [$projectId, $askedRun]
        );
    } else {
        $live = (int) dbValue(
            "SELECT COUNT(*) FROM runs WHERE project_id = ? AND state IN ('running','paused')", [$projectId]
        );
    }
    reply(['ready' => false, 'running' => $live > 0]);
}

/** Один заход за работой — только под замком. null — работы сейчас нет. */
function stepTakeOnce(
    int $projectId,
    int $agentId,
    array $who,
    string $session,
    int $askedRun = 0,
    int $askedStep = 0
): ?array
{
    $stepId = (int) ($who['step_id'] ?: ($askedStep ?: stepOfAgent(
        $projectId, $agentId, $askedRun ?: null
    )));
    if (!$stepId) return null;

    $step = stepRow($stepId, $projectId);
    if ($askedRun && (int) $step['run_id'] !== $askedRun) {
        throw new ApiError(ta('agents.steps.other_run'), 'scope');
    }
    if ($agentId && (int) ($step['agent_id'] ?? 0) !== $agentId) {
        throw new ApiError(ta('agents.steps.not_your_step'), 'scope');
    }
    workerAuthorityFresh($who, $projectId, $step);
    $run = runRow((int) $step['run_id'], $projectId);
    $sessionRequired = (int) ($run['engine'] ?? 1) === 2 && empty($who['step_id']);
    if ($sessionRequired && $session === '') {
        throw new ApiError(ta('agents.steps.need_session_new'));
    }
    $again = $step['state'] === 'running';
    if (!$again && $step['state'] !== 'issued') return null;

    if (!$again) {
        $rev = bumpRev($projectId);
        $took = dbRun(
            "UPDATE run_steps
                SET state = 'running', started_at = NOW(3), ran_on = ?, session = ?, rev = ?
              WHERE id = ? AND state = 'issued'",
            [stepRanOn(), $session, $rev, $stepId]
        );
        if ($took !== 1) return null;            // успели забрать — поищем в следующий заход
        runTouch((int) $step['run_id']);
        $step = stepRow($stepId, $projectId);
    } else {
        $claimed = (string) ($step['session'] ?? '');
        if ($sessionRequired && $claimed !== '' && !hash_equals($claimed, $session)) {
            throw new ApiError(ta('agents.steps.claimed'), 'conflict', [
                'code2' => 'step_claimed',
                'step' => $stepId,
            ]);
        }
        // Совместимость с шагом, взятым до появления claim: первый повтор закрепляет session.
        if ($claimed === '') dbRun('UPDATE run_steps SET session = ? WHERE id = ?', [$session, $stepId]);
    }

    // Пропуск шага сервер показывает один раз, поэтому потерянный ответ лечится перевыпуском.
    tokenRevokeFor('step_id', $stepId);
    $token = tokenIssue('step', [
        'project_id' => $projectId, 'run_id' => (int) $step['run_id'],
        'step_id' => $stepId, 'agent_id' => $step['agent_id'] ? (int) $step['agent_id'] : null,
    ], 'шаг ' . $step['element_no'] . '.' . $step['attempt']);

    eventStep($step, 'task', $again
        ? ta('agents.steps.ev_reissued', ['task' => "{$step['element_no']}.{$step['attempt']}"])
        : ta('agents.steps.ev_task_taken'), null, ['actor' => 'worker']);

    $shape = stepShape($step);
    $shape['token'] = $token['secret'];
    return ['ready' => true, 'step' => $shape, 'package' => stepPackage($step, $projectId)];
}

/** POST step.note — веха. В ответе может прийти просьба остановиться. */
function stepNote(): void
{
    // Веха не меняет состояния шага, поэтому версия прогона не растёт.
    reply(engineCommand(static function (): array {
        [$project, $run, $step] = stepOwn(false);
        $event = (string) (input('stage') ?? input('event') ?? 'working');
        $text = mb_substr((string) (input('text') ?? ''), 0, 500);

        $notes = $step['notes'] ? json_decode((string) $step['notes'], true) : [];
        $notes[] = ['event' => $event, 'text' => $text, 'at' => dbNow()];
        $rev = bumpRev((int) $project['id']);
        dbRun('UPDATE run_steps SET notes = ?, rev = ? WHERE id = ?', [json_encode($notes, JSON_UNESCAPED_UNICODE), $rev, $step['id']]);

        eventStep($step, 'note', mb_substr("$event: $text", 0, 200), null, ['actor' => 'worker']);
        return ['step' => stepShape(stepRow((int) $step['id'], (int) $project['id'])), 'stop' => $run['stop_at'] !== null];
    }));
}

/** POST step.submit — сдать результат. Без результата сдать нельзя. */
function stepSubmit(): void
{
    $runId = 0;
    $repeated = false;
    [$body, $warnings] = engineCommand(static function () use (&$runId): array {
        [$project, $run, $step] = stepOwn(false);
        $projectId = (int) $project['id'];
        $runId = (int) $run['id'];
        if (!in_array($step['state'], ['issued', 'running'], true)) {
            throw new ApiError(ta('agents.steps.step_closed'), 'conflict', ['code2' => 'step_closed']);
        }

        $result = trim((string) (input('result') ?? ''));
        // Граница колонки — до SQL: длинное не режем молча, а отказываем.
        if (strlen($result) > STEP_RESULT_BYTES) {
            throw new ApiError(ta('agents.steps.result_too_long', ['bytes' => strlen($result), 'max' => STEP_RESULT_BYTES]));
        }
        $files = (array) (input('files') ?? []);
        $warnings = checkSubmit($project, $run, $step, $result, $files);

        $rev = bumpRev($projectId);
        dbRun(
            "UPDATE run_steps SET state = 'submitted', result = ?, submitted_at = NOW(3), ran_on = CASE WHEN ? <> '' THEN ? ELSE ran_on END,
                    tokens_in = COALESCE(?, tokens_in), tokens_out = COALESCE(?, tokens_out), rev = ?
              WHERE id = ?",
            [$result, stepRanOn(), stepRanOn(),
             inputInt('tokensIn'), inputInt('tokensOut'), $rev, $step['id']]
        );
        runTouch((int) $run['id']);

        // Файлы результата становятся ассетами шага.
        $ctx = ['projectId' => $projectId, 'rev' => $rev, 'refs' => [], 'folders' => [], 'warnings' => []];
        $opened = (int) dbValue('SELECT UNIX_TIMESTAMP(opened_at) FROM run_steps WHERE id = ?', [(int) $step['id']]);
        foreach ($files as $file) {
            $path = (string) ($file['path'] ?? $file);
            $assetId = assetFromFile($projectId, $path, $rev);

            /* Сдать можно и то, что лежало в папке до прогона: worker первого шага
               честно сдаёт исходник. Такой файл — вход, а не работа, и стирать его
               при подготовке нельзя. Решаем это здесь, пока правда известна:
               файл моложе выдачи шага — работа шага. */
            $real  = realpath($path);
            $made  = $real && $opened && filemtime($real) >= $opened - 2;

            assetLink([
                'asset' => $assetId, 'step' => (int) $step['id'], 'role' => 'result',
                'output' => (string) ($file['output'] ?? ''),
                'madeByRun' => $made ? (int) $step['run_id'] : null,
            ], $ctx);
        }

        $names = array_values(array_filter(array_map(
            static fn($f) => basename((string) ($f['path'] ?? '')), $files)));
        eventStep($step, 'submit',
            ta('agents.steps.ev_submitted', ['task' => "{$step['element_no']}.{$step['attempt']}"]) . mb_substr($result, 0, 110)
            . ($names ? ta('agents.steps.ev_files', ['n' => count($names)]) : ''),
            ['result' => $result, 'files' => $names, 'warnings' => $warnings],
            ['actor' => 'worker']);
        return [['step' => stepShape(stepRow((int) $step['id'], $projectId))], $warnings];
    }, $repeated);

    if (!$repeated && ($move = advanceAfter($runId))) $body['advance'] = $move;
    reply($body, $warnings);
}

/** POST step.fail — не вышло. Блокер означает, что нужен человек. */
function stepFail(): void
{
    $runId = 0;
    $repeated = false;
    $out = engineCommand(static function () use (&$runId): array {
        [$project, $run, $step] = stepOwn(false);
        $runId = (int) $run['id'];
        $why = trim((string) (input('reason') ?? input('why') ?? ''));
        if ($why === '') throw new ApiError(ta('agents.steps.need_reason'));

        stepClose((int) $step['id'], 'failed', (int) $project['id'], [
            'error' => (!empty(input('blocker')) ? ta('agents.steps.blocker') : '') . $why,
        ]);
        marksRelease((int) $step['id']);
        runTouch((int) $run['id']);
        tokenRevokeFor('step_id', (int) $step['id']);
        eventStep($step, 'fail', ta('agents.steps.ev_failed', ['task' => "{$step['element_no']}.{$step['attempt']}"]) . mb_substr($why, 0, 150),
            $why, ['actor' => 'worker']);

        return ['step' => stepShape(stepRow((int) $step['id'], (int) $project['id']))];
    }, $repeated);

    if (!$repeated && ($move = advanceAfter($runId))) $out['advance'] = $move;
    reply($out);
}

/* ── Общее ────────────────────────────────────────────────────── */

/** Шаг, которым распоряжается вызывающий: leader — по номеру, worker — свой. */
function stepOwn(bool $leadOnly): array
{
    $who = caller();
    $project = requireProject(true);
    $projectId = (int) $project['id'];

    if ($who['role'] === 'worker') {
        if ($leadOnly) throw new ApiError(ta('agents.steps.leader_only'), 'scope');
        [$run, $step] = workerStepAuthority($who, $projectId);
        workerSessionAuthority($who, $run, $step, false);
        return [$project, $run, $step];
    }
    if ($leadOnly && !in_array($who['role'], ['lead', 'human', 'guest', 'admin'], true)) {
        throw new ApiError(ta('agents.steps.leader_only'), 'scope');
    }

    [$project, $run] = runOwn($leadOnly);
    $step = inputInt('step')
        ? stepRow((int) inputInt('step'), $projectId)
        : (inputInt('no') ? stepByNo((int) $run['id'], (int) inputInt('no')) : stepTheOnlyOpen((int) $run['id']));
    if ((int) $step['run_id'] !== (int) $run['id']) throw new ApiError(ta('agents.steps.other_run'), 'scope');
    return [$project, $run, $step];
}

/**
 * Точный шаг worker caller под project lock.
 * Engine2 agent-token обязан назвать run+step: текущую/latest попытку не подставляем.
 * Legacy engine1 сохраняет прежний поиск открытого шага агента.
 */
function workerStepAuthority(array $who, int $projectId): array
{
    $askedRun = input('run') !== null ? runAsked($projectId) : 0;
    if ($askedRun) runRow($askedRun, $projectId);
    $askedStep = inputInt('step') ?: 0;

    if (!empty($who['step_id'])) {
        if ($askedStep && $askedStep !== (int) $who['step_id']) {
            throw new ApiError(ta('agents.steps.token_other_step'), 'scope');
        }
        $step = stepRow((int) $who['step_id'], $projectId);
    } else {
        $agentId = (int) ($who['agent_id'] ?? 0);
        if (!$agentId) throw new ApiError(ta('agents.steps.token_no_agent'), 'scope');

        if ($askedStep) {
            $step = stepRow($askedStep, $projectId);
        } elseif ($askedRun) {
            $run = runRow($askedRun, $projectId);
            if ((int) ($run['engine'] ?? 1) === 2) {
                throw new ApiError(ta('agents.steps.need_exact_step'), 'scope', [
                    'code2' => 'step_scope_required', 'run' => $askedRun,
                ]);
            }
            $stepId = stepOfAgent($projectId, $agentId, $askedRun);
            if (!$stepId) throw new ApiError(ta('agents.steps.no_open_for_you'), 'not_found', ['code2' => 'no_step']);
            $step = stepRow($stepId, $projectId);
        } else {
            $engine2 = (int) dbValue(
                "SELECT COUNT(*) FROM run_steps s JOIN runs r ON r.id = s.run_id
                  WHERE s.project_id = ? AND s.agent_id = ? AND s.state IN ('issued','running')
                    AND r.state IN ('running','paused') AND r.engine = 2",
                [$projectId, $agentId]
            );
            if ($engine2 > 0) {
                throw new ApiError(ta('agents.steps.need_exact_run_step'), 'scope', [
                    'code2' => 'step_scope_required',
                ]);
            }
            $candidate = dbRow(
                "SELECT s.* FROM run_steps s JOIN runs r ON r.id = s.run_id
                  WHERE s.project_id = ? AND s.agent_id = ? AND s.state IN ('issued','running')
                    AND r.state IN ('running','paused') AND r.engine = 1
                  ORDER BY (s.state = 'running') DESC, s.id LIMIT 1",
                [$projectId, $agentId]
            );
            if (!$candidate) throw new ApiError(ta('agents.steps.no_open_for_you'), 'not_found', ['code2' => 'no_step']);
            $step = $candidate;
        }
    }

    if ($askedRun && (int) $step['run_id'] !== $askedRun) {
        throw new ApiError(ta('agents.steps.other_run'), 'scope');
    }
    if (!empty($who['agent_id']) && (int) ($step['agent_id'] ?? 0) !== (int) $who['agent_id']) {
        throw new ApiError(ta('agents.steps.not_your_step'), 'scope');
    }
    if (empty($who['step_id']) && !in_array((string) $step['state'], ['issued', 'running'], true)) {
        throw new ApiError(ta('agents.steps.step_closed'), 'conflict', ['code2' => 'step_closed']);
    }
    $run = runRow((int) $step['run_id'], $projectId);
    if (empty($who['step_id']) && (int) ($run['engine'] ?? 1) === 2
        && (!$askedRun || !$askedStep)) {
        throw new ApiError(ta('agents.steps.need_exact_run_step'), 'scope', [
            'code2' => 'step_scope_required', 'run' => (int) $run['id'], 'step' => (int) $step['id'],
        ]);
    }
    workerAuthorityFresh($who, $projectId, $step);
    return [$run, $step];
}

/** Engine2 agent-token пишет только внутри своего session claim; peek ничего не приобретает. */
function workerSessionAuthority(array $who, array $run, array $step, bool $peek): void
{
    if (!empty($who['step_id']) || (int) ($run['engine'] ?? 1) !== 2 || $peek) return;

    $session = trim((string) (input('session') ?? ''));
    if ($session === '') {
        throw new ApiError(ta('agents.steps.need_session'), 'scope', [
            'code2' => 'session_required', 'run' => (int) $run['id'], 'step' => (int) $step['id'],
        ]);
    }
    if (strlen($session) > 120) throw new ApiError(ta('agents.steps.session_long'));
    $claimed = (string) ($step['session'] ?? '');
    if ($claimed === '' || !hash_equals($claimed, $session)) {
        throw new ApiError(ta('agents.steps.other_session'), 'conflict', [
            'code2' => 'step_claimed', 'run' => (int) $run['id'], 'step' => (int) $step['id'],
        ]);
    }
}

/** Повторная проверка worker token уже после ожидания project lock. */
function workerAuthorityFresh(array $who, int $projectId, array $step): void
{
    if (empty($who['token_id'])) return;
    $token = dbRow(
        'SELECT * FROM tokens
          WHERE id = ? AND revoked_at IS NULL
            AND (expires_at IS NULL OR expires_at > NOW(3))',
        [(int) $who['token_id']]
    );
    if (!$token) throw new ApiError(ta('agents.steps.token_revoked'), 'unauthorized');
    if ((int) ($token['project_id'] ?? 0) !== $projectId) {
        throw new ApiError(ta('agents.steps.token_other_project'), 'scope');
    }
    if ($token['scope'] === 'step' && (int) ($token['step_id'] ?? 0) !== (int) $step['id']) {
        throw new ApiError(ta('agents.steps.token_other_step'), 'scope');
    }
    if ($token['scope'] === 'agent' && (int) ($token['agent_id'] ?? 0) !== (int) ($step['agent_id'] ?? 0)) {
        throw new ApiError(ta('agents.steps.not_your_step'), 'scope');
    }
}

function runMustBeLive(array $run, bool $forOpen): void
{
    if ($run['state'] === 'paused' && $forOpen) throw new ApiError(ta('agents.steps.run_paused'), 'conflict');
    if (!in_array($run['state'], ['running', 'paused'], true)) throw new ApiError(ta('agents.steps.run_closed'), 'conflict');
    if ($run['stop_at'] !== null && $forOpen) throw new ApiError(ta('agents.steps.human_stop'), 'conflict');
}

/**
 * Закрыть шаг состоянием. Причина и заметка ложатся по длине своих колонок: note — varchar(500)
 * знаков, error — TEXT, 65535 байт (строгий SQL иначе отказал бы на 501-м знаке).
 */
function stepClose(int $stepId, string $state, int $projectId, array $extra = []): void
{
    $note = mb_substr((string) ($extra['note'] ?? ''), 0, 500);
    $error = isset($extra['error']) ? mb_strcut((string) $extra['error'], 0, 65535) : null;
    $rev = bumpRev($projectId);
    dbRun(
        "UPDATE run_steps SET state = ?, finished_at = NOW(3), rev = ?,
                error = COALESCE(?, error),
                note = CASE WHEN ? <> '' THEN ? ELSE note END,
                tokens_in = COALESCE(?, tokens_in), tokens_out = COALESCE(?, tokens_out)
          WHERE id = ?",
        [$state, $rev, $error, $note, $note,
         $extra['tokens_in'] ?? null, $extra['tokens_out'] ?? null, $stepId]
    );
}

/* ── Действия: их делают и команды, и ход движка ─────────────── */

/**
 * Пауза показа: следующий ход не раньше, чем через 2 × N секунд.
 * N секунд держится вспышка блока, ещё N — бегущая стрелка. Ноль — полная скорость.
 */
function runPace(array $run): void
{
    $pause = (float) ($run['show_pause'] ?? 0);
    if ($pause <= 0) return;
    dbRun('UPDATE runs SET next_move_at = DATE_ADD(NOW(3), INTERVAL ? MICROSECOND) WHERE id = ?',
        [(int) round($pause * 2 * 1000000), (int) $run['id']]);
}

/**
 * Записать выданный шаг: попытка, жетоны входа, событие и снимок пакета.
 * Проверки (агент, ТЗ, лимит попыток) — у вызывающего. Только под замком.
 */
function stepIssue(array $run, array $element, array $enter, ?int $agentId, ?array $spec,
                   int $attempt, string $note, string $actor): array
{
    $projectId = (int) $run['project_id'];
    $no = (int) $element['no'];

    $rev = bumpRev($projectId);
    dbRun(
        'INSERT INTO run_steps (project_id, run_id, element_id, element_no, element_title, attempt, state, via_edge_id, agent_id,
                                lead_self, spec_hash, note, rev)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)',
        [$projectId, (int) $run['id'], (int) $element['id'], $no, $element['title'], $attempt, 'issued',
         $enter['via'], $agentId, stepLeadSelf((int) $run['folder_id'], $agentId),
         $spec ? hash('sha256', $spec['text']) : null, mb_substr($note, 0, 500), $rev]
    );
    $stepId = dbId();
    marksTake($stepId, $enter['marks']);
    runTouch((int) $run['id']);

    /* В ленту прогона — сам факт выдачи и снимок того, что уйдёт worker.
       Снимок нужен потому, что схему могут поправить: потом будет видно,
       по какому именно заданию человек работал. */
    $fresh = stepRow($stepId, $projectId);
    $agent = $agentId ? (string) dbValue('SELECT name FROM agents WHERE id = ?', [$agentId]) : '';
    eventStep($fresh, 'open', ta('agents.steps.ev_issued', ['task' => "{$no}.{$fresh['attempt']}"]) . ($agent ? ta('agents.steps.ev_agent', ['agent' => $agent]) : ''),
        null, ['actor' => $actor]);

    /* Пакет утилиты (JSON) — только её прогонам. У прогона leader worker
       получает текст задания, и в ленту ложится именно он — это пишет
       lib/api/simple.php (goTaskLog), когда leader берёт задание. */
    if (!advanceLeadRun($run)) {
        $package = stepPackage($fresh, $projectId);
        eventStep($fresh, 'package', ta('agents.steps.ev_package') . kbOf($package), $package, ['actor' => $actor]);
    }
    return $fresh;
}

/**
 * Принять шаг: закрыть, записать переменные, положить жетоны на выходы, снять пропуск.
 * Переменные шага = переменные входа, поверх них — разбор своего результата.
 */
function stepAcceptDo(array $run, array $step, string $why, string $actor, array $tokens = []): void
{
    $projectId = (int) $run['project_id'];
    $stepId = (int) $step['id'];

    stepClose($stepId, 'accepted', $projectId, [
        'note'       => $why,
        'tokens_in'  => $tokens['in'] ?? null,
        'tokens_out' => $tokens['out'] ?? null,
    ]);
    if ((int) $run['engine'] === 2) {
        varsSave($stepId, array_merge(varsIn($stepId)['vars'], varsParse((string) ($step['result'] ?? ''))));
    }
    marksGive($run, $step);
    runTouch((int) $run['id']);
    tokenRevokeFor('step_id', $stepId);

    eventStep($step, 'accept', ta('agents.steps.ev_accepted', ['task' => "{$step['element_no']}.{$step['attempt']}"])
        . ($why !== '' ? ' · ' . mb_substr($why, 0, 120) : ''), $why, ['actor' => $actor]);
    stepCoverToElement($step, $projectId);
    runPace($run);
}

/** Вернуть шаг на доработку: жетоны снова лежат на стрелках, пропуск гаснет. */
function stepReturnDo(array $run, array $step, string $why, string $actor): void
{
    $stepId = (int) $step['id'];
    stepClose($stepId, 'returned', (int) $run['project_id'], ['error' => $why]);
    marksRelease($stepId);
    runTouch((int) $run['id']);
    tokenRevokeFor('step_id', $stepId);

    eventStep($step, 'return', ta('agents.steps.ev_returned', ['task' => "{$step['element_no']}.{$step['attempt']}"]) . mb_substr($why, 0, 150),
        $why, ['actor' => $actor]);
    runPace($run);
}

/**
 * Записать сразу принятый шаг — ромб, шлюз или стартер: worker не нужен.
 * Попытка вставляется принятой, забирает жетоны входа, кладёт жетоны на выходы и пишет событие.
 * $row — поля шага сверх общих (result, chosen_edge_id, note); $event — [вид, заголовок, что ещё
 * для eventStep]; $vars — переменные поверх переменных входа (ромб и шлюз несут вход без изменений).
 * Только под замком.
 */
function stepInsertAccepted(array $run, array $element, array $enter, array $row, array $event,
                            array $vars = []): array
{
    $projectId = (int) $run['project_id'];
    $cols = [
        'project_id' => $projectId, 'run_id' => (int) $run['id'], 'element_id' => (int) $element['id'],
        'element_no' => (int) $element['no'], 'element_title' => $element['title'],
        'attempt'    => stepNextAttempt((int) $run['id'], (int) $element['no']),
        'state'      => 'accepted', 'via_edge_id' => $enter['via'], 'rev' => bumpRev($projectId),
    ] + $row;
    dbRun('INSERT INTO run_steps (' . implode(', ', array_keys($cols)) . ', submitted_at, finished_at)
           VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ',NOW(3),NOW(3))', array_values($cols));
    $made = stepRow(dbId(), $projectId);

    marksTake((int) $made['id'], $enter['marks']);
    if ((int) $run['engine'] === 2) varsSave((int) $made['id'], $vars + varsFromMarks($enter['marks'])['vars']);
    marksGive($run, $made);
    runTouch((int) $run['id']);

    [$kind, $title, $more] = $event;
    eventStep($made, $kind, $title, null, $more);
    runPace($run);
    return $made;
}

/**
 * Решить ромб: сразу принятая попытка, жетон уходит на выбранную ветку.
 * Переменные входа ромб переносит дальше без изменений.
 */
function stepDecideDo(array $run, array $element, array $arrow, string $branch, string $why,
                      array $enter, string $actor): array
{
    $word = $branch === 'yes' ? ta('agents.steps.word_yes') : ta('agents.steps.word_no');
    $made = stepInsertAccepted($run, $element, $enter,
        ['chosen_edge_id' => (int) $arrow['id'], 'result' => ta('agents.steps.branch_result', ['word' => $word]), 'note' => mb_substr($why, 0, 500)],
        ['decide', ta('agents.steps.decide_title', ['no' => $element['no'], 'word' => mb_strtolower($word)]) . ($why !== '' ? " · $why" : ''),
         ['actor' => $actor, 'meta' => ['branch' => $branch]]]);

    // Вопрос и ответ Jev про ромб шли раньше шага: теперь шаг есть — привязываем их к нему.
    dbRun("UPDATE run_events SET step_id = ?, attempt = ?
            WHERE run_id = ? AND element_no = ? AND step_id IS NULL AND kind IN ('jev-ask','jev-say')",
        [(int) $made['id'], (int) $made['attempt'], (int) $run['id'], (int) $element['no']]);
    return $made;
}

/**
 * Стартер пройден: сразу принятая попытка без worker, жетоны — на все его выходы.
 * Как решение ромба, только без ветки: стартер лишь отмечает начало прогона.
 * Пришли по шлюзу — его ответ становится ответом стартера, а переменные шлюза идут дальше.
 */
function stepStarterDo(array $run, array $element, array $enter, string $actor): array
{
    $from = runArrival($run);
    if ($from) runArrivalTake($run, $from);
    return stepInsertAccepted($run, $element, $enter, ['result' => $from['text'] ?? ta('agents.steps.start_result')],
        ['accept', ta('agents.steps.starter_passed', ['no' => $element['no']]), ['actor' => $actor, 'meta' => ['starter' => true]]],
        $from['vars'] ?? []);
}

/**
 * Номер следующей попытки элемента в прогоне. Стёртые номера (again, reset) не
 * переиспользуются: иначе запоздалый ответ «137.3: …» на стёртую попытку был бы
 * принят новой 137.3. Стёртые помнит лента — событие `reset` (stepResetDo).
 */
function stepNextAttempt(int $runId, int $no): int
{
    $live = (int) dbValue('SELECT COALESCE(MAX(attempt), 0) FROM run_steps WHERE run_id = ? AND element_no = ?',
        [$runId, $no]);
    $gone = (int) dbValue("SELECT COALESCE(MAX(attempt), 0) FROM run_events WHERE run_id = ? AND element_no = ? AND kind = 'reset'",
        [$runId, $no]);
    return 1 + max($live, $gone);
}

/**
 * Предел max_attempts исчерпан? Считаются круги, которые были, — живые попытки
 * элемента. Стёртые again — не круги: номер попытки они расходуют, а предел нет.
 */
function stepOverLimit(int $runId, int $no, int $max): bool
{
    return $max > 0
        && 1 + (int) dbValue('SELECT COUNT(*) FROM run_steps WHERE run_id = ? AND element_no = ?', [$runId, $no]) > $max;
}

/** Отменить открытый шаг: закрыть, вернуть жетоны, погасить пропуск, записать в ленту. */
function stepCancelDo(array $step, int $projectId, string $why, string $actor): void
{
    $id = (int) $step['id'];
    stepClose($id, 'cancelled', $projectId, ['error' => $why]);
    marksRelease($id);
    tokenRevokeFor('step_id', $id);
    eventStep($step, 'cancel', ta('agents.steps.ev_cancelled', ['task' => "{$step['element_no']}.{$step['attempt']}"]) . mb_substr($why, 0, 150),
        $why, ['actor' => $actor]);
}

/** Остановка прогона закрывает всё открытое — вечных отрезков в ленте не остаётся. Отдаёт число отменённых. */
function stepsCancelAll(int $runId, int $projectId, string $why, string $actor = 'human'): int
{
    $rows = dbAll("SELECT * FROM run_steps WHERE run_id = ? AND state IN ('issued','running','submitted')", [$runId]);
    foreach ($rows as $row) stepCancelDo($row, $projectId, $why, $actor);
    return count($rows);
}

/**
 * POST step.state — поставить шагу любое состояние руками.
 *
 * Это команда человека: он смотрит на холст и иногда знает больше сервера —
 * работа сделана мимо прогона, шаг завис, ветку выбрали на словах. Пропуска
 * worker здесь нет, поэтому меняется только запись о шаге.
 * Состояние «новый» стирает шаги элемента целиком (см. step.reset).
 */
function stepState(): void
{
    reply(engineCommand(static function (): array {
        [$project, $run] = runOwn(true);
        $projectId = (int) $project['id'];
        $no = inputInt('no');
        $want = (string) (input('state') ?? '');
        if (!$no) throw new ApiError(ta('agents.steps.no_number'), 'not_found');
        if ($want === 'none') return stepResetDo($project, $run);

        $known = ['issued', 'running', 'submitted', 'accepted', 'returned', 'failed', 'cancelled'];
        if (!in_array($want, $known, true)) throw new ApiError(ta('agents.steps.no_such_state', ['state' => $want]));

        $element = runElement($run, $no);
        if (!$element) throw new ApiError(ta('agents.steps.no_such_element'), 'not_found');
        if (!kindRule((string) $element['type'])['step']) {
            throw new ApiError(ta('agents.steps.step_kinds'));
        }

        $rev = bumpRev($projectId);
        $row = dbRow('SELECT * FROM run_steps WHERE run_id = ? AND element_no = ? ORDER BY attempt DESC LIMIT 1',
            [$run['id'], $no]);
        $was = $row ? (string) $row['state'] : 'none';

        // Шага ещё не было — заводим запись, чтобы человеку было что красить.
        if (!$row) {
            $agentId = $element['agent_id'] ? (int) $element['agent_id'] : null;
            dbRun(
                'INSERT INTO run_steps (project_id, run_id, element_id, element_no, element_title, attempt, state, agent_id,
                                        lead_self, note, rev)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?)',
                [$projectId, $run['id'], $element['id'], $no, $element['title'], 1, 'issued', $agentId,
                 stepLeadSelf((int) $run['folder_id'], $agentId), ta('agents.steps.state_set_by_human'), $rev]
            );
            $row = dbRow('SELECT * FROM run_steps WHERE id = ?', [dbId()]);
        }

        // Времена держим в согласии с состоянием: иначе в панели пустые графы.
        $now = dbNow();          // время базы: у PHP бывает свой часовой пояс
        $set = ['state = ?', 'rev = ?'];
        $args = [$want, $rev];
        $closed = in_array($want, ['accepted', 'returned', 'failed', 'cancelled'], true);

        if ($want === 'issued') { $set[] = 'started_at = NULL'; $set[] = 'submitted_at = NULL'; $set[] = 'finished_at = NULL'; }
        if ($want === 'running') { $set[] = 'started_at = COALESCE(started_at, ?)'; $args[] = $now;
                                   $set[] = 'submitted_at = NULL'; $set[] = 'finished_at = NULL'; }
        if ($want === 'submitted') { $set[] = 'started_at = COALESCE(started_at, ?)'; $args[] = $now;
                                     $set[] = 'submitted_at = COALESCE(submitted_at, ?)'; $args[] = $now;
                                     $set[] = 'finished_at = NULL'; }
        if ($closed) { $set[] = 'finished_at = ?'; $args[] = $now; }
        if (in_array($want, ['returned', 'failed', 'cancelled'], true)) {
            $set[] = 'error = ?';
            $args[] = mb_strcut((string) (input('why') ?? ta('agents.steps.state_set_by_human')), 0, 65535);
        }
        if ($want === 'accepted') { $set[] = "error = ''"; }

        $args[] = (int) $row['id'];
        dbRun('UPDATE run_steps SET ' . implode(', ', $set) . ' WHERE id = ?', $args);
        marksReconcile($run, stepRow((int) $row['id'], $projectId), $was, $want);
        runTouch((int) $run['id']);

        // Закрытый шаг worker больше не принадлежит.
        if ($closed) tokenRevokeFor('step_id', (int) $row['id']);

        return ['step' => stepShape(stepRow((int) $row['id'], $projectId))];
    }));
}

/**
 * POST step.reset — стереть попытку шага вместе с результатом.
 *
 * Новые прогоны — по одной попытке и только без потомков (см. stepResetDo);
 * старые — все попытки элемента разом, как раньше.
 */
function stepReset(): void
{
    $runId = 0;
    $repeated = false;
    $out = engineCommand(static function () use (&$runId): array {
        [$project, $run] = runOwn(true);
        $runId = (int) $run['id'];
        return stepResetDo($project, $run);
    }, $repeated);

    if (!$repeated && ($move = advanceAfter($runId))) $out['advance'] = $move;
    reply($out);
}

/**
 * Работа сброса — отдельно: её же зовёт step.state с состоянием «новый». Только под замком.
 *
 * Новые прогоны (engine = 2) сбрасывают одну попытку: названную (`step` — id шага,
 * или `attempt`) либо последнюю попытку элемента. В прогоне ведущего шаги,
 * построенные на ней, но без работы — невзятую выдачу и решение ромба — стирают
 * заодно (stepErase), глубже первыми; новый круг цикла только выдан — переделывается
 * прошлый сданный круг. Дальше блок в работе или принятый — отказ «сначала сбросьте 36.2 — он
 * построен на этом результате» со всем списком таких блоков. Забранные попыткой жетоны возвращаются
 * на стрелки, положенные ею — исчезают вместе с ней.
 *
 * Старые прогоны (engine = 1) стирают все попытки элемента, как раньше.
 */
function stepResetDo(array $project, array $run, ?int $no = null, bool $latest = false, ?string $actor = null): array
{
    $projectId = (int) $project['id'];
    // Номер дают параметром (простой путь зовёт блок «block») или в запросе.
    $no = $no ?? inputInt('no');
    if (!$no) throw new ApiError(ta('agents.steps.no_number'), 'not_found');

    if ((int) $run['engine'] !== 2) {
        $rows = dbAll('SELECT id FROM run_steps WHERE run_id = ? AND element_no = ?', [$run['id'], $no]);
        if (!$rows) throw new ApiError(ta('agents.steps.no_steps_of_element'), 'not_found');

        foreach ($rows as $row) tokenRevokeFor('step_id', (int) $row['id']);
        dbRun('DELETE FROM run_steps WHERE run_id = ? AND element_no = ?', [$run['id'], $no]);
        bumpRev($projectId);
        runTouch((int) $run['id']);

        return ['no' => $no, 'reset' => count($rows)];
    }

    // Какую попытку стираем: названную или последнюю.
    // $latest — только последняя попытка: простой путь не принимает step и attempt из адреса.
    $stepId  = $latest ? null : inputInt('step');
    $attempt = $latest ? null : inputInt('attempt');
    if ($stepId) {
        $row = dbRow('SELECT * FROM run_steps WHERE id = ? AND run_id = ? AND element_no = ?', [$stepId, $run['id'], $no]);
    } elseif ($attempt) {
        $row = dbRow('SELECT * FROM run_steps WHERE run_id = ? AND element_no = ? AND attempt = ?', [$run['id'], $no, $attempt]);
    } else {
        $row = dbRow('SELECT * FROM run_steps WHERE run_id = ? AND element_no = ? ORDER BY attempt DESC LIMIT 1', [$run['id'], $no]);
    }
    if (!$row) throw new ApiError(ta('agents.steps.no_steps_of_element'), 'not_found');
    // Стартер только отмечает начало — переделывать в нём нечего.
    if (isStarter((int) $row['element_id'])) throw new ApiError(ta('agents.steps.starter_no_redo', ['no' => $no]), 'conflict');

    /* На этом результате уже построены другие шаги. Шаги без работы — выдачу,
       которую не взяли, и решение ромба — снимаем заодно, глубже первыми. Блок
       в работе или принятый — отказ со всем списком: иначе откат одной опечатки
       шёл командой на звено. «Не взяли» знает только прогон ведущего: там task
       переводит шаг в работу, а в полном API выданный шаг уже с пропуском. */
    $lead = advanceLeadRun($run);
    $heirs = static function (int $stepId) use ($lead): array {
        $work = [];
        $empty = [];
        $walk = static function (array $ids) use (&$walk, &$work, &$empty, $lead): void {
            foreach (marksHeirs($ids) as $heir) {
                $id = (int) $heir['id'];
                if (isset($work[$id]) || isset($empty[$id])) continue;
                $type = (string) dbValue('SELECT type FROM elements WHERE id = ?', [(int) $heir['element_id']]);
                if (!$lead || ($heir['state'] !== 'issued' && $type !== 'decision')) { $work[$id] = $heir; continue; }
                $walk([$id]);
                $empty[$id] = $heir;
            }
        };
        $walk([$stepId]);
        return [$work, $empty];
    };

    /* Цикл: новый круг только выдан, а переделать просят сданный — берём прошлый
       принятый круг, выдача уйдёт заодно как его наследник. Иначе откат в цикле
       шёл двумя командами: сперва снять выдачу, потом сам круг. */
    if ($latest && $lead && $row['state'] === 'issued') {
        $done = dbRow("SELECT * FROM run_steps WHERE run_id = ? AND element_no = ? AND state = 'accepted'
                        ORDER BY attempt DESC LIMIT 1", [$run['id'], $no]);
        if ($done && isset($heirs((int) $done['id'])[1][(int) $row['id']])) $row = $done;
    }

    [$work, $empty] = $heirs((int) $row['id']);
    if ($work) throw marksHeirsError(array_values($work));

    $also = [];
    foreach ($empty as $heir) $also[] = stepErase($projectId, $run, $heir, $actor);
    stepErase($projectId, $run, $row, $actor);
    bumpRev($projectId);
    runTouch((int) $run['id']);

    $type = (string) dbValue('SELECT type FROM elements WHERE id = ?', [(int) $row['element_id']]);
    return ['no' => $no, 'reset' => 1 + count($also), 'address' => $row['element_no'] . '.' . $row['attempt'],
            'also' => $also, 'state' => $row['state'], 'type' => $type];
}

/**
 * Стереть попытку: пропуск погасить, забранные жетоны вернуть на стрелки, след в ленту.
 * Отдаёт «109.1 (выдача)» — что стёрто, для ответа leader.
 */
function stepErase(int $projectId, array $run, array $row, ?string $actor): string
{
    tokenRevokeFor('step_id', (int) $row['id']);
    marksRelease((int) $row['id']);
    $address = $row['element_no'] . '.' . $row['attempt'];
    /* След в ленте: номер стёртой попытки больше не выдаётся (stepNextAttempt).
       Выдачу, которую в прогоне ведущего не взяли в работу (задания никто не
       видел), номер не расходует — у события нет попытки, и следующий круг
       получит тот же номер. */
    $untaken = $row['state'] === 'issued' && advanceLeadRun($run);
    eventAdd([
        'project_id' => $projectId, 'run_id' => (int) $run['id'], 'kind' => 'reset', 'actor' => $actor ?? eventActor(caller()),
        'element_no' => (int) $row['element_no'], 'attempt' => $untaken ? null : (int) $row['attempt'],
        'title' => $untaken ? ta('agents.steps.ev_issue_removed', ['address' => $address]) : ta('agents.steps.ev_attempt_erased', ['address' => $address]),
    ]);
    // Положенные попыткой жетоны уходят вместе с ней (внешний ключ run_marks.from_step_id).
    dbRun('DELETE FROM run_steps WHERE id = ?', [(int) $row['id']]);

    $type = (string) dbValue('SELECT type FROM elements WHERE id = ?', [(int) $row['element_id']]);
    return $address . ($type === 'decision' ? ta('agents.steps.suffix_decision') : ($untaken ? ta('agents.steps.suffix_issue') : ''));
}

/**
 * POST run.reset — живой прогон (running, paused) заново: все шаги долой, тот же id и rN.
 * Закрытый не оживает — отказ 409: начать заново — новый прогон (begin, run.start).
 */
function runReset(): void
{
    $runId = 0;
    $repeated = false;
    $out = engineCommand(static function () use (&$runId): array {
        [$project, $run] = runOwn(true);
        $runId = (int) $run['id'];
        runMove($run, 'reset');
        runLiveGuard((int) $run['folder_id'], $runId);
        return runResetDo($project, $run);
    }, $repeated);

    if (!$repeated && ($move = advanceAfter($runId))) $out['advance'] = $move;
    reply($out);
}

/**
 * Работа живого сброса — только под замком, одной транзакцией команды.
 *
 * Адреса назад не идут: до удаления шагов каждому элементу — событие `reset` с наибольшей
 * попыткой, считая выдачи и прежние сбросы (stepNextAttempt). Прежние события остаются.
 * Событие без элемента — новая жизнь прогона для экрана (runEpoch). Пропуска шагов гаснут,
 * жетоны уходят вместе с шагами (внешний ключ), запоздалому ответу Jev или worker применить
 * себя больше не к чему. Обложки этого прогона снимаются; папка out/rN остаётся.
 * Старый прогон (engine = 1) переходит на жетоны-строки: считать прежним способом нечего.
 */
function runResetDo(array $project, array $run): array
{
    $projectId = (int) $project['id'];
    $runId = (int) $run['id'];
    $actor = eventActor(caller());

    $rows = dbAll('SELECT id, element_no FROM run_steps WHERE run_id = ? ORDER BY id', [$runId]);
    foreach (array_unique(array_map(static fn(array $r) => (int) $r['element_no'], $rows)) as $no) {
        $top = stepNextAttempt($runId, $no) - 1;
        eventAdd(['project_id' => $projectId, 'run_id' => $runId, 'kind' => 'reset', 'actor' => $actor,
                  'element_no' => $no, 'attempt' => $top,
                  'title' => ta('agents.runs.ev_reset_block', ['no' => $no, 'attempt' => $top])]);
    }
    eventAdd(['project_id' => $projectId, 'run_id' => $runId, 'kind' => 'reset', 'actor' => $actor,
              'title' => ta('agents.runs.ev_reset_run', ['n' => count($rows)])]);

    foreach ($rows as $row) tokenRevokeFor('step_id', (int) $row['id']);
    runCoversDrop($run, bumpRev($projectId));
    dbRun('DELETE FROM run_steps WHERE run_id = ?', [$runId]);
    dbRun("UPDATE runs SET engine = 2, stop_at = NULL, finished_at = NULL, next_move_at = NULL, wait_for = ''
            WHERE id = ?", [$runId]);
    runSetState($runId, 'running', $projectId, ta('agents.steps.run_restarted'));
    runTouch($runId);

    return ['run' => runShape(runRow($runId, $projectId)), 'reset' => count($rows)];
}

/**
 * Принятая картинка становится обложкой блока.
 *
 * Результат принадлежит шагу, а не схеме, поэтому на холсте его было не видно.
 * При приёмке последнюю картинку шага дополнительно цепляем к блоку ролью
 * «обложка» — прогон кончится, а кадр на карточке останется.
 */
function stepCoverToElement(array $step, int $projectId): void
{
    if (!$step['element_id']) return;
    $type = (string) dbValue('SELECT type FROM elements WHERE id = ?', [(int) $step['element_id']]);
    if ($type !== 'block') return;

    $picture = dbRow(
        "SELECT a.id FROM asset_links l JOIN assets a ON a.id = l.asset_id
          WHERE l.step_id = ? AND l.role = 'result' AND " . STEP_PICTURE_SQL . "
          ORDER BY l.id DESC LIMIT 1",
        [(int) $step['id']]
    );
    if (!$picture) return;

    $rev = bumpRev($projectId);
    $ctx = [
        'projectId' => $projectId, 'rev' => $rev, 'refs' => [], 'folders' => [], 'warnings' => [],
        'activeGuardRuntimeRun' => (int) $step['run_id'],
        'activeGuardRuntimeStep' => (int) $step['id'],
    ];
    assetLink([
        'asset'   => (int) $picture['id'],
        'element' => (int) $step['element_id'],
        'role'    => 'cover',
        // Пометка: эта обложка принадлежит прогону. Оформление схемы,
        // поставленное человеком, остаётся на месте и вернётся при подготовке.
        'madeByRun' => (int) $step['run_id'],
    ], $ctx);
    foreach (array_unique($ctx['folders']) as $folderId) touchFolder((int) $folderId, $rev);
}

function specChanged(array $step): bool
{
    if (!$step['spec_hash'] || !$step['element_id']) return false;
    $spec = specText('element_id', (int) $step['element_id']);
    return $spec && hash('sha256', $spec['text']) !== $step['spec_hash'];
}

/**
 * Слишком быстрая сдача.
 *
 * Оговорка нужна там, где шаг обязан оставить след: сдать файл или сходить
 * в платный сервис. Такое за три секунды не делается, и быстрая сдача значит
 * «работы не было». Там же, где работа идёт в уме и весь результат — одна
 * строка, быстро — это нормально: на счётных схемах круг вставал на каждом
 * честном шаге и звал человека впустую.
 */
function stepTooFast(array $step): bool
{
    if (!$step['opened_at'] || !$step['submitted_at']) return false;
    if ((dbStamp($step['submitted_at'] ?? null) - dbStamp($step['opened_at'] ?? null)) >= 3) return false;

    // Быстро — не беда, если схема следа и не ждала или он всё-таки есть.
    return stepOwesTrace($step) && !stepHasTrace($step);
}

/** Должен ли шаг оставить след: выходы в схеме или платные вызовы. */
function stepOwesTrace(array $step): bool
{
    $elementId = (int) ($step['element_id'] ?? 0);
    if (!$elementId) return false;

    $outputs = runProp($elementId, 'outputs', []);
    if (is_string($outputs)) $outputs = array_filter(array_map('trim', explode(',', $outputs)));
    if (!empty($outputs)) return true;

    return (int) runProp($elementId, 'paid_calls', 0) > 0;
}

/** Остался ли след: сданные файлы или заявки сервисам. */
function stepHasTrace(array $step): bool
{
    $stepId = (int) $step['id'];
    $files = (int) dbValue("SELECT COUNT(*) FROM asset_links WHERE step_id = ? AND role = 'result'", [$stepId]);
    if ($files > 0) return true;

    return (int) dbValue('SELECT COUNT(*) FROM run_jobs WHERE step_id = ?', [$stepId]) > 0;
}

/**
 * Отчёты шагов, а сквозь ромбы и шлюзы — отчёты блоков за ними.
 * Единственный такой обход: вход worker, задание leader, вопрос Jev и переход по шлюзу берут строки отсюда.
 *
 * Ромб сдаёт только ветку, поэтому остановись на нём — и worker потеряет
 * имя файла, которое назвал блок перед ромбом. Поэтому идём дальше вглубь.
 * $seen общий на весь обход: блок, до которого дошли двумя путями, назван один раз.
 */
function stepInputRows(array $stepIds, array &$seen = []): array
{
    $stepIds = array_values(array_diff($stepIds, $seen));
    if (!$stepIds) return [];
    $seen = array_merge($seen, $stepIds);

    $in = implode(',', array_fill(0, count($stepIds), '?'));
    $rows = dbAll(
        "SELECT s.id, s.element_no, s.result, e.title, e.type
           FROM run_steps s LEFT JOIN elements e ON e.id = s.element_id
          WHERE s.id IN ($in) ORDER BY s.id", $stepIds);

    $out = [];
    foreach ($rows as $row) {
        $result = trim((string) $row['result']);
        $type   = (string) ($row['type'] ?? '');

        if ($type === 'decision' || $type === 'gateway') {
            $back = array_map(
                static fn(array $m) => (int) $m['from_step_id'],
                dbAll('SELECT from_step_id FROM run_marks WHERE taken_by_step_id = ? ORDER BY id',
                    [(int) $row['id']]));
            $out = array_merge($out, stepInputRows($back, $seen));
            continue;
        }
        if ($result === '') continue;
        $out[] = [
            'no'     => (int) $row['element_no'],
            'title'  => (string) ($row['title'] ?? ''),
            'result' => $result,
        ];
    }
    return $out;
}
