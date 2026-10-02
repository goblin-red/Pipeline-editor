/* Вид «Холст»: схема рисуется мышью — то, ради чего Гоблин и заводился.
   Отдаёт: mount(), unmount().
   Не делает: ничего своего с данными — только показывает холст и жесты. */

import { initView, draw, fit, rememberCamera, restoreCamera } from 'goblin/canvas/view.js';
import { initPointer } from 'goblin/edit/pointer.js';
import { initPlacing } from 'goblin/edit/place.js';
import { initVolumeControls } from 'goblin/edit/volume-controls.js';
import { currentLook, setLook } from 'goblin/canvas/looks.js';

let ready = false;

export function mount() {
  document.getElementById('canvas-wrap').hidden = false;
  document.getElementById('rail').hidden = false;
  setLook(currentLook());
  if (!ready) {
    initView();
    initPointer();
    initPlacing();
    initVolumeControls();
    ready = true;
  }
  draw();
  // Масштаб и место, наведённые в прошлый раз, важнее «показать всё».
  if (!restoreCamera()) fit();
}

export function unmount() {
  rememberCamera();
  document.getElementById('canvas-wrap').hidden = true;
}
