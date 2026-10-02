<?php
/* Содержимое таблицы: одна понятная форма и одна проверка на неё.
   Отдаёт: TABLE_PROP, tableNormalize().
   Не делает: не рисует — рисует холст (public/src/canvas/table.js).

   Форма нарочно простая, чтобы её читал человек, а не только код:

     {
       "head": ["Отвечает за", "Не делает"],           // шапка: подсвечена
       "rows": [
         { "name": "Гоблин", "cells": ["схема", "не запускает CLI"] },
         { "name": "Агент",  "cells": ["работа", "не принимает сам"] }
       ]
     }

   `head` — подписи столбцов без первого: первый столбец занят именами строк.
   `name` — имя строки, `cells` — её клетки по порядку столбцов.
   Нет ни объединений, ни вложенности: таблица здесь — способ показать ряды,
   а не свёрстанный документ. */

declare(strict_types=1);

/** Имя допа, в котором живёт содержимое. Одно на весь код. */
const TABLE_PROP = 'table';

const TABLE_MAX_COLS = 12;
const TABLE_MAX_ROWS = 200;
const TABLE_MAX_CELL = 2000;

/**
 * Привести что пришло к этой форме.
 * Клетки добиваются до числа столбцов и обрезаются по нему: в таблице
 * не бывает рваных рядов, иначе её нельзя ни показать, ни прочитать.
 */
function tableNormalize($value): array
{
    if (is_string($value)) $value = json_decode($value, true);
    if (!is_array($value)) throw new ApiError(t('server.table.object'));

    // Прежняя форма из v1: columns + rows[].label.
    $head = $value['head'] ?? $value['columns'] ?? [];
    if (!is_array($head)) throw new ApiError(t('server.table.head'));

    $head = array_values(array_map(
        static fn($one) => mb_substr(trim((string) $one), 0, 200),
        array_slice($head, 0, TABLE_MAX_COLS)
    ));
    if (!$head) $head = [t('server.table.column', ['n' => 1])];
    $width = count($head);

    $rows = [];
    foreach (array_slice((array) ($value['rows'] ?? []), 0, TABLE_MAX_ROWS) as $row) {
        if (!is_array($row)) continue;
        $name = mb_substr(trim((string) ($row['name'] ?? $row['label'] ?? '')), 0, 200);
        $cells = array_values(array_map(
            static fn($cell) => mb_substr((string) (is_scalar($cell) ? $cell : ''), 0, TABLE_MAX_CELL),
            array_slice((array) ($row['cells'] ?? []), 0, $width)
        ));
        while (count($cells) < $width) $cells[] = '';
        $rows[] = ['name' => $name, 'cells' => $cells];
    }
    if (!$rows) $rows = [['name' => '', 'cells' => array_fill(0, $width, '')]];

    return ['head' => $head, 'rows' => $rows];
}
