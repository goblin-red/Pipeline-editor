/* Пальцы на холсте мобильного скина.
   Отдаёт: initTouch(), setSelecting().
   Не делает: правил схемы не знает — ставит и соединяет через edit/place.js
   и edit/scene.js, а блок двигает руками edit/pointer.js.

   Палец с пустого места двигает холст; палец с блока или группы сразу двигает их
   (выделено несколько — едут все); два пальца — масштаб и сдвиг.
   Касание блока — выбрать (снизу полоска действий, mobile/bar.js); двойное — его свойства.
   Касание пустого места — снять выбор. Двойное касание пустого места — показать схему
   (mobile/camera.js), а с инструментом в руке — поставить элемент (инструмент остаётся);
   одинарное с инструментом ничего не ставит и снимает инструмент. Шторку «Инструменты»
   касание холста не закрывает.
   Инструмент «стрелка»: коснись одного блока, потом другого; из ромба стрелка
   остаётся выбранной, чтобы задать ей ветку (mobile/bar.js). «Ярлык» — так же:
   блок, потом цель. «Группировать» — касания отмечают блоки, собирает их кнопка
   на плашке над холстом; «Разгруппировать» — касание группы или блока в ней.
   Инструмент «Выделение» (m-select): палец рисует рамку и выделяет всё, что задел;
   касание блока добавляет его или снимает. Что делать с выделенным — полоска снизу
   (mobile/bar.js): собрать в группу, удалить.
   Значок в углу группы (mobile/folds.js) сворачивает и разворачивает её.
   Уголок размера у выбранного блока тянут сразу. Перенос и размер отдаются
   edit/pointer.js, поэтому сетка, рамки и отмена работают как на компьютере.

   Мышиные жесты редактора в мобильном скине до холста не доходят: палец,
   скользнувший по блоку, не должен его сдвинуть. */

import { state, on, emit } from 'goblin/core/state.js';
import { panBy, zoomBy, toCanvas } from 'goblin/canvas/view.js';
import { canLink, isLinkEnd } from 'goblin/core/kinds.js';
import { box, boxesOverlap } from 'goblin/canvas/geometry.js';
import { isHiddenByCollapse } from 'goblin/core/containers.js';
import { placeAt } from 'goblin/edit/place.js';
import * as scene from 'goblin/edit/scene.js';
import { createLink } from 'goblin/edit/links.js';
import { ungroupSelected, toggleCollapse } from 'goblin/edit/grouping.js';
import { toast } from 'goblin/shell/topbar.js';
import { isMobile, closeDrawer, openDrawer, drawerTab } from 'goblin/mobile/drawer.js';
import { readableFit } from 'goblin/mobile/camera.js';
import { drawFolds } from 'goblin/mobile/folds.js';
import { t } from 'goblin/core/i18n.js';

const TAP_MOVE = 10;     // px: дальше — уже не касание, а жест
const TAP_MS = 500;      // дольше — не касание
const DOUBLE_MS = 320;   // второе касание в этот срок — двойное

// Поверх холста — кнопки со своей жизнью и поля ввода.
const OWN = '.canvas-tools, input, textarea, select';
// Инструменты, которые касанием ставят новый элемент, — в отличие от стрелки, ярлыка и групп.
const PLACE = ['block', 'decision', 'gateway', 'note', 'table'];

const fingers = new Map();   // pointerId → { x, y }
let gesture = null;          // kind: tap | pan | pinch | drag | select
let lastTap = null;          // для двойного касания: { at, x, y, id — элемент или нет }
let arrowFrom = null;        // инструмент «стрелка»: первый блок
let linkFrom = null;         // инструмент «ярлык»: снимок владельца — цель может быть в другой папке
let frame = null;            // рамка выделения на экране
let toolOff = null;          // одинарное касание пустого места снимет инструмент — если не придёт второе
let wrap = null;

export function initTouch() {
  wrap = document.getElementById('canvas-wrap');
  document.addEventListener('pointerdown', down, true);
  document.addEventListener('pointermove', move, true);
  document.addEventListener('pointerup', up, true);
  document.addEventListener('pointercancel', cancel, true);
  // Двойной щелчок редактора ставит блок и правит текст — пальцем это делает касание.
  document.addEventListener('dblclick', (event) => {
    if (onCanvas(event)) { event.stopPropagation(); event.preventDefault(); }
  }, true);
  wrap.addEventListener('contextmenu', (event) => { if (isMobile()) event.preventDefault(); });
  // После касания браузер шлёт поддельные щелчки мышью: они уводили фокус
  // из названия нового блока, и клавиатура телефона тут же пряталась.
  wrap.addEventListener('touchend', (event) => { if (onCanvas(event)) event.preventDefault(); }, { passive: false });
  on('tool', () => { arrowFrom = null; linkFrom = null; clearTimeout(toolOff); toolOff = null; });
  frame = document.createElement('div');
  frame.className = 'm-marquee';
  frame.hidden = true;
  wrap.append(frame);
}

/* ── Выделение рамкой ────────────────────────────────────────── */

/** Инструмент «Выделение» в руке: палец рисует рамку, а не двигает холст. */
const selecting = () => state.tool === 'm-select';

/** Выключить «Выделение» (или включить): это инструмент, как блок или стрелка. */
export function setSelecting(on) {
  if (on === selecting()) return;
  state.tool = on ? 'm-select' : null;
  emit('tool');
}

function drawFrame(a, b) {
  const rect = wrap.getBoundingClientRect();
  Object.assign(frame.style, {
    left: Math.min(a.x, b.x) - rect.left + 'px', top: Math.min(a.y, b.y) - rect.top + 'px',
    width: Math.abs(b.x - a.x) + 'px', height: Math.abs(b.y - a.y) + 'px',
  });
  frame.hidden = false;
}

/** Рамка отпущена: выделено всё, что она задела, — как рамкой мыши в edit/pointer.js. */
function selectInFrame(a, b) {
  frame.hidden = true;
  const p = toCanvas(a.x, a.y);
  const q = toCanvas(b.x, b.y);
  const area = { x: Math.min(p.x, q.x), y: Math.min(p.y, q.y), w: Math.abs(q.x - p.x), h: Math.abs(q.y - p.y) };
  state.selection.clear();
  for (const element of state.elements.values()) {
    if (element.type === 'arrow' || isHiddenByCollapse(element)) continue;
    if (boxesOverlap(area, box(element))) state.selection.add(element.id);
  }
  emit('selection');
}

/** Касание холста, которое ведёт этот модуль, а не редактор. */
function onCanvas(event) {
  return isMobile() && !event.mForward && wrap.contains(event.target)
    && !event.target.isContentEditable && !event.target.closest(OWN);
}

/* ── Касания ─────────────────────────────────────────────────── */

function down(event) {
  if (!onCanvas(event) || (event.pointerType === 'mouse' && event.button !== 0)) return;
  event.stopPropagation();
  const point = { x: event.clientX, y: event.clientY };
  fingers.set(event.pointerId, point);
  try { wrap.setPointerCapture(event.pointerId); } catch {}

  if (fingers.size === 1) {
    gesture = { kind: 'tap', id: event.pointerId, target: event.target,
                from: { ...point }, last: { ...point }, at: performance.now() };
    // Уголок размера у выбранного блока тянут сразу, без долгого нажатия.
    if (event.target.closest('.node-grip') && !state.viewOnly) {
      gesture.kind = 'drag';
      forward('pointerdown', event.target, point);
      return;
    }
    // Палец на блоке или группе: повёл — едет она, а не холст.
    const node = event.target.closest('.el');
    if (node && !state.viewOnly && !selecting()) gesture.node = node;
  } else if (fingers.size === 2 && gesture?.kind !== 'drag') {
    // Второй палец: масштаб. Блок, который уже едет, второй палец не трогает.
    frame.hidden = true;
    gesture = { kind: 'pinch', ...pair() };
  }
}

function move(event) {
  if (event.mForward) return;
  const finger = fingers.get(event.pointerId);
  if (!finger) return;
  event.stopPropagation();
  finger.x = event.clientX;
  finger.y = event.clientY;
  if (!gesture) return;

  if (gesture.kind === 'drag') {
    if (event.pointerId !== gesture.id) return;
    gesture.last = { ...finger };
    forward('pointermove', wrap, finger);
    drawFolds();   // значки групп едут вместе с группой и её размером
    return;
  }
  if (gesture.kind === 'pinch') { pinch(); return; }
  if (gesture.kind === 'select') { drawFrame(gesture.from, finger); return; }
  if (gesture.kind === 'tap') {
    if (Math.hypot(finger.x - gesture.from.x, finger.y - gesture.from.y) < TAP_MOVE) return;
    if (selecting()) { gesture.kind = 'select'; drawFrame(gesture.from, finger); return; }
    if (gesture.node) {
      gesture.kind = 'drag';
      forward('pointerdown', gesture.node, gesture.from);
      forward('pointermove', wrap, finger);
      gesture.last = { ...finger };
      return;
    }
    gesture.kind = 'pan';
  }
  panBy(finger.x - gesture.last.x, finger.y - gesture.last.y);
  gesture.last = { x: finger.x, y: finger.y };
}

function up(event) {
  if (event.mForward) return;
  const finger = fingers.get(event.pointerId);
  if (!finger) return;
  event.stopPropagation();
  fingers.delete(event.pointerId);
  const was = gesture;

  if (was?.kind === 'drag') {
    if (event.pointerId === was.id) { forward('pointerup', wrap, finger); rest(); }
    return;
  }
  if (was?.kind === 'select' && event.pointerId === was.id) selectInFrame(was.from, finger);
  if (was?.kind === 'tap' && !fingers.size && performance.now() - was.at < TAP_MS) tap(was.target, finger);
  rest();
}

function cancel(event) {
  if (event.mForward || !fingers.has(event.pointerId)) return;
  event.stopPropagation();
  fingers.delete(event.pointerId);
  const was = gesture;
  if (was?.kind === 'drag' && event.pointerId === was.id) forward('pointerup', wrap, was.last);
  frame.hidden = true;
  rest();
}

/** Жест кончился; остался один палец после щипка — он дальше двигает холст. */
function rest() {
  if (fingers.size === 1) {
    const [finger] = fingers.values();
    gesture = { kind: 'pan', last: { ...finger } };
  } else if (!fingers.size) {
    gesture = null;
  }
}

/* ── Масштаб двумя пальцами ──────────────────────────────────── */

function pair() {
  const [a, b] = [...fingers.values()];
  return {
    dist: Math.max(1, Math.hypot(a.x - b.x, a.y - b.y)),
    mid: { x: (a.x + b.x) / 2, y: (a.y + b.y) / 2 },
  };
}

function pinch() {
  if (fingers.size < 2) return;
  const now = pair();
  const rect = wrap.getBoundingClientRect();
  zoomBy(now.dist / gesture.dist, { x: now.mid.x - rect.left, y: now.mid.y - rect.top });
  panBy(now.mid.x - gesture.mid.x, now.mid.y - gesture.mid.y);
  gesture.dist = now.dist;
  gesture.mid = now.mid;
}

/* ── Жест редактору ──────────────────────────────────────────── */

/** Жест — редактору (edit/pointer.js) тем же пальцем. Пометка mForward: мимо этого модуля. */
function forward(type, target, point) {
  const event = new PointerEvent(type, {
    bubbles: true, cancelable: true, clientX: point.x, clientY: point.y,
    pointerId: gesture.id, pointerType: 'touch', isPrimary: true,
    button: 0, buttons: type === 'pointerup' ? 0 : 1,
  });
  event.mForward = true;
  target.dispatchEvent(event);
}

/* ── Касание: выбрать, поставить, соединить ──────────────────── */

function tap(target, point) {
  const node = target.closest?.('.el');
  const arrow = target.closest?.('g[data-id]');
  const id = Number(node?.dataset.id || arrow?.dataset.id) || null;
  const element = id ? state.elements.get(id) : null;
  // Шторку «Инструменты» касание холста не закрывает: её убирают значком в шапке.
  // Свойства («Блок») тоже остаются, если коснулись другого элемента, — панель
  // сразу показывает уже его; касание пустого места их закрывает.
  const keep = drawerTab() === 'tools' || (drawerTab() === 'props' && element);
  if (!keep) closeDrawer();

  // Значок в углу группы (mobile/folds.js): свернуть в плашку или развернуть обратно.
  const fold = target.closest?.('.m-fold');
  if (fold) {
    const group = state.elements.get(Number(fold.dataset.id));
    if (group && !state.viewOnly) { toggleCollapse(group); pick(group.id); }
    return;
  }
  if (state.tool === 'arrow') { arrowTap(element); return; }
  if (state.tool === 'link') { linkTap(element); return; }
  if (state.tool === 'm-group') { groupTap(element); return; }
  if (state.tool === 'm-ungroup') { ungroupTap(element); return; }
  // Выделение: касание блока добавляет его к выделенному или снимает, пустое место — снимает всё.
  if (selecting()) {
    if (element && element.type !== 'arrow') {
      if (state.selection.has(element.id)) state.selection.delete(element.id);
      else state.selection.add(element.id);
    } else if (!element) {
      state.selection.clear();
    }
    emit('selection');
    return;
  }

  // С инструментом в руке пустое место и рамка (группа, область) — место для нового:
  // ставит двойное касание, одинарное ничего не делает.
  const now = performance.now();
  const room = element && (element.type === 'group' || element.type === 'area');
  if (PLACE.includes(state.tool) && !state.viewOnly && (!element || room)) {
    const twice = lastTap?.place && now - lastTap.at < DOUBLE_MS
      && Math.hypot(point.x - lastTap.x, point.y - lastTap.y) < 30;
    lastTap = twice ? null : { at: now, x: point.x, y: point.y, place: true };
    clearTimeout(toolOff);
    toolOff = null;
    if (twice) { placeAt(state.tool, toCanvas(point.x, point.y)); return; }
    // Одинарное — снять инструмент, но не сразу: вдруг это первое касание двойного.
    const tool = state.tool;
    toolOff = setTimeout(() => {
      toolOff = null;
      if (state.tool === tool) { state.tool = null; emit('tool'); }
    }, DOUBLE_MS);
    return;
  }
  if (element) {
    // Второе касание того же элемента — открыть его свойства в меню ☰.
    const again = lastTap?.id === element.id && now - lastTap.at < DOUBLE_MS;
    lastTap = again ? null : { at: now, x: point.x, y: point.y, id: element.id };
    pick(element.id);
    if (again) openDrawer('props');
    return;
  }

  const twice = lastTap && !lastTap.id && now - lastTap.at < DOUBLE_MS
    && Math.hypot(point.x - lastTap.x, point.y - lastTap.y) < 30;
  lastTap = twice ? null : { at: now, x: point.x, y: point.y };
  if (twice) { readableFit(); return; }
  if (state.selection.size) { state.selection.clear(); emit('selection'); }
}

function pick(id) {
  if (state.selection.size === 1 && state.selection.has(id)) return;
  state.selection.clear();
  state.selection.add(id);
  emit('selection');
}

/** Стрелка двумя касаниями: откуда, потом куда. Инструмент остаётся — можно дальше. */
function arrowTap(element) {
  if (!element || !canLink(element.type)) {
    toast(t('editor.mobile.arrow_between'), true);
    return;
  }
  if (!arrowFrom || !state.elements.has(arrowFrom)) {
    arrowFrom = element.id;
    pick(element.id);
    toast(t('editor.mobile.arrow_target'));
    return;
  }
  if (arrowFrom === element.id) { arrowFrom = null; return; }
  const from = state.elements.get(arrowFrom);
  const made = scene.connect(arrowFrom, element.id);
  arrowFrom = null;
  state.selection.clear();
  // Из ромба — стрелка сразу выбрана: в полоске снизу ей выбирают ветку «да» или «нет».
  if (made && from?.type === 'decision') state.selection.add(made.id);
  emit('selection');
}

/** Ярлык двумя касаниями: чей, потом на что. Как «щёлк — щёлк» компьютерной версии. */
function linkTap(element) {
  if (!element || !isLinkEnd(element.type)) {
    toast(t('editor.mobile.link_on'), true);
    return;
  }
  if (!linkFrom) {
    linkFrom = { id: element.id, no: element.no, type: element.type,
                 title: element.title || '', folder: state.folder?.id };
    pick(element.id);
    toast(t('editor.mobile.link_target'));
    return;
  }
  if (linkFrom.id === element.id) { linkFrom = null; toast(t('editor.mobile.link_cancelled')); return; }
  const owner = linkFrom;
  linkFrom = null;
  createLink(owner.id, element.id, { near: owner });
}

/** «Группировать»: касание отмечает блок или снимает отметку. Собирает кнопка на плашке (mobile/bar.js). */
function groupTap(element) {
  if (!element || element.type === 'arrow') {
    toast(t('editor.mobile.group_pick'), true);
    return;
  }
  if (state.selection.has(element.id)) state.selection.delete(element.id);
  else state.selection.add(element.id);
  emit('selection');
}

/** «Разгруппировать»: касание группы — или блока в ней — разбирает эту группу. */
function ungroupTap(element) {
  const group = element?.type === 'group' ? element
    : (element?.in || []).map((id) => state.elements.get(id)).find((one) => one?.type === 'group');
  if (!group) {
    toast(t('editor.mobile.ungroup_pick'), true);
    return;
  }
  state.selection.clear();
  state.selection.add(group.id);
  ungroupSelected();
  state.tool = null;
  emit('tool');
  toast(t('editor.mobile.ungrouped'));
}
