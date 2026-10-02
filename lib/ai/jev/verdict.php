<?php
/* Решение по ответам Jev.
   Что делает: раскладывает вероятности по строкам критериев и выносит итог.
   Что отдаёт: jevDecide() — accept | return | unsure с причинами;
               jevBranchDecide() — ветка словесного ромба или подсказка человеку.
   Чего не делает: не ходит в сеть, не пишет в базу; «unavailable» решает
                   тот, кто звал jevCall(): сюда приходят только полные ответы. */

declare(strict_types=1);

require_once __DIR__ . '/../../core/i18n.php';   // проверки грузят файл отдельно от boot.php: ta() нужен и им

require_once __DIR__ . '/questions.php';

/**
 * Итог по критериям.
 * $answers — ответы jevCall(): ['a0' => ['type' => 'noul', 'noul' => 0.97], …]
 *            (допускается и просто число).
 * Отдаёт: ['verdict' => 'accept'|'return'|'unsure', 'reasons' => [...], 'lines' => [...]].
 */
function jevDecide(array $answers, array $accept, array $reject, float $high, float $low): array
{
    if (!($low >= 0.0 && $low < $high && $high <= 1.0)) {
        throw new InvalidArgumentException(ta('agents.ai.jev_thresholds'));
    }

    $lines = [];
    foreach (jevLines($accept) as $i => $text) {
        $lines[] = jevLine("a{$i}", 'accept', $text, $answers, $high, $low);
    }
    foreach (jevLines($reject) as $i => $text) {
        $lines[] = jevLine("r{$i}", 'reject', $text, $answers, $high, $low);
    }

    // Критериев нет — судить не о чем; «принять на всякий случай» нельзя.
    if (!$lines) {
        return ['verdict' => 'unsure', 'reasons' => [ta('agents.ai.jev_no_criteria')], 'lines' => []];
    }

    $fails  = [];
    $doubts = [];
    foreach ($lines as $line) {
        if ($line['mark'] === 'fail') {
            $fails[] = ($line['kind'] === 'accept' ? ta('agents.ai.jev_not_done') : ta('agents.ai.jev_forbidden')) . $line['text'];
        } elseif ($line['mark'] === 'doubt') {
            $doubts[] = ta('agents.ai.jev_doubt') . $line['text'];
        }
    }

    if ($fails)  return ['verdict' => 'return', 'reasons' => $fails,  'lines' => $lines];
    if ($doubts) return ['verdict' => 'unsure', 'reasons' => $doubts, 'lines' => $lines];
    return ['verdict' => 'accept', 'reasons' => [], 'lines' => $lines];
}

/** Одна строка: ok — как надо, fail — провалено, doubt — сомнение. */
function jevLine(string $id, string $kind, string $text, array $answers, float $high, float $low): array
{
    $p = jevProbability($answers[$id] ?? null);

    if ($p === null) {
        $mark = 'doubt';                                 // нет ответа — не ноль, а сомнение
    } elseif ($kind === 'accept') {
        $mark = $p >= $high ? 'ok' : ($p <= $low ? 'fail' : 'doubt');
    } else {
        $mark = $p <= $low ? 'ok' : ($p >= $high ? 'fail' : 'doubt');
    }

    return ['id' => $id, 'kind' => $kind, 'text' => $text, 'p' => $p, 'mark' => $mark];
}

/** Вероятность «да» из ответа Noul; не число или вне 0…1 — null. */
function jevProbability($answer): ?float
{
    $p = is_array($answer) ? ($answer['noul'] ?? null) : $answer;
    if (!is_int($p) && !is_float($p)) return null;

    $p = (float) $p;
    return (is_finite($p) && $p >= 0.0 && $p <= 1.0) ? $p : null;
}

/**
 * Словесный ромб: ветка при yes/no с уверенностью ≥ $min, иначе ждём человека.
 * Отдаёт: ['branch' => 'yes'|'no'|null, 'hint' => 'Jev: да 0,61 — не уверен'].
 */
function jevBranchDecide(array $answer, float $min = 0.85): array
{
    $choice = (string) ($answer['choice'] ?? '');
    $conf   = $answer['confidence'] ?? null;
    $conf   = (is_int($conf) || is_float($conf)) && is_finite((float) $conf) ? (float) $conf : 0.0;

    $word = ['yes' => ta('agents.advance.yes'), 'no' => ta('agents.advance.no'), 'unclear' => ta('agents.ai.jev_cannot')][$choice] ?? '?';
    $p    = $answer['probabilities'][$choice] ?? $conf;
    $num  = str_replace('.', ',', sprintf('%.2f', (float) $p));

    $sure = in_array($choice, ['yes', 'no'], true) && $conf >= $min;

    return [
        'branch' => $sure ? $choice : null,
        'hint'   => "Jev: {$word} {$num}" . ($sure ? '' : ta('agents.ai.jev_unsure_suffix')),
    ];
}
