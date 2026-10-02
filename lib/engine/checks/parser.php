<?php
/* Разборщик выражений: одна грамматика для арифметики блока (expr) и условия ромба (cond).
   Отдаёт: exprParse(), exprCompute(), exprNames(), классы ExprVerbal, ExprMissing, ExprFail.
   Не делает: не знает про шаги и базу, не зовёт eval — только текст и переменные.

   Грамматика (рекурсивный спуск):
     программа    := оператор (';' оператор)* [';']
     оператор     := имя '=' выражение          — присваивание, только в арифметике
                   | выражение
     выражение    := и ('||' и)*
     и            := сравнение ('&&' сравнение)*
     сравнение    := сумма [('>' | '>=' | '<' | '<=' | '==' | '!=') сумма]
     сумма        := произведение (('+' | '-') произведение)*
     произведение := знак (('*' | '/') знак)*
     знак         := '-' знак | '(' выражение ')' | число | имя

   Имена — буквы любого языка, цифры и «_»: условия на схемах пишут по-русски.
   В условии одиночное «=» значит «равно» — так пишут люди. Знаки ≤ ≥ ≠ понимаются. */

declare(strict_types=1);

/** Текст — не выражение: условие словами или пометка для человека. */
class ExprVerbal extends RuntimeException {}

/** В выражении есть имя, которого нет среди переменных. */
class ExprMissing extends RuntimeException
{
    public function __construct(public string $name)
    {
        parent::__construct(ta('agents.checks.no_variable', ['name' => $name]));
    }
}

/** Выражение разобрано, но посчитать его нельзя: деление на ноль, число вместо да/нет. */
class ExprFail extends RuntimeException {}

/* ── Разбор ───────────────────────────────────────────────────── */

/**
 * Разобрать текст в список операторов.
 * $assign = true — арифметика: «a = a + 1» присваивает; false — условие: «a = 5» сравнивает.
 * Не разобралось — ExprVerbal.
 */
function exprParse(string $text, bool $assign): array
{
    $tokens = exprTokens($text);
    if (!$tokens) throw new ExprVerbal(ta('agents.checks.empty'));

    $at = 0;
    $program = [];
    while ($at < count($tokens)) {
        $program[] = exprStatement($tokens, $at, $assign);
        if ($at < count($tokens)) {
            if ($tokens[$at] !== ';') {
                $extra = is_array($tokens[$at]) ? (string) $tokens[$at][1] : $tokens[$at];
                throw new ExprVerbal(ta('agents.checks.extra', ['extra' => $extra]));
            }
            $at++;
        }
    }
    return $program;
}

/** Текст → слова разборщика: числа, имена, знаки. Непонятный знак — ExprVerbal. */
function exprTokens(string $text): array
{
    $text = strtr($text, ['≤' => '<=', '≥' => '>=', '≠' => '!=']);
    $out = [];
    $at = 0;
    $len = strlen($text);

    while ($at < $len) {
        if (preg_match('/\G\s+/u', $text, $m, 0, $at)) { $at += strlen($m[0]); continue; }

        if (preg_match('/\G\d+(?:[.,]\d+)?/u', $text, $m, 0, $at)) {
            $out[] = ['num', (float) str_replace(',', '.', $m[0])];
            $at += strlen($m[0]);
            continue;
        }
        if (preg_match('/\G[\p{L}_][\p{L}\p{N}_]*/u', $text, $m, 0, $at)) {
            $out[] = ['name', $m[0]];
            $at += strlen($m[0]);
            continue;
        }
        if (preg_match('/\G(>=|<=|==|!=|&&|\|\||[><=+\-*\/();])/u', $text, $m, 0, $at)) {
            $out[] = $m[0];
            $at += strlen($m[0]);
            continue;
        }
        throw new ExprVerbal(ta('agents.checks.bad_sign'));
    }
    return $out;
}

function exprStatement(array $t, int &$at, bool $assign): array
{
    // Присваивание: имя, затем одиночное «=».
    if ($assign && is_array($t[$at] ?? null) && $t[$at][0] === 'name' && ($t[$at + 1] ?? null) === '=') {
        $name = $t[$at][1];
        $at += 2;
        return ['set', $name, exprOr($t, $at, $assign)];
    }
    return exprOr($t, $at, $assign);
}

function exprOr(array $t, int &$at, bool $assign): array
{
    $left = exprAnd($t, $at, $assign);
    while (($t[$at] ?? null) === '||') {
        $at++;
        $left = ['or', $left, exprAnd($t, $at, $assign)];
    }
    return $left;
}

function exprAnd(array $t, int &$at, bool $assign): array
{
    $left = exprCompare($t, $at, $assign);
    while (($t[$at] ?? null) === '&&') {
        $at++;
        $left = ['and', $left, exprCompare($t, $at, $assign)];
    }
    return $left;
}

function exprCompare(array $t, int &$at, bool $assign): array
{
    $left = exprSum($t, $at);
    $sign = $t[$at] ?? null;
    // В условии «=» — это «равно»; в арифметике одиночное «=» здесь — ошибка записи.
    if ($sign === '=' && !$assign) $sign = '==';
    if (in_array($sign, ['>', '>=', '<', '<=', '==', '!='], true)) {
        $at++;
        return ['cmp', $sign, $left, exprSum($t, $at)];
    }
    return $left;
}

function exprSum(array $t, int &$at): array
{
    $left = exprProduct($t, $at);
    while (in_array($t[$at] ?? null, ['+', '-'], true)) {
        $sign = $t[$at++];
        $left = ['math', $sign, $left, exprProduct($t, $at)];
    }
    return $left;
}

function exprProduct(array $t, int &$at): array
{
    $left = exprSign($t, $at);
    while (in_array($t[$at] ?? null, ['*', '/'], true)) {
        $sign = $t[$at++];
        $left = ['math', $sign, $left, exprSign($t, $at)];
    }
    return $left;
}

function exprSign(array $t, int &$at): array
{
    $token = $t[$at] ?? null;
    if ($token === null) throw new ExprVerbal(ta('agents.checks.cut_off'));

    if ($token === '-') {
        $at++;
        return ['neg', exprSign($t, $at)];
    }
    if ($token === '(') {
        $at++;
        $inner = exprOr($t, $at, false);
        if (($t[$at] ?? null) !== ')') throw new ExprVerbal(ta('agents.checks.no_paren'));
        $at++;
        return $inner;
    }
    if (is_array($token)) {
        $at++;
        return $token[0] === 'num' ? ['num', $token[1]] : ['var', $token[1]];
    }
    throw new ExprVerbal(ta('agents.checks.want_number_or_name'));
}

/* ── Счёт ─────────────────────────────────────────────────────── */

/**
 * Посчитать программу. Присваивания идут по порядку: «a = a + 1; s = s + a» — s видит новое a.
 * Отдаёт ['vars' => переменные после присваиваний, 'set' => присвоенные имена, 'value' => последнее значение].
 */
function exprCompute(array $program, array $vars): array
{
    $set = [];
    $value = null;
    foreach ($program as $node) {
        if ($node[0] === 'set') {
            $vars[$node[1]] = exprNumber(exprValue($node[2], $vars));
            if (!in_array($node[1], $set, true)) $set[] = $node[1];
            $value = $vars[$node[1]];
            continue;
        }
        $value = exprValue($node, $vars);
    }
    return ['vars' => $vars, 'set' => $set, 'value' => $value];
}

/** Значение узла: число (float) или да/нет (bool). */
function exprValue(array $node, array $vars)
{
    switch ($node[0]) {
        case 'num':
            return (float) $node[1];
        case 'var':
            if (!array_key_exists($node[1], $vars)) throw new ExprMissing($node[1]);
            return (float) $vars[$node[1]];
        case 'neg':
            return -exprNumber(exprValue($node[1], $vars));
        case 'math':
            $a = exprNumber(exprValue($node[2], $vars));
            $b = exprNumber(exprValue($node[3], $vars));
            if ($node[1] === '/' && abs($b) < 1e-12) throw new ExprFail(ta('agents.checks.div_zero'));
            return match ($node[1]) { '+' => $a + $b, '-' => $a - $b, '*' => $a * $b, '/' => $a / $b };
        case 'cmp':
            $a = exprNumber(exprValue($node[2], $vars));
            $b = exprNumber(exprValue($node[3], $vars));
            return match ($node[1]) {
                '>'  => $a > $b + 1e-9,
                '<'  => $a < $b - 1e-9,
                '>=' => $a >= $b - 1e-9,
                '<=' => $a <= $b + 1e-9,
                '==' => abs($a - $b) <= 1e-9,
                '!=' => abs($a - $b) > 1e-9,
            };
        case 'and':
            return exprBool(exprValue($node[1], $vars)) && exprBool(exprValue($node[2], $vars));
        case 'or':
            return exprBool(exprValue($node[1], $vars)) || exprBool(exprValue($node[2], $vars));
    }
    throw new ExprFail(ta('agents.checks.bad_node'));
}

function exprNumber($v): float
{
    if (is_bool($v)) throw new ExprFail(ta('agents.checks.want_number'));
    return (float) $v;
}

function exprBool($v): bool
{
    if (!is_bool($v)) throw new ExprFail(ta('agents.checks.want_bool'));
    return $v;
}

/** Имена, которые читает программа (без присваиваемых впервые) — для пояснений «a=2: …». */
function exprNames(array $program): array
{
    $out = [];
    $walk = static function (array $node) use (&$walk, &$out): void {
        if ($node[0] === 'var' && !in_array($node[1], $out, true)) $out[] = $node[1];
        foreach ($node as $part) if (is_array($part)) $walk($part);
    };
    foreach ($program as $node) $walk($node);
    return $out;
}
