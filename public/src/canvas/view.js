/* Холст: камера, перерисовка и связь модели с DOM.
   Отдаёт: initView(), draw(), drawOne(), dropOne(), rekeyNode(), focusOn(), fitZoom(), flyTo(), camera,
           toCanvas(), fit(), zoomBy(), zoomStep(), rememberCamera(), restoreCamera().
   Не делает: не меняет данные — рисует то, что лежит в state.

   Правило: перерисовывается один элемент и его стрелки, а не вся папка.
   Полная пересборка бывает только при смене папки. */

import { state, on, emit, stepOf } from 'goblin/core/state.js';
import { settings, gridStep, gridInk } from 'goblin/core/settings.js';
import { drawElement, updateElement } from 'goblin/canvas/element.js';
import { drawArrow, updateArrow, refreshArrowLabelPlates } from 'goblin/canvas/arrow.js';
import { ARROW_ROUTER } from 'goblin/canvas/geometry.js';
import { ISO_A, ISO_B, ISO_MATRIX, project, unproject, projectedBounds, depthOrder } from 'goblin/canvas/projection.js';
import { isHiddenByCollapse } from 'goblin/core/containers.js';
import { initLinks, paintLinks, paintLinksOf } from 'goblin/canvas/link.js';

export const camera = { x: 120, y: 80, zoom: 1, iso: false };

let canvas, layerElements, layerArrows, wrap, zoomLabel;
const nodes = new Map();   // id → DOM-узел

export function initView() {
  wrap = document.getElementById('canvas-wrap');
  canvas = document.getElementById('canvas');
  layerElements = document.getElementById('layer-elements');
  layerArrows = document.getElementById('arrows');
  zoomLabel = document.getElementById('zoom-label');

  on('scheme', draw);
  // Сменили папку — у неё своё запомненное место; нет запомненного — показываем всё.
  on('folder', () => {
    if (state.variant !== 'canvas' || wrap.hidden) return;
    if (cameraKey() === activeCameraKey) return;
    if (!restoreCamera()) fit();
  });
  on('element', (element) => drawOne(element));
  on('drop', (element) => dropOne(element.id));
  on('steps', repaintSteps);

  try { camera.iso = localStorage.getItem('goblin-iso') === '1'; } catch {}
  state.iso = camera.iso;
  wrap.addEventListener('wheel', onWheel, { passive: false });
  on('settings', (name) => {
    applyCamera();
    if (ARROW_ROUTER !== 'legacy' && (name === 'arrowPorts' || name === null)) redrawRoutes();
  });
  document.getElementById('btn-zoom-in').onclick = () => zoomStep(1.5);
  document.getElementById('btn-zoom-out').onclick = () => zoomStep(1 / 1.5);
  // Настоящий размер: тем же плавным ходом, что и кнопки шага.
  document.getElementById('btn-zoom-100').onclick = () => zoomStep(1 / camera.zoom);
  document.getElementById('btn-fit').onclick = fit;
  // Рефреш может произойти до отложенной записи последнего движения.
  window.addEventListener('beforeunload', rememberCamera);
  window.addEventListener('pagehide', rememberCamera);
  document.addEventListener('visibilitychange', () => {
    if (document.hidden) rememberCamera();
  });
  new ResizeObserver(keepPlace).observe(wrap);
  applyCamera();
  initLinks();                       // ярлыки висят на карточках: canvas/link.js
}

/* Левая плашка уехала или вернулась — левый край холста сдвинулся вместе с ней.
   Камера идёт навстречу, и схема стоит на месте, как при скрытии правой панели.
   Срабатывает на каждом кадре анимации, поэтому схема не дёргается. */
let wrapLeft = null;

function keepPlace() {
  // Холст спрятан (другой вид) — отсчёт начнётся заново, когда он вернётся.
  if (wrap.hidden || !wrap.clientWidth) { wrapLeft = null; return; }
  const left = wrap.getBoundingClientRect().left;
  if (wrapLeft !== null && left !== wrapLeft) panBy(wrapLeft - left, 0);
  wrapLeft = left;
}

/* ── Камера ──────────────────────────────────────────────────── */

export function applyCamera() {
  canvas.style.transform = `translate(${camera.x}px, ${camera.y}px) scale(${camera.zoom})`
    + (camera.iso ? ' ' + ISO_MATRIX : '');
  document.body.classList.toggle('iso', camera.iso);
  document.getElementById('btn-iso')?.classList.toggle('on', camera.iso);
  // Подписи стрелок живут в координатах схемы, поэтому при плавном масштабе
  // браузер перерисовывал бы буквы на каждом дробном шаге — отсюда дрожь.
  // Считаем им размер обратно масштабу: на экране он один и тот же.
  const label = Math.max(5, Math.min(90, 11 / camera.zoom));
  // Прячем подписи только на самом мелком масштабе: до этого они читаются,
  // потому что их размер на экране не меняется.
  document.body.classList.toggle('no-labels', camera.zoom < 0.50);
  canvas.style.setProperty('--zoom', String(camera.zoom));
  canvas.style.setProperty('--label-size', label.toFixed(2) + 'px');
  canvas.style.setProperty('--label-halo', (3 / camera.zoom).toFixed(2) + 'px');
  zoomLabel.textContent = Math.round(camera.zoom * 100) + '%';
  paintGrid();
  refreshArrowLabelPlates();
  emit('camera');
}

/* Точки-разделители — фон холста, а не элементы. Чтобы они ехали и росли
   вместе со схемой, шаг и начало отсчёта считаются от камеры.
   Точка всегда одна и та же — один пиксель экрана (в градиенте это радиус
   в половину пикселя), меняется только яркость: чем сильнее отдалили, тем
   тише сетка, ведь частые точки рябят. На 50 % они вдвое тише, чем на 100 %,
   а ниже 15 % их нет совсем. Насколько заметны точки вообще, человек решает
   сам — ползунком «Заметность точек сетки» в настройках холста. */
function paintGrid() {
  const step = gridStep() * camera.zoom;
  // До 100 % точка — ровно пиксель экрана; дальше чуть крупнее, вместе со схемой.
  const dot = camera.zoom < 1 ? 0.5 : Math.min(1, 0.65 + (camera.zoom - 1) * 0.25);
  const fade = Math.min(1, Math.max(0, Math.min(1, (camera.zoom - 0.15) / 0.8)) * 0.7 * gridInk());

  wrap.style.setProperty('--grid-step', step + 'px');
  wrap.style.setProperty('--grid-step-x', (step * 2 * ISO_A) + 'px');
  wrap.style.setProperty('--grid-step-y', (step * 2 * ISO_B) + 'px');
  wrap.style.setProperty('--iso-grid-x', camera.x + 'px');
  wrap.style.setProperty('--iso-grid-y', camera.y + 'px');
  wrap.style.setProperty('--grid-dot', dot + 'px');
  wrap.style.setProperty('--grid-x', (camera.x % step) + 'px');
  wrap.style.setProperty('--grid-y', (camera.y % step) + 'px');
  wrap.style.setProperty('--grid-fade', String(Math.round(fade * 100)));
  // Красим не по --line (он почти в цвет фона), а по --ink-faint: его видно.
  wrap.style.setProperty('--grid-ink',
    `color-mix(in srgb, var(--ink-faint) ${Math.round(fade * 100)}%, transparent)`);
  document.body.classList.toggle('no-grid', !settings.grid || fade === 0);
}

/** Объёмный вид: включается кнопкой в шапке, запоминается в этом браузере. */
export function toggleIso(force) {
  if (!wrap) return;
  const center = { x: wrap.clientWidth / 2, y: wrap.clientHeight / 2 };
  const point = unproject((center.x-camera.x)/camera.zoom,(center.y-camera.y)/camera.zoom,0,camera.iso);
  camera.iso = force === undefined ? !camera.iso : !!force;
  state.iso = camera.iso;
  const next = project(point.x,point.y,0,camera.iso);
  camera.x=center.x-next.x*camera.zoom;
  camera.y=center.y-next.y*camera.zoom;
  try { localStorage.setItem('goblin-iso', camera.iso ? '1' : '0'); } catch {}
  draw();
  applyCamera();
  rememberCamera();
  emit('projection');
  emit('panel');
}

export function toCanvas(clientX, clientY) {
  const rect = wrap.getBoundingClientRect();
  const x = (clientX - rect.left - camera.x) / camera.zoom;
  const y = (clientY - rect.top - camera.y) / camera.zoom;
  return unproject(x,y,0,camera.iso);
}

export function toScreen(x,y,z=0) {
  const p=project(x,y,z,camera.iso);
  return { x:camera.x+p.x*camera.zoom,y:camera.y+p.y*camera.zoom };
}

let saveTimer = null;

/** Запоминаем не чаще раза в полсекунды: камера двигается каждый кадр. */
function cameraMoved() {
  clearTimeout(saveTimer);
  saveTimer = setTimeout(rememberCamera, 500);
}

export function zoomBy(factor, at) {
  const point = at || { x: wrap.clientWidth / 2, y: wrap.clientHeight / 2 };
  const before = { x: (point.x - camera.x) / camera.zoom, y: (point.y - camera.y) / camera.zoom };
  camera.zoom = Math.min(3, Math.max(0.15, camera.zoom * factor));
  camera.x = point.x - before.x * camera.zoom;
  camera.y = point.y - before.y * camera.zoom;
  applyCamera();
  cameraMoved();
}

/**
 * Плавный шаг масштаба: им работают кнопки «+» и «−».
 * Скачок в полтора раза заметнее мелких ступенек, но прыгать целиком нельзя —
 * теряется место, поэтому доезжаем за несколько кадров.
 */
let zoomRide = null;
export function zoomStep(factor, ms = 180) {
  const point = { x: wrap.clientWidth / 2, y: wrap.clientHeight / 2 };
  const from = camera.zoom;
  const to = Math.min(3, Math.max(0.15, from * factor));
  if (to === from) return;

  if (zoomRide) cancelAnimationFrame(zoomRide);
  const started = performance.now();
  const ease = (t) => 1 - Math.pow(1 - t, 3);
  const ride = (now) => {
    const part = Math.min(1, (now - started) / ms);
    const zoom = from + (to - from) * ease(part);
    // Точка под курсором мыши остаётся на месте: зумим относительно середины.
    const before = { x: (point.x - camera.x) / camera.zoom, y: (point.y - camera.y) / camera.zoom };
    camera.zoom = zoom;
    camera.x = point.x - before.x * camera.zoom;
    camera.y = point.y - before.y * camera.zoom;
    applyCamera();
    if (part < 1) { zoomRide = requestAnimationFrame(ride); return; }
    zoomRide = null;
    cameraMoved();
  };
  zoomRide = requestAnimationFrame(ride);
}

export function panBy(dx, dy) {
  camera.x += dx;
  camera.y += dy;
  applyCamera();
  cameraMoved();
}

/* ── Камера переживает обновление страницы ──────────────────────
   Человек навёл масштаб и место, обновил страницу — и всё осталось как было.
   Помним по папке: у каждой схемы своё удобное положение. */

const CAMERA_STORE = 'goblin-camera';
let activeCameraKey = '';

function cameraKey() {
  return state.folder ? String(state.folder.id) : '';
}

export function rememberCamera() {
  clearTimeout(saveTimer);
  saveTimer = null;
  // state.folder уже может указывать на следующую папку; камера ещё от прежней.
  const key = activeCameraKey;
  if (!key) return;
  if (![camera.x, camera.y, camera.zoom].every(Number.isFinite)) return;
  try {
    const stored = JSON.parse(localStorage.getItem(CAMERA_STORE) || '{}');
    const all = stored && typeof stored === 'object' && !Array.isArray(stored) ? stored : {};
    all[key] = { x: camera.x, y: camera.y, zoom: camera.zoom, savedAt: Date.now() };
    // Числовые ключи Object.keys сортируются по номеру, а не по времени записи.
    // Оставляем текущую папку и ещё девятнадцать недавно открывавшихся.
    const others = Object.keys(all).filter((id) => id !== key)
      .sort((a, b) => (Number(all[b]?.savedAt) || 0) - (Number(all[a]?.savedAt) || 0));
    for (const old of others.slice(19)) delete all[old];
    localStorage.setItem(CAMERA_STORE, JSON.stringify(all));
  } catch {}
}

/** Вернуть запомненное. Нечего возвращать — false, и холст покажет всё сам. */
export function restoreCamera() {
  const key = cameraKey();
  if (!key) return false;
  // Сначала дописываем предыдущую камеру, затем переходим к новой папке.
  if (zoomRide) { cancelAnimationFrame(zoomRide); zoomRide = null; }
  if (activeCameraKey) rememberCamera();
  activeCameraKey = key;
  try {
    const saved = JSON.parse(localStorage.getItem(CAMERA_STORE) || '{}')[key];
    if (!saved || ![saved.x, saved.y, saved.zoom].every(Number.isFinite)) return false;
    camera.x = saved.x;
    camera.y = saved.y;
    camera.zoom = Math.min(3, Math.max(0.15, saved.zoom));
    applyCamera();
    return true;
  } catch { return false; }
}

/**
 * Навести холст на один объект: он встаёт в середину экрана.
 * Масштаб берём такой, чтобы объект был виден целиком и не занимал
 * весь экран — мелочь не раздуваем, огромную рамку ужимаем.
 */
export function focusOn(element) {
  if (!element || element.type === 'arrow') return;
  const {x,y,w,h}=projectedBounds(element,camera.iso);
  camera.zoom = fitZoom(element);
  camera.x = wrap.clientWidth / 2 - (x + w / 2) * camera.zoom;
  camera.y = wrap.clientHeight / 2 - (y + h / 2) * camera.zoom;
  applyCamera();
  cameraMoved();
}

/** Масштаб, при котором объект виден целиком с полями: мелочь не раздуваем, огромное ужимаем. */
export function fitZoom(element) {
  const { w, h } = projectedBounds(element, camera.iso);
  const pad = 160;
  return Math.min(1.4, Math.max(0.2, Math.min(wrap.clientWidth / (w + pad), wrap.clientHeight / (h + pad))));
}

let flight = 0;   // номер полёта: новый полёт гасит прежний

/**
 * Плавно подлететь к объекту: масштаб `zoom` и объект в середине экрана.
 * Масштаб и сдвиг меняются вместе, с замедлением к концу — видно,
 * откуда и куда перенесло. Так переходят по ярлыку.
 */
export function flyTo(element, zoom = 0.8, ms = 650) {
  if (!element || element.type === 'arrow') return;
  const { x, y, w, h } = projectedBounds(element, camera.iso);
  const from = { x: camera.x, y: camera.y, zoom: camera.zoom };
  const to = {
    zoom,
    x: wrap.clientWidth / 2 - (x + w / 2) * zoom,
    y: wrap.clientHeight / 2 - (y + h / 2) * zoom,
  };
  const mine = ++flight;
  const began = performance.now();
  const ease = (t) => 1 - Math.pow(1 - t, 3);

  const step = (now) => {
    if (mine !== flight) return;
    const t = Math.min(1, (now - began) / ms);
    const k = ease(t);
    camera.zoom = from.zoom + (to.zoom - from.zoom) * k;
    camera.x = from.x + (to.x - from.x) * k;
    camera.y = from.y + (to.y - from.y) * k;
    applyCamera();
    if (t < 1) requestAnimationFrame(step);
    else cameraMoved();
  };
  requestAnimationFrame(step);
}

/** Показать всё содержимое папки. */
export function fit() {
  const boxes = [...state.elements.values()].filter((e) => e.type !== 'arrow' && !isHiddenByCollapse(e))
    .map(e=>projectedBounds(e,camera.iso));
  if (!boxes.length) { camera.x = 120; camera.y = 80; camera.zoom = 1; applyCamera(); cameraMoved(); return; }
  const left = Math.min(...boxes.map(s=>s.x));
  const top = Math.min(...boxes.map(s=>s.y));
  const right = Math.max(...boxes.map(s=>s.x+s.w));
  const bottom = Math.max(...boxes.map(s=>s.y+s.h));

  const pad = 80;
  const zoomX = wrap.clientWidth / (right - left + pad * 2);
  const zoomY = wrap.clientHeight / (bottom - top + pad * 2);
  camera.zoom = Math.min(1.2, Math.max(0.15, Math.min(zoomX, zoomY)));
  camera.x = wrap.clientWidth/2-(left+right)/2*camera.zoom;
  camera.y = wrap.clientHeight/2-(top+bottom)/2*camera.zoom;
  applyCamera();
  cameraMoved();
}

/* Колесо и трекпад.
   Щипок двумя пальцами браузер шлёт как колесо с ctrlKey и мелкими шагами —
   поэтому масштаб считается плавной кривой, а не рывком в 10%.
   Выключено «двигать двумя пальцами» — прокрутка начинает менять масштаб. */
function onWheel(event) {
  const unit = event.deltaMode === 1 ? 16 : event.deltaMode === 2 ? 100 : 1;
  const dx = event.deltaX * unit;
  const dy = event.deltaY * unit;
  const speed = Number(settings.zoomSpeed) || 1;
  const pinch = event.ctrlKey || event.metaKey;

  if (pinch) {
    if (!settings.pinchZoom) return;
    event.preventDefault();
    zoomAt(Math.exp(-dy * 0.01 * speed), event);
    return;
  }
  event.preventDefault();
  if (settings.trackpadPan) { panBy(-dx, -dy); return; }
  zoomAt(Math.exp(-dy * 0.0015 * speed), event);
}

function zoomAt(factor, event) {
  const rect = wrap.getBoundingClientRect();
  zoomBy(factor, { x: event.clientX - rect.left, y: event.clientY - rect.top });
}

/* ── Рисование ───────────────────────────────────────────────── */

/** Полная пересборка — только при смене папки. */
export function draw() {
  layerElements.textContent = '';
  layerArrows.textContent = '';
  nodes.clear();

  const list = [...state.elements.values()];
  // Сначала подложки: область и группа лежат под блоками.
  // Схлопнутая группа — наоборот, поверх: она заменяет собой содержимое.
  const order = { area: 0, group: 1, note: 2, block: 3, decision: 3, gateway: 3 };
  list.filter((e) => e.type !== 'arrow')
      .sort((a, b) => (order[a.type] ?? 3) - (order[b.type] ?? 3))
      .forEach(add);
  list.filter((e) => e.type === 'arrow').forEach(add);
  scheduleDepth();
  paintLinks();                      // узлы новые — отростки ярлыков на них заново

  document.getElementById('empty-hint').hidden = list.length > 0;
  notePaint();                       // первый снимок подсветки — сразу при отрисовке
}

function add(element) {
  const node = element.type === 'arrow' ? drawArrow(element) : drawElement(element);
  if (!node) return;
  nodes.set(element.id, node);
  (element.type === 'arrow' ? layerArrows : layerElements).append(node);
  if (state.iso && element.type !== 'arrow') updateElement(node, element, stepOf(element));
}

/** Перерисовать один элемент и стрелки, которые его держат. */
export function drawOne(element) {
  const node = nodes.get(element.id);
  if (!node) { add(element); }
  else if (element.type === 'arrow') updateArrow(node, element);
  else updateElement(node, element);
  if (element.type !== 'arrow') paintLinksOf(element.id);

  if (ARROW_ROUTER !== 'legacy') {
    redrawRoutes();
  } else if (element.type !== 'arrow') {
    for (const arrow of state.elements.values()) {
      if (arrow.type === 'arrow' && (arrow.from === element.id || arrow.to === element.id)) {
        const arrowNode = nodes.get(arrow.id);
        if (arrowNode) updateArrow(arrowNode, arrow);
      }
    }
  }
  document.getElementById('empty-hint').hidden = state.elements.size > 0;
  scheduleDepth();
}

export function dropOne(id) {
  const node = nodes.get(id);
  if (node) node.remove();
  nodes.delete(id);
  if (ARROW_ROUTER !== 'legacy') redrawRoutes();
  scheduleDepth();
}

let depthFrame=null;
function scheduleDepth() {
  if (!state.iso || depthFrame!==null) return;
  depthFrame=requestAnimationFrame(()=>{
    depthFrame=null;if(!state.iso)return;
    const list=[...state.elements.values()].filter(e=>e.type!=='arrow'&&!isHiddenByCollapse(e));
    const distances=list.map(e=>{const s=e.style||{};return (Number(s.x)||0)+(Number(s.y)||0)});
    const far=Math.min(...distances),near=Math.max(...distances),span=Math.max(1,near-far);
    list.forEach((e,i)=>nodes.get(e.id)?.style.setProperty('--iso-distance-shade',String(.11*(near-distances[i])/span)));
    depthOrder(list).forEach((e,i)=>{const node=nodes.get(e.id);if(node)node.style.zIndex=String(1000+i);});
    emit('camera');
  });
}

/** Чужой блок тоже препятствие: пересчитываются все связанные маршруты. */
function redrawRoutes() {
  for (const arrow of state.elements.values()) {
    if (arrow.type !== 'arrow') continue;
    const node = nodes.get(arrow.id);
    if (node) updateArrow(node, arrow);
  }
}

/**
 * Сервер вернул настоящий id вместо временного — узел остаётся тот же.
 * Иначе карточку пересобирало бы прямо под руками: у только что поставленного
 * элемента правят название, и перерисовка стирала бы набранное.
 */
export function rekeyNode(oldId, newId) {
  const node = nodes.get(oldId);
  if (!node) return;
  nodes.delete(oldId);
  node.dataset.id = newId;
  nodes.set(newId, node);
}

export function nodeOf(id) { return nodes.get(id); }

/** Прогон сменился или пришли новые шаги: обновляем подсветку, не трогая остальное. */
function repaintSteps() {
  for (const element of state.elements.values()) {
    const node = nodes.get(element.id);
    if (!node) continue;
    if (element.type === 'arrow') updateArrow(node, element);
    else updateElement(node, element, stepOf(element));
  }
  notePaint();
}

/*
 * Журнал подсветки: что горело в эту секунду. Пишется в память страницы
 * (`window.__подсветка`), потому что прогон меняется быстрее, чем человек
 * успевает смотреть, а разбирать потом надо по мгновениям, а не по итогу.
 */
const paintLog = [];
window.__подсветка = paintLog;

function notePaint() {
  if (!state.run) return;
  const cards = [];
  const arrows = [];
  for (const element of state.elements.values()) {
    const node = nodes.get(element.id);
    if (!node) continue;
    if (element.type === 'arrow') {
      const line = node.querySelector('.arrow');
      const mark = line ? line.getAttribute('class').replace('arrow', '').trim() : '';
      if (mark) arrows.push(`${element.no}:${mark}`);
      continue;
    }
    if (node.dataset.step) cards.push(`${element.no}:${node.dataset.step}`);
  }
  const shot = { когда: new Date().toLocaleTimeString('ru-RU'), карточки: cards.join(' '), стрелки: arrows.join(' ') };
  const last = paintLog[paintLog.length - 1];
  if (last && last.карточки === shot.карточки && last.стрелки === shot.стрелки) return;
  paintLog.push(shot);
  if (paintLog.length > 300) paintLog.shift();
}
