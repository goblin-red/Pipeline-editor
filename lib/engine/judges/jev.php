<?php
/* Проверяющий Jev (md_backend/ru/progon.md, раздел «Jev»).
   Что делает: сначала код (формальный проверяющий), потом смысловые критерии через Jev;
               словесный ромб — вопрос yes / no / unclear.
   Что отдаёт: judgeJev(), judgeJevBranch() → ['verdict', 'reasons', 'details'].
   Чего не делает: не ходит в базу, не пишет ленту, не зовёт reply(), не берёт замок.
                   Движок вызывает это ВНЕ замка, а свежесть сверяет замыканием $stillSame.

   $package — что проверяется (собирает движок):
     task      — ТЗ блока (или spec.text), input — вход шага,
     work      — проверяемый текст, либо work_file — путь к сданному текстовому файлу,
     accept, reject — строки критериев, judge — свойство блока ('human' закрывает от Jev).
   $settings: high 0.90, low 0.15, deadline 5.0, confidence 0.85,
              model — версия, для которой человек подтвердил пороги (необязательно). */

declare(strict_types=1);

require_once __DIR__ . '/../../core/i18n.php';   // проверки грузят файл отдельно от boot.php: ta() нужен и им

const JEV_JUDGE_DEFAULTS = ['high' => 0.90, 'low' => 0.15, 'deadline' => 5.0, 'confidence' => 0.85];

/* Порог ветки ромба ниже порога приёмки: ромб решает только «да / нет» по
   ответу worker, и на живых прогонах Jev склонялся верно и при 0,65–0,87.
   Ниже порога ромб ждёт leader. Решение человека: 0,6. */
const JEV_BRANCH_CONFIDENCE = 0.6;

/** Почему Jev недоступен — по-человечески. */
function jevWhyText(string $why): string
{
    return match ($why) {
        'timeout'     => ta('agents.judge.why_timeout'),
        'network'     => ta('agents.judge.why_network'),
        'rate'        => ta('agents.judge.why_rate'),
        'overloaded'  => ta('agents.judge.why_overloaded'),
        'auth'        => ta('agents.judge.why_auth'),
        'bad_request' => ta('agents.judge.why_bad_request'),
        'bad_answer'  => ta('agents.judge.why_bad_answer'),
        'other_model' => ta('agents.judge.why_other_model'),
        default       => ta('agents.judge.jev_unavailable'),
    };
}

/**
 * Приёмка сдачи.
 * $formal() → решение формального проверяющего ['verdict', 'reasons', 'checks'].
 * $stillSame(string $fingerprint): bool — те же ли данные сейчас (движок перечитывает под замком).
 */
function judgeJev(array $run, array $step, array $package, array $settings,
                  callable $formal, ?callable $stillSame = null): array
{
    $plan = judgeJevPlan($run, $step, $package, $settings, $formal());
    if (!$plan['call']) return $plan['result'];

    $said = jevCall($plan['state'], $plan['questions'], (float) $plan['settings']['deadline']);
    if ($stillSame !== null && !$stillSame($plan['fingerprint'])) {
        return judgeJevOut('unsure', [ta('agents.judge.stale_answer')],
            judgeJevBase($plan, $said) + ['stale' => true]);
    }
    return judgeJevResolve($plan, $said);
}

/** Под замком: полный неизменяемый снимок решения, но без внешнего вызова. */
function judgeJevPlan(array $run, array $step, array $package, array $settings, array $formal): array
{
    $set = $settings + JEV_JUDGE_DEFAULTS;
    $role = (string) ($run['jev'] ?? 'off');
    $formal += ['verdict' => 'unsure', 'reasons' => [], 'checks' => [], 'detail' => []];
    $accept = jevLines((array) ($package['accept'] ?? []));
    $reject = jevLines((array) ($package['reject'] ?? []));

    $skip = static fn(array $result): array => ['call' => false, 'result' => $result];
    // Явный запрет блока сильнее режима прогона: формальные линии видны как подсказка,
    // но accept/return остаётся за человеком.
    if (($package['judge'] ?? '') === 'human') {
        return $skip(judgeJevHumanFallback($formal, ta('agents.judge.block_closed')));
    }
    if ($role === 'advisor' && (string) ($run['judge'] ?? 'human') === 'human'
        && $formal['verdict'] === 'return') {
        return $skip(judgeJevHumanFallback($formal));
    }
    if (!in_array($role, ['advisor', 'judge'], true) || (!$accept && !$reject)) {
        return $skip((string) ($run['judge'] ?? 'human') === 'formal'
            ? judgeJevOut((string) $formal['verdict'], $formal['reasons'], ['formal' => $formal])
            : judgeJevHumanFallback($formal));
    }
    if ($formal['verdict'] === 'return') {
        return $skip(judgeJevOut('return', $formal['reasons'], ['formal' => $formal]));
    }
    if (!judgeJevFormalAllowsSemantic($formal)) {
        return $skip(judgeJevOut('unsure', $formal['reasons'] ?: [ta('agents.judge.formal_needs_person')],
            ['formal' => $formal, 'blocked' => true]));
    }

    $task = (string) ($package['task'] ?? ($package['spec']['text'] ?? ''));
    $work = array_key_exists('work', $package)
        ? (string) $package['work']
        : jevWorkText($package['work_file'] ?? null, (string) ($step['result'] ?? ''));
    $built = jevState($task, (string) ($package['input'] ?? ''), $work, $accept, $reject);
    if ($built['tooLong']) {
        return $skip(judgeJevOut('unsure', [ta('agents.judge.too_long')],
            ['formal' => $formal, 'over' => $built['over'], 'role' => $role]));
    }

    $model = jevAccess()['jev_model'];
    $questions = jevAcceptQuestions($accept, $reject);
    $identity = [
        'scope' => 'step', 'run' => (int) ($run['id'] ?? 0), 'step' => (int) ($step['id'] ?? 0),
        'submitted' => (string) ($step['submitted_at'] ?? ''), 'role' => $role,
        'judge' => (string) ($run['judge'] ?? 'human'), 'artifacts' => array_values($package['artifacts'] ?? []),
    ];
    $print = jevFingerprint($built['state'], $questions, (float) $set['high'], (float) $set['low'], $model, $identity);
    return [
        'call' => true, 'scope' => 'step', 'run' => $run, 'step' => $step, 'package' => $package,
        'formal' => $formal, 'role' => $role, 'settings' => $set, 'model' => $model,
        'state' => $built['state'], 'questions' => $questions, 'fingerprint' => $print,
        'accept' => $accept, 'reject' => $reject, 'identity' => $identity,
    ];
}

/** Human policy никогда не применяет formal verdict автоматически. */
function judgeJevHumanFallback(array $formal, string $reason = ''): array
{
    return judgeJevOut('unsure', [$reason !== '' ? $reason : ta('agents.judge.human_accepts')], [
        'formal' => $formal,
        'human' => true,
    ]);
}

/** Jev может дополнять лишь безопасное «нет смысловой проверки», не чинить ошибки входа/формул. */
function judgeJevFormalAllowsSemantic(array $formal): bool
{
    if (($formal['verdict'] ?? '') === 'accept') return true;
    if (($formal['verdict'] ?? '') !== 'unsure') return false;
    $reasons = array_values((array) ($formal['reasons'] ?? []));
    return count($reasons) === 1 && str_starts_with((string) $reasons[0], ta('agents.judge.no_content_head'));
}

/** Вне замка после jevCall: превратить полный ответ в безопасный вердикт. */
function judgeJevResolve(array $plan, array $said): array
{
    $base = judgeJevBase($plan, $said);
    if (!$said['ok']) {
        $why = (string) $said['why'];
        return judgeJevOut('unavailable', [jevWhyText($why)],
            $base + ['why' => $why, 'detail' => $said['detail'] ?? '']);
    }
    $d = jevDecide($said['answers'], $plan['accept'], $plan['reject'],
        (float) $plan['settings']['high'], (float) $plan['settings']['low']);
    $base['lines'] = $d['lines'];
    $base['jev'] = ['verdict' => $d['verdict'], 'reasons' => $d['reasons']];
    /* Советчик советует, а не решает. Кто решает — тот же, кто и без Jev:
       проверяющий прогона. При `judge = formal` сдача идёт своим ходом с советом
       в придачу, иначе включённый советчик останавливал бы автоматический прогон.
       При `judge = human` ждём человека: его Jev не обходит ни в какой роли. */
    if ($plan['role'] === 'advisor') {
        $advice = ta('agents.judge.advice') . judgeJevWord($d['verdict']);
        $formal = (array) ($plan['formal'] ?? []);
        if ((string) ($plan['run']['judge'] ?? 'human') !== 'formal') {
            return judgeJevOut('unsure', [$advice], $base);
        }
        $verdict = (string) ($formal['verdict'] ?? 'unsure');
        $reasons = array_merge((array) ($formal['reasons'] ?? []), [$advice]);
        return judgeJevOut($verdict, $reasons, $base + ['formal' => $formal, 'advisor' => true]);
    }
    if (!empty($plan['settings']['model']) && $plan['settings']['model'] !== $plan['model']) {
        return judgeJevOut('unsure', [ta('agents.judge.thresholds', ['was' => $plan['settings']['model'], 'now' => $plan['model']])], $base);
    }
    return judgeJevOut($d['verdict'], $d['reasons'], $base);
}

function judgeJevBase(array $plan, array $said): array
{
    return [
        'formal' => $plan['formal'], 'role' => $plan['role'],
        'sent' => ['state' => $plan['state'], 'questions' => $plan['questions']],
        'fingerprint' => $plan['fingerprint'], 'model' => $said['model'] ?? $plan['model'],
        'ms' => $said['ms'] ?? 0, 'tokens' => $said['tokens'] ?? 0, 'tries' => $said['tries'] ?? 0,
        'lines' => [],
    ];
}

/**
 * Словесный ромб. Ветка — только при yes/no с уверенностью ≥ confidence.
 * verdict: yes | no | unsure | unavailable; в reasons — подсказка «Jev: да 0,61 — не уверен».
 */
function judgeJevBranch(string $condition, string $data, array $settings): array
{
    $plan = judgeJevBranchPlan($condition, $data, $settings);
    if (!$plan['call']) return $plan['result'];
    return judgeJevBranchResolve($plan,
        jevCall($plan['state'], $plan['questions'], (float) $plan['settings']['deadline']));
}

function judgeJevBranchPlan(string $condition, string $data, array $settings, array $identity = []): array
{
    $set = $settings + ['confidence' => JEV_BRANCH_CONFIDENCE] + JEV_JUDGE_DEFAULTS;
    if (mb_strlen($data, 'UTF-8') > JEV_LIMITS['work'] || mb_strlen($condition, 'UTF-8') > JEV_LIMITS['input']) {
        return ['call' => false, 'result' => judgeJevOut('unsure', [ta('agents.judge.too_much_data')], [])];
    }
    $state = ['condition' => $condition, 'data' => $data];
    $questions = jevBranchQuestion();
    $model = jevAccess()['jev_model'];
    $print = jevFingerprint(['task' => $condition, 'input' => $data], $questions,
        (float) $set['confidence'], 0.0, $model, $identity + ['scope' => 'branch']);
    return ['call' => true, 'scope' => 'branch', 'settings' => $set, 'model' => $model,
            'state' => $state, 'questions' => $questions, 'fingerprint' => $print, 'identity' => $identity];
}

function judgeJevBranchResolve(array $plan, array $said): array
{
    $base = [
        'sent' => ['state' => $plan['state'], 'questions' => $plan['questions']],
        'fingerprint' => $plan['fingerprint'], 'model' => $said['model'] ?? $plan['model'],
        'ms' => $said['ms'] ?? 0, 'tokens' => $said['tokens'] ?? 0, 'tries' => $said['tries'] ?? 0, 'lines' => [],
    ];
    if (!$said['ok']) {
        $why = (string) $said['why'];
        return judgeJevOut('unavailable', [jevWhyText($why)], $base + ['why' => $why]);
    }
    $answer = $said['answers']['branch'];
    $pick = jevBranchDecide($answer, (float) $plan['settings']['confidence']);
    return judgeJevOut($pick['branch'] ?? 'unsure', [$pick['hint']], $base + ['answer' => $answer]);
}

/** Итог всегда одной формы. */
function judgeJevOut(string $verdict, array $reasons, array $details): array
{
    $details += ['lines' => [], 'fingerprint' => null, 'model' => null, 'ms' => 0, 'tokens' => 0, 'tries' => 0];
    $formal = (array) ($details['formal'] ?? []);
    return [
        'verdict' => $verdict, 'reasons' => array_values($reasons), 'details' => $details,
        'checks' => (array) ($formal['checks'] ?? []),
        'detail' => (array) ($formal['detail'] ?? []) + ['jev' => [
            'role' => $details['role'] ?? null, 'lines' => $details['lines'],
            'model' => $details['model'], 'fingerprint' => $details['fingerprint'],
        ]],
    ];
}

function judgeJevWord(string $verdict): string
{
    return ['accept' => ta('agents.judge.w_accept'), 'return' => ta('agents.judge.w_return'), 'unsure' => ta('agents.judge.w_unsure')][$verdict] ?? $verdict;
}
