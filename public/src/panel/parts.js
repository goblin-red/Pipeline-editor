/* Из чего собрана правая панель: поля, кнопки, раскрывающиеся секции.
   Отдаёт: el(), wrap(), openSection(), field(), pick(), input(), textarea(), numberField(),
           checkbox(), button(), small(), kv(), label(), note(), escape(), escapeAttr().
   Не делает: ничего не знает о схеме — только рисует управление.

   Всё в одном месте, потому что этим пользуются все экраны панели: свойства
   элемента, набор выделенных, папка и проект. */

import { state } from 'goblin/core/state.js';
import { t } from 'goblin/core/i18n.js';

export const el = (tag, cls = '', text = '') => {
  const node = document.createElement(tag);
  if (cls) node.className = cls;
  if (text) node.textContent = text;
  return node;
};

export const label = (text) => {
  const node = el('span');
  node.textContent = text;
  node.style.cssText = 'display:block;font-size:11px;text-transform:uppercase;'
    + 'letter-spacing:.04em;color:var(--ink-dim);margin:8px 0 3px';
  return node;
};

export const note = (text) => {
  const node = el('p', 'muted');
  node.textContent = text;
  node.style.fontSize = '12.5px';
  return node;
};

/* Что человек раскрыл, то и остаётся раскрытым — и после обновления страницы
   тоже. Панель перерисовывается часто (правка поля, шаг прогона, переключатель
   «превью / список»), и без этой памяти секции захлопывались под руками.
   Ключ — заголовок без чисел, чтобы «Шаг 4.1» и «Шаг 4.2» были одной секцией. */
const SECTIONS_STORE = 'goblin-panel-sections';
const opened = new Map(readSections());
const sectionKey = (title) => title.replace(/[\d.]+/g, '').trim();

function readSections() {
  try { return Object.entries(JSON.parse(localStorage.getItem(SECTIONS_STORE) || '{}')); }
  catch { return []; }
}

/** Раскрыть секцию по её заголовку — например, когда на неё указали с холста. */
export function openSection(title) {
  rememberSection(sectionKey(title), true);
}

function rememberSection(key, open) {
  opened.set(key, open);
  try { localStorage.setItem(SECTIONS_STORE, JSON.stringify(Object.fromEntries(opened))); } catch {}
}

export function wrap(title, children, open = false, count = null) {
  const section = el('details', 'section');
  if (!title) {
    section.open = true;
  } else {
    const key = sectionKey(title);
    section.open = opened.get(key) ?? open;
    section.addEventListener('toggle', () => rememberSection(key, section.open));
    const summary = el('summary');
    summary.textContent = title;
    if (count !== null) { const b = el('b'); b.textContent = count; summary.append(b); }
    section.append(summary);
  }
  const inner = el('div', 'section-body');
  inner.append(...children.filter(Boolean));
  section.append(inner);
  return section;
}

export function field(name, control) {
  const wrapper = el('label', 'field');
  const title = el('span');
  title.textContent = name;
  wrapper.append(title, control);
  return wrapper;
}

/** Свернуть раскрытую плашку выбора. */
function pickShut(box) {
  box.classList.remove('open');
  box.querySelector('.pick-list').style.maxHeight = '0px';
}

/* Щелчок мимо раскрытой плашки сворачивает её. Слушатель один на все плашки: свой у каждой
   оставался бы на document навсегда — панель перерисовывается часто и плашки выбрасывает. */
document.addEventListener('pointerdown', (event) => {
  for (const box of document.querySelectorAll('.pick.open')) {
    if (!box.contains(event.target)) pickShut(box);
  }
});

/**
 * Плашка выбора: закрытая показывает выбранное, по щелчку плавно раскрывается
 * списком. Обычный select в тёмной теме выглядит чужим и не умеет цветных
 * строк, а цвет инструмента здесь — половина смысла.
 *
 * Снаружи ведёт себя как select: `.value`, `.onchange`, `.disabled`,
 * плюс `.setRows()` для списков, которые зависят от другого выбора.
 */
export function pick(rows, value, empty, emptyWord = t('editor.panel.not_set')) {
  const box = document.createElement('div');
  box.className = 'pick';

  const head = document.createElement('button');
  head.type = 'button';
  head.className = 'pick-head';

  const list = document.createElement('div');
  list.className = 'pick-list';
  box.append(head, list);

  let all = rows || [];
  let now = value || '';
  let onchange = null;

  // Кружок цвета нужен там, где цвет есть смысл: в списке без цветов
  // он висел бы пустым колечком у каждой строки.
  const tinted = () => all.some((item) => item.color);

  const paint = () => {
    const row = all.find((item) => item.key === now);
    head.innerHTML = (tinted() ? `<i class="cli-dot" style="background:${row?.color ? `var(--c-${row.color})` : 'transparent'}"></i>` : '')
      + `<span>${escape(row ? (row.label || row.key) : '— ' + emptyWord + ' —')}</span>`
      + '<b class="pick-arrow">⌄</b>';
  };

  const draw = () => {
    list.textContent = '';
    const items = empty ? [{ key: '', label: '— ' + emptyWord + ' —' }, ...all] : all;
    for (const row of items) {
      const item = document.createElement('button');
      item.type = 'button';
      item.className = 'pick-item' + (row.key === now ? ' on' : '');
      if (row.color) item.style.setProperty('--tint', `var(--c-${row.color})`);
      item.innerHTML = (tinted() ? `<i class="cli-dot" style="background:${row.color ? `var(--c-${row.color})` : 'transparent'}"></i>` : '')
        + `<span>${escape(row.label || row.key)}</span>`;
      item.onclick = () => {
        now = row.key;
        paint();
        draw();
        open(false);
        if (onchange) onchange();
      };
      list.append(item);
    }
  };

  const open = (on) => {
    box.classList.toggle('open', on);
    list.style.maxHeight = on ? Math.min(260, list.scrollHeight) + 'px' : '0px';
  };
  head.onclick = () => {
    if (box.classList.contains('off')) return;
    const next = !box.classList.contains('open');
    for (const other of document.querySelectorAll('.pick.open')) if (other !== box) pickShut(other);
    open(next);
  };

  Object.defineProperties(box, {
    value: { get: () => now, set: (next) => { now = next || ''; paint(); draw(); } },
    disabled: { get: () => box.classList.contains('off'),
                set: (off) => { box.classList.toggle('off', !!off); if (off) open(false); } },
    onchange: { get: () => onchange, set: (fn) => { onchange = fn; } },
  });
  box.setRows = (next) => { all = next || []; paint(); draw(); };

  paint();
  draw();
  open(false);
  return box;
}

export function input(value, onChange) {
  const node = el('input', 'input');
  node.value = value;
  node.disabled = state.viewOnly;
  node.onchange = () => onChange(node.value);
  return node;
}

export function textarea(value, onChange) {
  const node = el('textarea', 'input');
  node.value = value;
  node.disabled = state.viewOnly;
  node.onchange = () => onChange(node.value);
  return node;
}

export function numberField(name, value, onChange) {
  const node = el('input', 'input');
  node.type = 'number';
  node.value = value;
  node.disabled = state.viewOnly;
  node.onchange = () => onChange(Number(node.value));
  return field(name, node);
}

export function checkbox(text, checked, onChange, hint) {
  const wrapper = el('label', 'row');
  wrapper.style.margin = '8px 0';
  const node = el('input');
  node.type = 'checkbox';
  node.checked = !!checked;
  node.disabled = state.viewOnly;
  node.onchange = () => onChange(node.checked);
  // Подпись рядом с флажком и переносится сама (английская длиннее), пояснение — строкой ниже.
  const label = el('span', '', text);
  label.style.flex = '1 1 0';
  label.style.minWidth = '0';
  wrapper.append(node, label);
  if (hint) {
    const line = note(hint);
    line.style.flexBasis = '100%';
    wrapper.append(line);
    wrapper.style.flexWrap = 'wrap';
  }
  return wrapper;
}

export function button(text, onClick) {
  const node = el('button', 'btn btn-quiet btn-wide', text);
  node.disabled = state.viewOnly;
  node.onclick = onClick;
  return node;
}

export function small(text, onClick) {
  const node = el('button', 'btn btn-quiet btn-small', text);
  node.disabled = state.viewOnly;
  node.onclick = onClick;
  return node;
}

export function kv(pairs) {
  const list = el('dl', 'kv');
  for (const [key, value] of Object.entries(pairs)) {
    if (value === '' || value === undefined) continue;
    list.append(el('dt', '', key), el('dd', '', String(value)));
  }
  return list;
}

export function escape(text) {
  return String(text).replace(/[&<>]/g, (ch) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;' }[ch]));
}

export function escapeAttr(text) {
  return escape(text).replace(/"/g, '&quot;');
}
