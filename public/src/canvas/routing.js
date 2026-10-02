/* Маршрутизация стрелок без разворотов у портов.
   Сначала короткие ортогональные варианты, затем поиск по свободным коридорам.
   Геометрия, SVG и подпись рассчитываются из одного маршрута. */
import { box, portPoint } from 'goblin/canvas/geometry-legacy.js';

const SIDES = ['t', 'r', 'b', 'l'];
const DIR = { t: [0, -1], r: [1, 0], b: [0, 1], l: [-1, 0] };
const GAP = 22;
const distance = (a, b) => Math.abs(a.x - b.x) + Math.abs(a.y - b.y);
const direction = (a, b) => b.x > a.x ? 1 : b.x < a.x ? 3 : b.y > a.y ? 2 : 0;
const same = (a, b) => distance(a, b) < 0.001;
const wall = (b, pad = GAP) => ({ l: b.x - pad, r: b.x + b.w + pad, t: b.y - pad, b: b.y + b.h + pad });
const inside = (p, w) => p.x > w.l + 0.001 && p.x < w.r - 0.001 && p.y > w.t + 0.001 && p.y < w.b - 0.001;
function crosses(a, b, w) {
  return a.y === b.y
    ? a.y > w.t + 0.001 && a.y < w.b - 0.001 && Math.max(a.x, b.x) > w.l + 0.001 && Math.min(a.x, b.x) < w.r - 0.001
    : a.x > w.l + 0.001 && a.x < w.r - 0.001 && Math.max(a.y, b.y) > w.t + 0.001 && Math.min(a.y, b.y) < w.b - 0.001;
}
function clean(points) {
  const out = [];
  for (const p of points) {
    if (out.length && same(out.at(-1), p)) continue;
    if (out.length > 1 && direction(out.at(-2), out.at(-1)) === direction(out.at(-1), p)) out.pop();
    out.push(p);
  }
  return out;
}
function crossingCost(a, b, avoid = []) {
  let cost = 0;
  for (const line of avoid) {
    const c = line.a, d = line.b;
    const horizontal = a.y === b.y, otherHorizontal = c.y === d.y;
    if (horizontal === otherHorizontal) {
      const aligned = horizontal ? Math.abs(a.y - c.y) < 8 : Math.abs(a.x - c.x) < 8;
      const overlap = horizontal
        ? Math.min(Math.max(a.x, b.x), Math.max(c.x, d.x)) - Math.max(Math.min(a.x, b.x), Math.min(c.x, d.x))
        : Math.min(Math.max(a.y, b.y), Math.max(c.y, d.y)) - Math.max(Math.min(a.y, b.y), Math.min(c.y, d.y));
      if (aligned && overlap > 1) cost += 500 + overlap * 3;
    } else {
      const h1 = horizontal ? a : c, h2 = horizontal ? b : d;
      const v1 = horizontal ? c : a, v2 = horizontal ? d : b;
      if (v1.x >= Math.min(h1.x, h2.x) && v1.x <= Math.max(h1.x, h2.x)
          && h1.y >= Math.min(v1.y, v2.y) && h1.y <= Math.max(v1.y, v2.y)) {
        const touch = { x: v1.x, y: h1.y };
        if (!((same(touch, a) || same(touch, b)) && (same(touch, c) || same(touch, d)))) cost += 700;
      }
    }
  }
  return cost;
}
function price(points, avoid = []) {
  return points.slice(1).reduce((sum, p, i) => sum + crossingCost(points[i], p, avoid), 0) + points.slice(1).reduce((sum, p, i) => sum + distance(points[i], p), 0) + Math.max(0, points.length - 2) * 44;
}
function valid(points, walls, start, finish) {
  const p = clean(points);
  if (p.length < 2 || direction(p[0], p[1]) !== start || direction(p.at(-2), p.at(-1)) !== finish) return null;
  for (let i = 1; i < p.length; i++) {
    if (p[i].x !== p[i - 1].x && p[i].y !== p[i - 1].y) return null;
    if (i > 1 && direction(p[i - 2], p[i - 1]) === (direction(p[i - 1], p[i]) + 2) % 4) return null;
    // Первому и последнему отрезкам разрешён только проход через свой порт.
    if (walls.some((w, n) => !(i === 1 && n === 0) && !(i === p.length - 1 && n === 1) && crosses(p[i - 1], p[i], w))) return null;
  }
  return p;
}

function endpoints(from, to, sides, shifts, pad) {
  const a = portPoint(from, sides[0], shifts[0]);
  const b = portPoint(to, sides[1], shifts[1]);
  const offset = (p, side) => ({ x: p.x + DIR[side][0] * (pad + 12), y: p.y + DIR[side][1] * (pad + 12) });
  return [a, offset(a, sides[0]), offset(b, sides[1]), b];
}

function routePair(from, to, sides, options, pad = GAP, search = false) {
  const [a, s, t, b] = endpoints(from, to, sides, options.shifts || [0, 0], pad);
  const walls = [box(from), box(to), ...(options.simple ? [] : options.obstacles || [])].map(r => wall(r, pad));
  const start = SIDES.indexOf(sides[0]), finish = (SIDES.indexOf(sides[1]) + 2) % 4;
  const xs = [...new Set([s.x, t.x, (s.x + t.x) / 2 + (options.lane || 0) * 12, ...(options.avoid || []).flatMap(l => l.a.x === l.b.x ? [l.a.x - 18, l.a.x + 18] : []), ...walls.flatMap(w => [w.l, w.r])])].sort((a, b) => a - b);
  const ys = [...new Set([s.y, t.y, (s.y + t.y) / 2 + (options.lane || 0) * 12, ...(options.avoid || []).flatMap(l => l.a.y === l.b.y ? [l.a.y - 18, l.a.y + 18] : []), ...walls.flatMap(w => [w.t, w.b])])].sort((a, b) => a - b);
  const candidates = [[a, b], [a, { x: b.x, y: a.y }, b], [a, { x: a.x, y: b.y }, b]];
  for (const x of xs) candidates.push([a, s, { x, y: s.y }, { x, y: t.y }, t, b]);
  for (const y of ys) candidates.push([a, s, { x: s.x, y }, { x: t.x, y }, t, b]);
  let best = null;
  for (const candidate of candidates) {
    const points = valid(candidate, walls, start, finish);
    if (points && (!best || price(points, options.avoid) < price(best, options.avoid))) best = points;
  }
  if (search && !walls.some(w => inside(s, w) || inside(t, w))) {
    const middle = searchGrid(s, t, xs, ys, walls, start, finish, options.avoid);
    if (middle) {
      const points = valid([a, ...middle, b], walls, start, finish);
      if (points && (!best || price(points, options.avoid) < price(best, options.avoid))) best = points;
    }
  }
  return best;
}

/* A*: состояние содержит направление, поэтому разворот на 180° запрещён
   во всём пути, включая стыки с портами. Куча не сортирует очередь целиком. */
function searchGrid(start, target, xs, ys, walls, startDir, endDir, avoid = []) {
  const heap = [], costs = new Map(), previous = new Map();
  const width = xs.length;
  const index = p => ys.indexOf(p.y) * width + xs.indexOf(p.x);
  const point = id => ({ x: xs[id % width], y: ys[Math.floor(id / width)] });
  const goal = index(target), first = index(start) * 4 + startDir;
  function push(item) {
    heap.push(item);
    let i = heap.length - 1;
    while (i) { const p = (i - 1) >> 1; if (heap[p].f <= item.f) break; heap[i] = heap[p]; i = p; }
    heap[i] = item;
  }
  function pop() {
    const top = heap[0], last = heap.pop();
    if (heap.length) {
      let i = 0;
      while (i * 2 + 1 < heap.length) {
        let child = i * 2 + 1;
        if (child + 1 < heap.length && heap[child + 1].f < heap[child].f) child++;
        if (heap[child].f >= last.f) break;
        heap[i] = heap[child]; i = child;
      }
      heap[i] = last;
    }
    return top;
  }
  costs.set(first, 0); push({ key: first, g: 0, f: distance(start, target) });
  while (heap.length) {
    const current = pop();
    if (current.g !== costs.get(current.key)) continue;
    const id = Math.floor(current.key / 4), dir = current.key % 4, p = point(id);
    if (id === goal && dir !== (endDir + 2) % 4) {
      const path = []; let k = current.key;
      while (k !== undefined) { path.push(point(Math.floor(k / 4))); k = previous.get(k); }
      return path.reverse();
    }
    const x = id % width, y = Math.floor(id / width);
    for (let d = 0; d < 4; d++) {
      if (d === (dir + 2) % 4) continue;
      const nx = x + DIR[SIDES[d]][0], ny = y + DIR[SIDES[d]][1];
      if (nx < 0 || ny < 0 || nx >= width || ny >= ys.length) continue;
      const next = ny * width + nx, q = point(next);
      if (walls.some(w => inside(q, w) || crosses(p, q, w))) continue;
      const key = next * 4 + d;
      const g = current.g + distance(p, q) + crossingCost(p, q, avoid) + (dir === d ? 0 : 44) + (next === goal && d !== endDir ? 44 : 0);
      if (g >= (costs.get(key) ?? Infinity)) continue;
      costs.set(key, g); previous.set(key, current.key);
      push({ key, g, f: g + distance(q, target) });
    }
  }
  return null;
}

export function route(from, to, arrow = {}, options = {}) {
  const style = arrow.style || {};
  const fixed = options.sides;
  const starts = fixed ? [fixed[0]] : SIDES.includes(style.fromSide) ? [style.fromSide] : SIDES;
  const ends = fixed ? [fixed[1]] : SIDES.includes(style.toSide) ? [style.toSide] : SIDES;
  const wanted = from.type === 'decision' ? (arrow.branch === 'yes' ? 'l' : arrow.branch === 'no' ? 'r' : null) : null;
  // Сужаем запас только если при нормальном зазоре пути вообще нет.
  for (const pad of [GAP, 8, 0]) {
    let candidate = null;
    for (const search of [false, true]) {
      let best = null;
      for (const a of starts) for (const b of ends) {
        const points = routePair(from, to, [a, b], options, pad, search);
        if (!points) continue;
        const cost = price(points, options.avoid) + (wanted && a !== wanted ? 150 : 0)
          + (options.taken?.from?.has(a) ? 24 : 0) + (options.taken?.to?.has(b) ? 24 : 0);
        if (!best || cost < best.cost) best = { points, sides: [a, b], cost, pad };
      }
      if (best && (!candidate || best.cost < candidate.cost)) candidate = best;
      const intersections = candidate?.points.slice(1).reduce((sum, p, i) => sum + crossingCost(candidate.points[i], p, options.avoid), 0);
      if (candidate && (search || intersections === 0)) return candidate;
    }
  }
  // Перекрытые карточки могут закрыть порт физически. Не рисуем сквозь них
  // ложный маршрут: связь появится после освобождения порта.
  return { points: [], sides: [starts[0], ends[0]], cost: Infinity, pad: 0 };
}

export function pathOf(points, radius = 14) {
  if (points.length < 2) return '';
  let path = `M ${points[0].x} ${points[0].y}`;
  for (let i = 1; i < points.length - 1; i++) {
    const p = points[i], a = points[i - 1], b = points[i + 1];
    const r = Math.min(radius, distance(a, p) / 2, distance(p, b) / 2);
    const before = { x: p.x + Math.sign(a.x - p.x) * r, y: p.y + Math.sign(a.y - p.y) * r };
    const after = { x: p.x + Math.sign(b.x - p.x) * r, y: p.y + Math.sign(b.y - p.y) * r };
    path += ` L ${before.x} ${before.y} Q ${p.x} ${p.y} ${after.x} ${after.y}`;
  }
  return path + ` L ${points.at(-1).x} ${points.at(-1).y}`;
}

/* Подпись — на самой линии, на середине подходящего отрезка; её прямоугольник
   не должен лежать на карточке. Размер задаёт рисующий модуль. */
export function labelOf(points, obstacles, width = 150, height = 40) {
  /* Подпись лежит прямо на линии — в середине отрезка, без сдвига вбок;
     подложка под ней (arrow-label-plate) прячет линию под текстом.
     Отрезок выбираем ближе к середине стрелки и такой, где подпись не ложится
     на блок. Вдоль горизонтального отрезка подпись должна уместиться по длине,
     вертикальный она просто пересекает поперёк. */
  let best = null;
  const total = points.slice(1).reduce((sum, p, i) => sum + distance(points[i], p), 0);
  let walked = 0;
  for (let i = 1; i < points.length; i++) {
    const a = points[i - 1], b = points[i], length = distance(a, b);
    const p = { x: (a.x + b.x) / 2, y: (a.y + b.y) / 2 };
    const horizontal = Math.abs(a.y - b.y) < 1;
    const rect = { l: p.x - width / 2, r: p.x + width / 2, t: p.y - height / 2, b: p.y + height / 2 };
    const hits = obstacles.filter(o => rect.l < o.x + o.w + 6 && rect.r > o.x - 6 && rect.t < o.y + o.h + 6 && rect.b > o.y - 6).length;
    const tight = Math.max(0, (horizontal ? width : height) + 16 - length);
    const cost = hits * 100000 + Math.abs(walked + length / 2 - total / 2) + tight * 3;
    if (!best || cost < best.cost) best = { ...p, cost };
    walked += length;
  }
  return best || points[0] || { x: 0, y: 0 };
}
