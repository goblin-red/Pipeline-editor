/* Подсказки: встают под значком сразу, без задержки браузера.
   Отдаёт: initTips().
   Не делает: текста не придумывает — берёт его из title того, на что навели.

   Системная подсказка ждёт около секунды и выскакивает у курсора: по ряду
   значков в шапке так не пробежишься — пока ждёшь, уже ушёл. Поэтому при
   наведении title переезжает в data-tip (иначе браузер покажет свою поверх
   нашей), а рисуем мы одну табличку на всю страницу — ровно под значком.

   Карточки холста не трогаем: там подсказка выскакивала бы на каждом шаге
   мыши и закрывала бы схему.

   В открытых окнах (dialog) title тоже не трогаем: модальное окно живёт в верхнем
   слое, и табличка из body под ним не видна — там подсказку покажет сам браузер. */

let tip = null;
let shown = null;

export function initTips() {
  if (tip) return;

  tip = document.createElement('div');
  tip.className = 'tip';
  tip.hidden = true;
  document.body.append(tip);

  document.addEventListener('pointerover', onOver, true);
  document.addEventListener('pointerout', onOut, true);
  document.addEventListener('pointerdown', hide, true);
  document.addEventListener('wheel', hide, { capture: true, passive: true });
  window.addEventListener('blur', hide);
}

function onOver(event) {
  const host = event.target?.closest?.('[title], [data-tip]');
  if (!host) { hide(); return; }
  // Схема живёт своей жизнью: на карточках подсказки не показываем.
  if (host.closest('#canvas')) { hide(); return; }
  // Окно поверх страницы: своя табличка оказалась бы под ним, title остаётся браузеру.
  if (host.closest('dialog[open]')) { hide(); return; }
  if (host === shown) return;
  show(host);
}

function onOut(event) {
  if (!shown) return;
  const to = event.relatedTarget;
  if (to && shown.contains(to)) return;
  hide();
}

function show(host) {
  // Свой title браузер показал бы поверх нашей таблички — забираем его себе.
  if (host.hasAttribute('title')) {
    const text = host.getAttribute('title').trim();
    host.dataset.tip = text;
    if (!host.getAttribute('aria-label')) host.setAttribute('aria-label', text);
    host.removeAttribute('title');
  }

  const text = (host.dataset.tip || '').trim();
  if (!text) { hide(); return; }

  tip.textContent = text;
  tip.hidden = false;
  shown = host;
  place(host);
}

/* Под значком и по его середине. Не влезает снизу или с краю — сдвигаем,
   но остаёмся привязанными к значку: подсказка у курсора уже была. */
function place(host) {
  const box = host.getBoundingClientRect();
  const size = tip.getBoundingClientRect();
  const gap = 6;

  let left = box.left + box.width / 2 - size.width / 2;
  left = Math.min(Math.max(8, left), window.innerWidth - size.width - 8);

  let top = box.bottom + gap;
  if (top + size.height > window.innerHeight - 8) top = box.top - size.height - gap;

  tip.style.left = Math.round(left) + 'px';
  tip.style.top = Math.round(top) + 'px';
}

function hide() {
  if (!tip || tip.hidden) { shown = null; return; }
  tip.hidden = true;
  shown = null;
}
