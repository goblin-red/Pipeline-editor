/* Единая ортографическая проекция для карточек, стрелок и жестов.
   Координаты схемы остаются X/Y; z — высота основания, thick — высота тела. */
import { state } from 'goblin/core/state.js';
import { box } from 'goblin/canvas/geometry-legacy.js';

export const ISO_A = Math.cos(36 * Math.PI / 180);
export const ISO_B = Math.sin(36 * Math.PI / 180);
export const ISO_MATRIX = `matrix(${ISO_A},${ISO_B},${-ISO_A},${ISO_B},0,0)`;
export const AXES = { x: { x: ISO_A, y: ISO_B }, y: { x: -ISO_A, y: ISO_B }, z: { x: 0, y: -1 } };
export const numeric = (value, fallback = 0) => Number.isFinite(Number(value)) ? Number(value) : fallback;

export function thickness(element) {
  const fallback = { block: 18, decision: 14, gateway: 14, table: 8 }[element.type] || 0;
  return Math.max(0, Math.min(2000, element.style?.thick == null ? fallback : numeric(element.style.thick)));
}
export function elevation(element) { return numeric(element.style?.z); }
export function liftOf(element) { return elevation(element) + thickness(element); }
export function project(x, y, z = 0, iso = state.iso) {
  return iso ? { x: (x - y) * ISO_A, y: (x + y) * ISO_B - z } : { x, y };
}
export function unproject(x, y, z = 0, iso = state.iso) {
  if (!iso) return { x, y };
  return { x: (x / ISO_A + (y + z) / ISO_B) / 2, y: ((y + z) / ISO_B - x / ISO_A) / 2 };
}
export function axisDelta(axis, dx, dy, zoom = 1) {
  const v = AXES[axis];
  return (dx * v.x + dy * v.y) / ((v.x * v.x + v.y * v.y) * zoom);
}
/** Положение верхней поверхности в координатах наклонённой плоскости. */
export function surfaceElement(element) {
  if (!element || !state.iso) return element;
  const b = box(element), shift = liftOf(element) / (2 * ISO_B);
  return { ...element, style: { ...element.style, x: b.x - shift, y: b.y - shift } };
}
/** Габариты на экране до масштаба и смещения камеры, включая боковые грани. */
export function projectedBounds(element, iso = state.iso) {
  const b = box(element);
  const levels = iso ? [elevation(element), liftOf(element)] : [0];
  const points = levels.flatMap(z => [[0, 0], [b.w, 0], [b.w, b.h], [0, b.h]]
    .map(([x, y]) => project(b.x + x, b.y + y, z, iso)));
  const x = Math.min(...points.map(p => p.x)), y = Math.min(...points.map(p => p.y));
  return { x, y, w: Math.max(...points.map(p => p.x)) - x, h: Math.max(...points.map(p => p.y)) - y };
}

/** Порядок перекрытия: верхний объект закрывает нижний, ближний — дальний. */
export function depthOrder(elements) {
  const list = elements.slice();
  const byId=new Map(list.map(e=>[e.id,e]));
  const contains=(parent,child)=>{
    const seen=new Set(),pending=[...(child.in||[])];
    while(pending.length){const id=pending.pop();if(id===parent.id)return true;if(seen.has(id))continue;
      seen.add(id);pending.push(...(byId.get(id)?.in||[]));}
    return false;
  };
  const score = e => { const b = box(e); return b.x + b.w / 2 + b.y + b.h / 2 + liftOf(e); };
  list.sort((a, b) => score(a) - score(b) || a.id - b.id);
  const next = list.map(() => []), incoming = list.map(() => 0);
  for (let i = 0; i < list.length; i++) for (let j = i + 1; j < list.length; j++) {
    const a = box(list[i]), b = box(list[j]);
    const overlap = a.x < b.x + b.w && a.x + a.w > b.x && a.y < b.y + b.h && a.y + a.h > b.y;
    let before = null;
    if (contains(list[i],list[j])) before = i;
    else if (contains(list[j],list[i])) before = j;
    else if (overlap && liftOf(list[i]) <= elevation(list[j])) before = i;
    else if (overlap && liftOf(list[j]) <= elevation(list[i])) before = j;
    else if (a.x + a.w <= b.x || a.y + a.h <= b.y) before = i;
    else if (b.x + b.w <= a.x || b.y + b.h <= a.y) before = j;
    if (before === null) continue;
    const after = before === i ? j : i;
    next[before].push(after);incoming[after]++;
  }
  const remaining = new Set(list.map((_, i) => i)), order = [];
  while (remaining.size) {
    // При пересекающихся телах строгого порядка нет: устойчивый порядок по центрам.
    const i = [...remaining].find(i => incoming[i] === 0) ?? remaining.values().next().value;
    remaining.delete(i);order.push(list[i]);
    for (const j of next[i]) incoming[j]--;
  }
  return order;
}
