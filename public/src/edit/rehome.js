/* Переезд: объект переходит в другую рамку — и в составе, и на холсте.
   Отдаёт: canRehome(), rehome().
   Не делает: ничего не рисует и не знает про мышь — её дело вызвать.

   Зачем двигать на холсте. Вхождение в рамку на холсте определяется ещё и
   глазами: элемент внутри, когда рамка накрыла его целиком (`holds()`).
   Если переписать только состав, на холсте объект останется снаружи, и
   первый же его перенос мышью выкинет его обратно. Поэтому переезд всегда
   двигает объект внутрь рамки, а рамку при нужде расширяет.

   Содержимое едет следом: группа везёт своих, область — тех, кто на ней
   стоит. Это те же правила, что у переноса мышью (`core/containers.js`). */

import { state } from 'goblin/core/state.js';
import { box } from 'goblin/canvas/geometry.js';
import { isContainer, membersDeep, carrySet } from 'goblin/core/containers.js';
import * as scene from 'goblin/edit/scene.js';

const PAD = 26;        // отступ от края рамки
const HEAD = 52;       // место под заголовок рамки
const GAP = 18;        // просвет между переехавшими

/** Можно ли положить это в эту рамку: не в себя, не в своё же содержимое. */
export function canRehome(ids, targetId) {
  if (!targetId) return [...ids].some((id) => (state.elements.get(id)?.in || []).length);

  const target = state.elements.get(targetId);
  if (!target || !isContainer(target)) return false;

  const inside = new Set(membersDeep(targetId).map((element) => element.id));
  for (const id of ids) {
    const element = state.elements.get(id);
    if (!element || element.type === 'arrow') continue;
    if (id === targetId || inside.has(id)) return false;
    // Уже лежит прямо в этой рамке — переезжать некуда.
    if ((element.in || []).length === 1 && element.in[0] === targetId) continue;
    return true;
  }
  return false;
}

/**
 * Перевезти объекты в рамку `targetId`; `null` — вынести из всех рамок.
 * Возвращает, сколько объектов переехало.
 */
export function rehome(ids, targetId) {
  const moving = top(ids, targetId);
  if (!moving.length) return 0;

  const target = targetId ? state.elements.get(targetId) : null;
  let place = target ? inside(target) : outside(moving[0]);

  for (const element of moving) {
    const b = box(element);
    shift(element, place.x - b.x, place.y - b.y);
    scene.setMembers(element.id, target ? [target.id] : []);
    place = { x: place.x, y: place.y + b.h + GAP };
  }

  if (target) grow(target, moving);
  return moving.length;
}

/** Только верхние: кто едет внутри другого переезжающего, отдельно не двигаем. */
function top(ids, targetId) {
  const set = new Set(ids);
  const carried = new Set();
  for (const id of set) {
    const element = state.elements.get(id);
    if (isContainer(element)) for (const member of membersDeep(id)) carried.add(member.id);
  }

  const out = [];
  for (const id of set) {
    if (carried.has(id)) continue;
    const element = state.elements.get(id);
    if (!element || element.type === 'arrow') continue;
    if (id === targetId) continue;
    if (targetId && membersDeep(targetId).some((member) => member.id === id)) continue;
    out.push(element);
  }
  return out;
}

/** Откуда начинать раскладку внутри рамки: под заголовком, у левого края. */
function inside(target) {
  const t = box(target);
  const busy = membersDeep(target.id).filter((element) => element.type !== 'arrow').map(box);
  const bottom = busy.length ? Math.max(...busy.map((b) => b.y + b.h)) + GAP : t.y + HEAD;
  return { x: t.x + PAD, y: Math.max(t.y + HEAD, bottom) };
}

/** Куда положить вынесенное из рамки: правее прежней рамки, чтобы не влипло обратно. */
function outside(element) {
  const owner = (element.in || []).map((id) => state.elements.get(id)).find(isContainer);
  const b = box(element);
  if (!owner) return { x: b.x, y: b.y };
  const o = box(owner);
  return { x: o.x + o.w + 60, y: o.y };
}

/** Подвинуть объект и всё, что он везёт. */
function shift(element, dx, dy) {
  if (!dx && !dy) return;
  for (const id of carrySet([element.id]).keys()) {
    const one = state.elements.get(id);
    if (!one || one.type === 'arrow') continue;
    const b = box(one);
    scene.patch(id, { style: { x: Math.round(b.x + dx), y: Math.round(b.y + dy) } });
  }
}

/** Рамка растёт, если приехавшее в неё не влезло. */
function grow(target, moved) {
  const t = box(target);
  const boxes = moved.map(box);
  const right = Math.max(...boxes.map((b) => b.x + b.w)) + PAD;
  const bottom = Math.max(...boxes.map((b) => b.y + b.h)) + PAD;
  const width = Math.max(t.w, right - t.x);
  const height = Math.max(t.h, bottom - t.y);
  if (width === t.w && height === t.h) return;
  scene.patch(target.id, { style: { width: Math.round(width), height: Math.round(height) } });
}
