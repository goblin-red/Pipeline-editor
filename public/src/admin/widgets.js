/* Админка: столбики по дням, шторка подробностей, сводные карточки, пары «название — значение».
   Отдаёт: bars(), openDrawer(), closeDrawer(), kpi(), kv(), panel(), head(), sub().
   Не делает: не ходит на сервер. */

import { el, fmt } from 'goblin/admin/core.js';
import { t } from 'goblin/core/i18n.js';

const SVG = 'http://www.w3.org/2000/svg';

/** Столбики по дням: series = [{day, n}]. Подсказка на столбике — дата и число. */
export function bars(series, { unit = '' } = {}) {
  const width = 600, height = 110, gap = 3;
  const max = Math.max(1, ...series.map((s) => s.n));
  const w = (width - gap * (series.length - 1)) / series.length;
  const svg = document.createElementNS(SVG, 'svg');
  svg.setAttribute('viewBox', `0 0 ${width} ${height}`);
  svg.setAttribute('preserveAspectRatio', 'none');
  svg.classList.add('adm-chart');
  series.forEach((s, i) => {
    const h = s.n ? Math.max(3, (s.n / max) * (height - 4)) : 2;
    const rect = document.createElementNS(SVG, 'rect');
    rect.setAttribute('x', String(i * (w + gap)));
    rect.setAttribute('y', String(height - h));
    rect.setAttribute('width', String(w));
    rect.setAttribute('height', String(h));
    rect.setAttribute('rx', '2');
    if (!s.n) rect.classList.add('zero');
    const tip = document.createElementNS(SVG, 'title');
    tip.textContent = `${fmt.day(s.day)}: ${fmt.num(s.n)}${unit}`;
    rect.append(tip);
    svg.append(rect);
  });
  const total = series.reduce((sum, s) => sum + s.n, 0);
  return el('div', 'adm-chart-box', [
    el('div', 'adm-chart-total', fmt.num(total)),
    svg,
    el('div', 'adm-chart-legend', [el('span', '', fmt.day(series[0]?.day)), el('span', '', t('admin.widgets.today'))]),
  ]);
}

/** Карточка-число: kpi(число, подпись, пояснение, по щелчку). */
export function kpi(value, label, hint = '', onClick = null, accent = false) {
  const card = el(onClick ? 'button' : 'div', 'adm-kpi' + (accent ? ' accent' : ''),
    [el('b', '', typeof value === 'number' ? fmt.num(value) : value), el('span', '', label), hint ? el('small', '', hint) : null]);
  if (onClick) card.onclick = onClick;
  return card;
}

export function panel(title, content, note = '') {
  return el('section', 'adm-panel', [el('h3', '', [title, note ? el('small', '', note) : null]), ...[].concat(content)]);
}

/** Шапка раздела: заголовок, пояснение, кнопки справа. */
export function head(title, text = '', actions = []) {
  return el('header', 'adm-head', [el('div', '', [el('h1', '', title), text ? el('p', '', text) : null]),
    actions.length ? el('div', 'adm-actions', actions) : null]);
}

export const sub = (text) => el('h2', 'adm-sub', text);

/** Пары «название — значение» (значение — текст или узел). */
export function kv(pairs) {
  const list = el('dl', 'adm-kv');
  for (const [key, value] of Object.entries(pairs)) {
    if (value === undefined) continue;
    list.append(el('dt', '', key), el('dd', '', value instanceof Node ? value : String(value ?? '—')));
  }
  return list;
}

/* ── Шторка подробностей ─────────────────────────────────────── */

let current = null;

/** Открыть шторку справа. Содержимое — узел; onClose — когда закрыли. */
export function openDrawer({ title, text = '', body, onClose = null }) {
  closeDrawer(true);
  const close = el('button', 'dialog-x', '×');
  const shade = el('div', 'adm-shade');
  const drawer = el('aside', 'adm-drawer', [
    el('header', 'adm-drawer-head', [el('div', '', [el('h2', '', title), text ? el('p', '', text) : null]), close]),
    el('div', 'adm-drawer-body', body),
  ]);
  current = { shade, drawer, onClose };
  close.onclick = () => closeDrawer();
  shade.onclick = () => closeDrawer();
  document.body.append(shade, drawer);
  return drawer;
}

export function closeDrawer(silent = false) {
  if (!current) return;
  const { shade, drawer, onClose } = current;
  current = null;
  shade.remove();
  drawer.remove();
  if (!silent && onClose) onClose();
}

document.addEventListener('keydown', (event) => {
  if (event.key === 'Escape' && current && !document.querySelector('.adm-modal')) closeDrawer();
});
