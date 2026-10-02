/* Камера мобильного скина: «показать схему» так, чтобы названия читались.
   Отдаёт: initMobileCamera(), readableFit().
   Не делает: не заменяет камеру холста — двигает ту же (canvas/view.js).

   На компьютере «показать всё» ужимает схему в экран целиком. Телефон
   горизонтально низкий, и длинная схема становилась россыпью точек.
   Здесь масштаб не мельче READABLE: схема влезла — встаёт по центру,
   не влезла — встаёт от своего начала (левый верхний угол), дальше её листают. */

import { state, on } from 'goblin/core/state.js';
import { camera, applyCamera, rememberCamera } from 'goblin/canvas/view.js';
import { projectedBounds } from 'goblin/canvas/projection.js';
import { isHiddenByCollapse } from 'goblin/core/containers.js';
import { isMobile } from 'goblin/mobile/drawer.js';

const READABLE = 0.6;     // мельче названия на телефоне не читаются
const MOST = 1.2;
const PAD = 40;

let ready = false;        // холст собран: событие 'variant' приходит после его сборки

export function initMobileCamera() {
  on('variant', (name) => { ready = name === 'canvas'; });

  // Кнопка ⤢ у масштаба: в мобильном скине — читаемый вид, а не «всё в точку».
  document.getElementById('btn-fit')?.addEventListener('click', (event) => {
    if (!isMobile()) return;
    event.stopImmediatePropagation();
    readableFit();
  }, true);

  // Папку открыли впервые на этом устройстве — холст сам ужал бы её целиком.
  on('folder', () => { if (isMobile() && !savedCamera()) setTimeout(readableFit); });
}

/** Камера этой папки уже запомнена (canvas/view.js, goblin-camera). */
export function savedCamera() {
  try { return !!JSON.parse(localStorage.getItem('goblin-camera') || '{}')[String(state.folder?.id)]; }
  catch { return false; }
}

export function readableFit() {
  if (!ready) return;
  const wrap = document.getElementById('canvas-wrap');
  const boxes = [...state.elements.values()]
    .filter((e) => e.type !== 'arrow' && !isHiddenByCollapse(e))
    .map((e) => projectedBounds(e, camera.iso));
  if (!wrap || !boxes.length) return;

  const left = Math.min(...boxes.map((b) => b.x));
  const top = Math.min(...boxes.map((b) => b.y));
  const right = Math.max(...boxes.map((b) => b.x + b.w));
  const bottom = Math.max(...boxes.map((b) => b.y + b.h));
  const W = wrap.clientWidth;
  const H = wrap.clientHeight;

  const whole = Math.min((W - PAD * 2) / (right - left), (H - PAD * 2) / (bottom - top));
  camera.zoom = Math.min(MOST, Math.max(READABLE, whole));
  // По каждой оси: влезла — по центру, нет — от начала схемы.
  camera.x = (right - left) * camera.zoom <= W - PAD * 2
    ? W / 2 - (left + right) / 2 * camera.zoom : PAD - left * camera.zoom;
  camera.y = (bottom - top) * camera.zoom <= H - PAD * 2
    ? H / 2 - (top + bottom) / 2 * camera.zoom : PAD - top * camera.zoom;
  applyCamera();
  rememberCamera();
}
