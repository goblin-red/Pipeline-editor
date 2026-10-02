/* ПЕРЕКЛЮЧЕНИЕ АЛГОРИТМА СТРЕЛОК
   ARROW_ROUTER = 'harmonious' — новый алгоритм (включён по умолчанию).
   ARROW_ROUTER = 'legacy'     — прежний алгоритм без изменений.
   После смены значения сохраните файл и обновите страницу.
   Резервный код: geometry-legacy.js. Переключатель действует и на отрисовку,
   и на выбор портов, и на подписи. Данные схемы не переписываются.
   В новом алгоритме «прямая» и «кривая» используют безопасный ортогональный
   маршрут с радиусами 14 и 22; «простая» не обходит сторонние карточки. */
export const ARROW_ROUTER = 'harmonious';

import * as legacy from 'goblin/canvas/geometry-legacy.js';
import { route, pathOf, labelOf } from 'goblin/canvas/routing.js';
export { box, center, portPoint, pickSides, isStraight, isSimple, pointInBox, boxesOverlap } from 'goblin/canvas/geometry-legacy.js';

/* «Плавная» рисуется прежним алгоритмом — настоящей кривой от порта к порту.
   Обхода чужих карточек у неё нет: выбрали кривую, значит кривая важнее обхода. */
export const curveSides = legacy.sidesFor;
export const curvePath = legacy.arrowPath;
export const curveMid = legacy.midPoint;

export function sidesFor(from, to, arrow = {}, options = {}) {
  return ARROW_ROUTER === 'legacy' ? legacy.sidesFor(from, to, arrow, options) : route(from, to, arrow, options).sides;
}
export function elbowPoints(from, to, options = {}) {
  return ARROW_ROUTER === 'legacy' ? legacy.elbowPoints(from, to, options) : route(from, to, {}, options).points;
}
export function arrowPath(from, to, options = {}) {
  if (ARROW_ROUTER === 'legacy') return legacy.arrowPath(from, to, options);
  const result = route(from, to, {}, options);
  return pathOf(result.points, Math.min(result.pad, options.straight ? 14 : 22));
}
export function midPoint(from, to, sides, shifts = [0, 0], options = {}) {
  if (ARROW_ROUTER === 'legacy') return legacy.midPoint(from, to, sides, shifts, options);
  const result = route(from, to, {}, { ...options, sides, shifts });
  return labelOf(result.points, [legacy.box(from), legacy.box(to), ...(options.obstacles || [])]);
}
