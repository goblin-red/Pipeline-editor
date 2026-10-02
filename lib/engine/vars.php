<?php
/* Переменные шага: числа из результата и их путь по стрелкам.
   Отдаёт: varsParse(), varsNumber(), varsFormat(), varsText(), varsOf(), varsFromSteps(),
           varsFromMarks(), varsIn(), varsSave().
   Не делает: не проверяет правильность чисел — это checks/expr.php и checks/form.php.

   Правило одно: переменные шага = переменные шагов, давших ему жетоны,
   поверх них — разбор его собственного результата. Ромб и шлюз переносят
   переменные входа без изменений. Два входа слияния с разными значениями
   одной переменной — конфликт: решает человек. */

declare(strict_types=1);

/**
 * Числа из строки результата.
 * Понимаем «a=3 s=6» (точнее всего) и «кругов 6 · сумма 21», «строк 0 · лиц 2».
 * Имя может быть русским — условия на схемах пишут по-русски.
 */
function varsParse(string $said): array
{
    $out  = [];
    $name = '[\p{L}_][\p{L}\p{N}_]*';
    $num  = '-?\d+(?:[.,]\d+)?';
    /* Размер кадра — не переменная: из «кадр 2832×4240» нельзя делать
       «кадр=2832», иначе половина числа теряется и уходит дальше по схеме
       как правда. Поэтому число, стоящее рядом со знаком умножения,
       пропускаем целиком — и слева от него, и справа. Размер с пробелами
       вокруг знака («2832 × 4240», «2832 x 4240», кириллическое «х») убираем
       из разбора сразу: иначе выходило «кадр=2832» и «x=4240». */
    $said = (string) preg_replace("/(?<![\\d.,])$num\\s*[×✕xXхХ*]\\s*$num(?!\\d)/u", ' ', $said);
    $size = '(?!\\d)(?![××xX*]\\s*\\d)';
    $num  = "(?<![\\d××xX*])$num$size";

    // Сначала пары через знак равенства — они точнее.
    if (preg_match_all("/($name)\\s*=\\s*($num)/u", $said, $found, PREG_SET_ORDER)) {
        foreach ($found as $one) $out[$one[1]] = varsNumber($one[2]);
    }
    // Затем «слово число».
    if (preg_match_all("/($name)\\s+($num)/u", $said, $found, PREG_SET_ORDER)) {
        foreach ($found as $one) {
            if (!array_key_exists($one[1], $out)) $out[$one[1]] = varsNumber($one[2]);
        }
    }
    return $out;
}

/** Число из текста: целое остаётся целым, дробь — дробью. Запятая = точка. */
function varsNumber($raw)
{
    $value = is_string($raw) ? (float) str_replace(',', '.', $raw) : (float) $raw;
    return (abs($value - round($value)) < 1e-9 && abs($value) < 1e15) ? (int) round($value) : $value;
}

/** Число для людей и для образца: «6», «2.5». */
function varsFormat($value): string
{
    $value = varsNumber($value);
    if (is_int($value)) return (string) $value;
    return rtrim(rtrim(sprintf('%.6F', $value), '0'), '.');
}

/** «a=5 s=15» — переменные одной строкой. */
function varsText(array $vars): string
{
    $out = [];
    foreach ($vars as $name => $value) $out[] = $name . '=' . varsFormat($value);
    return implode(' ', $out);
}

/** Переменные, записанные в шаге. */
function varsOf(array $step): array
{
    $vars = $step['vars'] ?? null;
    return $vars ? (json_decode((string) $vars, true) ?: []) : [];
}

/**
 * Слить переменные нескольких шагов-источников.
 * Отдаёт ['vars' => имя → значение, 'clash' => имя → [значения]] — clash не пуст, если значения разошлись.
 */
function varsFromSteps(array $steps): array
{
    $vars  = [];
    $clash = [];
    foreach ($steps as $step) {
        foreach (varsOf($step) as $name => $value) {
            if (array_key_exists($name, $vars) && abs((float) $vars[$name] - (float) $value) > 1e-9) {
                $clash[$name] = array_values(array_unique(array_merge($clash[$name] ?? [$vars[$name]], [$value])));
                continue;
            }
            $vars[$name] = $value;
        }
    }
    return ['vars' => $vars, 'clash' => $clash];
}

/** Переменные входа по жетонам: чьи жетоны — того и переменные. */
function varsFromMarks(array $marks): array
{
    $ids = array_values(array_unique(array_map(static fn(array $m) => (int) $m['from_step_id'], $marks)));
    if (!$ids) return ['vars' => [], 'clash' => []];
    $in = implode(',', array_fill(0, count($ids), '?'));
    return varsFromSteps(dbAll("SELECT id, vars FROM run_steps WHERE id IN ($in) ORDER BY id", $ids));
}

/** Переменные входа шага: по жетонам, которые он забрал. */
function varsIn(int $stepId): array
{
    return varsFromMarks(dbAll('SELECT from_step_id FROM run_marks WHERE taken_by_step_id = ? ORDER BY id', [$stepId]));
}

/** Записать переменные шага. */
function varsSave(int $stepId, array $vars): void
{
    dbRun('UPDATE run_steps SET vars = ? WHERE id = ?',
        [$vars ? json_encode($vars, JSON_UNESCAPED_UNICODE) : null, $stepId]);
}
