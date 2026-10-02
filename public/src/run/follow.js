/* «Следить за прогоном»: холст сам едет к узлу, который сейчас открылся.
   Отдаёт: ничего — подключается сам при первом импорте (shell/runbar.js).
   Не делает: не выбирает узлы и не меняет масштаб — только плавно сдвигает холст
              (canvas/view.js::flyTo).

   Кнопка «◎ Следить» — рядом с плашкой прогона в углу холста, видна, пока прогон жив.
   Включённое слежение помнит браузер (core/settings.js, runFollow).
   Едем к только что открытому узлу (выдан, в работе, сдан, ждёт — run/paint.js::openBlocks()).
   Открылось несколько сразу — к меньшему номеру. Узел, к которому подлетели, закрылся,
   а новых нет — к любому ещё открытому. */

import { state, on } from 'goblin/core/state.js';
import { settings, setSetting } from 'goblin/core/settings.js';
import { flyTo, camera } from 'goblin/canvas/view.js';
import { openBlocks } from 'goblin/run/paint.js';

const LIVE = new Set(['running', 'paused']);
let followed = null;    // к какому узлу подлетели
let seen = new Set();   // какие узлы были открыты при прошлой картине

on('run', () => { followed = null; seen = new Set(); drawButton(); });
on('runstate', drawButton);
on('paint', follow);
on('settings', (name) => {
  if (name !== null && name !== 'runFollow') return;
  followed = null;
  drawButton();
  follow();
});
drawButton();

/** Кнопка видна, пока прогон жив; нажата — слежение включено. */
function drawButton() {
  const button = document.getElementById('run-follow');
  if (!button) return;
  button.hidden = !LIVE.has(state.run?.state);
  button.setAttribute('aria-pressed', settings.runFollow ? 'true' : 'false');
  button.onclick = () => setSetting('runFollow', !settings.runFollow);
}

/** Открылся новый узел — холст плавно едет к нему; масштаб остаётся тот, что выбрал человек. */
function follow() {
  const open = openBlocks();
  const fresh = open.filter((element) => !seen.has(element.id));
  seen = new Set(open.map((element) => element.id));

  if (!settings.runFollow || !LIVE.has(state.run?.state)) return;
  // Холст спрятан (вид «Прогон») — ехать некуда: размеры экрана нулевые.
  const wrap = document.getElementById('canvas-wrap');
  if (!wrap || wrap.hidden || !wrap.clientWidth) return;

  const still = open.some((element) => element.id === followed);
  const element = fresh[0] || (still ? null : open[0]);
  if (!element) return;
  followed = element.id;
  flyTo(element, camera.zoom);
}
