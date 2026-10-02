/* Устройство таблицы в панели: сколько столбцов и строк, что убрать, что добавить.
   Отдаёт: tableSection().
   Не делает: не правит текст клеток — их правят двойным щелчком прямо
   на холсте (edit/inplace.js). Здесь только скелет: добавить, убрать, вид. */

import { state } from 'goblin/core/state.js';
import * as scene from 'goblin/edit/scene.js';
import { TABLE_PROP, tableOf } from 'goblin/canvas/table.js';
import { el, wrap, note, small } from 'goblin/panel/parts.js';
import { t } from 'goblin/core/i18n.js';

export function tableSection(element) {
  const data = tableOf(element);
  const save = (next) => scene.setProps(element.id, { [TABLE_PROP]: next });

  const columns = el('div', 'tbl-chips');
  data.head.forEach((title, column) => {
    columns.append(chip(title || t('editor.table.column_lc', { n: column + 1 }), t('editor.table.remove_column'), () => {
      data.head.splice(column, 1);
      for (const row of data.rows) row.cells.splice(column, 1);
      save(data);
    }));
  });

  const rows = el('div', 'tbl-chips');
  data.rows.forEach((row, line) => {
    rows.append(chip(row.name || t('editor.table.row_lc', { n: line + 1 }), t('editor.table.remove_row'), () => {
      data.rows.splice(line, 1);
      save(data);
    }));
  });

  // Кнопки — своей строкой с переносом: в узкой панели четыре штуки в ряд
  // не помещались, и подписи ломались пополам.
  const add = el('div', 'tbl-actions');
  add.append(
    small(t('editor.table.add_column'), () => {
      data.head.push(t('editor.table.column', { n: data.head.length + 1 }));
      for (const row of data.rows) row.cells.push('');
      save(data);
    }),
    small(t('editor.table.add_row'), () => {
      data.rows.push({ name: t('editor.table.row', { n: data.rows.length + 1 }), cells: Array(data.head.length).fill('') });
      save(data);
    }),
    small(element.style?.zebra === false ? t('editor.table.stripes_on') : t('editor.table.stripes_off'),
      () => scene.patch(element.id, { style: { zebra: element.style?.zebra === false } })),
    small(element.style?.compact ? t('editor.table.roomy') : t('editor.table.dense'),
      () => scene.patch(element.id, { style: { compact: !element.style?.compact } })),
  );

  return wrap(t('editor.table.section'), [
    note(t('editor.table.note')),
    label(t('editor.table.columns')), columns,
    label(t('editor.table.rows')), rows,
    add,
  ], true, data.rows.length);
}

/** Плашка с крестиком: щёлкнул — убрал столбец или строку. */
function chip(text, title, onClick) {
  const holder = el('span', 'tbl-chip');
  holder.append(el('span', '', text));
  const off = el('button', 'tbl-drop', '×');
  off.title = title;
  off.disabled = state.viewOnly;
  off.onclick = onClick;
  holder.append(off);
  return holder;
}

function label(text) {
  const node = el('span', 'tbl-label');
  node.textContent = text;
  return node;
}
