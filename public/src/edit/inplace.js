/* Правка текста прямо в карточке — двойным щелчком.
   Отдаёт: editInPlace(), editCell(), isEditing().
   Не делает: не решает, где этот жест разрешён — это edit/pointer.js.

   Пока идёт правка, карточку не перерисовывают: иначе текст выпрыгнул бы
   из-под курсора. Enter в заголовке заканчивает, в описании — переносит строку.
   Каретка встаёт туда, куда ткнули: текст не выделяется целиком, чтобы можно
   было просто дописать, а не перенабирать всё заново.

   Клетки таблицы правятся тем же жестом и тем же кодом: Tab переходит
   к следующей клетке, Enter заканчивает. */

import * as scene from 'goblin/edit/scene.js';
import { TABLE_PROP, cellsOf, putCell } from 'goblin/canvas/table.js';

let editing = null;

export function isEditing(node) {
  return !!editing && (!node || editing.node === node);
}

export function editInPlace(node, element, field, at = null) {
  if (editing) finish(true);

  const cls = field === 'description' ? '.node-desc' : '.node-title';
  const target = node.querySelector(cls);
  if (!target) return;

  // Пустое поле показывает подсказку — название типа или ничего. Правят пустоту,
  // а не подсказку: иначе к ней дописывали бы буквы.
  const wasHidden = target.hidden;
  target.hidden = false;
  if (!element[field]) target.textContent = '';

  node.classList.add('editing');
  target.contentEditable = 'plaintext-only';
  target.spellcheck = false;
  target.focus();
  putCaret(target, at);

  editing = { node, element, field, target, wasHidden };

  target.addEventListener('blur', onBlur);
  target.addEventListener('keydown', onKey);
  noTextDrag(target);
}

/**
 * Выделенный текст в поле правки не перетаскивается и не бросается в него:
 * двойной щелчок выделял слово, лёгкий сдвиг мыши тащил его, и копия
 * вставлялась в то же поле — название задваивалось и уходило в базу.
 */
function noTextDrag(target) {
  const stop = (event) => { event.preventDefault(); event.stopPropagation(); };
  target.addEventListener('dragstart', stop);
  target.addEventListener('drop', stop);
}

/* ── Клетка таблицы ───────────────────────────────────────────── */

/**
 * Править клетку прямо на холсте. `cell` — тот самый th или td,
 * он же и помнит своё место в таблице (data-kind, data-row, data-col).
 */
export function editCell(node, element, cell, at = null) {
  if (editing) finish(true);
  if (!cell || cell.dataset.kind === 'corner') return;

  node.classList.add('editing');
  cell.contentEditable = 'plaintext-only';
  cell.spellcheck = false;
  cell.focus();
  putCaret(cell, at);

  editing = { node, element, target: cell, table: { ...cell.dataset }, was: cell.textContent };

  cell.addEventListener('blur', onBlur);
  cell.addEventListener('keydown', onKey);
}

/** Tab ведёт к следующей клетке: набивать таблицу иначе невозможно. */
function stepCell(back) {
  const { node, element, target } = editing;
  const cells = cellsOf(node);
  const at = cells.indexOf(target);
  finish(true);

  const next = cells[at + (back ? -1 : 1)];
  // После записи карточка перерисовалась — ищем клетку на том же месте заново.
  if (!next) return;
  const fresh = cellsOf(node)[at + (back ? -1 : 1)] || next;
  editCell(node, element, fresh);
}

function onBlur() { finish(true); }

function onKey(event) {
  if (!editing) return;
  event.stopPropagation();          // Delete и цифры сейчас — это текст, а не команды
  if (event.key === 'Escape') { event.preventDefault(); finish(false); return; }

  if (editing.table) {
    if (event.key === 'Tab') { event.preventDefault(); stepCell(event.shiftKey); return; }
    if (event.key === 'Enter') { event.preventDefault(); finish(true); }
    return;
  }
  if (event.key === 'Enter' && (editing.field === 'title' || event.metaKey || event.ctrlKey)) {
    event.preventDefault();
    finish(true);
  }
}

function finish(save) {
  if (!editing) return;
  const { node, element, field, target, wasHidden, table, was } = editing;
  editing = null;

  target.removeEventListener('blur', onBlur);
  target.removeEventListener('keydown', onKey);
  // Именно removeAttribute: contentEditable = 'false' оставляет сам атрибут,
  // и подсветка поля осталась бы висеть после ухода фокуса.
  target.removeAttribute('contenteditable');
  target.spellcheck = true;
  node.classList.remove('editing');
  window.getSelection()?.removeAllRanges();

  const text = target.textContent.replace(/\s+$/, '');

  // Клетка таблицы: написанное ложится в доп на своё место.
  if (table) {
    if (save && text !== was) {
      const next = putCell(element, table, text);
      if (next) scene.setProps(element.id, { [TABLE_PROP]: next });
    } else {
      target.textContent = was;
    }
    return;
  }

  if (save && text !== (element[field] || '')) {
    scene.patch(element.id, { [field]: text });
  } else {
    target.textContent = element[field] || '';
    target.hidden = wasHidden;
  }
}

/** Каретка туда, куда ткнули; не попали в текст — в конец строки. */
function putCaret(node, at) {
  const selection = window.getSelection();
  if (!selection) return;

  let range = null;
  if (at && document.caretRangeFromPoint) {
    range = document.caretRangeFromPoint(at.x, at.y);
  } else if (at && document.caretPositionFromPoint) {
    const spot = document.caretPositionFromPoint(at.x, at.y);
    if (spot) { range = document.createRange(); range.setStart(spot.offsetNode, spot.offset); }
  }
  if (!range || !node.contains(range.startContainer)) {
    range = document.createRange();
    range.selectNodeContents(node);
    range.collapse(false);
  } else {
    range.collapse(true);
  }
  selection.removeAllRanges();
  selection.addRange(range);
}
