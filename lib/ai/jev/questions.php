<?php
/* Вопросы к Jev.
   Что делает: строит вопросы Noul по строкам критериев и вопрос Choice для словесного ромба.
   Что отдаёт: jevLines(), jevAcceptQuestions(), jevBranchQuestion().
   Чего не делает: не ходит в сеть, не решает, не читает базу.

   Вопросы — по-английски: это основной язык модели. Строки критериев
   остаются в состоянии как есть, по-русски; вопрос ссылается на них по пути. */

declare(strict_types=1);

/** Строки критериев: обрезать пробелы, выбросить пустые, пронумеровать заново. */
function jevLines(array $lines): array
{
    $out = [];
    foreach ($lines as $line) {
        $line = trim((string) $line);
        if ($line !== '') $out[] = $line;
    }
    return $out;
}

/**
 * По вопросу Noul на каждую строку: a0, a1… для accept, r0, r1… для reject.
 * Направление хранит код: accept обязан получить «да», reject — «нет».
 */
function jevAcceptQuestions(array $accept, array $reject): array
{
    $questions = [];

    foreach (jevLines($accept) as $i => $_) {
        $questions["a{$i}"] = [
            'type'         => 'noul',
            'instructions' =>
                "`accept[{$i}]` is a requirement written in Russian. "
                . '`task` is the assignment and `work` is the submitted work. '
                . "Is the statement in `accept[{$i}]` true of `work`?",
        ];
    }

    foreach (jevLines($reject) as $i => $_) {
        $questions["r{$i}"] = [
            'type'         => 'noul',
            'instructions' =>
                "`reject[{$i}]` describes, in Russian, something the work must not contain. "
                . "Does `work` contain what `reject[{$i}]` describes?",
        ];
    }

    return $questions;
}

/**
 * Словесный ромб: да, нет или «по этим данным не решить».
 * Состояние для него: `condition` — условие ромба, `data` — принятые результаты.
 */
function jevBranchQuestion(): array
{
    return [
        'branch' => [
            'type'         => 'choice',
            'instructions' =>
                '`condition` is a rule written in Russian on a decision point of a workflow. '
                . '`data` holds the results available at this point. '
                . 'Does `condition` hold for `data`?',
            'criteria'     => [
                'yes'     => '`condition` is true for `data`',
                'no'      => '`condition` is false for `data`',
                'unclear' => '`data` does not contain enough to decide whether `condition` holds',
            ],
        ],
    ];
}
