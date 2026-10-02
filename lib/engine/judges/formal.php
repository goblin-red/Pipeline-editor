<?php
/* Формальный проверяющий: решает по коду, без людей и без сети.
   Отдаёт: judgeFormal().
   Не делает: не меняет шаг — только выносит решение; не спрашивает Jev.

   Проверки по порядку, первая проваленная — возврат с причиной:
     есть результат → образец (вид, затем значения {a}) → арифметика → файлы и заявки.
   Принять можно, только если прошла СОДЕРЖАТЕЛЬНАЯ проверка — арифметика с
   присваиваниями или образец с переменными. Файлы и образец без переменных — это
   техническая допустимость: непустая картинка не того объекта пройдёт их все.
   Нет содержательной проверки — «не уверен», шаг ждёт человека. Исключение —
   свойство блока trust_form = true.

   Причина возврата для worker — без ожидаемых чисел («арифметика не сходится с входом»):
   иначе он сдаст подсказку, не посчитав. Полная сверка — в detail, её видит leader. */

declare(strict_types=1);

/**
 * Решение по сдаче.
 * Отдаёт ['verdict' => accept|return|unsure, 'reasons' => [для worker и человека],
 *         'checks' => [['name', 'ok', 'say']], 'detail' => [полные сверки]].
 */
function judgeFormal(array $project, array $run, array $step, array $graph): array
{
    $nodeId = (int) $step['element_id'];
    $result = trim((string) ($step['result'] ?? ''));
    $checks = [];
    $detail = [];
    $content = false;

    $out = static function (string $verdict, array $reasons) use (&$checks, &$detail): array {
        return ['verdict' => $verdict, 'reasons' => $reasons, 'checks' => $checks, 'detail' => $detail];
    };

    // 1. Есть результат: строка или файлы.
    $files = (int) dbValue("SELECT COUNT(*) FROM asset_links WHERE step_id = ? AND role = 'result'", [(int) $step['id']]);
    if ($result === '' && $files === 0) return $out('return', [ta('agents.judge.no_result')]);

    // Переменные входа: разошлись на слиянии — решает человек.
    $in = varsIn((int) $step['id']);
    if ($in['clash']) {
        $say = [];
        foreach ($in['clash'] as $name => $values) {
            $say[] = "$name: " . implode(ta('agents.judge.and'), array_map('varsFormat', $values));
        }
        return $out('unsure', [ta('agents.judge.clash', ['list' => implode('; ', $say)])]);
    }
    $got = varsParse($result);

    // 2. Образец ответа: сначала вид, потом значения {a}.
    $answer = trim((string) graphProp($graph, $nodeId, 'answer', ''));
    if ($answer !== '') {
        $fits = answerFits($result, $answer);
        if ($fits === null) return $out('unsure', [ta('agents.judge.sample_bad', ['answer' => $answer])]);
        if ($fits === false) {
            $checks[] = ['name' => ta('agents.judge.name_sample'), 'ok' => false, 'say' => ta('agents.judge.sample_no')];
            return $out('return', [ta('agents.judge.not_sample', ['answer' => $answer])]);
        }
        if (formBound($answer)) {
            $form = formCheck($answer, $result, $in['vars']);
            $detail['form'] = $form;
            if ($form['status'] === 'fail') {
                $checks[] = ['name' => ta('agents.judge.name_sample'), 'ok' => false, 'say' => ta('agents.judge.sample_values')];
                return $out('return', [ta('agents.judge.answer_not_input')]);
            }
            if ($form['status'] !== 'ok') return $out('unsure', [$form['why']]);
            $checks[] = ['name' => ta('agents.judge.name_sample'), 'ok' => true, 'say' => $form['why']];
            $content = true;
        } else {
            $checks[] = ['name' => ta('agents.judge.name_sample'), 'ok' => true, 'say' => ta('agents.judge.sample_kind')];
        }
    }

    // 3. Арифметика.
    $expr = trim((string) graphProp($graph, $nodeId, 'expr', ''));
    if ($expr !== '') {
        $math = exprCheck($expr, $in['vars'], $got);
        $detail['expr'] = $math;
        if ($math['status'] === 'fail') {
            $checks[] = ['name' => ta('agents.judge.name_math'), 'ok' => false, 'say' => ta('agents.judge.math_no')];
            return $out('return', [ta('agents.judge.math_not_input')]);
        }
        if (in_array($math['status'], ['missing', 'error'], true)) return $out('unsure', [$math['why']]);
        if ($math['status'] === 'note') {
            $checks[] = ['name' => ta('agents.judge.name_math'), 'ok' => null, 'say' => ta('agents.judge.math_note')];
        } else {
            $checks[] = ['name' => ta('agents.judge.name_math'), 'ok' => true, 'say' => $math['why']];
            $content = true;
        }
    }

    // 4. Файлы и заявки — в момент приёмки.
    $problem = filesReview($project, $run, $step, $graph);
    if ($problem !== '') {
        $checks[] = ['name' => ta('agents.judge.name_files'), 'ok' => false, 'say' => $problem];
        return $out('return', [$problem]);
    }
    if ($files > 0) $checks[] = ['name' => ta('agents.judge.name_files'), 'ok' => true, 'say' => ta('agents.judge.files_yes')];

    // Решение: без содержательной проверки — не уверен.
    $trust = filter_var(graphProp($graph, $nodeId, 'trust_form', false), FILTER_VALIDATE_BOOLEAN);
    if ($content || $trust) {
        $said = array_map(static fn(array $c) => $c['say'], array_filter($checks, static fn(array $c) => $c['ok']));
        return $out('accept', $said ?: [ta('agents.judge.formal_passed')]);
    }
    return $out('unsure', [ta('agents.judge.no_content', ['vars' => '{' . ta('agents.judge.vars_word') . '}'])]);
}
