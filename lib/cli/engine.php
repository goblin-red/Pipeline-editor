<?php
/* Утилита нового движка (этап Э3): смотреть прогон, ручной шаг одним вызовом,
   вести до человека, брать работу worker.
   Отдаёт: cmdState(), cmdHistory(), cmdPace(), cmdGo(), cmdDriveRoute(), cmdDrive2(),
           cmdWorkRoute(), cmdWork2(),
           workTake(), runState(), runPhase(), reviewOf(), didLine(), serverHas().
   Не делает: не знает правил прогона — ни условий ромба, ни приёмки, ни жетонов.
              Ход делает сервер (run.advance); утилита печатает, что он сделал,
              и замечает, когда нужен человек или worker молчит.

   Коды выхода (md_backend/ru/progon.md): 0 пройден или ход сделан · 2 сервер недоступен
     3 нужен человек · 4 worker молчит · 5 остановлен, провален или на паузе · 6 тупик.

   drive и work — основные команды: cmdDriveRoute() и cmdWorkRoute() выбирают путь по engine
   прогона. drive2 и work2 — имена-синонимы нового пути, drive1 и work1 — старый ход (engine = 1).
   Таблица команд — COMMANDS в bin/goblin.php. */

declare(strict_types=1);

const RUN_WORDS    = ['running' => 'идёт', 'paused' => 'на паузе', 'stopped' => 'остановлен',
                      'done' => 'пройден', 'failed' => 'сорвался'];
const DRIVER_WORDS = ['manual' => 'ведёт человек', 'utility' => 'ведёт утилита'];
const JUDGE_WORDS  = ['human' => 'принимает человек', 'formal' => 'принимают формальные проверки'];
const JEV_WORDS    = ['off' => 'Jev выключен', 'advisor' => 'Jev советует', 'judge' => 'Jev принимает'];
const STEP_WORDS   = ['issued' => 'выдан', 'running' => 'в работе', 'submitted' => 'сдан',
                      'accepted' => 'принят', 'returned' => 'возвращён', 'failed' => 'упал',
                      'cancelled' => 'отменён'];
const BY_WORDS     = ['formal' => 'формально', 'human' => 'человек', 'jev' => 'Jev',
                      'lead' => 'leader', 'worker' => 'worker', 'script' => 'утилита'];


// ── Сервер ─────────────────────────────────────────────────────

/** Есть ли у сервера операция. Список спрашиваем один раз за запуск. */
function serverHas(string $op): bool
{
    static $ops = null;
    if ($ops === null) {
        $answer = quiet(static fn() => call('GET', 'config.get', [], []));
        $why = $answer === null ? lastFail()->why : '';
        /* Пропуск погас (прогон закрылся) — спрашиваем ключом проекта: читать итог
           leader вправе и после конца прогона. Сервера нет — это обрыв, код 2. */
        if ($answer === null && in_array($why, ['unauthorized', 'scope'], true)) {
            $answer = quiet(static fn() => call('GET', 'config.get', [], ['anon' => true]));
        }
        if ($answer === null) {
            if ($why === 'network') die_(lastFail()->getMessage(), 'network', 2);
            if (in_array($why, ['unauthorized', 'scope'], true)) {
                die_('пропуск не действует: прогон закрыт или пропуск отозван — '
                    . 'возьмите новый (goblin attach rN)', $why, 2);
            }
            die_('сервер не отдал список операций: ' . lastFail()->getMessage(), $why ?: 'server', 2);
        }
        $ops = array_column($answer['ops'] ?? [], 'op');
    }
    return in_array($op, $ops, true);
}

/** Новой операции ещё нет на сервере — сказать прямо, а не «неизвестная операция». */
function needServer(string $op): void
{
    if (!serverHas($op)) die_("сервер ещё не умеет $op — это серверная часть этапа Э3", 'not_ready');
}

/**
 * Снимок прогона (run.state); с $full — ещё и все шаги.
 *
 * Пройденный прогон гасит пропуск leader, а посмотреть итог всё равно нужно:
 * пропуск больше не действует — читаем ключом проекта, как это делает человек.
 */
function runState(array $f, bool $full = false): array
{
    needServer('run.state');
    $args = runArgs($f) + ($full ? ['full' => 1] : []);
    $a = quiet(static fn() => call('GET', 'run.state', $args, $f, 40));
    if ($a !== null) return $a;

    // Причину первого отказа запоминаем сразу: запасной запрос идёт через
    // quiet(), а тот обнуляет lastFail() — иначе отказ печатается без слов.
    $why   = lastFail()->why;
    $sorry = lastFail()->getMessage();

    $label = runIdArg($f);
    if (in_array($why, ['unauthorized', 'scope'], true) && $label !== null) {
        $byKey = quiet(static fn() => call('GET', 'run.state', ['run' => $label] + $args, ['anon' => true], 40));
        if ($byKey !== null) return $byKey;
    }
    die_($sorry, $why ?: 'server', 2);
}

/** Одна страница хода: version будит ожидание, event-курсор доставляет действия без потерь. */
function runAdvance(array $f, int $since, int $sinceEvent, int $wait = 25): array
{
    return call('POST', 'run.advance', runArgs($f) + [
        'since' => $since, 'sinceEvent' => $sinceEvent, 'wait' => $wait,
    ], $f, max(15, $wait + 15));
}

/**
 * Забрать весь согласованный снимок событий. Пока есть hasMoreEvents, страницы
 * читаются с wait=0; event id защищает печать от дубля при повторном ответе.
 * Возвращает [последний ответ с объединённым did, новый event-курсор].
 */
function runAdvanceDrain(array $f, int $since, int $sinceEvent, int $wait = 25): array
{
    $cursor = $sinceEvent;
    $did = [];
    $seen = [];
    $answer = [];

    for ($page = 0; $page < 100; $page++) {
        $before = $cursor;
        $answer = runAdvance($f, $since, $cursor, $page === 0 ? $wait : 0);
        if (!array_key_exists('lastEvent', $answer) || !array_key_exists('hasMoreEvents', $answer)) {
            die_('run.advance не вернул lastEvent/hasMoreEvents', 'bad_contract');
        }
        foreach ($answer['did'] ?? [] as $one) {
            $event = (int) ($one['event'] ?? 0);
            if ($event <= 0) die_('событие did без числового event id', 'bad_contract');
            if ($event <= $sinceEvent || isset($seen[$event])) continue;
            $seen[$event] = true;
            $did[] = $one;
        }

        $cursor = max($cursor, (int) ($answer['lastEvent'] ?? $cursor));
        if (empty($answer['hasMoreEvents'])) {
            $answer['did'] = $did;
            $answer['lastEvent'] = $cursor;
            return [$answer, $cursor];
        }
        if ($cursor <= $before) die_('сервер не двигает lastEvent при hasMoreEvents', 'bad_contract');
        $since = (int) ($answer['run']['version'] ?? $since);
    }
    die_('слишком много страниц did — lastEvent не исчерпан', 'bad_contract');
}

/**
 * Ход круга. Отдаёт [ответ, курсор событий]; прогон закрылся — [null, курсор],
 * а в `$end` лежит код выхода.
 *
 * Сервер гасит пропуск leader, как только прогон пройден или остановлен, поэтому
 * последний запрос круга приходит отказом. Это конец работы, а не сбой: перечитываем
 * состояние ключом проекта и отвечаем как положено.
 */
function advanceOrEnd(array $f, int $since, int $sinceEvent, ?int &$end, int $wait = 25): array
{
    $page = quiet(static fn() => runAdvanceDrain($f, $since, $sinceEvent, $wait));
    if ($page !== null) return $page;

    // Слова отказа берём до запасного запроса: quiet() обнуляет lastFail(),
    // и после удачного run.state причина первого отказа исчезала.
    $why   = lastFail()->why;
    $sorry = lastFail()->getMessage();

    $label = runIdArg($f);
    if (in_array($why, ['unauthorized', 'scope'], true) && $label !== null) {
        $state = quiet(static fn() => call('GET', 'run.state', ['run' => $label], ['anon' => true], 20));
        $where = (string) ($state['run']['state'] ?? '');
        if ($where === 'done') {
            say('✓ ' . runLabel($state) . ' пройден');
            $end = 0;
            return [null, $sinceEvent];
        }
        if (in_array($where, ['stopped', 'failed'], true)) {
            say('■ ' . runLabel($state) . ' ' . (RUN_WORDS[$where] ?? $where));
            $end = 5;
            return [null, $sinceEvent];
        }
    }
    // Живой прогон и отказ по пропуску: чаще всего в окружении лежат сразу
    // пропуск leader и пропуск агента, а утилита берёт агентский раньше.
    if (in_array($why, ['unauthorized', 'scope'], true) && cfg('agent') !== '' && cfg('run') !== '') {
        $sorry .= '; в окружении сразу GOBLIN_AGENT и GOBLIN_RUN — leader и worker'
            . ' работают в разных окружениях, для drive оставь только GOBLIN_RUN';
    }
    die_($sorry, $why ?: 'server', 2);
}

/**
 * Где прогон сейчас — только по ответу сервера, без правил:
 *   done · stopped (не идёт: остановлен, провален, пауза) · show (ждёт паузу показа)
 *   review (сдача ждёт человека) · human (готово то, чего движок сам не делает)
 *   dead (ничего не открыто и ничего не готово — тупик) · work (worker работают)
 */
function runPhase(array $a): string
{
    $state = (string) ($a['run']['state'] ?? '');
    if ($state === 'done') return 'done';
    if ($state !== 'running') return 'stopped';
    if (($a['wakeIn'] ?? null) !== null) return 'show';

    $open = $a['open'] ?? [];
    foreach ($open as $one) {
        if (($one['state'] ?? '') === 'submitted') return 'review';
    }
    if (!empty($a['ready'])) return 'human';
    return $open ? 'work' : 'dead';
}

/**
 * Сервер просит ещё один явный ход: сделать его до остановки у человека.
 * Один и тот же version повторно не дёргаем — это был бы tight loop на
 * нарушенном контракте needsAdvance.
 */
function runNeedsAdvance(array $a, array &$seen): bool
{
    if (empty($a['needsAdvance'])) return false;
    $version = (int) ($a['run']['version'] ?? -1);
    if (isset($seen[$version])) {
        die_('сервер повторил needsAdvance без смены version — автоматический ход остановлен',
            'bad_contract', 3);
    }
    $seen[$version] = true;
    return true;
}


// ── Печать ─────────────────────────────────────────────────────

/** Секунды без лишних нулей: 3, 1.5. */
function secs($value): string
{
    $n = (float) $value;
    return $n == floor($n) ? (string) (int) $n : (string) round($n, 1);
}

/** Одна строка о том, что сделал движок (элемент `did`). */
function didLine(array $d): string
{
    $step = (string) ($d['step'] ?? '');
    $why  = trim((string) ($d['why'] ?? ''));
    $by   = isset($d['by']) ? ' · ' . (BY_WORDS[$d['by']] ?? $d['by']) : '';

    /* Заголовок события часто начинается теми же словами, что и наша строка
       («принят 35.2»): повтор срезаем, иначе выходит «принят 35.2: принят 35.2». */
    $tail = static function (string $head) use ($why): string {
        $why = trim(preg_replace('/^' . preg_quote($head, '/') . '\s*·?\s*/u', '', $why));
        return $why !== '' ? ": $why" : '';
    };

    return match ((string) ($d['kind'] ?? '')) {
        'accept' => "✓ принят $step$by" . $tail("принят $step"),
        'return' => "↩ возвращён $step$by" . $tail("возвращён $step"),
        'decide' => "◆ ромб $step → " . (($d['branch'] ?? '') === 'yes' ? 'да' : 'нет') . ($why !== '' ? " ($why)" : ''),
        'open'   => "→ выдан $step" . (!empty($d['agent']) ? " · {$d['agent']}" : ''),
        'message' => '✉ ' . ($why !== '' ? $why : 'сообщение') . ($step !== null ? " · шаг $step" : ''),
        // Заголовок события сам может начинаться словами «прогон закрыт»:
        // срезаем повтор, иначе выходило «прогон закрыт: прогон закрыт: …».
        'finish' => '■ прогон закрыт' . ($why !== ''
            ? ': ' . preg_replace('/^прогон закрыт:\s*/u', '', $why)
            : ''),
        default  => '· ' . trim(($d['kind'] ?? '') . " $step") . ($why !== '' ? ": $why" : ''),
    };
}

/** Шапка прогона и «чего ждёт». */
function sayState(array $a): void
{
    $run = $a['run'];
    say(sprintf('прогон %s · %s · %s · %s · %s · пауза показа %s с · версия %d',
        $run['label'] ?? '?',
        RUN_WORDS[$run['state'] ?? ''] ?? ($run['state'] ?? '?'),
        DRIVER_WORDS[$run['driver'] ?? ''] ?? 'ведёт ?',
        JUDGE_WORDS[$run['judge'] ?? ''] ?? 'принимает ?',
        JEV_WORDS[$run['jev'] ?? ''] ?? 'Jev ?',
        secs($run['showPause'] ?? 0),
        (int) ($run['version'] ?? 0)));

    if (trim((string) ($run['waitFor'] ?? '')) !== '') say('чего ждёт: ' . $run['waitFor']);

    /* Сданный ответ показываем прямо здесь. Без него leader, у которого нет
       под рукой вывода drive, решал вслепую: строка «ждёт вашей приёмки»
       называла шаг, но не то, что в нём сдано. */
    foreach ($a['open'] ?? [] as $one) {
        $said = trim((string) ($one['result'] ?? ''));
        say("  открыт {$one['no']}.{$one['attempt']} — " . (STEP_WORDS[$one['state']] ?? $one['state'])
            . ($said !== '' ? ': ' . mb_substr($said, 0, 120) : ''));

        if (($one['state'] ?? '') === 'submitted') {
            say("     принять: goblin go {$one['no']}   ·   вернуть: goblin go {$one['no']} --return «что не так»");
        }
    }
    // У закрытого прогона «готово к выдаче» — не подсказка, а обман:
    // выдавать уже нечего и некому.
    $alive = in_array((string) ($run['state'] ?? ''), ['running', 'paused'], true);
    if ($alive && !empty($a['ready'])) {
        say('готово, но движок сам не делает: ' . implode(', ', array_map(
            static fn($r) => (string) ($r['no'] ?? '?') . (!empty($r['title']) ? " «{$r['title']}»" : ''),
            $a['ready'])));
    }
    if (($a['wakeIn'] ?? null) !== null) say('следующий ход через ' . secs($a['wakeIn']) . ' с — пауза показа');
}

/** Шаги строками: значок, адрес, результат. */
function sayHistory(array $rows): void
{
    foreach ($rows as $one) {
        $mark = MARKS[$one['state']] ?? '·';
        $said = trim((string) ($one['result'] ?? ''));
        $word = $one['state'] !== 'accepted' ? '  (' . (STEP_WORDS[$one['state']] ?? $one['state']) . ')' : '';
        say("  $mark {$one['no']}.{$one['attempt']}" . ($said !== '' ? "  $said" : '') . $word);
    }
}

/** Заголовок прогона коротко: для строк drive. */
function runLabel(array $a): string
{
    return (string) ($a['run']['label'] ?? '?');
}


// ── leader: смотреть ──────────────────────────────────────────

/** goblin state [--full] — где мы и чего ждём; с --full ещё и все шаги. */
function cmdState(array $f): int
{
    $a = runState($f, !empty($f['full']));
    sayState($a);
    if (!empty($f['full'])) sayHistory($a['steps'] ?? []);
    return 0;
}

/** goblin history [--no 35] [--all] — принятое в прогоне (с --all — все попытки). */
function cmdHistory(array $f): int
{
    $a   = runState($f, true);
    $no  = num($f, 'no', 0);
    $all = !empty($f['all']);

    $rows = array_values(array_filter($a['steps'] ?? [], static fn($one) =>
        ($all || $one['state'] === 'accepted') && (!$no || (int) $one['no'] === $no)));

    say('прогон ' . runLabel($a) . ' · ' . ($all ? 'все попытки' : 'принятое')
        . ($no ? " · блок $no" : '') . ' · ' . count($rows));
    sayHistory($rows);
    return 0;
}

/**
 * goblin report [--id rN] — короткий итог прогона: сколько принято, сколько
 * времени занял каждый шаг и что выглядит подозрительно быстрым.
 *
 * Операция run.report была на сервере с самого начала, но входа из утилиты
 * у неё не было — итог приходилось собирать глазами из history.
 */
function cmdReport(array $f): int
{
    needServer('run.report');

    // Итог смотрят уже после конца прогона: пропуск leader к этому моменту погас.
    $slow = num($f, 'slow', 0);
    $a = askAboutRun('run.report', runArgs($f) + ($slow > 0 ? ['slow' => $slow] : []), $f);

    /* Ромбы и шлюзы — тоже строки шагов, но работы в них нет. Слово «попыток»
       в заголовке сбивало: считаем отдельно и называем каждое своим словом. */
    $byWorker = 0;
    $byEngine = 0;
    foreach ($a['steps'] ?? [] as $one) {
        if (($one['state'] ?? '') !== 'accepted') continue;
        in_array((string) ($one['type'] ?? ''), ['decision', 'gateway'], true) ? $byEngine++ : $byWorker++;
    }

    $run = $a['run'] ?? [];
    say('прогон ' . runLabel($a) . ' · ' . (RUN_WORDS[$run['state'] ?? ''] ?? ($run['state'] ?? '?'))
        . ' · принято ' . (int) ($a['accepted'] ?? 0) . ' из ' . (int) ($a['total'] ?? 0)
        . ((int) ($a['jobs'] ?? 0) ? ' · заявок ' . (int) $a['jobs'] : ''));
    if ($byEngine) say("  работ worker: $byWorker · решений движка и шлюзов: $byEngine");

    $fast = 0;
    foreach ($a['steps'] ?? [] as $one) {
        $mark = match ((string) $one['state']) {
            'accepted' => '✓', 'returned' => '↩', 'failed' => '✗', 'cancelled' => '⊘', default => '·',
        };
        $seconds = $one['seconds'] === null ? '' : ' · ' . secs((float) $one['seconds']) . ' с';
        if (!empty($one['suspiciouslyFast'])) $fast++;

        say(sprintf('  %s %d.%d %s%s%s%s',
            $mark, (int) $one['no'], (int) $one['attempt'], (string) $one['title'], $seconds,
            trim((string) $one['result']) !== '' ? ' — ' . $one['result'] : '',
            !empty($one['suspiciouslyFast']) ? '  · быстро, без содержательной проверки' : ''));

        // Возврат и провал без причины читать бессмысленно: она и есть суть строки.
        $error = trim((string) ($one['error'] ?? ''));
        if ($error !== '' && in_array((string) $one['state'], ['returned', 'failed', 'cancelled'], true)) {
            say('      причина: ' . $error);
        }
    }

    // Не обвинение, а наводка: быстро и без содержательной проверки.
    if ($fast) {
        say("· быстрых шагов без содержательной проверки: $fast"
            . ' — стоит глянуть, порог меняется флагом --slow N');
    }
    if (trim((string) ($run['summary'] ?? '')) !== '') say('итог: ' . $run['summary']);
    return 0;
}

/** goblin pace 3 — пауза показа на ходу; 0 отпускает прогон на полную скорость. */
function cmdPace(array $f): int
{
    needServer('run.update');
    $pause = arg($f);
    if ($pause === null || !is_numeric($pause) || (float) $pause < 0) {
        die_('goblin pace <секунды>   — 0 снимает паузу показа');
    }

    $a = call('POST', 'run.update', runArgs($f) + ['pause' => (float) $pause], $f);
    $now = (float) ($a['run']['showPause'] ?? $pause);
    say($now > 0
        ? '✓ пауза показа ' . secs($now) . ' с: каждый блок и стрелка держатся на холсте'
        : '✓ пауза показа снята: прогон идёт на полной скорости');
    return 0;
}


// ── leader: ручной шаг ─────────────────────────────────────────

/**
 * Ручной шаг одним вызовом.
 *   goblin go [N] [--why «…»]        — принять сданное (N — если сданных несколько)
 *   goblin go [N] --return «…»       — вернуть на доработку
 *   goblin go 36 yes|no [--why «…»]  — решить ромб
 * Затем ход движка — до следующей сдачи, до человека или до конца — и одним экраном
 * новая сдача со всем для проверки.
 */
function cmdGo(array $f): int
{
    needServer('run.advance');
    $before = runState($f, true);

    /* Шаг называют и номером блока (36), и адресом попытки (36.2) — в выводе
       он везде стоит с попыткой, и leader подставляли его целиком. Берём
       номер блока: попытку всё равно выбирает сервер по открытой сдаче. */
    $no = arg($f);
    if (is_string($no) && preg_match('/^(\d+)\.\d+$/', trim($no), $found)) $no = $found[1];

    $branch = arg($f, 1);

    // 1. Действие человека.
    if ($branch !== null) {
        if (!in_array($branch, ['yes', 'no'], true)) die_('ветка ромба: yes или no');
        call('POST', 'step.decide', runArgs($f) + ['no' => $no, 'branch' => $branch, 'why' => $f['why'] ?? null], $f);
        say("◆ ромб $no → " . ($branch === 'yes' ? 'да' : 'нет'));
    } else {
        $sent = array_values(array_filter($before['open'] ?? [], static fn($one) =>
            ($one['state'] ?? '') === 'submitted' && ($no === null || (string) $one['no'] === (string) $no)));

        if (count($sent) > 1) {
            die_('сдано несколько шагов — скажи номер: goblin go ' . implode(' | ', array_column($sent, 'no')));
        }
        if ($sent) {
            goAct($f, $sent[0]);
        } elseif (isset($f['return']) || $no !== null) {
            die_('сданного шага' . ($no !== null ? " $no" : '') . ' нет — принимать и возвращать нечего');
        }
    }

    /* 2. Ход движка. Курсор ставим на верхушку ленты, снятую до действия:
          иначе каждый `go` заново печатал прогон с первого события, и на
          длинной схеме leader получал простыню вместо своего шага.
          Всю ленту показывают goblin log и goblin history.

          Ярлык прогона запоминаем здесь же: приёмка последнего шага закрывает
          прогон и гасит пропуск leader, и без ярлыка следующий запрос падал
          с «Пропуск не действует» — на удачно завершённом прогоне. */
    if (!array_key_exists('id', $f) && !empty($before['run']['label'])) {
        $f['id'] = (string) $before['run']['label'];
    }
    return goOn($f, (int) ($before['lastEvent'] ?? 0));
}

/** Принять или вернуть одну сдачу. */
function goAct(array $f, array $step): void
{
    $address = "{$step['no']}.{$step['attempt']}";

    if (isset($f['return'])) {
        $why = is_string($f['return']) ? $f['return'] : (string) ($f['why'] ?? '');
        if (trim($why) === '') die_('скажи, что не так: goblin go --return «…»');
        call('POST', 'step.return', runArgs($f) + ['step' => $step['id'], 'why' => $why], $f);
        say("↩ возвращён $address: $why");
        return;
    }
    // Печатать приёмку здесь не нужно: следом идёт лента событий, и она
    // скажет то же самое, только с разбором проверок.
    call('POST', 'step.accept', runArgs($f) + ['step' => $step['id'], 'why' => $f['why'] ?? null], $f);
}

/** Ход движка после действия: печатать сделанное, остановиться на том, что требует человека. */
function goOn(array $f, int $sinceEvent = 0): int
{
    $timeout = num($f, 'timeout', 600);
    $since   = -1;
    $moved   = time();
    $advanceSeen = [];
    $end = null;

    // Сказали один раз, что ждём worker: без этого `go` после приёмки молчит
    // до самого таймаута, и непонятно, работает он или завис.
    $said = false;

    while (true) {
        // Ждать дольше собственного срока незачем: --timeout 5 должен
        // возвращать управление через пять секунд, а не через двадцать пять.
        [$a, $sinceEvent] = advanceOrEnd($f, $since, $sinceEvent, $end, waitWithin($moved, $timeout));
        if ($a === null) return (int) $end;
        foreach ($a['did'] ?? [] as $line) say(didLine($line));
        if ((int) $a['run']['version'] !== $since) $moved = time();
        $since = (int) $a['run']['version'];
        if (runNeedsAdvance($a, $advanceSeen)) continue;

        if (!$said && runPhase($a) === 'work') {
            say('⏳ жду сдачу worker, до ' . $timeout . ' с (срок задаётся флагом --timeout)'
                . ' — Ctrl+C не повредит прогону');
            $said = true;
        }

        switch (runPhase($a)) {
            case 'done':
                say('✓ прогон ' . runLabel($a) . ' пройден');
                return 0;
            case 'stopped':
                say('■ прогон ' . runLabel($a) . ' ' . (RUN_WORDS[$a['run']['state']] ?? $a['run']['state']));
                return 5;
            case 'review':
                sayReview($a, $f);
                return 0;
            case 'human':
                say('⚠ ' . ($a['run']['waitFor'] ?: 'нужен человек'));
                foreach ($a['ready'] as $item) {
                    if (($item['type'] ?? '') === 'decision') say("  goblin go {$item['no']} yes|no --why «почему»");
                }
                return driveExit(3, 'нужен leader');
            case 'dead':
                say('⚠ ' . ($a['run']['waitFor'] ?: 'тупик: открыть нечего'));
                return driveExit(6, 'тупик: открывать нечего');
        }
        if (time() - $moved > $timeout) {
            say('⚠ worker молчит ' . howLong(time() - $moved) . ': ' . waitingOn($a));
            return driveExit(4, 'worker молчит');
        }
    }
}

/** Новая сдача одним экраном: ТЗ, вход, результат, итог формальных проверок. */
function sayReview(array $a, array $f): void
{
    foreach ($a['open'] ?? [] as $step) {
        if (($step['state'] ?? '') !== 'submitted') continue;
        $r = reviewOf($a, $step, $f);

        say('');
        say("⌛ сдан {$step['no']}.{$step['attempt']}" . ($r['title'] !== '' ? " «{$r['title']}»" : ''));
        if ($r['spec'] !== '') say("ТЗ:\n" . preg_replace('/^/m', '  ', mb_strimwidth($r['spec'], 0, 1200, ' …')));
        if ($r['input'] !== '') say("вход: {$r['input']}");
        if ($r['vars']) {
            say('переменные входа: ' . implode(' ', array_map(
                static fn($k, $v) => "$k=$v", array_keys($r['vars']), $r['vars'])));
        }
        say("результат: {$r['result']}");
        foreach ($r['files'] as $file) say('  файл: ' . (is_array($file) ? ($file['path'] ?? json_encode($file, JSON_UNESCAPED_UNICODE)) : $file));
        say('проверки: ' . ($r['checks'] !== '' ? $r['checks'] : 'сервер не прислал'));
    }
    say('');
    say('дальше: goblin go · goblin go --return «что не так»');
}

/**
 * Всё для проверки сданного шага: { title, spec, input, vars, result, files, checks }.
 *
 * Сервер отдаёт это полем `review` у открытого шага в run.state (решение leader).
 * Пока поля нет — собираем сами из того, что уже есть: пакет задания из ленты
 * прогона (ТЗ, вход, переменные — ровно то, что получил worker), результат — из шагов.
 * Итога формальных проверок без сервера взять неоткуда.
 */
function reviewOf(array $a, array $step, array $f): array
{
    $out = ['title' => '', 'spec' => '', 'input' => '', 'vars' => [], 'result' => '', 'files' => [], 'checks' => ''];

    if (!empty($step['review']) && is_array($step['review'])) {
        $r = $step['review'];
        return [
            'title'  => (string) ($r['title'] ?? $step['title'] ?? ''),
            'spec'   => textOf($r['spec'] ?? ''),
            'input'  => inputLine($r['input'] ?? ''),
            'vars'   => (array) ($r['vars'] ?? []),
            'result' => (string) ($r['result'] ?? ''),
            'files'  => (array) ($r['files'] ?? []),
            'checks' => checksLine($r['checks'] ?? ''),
        ];
    }

    // Результат — из шагов прогона.
    foreach ($a['steps'] ?? runState($f, true)['steps'] ?? [] as $one) {
        if ((int) ($one['id'] ?? 0) === (int) $step['id']) $out['result'] = (string) ($one['result'] ?? '');
    }

    // Пакет этой попытки — последнее событие package в ленте.
    $events = quiet(static fn() => call('GET', 'run.log', runArgs($f) + [
        'step' => (string) $step['no'], 'kind' => 'package',
    ], $f))['events'] ?? [];
    $last = null;
    foreach ($events as $event) {
        if ((int) ($event['attempt'] ?? 0) === (int) $step['attempt']) $last = $event;
    }
    if ($last) {
        $body = quiet(static fn() => call('GET', 'run.log', runArgs($f) + ['event' => (string) $last['id']], $f))['event']['body'] ?? '';
        $pack = json_decode((string) $body, true) ?: [];

        $out['title'] = (string) ($pack['step']['title'] ?? $pack['element']['title'] ?? '');
        $out['spec']  = textOf($pack['spec'] ?? '');
        $out['vars']  = (array) ($pack['vars'] ?? []);
        $out['input'] = inputLine($pack['input'] ?? (($pack['earlier'] ?? []) ? end($pack['earlier']) : ''));
    }
    return $out;
}

/**
 * Образец ответа с пояснением, что в нём подставляют.
 *
 * `{a}` — имя переменной, и worker гадали, писать ли фигурные скобки. Сами
 * скобки в ответе не нужны: на их место идёт число.
 */
function answerHint(string $answer): string
{
    if (!preg_match_all('/\{([\p{L}_][\p{L}\p{N}_]*)\}/u', $answer, $found)) return $answer;

    $names = array_unique($found[1]);
    return $answer . "\n"
        . (count($names) > 1 ? 'Вместо ' : 'Вместо ')
        . '{' . implode('}, {', $names) . '} подставь '
        . (count($names) > 1 ? 'свои числа' : 'своё число')
        . ' — фигурные скобки в ответе не пишутся.';
}

/**
 * Сколько ещё можно держать долгий запрос: не дольше остатка своего срока.
 * Ноль сервер понимает как «ответь сразу», поэтому меньше секунды не просим.
 */
function waitWithin(int $moved, int $timeout): int
{
    $left = $timeout - (time() - $moved);
    return max(1, min(25, $left));
}

/**
 * Чего ждём, когда сервер молчит.
 *
 * `wait_for` пуст, пока движку нечего делать: шаг выдан и должен быть у worker.
 * Раньше строка «worker молчит 25с:» обрывалась пустотой и не называла шаг.
 */
function waitingOn(array $a): string
{
    $said = trim((string) ($a['run']['waitFor'] ?? ''));
    if ($said !== '') return $said;

    $open = [];
    foreach ($a['open'] ?? [] as $one) {
        $open[] = "{$one['no']}.{$one['attempt']} — " . (STEP_WORDS[$one['state']] ?? $one['state'])
            . (!empty($one['agent']) ? " · агент {$one['agent']}" : '');
    }
    return $open
        ? 'шаг у worker: ' . implode(', ', $open)
        : 'открытых шагов нет — посмотри goblin state';
}

/** Последняя строка drive: код выхода цифрой и словом. */
function driveExit(int $code, string $why): int
{
    say("код выхода $code — $why");
    return $code;
}

/** ТЗ бывает строкой или объектом с полем text. */
function textOf($spec): string
{
    return trim(is_array($spec) ? (string) ($spec['text'] ?? '') : (string) $spec);
}

/** Вход одной строкой: у слияния их несколько. */
function inputLine($input): string
{
    if (is_string($input)) return trim($input);
    if (!is_array($input)) return '';
    if (isset($input['result'])) {
        return (isset($input['no']) ? "{$input['no']}: " : '') . $input['result'];
    }
    return implode(' · ', array_filter(array_map('inputLine', $input)));
}

/** Итог проверок строкой: «образец — да · арифметика — да». */
function checksLine($checks): string
{
    if (is_string($checks)) return trim($checks);
    if (!is_array($checks)) return '';
    $out = [];
    foreach ($checks as $name => $one) {
        $out[] = is_array($one)
            ? trim(($one['name'] ?? $name) . ' — ' . (($one['ok'] ?? null) === true ? 'да' : (($one['ok'] ?? null) === false ? 'нет' : '?'))
                . (!empty($one['why']) ? " ({$one['why']})" : ''))
            : (is_string($name) ? "$name — $one" : (string) $one);
    }
    return implode(' · ', $out);
}


// ── leader: вести до человека ─────────────────────────────────

/** Canonical drive: engine 2 ведёт сервер, engine 1 остаётся на старом круге. */
function cmdDriveRoute(array $f): int
{
    if (!serverHas('run.state')) return cmdDrive($f);
    // Старый drive умеет вести сразу весь проект; новый требует точный прогон.
    if (cfg('run') === '' && runIdArg($f) === null) return cmdDrive($f);
    $state = runState($f);
    return (int) ($state['run']['engine'] ?? 1) === 2 ? cmdDrive2($f) : cmdDrive($f);
}

/**
 * goblin drive2 [--timeout 600] — вести прогон до конца или до человека.
 * Правил здесь нет: каждый вызов run.advance делает всё, что можно без человека,
 * и держит запрос, пока не будет перемены.
 */
function cmdDrive2(array $f): int
{
    needServer('run.advance');
    asScript();
    $timeout = num($f, 'timeout', 600);
    $since   = -1;
    $sinceEvent = 0;
    $moved   = time();
    $advanceSeen = [];
    $end = null;

    while (true) {
        [$a, $sinceEvent] = advanceOrEnd($f, $since, $sinceEvent, $end, waitWithin($moved, $timeout));
        if ($a === null) return (int) $end;
        foreach ($a['did'] ?? [] as $line) say(runLabel($a) . ' · ' . didLine($line));
        if ((int) $a['run']['version'] !== $since) $moved = time();
        $since = (int) $a['run']['version'];
        if (runNeedsAdvance($a, $advanceSeen)) continue;

        /* Код выхода несёт смысл, а видно его только через $?: называем его
           и словами. Агенту-leader это экономит лишний разбор вывода. */
        switch (runPhase($a)) {
            case 'done':    say('✓ ' . runLabel($a) . ' пройден'); return driveExit(0, 'прогон пройден');
            case 'stopped': say('■ ' . runLabel($a) . ' ' . (RUN_WORDS[$a['run']['state']] ?? $a['run']['state']));
                            return driveExit(5, 'прогон не идёт');
            case 'review':
            case 'human':   say('⚠ ' . ($a['run']['waitFor'] ?: 'нужен человек'));
                            return driveExit(3, 'нужен leader');
            case 'dead':    say('⚠ ' . ($a['run']['waitFor'] ?: 'тупик: открыть нечего'));
                            return driveExit(6, 'тупик: открывать нечего');
        }
        if (time() - $moved > $timeout) {
            say('⚠ worker молчит ' . howLong(time() - $moved) . ': ' . waitingOn($a));
            return driveExit(4, 'worker молчит');
        }
    }
}


// ── worker: взять шаг ──────────────────────────────────────────

/** Canonical work: известный legacy-шаг оставляем старому step.mine/get. */
function cmdWorkRoute(array $f): int
{
    if (!serverHas('step.take')) return cmdWork($f);
    $agent = is_string($f['token'] ?? null) ? $f['token'] : cfg('agent');
    if ($agent === '') return cmdWork($f);

    // Явный --id уже задаёт область работы: не подменяем её найденным
    // current-step из общего step.mine.
    if (runIdArg($f) !== null) {
        $state = runState($f);
        $where = (string) ($state['run']['state'] ?? '');
        /* Кончился прогон только в этих состояниях. Пауза обратима: leader сделает
           resume, и работа придёт — уходить из круга нельзя (код 3 = «работы пока нет»). */
        if (in_array($where, ['done', 'stopped', 'failed'], true)) {
            say('прогон закрыт: работа больше не придёт');
            return 0;
        }
        if ($where === 'paused') {
            say('прогон на паузе — работа появится, когда leader продолжит');
            return 3;
        }
        return (int) ($state['run']['engine'] ?? 1) === 1
            ? cmdWork($f)
            : workTake($f);
    }

    $peek = quiet(static fn() => call('GET', 'step.mine', ['wait' => 0], ['_' => [], 'token' => $agent], 15));
    $runId = (int) ($peek['step']['run'] ?? 0);
    if ($runId) {
        $state = quiet(static fn() => call('GET', 'run.state', ['run' => $runId], ['_' => [], 'token' => $agent], 15));
        if ($state !== null && (int) ($state['run']['engine'] ?? 1) === 1) return cmdWork($f);
        $f['id'] = (string) $runId;
    }
    return workTake($f);
}

/** goblin work2 [--wait 25] [--raw] — взять свой шаг через step.take. */
function cmdWork2(array $f): int
{
    needServer('step.take');
    return workTake($f);
}

/**
 * Взять шаг пропуском агента. Пропуск шага — на экран (export) и в кэш:
 * submit, note, job и fail найдут его сами.
 * Коды: 0 — шаг взят или работа окончена · 3 — работы пока нет, зови снова.
 */
function workTake(array $f): int
{
    $agent = is_string($f['token'] ?? null) ? $f['token'] : cfg('agent');
    if ($agent === '') die_('нужен пропуск агента: export GOBLIN_AGENT=gba_…');
    $session = workerSession($f, true);

    // Только пропуском агента: пропуск шага из кэша или окружения тут не годится.
    $wait = num($f, 'wait', 25);
    $take = runArgs($f) + ['wait' => $wait, 'session' => $session];
    $auth = ['_' => [], 'token' => $agent, 'session' => $session];
    $a = quiet(static fn() => call('POST', 'step.take', $take, $auth, $wait + 15));

    if ($a === null) {
        if (lastFail() instanceof CommittedReplay) {
            $replay = lastFail()->response;
            $receipt = is_array($replay['receipt'] ?? null) ? $replay['receipt'] : [];
            $recovery = is_array($replay['recovery'] ?? null) ? $replay['recovery'] : [];
            $run = (int) ($recovery['run'] ?? $receipt['run'] ?? 0);
            $step = (int) ($recovery['step'] ?? $receipt['step'] ?? 0);
            if (!$run || !$step) die_(committedReplayMessage(lastFail()), 'replay_secret_unavailable', 3);

            // Команда уже сделана: не ищем «текущий» шаг заново, а просим новый
            // секрет строго для прежних run/step и той же worker session.
            $restore = ['wait' => 0, 'session' => $session, 'run' => $run, 'step' => $step];
            $a = quiet(static fn() => call('POST', 'step.take', $restore, $auth, 15));
            if ($a === null) die_('команда выполнена, но доступ к прежнему шагу не восстановлен: '
                . lastFail()->getMessage(), lastFail()->why, 3);
        }
    }
    if ($a === null) {
        $why = lastFail()->why;
        if (in_array($why, ['unauthorized', 'not_found'], true)) {
            stepCacheForget($f);
            die_('доступ к серверу или прогону потерян; исход неизвестен: ' . lastFail()->getMessage(), $why, 2);
        }
        die_(lastFail()->getMessage(), $why, $why === 'network' ? 2 : 1);
    }

    /* Код выхода здесь несёт весь смысл: 0 — расходиться, 3 — звать снова.
       Раньше в тексте его не было, и два похожих исхода читались одинаково. */
    if (empty($a['ready'])) {
        if (!empty($a['running'])) {
            say('работы пока нет — зови goblin work снова (код 3)');
            return 3;
        }
        stepCacheForget($f);
        say('прогонов больше нет — работа окончена (код 0)');
        return 0;
    }

    $step  = $a['step'] ?? [];
    $token = (string) ($step['token'] ?? '');
    if ($token === '') die_('сервер выдал шаг без пропуска (step.token)');
    if (isset($f['id']) && is_string($f['id']) && ctype_digit(trim($f['id']))) {
        $asked = (int) trim($f['id']);
        if ($asked !== (int) ($step['run'] ?? 0)) {
            die_('сервер выдал шаг другого прогона — секрет не сохранён', 'scope_mismatch');
        }
    }
    stepCacheKeep($token, [
        'run' => (int) ($step['run'] ?? 0),
        'agent' => (int) ($step['agent'] ?? 0),
        'step' => (int) ($step['id'] ?? 0),
    ], $f);

    say('→ твой шаг ' . ($step['address'] ?? "{$step['no']}.{$step['attempt']}")
        . (!empty($step['title']) ? ": {$step['title']}" : ''));
    say('пропуск шага сохранён в закрытом кэше worker session');
    if (!empty($f['raw'])) {
        say(json_encode($a['package'] ?? [], JSON_UNESCAPED_UNICODE));
        return 0;
    }

    // Главное — в первых строках: ТЗ длинное, а worker нужны вход и образец.
    sayPackageHead($a['package'] ?? [], !empty($f['brief']));
    if (!empty($f['brief'])) return 0;

    sayPackage($a['package'] ?? []);
    return 0;
}

/**
 * Короткая шапка задания: вход, образец ответа и где взять инструкцию.
 *
 * Полное ТЗ занимает экран, и worker грепали его ради трёх строк. С флагом
 * `--brief` этой шапкой вывод и ограничивается.
 */
function sayPackageHead(array $p, bool $brief = false): void
{
    $vars = (array) ($p['vars'] ?? []);
    $line = $vars
        ? implode(' ', array_map(static fn($k, $v) => "$k=$v", array_keys($vars), $vars))
        : inputLine($p['input'] ?? '');

    say('');
    say('── коротко ──────────────────────────────────');
    // Адрес шага стоит выше рамки, но в кратком выводе он нужен и внутри.
    if (!empty($p['address']))                    say('шаг:     ' . $p['address']
        . (!empty($p['element']['title']) ? ' · ' . $p['element']['title'] : ''));
    // Пустая строка читалась как потерянное поле: у первого шага входа и правда нет.
    say('вход:    ' . (trim($line) !== '' ? $line : 'нет — это первый шаг'));
    // Пустую строку читают как потерянное поле: говорим прямо, что образца нет.
    if (trim((string) ($p['answer'] ?? '')) !== '') {
        foreach (explode("\n", answerHint((string) $p['answer'])) as $i => $row) {
            say(($i === 0 ? 'образец: ' : '         ') . $row);
        }
    } else {
        say('образец: не задан — сдавай так, как велит ТЗ');
    }
    $why = trim((string) ($p['previous']['why'] ?? ''));
    if ($why !== '')                              say('вернули: ' . $why);
    // Слово leader важнее всего остального: оно новее задания.
    foreach ($p['said'] ?? [] as $one)            say('leader: ' . ($one['text'] ?? ''));
    /* Инструкцию называем в начале прогона: на седьмом шаге подряд эта строка
       уже шум. Пустой список принятого — верный признак первого шага. */
    if (empty($p['earlier'])) say('инструкция worker: goblin help worker');
    if ($brief) say('полное задание с ТЗ: goblin task');
    say('─────────────────────────────────────────────');
}

/** Пакет задания по-человечески (md_backend/ru/progon.md). */
function sayPackage(array $p): void
{
    say("Рабочая папка: " . ($p['workDir'] ?? '?') . "   результат класть в: " . ($p['outDir'] ?? '?'));

    $spec = textOf($p['spec'] ?? '');
    if ($spec !== '') say("\n## ТЗ\n$spec");

    if (!empty($p['context'])) {
        say("\n## Контекст");
        foreach ($p['context'] as $item) say('— ' . ($item['title'] ?? '') . ': ' . textOf($item['text'] ?? $item));
    }

    // Входные материалы блока: без них worker не знает, с какими файлами работать.
    if (!empty($p['inputs'])) {
        say("\n## Входы");
        foreach ($p['inputs'] as $item) {
            $where = !empty($item['uri']) ? (string) $item['uri'] : (!empty($item['file']) ? 'файл' : '');
            say('— ' . ($item['title'] ?? '') . ($where !== '' ? ": $where" : ''));
        }
    }

    /* Три названия одного и того же сбивали worker: «Вход» — это откуда
       пришёл жетон, а работать надо с числами, которые сервер уже разобрал. */
    $input = inputLine($p['input'] ?? '');
    if ($input !== '') say("\n## Пришло по стрелке\n$input");
    if (!empty($p['vars'])) {
        say("\n## Твой вход — считай от этих чисел\n" . implode(' ', array_map(
            static fn($k, $v) => "$k=$v", array_keys((array) $p['vars']), (array) $p['vars'])));
    }

    /* Уже принятое в прогоне. ТЗ схем ссылаются на этот раздел прямым текстом
       («возьми числа из раздела Уже принято»), а на engine 2 его не печатали. */
    if (!empty($p['earlier'])) {
        say("\n## Уже принято — справка, разбирать её руками не нужно");
        foreach ($p['earlier'] as $item) {
            say('— ' . ($item['no'] ?? '?') . ' ' . ($item['title'] ?? '') . ': ' . ($item['result'] ?? ''));
        }
    }

    if (trim((string) ($p['answer'] ?? '')) !== '') say("\n## Образец ответа\n" . answerHint((string) $p['answer']));
    if (!empty($p['accept'])) say("\n## Должно быть\n— " . implode("\n— ", (array) $p['accept']));
    if (!empty($p['reject'])) say("\n## Не должно быть\n— " . implode("\n— ", (array) $p['reject']));

    sayPackageLimits($p['limits'] ?? []);
    sayPackageAgent($p['agent'] ?? null);

    // Общая картина папки приходит, только если человек включил share_scheme.
    if (!empty($p['scheme'])) {
        say("\n## Общая картина папки");
        foreach ($p['scheme'] as $item) {
            say(($item['type'] ?? '') === 'arrow'
                ? '— ' . ($item['no'] ?? '?') . ' стрелка ' . ($item['from'] ?? '?') . ' → ' . ($item['to'] ?? '?')
                    . (!empty($item['branch']) ? " [{$item['branch']}]" : '')
                : '— ' . ($item['no'] ?? '?') . ' ' . ($item['type'] ?? '') . ': ' . ($item['title'] ?? ''));
        }
    }

    $why = trim((string) ($p['previous']['why'] ?? ''));
    if ($why !== '') say("\n## Прошлую попытку вернули\n$why");

    /* Записка leader шагу. Печаталась только старым печатником `goblin task`,
       а на engine 2 worker её не видел вовсе — хотя пишут её именно ему.
       Строку «leader: …» с адресом для доклада не повторяем. */
    $note = [];
    foreach (explode("\n", (string) ($p['note'] ?? '')) as $row) {
        $row = trim($row);
        if ($row === '' || str_starts_with($row, 'leader:')) continue;
        $note[] = $row;
    }
    if ($note) say("\n## Подсказка leader\n" . implode(' ', $note));

    // Сказанное вслух после выдачи шага: `goblin say` у leader.
    if (!empty($p['said'])) {
        say("\n## leader говорит");
        foreach ($p['said'] as $one) {
            say('— ' . (BY_WORDS[$one['by'] ?? ''] ?? ($one['by'] ?? '')) . ': ' . ($one['text'] ?? ''));
        }
    }

    if (!empty($p['stop'])) say("\n⚠ человек попросил остановиться: сделай goblin fail --reason cancelled");

    say("\nСдать: goblin submit --result «одна строка» [файл=выход …] [--next]");
}

/** Пределы шага: обязательные выходы, срок и лимит платных вызовов. */
function sayPackageLimits(array $limits): void
{
    $lines = [];

    if (!empty($limits['outputs'])) {
        $lines[] = 'обязательные выходы: ' . implode(', ', (array) $limits['outputs']);
    }
    if (($limits['timeout'] ?? null) !== null && (int) $limits['timeout'] > 0) {
        $lines[] = 'срок: ' . (int) $limits['timeout'] . ' с';
    }
    if (isset($limits['paidCalls'])) {
        $lines[] = 'платных вызовов не больше: ' . (int) $limits['paidCalls']
            . ' (заявка — goblin job add … до обращения к сервису)';
    }

    // Последняя попытка — повод не гадать, а спросить: goblin fail --blocker.
    $max = (int) ($limits['maxAttempts'] ?? 0);
    $now = (int) ($limits['attempt'] ?? 0);
    if ($max > 0) {
        $lines[] = "выдач у этого блока: $now из $max"
            . ($now >= $max ? ' — попытка последняя' : '');
    }

    if ($lines) say("\n## Пределы\n— " . implode("\n— ", $lines));
}

/** Карточка агента: чем работать, какой моделью и с каким усилием. */
function sayPackageAgent(?array $agent): void
{
    if (!$agent) return;

    $lines = array_filter([
        trim((string) ($agent['cli'] ?? '')) !== '' ? 'чем: ' . $agent['cli'] : null,
        trim((string) ($agent['model'] ?? '')) !== '' ? 'модель: ' . $agent['model'] : null,
        trim((string) ($agent['effort'] ?? '')) !== '' ? 'усилие: ' . $agent['effort'] : null,
    ]);
    if (!$lines) return;

    say("\n## Кем работать: " . ($agent['name'] ?? '') . "\n— " . implode("\n— ", $lines));
}
