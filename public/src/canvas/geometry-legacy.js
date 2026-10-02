/* Геометрия холста: где у элемента порты и как между ними идёт стрелка.
   Отдаёт: box(), portPoint(), arrowPath(), pickSides(), pointInBox().
   Не делает: не трогает DOM. */

export function box(element) {
  const s = element.style || {};
  return {
    x: Number(s.x) || 0,
    y: Number(s.y) || 0,
    w: Number(s.width) || 280,
    h: Number(s.height) || 180,
  };
}

export function center(element) {
  const b = box(element);
  return { x: b.x + b.w / 2, y: b.y + b.h / 2 };
}

/**
 * Точка порта. shift сдвигает её вдоль той же стороны: так несколько стрелок,
 * пришедших с одной стороны, не втыкаются в одну точку. Сдвиг ограничен
 * краями стороны, иначе стрелка приходила бы мимо карточки.
 */
export function portPoint(element, side, shift = 0) {
  const b = box(element);
  const alongX = Math.max(-(b.w / 2 - 16), Math.min(b.w / 2 - 16, shift));
  const alongY = Math.max(-(b.h / 2 - 16), Math.min(b.h / 2 - 16, shift));
  switch (side) {
    case 't': return { x: b.x + b.w / 2 + alongX, y: b.y };
    case 'b': return { x: b.x + b.w / 2 + alongX, y: b.y + b.h };
    case 'l': return { x: b.x, y: b.y + b.h / 2 + alongY };
    default:  return { x: b.x + b.w, y: b.y + b.h / 2 + alongY };
  }
}

/** Стороны выбираются сами: куда ближе, туда и пойдёт стрелка. */
export function pickSides(from, to) {
  const a = center(from);
  const b = center(to);
  const dx = b.x - a.x;
  const dy = b.y - a.y;
  if (Math.abs(dy) >= Math.abs(dx)) {
    return dy >= 0 ? ['b', 't'] : ['t', 'b'];
  }
  return dx >= 0 ? ['r', 'l'] : ['l', 'r'];
}

const SIDES = ['t', 'r', 'b', 'l'];

/* Куда «смотрит» порт: по этой стрелке считается, развёрнут он к соседу или
   от него. Порт, глядящий в противоположную сторону, штрафуется — иначе
   стрелка сначала уходит назад, а потом огибает карточку. */
const LOOK = { t: { x: 0, y: -1 }, b: { x: 0, y: 1 }, l: { x: -1, y: 0 }, r: { x: 1, y: 0 } };

/**
 * Какими дырками стрелка цепляется за карточки.
 *
 * По умолчанию берутся ближайшие порты: тот путь, который короче и не идёт
 * задом наперёд. У ромба ветки тянет в стороны — ДА влево, НЕТ вправо, — но
 * только пока это не делает дорогу вдвое длиннее.
 *
 * Если стрелке заданы свои стороны (style.fromSide / style.toSide), они и
 * берутся: человек воткнул стрелку руками, и переставлять её незачем.
 */
export function sidesFor(from, to, arrow = {}, options = {}) {
  const style = arrow.style || {};
  // Дырку, выбранную руками, держим — даже если руками выбрали только одну.
  const ownFrom = SIDES.includes(style.fromSide) ? style.fromSide : null;
  const ownTo = SIDES.includes(style.toSide) ? style.toSide : null;
  if (ownFrom && ownTo) return [ownFrom, ownTo];

  // У прямой стрелки стороны выбираются по настоящему пути: считаем ломаную
  // для каждой пары дырок и берём ту, что идёт спокойнее всего.
  const pick = options.straight
    ? (fixed) => byRoute(from, to, options, fixed, ownTo)
    : (fixed) => cheapest(from, to, fixed, options, ownTo);

  const taken = options.taken || { from: new Set(), to: new Set() };
  const best = pick(ownFrom);
  if (from?.type === 'decision' && (arrow.branch === 'yes' || arrow.branch === 'no')) {
    const wanted = arrow.branch === 'yes' ? 'l' : 'r';
    const aside = pick(wanted);
    if (aside.cost <= best.cost * 2) return [wanted, aside.side];
  }
  return [best.from, best.to];
}

/** Пара дырок с самым спокойным ломаным путём: длина, повороты, пересечения. */
function byRoute(from, to, options, fixed = null, fixedTo = null) {
  let best = null;
  for (const a of fixed ? [fixed] : SIDES) {
    for (const b of fixedTo ? [fixedTo] : SIDES) {
      const curve = bend(from, to, { ...options, sides: [a, b] });
      const points = elbow(curve);
      const walls = [curve.boxA, curve.boxB, ...(options.obstacles || [])].map((rect) => fence(rect));
      const price = score(points, walls, options.avoid || [])
        + turnBack(a, points[0], points[points.length - 1]) * 0.5
        + busy(a, b, options);
      if (!best || price < best.cost) best = { from: a, to: b, side: b, cost: price };
    }
  }
  return best;
}

/**
 * Занятое гнездо чуть дороже свободного — но именно чуть: ближняя дырка
 * важнее свободной. Прежний штраф в 260 пикселей перевешивал расстояние,
 * и вторая стрелка к той же карточке втыкалась сверху, хотя сосед стоял
 * слева. Двум стрелкам в одной дырке не тесно: их разводит spread().
 */
function busy(sideA, sideB, options) {
  const taken = options.taken;
  if (!taken) return 0;
  return (taken.from?.has(sideA) ? 30 : 0) + (taken.to?.has(sideB) ? 30 : 0);
}

/** Самая дешёвая пара портов. Сторона начала можно закрепить. */
function cheapest(from, to, fixed = null, options = {}, fixedTo = null) {
  let best = null;
  for (const a of fixed ? [fixed] : SIDES) {
    for (const b of fixedTo ? [fixedTo] : SIDES) {
      const pa = portPoint(from, a);
      const pb = portPoint(to, b);
      const span = Math.hypot(pb.x - pa.x, pb.y - pa.y);
      const cost = span + turnBack(a, pa, pb) + turnBack(b, pb, pa) + busy(a, b, options);
      if (!best || cost < best.cost) best = { from: a, to: b, side: b, cost };
    }
  }
  return best;
}

/** Штраф за порт, отвёрнутый от соседа: чем сильнее отвёрнут, тем дороже. */
function turnBack(side, self, other) {
  const look = LOOK[side];
  const dx = other.x - self.x;
  const dy = other.y - self.y;
  const span = Math.hypot(dx, dy) || 1;
  const facing = (look.x * dx + look.y * dy) / span;      // 1 — точно туда, -1 — назад
  return facing >= 0 ? 0 : Math.abs(facing) * 420;
}

/**
 * Путь стрелки: мягкая кривая между портами.
 * Возвратная стрелка цикла обходит блоки сбоку, чтобы не сливаться с прямой.
 */
/** Прямая ли эта стрелка: в style.route у неё стоит «прямая». */
export function isStraight(arrow) {
  return ['line', 'line-simple', 'straight', 'orthogonal'].includes(arrow?.style?.route);
}

/** Простая прокладка — та, что ведёт углом и чужие карточки не обходит. */
export function isSimple(arrow) {
  return arrow?.style?.route === 'line-simple';
}

export function arrowPath(from, to, options = {}) {
  const curve = bend(from, to, options);
  if (options.straight) return cornered(elbow(curve));
  return `M ${curve.a.x} ${curve.a.y} C ${curve.c1.x} ${curve.c1.y}, ${curve.c2.x} ${curve.c2.y}, ${curve.b.x} ${curve.b.y}`;
}

/*
 * Прямая стрелка идёт углами, а не наискось: сначала отходит от дырки на
 * четверть шага, потом поворачивает и входит в чужую дырку строго по прямой.
 * Косая линия втыкалась в бок карточки под случайным углом — от этого схема
 * и выглядела рваной.
 */
/** Ломаная прямой стрелки: её же проверяют тесты геометрии. */
export function elbowPoints(from, to, options = {}) {
  return elbow(bend(from, to, options));
}

function elbow(curve) {
  const { sideA, sideB, boxA, boxB, lane = 0, avoid = [] } = curve;
  // Простая прокладка чужих карточек не видит — в этом и разница.
  const obstacles = curve.simple ? [] : (curve.obstacles || []);
  const [a, b] = align(curve);

  // Что обходим: чужие карточки и свои же два конца — в них влезать нельзя.
  const wallA = fence(boxA);
  const wallB = fence(boxB);
  const walls = [wallA, wallB, ...obstacles.map((rect) => fence(rect))];
  const p1 = stub(a, LOOK[sideA], walls, wallA);
  const p2 = stub(b, LOOK[sideB], walls, wallB);

  // Уровни, по которым имеет смысл вести линию: середина между отступами,
  // края карточек и рамка снаружи всего. Своя дорожка сдвигает середину,
  // чтобы соседние стрелки не легли одна на другую.
  const xs = [(p1.x + p2.x) / 2 + lane * 22];
  const ys = [(p1.y + p2.y) / 2 + lane * 22];
  for (const wall of walls) {
    xs.push(wall.left, wall.right);
    ys.push(wall.top, wall.bottom);
  }
  if (walls.length) {
    xs.push(Math.min(...walls.map((w) => w.left)) - 28, Math.max(...walls.map((w) => w.right)) + 28);
    ys.push(Math.min(...walls.map((w) => w.top)) - 28, Math.max(...walls.map((w) => w.bottom)) + 28);
  }

  /* Уровень, отстоящий от отступа на считанные пиксели, подтягиваем к нему:
     иначе на углу остаётся крошечная ступенька — она и читается как «залом». */
  const snapX = (x) => (Math.abs(x - p1.x) < 9 ? p1.x : (Math.abs(x - p2.x) < 9 ? p2.x : x));
  const snapY = (y) => (Math.abs(y - p1.y) < 9 ? p1.y : (Math.abs(y - p2.y) < 9 ? p2.y : y));

  const tries = [];
  if (Math.abs(p1.x - p2.x) < 0.01 || Math.abs(p1.y - p2.y) < 0.01) tries.push([p1, p2]);
  if (curve.simple) {
    // Простой ход: угол или ступенька, без обхода чужих карточек.
    tries.push([p1, { x: p1.x, y: p2.y }, p2], [p1, { x: p2.x, y: p1.y }, p2],
               [p1, { x: p1.x, y: snapY((p1.y + p2.y) / 2) }, { x: p2.x, y: snapY((p1.y + p2.y) / 2) }, p2],
               [p1, { x: snapX((p1.x + p2.x) / 2), y: p1.y }, { x: snapX((p1.x + p2.x) / 2), y: p2.y }, p2]);
    let pick = null;
    for (const road of tries) {
      const clean = dedupe(road);
      const price = score(clean, [], avoid);
      if (!pick || price < pick.price) pick = { clean, price };
    }
    return dedupe([a, p1, ...pick.clean.slice(1, -1), p2, b]);
  }
  for (const raw of xs) {
    const x = snapX(raw);
    tries.push([p1, { x, y: p1.y }, { x, y: p2.y }, p2]);
  }
  for (const raw of ys) {
    const y = snapY(raw);
    tries.push([p1, { x: p1.x, y }, { x: p2.x, y }, p2]);
  }
  // Обход по внешней рамке — выручает, когда все прямые ходы закрыты.
  for (const x of [xs[xs.length - 2], xs[xs.length - 1]]) {
    for (const y of [ys[ys.length - 2], ys[ys.length - 1]]) {
      if (!Number.isFinite(x) || !Number.isFinite(y)) continue;
      tries.push([p1, { x: snapX(x), y: p1.y }, { x: snapX(x), y: snapY(y) },
                  { x: p2.x, y: snapY(y) }, p2]);
    }
  }

  let best = null;
  for (const road of tries) {
    const clean = dedupe(road);
    const price = score(clean, walls, avoid);
    if (!best || price < best.price) best = { clean, price };
  }
  return dedupe([a, p1, ...best.clean.slice(1, -1), p2, b]);
}

/**
 * Почти совпавшие дырки подводим к одной линии: разъезд в пару пикселей давал
 * либо еле заметный наклон, либо крошечную ступеньку на углу.
 */
function align(curve) {
  const { a, b, sideA, sideB, boxA, boxB } = curve;
  const vertA = LOOK[sideA].x === 0;
  const vertB = LOOK[sideB].x === 0;
  if (vertA !== vertB) return [a, b];

  const near = 40;
  const one = { ...a };
  const two = { ...b };
  if (vertA) {
    if (Math.abs(a.x - b.x) > near) return [a, b];
    const x = (a.x + b.x) / 2;
    if (!inside(x, boxA.x, boxA.w) || !inside(x, boxB.x, boxB.w)) return [a, b];
    one.x = x;
    two.x = x;
  } else {
    if (Math.abs(a.y - b.y) > near) return [a, b];
    const y = (a.y + b.y) / 2;
    if (!inside(y, boxA.y, boxA.h) || !inside(y, boxB.y, boxB.h)) return [a, b];
    one.y = y;
    two.y = y;
  }
  return [one, two];
}

/** Попадает ли точка в сторону карточки с запасом от углов. */
function inside(value, start, span, pad = 16) {
  return value >= start + pad && value <= start + span - pad;
}

/** Карточка с запасом: ближе этого стрелке подходить незачем. */
function fence(rect, pad = 16) {
  return {
    left: rect.x - pad, right: rect.x + rect.w + pad,
    top: rect.y - pad, bottom: rect.y + rect.h + pad,
  };
}

/**
 * Отход от дырки. Из своей рамки выходим наружу, в чужую — не влезаем:
 * если впереди чужая карточка, отход укорачивается до неё.
 */
function stub(point, look, walls, own, want = 46) {
  let far = want;
  const mine = own && point.x >= own.left && point.x <= own.right
    && point.y >= own.top && point.y <= own.bottom;
  if (mine) {
    if (look.x > 0) far = Math.max(far, own.right - point.x + 14);
    if (look.x < 0) far = Math.max(far, point.x - own.left + 14);
    if (look.y > 0) far = Math.max(far, own.bottom - point.y + 14);
    if (look.y < 0) far = Math.max(far, point.y - own.top + 14);
  }
  for (const wall of walls) {
    if (wall === own) continue;
    const gap = ahead(point, look, wall);
    if (gap !== null) far = Math.min(far, Math.max(16, gap - 14));
  }
  return { x: point.x + look.x * far, y: point.y + look.y * far };
}

/** Сколько от точки до рамки, если идти прямо по направлению взгляда. */
function ahead(point, look, wall) {
  if (look.x !== 0) {
    if (point.y < wall.top || point.y > wall.bottom) return null;
    const gap = look.x > 0 ? wall.left - point.x : point.x - wall.right;
    return gap > 0 ? gap : null;
  }
  if (point.x < wall.left || point.x > wall.right) return null;
  const gap = look.y > 0 ? wall.top - point.y : point.y - wall.bottom;
  return gap > 0 ? gap : null;
}

/**
 * Чем плох ход. Пересечь карточку — дороже всего, дальше длина, потом
 * повороты и пересечения с чужими стрелками. Так побеждает короткий путь,
 * который никого не задел.
 */
function score(points, walls, avoid = []) {
  let hits = 0;
  let length = 0;
  let crossed = 0;
  for (let n = 1; n < points.length; n += 1) {
    const from = points[n - 1];
    const to = points[n];
    length += Math.abs(to.x - from.x) + Math.abs(to.y - from.y);
    for (const wall of walls) if (through(from, to, wall)) hits += 1;
    for (const line of avoid) if (meets(from, to, line.a, line.b)) crossed += 1;
  }
  return hits * 1000000 + crossed * 900 + length + Math.max(0, points.length - 2) * 12;
}

/** Проходит ли отрезок сквозь рамку (по её краю — не считается). */
function through(a, b, wall) {
  const eps = 0.1;
  if (Math.abs(a.y - b.y) < eps) {
    if (a.y <= wall.top + eps || a.y >= wall.bottom - eps) return false;
    return Math.max(Math.min(a.x, b.x), wall.left) < Math.min(Math.max(a.x, b.x), wall.right) - eps;
  }
  if (Math.abs(a.x - b.x) < eps) {
    if (a.x <= wall.left + eps || a.x >= wall.right - eps) return false;
    return Math.max(Math.min(a.y, b.y), wall.top) < Math.min(Math.max(a.y, b.y), wall.bottom) - eps;
  }
  return true;
}

/** Пересекаются ли два отрезка. */
function meets(a1, a2, b1, b2) {
  const turn = (p, q, r) => Math.sign((q.x - p.x) * (r.y - p.y) - (q.y - p.y) * (r.x - p.x));
  return turn(b1, b2, a1) !== turn(b1, b2, a2) && turn(a1, a2, b1) !== turn(a1, a2, b2);
}

/**
 * Приводим ломаную в порядок: совпавшие точки и точки на одной прямой убираем.
 * Больше ничего не двигаем — прямая стрелка должна остаться прямой.
 */
function dedupe(points) {
  return tighten(points.map((point) => ({ ...point })));
}

/** Совпавшие точки и точки на одной прямой убираем; развороты оставляем. */
function tighten(points) {
  const out = [];
  for (const point of points) {
    const last = out[out.length - 1];
    if (last && Math.abs(point.x - last.x) < 0.5 && Math.abs(point.y - last.y) < 0.5) continue;
    out.push(point);
  }
  const line = [];
  for (const point of out) {
    const n = line.length;
    if (n >= 2) {
      const a = line[n - 2];
      const b = line[n - 1];
      const flatX = Math.abs(a.x - b.x) < 0.5 && Math.abs(b.x - point.x) < 0.5
        && Math.sign(b.y - a.y) === Math.sign(point.y - b.y);
      const flatY = Math.abs(a.y - b.y) < 0.5 && Math.abs(b.y - point.y) < 0.5
        && Math.sign(b.x - a.x) === Math.sign(point.x - b.x);
      if (flatX || flatY) { line[n - 1] = point; continue; }
    }
    line.push(point);
  }
  return line;
}

/** Путь по точкам со скруглёнными углами: острые углы выглядят грубо. */
function cornered(points, radius = 12) {
  if (points.length < 3) {
    return `M ${points[0].x} ${points[0].y} L ${points[points.length - 1].x} ${points[points.length - 1].y}`;
  }
  let out = `M ${points[0].x} ${points[0].y}`;
  for (let n = 1; n < points.length - 1; n += 1) {
    const prev = points[n - 1];
    const here = points[n];
    const next = points[n + 1];
    const before = shorten(here, prev, radius);
    const after = shorten(here, next, radius);
    out += ` L ${before.x} ${before.y} Q ${here.x} ${here.y}, ${after.x} ${after.y}`;
  }
  const last = points[points.length - 1];
  return out + ` L ${last.x} ${last.y}`;
}

/** Точка на пути от «здесь» к «туда», отступя не больше половины отрезка. */
function shorten(here, there, radius) {
  const dx = there.x - here.x;
  const dy = there.y - here.y;
  const span = Math.hypot(dx, dy) || 1;
  const step = Math.min(radius, span / 2);
  return { x: here.x + (dx / span) * step, y: here.y + (dy / span) * step };
}

/** Опорные точки кривой: их знают и путь, и подпись — иначе подпись уезжает. */
function bend(from, to, options = {}) {
  const [sideA, sideB] = options.sides || pickSides(from, to);
  const [shiftA, shiftB] = options.shifts || [0, 0];
  const a = portPoint(from, sideA, shiftA);
  const b = portPoint(to, sideB, shiftB);
  const vertical = sideA === 't' || sideA === 'b';
  const pull = Math.max(40, Math.min(160, Math.hypot(b.x - a.x, b.y - a.y) / 2));

  if (options.back) {
    /* Возвратная стрелка цикла отходит от дырки прямо, как все остальные,
       и лишь потом отводится вбок — чтобы не сливаться с прямой. Раньше она
       заворачивала прямо у порта, и казалось, будто линия делает лишний крюк. */
    const dx = b.x - a.x;
    const dy = b.y - a.y;
    const span = Math.hypot(dx, dy) || 1;
    const bow = Math.min(90, span / 4);
    const perp = { x: -dy / span * bow, y: dx / span * bow };
    return {
      a, b, sideA, sideB, boxA: box(from), boxB: box(to),
      c1: { x: a.x + LOOK[sideA].x * pull + perp.x, y: a.y + LOOK[sideA].y * pull + perp.y },
      c2: { x: b.x + LOOK[sideB].x * pull + perp.x, y: b.y + LOOK[sideB].y * pull + perp.y },
    };
  }
  const c1 = vertical
    ? { x: a.x, y: a.y + (sideA === 'b' ? pull : -pull) }
    : { x: a.x + (sideA === 'r' ? pull : -pull), y: a.y };
  const c2 = (sideB === 't' || sideB === 'b')
    ? { x: b.x, y: b.y + (sideB === 't' ? -pull : pull) }
    : { x: b.x + (sideB === 'l' ? -pull : pull), y: b.y };
  return { a, b, sideA, sideB, boxA: box(from), boxB: box(to), c1, c2 };
}

/** Середина самой кривой, а не отрезка между портами: подпись садится на линию. */
export function midPoint(from, to, sides, shifts = [0, 0], options = {}) {
  const curve = bend(from, to, { ...options, sides, shifts });
  if (options.straight) return alongPath(elbow(curve));
  const at = (p1, p2, p3, p4) => (p1 + 3 * p2 + 3 * p3 + p4) / 8;   // кубическая кривая при t = 0.5
  return {
    x: at(curve.a.x, curve.c1.x, curve.c2.x, curve.b.x),
    y: at(curve.a.y, curve.c1.y, curve.c2.y, curve.b.y),
  };
}

/** Середина ломаной по длине: подпись садится на саму линию. */
function alongPath(points) {
  let total = 0;
  const legs = [];
  for (let n = 1; n < points.length; n += 1) {
    const span = Math.hypot(points[n].x - points[n - 1].x, points[n].y - points[n - 1].y);
    legs.push(span);
    total += span;
  }
  let left = total / 2;
  for (let n = 0; n < legs.length; n += 1) {
    if (left > legs[n]) { left -= legs[n]; continue; }
    const part = legs[n] ? left / legs[n] : 0;
    return {
      x: points[n].x + (points[n + 1].x - points[n].x) * part,
      y: points[n].y + (points[n + 1].y - points[n].y) * part,
    };
  }
  return points[points.length - 1];
}

export function pointInBox(point, element) {
  const b = box(element);
  return point.x >= b.x && point.x <= b.x + b.w && point.y >= b.y && point.y <= b.y + b.h;
}

/** Прямоугольник выделения рамкой. */
export function boxesOverlap(a, b) {
  return !(a.x + a.w < b.x || b.x + b.w < a.x || a.y + a.h < b.y || b.y + b.h < a.y);
}

/* Шаг сетки и прилипание переехали в core/settings.js: их выбирает человек
   в окне настроек, и решаться это должно в одном месте. */
