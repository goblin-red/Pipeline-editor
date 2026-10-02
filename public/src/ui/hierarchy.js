/* Вид «Иерархия»: что в чём лежит — колонками, как в проводнике.
   Отдаёт: mount(), unmount().
   Не делает: ничего не правит и на сервер не пишет — только показывает
   то, что уже сложилось на холсте.

   Уровни идут колонками слева направо, элементы внутри уровня — списком
   сверху вниз. Рамка ведёт себя как папка и раскрывается вправо, остальное —
   «файлы»: блок, ромб, шлюз, таблица, пометка. Кто рамка — решает tree.js.

   Щелчок по строке выделяет объект, и правая панель показывает его
   свойства — те же самые, что и при выборе на холсте.

   Строку можно перетащить в папку — хоть на соседний уровень, хоть на
   другой край дерева; выделено несколько — переедут все. Пустое место
   первой колонки выносит объект из всех рамок. Состав и холст меняются
   заодно: правило переезда живёт в `edit/rehome.js`.

   Связи показываются во «Всё сразу» тремя способами: без линий, только у
   выбранного (их можно набрать несколько — Shift или ⌘) и все сразу.
   Номера соседей (← откуда, → куда) появляются в строках, как только связи
   включены: они читаются всегда, даже там, где линию вести некуда.

   Ярлыки в связи не входят: у строки свой значок «↗ N» (ярлыки объекта)
   и «↙ N» (ведут к нему). Щелчок по значку — переход, если ярлык один;
   если их несколько — объект выделяется, и панель открывает «Ярлыки».

   Два способа смотреть:
     компактный — справа открывается только выбранная ветка;
     «всё сразу» — все уровни и все ветки, без единого щелчка. */

import { state, on, emit } from 'goblin/core/state.js';
import { KINDS } from 'goblin/core/kinds.js';
import { FOLDER_ICON } from 'goblin/left/parts.js';
import { isFolder, childrenOf, rootItems, countAll } from 'goblin/core/tree.js';
import { canRehome, rehome } from 'goblin/edit/rehome.js';
import { toast } from 'goblin/shell/topbar.js';
import { linksFrom, linksTo, endName } from 'goblin/edit/links.js';
import { followLink } from 'goblin/canvas/link.js';
import { openSection } from 'goblin/panel/parts.js';
import { t, tn } from 'goblin/core/i18n.js';

const STORE = 'goblin-tree';
const LINKS = 'goblin-tree-links';
const LIMIT = 30;            // столько знаков названия видно в строке

/* Лист рисунком — пара к папке из classic.js. */
const FILE_ICON = '<svg viewBox="0 0 24 20" width="22" height="18" fill="none" stroke="currentColor" stroke-width="1.6">'
  + '<path d="M5 2.5h8l5 5v10a1.5 1.5 0 0 1-1.5 1.5h-11A1.5 1.5 0 0 1 4 17.5v-13A1.5 1.5 0 0 1 5.5 2.5z"/>'
  + '<path d="M13 2.5v5h5"/></svg>';

let root = null;
let off = [];
let chain = [];              // выбранные папки: по одной на колонку
let mode = read();
let links = readLinks();     // связи: none | picked | all
let painting = 0;            // кадр перерисовки стрелок
let dragging = [];           // что тащим сейчас
let aimed = null;            // куда целимся

export function mount() {
  document.getElementById('canvas-wrap').hidden = true;
  document.getElementById('rail').hidden = false;

  root = document.createElement('section');
  root.className = 'ui-tree';
  root.innerHTML = `
    <header class="ui-tree-head">
      <div class="switch" id="tree-mode">
        <button class="switch-item" data-mode="compact">${t('editor.tree.compact')}</button>
        <button class="switch-item" data-mode="wide">${t('editor.tree.wide')}</button>
      </div>
      <div class="switch" id="tree-links">
        <button class="switch-item" data-links="none">${t('editor.tree.links_none')}</button>
        <button class="switch-item" data-links="picked">${t('editor.tree.links_picked')}</button>
        <button class="switch-item" data-links="all">${t('editor.tree.links_all')}</button>
      </div>
      <span class="muted" id="tree-count"></span>
    </header>
    <div class="ui-tree-cols" id="tree-cols"></div>`;
  document.querySelector('.workspace').insertBefore(root, document.getElementById('panel'));

  root.querySelector('#tree-mode').onclick = (event) => {
    const button = event.target.closest('[data-mode]');
    if (!button) return;
    mode = button.dataset.mode;
    try { localStorage.setItem(STORE, mode); } catch {}
    render();
  };

  root.querySelector('#tree-links').onclick = (event) => {
    const button = event.target.closest('[data-links]');
    if (!button) return;
    links = button.dataset.links;
    try { localStorage.setItem(LINKS, links); } catch {}
    render();
  };

  const cols = root.querySelector('#tree-cols');
  // Колонки ездят вбок и вниз — линии связей идут следом.
  cols.addEventListener('scroll', repaint);

  // Куда ляжет брошенное, решает место: папка под курсором, колонка её
  // содержимого или пустое место первой колонки — тогда объект выносится.
  cols.addEventListener('dragover', (event) => {
    if (!dragging.length) return;
    const spot = spotOf(event);
    if (!spot || !canRehome(dragging, spot.id)) { clearAim(); return; }
    event.preventDefault();
    event.dataTransfer.dropEffect = 'move';
    aim(spot.node);
  });
  cols.addEventListener('drop', (event) => {
    if (!dragging.length) return;
    const spot = spotOf(event);
    if (!spot) { clearAim(); return; }
    event.preventDefault();
    move(dragging, spot.id);
  });
  cols.addEventListener('dragleave', (event) => {
    if (event.target === cols) clearAim();
  });

  // Щелчок по пустому месту снимает выделение — как на холсте.
  cols.addEventListener('click', (event) => {
    if (event.target.closest('.ui-tree-row')) return;
    if (!state.selection.size) return;
    state.selection.clear();
    emit('selection');
  });

  // Схема пришла заново — папка могла смениться, и выбранная ветка уже чужая.
  off = [
    on('scheme', () => { chain = []; render(); }),
    on('element', render),
    on('drop', render),
    on('selection', render),
    on('links', render),
  ];
  render();
}

export function unmount() {
  for (const stop of off) stop();
  off = [];
  cancelAnimationFrame(painting);
  painting = 0;
  root?.remove();
  root = null;
}

function read() {
  try { return localStorage.getItem(STORE) === 'wide' ? 'wide' : 'compact'; } catch { return 'compact'; }
}

function readLinks() {
  try {
    const saved = localStorage.getItem(LINKS);
    if (saved === 'picked' || saved === 'all' || saved === 'none') return saved;
    if (saved === '1') return 'picked';        // прежняя галочка
  } catch {}
  return 'none';
}

function render() {
  if (!root) return;
  const cols = root.querySelector('#tree-cols');
  cols.textContent = '';
  for (const button of root.querySelectorAll('[data-mode]')) {
    button.classList.toggle('on', button.dataset.mode === mode);
  }
  for (const button of root.querySelectorAll('[data-links]')) {
    button.classList.toggle('on', button.dataset.links === links);
  }

  // Стрелки живут только во «Всё сразу»: в компактном второй конец обычно
  // не на экране.
  root.querySelector('#tree-links').hidden = mode !== 'wide';
  cols.classList.toggle('with-links', mode === 'wide' && links !== 'none');

  const first = rootItems();
  if (!first.length) {
    cols.innerHTML = `<p class="ui-tree-empty">${t('editor.tree.empty_folder')}</p>`;
    root.querySelector('#tree-count').textContent = '';
    return;
  }

  const flat = !first.some(isFolder);

  if (mode === 'wide') {
    const all = spread(first);
    for (const [index, lines] of all.entries()) cols.append(column(index, lines));
    count(all.length, flat);
    paint();
    return;
  }

  // Компактный: колонка на каждую выбранную папку. Папка могла исчезнуть —
  // тогда ветка обрывается на ней и дальше не рисуется.
  chain = chain.filter((id) => isFolder(state.elements.get(id)));
  cols.append(column(0, first.map((el) => ({ el, owner: null })), null));
  for (const [index, id] of chain.entries()) {
    cols.append(column(index + 1, childrenOf(id).map((el) => ({ el, owner: id })), id));
  }
  count(1 + chain.length, flat);
}

/**
 * Сколько строк занимает объект вместе со всем своим выводком.
 * Пустая папка и обычный «файл» — одна строка; папка с детьми — столько,
 * сколько её дети, чтобы сосед снизу встал под ними, а не напротив.
 */
function sizeOf(element, seen = new Set()) {
  if (!isFolder(element) || seen.has(element.id)) return 1;
  seen.add(element.id);
  const kids = childrenOf(element.id);
  if (!kids.length) return 1;
  return kids.reduce((lines, kid) => lines + sizeOf(kid, seen), 0);
}

/**
 * Раскладка дерева по колонкам: дети стоят вровень со своим родителем,
 * а следующий сосед уходит вниз, под весь его выводок.
 * Отдаёт по колонке на уровень; в колонке — строки `{el}` и пустоты
 * `{blank: сколько строк}`.
 *
 * `band` — полоса фона: у каждого объекта первого уровня своя, и весь его
 * выводок до последней колонки красится так же. Соседняя полоса — другая,
 * и так по очереди: видно, где кончается одно семейство и начинается другое.
 */
function spread(roots, limit = 40) {
  const out = [];
  let slots = roots.map((el, index) => ({ el, band: index % 2, owner: null }));

  while (slots.some((slot) => slot.el) && out.length < limit) {
    const lines = [];
    const next = [];
    for (const slot of slots) {
      const { band, owner } = slot;
      if (!slot.el) { lines.push({ blank: slot.blank, band, owner }); next.push({ blank: slot.blank, band, owner }); continue; }

      const size = sizeOf(slot.el);
      lines.push({ el: slot.el, band, owner });
      if (size > 1) lines.push({ blank: size - 1, band, owner });

      // Следующая колонка — это нутро этой папки: туда и кладут.
      const mine = isFolder(slot.el) ? slot.el.id : undefined;
      const kids = isFolder(slot.el) ? childrenOf(slot.el.id) : [];
      if (kids.length) for (const kid of kids) next.push({ el: kid, band, owner: mine });
      else next.push({ blank: size, band, owner: mine });   // пусто напротив бездетного
    }
    out.push(lines);
    slots = next;
  }
  return out;
}

/** Одна колонка-уровень: заголовок, строки и пустоты между ними. */
function column(index, lines, owner) {
  const box = document.createElement('div');
  box.className = 'ui-tree-col';
  box.innerHTML = `<div class="ui-tree-level">${t('editor.tree.level', { n: index + 1 })}</div>`;
  box.addEventListener('scroll', repaint);   // колонка прокрутилась — стрелки следом

  // Чья колонка: в компактном её хозяин известен заранее, во «всё сразу» —
  // только если все строки от одной папки. Глубокие колонки сводят ветки
  // разных папок, и общего хозяина у них нет: там решает строка, на которую
  // бросили. Пустая колонка тоже знает хозяина — иначе в пустую папку
  // ничего не положить.
  const owners = new Set(lines.map((line) => line.owner));
  if (owner !== undefined) box.dataset.owner = String(owner ?? '');
  else if (owners.size === 1) box.dataset.owner = String([...owners][0] ?? '');

  for (const line of lines) {
    if (line.el) { box.append(row(line.el, index, line.band, line.owner)); continue; }
    const gap = document.createElement('div');
    gap.className = 'ui-tree-blank';
    gap.style.setProperty('--lines', String(line.blank));
    if (line.band !== undefined) gap.dataset.band = String(line.band);
    gap.dataset.owner = String(line.owner ?? '');
    box.append(gap);
  }
  if (!lines.length) {
    const empty = document.createElement('p');
    empty.className = 'ui-tree-empty';
    empty.textContent = t('editor.tree.folder_empty');
    box.append(empty);
  }
  return box;
}

/** Строка: значок, название, галочка у папки с содержимым. */
function row(element, index, band, owner) {
  const folder = isFolder(element);
  const inside = folder ? childrenOf(element.id).length : 0;
  const line = document.createElement('div');

  line.className = 'ui-tree-row'
    + (folder ? ' folder' : '')
    + (folder && !inside ? ' empty' : '')
    + (chain[index] === element.id ? ' open' : '')
    + (state.selection.has(element.id) ? ' on' : '');
  line.dataset.id = element.id;   // подсказки при наведении нет: она мешает
  line.dataset.owner = String(owner ?? '');
  if (band !== undefined) line.dataset.band = String(band);

  const mark = folder || element.type === 'block' ? '' : `<i>${KINDS[element.type]?.icon || '·'}</i>`;
  const near = links !== 'none' && mode === 'wide' ? linkWords(element) : '';
  line.innerHTML = `
    <span class="ui-tree-icon">${folder ? FOLDER_ICON : FILE_ICON}${mark}</span>
    <span class="ui-tree-name">${escape(short(name(element)))}</span>
    <span class="ui-tree-near">${jumpsOf(element)}${escape(near)}</span>
    <span class="ui-tree-more">${folder && inside ? '▸' : ''}</span>`;

  // Сосед выбранного виден сразу, ещё до того как глаз найдёт линию.
  // Выбранных может быть несколько — считаем по всем.
  if (near && links === 'picked') {
    for (const id of state.selection) {
      if (id === element.id) continue;
      const picked = state.elements.get(id);
      if (!picked) continue;
      const side = linksOf(picked);
      if (side.into.includes(element.id)) line.classList.add('near-in');
      if (side.from.includes(element.id)) line.classList.add('near-out');
    }
  }

  // Перетаскивание: тянем выделенное, если строка из него, иначе одну её.
  // Куда положить — решает место броска, это разбирает общий обработчик.
  if (!state.viewOnly) {
    line.draggable = true;
    line.ondragstart = (event) => {
      dragging = state.selection.has(element.id) && state.selection.size > 1
        ? [...state.selection]
        : [element.id];
      event.dataTransfer.effectAllowed = 'move';
      event.dataTransfer.setData('text/plain', String(element.id));
    };
    line.ondragend = () => { dragging = []; clearAim(); };
  }

  // Щелчок показывает свойства в правой панели — что папка, что файл.
  // Shift или ⌘ набирают несколько объектов: повторный щелчок снимает выбор.
  // Простой щелчок по папке вдобавок открывает её колонку.
  line.onclick = (event) => {
    if (event.target.closest('.ui-tree-jumps')) { jump(element); return; }
    const many = event.shiftKey || event.metaKey || event.ctrlKey;
    if (many) {
      if (state.selection.has(element.id)) state.selection.delete(element.id);
      else state.selection.add(element.id);
    } else {
      state.selection.clear();
      state.selection.add(element.id);
      if (folder && mode === 'compact') chain = [...chain.slice(0, index), element.id];
    }
    emit('selection');        // панель покажет свойства, а render придёт по событию
  };
  return line;
}

/* ── Ярлыки ───────────────────────────────────────────────────── */

/** Значок ярлыков строки: «↗ 2» — висят на объекте, «↙ 1» — ведут к нему. */
function jumpsOf(element) {
  const out = linksFrom(element.id);
  const into = linksTo(element.id);
  if (!out.length && !into.length) return '';
  const names = [
    ...out.map((link) => '↗ ' + endName(link, 'to')),
    ...into.map((link) => '↙ ' + endName(link, 'from')),
  ];
  const text = [out.length ? '↗' + out.length : '', into.length ? '↙' + into.length : ''].filter(Boolean).join(' ');
  return `<span class="ui-tree-jumps" title="${escape(names.join('\n')).replace(/"/g, '&quot;')}">${text}</span> `;
}

/** Щелчок по значку: один ярлык — переход, несколько — панель «Ярлыки». */
function jump(element) {
  const all = [
    ...linksFrom(element.id).map((link) => [link, 'out']),
    ...linksTo(element.id).map((link) => [link, 'in']),
  ];
  if (all.length === 1) { followLink(...all[0]); return; }
  state.selection.clear();
  state.selection.add(element.id);
  openSection(t('editor.panel.links_section'));
  emit('selection');
}

/* ── Перетаскивание ──────────────────────────────────────────── */

/**
 * Куда ляжет брошенное. Папка под курсором принимает в себя; всё прочее —
 * строка соседа, отступ или колонка — кладёт туда же, где лежат её строки.
 * Возвращает `{id, node}`: `id` равен null для корня папки.
 */
function spotOf(event) {
  const row = event.target.closest('.ui-tree-row');
  if (row) {
    const element = state.elements.get(Number(row.dataset.id));
    if (isFolder(element) && !dragging.includes(element.id)) return { id: element.id, node: row };
    return owned(row, row.parentElement);
  }
  const blank = event.target.closest('.ui-tree-blank');
  if (blank) return owned(blank, blank.parentElement);

  const col = event.target.closest('.ui-tree-col');
  if (col) return col.dataset.owner === undefined ? null : owned(col, col);
  return { id: null, node: null };          // пусто правее всех колонок — корень
}

/** Хозяин места: пусто — корень папки. Подсвечиваем колонку целиком. */
function owned(node, highlight) {
  if (node.dataset.owner === undefined) return null;
  const id = node.dataset.owner === '' ? null : Number(node.dataset.owner);
  return { id, node: highlight };
}

function aim(node) {
  if (aimed === node) return;
  clearAim();
  if (!node) return;                        // корень: подсвечивать нечего
  aimed = node;
  node.classList.add('aim');
}

function clearAim() {
  aimed?.classList.remove('aim');
  aimed = null;
}

/** Перевезти и сказать человеку, что получилось. */
function move(ids, targetId) {
  const list = [...ids];
  clearAim();
  dragging = [];
  if (!canRehome(list, targetId)) return;

  const done = rehome(list, targetId);
  if (!done) return;
  const where = targetId ? name(state.elements.get(targetId)) : t('editor.tree.root');
  toast(done === 1 ? t('editor.tree.moved_one', { where }) : t('editor.tree.moved_many', { n: done, where }));
  render();
}

/* ── Связи ───────────────────────────────────────────────────── */

/** Соседи по стрелкам: откуда пришли и куда уходим. */
function linksOf(element) {
  const into = [];
  const from = [];
  for (const arrow of state.elements.values()) {
    if (arrow.type !== 'arrow') continue;
    if (arrow.to === element.id) into.push(arrow.from);
    if (arrow.from === element.id) from.push(arrow.to);
  }
  return { into, from };
}

/** Номера соседей одной строкой: «← 11 → 15 16». Длинный список подрезаем. */
function linkWords(element) {
  const { into, from } = linksOf(element);
  const numbers = (ids) => {
    const list = ids.map((id) => state.elements.get(id)?.no).filter(Boolean);
    return list.length > 2 ? `${list.slice(0, 2).join(' ')} +${list.length - 2}` : list.join(' ');
  };
  const left = into.length ? `← ${numbers(into)}` : '';
  const right = from.length ? `→ ${numbers(from)}` : '';
  return [left, right].filter(Boolean).join('  ');
}

/* ── Стрелки ─────────────────────────────────────────────────── */

/** Перерисовать стрелки, но не чаще раза в кадр. */
function repaint() {
  if (painting) return;
  painting = requestAnimationFrame(() => { painting = 0; paint(); });
}

/**
 * Линии связей поверх колонок. «Связи выбранных» ведут линии только от тех
 * объектов, что набраны щелчками, — две-три вместо сорока. «Все связи»
 * рисуют схему целиком: линий много, поэтому они тише и все одного цвета.
 */
function paint() {
  if (!root) return;
  const cols = root.querySelector('#tree-cols');
  cols.querySelector('.ui-tree-links')?.remove();
  if (links === 'none' || mode !== 'wide') return;
  const picked = new Set(state.selection);
  if (links === 'picked' && !picked.size) return;

  const where = new Map();
  const field = cols.getBoundingClientRect();
  for (const line of cols.querySelectorAll('.ui-tree-row')) {
    const r = line.getBoundingClientRect();
    where.set(Number(line.dataset.id), {
      left: r.left - field.left + cols.scrollLeft,
      right: r.right - field.left + cols.scrollLeft,
      middle: r.top - field.top + cols.scrollTop + r.height / 2,
    });
  }

  const ns = 'http://www.w3.org/2000/svg';
  const svg = document.createElementNS(ns, 'svg');
  svg.setAttribute('class', 'ui-tree-links');
  svg.setAttribute('width', cols.scrollWidth);
  svg.setAttribute('height', cols.scrollHeight);
  let drawn = 0;
  for (const element of state.elements.values()) {
    if (element.type !== 'arrow') continue;
    const mine = picked.has(element.from) || picked.has(element.to);
    if (links === 'picked' && !mine) continue;
    const from = where.get(element.from);
    const to = where.get(element.to);
    if (!from || !to) continue;

    // У выбранных: уходящая линия цветом выбранного, входящая — синим.
    // Во «всех связях» цвет один и тихий, иначе рябит в глазах.
    const out = picked.has(element.from);

    // Концы выбираются по взаимному положению строк: линия выходит той
    // стороной, куда идёт, и входит встречной. Обе опорные точки лежат с
    // той же стороны — иначе кривая делает зигзаг с крючком на конце.
    const y1 = from.middle;
    const y2 = to.middle;
    let d;
    if (to.left >= from.right) {                 // цель правее
      const x1 = from.right;
      const x2 = to.left;
      const b = Math.max(30, (x2 - x1) / 2);
      d = `M${x1},${y1} C${x1 + b},${y1} ${x2 - b},${y2} ${x2},${y2}`;
    } else if (from.left >= to.right) {          // цель левее
      const x1 = from.left;
      const x2 = to.right;
      const b = Math.max(30, (x1 - x2) / 2);
      d = `M${x1},${y1} C${x1 - b},${y1} ${x2 + b},${y2} ${x2},${y2}`;
    } else {                                     // одна колонка — дуга слева
      const x = Math.min(from.left, to.left);
      const b = Math.min(90, 26 + Math.abs(y2 - y1) / 3);
      d = `M${x},${y1} C${x - b},${y1} ${x - b},${y2} ${x},${y2}`;
    }
    const path = document.createElementNS(ns, 'path');
    path.setAttribute('d', d);
    path.setAttribute('class', links === 'all' && !mine ? 'far' : out ? 'out' : 'in');
    if (element.back) path.setAttribute('stroke-dasharray', '5 4');
    svg.append(path);
    drawn++;
  }

  if (drawn) cols.append(svg);
}

/** Счётчик в шапке: сколько уровней и сколько всего элементов. */
function count(deep, flat) {
  const total = countAll();
  root.querySelector('#tree-count').textContent = flat
    ? t('editor.tree.flat_count', { elements: tn('editor.tree.elements', total) })
    : t('editor.tree.deep_count', { levels: tn('editor.tree.levels', deep), elements: tn('editor.tree.elements', total) });
}

/** Номер у элемента — часть названия: «01 Собрать сырьё». */
function name(element) {
  const title = element.title || KINDS[element.type]?.title || t('editor.folders.unnamed');
  return element.no ? `${element.no} ${title}` : title;
}

function short(text) {
  return text.length > LIMIT ? `${text.slice(0, LIMIT - 1)}…` : text;
}


const escape = (text) => String(text).replace(/[&<>]/g, (ch) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;' }[ch]));
