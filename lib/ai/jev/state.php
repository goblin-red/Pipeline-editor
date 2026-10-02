<?php
/* Состояние для Jev и его отпечаток.
   Что делает: собирает ровно то, что уходит наружу (ТЗ, вход, работа, критерии),
               проверяет пределы длины, считает отпечаток запроса.
   Что отдаёт: jevState(), jevWorkText(), jevFingerprint(), константу JEV_LIMITS.
   Чего не делает: не обрезает текст, не ходит в сеть, не решает «не уверен» —
                   только ставит признак tooLong; решение примет движок. */

declare(strict_types=1);

require_once __DIR__ . '/questions.php';

/** Пределы в знаках. Длиннее — автоматически не проверяем. */
const JEV_LIMITS = ['task' => 6000, 'input' => 3000, 'work' => 8000];

/** Расширения, которые считаем текстом. */
const JEV_TEXT_EXT = ['txt', 'md', 'csv', 'json', 'html', 'htm', 'xml', 'yml', 'yaml'];

/**
 * Состояние запроса.
 * Отдаёт: ['state' => [task, input, work, accept, reject], 'tooLong' => bool, 'over' => [поле => длина]].
 */
function jevState(string $task, string $input, string $work, array $accept, array $reject): array
{
    $state = [
        'task'   => $task,
        'input'  => $input,
        'work'   => $work,
        'accept' => jevLines($accept),
        'reject' => jevLines($reject),
    ];

    // Длину считаем в знаках, а не в байтах: русский текст вдвое «тяжелее».
    $over = [];
    foreach (JEV_LIMITS as $field => $max) {
        $len = mb_strlen($state[$field], 'UTF-8');
        if ($len > $max) $over[$field] = $len;
    }

    return ['state' => $state, 'tooLong' => $over !== [], 'over' => $over];
}

/**
 * Что проверяется: содержимое сданного текстового файла, иначе строка результата.
 * Файл не текстовый, не читается или не UTF-8 — берётся строка результата.
 * Читаем не больше, чем нужно, чтобы понять «слишком длинно».
 */
function jevWorkText(?string $path, string $result): string
{
    if ($path === null || $path === '' || !is_file($path) || !is_readable($path)) return $result;

    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    if (!in_array($ext, JEV_TEXT_EXT, true)) return $result;

    // 4 байта на знак — худший случай UTF-8; +1 знак, чтобы превышение было видно.
    $cap  = (JEV_LIMITS['work'] + 1) * 4;
    $text = @file_get_contents($path, false, null, 0, $cap);
    if ($text === false) return $result;

    // Обрыв посреди буквы на границе чтения — отрезаем хвост до целого знака.
    if (strlen($text) === $cap) $text = mb_strcut($text, 0, $cap - 3, 'UTF-8');
    if (!mb_check_encoding($text, 'UTF-8')) return $result;

    return $text;
}

/**
 * Отпечаток: sha256 от всего, что влияет на ответ и решение.
 * Совпал отпечаток — ответ Jev относится ровно к этим данным.
 */
function jevFingerprint(array $state, array $questions, float $high, float $low, string $model,
                        array $identity = []): string
{
    $parts = [
        'task'      => (string) ($state['task'] ?? ''),
        'input'     => (string) ($state['input'] ?? ''),
        'work'      => (string) ($state['work'] ?? ''),
        'accept'    => array_values($state['accept'] ?? []),
        'reject'    => array_values($state['reject'] ?? []),
        'questions' => $questions,
        'high'      => sprintf('%.4f', $high),
        'low'       => sprintf('%.4f', $low),
        'model'     => $model,
        // Только локальная привязка ответа: наружу identity не отправляется.
        'identity'  => $identity,
    ];

    $json = json_encode($parts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    return hash('sha256', $json);
}
