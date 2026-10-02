/* Отмена и возврат: ⌘Z и ⇧⌘Z.
   Отдаёт: record(), group(), undo(), redo(), canUndo(), canRedo(), clearHistory(), replaying().
   Не делает: ничего не знает о правках — шаг сам приносит две функции,
   «как вернуть» и «как повторить».

   Шаг адресует элементы номером, а не id: удалённый и возвращённый элемент
   получает новую строку в базе, но остаётся тем же объектом под тем же номером.
   Поэтому отмена находит его заново, и цепочка отмен не рвётся. */

import { emit } from 'goblin/core/state.js';

const LIMIT = 100;

const past = [];
const future = [];
let busy = false;
let collecting = null;

/** Идёт ли сейчас откат: пока идёт, новые шаги не записываются. */
export function replaying() { return busy; }

/**
 * Запомнить шаг.
 * @param {{label: string, undo: Function, redo: Function}} step
 */
export function record(step) {
  if (busy) return;
  if (collecting) { collecting.push(step); return; }
  past.push(step);
  if (past.length > LIMIT) past.shift();
  future.length = 0;      // новая правка обрывает будущее: возвращать больше некуда
  emit('history');
}

/**
 * Один жест — один шаг отмены.
 * Перенос десяти блоков или сборка группы отменяются целиком, а не по кусочку.
 */
export function group(label, action) {
  if (busy || collecting) return action();
  collecting = [];
  let steps;
  try { action(); } finally { steps = collecting; collecting = null; }

  if (!steps.length) return;
  if (steps.length === 1) { record(steps[0]); return; }
  record({
    label,
    undo: () => { for (const step of [...steps].reverse()) step.undo(); },
    redo: () => { for (const step of steps) step.redo(); },
  });
}

export function canUndo() { return past.length > 0; }
export function canRedo() { return future.length > 0; }

export function undo() {
  const step = past.pop();
  if (!step) return null;
  run(step.undo);
  future.push(step);
  emit('history');
  return step.label;
}

export function redo() {
  const step = future.pop();
  if (!step) return null;
  run(step.redo);
  past.push(step);
  emit('history');
  return step.label;
}

/** Сменилась папка — прошлое чужое. */
export function clearHistory() {
  past.length = 0;
  future.length = 0;
  emit('history');
}

function run(action) {
  busy = true;
  try { action(); } finally { busy = false; }
}
