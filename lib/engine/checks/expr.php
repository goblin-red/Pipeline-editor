<?php
/* Арифметика блока: «a = a + 1; s = s + a» против того, что сдал worker.
   Отдаёт: exprRun(), exprCheck().
   Не делает: не решает, принять ли шаг, — это проверяющий (judges/formal.php).

   Присваивания применяются к переменным входа по порядку: «s = s + a» видит уже новое a.
   Выражение без присваиваний («итог = (a, s)») — не проверка, а пометка для человека. */

declare(strict_types=1);

/**
 * Посчитать арифметику по переменным входа.
 * Отдаёт ['status' => ok|note|missing|error, 'vars' => после присваиваний, 'set' => присвоенные имена, 'why'].
 */
function exprRun(string $expr, array $vars): array
{
    $expr = trim($expr);
    try {
        $program = exprParse($expr, true);
    } catch (ExprVerbal $e) {
        return ['status' => 'note', 'vars' => $vars, 'set' => [], 'why' => ta('agents.checks.expr_note', ['expr' => $expr])];
    }

    $assigns = array_filter($program, static fn(array $node) => $node[0] === 'set');
    if (!$assigns) {
        return ['status' => 'note', 'vars' => $vars, 'set' => [], 'why' => ta('agents.checks.expr_no_assign', ['expr' => $expr])];
    }

    try {
        $done = exprCompute($program, $vars);
    } catch (ExprMissing $e) {
        return ['status' => 'missing', 'vars' => $vars, 'set' => [], 'why' => ta('agents.checks.no_input', ['name' => $e->name, 'cond' => $expr])];
    } catch (ExprFail $e) {
        return ['status' => 'error', 'vars' => $vars, 'set' => [], 'why' => "«{$expr}»: {$e->getMessage()}"];
    }
    return ['status' => 'ok', 'vars' => $done['vars'], 'set' => $done['set'], 'why' => ''];
}

/**
 * Сверить сданное с арифметикой.
 * $in — переменные входа, $got — разбор сданной строки.
 * Отдаёт ['status' => ok|fail|note|missing|error, 'expected' => 'a=5 s=15', 'got' => 'a=5 s=14', 'why'].
 * В why при провале — полная сверка; worker её не показывают (там ожидаемые числа).
 */
function exprCheck(string $expr, array $in, array $got): array
{
    $run = exprRun($expr, $in);
    if ($run['status'] !== 'ok') return $run + ['expected' => '', 'got' => varsText($got)];

    $expected = [];
    $sent     = [];
    $same     = true;
    foreach ($run['set'] as $name) {
        $expected[$name] = $run['vars'][$name];
        if (!array_key_exists($name, $got)) {
            $same = false;
            continue;
        }
        $sent[$name] = $got[$name];
        if (abs((float) $got[$name] - (float) $run['vars'][$name]) > 1e-9) $same = false;
    }

    $want = varsText($expected);
    $said = varsText($sent) ?: ta('agents.checks.without', ['list' => implode(', ', array_keys($expected))]);
    if ($same) {
        return ['status' => 'ok', 'expected' => $want, 'got' => $said, 'why' => ta('agents.checks.math_yes', ['want' => $want])];
    }
    return ['status' => 'fail', 'expected' => $want, 'got' => $said, 'why' => ta('agents.checks.expected_got', ['want' => $want, 'said' => $said])];
}
