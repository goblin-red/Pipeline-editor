/* Дерево вложенности: кто в какой рамке лежит.
   Отдаёт: isFolder(), parentOf(), childrenOf(), rootItems(), levels(), countAll().
   Не делает: не рисует, не правит и на сервер не ходит — только отвечает,
   что во что вложено.

   Папкой считается любая рамка — и группа, и область: держат они одинаково,
   разный у них только облик. Стрелки в дерево не попадают вовсе.

   В `in` у объекта стоит одна рамка — его родитель. У схем, нарисованных
   до этого правила, рамок может оказаться несколько: родителем из них
   считается самая внутренняя. */

import { state } from 'goblin/core/state.js';
import { box } from 'goblin/canvas/geometry.js';

export function isFolder(element) {
  return element?.type === 'group' || element?.type === 'area';
}

/** Рамки из списка `in`, которые всё ещё существуют. */
function candidates(element) {
  const out = [];
  for (const id of element?.in || []) {
    const container = state.elements.get(id);
    if (isFolder(container)) out.push(container);
  }
  return out;
}

/** Лежит ли группа `inner` внутри группы `outer` — по составу, не по картинке. */
function insideOf(inner, outer, seen = new Set()) {
  for (const next of candidates(inner)) {
    if (next.id === outer.id) return true;
    if (seen.has(next.id)) continue;
    seen.add(next.id);
    if (insideOf(next, outer, seen)) return true;
  }
  return false;
}

/**
 * Группа, в которой лежит элемент, или null, если он в корне.
 * Из нескольких берём самую внутреннюю; если они друг другу не родня —
 * самую тесную, как это делает холст.
 */
export function parentOf(element) {
  const list = candidates(element);
  if (list.length <= 1) return list[0] || null;

  let best = list[0];
  for (const group of list.slice(1)) {
    if (insideOf(group, best)) { best = group; continue; }
    if (insideOf(best, group)) continue;
    const a = box(group);
    const b = box(best);
    if (a.w * a.h < b.w * b.h) best = group;
  }
  return best;
}

/** Порядок в колонке: сначала папки, потом всё остальное; внутри — по номеру. */
function inOrder(list) {
  return list.sort((a, b) => {
    if (isFolder(a) !== isFolder(b)) return isFolder(a) ? -1 : 1;
    if (a.no && b.no) return a.no - b.no;
    if (a.no || b.no) return a.no ? -1 : 1;
    return (a.title || '').localeCompare(b.title || '', 'ru');
  });
}

/** Кто лежит прямо в этой группе. */
export function childrenOf(id) {
  const out = [];
  for (const element of state.elements.values()) {
    if (element.type === 'arrow') continue;
    if (parentOf(element)?.id === id) out.push(element);
  }
  return inOrder(out);
}

/** Верхний уровень: всё, что не лежит ни в одной группе. */
export function rootItems() {
  const out = [];
  for (const element of state.elements.values()) {
    if (element.type === 'arrow') continue;
    if (!parentOf(element)) out.push(element);
  }
  return inOrder(out);
}

/**
 * Все уровни разом — для вида «Всё сразу».
 * Уровень это список секций: у первого одна секция без заголовка,
 * у следующих — по секции на каждую непустую папку уровня выше.
 */
export function levels(limit = 40) {
  const first = rootItems();
  if (!first.length) return [];

  const out = [[{ parent: null, items: first }]];
  const seen = new Set();

  while (out.length < limit) {
    const next = [];
    for (const section of out[out.length - 1]) {
      for (const element of section.items) {
        if (!isFolder(element) || seen.has(element.id)) continue;
        seen.add(element.id);                       // кольцо в составе не зациклит
        const items = childrenOf(element.id);
        if (items.length) next.push({ parent: element, items });
      }
    }
    if (!next.length) break;
    out.push(next);
  }
  return out;
}

/** Сколько всего элементов в дереве — стрелки не в счёт. */
export function countAll() {
  let count = 0;
  for (const element of state.elements.values()) if (element.type !== 'arrow') count++;
  return count;
}
