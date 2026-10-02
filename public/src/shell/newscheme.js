/* Окно «Новая схема»: каталог готовых схем и конструктор по опросу.
   Отдаёт: openNewScheme().
   Не делает: не собирает схему сам — каталог разворачивает сервер (template.apply), конструктор
   ведут сервер и модель (ai.build.*). Здесь — выбор, карточки вопросов, превью и кнопки.

   Каталог: разделы слева, карточки справа; карточка открывает паспорт схемы и миникарту.
   Конструктор: цель своими словами → вопросы карточками (варианты + свой ответ) → черновик с
   миникартой → «Создать папку» или «Доработать» словами. */

import * as api from 'goblin/api/client.js';
import { loadProject, loadFolder } from 'goblin/api/sync.js';
import { toast } from 'goblin/shell/topbar.js';
import { rich } from 'goblin/ui/rich.js';
import { t, tn } from 'goblin/core/i18n.js';

let dialog, body;
let tab = 'catalog';
let catalog = null;          // { categories, templates } — читается раз за открытие окна
let category = '';           // выбранный раздел; пусто — все
let search = '';
let opened = null;           // ключ схемы, открытой в каталоге
let poll = null;             // опрос сервера, пока модель думает
let waitFrom = 0;            // когда начали ждать — для счётчика секунд
/* Номер хода окна: вкладка, раздел, карточка, шаг опроса — новый ход. Ответ сервера,
   пришедший после смены хода или закрытия окна, ничего не рисует и опрос не заводит. */
let turn = 0;
const move = () => ++turn;
const stale = (mine) => mine !== turn || !dialog.open;

const EXAMPLES = [
  t('editor.newscheme.example_1'),
  t('editor.newscheme.example_2'),
  t('editor.newscheme.example_3'),
  t('editor.newscheme.example_4'),
];
const PASSPORT = [
  ['for', t('editor.newscheme.p_for')], ['input', t('editor.newscheme.p_input')], ['output', t('editor.newscheme.p_output')], ['steps', t('editor.newscheme.p_steps')],
  ['services', t('editor.newscheme.p_services')], ['tune', t('editor.newscheme.p_tune')],
];

/** Открыть окно: вкладка «catalog» или «builder»; goal — начать конструктор с этой цели. */
export async function openNewScheme(which = 'catalog', { goal = '' } = {}) {
  dialog = document.getElementById('dialog-newscheme');
  body = document.getElementById('ns-body');
  tab = which;
  catalog = null;
  opened = null;
  for (const button of dialog.querySelectorAll('[data-ns-tab]')) {
    button.onclick = () => { tab = button.dataset.nsTab; opened = null; draw(); };
  }
  dialog.onclose = stopPoll;
  for (const x of dialog.querySelectorAll('[data-close]')) x.onclick = () => dialog.close();
  if (!dialog.open) dialog.showModal();
  await draw();
  if (goal) {
    const area = body.querySelector('.ns-intro textarea');
    if (area) { area.value = goal; area.focus(); area.setSelectionRange(goal.length, goal.length); }
  }
}

async function draw() {
  stopPoll();
  for (const button of dialog.querySelectorAll('[data-ns-tab]')) button.classList.toggle('on', button.dataset.nsTab === tab);
  body.dataset.tab = tab;
  if (tab === 'catalog') return drawCatalog();
  return drawBuilder();
}

/* ── Каталог ──────────────────────────────────────────────────── */

async function drawCatalog() {
  const mine = move();
  if (!catalog) {
    body.replaceChildren(note(t('editor.newscheme.loading_catalog')));
    let got;
    try { got = await api.get('catalog.get'); } catch { if (!stale(mine)) body.replaceChildren(note(t('editor.newscheme.catalog_down'))); return; }
    if (stale(mine)) return;
    catalog = got;
  }
  if (opened) return drawTemplate(opened);

  const find = el('input', 'input ns-search');
  find.type = 'search';
  find.placeholder = t('editor.newscheme.search');
  find.value = search;
  find.oninput = () => { search = find.value; drawCards(list); };
  const cats = el('nav', 'ns-cats', [catButton('', t('editor.newscheme.all'), catalog.templates.length)]);
  for (const one of catalog.categories) cats.append(catButton(one.key, one.title, one.count, one.about));
  const side = el('aside', 'ns-side', [find, cats]);

  const list = el('section', 'ns-list');
  body.replaceChildren(el('div', 'ns-catalog', [side, list]));
  drawCards(list);
  find.focus();
}

function catButton(key, title, count, about = '') {
  const button = el('button', 'ns-cat' + (category === key ? ' on' : ''));
  button.append(el('span', '', title), el('small', '', String(count)));
  button.onclick = () => { category = key; drawCatalog(); };
  if (about) button.dataset.about = about;
  return button;
}

function drawCards(list) {
  const words = search.trim().toLowerCase();
  const shown = catalog.templates.filter((one) => (!category || one.category === category)
    && (!words || [one.title, one.about, ...(one.tags || []), ...(one.services || [])].join(' ').toLowerCase().includes(words)));
  const head = catalog.categories.find((one) => one.key === category);
  list.replaceChildren();
  if (head) list.append(el('p', 'ns-about', head.about));
  if (!shown.length) {
    list.append(note(catalog.templates.length ? t('editor.newscheme.nothing_found')
      : t('editor.newscheme.catalog_empty')));
    return;
  }
  const grid = el('div', 'ns-grid');
  for (const one of shown) grid.append(card(one));
  list.append(grid);
}

function card(one) {
  const node = el('button', 'ns-card');
  const title = catalog.categories.find((c) => c.key === one.category)?.title || '';
  node.append(
    el('small', 'ns-card-cat', title),
    el('b', '', one.title),
    el('span', 'ns-card-about', one.about || ''),
    el('span', 'ns-card-meta', [el('span', '', stepsWord(one.steps)),
      ...(one.services || []).slice(0, 3).map((s) => el('span', 'ns-chip', s)),
      ...(one.checked ? [el('span', 'ns-ok', t('editor.newscheme.checked'))] : [])]),
  );
  node.onclick = () => { opened = one.key; drawCatalog(); };
  return node;
}

async function drawTemplate(key) {
  const mine = move();
  body.replaceChildren(note(t('editor.newscheme.opening')));
  let one;
  try { one = (await api.get('catalog.get', { template: key })).template; } catch { if (stale(mine)) return; opened = null; return drawCatalog(); }
  if (stale(mine)) return;

  const back = el('button', 'btn btn-quiet btn-small ns-back', t('editor.newscheme.back'));
  back.onclick = () => { opened = null; drawCatalog(); };

  const facts = el('dl', 'ns-facts');
  for (const [name, word] of PASSPORT) {
    const value = one.passport?.[name];
    if (!value || (Array.isArray(value) && !value.length)) continue;
    facts.append(el('dt', '', word), el('dd', '', Array.isArray(value)
      ? [el(name === 'steps' ? 'ol' : 'ul', '', value.map((v) => el('li', '', v)))] : value));
  }

  const name = field(t('editor.folders.prompt_name'), one.title);
  const make = el('button', 'btn btn-accent', t('editor.newscheme.create_folder'));
  make.onclick = async () => {
    make.disabled = true;
    try {
      const answer = await api.post('template.apply', { template: one.key, name: name.input.value.trim() || one.title });
      await openFolder(answer.folder, t('editor.newscheme.created', { name: one.title }), answer.problems);
    } catch { make.disabled = false; }
  };
  const tune = el('button', 'btn btn-quiet', t('editor.newscheme.tune'));
  tune.onclick = () => openNewScheme('builder', { goal: t('editor.newscheme.based_on', { name: one.title }) });

  const side = el('div', 'ns-pass', [
    el('h3', 'ns-title', one.title), el('p', 'ns-about', one.about || ''),
    one.checked ? el('p', 'ns-ok', t('editor.newscheme.checked_run', { date: one.checked.split('-').reverse().join('.') })) : el('span'),
    facts, name.row, el('div', 'ns-actions', [make, tune]),
  ]);
  body.replaceChildren(back, el('div', 'ns-detail', [el('div', 'ns-map', [miniMap(one.preview)]), side]));
}

/* ── Конструктор ──────────────────────────────────────────────── */

async function drawBuilder() {
  const mine = move();
  let data;
  try { data = await api.get('ai.build.get'); } catch { if (!stale(mine)) body.replaceChildren(note(t('editor.newscheme.builder_down'))); return; }
  if (stale(mine)) return;
  if (!data.session || data.session.state === 'done' && !data.draft?.folder) return drawIntro();
  drawSession(data);
}

function drawIntro() {
  const mine = move();
  const area = el('textarea', 'input ns-goal-input');
  area.rows = 3;
  area.placeholder = t('editor.newscheme.goal_placeholder');
  const start = el('button', 'btn btn-accent', t('editor.newscheme.start'));
  start.onclick = async () => {
    const goal = area.value.trim();
    if (goal.length < 10) { toast(t('editor.newscheme.more_detail'), true); area.focus(); return; }
    start.disabled = true;
    try { await api.post('ai.build.start', { goal }); if (!stale(mine)) drawBuilder(); } catch { start.disabled = false; }
  };
  area.onkeydown = (event) => { if (event.key === 'Enter' && (event.metaKey || event.ctrlKey)) start.click(); };
  const chips = el('div', 'ns-examples', EXAMPLES.map((text) => {
    const chip = el('button', 'ns-chip ns-chip-btn', text);
    chip.onclick = () => { area.value = text; area.focus(); };
    return chip;
  }));
  body.replaceChildren(el('div', 'ns-intro', [
    el('h3', 'ns-title', t('editor.newscheme.describe')),
    el('p', 'ns-about', t('editor.newscheme.intro')),
    area, chips, el('div', 'ns-actions', [start]),
  ]));
  area.focus();
}

function drawSession(data) {
  const { session, history, card: open, draft, job } = data;
  const again = el('button', 'btn btn-quiet btn-small', t('editor.newscheme.again'));
  again.onclick = () => { stopPoll(); drawIntro(); };
  const base = (open?.base || draft?.base)?.title;
  const head = el('div', 'ns-head', [
    el('div', 'ns-goal', [el('b', '', t('editor.newscheme.goal')), session.goal]),
    el('div', 'ns-progress', [
      el('span', '', session.asked ? t('editor.newscheme.asked', { n: session.asked }) : t('editor.newscheme.first_question')),
      // Основу модель называет с первой карточкой; до неё — ни «основы», ни «с нуля».
      ...(open || draft ? [el('span', 'ns-chip', base ? t('editor.newscheme.base', { name: base }) : t('editor.newscheme.scratch'))] : []),
    ]),
    again,
  ]);

  const past = el('ol', 'ns-history', history.map((one) => el('li', '', [el('span', 'ns-q', one.q), el('span', 'ns-a', one.a)])));
  const now = el('div', 'ns-now');
  body.replaceChildren(el('div', 'ns-session', [head, ...(history.length ? [past] : []), now]));

  const busy = job && ['queued', 'running'].includes(job.state);
  if (busy) return waiting(now, job, session);
  if (job?.state === 'failed') return failed(now, job, session);
  if (draft && ['ready', 'done'].includes(session.state)) return drawDraft(now, draft, session);
  if (open) return drawCard(now, open, session);
  now.append(note(t('editor.newscheme.waiting')));
}

function waiting(now, job, session) {
  if (!waitFrom) waitFrom = Date.now();
  const line = el('p', 'ns-wait');
  const tick = () => {
    const seconds = Math.round((Date.now() - waitFrom) / 1000);
    line.textContent = (job.kind === 'diagram' ? t('editor.newscheme.building') : t('editor.newscheme.thinking')) + ` · ${t('editor.run.seconds', { n: seconds })}`;
  };
  tick();
  now.replaceChildren(line);
  stopPoll(false);
  const mine = turn;
  const own = setInterval(async () => {
    if (stale(mine)) { clearInterval(own); return; }
    tick();
    let data;
    try { data = await api.get('ai.build.get', { session: session.id }); } catch { return; }
    if (stale(mine)) { clearInterval(own); return; }
    if (!['queued', 'running'].includes(data.job?.state)) { stopPoll(); drawSession(data); }
  }, 1500);
  poll = own;
}

function failed(now, job, session) {
  const retry = el('button', 'btn btn-accent', job.kind === 'diagram' ? t('editor.newscheme.rebuild') : t('editor.newscheme.retry'));
  retry.onclick = () => answer(session, { retry: 1 }, retry);
  now.replaceChildren(el('p', 'ns-bad', t('editor.newscheme.failed', { why: job.error || t('editor.newscheme.no_answer') })), el('div', 'ns-actions', [retry]));
}

function drawCard(now, open, session) {
  const parts = [];
  if (open.say) parts.push(el('div', 'ns-say ai-rich', { html: rich(open.say) }));

  if (open.question) {
    const q = open.question;
    parts.push(el('h4', 'ns-question', q.text));
    if (q.why) parts.push(el('p', 'ns-why', q.why));
    const picked = new Set();
    const options = el('div', 'ns-options', q.options.map((text) => {
      const button = el('button', 'ns-option', text);
      button.onclick = () => {
        if (!q.multi) return answer(session, { text }, button);
        picked.has(text) ? picked.delete(text) : picked.add(text);
        button.classList.toggle('on', picked.has(text));
      };
      return button;
    }));
    parts.push(options);
  } else if (open.plan?.length) {
    parts.push(el('h4', 'ns-question', t('editor.newscheme.plan')), el('ul', 'ns-plan', open.plan.map((p) => el('li', '', p))));
  }

  const own = el('input', 'input');
  own.placeholder = open.question ? t('editor.newscheme.own_answer') : t('editor.newscheme.add_detail');
  const send = el('button', 'btn btn-quiet', t('editor.newscheme.reply'));
  send.onclick = () => {
    const chosen = [...(open.question?.multi ? now.querySelectorAll('.ns-option.on') : [])].map((b) => b.textContent);
    const text = [...chosen, own.value.trim()].filter(Boolean).join('; ');
    if (!text) { own.focus(); return; }
    answer(session, { text }, send);
  };
  own.onkeydown = (event) => { if (event.key === 'Enter') send.click(); };
  const build = el('button', open.question ? 'btn btn-quiet' : 'btn btn-accent', open.question ? t('editor.newscheme.enough') : t('editor.newscheme.build'));
  build.onclick = () => answer(session, { build: 1, text: own.value.trim() }, build);

  now.replaceChildren(el('div', 'ns-card-now', [...parts,
    el('div', 'ns-own', [own, send]), el('div', 'ns-actions', [build])]));
  (open.question?.options?.length ? now.querySelector('.ns-option') : own)?.focus();
}

function drawDraft(now, draft, session) {
  const name = field(t('editor.folders.prompt_name'), draft.name || t('editor.dialog.new_scheme'));
  const make = el('button', 'btn btn-accent', t('editor.newscheme.create_folder'));
  make.disabled = !!draft.error;
  make.onclick = async () => {
    make.disabled = true;
    try {
      const made = await api.post('ai.build.create', { session: session.id, name: name.input.value.trim() });
      await openFolder(made.folder, t('editor.newscheme.created_builder'), made.problems);
    } catch { make.disabled = false; }
  };
  const fix = el('textarea', 'input');
  fix.rows = 2;
  fix.placeholder = t('editor.newscheme.fix_placeholder');
  const redo = el('button', 'btn btn-quiet', t('editor.newscheme.refine'));
  redo.onclick = () => {
    if (!fix.value.trim()) { fix.focus(); return; }
    answer(session, { text: fix.value.trim() }, redo);
  };

  const side = session.state === 'done' && draft.folder
    ? [el('div', 'ns-say ai-rich', { html: rich(draft.say || '') }), el('p', 'ns-ok', t('editor.newscheme.folder_created')),
       button(t('editor.newscheme.open_folder'), 'btn btn-accent', () => openFolder(draft.folder, t('editor.newscheme.folder_opened'))),
       button(t('editor.dialog.new_scheme'), 'btn btn-quiet', drawIntro)]
    : [el('div', 'ns-say ai-rich', { html: rich(draft.say || '') }), name.row, el('div', 'ns-actions', [make]),
       el('div', 'ns-redo', [fix, redo])];
  now.replaceChildren(el('div', 'ns-detail', [el('div', 'ns-map', [miniMap(draft.preview)]), el('div', 'ns-pass', side)]));
}

async function answer(session, payload, button) {
  if (button) button.disabled = true;
  const mine = turn;
  try {
    await api.post('ai.build.answer', { session: session.id, ...payload });
    if (stale(mine)) return;
    waitFrom = Date.now();
    drawBuilder();
  } catch { if (button) button.disabled = false; }
}

function stopPoll(reset = true) {
  clearInterval(poll);
  poll = null;
  if (reset) waitFrom = 0;
}

/* ── Общее ────────────────────────────────────────────────────── */

/** Новая папка создана: проект перечитать, папку открыть, окно закрыть. */
async function openFolder(folder, say, problems = []) {
  dialog.close();
  await loadProject();
  await loadFolder(folder);
  toast(problems?.length ? t('editor.newscheme.precheck', { say, problems: problems.join('; ') }) : say, !!problems?.length);
}

/**
 * Миникарта схемы: узлы по их местам на холсте и стрелки между ними — одной картинкой SVG.
 * Масштаб — по рамке всех узлов; подписи — начало названия.
 */
function miniMap(preview) {
  const nodes = preview?.nodes || [];
  const NS = 'http://www.w3.org/2000/svg';
  const svg = document.createElementNS(NS, 'svg');
  svg.classList.add('ns-svg');
  if (!nodes.length) return svg;
  const x0 = Math.min(...nodes.map((n) => n.x)) - 60, y0 = Math.min(...nodes.map((n) => n.y)) - 60;
  const x1 = Math.max(...nodes.map((n) => n.x + n.w)) + 60, y1 = Math.max(...nodes.map((n) => n.y + n.h)) + 60;
  svg.setAttribute('viewBox', `${x0} ${y0} ${x1 - x0} ${y1 - y0}`);
  const add = (tag, attrs, parent = svg) => {
    const node = document.createElementNS(NS, tag);
    for (const [k, v] of Object.entries(attrs)) node.setAttribute(k, v);
    parent.append(node);
    return node;
  };
  const defs = add('defs', {});
  const tip = add('marker', { id: 'ns-tip', viewBox: '0 0 10 10', refX: 9, refY: 5, markerWidth: 8, markerHeight: 8, orient: 'auto' }, defs);
  add('path', { d: 'M0,0 L10,5 L0,10 z', class: 'ns-tip' }, tip);

  const at = Object.fromEntries(nodes.map((n) => [n.ref, n]));
  for (const a of preview.arrows || []) {
    const from = at[a.from], to = at[a.to];
    if (!from || !to) continue;
    const fx = from.x + from.w / 2, tx = to.x + to.w / 2;
    let d;
    if (a.back) {
      const side = Math.max(from.x + from.w, to.x + to.w) + 70;
      d = `M${from.x + from.w},${from.y + from.h / 2} C${side},${from.y + from.h / 2} ${side},${to.y + to.h / 2} ${to.x + to.w},${to.y + to.h / 2}`;
    } else if (to.y > from.y + from.h / 2) {
      d = `M${fx},${from.y + from.h} C${fx},${(from.y + from.h + to.y) / 2} ${tx},${(from.y + from.h + to.y) / 2} ${tx},${to.y}`;
    } else {
      d = `M${from.x + from.w},${from.y + from.h / 2} L${to.x},${to.y + to.h / 2}`;
    }
    add('path', { d, class: 'ns-arrow' + (a.back ? ' back' : ''), 'marker-end': 'url(#ns-tip)' });
    if (a.branch === 'yes' || a.branch === 'no') {
      add('text', { x: fx + (tx >= fx ? 14 : -40), y: from.y + from.h + 30, class: 'ns-branch' }).textContent = a.branch === 'yes' ? t('editor.run.check_yes') : t('editor.run.check_no');
    }
  }
  for (const n of nodes) {
    const cx = n.x + n.w / 2, cy = n.y + n.h / 2;
    if (n.start) add('circle', { cx, cy, r: Math.min(n.w, n.h) / 2, class: 'ns-node start' });
    else if (n.type === 'decision') add('polygon', { points: `${cx},${n.y} ${n.x + n.w},${cy} ${cx},${n.y + n.h} ${n.x},${cy}`, class: 'ns-node decision' });
    else if (n.type === 'gateway') add('polygon', { points: `${n.x + 30},${n.y} ${n.x + n.w - 30},${n.y} ${n.x + n.w},${cy} ${n.x + n.w - 30},${n.y + n.h} ${n.x + 30},${n.y + n.h} ${n.x},${cy}`, class: 'ns-node gateway' });
    else add('rect', { x: n.x, y: n.y, width: n.w, height: n.h, rx: 18, class: 'ns-node' });
    const label = n.title.length > 22 ? n.title.slice(0, 21) + '…' : n.title;
    add('text', { x: cx, y: cy + 9, 'text-anchor': 'middle', class: 'ns-label' }).textContent = label;
  }
  return svg;
}

function field(label, value) {
  const input = el('input', 'input');
  input.value = value;
  const row = el('label', 'ns-field', [el('span', '', label), input]);
  return { row, input };
}

function stepsWord(n) {
  return tn('editor.newscheme.steps', n);
}

function button(text, cls, onClick) {
  const node = el('button', cls, text);
  node.onclick = onClick;
  return node;
}

function note(text) {
  return el('p', 'ns-note', text);
}

/** Узел: класс и содержимое — строка, список узлов или {html}. */
function el(tag, cls = '', content = '') {
  const node = document.createElement(tag);
  if (cls) node.className = cls;
  if (Array.isArray(content)) node.append(...content.map((one) => (typeof one === 'string' ? document.createTextNode(one) : one)));
  else if (content && typeof content === 'object' && 'html' in content) node.innerHTML = content.html;
  else if (content) node.textContent = content;
  return node;
}

