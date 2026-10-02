/* Таблица на холсте: шапка подсвечена, ряды чередуются еле заметно.
   Отдаёт: TABLE_PROP, drawTable(), tableOf(), emptyTable(), cellsOf(), putCell().
   Не делает: не правит содержимое само — двойной щелчок по клетке ведёт
   в edit/inplace.js, сетка полей — в panel/table-edit.js.

   Содержимое лежит в допе `table` той же формой, что держит сервер
   (lib/elements/table.php):
     { head: ["столбец", …], rows: [{ name: "строка", cells: ["…", …] }] } */

import { t } from 'goblin/core/i18n.js';

/** Одно имя допа на весь клиент — зеркало TABLE_PROP на сервере. */
export const TABLE_PROP = 'table';

export function emptyTable() {
  return {
    head: [t('editor.table.column', { n: 1 }), t('editor.table.column', { n: 2 })],
    rows: [
      { name: t('editor.table.row', { n: 1 }), cells: ['', ''] },
      { name: t('editor.table.row', { n: 2 }), cells: ['', ''] },
    ],
  };
}

/** Содержимое элемента, приведённое к форме. Ничего нет — пустая таблица. */
export function tableOf(element) {
  const raw = element?.props?.[TABLE_PROP];
  const value = typeof raw === 'string' ? safeParse(raw) : raw;
  if (!value || !Array.isArray(value.head)) return emptyTable();

  const head = value.head.map((one) => String(one ?? ''));
  const width = head.length || 1;
  const rows = (Array.isArray(value.rows) ? value.rows : []).map((row) => {
    const cells = (Array.isArray(row?.cells) ? row.cells : []).slice(0, width).map((one) => String(one ?? ''));
    while (cells.length < width) cells.push('');
    return { name: String(row?.name ?? ''), cells };
  });
  return { head, rows: rows.length ? rows : [{ name: '', cells: Array(width).fill('') }] };
}

/**
 * Нарисовать таблицу внутрь карточки.
 * Первый столбец — имена строк, поэтому он подсвечен как шапка:
 * по нему читают ряд так же, как по шапке читают столбец.
 */
export function drawTable(holder, element) {
  const data = tableOf(element);

  // Пересобираем только когда содержимое и вид действительно изменились.
  // Иначе клетки подменяются на каждую перерисовку карточки, и браузер
  // перестаёт считать два щелчка двойным: цель-то каждый раз новая.
  const sign = JSON.stringify([data, element.style?.zebra, element.style?.compact]);
  if (holder.dataset.sign === sign && holder.firstElementChild) return holder.firstElementChild;
  holder.dataset.sign = sign;
  holder.textContent = '';

  const table = document.createElement('table');
  table.className = 'node-grid';
  if (element.style?.zebra === false) table.classList.add('no-zebra');
  if (element.style?.compact) table.classList.add('compact');

  const head = document.createElement('thead');
  const headRow = document.createElement('tr');
  headRow.append(cell('th', '', 'grid-corner', { kind: 'corner' }));
  data.head.forEach((title, column) => headRow.append(cell('th', title, '', { kind: 'head', col: column })));
  head.append(headRow);
  table.append(head);

  const tbody = document.createElement('tbody');
  data.rows.forEach((row, line) => {
    const tr = document.createElement('tr');
    tr.append(cell('th', row.name, 'grid-name', { kind: 'name', row: line }));
    row.cells.forEach((text, column) => tr.append(cell('td', text, '', { kind: 'cell', row: line, col: column })));
    tbody.append(tr);
  });
  table.append(tbody);
  holder.append(table);
  return table;
}

/* Каждая клетка помечена своим местом: по этим приметам двойной щелчок
   находит, что именно правят, и кладёт написанное обратно в доп. */
function cell(tag, text, cls = '', spot = {}) {
  const node = document.createElement(tag);
  if (cls) node.className = cls;
  node.dataset.kind = spot.kind || 'cell';
  if (spot.row !== undefined) node.dataset.row = String(spot.row);
  if (spot.col !== undefined) node.dataset.col = String(spot.col);
  node.textContent = text;
  return node;
}

/** Клетки в порядке чтения: по ним ходит Tab. Угол не правится. */
export function cellsOf(node) {
  return [...node.querySelectorAll('.node-grid [data-kind]')]
    .filter((one) => one.dataset.kind !== 'corner');
}

/** Положить написанное на его место. Возвращает новое содержимое таблицы. */
export function putCell(element, spot, text) {
  const data = tableOf(element);
  const row = Number(spot.row);
  const col = Number(spot.col);

  if (spot.kind === 'head' && data.head[col] !== undefined) data.head[col] = text;
  else if (spot.kind === 'name' && data.rows[row]) data.rows[row].name = text;
  else if (spot.kind === 'cell' && data.rows[row]) data.rows[row].cells[col] = text;
  else return null;

  return data;
}

function safeParse(text) {
  try { return JSON.parse(text); } catch { return null; }
}
