<?php
/* Условие ромба: «a > 5», «a >= 3 && s > 10», «(a == 1 || b == 1) && c == 1».
   Отдаёт: condEval(), condSpaces().
   Не делает: не выбирает ветку сам и не спрашивает Jev — только считает.

   Статусы:
     ok      — посчитано, value = да/нет;
     verbal  — это не выражение, а слова («клиент доволен ответом»): решает человек или Jev;
     missing — в условии есть имя, которого нет во входе: «нет входа», а не «нет»;
     error   — посчитать нельзя (деление на ноль, число вместо да/нет) — чинить схему.

   Словесные правила по тексту результата входа остались от прежнего круга leader —
   ими пользуются схемы: «содержит лицо есть», «начинается …», «кончается есть». */

declare(strict_types=1);

/**
 * Посчитать условие по переменным входа. $text — строка результата шага, давшего жетон
 * (нужна только словесным правилам).
 * Отдаёт ['status' => ok|verbal|missing|error, 'value' => ?bool, 'why' => '…'].
 */
function condEval(string $cond, array $vars, string $text = ''): array
{
    $cond = trim(condSpaces($cond));
    $cond = rtrim($cond, " \t?");        // «a > 5 ?» — так пишут в названии ромба
    if ($cond === '') return ['status' => 'verbal', 'value' => null, 'why' => ta('agents.checks.no_cond')];

    // Словесное правило по тексту результата.
    // Слова правила — по-русски или по-английски: contains, starts with, ends with.
    if (preg_match('/^(содержит|начинается|кончается|contains|starts\s+with|ends\s+with)\s+(.+)$/ui', $cond, $found)) {
        $rule = ['содержит' => 'contains', 'contains' => 'contains', 'начинается' => 'starts', 'starts' => 'starts', 'кончается' => 'ends', 'ends' => 'ends'][strtok(mb_strtolower($found[1]), " \t")] ?? mb_strtolower($found[1]);
        return condWords($rule, $found[2], $text, $cond);
    }

    try {
        $program = exprParse($cond, false);
    } catch (ExprVerbal $e) {
        return ['status' => 'verbal', 'value' => null, 'why' => ta('agents.checks.cond_words', ['cond' => $cond])];
    }
    if (count($program) !== 1) {
        return ['status' => 'verbal', 'value' => null, 'why' => ta('agents.checks.cond_words', ['cond' => $cond])];
    }

    // Что видели: «a=2: «a > 5» неверно».
    $seen = [];
    foreach (exprNames($program) as $name) {
        if (array_key_exists($name, $vars)) $seen[] = $name . '=' . varsFormat($vars[$name]);
    }
    $head = $seen ? implode(' ', $seen) . ': ' : '';

    try {
        $value = exprCompute($program, $vars)['value'];
    } catch (ExprMissing $e) {
        return ['status' => 'missing', 'value' => null, 'why' => ta('agents.checks.no_input', ['name' => $e->name, 'cond' => $cond])];
    } catch (ExprFail $e) {
        return ['status' => 'error', 'value' => null, 'why' => "«{$cond}»: {$e->getMessage()}"];
    }
    if (!is_bool($value)) {
        return ['status' => 'error', 'value' => null, 'why' => ta('agents.checks.gives_number', ['cond' => $cond])];
    }
    return ['status' => 'ok', 'value' => $value, 'why' => $head . "«{$cond}» " . ($value ? ta('agents.checks.right') : ta('agents.checks.wrong'))];
}

/**
 * Пробелы любого вида — неразрывный, узкий, табуляция, перевод строки, пробел нулевой
 * ширины — обычным пробелом, знак в знак. Иначе «starts<NBSP>with» разбиралось бы наполовину.
 */
function condSpaces(string $text): string
{
    return (string) preg_replace('/[\p{Z}\s\x{200B}\x{FEFF}]/u', ' ', $text);
}

/** Словесное правило: содержит / начинается / кончается — по тексту результата входа. */
function condWords(string $rule, string $what, string $text, string $cond): array
{
    $what = mb_strtolower(trim($what, " \t«»\"'"));
    $said = mb_strtolower(trim(condSpaces($text)));
    if ($what === '') return ['status' => 'verbal', 'value' => null, 'why' => ta('agents.checks.cond_words', ['cond' => $cond])];
    if ($said === '') return ['status' => 'missing', 'value' => null, 'why' => ta('agents.checks.no_text', ['cond' => $cond])];

    $value = match ($rule) {
        'contains' => str_contains($said, $what),
        'starts'   => str_starts_with($said, $what),
        'ends'     => str_ends_with($said, $what),
        default    => null,             // правило не узнали — это слова, а не формула
    };
    if ($value === null) return ['status' => 'verbal', 'value' => null, 'why' => ta('agents.checks.cond_words', ['cond' => $cond])];
    return ['status' => 'ok', 'value' => $value, 'why' => "«{$text}»: «{$cond}» " . ($value ? ta('agents.checks.right') : ta('agents.checks.wrong'))];
}
