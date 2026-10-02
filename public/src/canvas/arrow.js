/* Стрелка: путь, ветка и подпись.
   Отдаёт: drawArrow(), updateArrow().
   Не делает: не решает, куда идти прогону — это дело сервера.

   Первичное у стрелки — откуда, куда и ветка. Подпись и цвет вторичны.

   Концами стрелки бывают только блок, ромб и шлюз. Если конец спрятан
   в схлопнутой группе, стрелка доводится до её края — но рисуется иначе
   и подписывается номером настоящего конца, чтобы её не приняли
   за стрелку к самой рамке. */

import { state, stepOf, byNo } from 'goblin/core/state.js';
import { ARROW_ROUTER, curvePath, curveMid, curveSides, box, isStraight, isSimple } from 'goblin/canvas/geometry.js';
import { route, pathOf, labelOf } from 'goblin/canvas/routing.js';
import { settings } from 'goblin/core/settings.js';
import { edgeColor } from 'goblin/canvas/palette.js';
import { anchorOf, isHiddenByCollapse } from 'goblin/core/containers.js';
import { surfaceElement } from 'goblin/canvas/projection.js';
import { hasServerPaint, edgeLook } from 'goblin/run/paint.js';
import { lookRule } from 'goblin/canvas/looks.js';
import { t } from 'goblin/core/i18n.js';

const SVG = 'http://www.w3.org/2000/svg';

export function drawArrow(element) {
  const group = document.createElementNS(SVG, 'g');
  group.dataset.id = element.id;

  const hit = document.createElementNS(SVG, 'path');
  hit.setAttribute('class', 'hit');
  const line = document.createElementNS(SVG, 'path');
  line.setAttribute('class', 'arrow');
  const plate = document.createElementNS(SVG, 'rect');
  plate.setAttribute('class', 'arrow-label-plate');
  plate.setAttribute('aria-hidden', 'true');
  plate.style.display = 'none';
  const label = document.createElementNS(SVG, 'text');
  label.setAttribute('class', 'arrow-label');
  label.setAttribute('text-anchor', 'middle');

  group.append(hit, line, plate, label);
  updateArrow(group, element);
  return group;
}

export function updateArrow(group, element) {
  const realFrom = state.elements.get(element.from);
  const realTo = state.elements.get(element.to);
  // Конец внутри схлопнутой группы — стрелка доводится до её края.
  // Оба конца в одной группе — стрелка внутренняя, её не видно.
  const anchorFrom = anchorOf(element.from), anchorTo = anchorOf(element.to);
  if (!anchorFrom || !anchorTo || anchorFrom === anchorTo) { group.style.display = 'none'; return; }
  const from = surfaceElement(anchorFrom), to = surfaceElement(anchorTo);
  group.style.display = '';

  const folded = anchorFrom !== realFrom || anchorTo !== realTo;
  group.classList.toggle('folded', folded);

  /* Линию задаёт папка; стрелка решает сама только там, где папка молчит. */
  const rule = state.folder?.style?.route;
  const kind = ['curve', 'line', 'line-simple'].includes(rule) ? rule : (element.style?.route || '');
  const straight = kind === 'curve' ? false
    : (kind === 'line' || kind === 'line-simple' ? true : isStraight(element));
  const simple = kind === 'line-simple' || (!kind && isSimple(element));
  // Выбрали «плавные» — рисуем кривой, прежним алгоритмом: ортогональный
  // маршрут даёт только скруглённые углы, а это не то, что просили.
  const modern = ARROW_ROUTER !== 'legacy' && kind !== 'curve';
  const planned = modern ? plannedRoutes().get(element.id) : null;
  const lane = modern ? 0 : corridor(element, from, to);
  const avoid = modern ? [] : others(element);
  const obstacles = walls(from, to);
  // «Сама выбирает ближайшую» — значит, запомненные дырки в расчёт не идут.
  const ports = settings.arrowPorts === 'manual' ? element : { ...element, style: {} };
  const sides = modern ? planned.sides : curveSides(from, to, ports,
    { straight, simple, lane, avoid, obstacles, taken: seats(element, from, to) });
  const shifts = modern ? [0, 0] : [spread(from, sides[0], element), spread(to, sides[1], element)];
  const path = modern ? pathOf(planned.points, Math.min(planned.pad, straight ? 14 : 22)) : curvePath(from, to,
    { back: element.back, sides, shifts, straight, simple, lane, avoid, obstacles });
  group.querySelector('.hit').setAttribute('d', path);

  const line = group.querySelector('.arrow');
  line.setAttribute('d', path);
  line.setAttribute('class', 'arrow'
    + (element.branch === 'yes' ? ' yes' : '')
    + (element.branch === 'no' ? ' no' : '')
    + (element.back ? ' back' : '')
    + (folded ? ' folded' : '')
    + (state.selection.has(element.id) ? ' sel' : '')
    + (trace(element) ? ' ' + trace(element) : ''));

  line.style.stroke = element.style?.color ? edgeColor(element.style.color) : '';
  if (element.style?.dash) line.style.strokeDasharray = '6 5';
  if (element.style?.width) line.style.strokeWidth = String(element.style.width);

  const label = group.querySelector('.arrow-label');
  // Скин может убрать подпись стрелки и оставить только ветку (мобильный: arrowTitle: false).
  const title = lookRule().show.arrowTitle === false ? '' : element.title;
  const own = branchWord(element) + (title ? (branchWord(element) ? ' · ' : '') + title : '');
  // Спрятанный конец называем по номеру: видно, что стрелка идёт внутрь рамки.
  const hidden = folded
    ? '↦ ' + [anchorFrom !== realFrom ? realFrom?.no : null, anchorTo !== realTo ? realTo?.no : null]
        .filter((no) => no !== null && no !== undefined).join(' → ')
    : '';
  const text = [own, hidden].filter(Boolean).join('  ');
  group.querySelector('.hit').innerHTML = '';
  if (folded) {
    const title = document.createElementNS(SVG, 'title');
    title.textContent = t('editor.canvas.arrow_hidden', { target: realTo?.no ?? '?', group: to?.no ?? '' });
    group.querySelector('.hit').append(title);
  }
  const wrapped = wrapWords(text, 18);
  const fontSize = parseFloat(getComputedStyle(label).fontSize) || 12;
  const labelWidth = Math.max(30, ...wrapped.map(row => row.length * fontSize * 0.68));
  const point = modern ? labelOf(planned.points, [box(from), box(to), ...obstacles], labelWidth, wrapped.length * fontSize * 1.2 + 8)
    : curveMid(from, to, sides, shifts, { back: element.back, straight, simple, lane, avoid, obstacles });
  label.style.display = modern && !planned.points.length ? 'none' : '';
  // Подпись центруется по середине связи и переносится по словам: длинная
  // строка вдоль стрелки перечёркивала пол-схемы.
  label.textContent = '';
  if (text) {
    label.setAttribute('x', Math.round(point.x));
    label.setAttribute('y', Math.round(point.y));
    const lines = wrapped;
    lines.forEach((line, n) => {
      const row = document.createElementNS(SVG, 'tspan');
      row.setAttribute('x', Math.round(point.x));
      row.setAttribute('dy', n === 0 ? `${-((lines.length - 1) * 0.6).toFixed(2)}em` : '1.2em');
      row.textContent = line;
      label.append(row);
    });
  }
  markPasses(group, element, point, text ? wrapped.length : 0, fontSize, label.style.display);
  refreshArrowLabelPlates();
}

/**
 * «×N» у стрелки — сколько раз по ней прошли, по слову сервера (прогоны
 * engine = 2, run/paint.js). Стоит под подписью, а без подписи — на её месте.
 */
function markPasses(group, element, point, rows, fontSize, display) {
  const pass = edgeLook(element.id).pass;
  let mark = group.querySelector('.arrow-pass');
  if (pass <= 1) { mark?.remove(); return; }
  if (!mark) {
    mark = document.createElementNS(SVG, 'text');
    mark.setAttribute('class', 'arrow-pass');
    mark.setAttribute('text-anchor', 'middle');
    group.append(mark);
  }
  mark.textContent = '×' + pass;
  mark.setAttribute('x', Math.round(point.x));
  mark.setAttribute('y', Math.round(point.y + (rows ? (rows * 0.6 + 0.9) * fontSize : 0)));
  mark.style.display = display;
}

let plateFrame = null;

/** Подложки подписей подстраиваются под реальный текст и масштаб камеры.
    Подпись лежит на линии, поэтому подложка нужна во всех обликах: она прячет
    линию под текстом. */
export function refreshArrowLabelPlates() {
  if (plateFrame !== null) return;
  plateFrame = requestAnimationFrame(() => {
    plateFrame = null;
    // Сначала измеряем все подписи, затем меняем SVG — без чередования layout/write.
    const sizes = [...document.querySelectorAll('#arrows .arrow-label')].map(label => {
      const plate = label.parentElement.querySelector('.arrow-label-plate');
      const style = getComputedStyle(label);
      const bounds = label.getBBox();
      const visible = label.textContent.trim() && style.display !== 'none' && bounds.width > 0 && bounds.height > 0;
      return { plate, bounds, visible, font: parseFloat(style.fontSize) || 12 };
    });
    for (const { plate, bounds, visible, font } of sizes) {
      if (!plate) continue;
      plate.style.display = visible ? '' : 'none';
      if (!visible) continue;
      const px = font * .55, py = font * .3;
      plate.setAttribute('x', bounds.x - px);
      plate.setAttribute('y', bounds.y - py);
      plate.setAttribute('width', bounds.width + px * 2);
      plate.setAttribute('height', bounds.height + py * 2);
      plate.setAttribute('rx', font * .4);
    }
  });
}

document.fonts?.addEventListener('loadingdone', refreshArrowLabelPlates);

/** Разбить подпись на строки не длиннее предела, по словам. */
function wrapWords(text, limit) {
  const lines = [];
  let line = '';
  for (let word of String(text).split(/\s+/)) {
    while (word.length > limit) {               // слово длиннее строки — режем
      if (line) { lines.push(line); line = ''; }
      lines.push(word.slice(0, limit));
      word = word.slice(limit);
    }
    if (!line) { line = word; continue; }
    if ((line + ' ' + word).length <= limit) line += ' ' + word;
    else { lines.push(line); line = word; }
  }
  if (line) lines.push(line);
  return lines.slice(0, 4);                     // больше четырёх строк — это уже не подпись
}

/**
 * Насколько отодвинуть стрелку от середины стороны.
 *
 * Все стрелки, приходящие в один и тот же бок карточки, делят его между собой:
 * место занято — следующая встаёт рядом, а не поверх. Порядок берём по номеру
 * стрелки, чтобы он не прыгал при перерисовке.
 */
function spread(element, side, arrow) {
  const room = side === 't' || side === 'b' ? box(element).w : box(element).h;
  const mates = [];
  for (const other of state.elements.values()) {
    if (other.type !== 'arrow') continue;
    const otherFrom = surfaceElement(state.elements.get(other.from));
    const otherTo = surfaceElement(state.elements.get(other.to));
    if (!otherFrom || !otherTo) continue;
    const [sideA, sideB] = curveSides(otherFrom, otherTo, other);
    if (otherFrom.id === element.id && sideA === side) mates.push(other.id);
    else if (otherTo.id === element.id && sideB === side) mates.push(other.id);
  }
  if (mates.length < 2) return 0;

  mates.sort((a, b) => a - b);
  const seat = mates.indexOf(arrow.id);
  if (seat < 0) return 0;
  const step = Math.min(34, room / (mates.length + 1));
  return (seat - (mates.length - 1) / 2) * step;
}

/**
 * Какие гнёзда у концов уже заняты соседними стрелками. Свободное гнездо
 * лучше занятого: в одну дырку две стрелки не втыкают, пока есть куда ещё.
 */
function seats(element, from, to) {
  const taken = { from: new Set(), to: new Set() };
  for (const other of state.elements.values()) {
    if (other.type !== 'arrow' || other.id === element.id) continue;
    const otherFrom = surfaceElement(state.elements.get(other.from));
    const otherTo = surfaceElement(state.elements.get(other.to));
    if (!otherFrom || !otherTo) continue;
    const [sideA, sideB] = curveSides(otherFrom, otherTo, other);
    for (const [side, owner] of [[sideA, otherFrom], [sideB, otherTo]]) {
      if (owner.id === from.id) taken.from.add(side);
      if (owner.id === to.id) taken.to.add(side);
    }
  }
  return taken;
}

/** Чужие карточки на пути: их прямая стрелка обходит, а не режет. */
function walls(from, to) {
  const out = [];
  for (const other of state.elements.values()) {
    if (other.type === 'arrow' || other.id === from.id || other.id === to.id) continue;
    if (other.type === 'area' || other.type === 'group') continue;   // рамки стрелке не мешают
    if (isHiddenByCollapse(other)) continue;
    out.push(box(surfaceElement(other)));
  }
  return out;
}

/**
 * Куда тянутся соседние стрелки: берём прямую от середины к середине их
 * концов. Точный путь соседа знать незачем — важно, где он проходит, чтобы
 * не лезть поперёк.
 */
function others(element) {
  const out = [];
  for (const other of state.elements.values()) {
    if (other.type !== 'arrow' || other.id === element.id) continue;
    const from = surfaceElement(state.elements.get(other.from));
    const to = surfaceElement(state.elements.get(other.to));
    if (!from || !to) continue;
    out.push({ a: heart(from), b: heart(to) });
  }
  return out;
}

const heart = (element) => {
  const b = box(element);
  return { x: b.x + b.w / 2, y: b.y + b.h / 2 };
};

/**
 * Номер дорожки. Стрелки, идущие примерно одним коридором, разводятся по
 * соседним линиям: иначе две линии ложатся одна на другую и видно только
 * верхнюю. Порядок — по номеру стрелки, чтобы дорожки не прыгали.
 */
function corridor(element, from, to) {
  const mine = span(from, to);
  const mates = [];
  for (const other of state.elements.values()) {
    if (other.type !== 'arrow') continue;
    const otherFrom = surfaceElement(state.elements.get(other.from));
    const otherTo = surfaceElement(state.elements.get(other.to));
    if (!otherFrom || !otherTo) continue;
    const near = span(otherFrom, otherTo);
    const overlap = near.left < mine.right && near.right > mine.left
      && near.top < mine.bottom && near.bottom > mine.top;
    if (overlap) mates.push(other.id);
  }
  if (mates.length < 2) return 0;
  mates.sort((a, b) => a - b);
  const seat = mates.indexOf(element.id);
  return seat < 0 ? 0 : seat - (mates.length - 1) / 2;
}

/** Прямоугольник, в котором живёт стрелка: от карточки до карточки. */
function span(from, to) {
  const one = box(from);
  const two = box(to);
  return {
    left: Math.min(one.x, two.x),
    right: Math.max(one.x + one.w, two.x + two.w),
    top: Math.min(one.y, two.y),
    bottom: Math.max(one.y + one.h, two.y + two.h),
  };
}

function branchWord(element) {
  if (element.branch === 'yes') return t('editor.canvas.yes_caps');
  if (element.branch === 'no') return t('editor.canvas.no_caps');
  return element.back ? t('editor.canvas.one_more_round') : '';
}

/**
 * Стрелка «горит», если по ней пришёл шаг, который сейчас в работе.
 * Прогон нового движка (engine = 2) не угадывается: что горит, говорит
 * сервер (run.paint → run/paint.js). Угадывание ниже — только для прежних
 * прогонов engine = 1, их отрисовка не меняется.
 */
function trace(element) {
  if (hasServerPaint()) return edgeLook(element.id).cls;
  if (!state.run) return '';

  for (const step of state.steps.values()) {
    // Живой шаг: по этой стрелке прямо сейчас идёт работа. Горит с выдачи
    // задания и до приёмки — иначе подсветку не успеть заметить.
    if (step.via === element.id && ['issued', 'running', 'submitted'].includes(step.state)) return 'live';
  }

  /* Пройденный путь остаётся зелёным. Считаем его по началу стрелки, а не по
     полю via: в памяти живёт только последняя попытка шага, и стрелки первых
     кругов иначе гасли. У ромба горит только выбранная ветка. */
  const from = state.elements.get(element.from);
  const step = from ? state.steps.get(from.no) : null;
  if (!step || step.state !== 'accepted') return '';
  if (step.chosen && step.chosen !== element.id) return '';
  return 'done';
}


/* Одна раскладка на всю сцену: соседей учитываем по настоящим отрезкам.
   Стабильный порядок исключает зависимость от порядка перерисовки DOM.
   Автоматический режим игнорирует сохранённые ручные стороны; ручной
   фиксирует каждую заданную сторону. Переключение не меняет данные. */
let routeSignature = '';
let routeCache = new Map();
function plannedRoutes() {
  const list = [...state.elements.values()].filter(e => e.type === 'arrow').sort((a, b) => a.id - b.id);
  const entries = list.map(arrow => ({ arrow, from: surfaceElement(anchorOf(arrow.from)), to: surfaceElement(anchorOf(arrow.to)) }))
    .filter(e => e.from && e.to && e.from.id !== e.to.id);
  const signature = JSON.stringify([state.iso, settings.arrowPorts, state.folder?.style?.route,
    [...state.elements.values()].map(e => [e.id, e.type, e.from, e.to, e.branch, e.back, e.style, e.in]),
    entries.map(e => [e.arrow.id, e.from.id, e.to.id])]);
  if (signature === routeSignature) return routeCache;
  const cache = new Map(), avoid = [], occupied = new Map();
  const slot = (id, side) => occupied.get(`${id}:${side}`) || 0;
  const shift = n => n ? Math.ceil(n / 2) * 18 * (n % 2 ? 1 : -1) : 0;
  for (const { arrow, from, to } of entries) {
    const kind = state.folder?.style?.route || arrow.style?.route;
    const options = { obstacles: walls(from, to), avoid, simple: kind === 'line-simple' };
    const input = settings.arrowPorts === 'manual' ? arrow : { ...arrow, style: {} };
    let result = route(from, to, input, options);
    const shifts = [shift(slot(from.id, result.sides[0])), shift(slot(to.id, result.sides[1]))];
    if (shifts.some(Boolean)) {
      const spaced = route(from, to, input, { ...options, sides: result.sides, shifts });
      if (spaced.points.length) result = spaced;
    }
    for (const [id, side] of [[from.id, result.sides[0]], [to.id, result.sides[1]]]) occupied.set(`${id}:${side}`, slot(id, side) + 1);
    cache.set(arrow.id, result);
    for (let i = 1; i < result.points.length; i++) avoid.push({ a: result.points[i - 1], b: result.points[i] });
  }
  routeSignature = signature;
  routeCache = cache;
  return cache;
}
