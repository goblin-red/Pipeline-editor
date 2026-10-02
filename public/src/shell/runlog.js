/* Лента прогона: что происходило, по шагам и с сырьём.
   Отдаёт: runLogStrip(), runLogFull(), runsList().
   Не делает: ничего не ведёт — только показывает записанное сервером.

   Вид строки (обе ленты — краткая и полная — одинаковы): сверху шапка прогона
   с полной датой начала; дальше строка на событие в две строчки —
     № шага · мм:сс · значок и номер элемента · кто
     что случилось (слова «ромб 138», «блок 137» заменены значками).
   Номер шага сквозной по прогону: в краткой ленте и под фильтром он не сбивается.

   Свёрнуто видны заголовки, раскрыл событие — приходит тело: пакет задания
   дословно, строка запуска, запрос к сервису, вывод терминала. Тела тянутся
   по требованию: в ленте они не нужны, а весят много. */

import { state, on, emit } from 'goblin/core/state.js';
import * as api from 'goblin/api/client.js';
import { loadRun } from 'goblin/api/sync.js';
import { KINDS, RUN_WORDS, STARTER_ICON, iconOf as kindIcon } from 'goblin/core/kinds.js';
import { t, tn } from 'goblin/core/i18n.js';

/* Кто написал событие: ярлык в строке и цвет по нему.
   Без этого лента сливается в одну простыню и не видно,
   где говорит leader, где worker, а где сам скрипт. */
const WHO = {
  lead:   'leader',
  worker: 'worker',
  script: t('editor.runlog.who_script'),
  human:  t('editor.runlog.who_human'),
  judge:  t('editor.pulse.jev'),
};

/* Джев в прогоне — дублёр: своей графой видно, что ему отправили
   и что он ответил. Стрелка показывает сторону. */
const JEV = { 'jev-ask': t('editor.runlog.jev_ask'), 'jev-say': t('editor.runlog.jev_say') };

/* Виды событий, которые человек чаще всего хочет отфильтровать. */
const FILTERS = [
  [t('editor.runlog.f_all'), null],
  [t('editor.runlog.f_submit'), 'submit,file'],
  [t('editor.runlog.f_fail'), 'fail'],
  [t('editor.runlog.f_message'), 'message'],
  [t('editor.runlog.f_job'), 'job'],
  [t('editor.runlog.f_drive'), 'drive'],
  [t('editor.pulse.jev'), 'jev-ask,jev-say'],
];

const el = (tag, cls, text) => {
  const node = document.createElement(tag);
  if (cls) node.className = cls;
  if (text !== undefined) node.textContent = text;
  return node;
};

/* Выбранное событие одно на весь экран: щёлкнул в ленте справа —
   та же строка подсветилась в полном журнале, и наоборот. */
let picked = null;

function pickEvent(id) {
  picked = picked === id ? null : id;
  emit('run-event', picked);
}

on('run-event', (id) => {
  for (const node of document.querySelectorAll('.runlog-line[data-event]')) {
    node.classList.toggle('on', Number(node.dataset.event) === id);
  }
  // В полном журнале ещё и подводим строку к глазам, раскрыв её шаг.
  const aim = document.querySelector('#run-log .runlog-line.on');
  if (!aim) return;
  aim.closest('details.runlog-step')?.setAttribute('open', '');
  aim.scrollIntoView({ block: 'center', behavior: 'smooth' });
});

const clock = (at) => String(at || '').slice(11, 19);
// Минуты и секунды: полное время — один раз, в шапке прогона.
const minsec = (at) => String(at || '').slice(14, 19);
const hourOf = (at) => String(at || '').slice(0, 13);
const size = (bytes) => (bytes < 1024 ? t('editor.runlog.bytes', { n: bytes }) : t('editor.runlog.kbytes', { n: (bytes / 1024).toFixed(1) }));

/* Значок элемента схемы вместо слов «блок», «ромб»: графа читается глазом.
   Значки — те же, что во всём редакторе (core/kinds.js). */
const ICON = { block: KINDS.block.icon, decision: KINDS.decision.icon, gateway: KINDS.gateway.icon, start: STARTER_ICON };

function elementByNo(no) {
  for (const element of state.elements.values()) if (element.no === no) return element;
  return null;
}

function iconOf(no) {
  return kindIcon(elementByNo(no));
}

/** «ромб 138» → «◇138», «блок 137» → «▢137»: номер и так в своей графе, слово лишнее. */
const iconify = (text) => String(text || '')
  .replace(/(?:ромб|decision)\s+(\d+)/g, `${ICON.decision}$1`)
  .replace(/(?:шлюз|gateway)\s+(\d+)/g, `${ICON.gateway}$1`)
  .replace(/(?:стартер|starter)\s+(\d+)/g, `${ICON.start}$1`)
  .replace(/(?:блок|block)\s+(\d+)/g, `${ICON.block}$1`);

/** Шапка ленты: какой прогон и когда начат — полной датой, один раз. */
function runHead() {
  const run = state.run;
  const at = String(run?.startedAt || '');
  const when = at ? `${at.slice(8, 10)}.${at.slice(5, 7)}.${at.slice(0, 4)} ${at.slice(11, 19)}` : '';
  const head = el('div', 'runlog-run');
  head.append(el('b', '', `r${run?.no ?? ''}`), el('span', '', when ? t('editor.run.started', { at: when }) : ''));
  return head;
}

/** Сквозной номер шага и соседнее событие — по полному списку прогона. */
function orderOf(all) {
  const order = new Map();
  all.forEach((one, i) => order.set(one.id, { n: i + 1, prev: all[i - 1] || null }));
  return order;
}

/**
 * Список прогонов папки с итогом у каждого.
 *
 * `plain` — просто список, без раскрывашки: так он живёт в левой плашке,
 * где сворачивает его сама плашка.
 */
export function runsList(holder, plain = false) {
  const box = el(plain ? 'div' : 'details', 'runs-list');
  const head = plain ? null : el('summary', 'runs-head');
  if (head) { box.open = false; box.append(head); }

  const draw = () => {
    const runs = state.runs || [];
    const now = state.run;
    if (head) {
      head.textContent = now
        ? `r${now.no} · ${now.title || t('editor.runlog.run')} — ${stateWord(now.state)}`
        : t('editor.runlog.runs_count', { n: runs.length });
    }

    for (const old of [...box.children].slice(head ? 1 : 0)) old.remove();
    for (const run of runs) {
      /* В списке только заголовок: итог и время — подсказкой, иначе
         архив разрастается в простыню и прогон в нём не найти. */
      const line = el('button', 'runs-item' + (now && run.id === now.id ? ' on' : ''));
      line.append(el('b', '', `r${run.no}`), el('span', '', run.title || t('editor.runlog.run')));
      line.title = [stateWord(run.state), (run.startedAt || '').slice(5, 16),
        run.summary].filter(Boolean).join(' · ');
      // Выбор человека закрепляется в адресе: опрос не перебьёт его живым прогоном.
      line.onclick = () => { api.setHash({ run: run.id }); loadRun(run.id); if (head) box.open = false; };
      box.append(line);
    }
    if (!runs.length) box.append(el('div', 'runs-empty', t('editor.runlog.no_runs')));
  };

  draw();
  const stop = on('run', draw);
  holder.append(box);
  return stop;
}

function stateWord(name) {
  return RUN_WORDS[name] || name;
}

/** Короткая лента для панели: последние события и кнопка «открыть целиком». */
export function runLogStrip(holder, onOpen) {
  const box = el('div', 'runlog-strip');
  holder.append(box);

  /* Отрисовка ждёт ответ сервера, а события во время прогона приходят часто.
     Без счётчика две отрисовки успевали дописать в одну и ту же коробку,
     и лента выходила задвоенной. */
  let turn = 0;

  const draw = async () => {
    const mine = ++turn;
    if (!state.run) { box.textContent = ''; return; }

    const answer = await api.get('run.log', { run: state.run.id, limit: 200 }).catch(() => null);
    if (mine !== turn) return;                 // нас обогнали — рисует другой

    box.textContent = '';
    const all = answer?.events || [];
    const order = orderOf(all);
    const events = all.slice(-12).reverse();             // новое сверху, как в полном журнале
    if (events.length) box.append(runHead());
    for (const one of events) box.append(lineOf(one, false, order.get(one.id), true));

    if (events.length) {
      const more = el('button', 'btn btn-quiet btn-wide', t('editor.runlog.open_all'));
      more.onclick = onOpen;
      box.append(more);
    }
  };

  return redrawOnChange(draw);
}

/** Полный журнал: шаги, внутри — события, внутри события — сырьё. */
export function runLogFull(holder) {
  const box = el('section', 'runlog');
  const bar = el('div', 'runlog-bar');
  const body = el('div', 'runlog-body');
  box.append(bar, body);
  holder.append(box);

  let kind = null;
  let flat = false;
  let turn = 0;

  const draw = async () => {
    const mine = ++turn;
    if (!state.run) {
      body.textContent = '';
      body.append(el('div', 'runs-empty', t('editor.runlog.none_chosen')));
      return;
    }

    const answer = await api.get('run.log', { run: state.run.id, limit: 500 }).catch(() => null);
    if (mine !== turn) return;                 // пока ждали, началась новая отрисовка

    body.textContent = '';
    /* Фильтр — здесь, а не на сервере: номер шага сквозной по всему прогону,
       и под фильтром он должен остаться тем же. */
    const all = answer?.events || [];
    const order = orderOf(all);
    const kinds = kind ? kind.split(',') : null;
    const events = kinds ? all.filter((one) => kinds.includes(one.kind)) : all;
    if (!events.length) {
      body.append(el('div', 'runs-empty', t('editor.runlog.no_events')));
      return;
    }

    body.append(runHead());
    // Новое сверху, старое снизу.
    const newest = [...events].reverse();
    if (flat || kind) {
      for (const one of newest) body.append(lineOf(one, true, order.get(one.id)));
      return;
    }

    /* Одна лента, новое сверху: строки leader идут как есть, а там, где
       было последнее событие шага, встаёт свёрнутая плашка с его событиями. */
    const keys = stepKeys(events);
    const groups = new Map();
    for (const one of events) {
      if (!one.no) continue;
      const key = keys.get(one.id);
      if (!groups.has(key)) groups.set(key, []);
      groups.get(key).push(one);
    }

    const shown = new Set();
    for (const one of newest) {
      if (!one.no) { body.append(lineOf(one, true, order.get(one.id))); continue; }

      const key = keys.get(one.id);
      if (shown.has(key)) continue;            // событие уже внутри своей плашки
      shown.add(key);
      body.append(groupOf(key, groups.get(key), order));
    }
  };

  // Полоса сверху: фильтры по виду и переключатель «лентой».
  for (const [name, value] of FILTERS) {
    const button = el('button', 'runlog-filter' + (value === kind ? ' on' : ''), name);
    button.onclick = () => {
      kind = value;
      for (const other of bar.querySelectorAll('.runlog-filter')) other.classList.remove('on');
      button.classList.add('on');
      draw();
    };
    bar.append(button);
  }
  const flatButton = el('button', 'runlog-filter', t('editor.runlog.flat'));
  flatButton.onclick = () => {
    flat = !flat;
    flatButton.classList.toggle('on', flat);
    draw();
  };
  bar.append(flatButton);

  return redrawOnChange(draw);
}

/**
 * Лента перечитывается, когда прогон и вправду сменился: другой прогон, новая
 * версия (run.state) или шаги прежнего движка. Тики очереди показа тоже шлют
 * 'steps' — на них лента не мигает и сервер лишний раз не спрашивает.
 * Отдаёт отписку.
 */
function redrawOnChange(draw) {
  let seen = runKey();
  let timer = null;
  const check = (force) => {
    clearTimeout(timer);
    timer = setTimeout(() => {
      const now = runKey();
      if (!force && now === seen) return;
      seen = now;
      draw();
    }, 150);
  };
  draw();
  const stops = [on('run', () => check(true)), on('runstate', () => check(false)), on('steps', () => check(false))];
  return () => { clearTimeout(timer); stops.forEach((stop) => stop()); };
}

const runKey = () => `${state.run?.id ?? ''}|${state.run?.version ?? ''}|`
  + [...state.steps.values()].map((step) => `${step.id}:${step.state}:${step.attempt}`).join(',');

/**
 * Шаг каждого события: «138.3». У вопроса и ответа Jev попытки нет — они
 * относятся к попытке того же элемента, что идёт следом (решение ромба).
 * Иначе вопросы Jev всех кругов сваливались в одну плашку «138.1».
 */
function stepKeys(events) {
  const keys = new Map();
  let next = new Map();                          // номер элемента → ближайшая попытка впереди
  for (let i = events.length - 1; i >= 0; i--) {
    const one = events[i];
    if (!one.no) continue;
    if (one.attempt) next.set(one.no, one.attempt);
    keys.set(one.id, `${one.no}.${one.attempt || next.get(one.no) || 1}`);
  }
  return keys;
}

/**
 * Плашка одного шага: заголовок с номером, названием и итогом,
 * внутри — все его события. Свёрнута, пока с шагом всё в порядке.
 */
function groupOf(key, list, order) {
  const bad = list.some((one) => ['fail', 'return'].includes(one.kind));
  const group = el('details', 'runlog-step' + (bad ? ' runlog-bad' : ''));
  group.open = bad || list.some((one) => one.kind === 'judge');

  const last = list[list.length - 1];
  const took = spanOf(list);

  const head = el('summary', 'runlog-step-head');
  head.append(
    el('b', 'runlog-key', iconOf(list[0].no) + key),
    el('span', 'runlog-name', nameOf(list[0].no)),
    el('small', 'runlog-meta', [countWord(list.length), took, last.what].filter(Boolean).join(' · ')),
  );
  group.append(head);

  for (const one of [...list].reverse()) group.append(lineOf(one, true, order.get(one.id)));   // новое сверху
  return group;
}

/** Название блока по номеру: в заголовке шага «35.2» само по себе немо. */
function nameOf(no) {
  for (const element of state.elements.values()) {
    if (element.no === no) return element.title || t('editor.info.untitled');
  }
  return t('editor.runlog.block_no', { no });
}

/** «1 событие», «2 события», «6 событий» — иначе заголовок читается коряво. */
function countWord(count) {
  return tn('editor.runlog.events', count);
}

/** Сколько заняли события шага: от первого до последнего. */
function spanOf(list) {
  const first = Date.parse(String(list[0].at).replace(' ', 'T'));
  const last = Date.parse(String(list[list.length - 1].at).replace(' ', 'T'));
  if (!first || !last || last <= first) return '';
  const sec = Math.round((last - first) / 1000);
  return sec < 90 ? t('editor.run.seconds', { n: sec }) : t('editor.runlog.min_sec', { m: Math.floor(sec / 60), s: sec % 60 });
}

/**
 * Строка события. Если у события есть тело, строка раскрывается: тело
 * запрашивается у сервера в этот момент, а не заранее.
 */
function lineOf(one, canOpen, place = null, brief = false) {
  const mark = one.id === picked ? ' on' : '';
  const cls = `runlog-line kind-${one.kind} who-${one.actor || 'script'}${mark}`;

  if (!one.size || !canOpen) {
    const plain = el('div', cls);
    headInto(plain, one, false, place, brief);
    return pickable(plain, one.id);
  }

  const box = el('details', cls + ' has-body');
  const head = el('summary', '');
  headInto(head, one, true, place, brief);
  box.append(head);
  const pre = el('pre', 'runlog-body-text', t('editor.runlog.reading'));
  box.append(pre);

  box.ontoggle = async () => {
    if (!box.open || box.dataset.got) return;
    box.dataset.got = '1';
    const answer = await api.get('run.log', { run: state.run.id, event: one.id }).catch(() => null);
    pre.textContent = answer?.event?.body || t('editor.runlog.empty');
  };
  return pickable(box, one.id);
}

/**
 * Строка-таблица в две строчки:
 *   ▸ · № шага · мм:сс · ◇138.2 · кто
 *       что случилось
 * Время — минуты и секунды; сменился час — полное, чтобы не спутать.
 * Значок раскрытия стоит и у строк без тела — пустым: иначе графы разъезжаются.
 */
function headInto(node, one, canOpen, place, brief = false) {
  const prev = place?.prev;
  const time = prev && hourOf(prev.at) !== hourOf(one.at) ? clock(one.at) : minsec(one.at);
  node.append(
    el('span', 'runlog-caret', canOpen ? '▸' : ''),
    el('span', 'runlog-n', place ? String(place.n) : ''),
    el('span', 'runlog-time', time),
    // Попытки у события нет (вопрос Jev) — только номер элемента, без выдуманной «.1».
    el('span', 'runlog-el', one.no ? `${iconOf(one.no)}${one.no}${one.attempt ? '.' + one.attempt : ''}` : ''),
    el('span', 'runlog-who', JEV[one.kind] || WHO[one.actor] || one.actor || t('editor.runlog.who_script')),
    el('span', 'runlog-what', whatOf(one, brief)),
  );
}

/* Размер в подписи события: «· 2,9 КБ», «· 645 Б». Сервер иногда пишет его сам. */
const SIZE_TAIL = /\s·\s[\d.,]+(?:\s\d+)*\s(?:Б|КБ|МБ|B|KB|MB)(?=\s|$)/gu;

/**
 * Что случилось — текстом графы. Краткая лента (панель справа) — без байтов:
 * размер нужен, когда разбирают сырьё, а это полный журнал. В полном — размер
 * один раз: если сервер уже вписал его в подпись, второй раз не добавляем.
 */
function whatOf(one, brief) {
  let text = iconify(one.title) + (one.tookMs ? ` · ${t('editor.run.seconds', { n: (one.tookMs / 1000).toFixed(1) })}` : '');
  if (brief) return text.replace(SIZE_TAIL, '');
  if (one.size && !SIZE_TAIL.test(text)) text += ` · ${size(one.size)}`;
  SIZE_TAIL.lastIndex = 0;
  return text;
}

/** Строка журнала выделяется по щелчку — и здесь, и в другой ленте. */
function pickable(node, id) {
  node.dataset.event = String(id);
  node.addEventListener('click', (e) => {
    if (e.target.closest('.runlog-body-text')) return;   // выделяют текст тела
    pickEvent(id);
  });
  return node;
}
