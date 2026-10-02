/* Вид «Лента»: время одного прогона по исполнителям — кто какой блок брал и когда.
   Отдаёт: mount(), unmount().
   Не делает: ничего не ведёт и не пишет. Своего опроса нет: run.timeline
              перечитывается, когда run/paint.js приносит новую версию прогона
              (событие 'runstate'); между ними открытые отрезки тянутся до «сейчас»
              своими часами.

   Строки — исполнители (lanes: ведущий, worker'ы, Jev, движок), отрезки — попытки
   блоков и решения ромбов (bars) цветом состояния из общей таблицы подсветки
   (css/run.css, --run). Шлюз и стартер — отметки. Сверху — полоса «ожидание»
   (waits), поперёк всей ленты — события прогона (marks). Прогон — выбранный:
   state.run, тот же, что на холсте и во вкладке «Прогон». Вид всегда в палитре
   «Студии» (css/timeline.css). */

import * as api from 'goblin/api/client.js';
import { state, on, emit } from 'goblin/core/state.js';
import { setVariant } from 'goblin/core/variants.js';
import { loadRun } from 'goblin/api/sync.js';
import { KINDS, STARTER_ICON, STEP_WORDS, RUN_WORDS, RUN_MARKS, iconOf } from 'goblin/core/kinds.js';
import { flyTo, fitZoom } from 'goblin/canvas/view.js';
import { t } from 'goblin/core/i18n.js';

const TICKS = [1, 2, 5, 10, 15, 30, 60, 120, 300, 600, 900, 1800, 3600, 7200, 14400];
const TICK_PX = 90;                 // деления не теснее этого
const PAD = 30;                     // отступ шкалы от имён: отметка в самом начале не прячется под ними
const POINT_PX = 26;                // решение ромба уже этого — отметкой, а не отрезком
const POINT_W = 90;                 // ширина отметки, если замерить не вышло (вид скрыт): с запасом на «◆ 1234 НЕТ»
const BAR_MIN = 8;                  // отрезок не уже этого: его видно и в него можно попасть
const BAR_H = 26;                   // высота отрезка
const BAR_GAP = 4;                  // между уровнями одной строки
const LANE_PAD = 7;                 // поля строки сверху и снизу
const RETRIES = 6;                  // повторов после сбоя чтения
const SCALE_MIN = 0.01;             // точек на секунду
const SCALE_MAX = 400;
const MARK_ICONS = { start: '◯', pause: '‖', resume: '▶', stop: '■', reset: '↺', finish: '✓', done: '✓', failed: '✗', stopped: '■' };
const KIND_ICONS = { work: KINDS.block.icon, decision: KINDS.decision.icon, pass: KINDS.gateway.icon, start: STARTER_ICON };

let root = null;
let off = [];
let data = null;        // ответ run.timeline показанного прогона
let got = 0;            // performance.now() в миг ответа: «сейчас» = run.now + сколько прошло
let turn = 0;           // поколение чтения: ответ прежнего прогона не ложится на новый
let seen = '';          // версия и жизнь прогона, по которой лента прочитана
let scale = 0;          // точек на секунду
let zoomed = false;     // масштаб выбрал человек; иначе прогон всегда вписан целиком
let follow = true;      // живой прогон: держать «сейчас» в поле зрения
let later = null;       // отложенное перечтение
let retry = null;       // повтор после сбоя чтения
let misses = 0;         // сбоев чтения подряд
let full = null;        // полные ответы попыток этого снимка: { key, ready: Promise<Map step id → текст> }
let hovered = null;     // отрезок под курсором: в его подсказку дописывается полный ответ
let pinned = null;      // отрезок закреплённой щелчком подсказки: она стоит на месте и прокручивается
let pointer = { x: 0, y: 0 };
let tick = null;        // часы живого прогона: открытые отрезки растут
let open = [];          // [{ node, from }] — открытые отрезки и черта «сейчас»

export function mount() {
  document.getElementById('canvas-wrap').hidden = true;
  document.getElementById('rail').hidden = false;

  root = el('section', 'ui-timeline');
  root.innerHTML = `
    <header class="tl-head">
      <span class="tl-chip" id="tl-chip"></span>
      <select class="tl-pick" id="tl-pick" hidden></select>
      <span class="tl-when" id="tl-when"></span>
      <span class="tl-grow"></span>
      <span class="tl-hint">${t('editor.timeline.zoom_hint')}</span>
      <button class="tl-btn" data-act="fit">${t('editor.timeline.fit')}</button>
      <button class="tl-btn" data-act="now">${t('editor.timeline.now')}</button>
    </header>
    <div class="tl-body" id="tl-body"><div class="tl-plot" id="tl-plot"></div></div>
    <div class="tl-empty" id="tl-empty" hidden></div>
    <div class="tl-tip" id="tl-tip" hidden></div>`;
  document.querySelector('.workspace').insertBefore(root, document.getElementById('panel'));

  root.querySelector('.tl-head').onclick = (event) => {
    const act = event.target.closest('[data-act]')?.dataset.act;
    if (act === 'fit') { zoomed = false; follow = true; draw(); }
    if (act === 'now') { follow = true; toNow(); }
  };
  // Другой прогон папки — прямо из шапки: в скинах с вкладками архива слева нет.
  root.querySelector('#tl-pick').onchange = (event) => {
    const id = Number(event.target.value);
    api.setHash({ run: id || null });   // выбор человека закреплён, как в архиве; «сброшенная схема» — без прогона
    loadRun(id || null);
  };
  const body = root.querySelector('#tl-body');
  body.addEventListener('wheel', onWheel, { passive: false });
  body.addEventListener('scroll', () => { follow = atEnd(); if (!pinned) hideTip(); });
  body.addEventListener('mousemove', onHover);
  body.addEventListener('mouseleave', () => { if (!pinned) hideTip(); });
  body.addEventListener('click', onClick);
  body.addEventListener('dblclick', onDouble);

  const onKey = (event) => { if (event.key === 'Escape' && pinned) hideTip(true); };
  document.addEventListener('keydown', onKey);
  off = [
    () => document.removeEventListener('keydown', onKey),
    on('run', onRun),
    on('runstate', () => { if (keyOf() !== seen) soon(); }),
    on('selection', markPicked),
  ];
  load();
}

export function unmount() {
  for (const stop of off) stop();
  off = [];
  turn++;
  clearTimeout(later);
  clearTimeout(retry);
  clearInterval(tick);
  tick = null;
  open = [];
  hovered = null;
  pinned = null;
  root?.remove();
  root = null;
}

/* ── Данные ──────────────────────────────────────────────────── */

/** Сменился прогон — вписать заново; тот же прогон с новой версией — только перечитать. */
function onRun() {
  if (Number(data?.run?.id) !== Number(state.run?.id)) { zoomed = false; follow = true; misses = 0; load(); return; }
  if (keyOf() !== seen) soon();
}

/** Версия и жизнь показанного прогона: выросли — лента перечитывается. */
function keyOf() {
  const run = state.run;
  return run ? versionKey(run.id, run.version, state.runState?.epoch) : '';
}

const versionKey = (id, version, epoch) => `${Number(id)}|${Number(version) || 0}|${Number(epoch) || 0}`;

function soon() {
  clearTimeout(later);
  later = setTimeout(load, 250);
}

/**
 * Прочитать ленту выбранного прогона. Показанной считается только версия удачного ответа:
 * сбой не прячет ленту (последний хороший снимок этого прогона остаётся) и повторяется
 * сам, с растущей паузой и не больше RETRIES раз — и у закрытого прогона, за которым
 * общее слежение уже не ходит. Смена прогона и уход с вида повтор отменяют.
 */
async function load() {
  clearTimeout(retry);
  const mine = ++turn;
  const run = state.run;
  if (!run) { data = null; seen = ''; draw(); return; }
  const answer = await api.quietGet('run.timeline', { run: run.id });
  if (mine !== turn || !root) return;
  if (answer && Number(answer.run?.id) === run.id && Array.isArray(answer.lanes)) {
    data = answer;
    got = performance.now();
    misses = 0;
    seen = versionKey(answer.run.id, answer.run.version, answer.run.epoch);
  } else {
    // Чужой прогон или ответ не того вида — ленты нет, а не чужая лента.
    if (Number(data?.run?.id) !== run.id) data = null;
    if (++misses <= RETRIES) retry = setTimeout(load, Math.min(1000 * 2 ** (misses - 1), 15000));
  }
  draw();
}

const live = () => ['running', 'paused'].includes(data?.run?.state);

/** «Сейчас» по часам сервера: его отметка в ответе плюс сколько прошло с ответа. */
function nowMs() {
  return timeOf(data?.run?.now) + (performance.now() - got);
}

function span() {
  const from = timeOf(data.run.started) || firstTime();
  const to = live() ? nowMs() : (timeOf(data.run.finished) || lastTime() || nowMs());
  return { from, to: Math.max(to, from + 1000) };
}

function firstTime() {
  const all = (data.bars || []).map((bar) => timeOf(bar.from)).filter(Boolean);
  return all.length ? Math.min(...all) : 0;
}
const lastTime = () => Math.max(0, ...(data.bars || []).map((bar) => timeOf(bar.to || bar.from)));

/* ── Отрисовка ───────────────────────────────────────────────── */

function draw() {
  if (!root) return;
  clearInterval(tick);
  tick = null;
  open = [];
  drawHead();
  const plot = root.querySelector('#tl-plot');
  const empty = root.querySelector('#tl-empty');
  plot.textContent = '';

  const say = !state.run ? t('editor.timeline.none')
    : !data ? t('editor.timeline.unread')
    : !(data.bars || []).length ? t('editor.timeline.no_work') : '';
  empty.hidden = !say;
  empty.textContent = say;
  if (pinned) repin();
  if (!data) return;

  const body = root.querySelector('#tl-body');
  const nameW = nameWidth();
  const { from, to } = span();
  // Живому прогону — запас справа на четверть экрана: «сейчас» растёт, не упираясь в край.
  const room = body.clientWidth - nameW;
  const ahead = live() ? Math.max(40, room * 0.25) : 40;
  if (!zoomed || !scale) scale = clamp((room - ahead - PAD) / ((to - from) / 1000));
  const x = (ms) => PAD + ((ms - from) / 1000) * scale;
  const width = Math.max(room, x(to) + ahead);
  plot.style.width = nameW + width + 'px';

  // Шапка ленты: деления времени и полоса «ожидание» — всегда на виду сверху.
  const top = el('div', 'tl-top');
  top.append(row('tl-ruler', el('span', 'tl-corner'), ruler(from, width, x)));
  top.append(row('tl-waits', el('span', 'tl-name tl-name-waits', t('editor.timeline.waits')), waits(x, width)));
  plot.append(top);

  // Строки исполнителей: имя слева закреплено, отрезки — по времени.
  const rows = data.lanes.map((lane) => {
    const track = el('div', 'tl-track');
    track.style.width = width + 'px';
    const nodes = (data.bars || []).filter((one) => one.lane === lane.key).map((bar) => ({ bar, node: barNode(bar, x) }));
    track.append(...nodes.map((one) => one.node));
    plot.append(row('tl-lane', laneName(lane), track, lane.kind));
    return { track, nodes };
  });
  // Уровни — по настоящей ширине на экране: подпись отметки меряется, а не угадывается.
  // Сначала все замеры, потом все записи — одна перекладка страницы, а не по строке.
  const measured = rows.map(({ nodes }) => levels(nodes.map(({ bar, node }) => ({ bar, node, ...extent(bar, node, x) }))));
  rows.forEach(({ track }, n) => {
    const depth = Math.max(1, ...measured[n].map((one) => one.level + 1));
    track.style.height = LANE_PAD * 2 + depth * BAR_H + (depth - 1) * BAR_GAP + 'px';
    for (const { node, level } of measured[n]) node.style.top = LANE_PAD + level * (BAR_H + BAR_GAP) + 'px';
  });

  // События прогона и «сейчас» — черты поперёк всей ленты.
  const marks = el('div', 'tl-marks');
  marks.style.left = nameW + 'px';
  marks.style.width = width + 'px';
  for (const mark of data.marks || []) marks.append(markNode(mark, x));
  if (live()) {
    const now = el('i', 'tl-now');
    now.style.left = x(nowMs()) + 'px';
    marks.append(now);
    open.push({ node: now, now: true });
  }
  plot.append(marks);

  markPicked();
  if (follow && live()) toNow(); else if (follow) body.scrollLeft = 0;
  if (live()) tick = setInterval(() => grow(x), 1000);
}

/** Шапка вида: какой прогон и в каком он состоянии — как плашка в углу холста. */
function drawHead() {
  const run = data?.run || state.run;
  const chip = root.querySelector('#tl-chip');
  const when = root.querySelector('#tl-when');
  chip.hidden = !run;
  when.textContent = '';
  // Список прогонов — и когда прогон не выбран (после сброса): выбрать можно прямо отсюда.
  drawPick();
  if (!run) return;
  chip.dataset.run = run.state;
  chip.innerHTML = `<i>${RUN_MARKS[run.state] || '·'}</i><b>r${escape(run.no)}</b><span>${escape(RUN_WORDS[run.state] || run.state)}</span>`;
  const at = String(run.started || run.startedAt || '');
  if (at) when.textContent = t('editor.run.started', { at: `${at.slice(8, 10)}.${at.slice(5, 7)}.${at.slice(0, 4)} ${at.slice(11, 19)}` });
}

/** Выбор прогона папки: номер; его состояние — на плашке рядом. Схему сбросили, а нового прогона
    ещё нет — первой строкой «— сброшенная схема»: к ней возвращаются из прошлых прогонов. Выбирать
    не из чего (одна строка) — списка нет. */
function drawPick() {
  const pick = root.querySelector('#tl-pick');
  const runs = state.runs || [];
  const prepared = Number(state.folder?.style?.preparedRun) || 0;
  const blank = !state.run || (prepared > 0 && !runs.some((run) => run.id > prepared));
  pick.hidden = runs.length + (blank ? 1 : 0) < 2;
  pick.textContent = '';
  if (blank) pick.append(Object.assign(el('option', '', t('editor.timeline.blank_scheme')), { value: '', selected: !state.run }));
  for (const run of runs) {
    const option = el('option', '', `r${run.no}`);
    option.value = run.id;
    option.selected = run.id === state.run?.id;
    pick.append(option);
  }
}

function row(cls, name, track, kind = '') {
  const node = el('div', 'tl-row ' + cls);
  if (kind) node.dataset.kind = kind;
  node.append(name, track);
  return node;
}

function laneName(lane) {
  const name = el('span', 'tl-name');
  const title = lane.title || lane.key;
  const kind = laneKind(lane.kind);
  // Вся строка — догадка (старые попытки без факта): «≈» у имени и слово под ним.
  const bars = (data?.bars || []).filter((bar) => bar.lane === lane.key);
  const guess = bars.length > 0 && bars.every((bar) => bar.guess);
  name.append(el('b', '', (guess ? '≈ ' : '') + title));
  // Вид строки — только когда он не повторяет имя: «Анна · worker», но просто «ведущий».
  const small = [kind && kind.toLowerCase() !== title.toLowerCase() ? kind : '', guess ? t('editor.timeline.guess_word') : '']
    .filter(Boolean).join(' · ');
  if (small) name.append(el('small', '', small));
  if (guess) name.dataset.guess = '1';
  return name;
}

function laneKind(kind) {
  return { lead: t('editor.timeline.lane_lead'), worker: 'worker', jev: 'Jev', engine: t('editor.timeline.lane_engine') }[kind] || '';
}

/** Деления: шаг не теснее TICK_PX, подписи — минуты и секунды от начала прогона. */
function ruler(from, width, x) {
  const track = el('div', 'tl-track tl-scale');
  track.style.width = width + 'px';
  const step = TICKS.find((sec) => sec * scale >= TICK_PX) || TICKS[TICKS.length - 1];
  for (let sec = 0; PAD + sec * scale <= width; sec += step) {
    const tickNode = el('span', 'tl-tick', clockOf(sec));
    tickNode.style.left = x(from + sec * 1000) + 'px';
    track.append(tickNode);
  }
  return track;
}

/** Ожидание прогона: штриховка с причиной. */
function waits(x, width) {
  const track = el('div', 'tl-track');
  track.style.width = width + 'px';
  for (const wait of data.waits || []) {
    const from = timeOf(wait.from);
    const to = wait.to ? timeOf(wait.to) : nowMs();
    const node = el('div', 'tl-wait');
    node.dataset.reason = wait.reason || '';
    node.style.left = x(from) + 'px';
    node.style.width = Math.max(3, x(to) - x(from)) + 'px';
    node.tip = { kind: 'wait', wait };
    if (x(to) - x(from) > 80) node.append(el('span', '', reasonWord(wait.reason)));
    if (!wait.to) open.push({ node, from });
    track.append(node);
  }
  return track;
}

function reasonWord(reason) {
  return {
    pause: t('editor.timeline.wait_pause'), decision: t('editor.timeline.wait_decision'),
    pass: t('editor.timeline.wait_pass'), person: t('editor.timeline.wait_person'), lead: t('editor.timeline.wait_lead'),
  }[reason] || reason || '';
}

/** Отрезок: попытка блока или решение ромба; шлюз и стартер — отметка-точка. */
function barNode(bar, x) {
  const from = timeOf(bar.from);
  const point = isPoint(bar, x);
  const quick = point && bar.kind === 'decision';
  const node = el('div', 'tl-bar tl-' + (bar.kind || 'work') + (point ? ' tl-point' : ''));
  node.dataset.state = bar.state || (bar.to ? 'accepted' : 'ready');
  if (bar.branch) node.dataset.branch = bar.branch;
  if (bar.element) node.dataset.element = bar.element;
  node.tip = { kind: 'bar', bar };
  node.style.left = x(from) + 'px';
  if (point) {
    if (quick) node.style.left = x((from + timeOf(bar.to)) / 2) + 'px';
    // Ветка решённого ромба — словом: цвет у него «принят» при любой ветке.
    const branch = bar.branch ? ' ' + t(bar.branch === 'yes' ? 'editor.canvas.yes_caps' : 'editor.canvas.no_caps') : '';
    node.append(el('b', '', KIND_ICONS[bar.kind]), el('span', 'tl-label', String(bar.no ?? '') + branch));
    return node;
  }
  const to = bar.to ? timeOf(bar.to) : nowMs();
  const width = Math.max(BAR_MIN, x(to) - x(from));
  node.style.width = width + 'px';
  // Работа внутри попытки — от «взят» до «сдан» (или до конца): гуще; до «взят» — ждала исполнителя.
  if (bar.kind === 'work' && bar.took) {
    const work = el('i', 'tl-work');
    const took = timeOf(bar.took);
    const end = bar.sent ? timeOf(bar.sent) : to;
    work.style.left = Math.max(0, x(took) - x(from)) + 'px';
    work.style.width = Math.max(2, x(end) - x(took)) + 'px';
    node.append(work);
    if (!bar.sent && !bar.to) open.push({ node: work, from: took, inner: true });
  }
  node.append(el('span', 'tl-label', labelOf(bar, width)));
  if (!bar.to) open.push({ node, from });
  return node;
}

/** Отметка, а не отрезок: шлюз, стартер и решение ромба, принятое в миг («◆ 12», а не обломок в пару точек). */
function isPoint(bar, x) {
  if (bar.kind === 'pass' || bar.kind === 'start') return true;
  return bar.kind === 'decision' && !!bar.to && x(timeOf(bar.to)) - x(timeOf(bar.from)) < POINT_PX;
}

/**
 * Параллельная работа одного исполнителя — уровнями внутри его строки: отрезки не
 * перекрывают друг друга, строка становится выше. Открытый отрезок занимает уровень
 * до конца ленты — он ещё растёт.
 */
function levels(items) {
  const ends = [];                          // правый край занятого на каждом уровне, в точках
  return [...items].sort((a, b) => a.left - b.left)
    .map((one) => {
      let level = ends.findIndex((end) => end + BAR_GAP <= one.left);
      if (level < 0) { level = ends.length; ends.push(0); }
      ends[level] = one.right;
      return { ...one, level };
    });
}

/**
 * Сколько места отрезок занимает на экране: не уже BAR_MIN. Отметка стоит серединой
 * на своём миге, ширина — замер её подписи («◆ 1234 НЕТ»); скрытый вид — запас POINT_W.
 */
function extent(bar, node, x) {
  const from = x(timeOf(bar.from));
  if (isPoint(bar, x)) {
    const at = parseFloat(node.style.left) || from;
    const half = (node.offsetWidth || POINT_W) / 2;
    return { left: at - half, right: at + half };
  }
  const to = bar.to ? x(timeOf(bar.to)) : Infinity;
  return { left: from, right: Math.max(to, from + BAR_MIN) };
}

/** Подпись внутри отрезка: «▣ 14 Название · 14.2 ↻2», на узком — только номер. */
function labelOf(bar, width) {
  if (width < 22) return '';
  const lap = Number(bar.lap) > 1 ? ` ↻${bar.lap}` : '';
  // У ромба главное — ветка: она сразу за номером и не уходит под многоточие.
  const branch = bar.branch ? ' ' + t(bar.branch === 'yes' ? 'editor.canvas.yes_caps' : 'editor.canvas.no_caps') : '';
  if (width < 90) return `${bar.no ?? ''}${branch || lap}`;
  const address = bar.address && !bar.branch ? ` · ${bar.address}` : '';
  const icon = KIND_ICONS[bar.kind] || KIND_ICONS.work;
  return branch ? `${icon} ${bar.no ?? ''}${branch} · ${bar.title || ''}` : `${icon} ${bar.no ?? ''} ${bar.title || ''}${address}${lap}`;
}

/** Событие прогона — черта поперёк ленты; значок сверху, над ним — подсказка. */
function markNode(mark, x) {
  const node = el('div', 'tl-mark');
  node.dataset.kind = mark.kind || '';
  if (mark.end) node.dataset.end = mark.end;
  node.style.left = x(timeOf(mark.at)) + 'px';
  const icon = el('b', '', mark.kind === 'finish' ? MARK_ICONS[mark.end] || MARK_ICONS.finish : MARK_ICONS[mark.kind] || '•');
  icon.tip = { kind: 'mark', mark };
  node.append(icon);
  return node;
}

/** Часы живого прогона: открытые отрезки и черта «сейчас» тянутся без запроса к серверу. */
function grow(x) {
  if (!root || !data) return;
  const now = nowMs();
  for (const item of open) {
    if (item.now) { item.node.style.left = x(now) + 'px'; continue; }
    item.node.style.width = Math.max(item.inner ? 2 : BAR_MIN, x(now) - x(item.from)) + 'px';
  }
  // «Сейчас» дошло до края запаса — лента собирается заново, с новым запасом и делениями.
  if (nameWidth() + x(now) + 20 > root.querySelector('#tl-plot').offsetWidth) { draw(); return; }
  if (follow) toNow();
}

/* ── Управление ──────────────────────────────────────────────── */

const clamp = (value) => Math.min(SCALE_MAX, Math.max(SCALE_MIN, value || SCALE_MIN));

function nameWidth() {
  return parseFloat(getComputedStyle(root).getPropertyValue('--tl-name-w')) || 160;
}

function atEnd() {
  const body = root.querySelector('#tl-body');
  return body.scrollLeft + body.clientWidth >= body.scrollWidth - 24;
}

function toNow() {
  const body = root?.querySelector('#tl-body');
  if (body) body.scrollLeft = body.scrollWidth;
}

/** Масштаб — колесом над шкалой времени или с Ctrl/⌘ где угодно; точка под курсором стоит на месте. */
function onWheel(event) {
  if (!data) return;
  const overScale = !!event.target.closest('.tl-ruler');
  if (!overScale && !event.ctrlKey && !event.metaKey) return;
  event.preventDefault();
  const body = root.querySelector('#tl-body');
  const nameW = nameWidth();
  const cursor = event.clientX - body.getBoundingClientRect().left - nameW;
  const at = (body.scrollLeft + cursor - PAD) / scale;    // секунда под курсором
  scale = clamp(scale * Math.exp(-event.deltaY * 0.0015));
  zoomed = true;
  follow = false;
  draw();
  body.scrollLeft = PAD + at * scale - cursor;
}

function onClick(event) {
  const bar = event.target.closest('.tl-bar[data-element]');
  if (!bar) { if (pinned) hideTip(true); return; }
  // Подсказка попытки закрепляется: стоит на месте, ответ в ней прокручивается целиком.
  pin(bar.tip.bar, event);
  const id = Number(bar.dataset.element);
  if (!state.elements.has(id)) return;
  state.selection.clear();
  state.selection.add(id);
  emit('selection');
  emit('props-tab');       // свойства блока — в правой панели, как щелчок по строке «Списка»
}

async function onDouble(event) {
  const bar = event.target.closest('.tl-bar[data-element]');
  const element = bar && state.elements.get(Number(bar.dataset.element));
  if (!element) return;
  await setVariant('canvas');
  flyTo(element, fitZoom(element));
}

/** Выделенный блок — его отрезки обведены. */
function markPicked() {
  if (!root) return;
  for (const node of root.querySelectorAll('.tl-bar[data-element]')) {
    node.classList.toggle('on', state.selection.has(Number(node.dataset.element)));
  }
}

/* ── Подсказка ───────────────────────────────────────────────── */

function onHover(event) {
  if (pinned) return;                       // закреплённая подсказка не бегает за курсором
  const target = event.target.closest('.tl-bar, .tl-wait, .tl-mark b, .tl-scale');
  if (!target) { hideTip(); return; }
  const lines = target.classList.contains('tl-scale') ? scaleTip(event, target) : tipOf(target.tip);
  if (!lines.length) { hideTip(); return; }
  pointer = { x: event.clientX, y: event.clientY };
  showTip(lines);
  const bar = target.tip?.kind === 'bar' ? target.tip.bar : null;
  if (bar === hovered) return;
  hovered = bar;
  if (bar) completeAnswer(bar);
}

/** Щелчок по отрезку: его подсказка встаёт на месте щелчка и ловит колесо — длинный ответ прокручивается. */
function pin(bar, event) {
  pinned = bar;
  hovered = bar;
  pointer = { x: event.clientX, y: event.clientY };
  showTip(tipOf({ kind: 'bar', bar }));
  completeAnswer(bar);
}

/**
 * Сервер укоротил ответ попытки — дочитываем полный. После ожидания сверяемся: вид
 * ещё открыт, снимок ленты тот же, и подсказка всё ещё об этом отрезке.
 */
function completeAnswer(bar) {
  if (bar.full !== undefined || !String(bar.result || '').endsWith('…')) return;
  fullAnswer(bar).then((ok) => {
    if (ok && root && (hovered === bar || pinned === bar)) showTip(tipOf({ kind: 'bar', bar }));
  });
}

function showTip(lines) {
  const tip = root.querySelector('#tl-tip');
  const box = tip.querySelector('.tl-answer');
  const keep = box ? box.scrollTop : 0;     // перечтение ленты не сбрасывает прокрутку ответа
  tip.textContent = '';
  tip.classList.toggle('pinned', !!pinned);
  lines.forEach((line, n) => tip.append(typeof line === 'string'
    ? el(n ? 'div' : 'b', '', line) : el('div', line.cls, line.text)));
  tip.hidden = false;
  const answer = tip.querySelector('.tl-answer');
  if (answer) {
    answer.scrollTop = keep;
    // Ответ длиннее окошка: подсказать, как его дочитать, и как закрыть закреплённую.
    if (pinned) tip.append(el('div', 'tl-tip-hint', t('editor.timeline.unpin_hint')));
    else if (answer.scrollHeight > answer.clientHeight) tip.append(el('div', 'tl-tip-hint', t('editor.timeline.pin_hint')));
  }
  place(tip);
}

/** Подсказка — рядом с курсором и целиком внутри вида. */
function place(tip) {
  const room = root.getBoundingClientRect();
  const left = Math.min(pointer.x - room.left + 14, room.width - tip.offsetWidth - 8);
  const top = pointer.y - room.top + 16 + tip.offsetHeight > room.height
    ? pointer.y - room.top - tip.offsetHeight - 10 : pointer.y - room.top + 16;
  tip.style.left = Math.max(8, left) + 'px';
  tip.style.top = Math.max(8, top) + 'px';
}

/** Лента перечитана — закреплённая подсказка переходит на тот же отрезок нового снимка. */
function repin() {
  const same = (data?.bars || []).find((bar) => bar.kind === pinned.kind && bar.step === pinned.step
    && bar.from === pinned.from && bar.element === pinned.element);
  if (!same) { hideTip(true); return; }
  pinned = same;
  hovered = same;
  showTip(tipOf({ kind: 'bar', bar: same }));
  completeAnswer(same);
}

/** Спрятать подсказку; закреплённую — только явно (Esc, щелчок мимо). */
function hideTip(unpin = false) {
  if (pinned && !unpin) return;
  pinned = null;
  hovered = null;
  const tip = root?.querySelector('#tl-tip');
  if (tip) tip.hidden = true;
}

/**
 * Полный ответ именно этой попытки: лента отдаёт его укороченным, шаги прогона (run.get) —
 * целиком. Читается один раз на снимок ленты и кладётся в сам отрезок (bar.full).
 * false — вид сняли, снимок сменился или чтение не удалось: отрезок остаётся с коротким ответом.
 */
async function fullAnswer(bar) {
  const key = seen;
  if (full?.key !== key) {
    const ready = api.quietGet('run.get', { run: data.run.id })
      .then((answer) => answer && new Map((answer.steps || []).map((step) => [Number(step.id), step.result || ''])));
    full = { key, ready };
  }
  const mine = full;
  const answers = await mine.ready;
  if (!answers && full === mine) full = null;    // сбой — следующее наведение попробует снова
  if (!root || key !== seen || !answers) return false;
  bar.full = answers.get(Number(bar.step)) || null;
  return true;
}

/** Над шкалой: абсолютное время под курсором и сколько прошло с начала. */
function scaleTip(event, track) {
  const { from } = span();
  const sec = (event.clientX - track.getBoundingClientRect().left - PAD) / scale;
  if (sec < 0) return [];
  return [`${absClock(from + sec * 1000)} · +${clockOf(sec)}`];
}

function tipOf(tip) {
  if (!tip) return [];
  if (tip.kind === 'wait') {
    const { wait } = tip;
    const where = wait.no ? ` · ${elementIcon(wait.no)}${wait.no}` : '';
    return [reasonWord(wait.reason) + where, times([['from', wait.from], ['to', wait.to]]), durationOf(wait.from, wait.to)]
      .filter(Boolean);
  }
  if (tip.kind === 'mark') {
    const { mark } = tip;
    const asked = mark.kind === 'stop' && mark.asked ? t('editor.timeline.stop_asked') : '';
    const life = mark.kind === 'reset' && mark.epoch ? t('editor.timeline.life_n', { n: mark.epoch }) : '';
    return [mark.title || mark.kind, [absClock(timeOf(mark.at)), life, asked].filter(Boolean).join(' · ')];
  }
  const { bar } = tip;
  const lane = data.lanes.find((one) => one.key === bar.lane);
  // Попытка — своим адресом N.K: подсказка говорит именно о ней, а не о последней попытке блока.
  const head = `${KIND_ICONS[bar.kind] || KIND_ICONS.work} ${bar.no ?? ''} «${bar.title || ''}»`;
  const attempt = [bar.address ? t('editor.canvas.try_n', { address: bar.address }) : '',
    Number(bar.lap) > 1 ? t('editor.canvas.round_n', { n: bar.lap }) : ''].filter(Boolean).join(' · ');
  const answer = bar.full || bar.result;
  const status = bar.kind === 'decision'
    ? (bar.branch ? t('editor.canvas.decided', { branch: t(bar.branch === 'yes' ? 'editor.canvas.yes_caps' : 'editor.canvas.no_caps') })
      : t('editor.canvas.wait_decision')) + (bar.confidence != null ? ` · Jev ${Number(bar.confidence).toFixed(2)}` : '')
    : STEP_WORDS[bar.state] || bar.state || '';
  return [
    head,
    attempt,
    [lane?.title && bar.guess ? t('editor.timeline.guess', { name: lane.title }) : lane?.title, status].filter(Boolean).join(' · '),
    bar.guess ? t('editor.timeline.guess_why') : '',
    bar.kind === 'pass' && bar.target ? `→ ${folderName(bar.target)}` : '',
    times([['issued', bar.from], ['took', bar.took], ['sent', bar.sent], ['closed', bar.to]]),
    durationOf(bar.from, bar.to),
    answer ? { cls: 'tl-answer', text: `${t('editor.timeline.answer')}: ${answer}` } : '',
    bar.error ? `${t('editor.timeline.why')}: ${bar.error}` : '',
  ].filter(Boolean);
}

/** «выдан 10:21:05 · взят 10:21:07 · сдан …»; открытое — «ещё открыт». */
function times(pairs) {
  const words = { from: t('editor.timeline.from'), to: t('editor.timeline.to'), issued: t('editor.timeline.issued'),
    took: t('editor.timeline.took'), sent: t('editor.timeline.sent'), closed: t('editor.timeline.closed') };
  const parts = pairs.filter(([, at]) => at).map(([name, at]) => `${words[name]} ${absClock(timeOf(at))}`);
  if (!pairs[pairs.length - 1][1]) parts.push(t('editor.timeline.open'));
  return parts.join(' · ');
}

function durationOf(from, to) {
  const ms = (to ? timeOf(to) : nowMs()) - timeOf(from);
  return ms > 0 ? t('editor.timeline.took_for', { time: clockOf(Math.round(ms / 1000)) }) : '';
}

/* ── Мелочи ──────────────────────────────────────────────────── */

/** Значок узла по номеру — тот же набор, что во всём редакторе. */
function elementIcon(no) {
  const element = [...state.elements.values()].find((one) => one.no === Number(no));
  return element ? iconOf(element) : '';
}

function folderName(id) {
  return state.folders.find((folder) => folder.id === Number(id))?.name || t('editor.canvas.folder_n', { id });
}

/** Секунды от начала прогона: «4:05», «1:02:03». */
function clockOf(sec) {
  const s = Math.max(0, Math.round(sec));
  const h = Math.floor(s / 3600);
  const m = Math.floor((s % 3600) / 60);
  const rest = String(s % 60).padStart(2, '0');
  return h ? `${h}:${String(m).padStart(2, '0')}:${rest}` : `${m}:${rest}`;
}

/** Время сервера по часам: «10:21:05». Дата — в шапке вида. */
function absClock(ms) {
  const date = new Date(ms);
  return [date.getHours(), date.getMinutes(), date.getSeconds()].map((n) => String(n).padStart(2, '0')).join(':');
}

/** Время сервера «2026-09-30 10:21:05.500» в миллисекундах — как в run/paint.js. */
function timeOf(text) {
  if (!text) return 0;
  const ms = Date.parse(String(text).replace(' ', 'T'));
  return Number.isFinite(ms) ? ms : 0;
}

function el(tag, cls, text) {
  const node = document.createElement(tag);
  if (cls) node.className = cls;
  if (text !== undefined) node.textContent = text;
  return node;
}

const escape = (text) => String(text).replace(/[&<>]/g, (ch) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;' }[ch]));
