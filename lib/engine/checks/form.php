<?php
/* Образец ответа: «a=<число> s=<число>», «кругов {a} · сумма {s}»; по-английски — <number>, <file>….
   Отдаёт: answerFits(), answerSample(), formCheck(), formBound(), formHole().
   Не делает: не бросает отказ — решает вызывающий (checkSubmit при сдаче, проверяющий при приёмке).

   Две проверки одним образцом:
     вид       — при сдаче: <число>, <слово>, <файл>, <текст> и {a} как «число на этом месте»;
     значения  — при приёмке: {a} = значение переменной входа a. Перестановка чисел видна. */

declare(strict_types=1);

/** Подстановки вида: что может стоять на месте дырки. Имена — по-русски и по-английски, смысл один. */
// <файл> — то же, что <слово>: путь без пробелов; имя подсказывает worker, что писать путь.
const FORM_HOLES = ['число' => '-?\\d+(?:[.,]\\d+)?', 'слово' => '\\S+', 'файл' => '\\S+', 'текст' => '.+',
                    'number' => '-?\\d+(?:[.,]\\d+)?', 'word' => '\\S+', 'file' => '\\S+', 'text' => '.+'];

/** Похоже на дырку: слово в угловых скобках. Дырка ли это — решает formHole(). */
const FORM_HOLE_RX = '<\\s*\\p{L}+\\s*>';

/**
 * Дырка так, как её пишут люди: <число>, <Number>, < ЧИСЛО > — без учёта регистра и пробелов
 * внутри. Отдаёт имя маленькими буквами (ключ FORM_HOLES) или null — это обычный текст образца.
 */
function formHole(string $bit): ?string
{
    if (!preg_match('/^<\\s*(\\p{L}+)\\s*>$/u', $bit, $m)) return null;
    $name = mb_strtolower($m[1]);
    return isset(FORM_HOLES[$name]) ? $name : null;
}

/**
 * Ответ по образцу? Проверяется только вид.
 *
 * Образец пишется по-человечески: `a=<число> s=<число>`, `кругов <число> · сумма <число>`.
 * Понимаем подстановки <число>, <слово>, <файл>, <текст>; {имя} на этом шаге — тоже число.
 * Остальное сверяется дословно, только пробелы считаются за любой пробельный промежуток.
 */
function answerFits(string $result, string $pattern): ?bool
{
    $got = @preg_match('/^' . formRegex($pattern, null)['rx'] . '$/ui', trim($result));
    if ($got === false) return null;   // образец битый: сказать об этом прямо
    return $got === 1;
}

/**
 * Образец с подставленными заглушками: «a=<число> s=<число>» → «a=1 s=2».
 *
 * Нужен только чтобы показать worker форму строки при отказе. Настоящие
 * значения переменных сюда не подставляем: ответ подсказывать нельзя.
 */
function answerSample(string $pattern): string
{
    $n = 0;
    $out = preg_replace_callback('/' . FORM_HOLE_RX . '/u', static function (array $m) use (&$n): string {
        $hole = formHole($m[0]);
        if ($hole === null) return $m[0];
        $n++;
        if ($hole === 'число' || $hole === 'number') return (string) $n;
        if ($hole === 'файл' || $hole === 'file') return 'out/' . ta('agents.form.file') . $n;
        return ta('agents.form.word') . $n;
    }, $pattern);

    // {имя} — тоже число, но какое именно, worker должен посчитать сам.
    return (string) preg_replace('/\{[\p{L}_][\p{L}\p{N}_]*\}/u', ta('agents.form.your_number'), (string) $out);
}

/** Есть ли в образце переменные {имя}. */
function formBound(string $pattern): bool
{
    return (bool) preg_match('/\{[\p{L}_][\p{L}\p{N}_]*\}/u', $pattern);
}

/**
 * Сверить значения: {a} должно быть ровно значением переменной входа a.
 * Отдаёт ['status' => ok|fail|missing|bad, 'bound' => есть ли {имя}, 'expected' => 'кругов 6 · сумма 21', 'why'].
 */
function formCheck(string $pattern, string $result, array $vars): array
{
    $bound = formBound($pattern);
    $built = formRegex($pattern, $vars);
    if ($built['missing']) {
        return ['status' => 'missing', 'bound' => $bound, 'expected' => '',
                'why' => ta('agents.checks.sample_no_input', ['list' => implode(', ', $built['missing']), 'pattern' => $pattern])];
    }

    $got = @preg_match('/^' . $built['rx'] . '$/ui', trim($result));
    if ($got === false) {
        return ['status' => 'bad', 'bound' => $bound, 'expected' => '', 'why' => ta('agents.checks.sample_bad', ['pattern' => $pattern])];
    }
    $expected = formExpected($pattern, $vars);
    if ($got === 1) {
        return ['status' => 'ok', 'bound' => $bound, 'expected' => $expected, 'why' => ta('agents.checks.sample_yes', ['expected' => $expected])];
    }
    return ['status' => 'fail', 'bound' => $bound, 'expected' => $expected,
            'why' => ta('agents.checks.sample_expected', ['expected' => $expected, 'got' => trim($result)])];
}

/**
 * Регулярка из образца. $vars = null — только вид ({имя} как число);
 * иначе {имя} — точное значение переменной. Отдаёт ['rx' => …, 'missing' => [имена без значения]].
 */
function formRegex(string $pattern, ?array $vars): array
{
    $rx = '';
    $missing = [];
    // Дырки — тем же правилом, что в answerSample: регистр и пробелы внутри не важны.
    $bits = preg_split('/(' . FORM_HOLE_RX . '|\{[\p{L}_][\p{L}\p{N}_]*\})/u', $pattern, -1, PREG_SPLIT_DELIM_CAPTURE);

    foreach ($bits as $bit) {
        if (($hole = formHole($bit)) !== null) { $rx .= '(' . FORM_HOLES[$hole] . ')'; continue; }

        if (preg_match('/^\{([\p{L}_][\p{L}\p{N}_]*)\}$/u', $bit, $m)) {
            if ($vars === null) { $rx .= '(' . FORM_HOLES['число'] . ')'; continue; }
            if (!array_key_exists($m[1], $vars)) { $missing[] = $m[1]; continue; }
            // Дробь можно написать и с точкой, и с запятой.
            $rx .= '(' . str_replace('\\.', '[.,]', preg_quote(varsFormat($vars[$m[1]]), '/')) . ')';
            continue;
        }

        // Пробелы в образце — это «сколько угодно пробелов» в ответе.
        // preg_quote обязательно с разделителем: иначе образец со слешем
        // («out/out.txt записан») ломал саму регулярку и не совпадал никогда.
        $rx .= implode('\\s+', array_map(
            static fn(string $one) => preg_quote($one, '/'),
            preg_split('/\\s+/u', $bit)
        ));
    }
    return ['rx' => $rx, 'missing' => $missing];
}

/** Образец с подставленными значениями: «кругов 6 · сумма 21». */
function formExpected(string $pattern, array $vars): string
{
    return (string) preg_replace_callback('/\{([\p{L}_][\p{L}\p{N}_]*)\}/u',
        static fn(array $m) => array_key_exists($m[1], $vars) ? varsFormat($vars[$m[1]]) : $m[0], $pattern);
}
