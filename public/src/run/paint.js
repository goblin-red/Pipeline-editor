/* Подсветка прогона по слову сервера: какой блок и какая стрелка как горят.
   Отдаёт: applyPaint(), applyState(), hasServerPaint(), blockLook(), edgeLook(),
           reviewOf(), refreshRun(), openBlocks().
   Не делает: не угадывает подсветку по шагам — это прежний путь прогонов
              engine = 1 (canvas/arrow.js::trace), не решает правил прогона
              и сам команд не отправляет. Ручные команды человека (стоп,
              состояние шага) шлёт панель, а отсюда берёт только refreshRun().

   Ответ run.paint (договор — md_backend/ru/progon.md) ложится в state.paint целиком.
   Ответ чужого прогона или не новее показанного отбрасывается: версия прогона
   растёт всегда, и устаревший ответ экран не откатывает. Выросла эпоха (run.reset
   живого прогона, тот же id) — прежняя картинка и очередь показа уже не его.

   Пауза показа N секунд (серверная, show_pause): после события N секунд
   вспыхивает блок, затем N секунд бежит стрелка. Отсчёт — по отметкам `at`
   и серверному `now`: своим часам клиент не верит, берёт только ход
   времени с момента ответа.

   Подключается сам при первом импорте: следит за сменой прогона и, если
   у прогона новый движок (engine = 2), ждёт перемен через run.state. */

import * as api from 'goblin/api/client.js';
import { state, on, emit } from 'goblin/core/state.js';
import { runShowMs } from 'goblin/core/settings.js';
import { t } from 'goblin/core/i18n.js';

let follow = 0;           // номер слежения: сменился прогон — прежнее слежение гаснет
let phaseTimer = null;    // следующая граница паузы: блок погас, стрелка побежала
let followProject = '';   // проект этого слежения: hash может смениться раньше ответа
let quietNext = false;    // вкладку прятали: следующая картина ложится сразу, без повтора переходов

on('run', restart);
// Сменили задержку показа в настройках — очередь пересчитывается сразу.
on('settings', (name) => { if (name === null || name?.startsWith('runShow')) pumpShow(); });

/* ── Приём ответа ────────────────────────────────────────────── */

/**
 * Положить ответ run.paint в state.paint и перерисовать холст.
 * Отдаёт false, если ответ отброшен: чужой прогон или версия не новее.
 */
export function applyPaint(answer) {
  if (!answer || !state.run || Number(answer.run) !== state.run.id) return false;
  const shown = state.paint;
  const same = !!shown && shown.run === Number(answer.run);
  const epoch = Number(answer.epoch) || 0;
  // Прежняя жизнь прогона или версия не новее — устаревший ответ экран не откатывает.
  if (same && (epoch < shown.epoch || (epoch === shown.epoch && Number(answer.version) <= shown.version))) return false;

  state.paint = {
    run: Number(answer.run),
    version: Number(answer.version),
    epoch,
    state: String(answer.state || state.run.state || ''),
    pause: Math.max(0, Number(answer.pause) || 0),
    now: timeOf(answer.now),
    got: performance.now(),
    blocks: new Map((answer.blocks || []).map((b) => [Number(b.id), { ...b, at: timeOf(b.at) }])),
    edges: new Map((answer.edges || []).map((e) => [Number(e.id), { ...e, at: timeOf(e.at) }])),
  };
  enqueuePaint(state.paint, same && epoch > shown.epoch);   // каждый переход — на экран не меньше showMs()
  repaint();
  return true;
}

/**
 * Снимок run.state: где мы и чего ждём. Ложится в state.runState целиком.
 * Отдаёт false, если ответ чужого прогона или старее показанного.
 */
export function applyState(answer) {
  if (!answer || !state.run || Number(answer.run?.id) !== state.run.id) return false;
  const shown = Number(state.runState?.run?.version ?? -1);
  const incoming = Number(answer.run?.version ?? -1);
  if (shown > incoming || Number(answer.epoch ?? 0) < Number(state.runState?.epoch ?? 0)) return false;

  // run.state — источник текущего состояния нового движка. Не оставляем
  // state.run снимком от прежнего run.get: иначе после паузы или завершения
  // полоса продолжала показывать «идёт».
  Object.assign(state.run, answer.run);
  const listed = (state.runs || []).find((run) => run.id === state.run.id);
  if (listed) Object.assign(listed, answer.run);

  state.runState = {
    ...answer,
    open: answer.open || [],
    ready: answer.ready || [],
    wakeIn: answer.wakeIn ?? null,
    now: timeOf(answer.now),
  };
  state.ready = answer.ready || [];
  if (Array.isArray(answer.steps)) applySteps(answer.steps, answer.open || []);
  emit('runstate');
  emit('steps');          // карточки показывают итог проверок сданного шага
  return true;
}

/** Полный run.state отдаёт короткие шаги; сохраняем уже известные подробности. */
function applySteps(steps, open) {
  const old = new Map([...state.steps.values()].map((step) => [step.id, step]));
  const opened = new Map(open.map((step) => [step.id, step]));
  const latest = new Map();

  for (const short of steps) {
    const step = { ...(old.get(short.id) || {}), ...short, ...(opened.get(short.id) || {}) };
    step.title ||= [...state.elements.values()].find((element) => element.no === step.no)?.title || '';
    const known = latest.get(step.no);
    if (!known || Number(known.attempt) <= Number(step.attempt)) latest.set(step.no, step);
  }
  state.steps.clear();
  for (const [no, step] of latest) state.steps.set(no, step);
}

/** Итог формальных проверок сданного шага этого блока — подсказка перед приёмкой. */
export function reviewOf(element) {
  const step = (state.runState?.open || []).find((one) => one.no === element.no && one.state === 'submitted');
  const review = step?.review;
  if (typeof review === 'string') return review;
  const lines = review?.checks?.lines;
  if (Array.isArray(lines) && lines.length) {
    return lines.map((line) => line.say || `${line.name || t('editor.run.check')} — ${line.ok === true ? t('editor.run.check_yes') : line.ok === false ? t('editor.run.check_no') : '?'}`)
      .filter(Boolean).join(' · ');
  }
  return (review?.checks?.reasons || []).join(' · ');
}

/** Подсветка этого прогона пришла от сервера — значит, угадывать не нужно. */
export function hasServerPaint() {
  return !!(state.paint && state.run && state.paint.run === state.run.id);
}

/** Узлы, открытые на экране сейчас (выдан, в работе, сдан, ждёт), по номерам: за ними едет run/follow.js. */
export function openBlocks() {
  if (!hasServerPaint()) return [];
  return [...display.blocks]
    .filter(([, shown]) => OPENED.has(shown.look?.state))
    .map(([id]) => state.elements.get(id))
    .filter(Boolean)
    .sort((a, b) => a.no - b.no);
}

/* Прогон жив, пока он идёт или на паузе. */
const LIVE = new Set(['running', 'paused']);

/** Прогон закрыт: так говорит картинка сервера или уже свежий снимок run.state. */
function runClosed() {
  return [state.paint?.state, state.run?.state].some((one) => one && !LIVE.has(one));
}

/** Серверное «сейчас» в миллисекундах: отметка ответа плюс сколько прошло с него. */
function serverNow() {
  if (!state.paint) return 0;
  return state.paint.now + (performance.now() - state.paint.got);
}

/* ── Что показать ────────────────────────────────────────────── */

/**
 * Узел для карточки — в том же виде, что шаг прогона: состояние, круг, ответ.
 * null — сервер подсветку не прислал (старый прогон), {none: true} — узел не тронут.
 * attempt — круг цикла (lap: у принятого — сколько проходов, у текущей работы —
 * следующий), tries — номер попытки из адреса N.K: это разные числа.
 */
export function blockLook(element) {
  if (!hasServerPaint()) return null;
  // На экране — только то, чей черёд настал: узел, чей переход ещё в очереди, стоит как был.
  const shownBlock = display.blocks.get(element.id);
  const block = shownBlock?.look;
  if (!block || block.none) return { none: true };
  const round = Number(block.round) || 0;
  return {
    no: element.no,
    state: block.state,
    attempt: Number(block.lap) || (block.state === 'accepted' ? round : round + 1) || 1,
    round: true,
    tries: Number(block.attempt) || 0,
    result: block.result || '',
    error: block.error || '',
    branch: block.branch || '',
    // Чего ждёт готовый узел — код сервера: pause | decision | pass | person. Текст waitFor не разбираем.
    wait: block.state === 'ready' ? String(block.wait || '') : '',
    // Картинка-результат этого прогона для блока — у старого прогона тоже, после сброса схемы.
    cover: block.cover ?? null,
    // Мигает, пока новое состояние держится свой срок показа, — или в паузе показа.
    flash: fresh(shownBlock) || holding(block.at),
  };
}

/**
 * Стрелка: класс линии и сколько раз по ней прошли («×N»).
 *   live — бежит: показ нового прохода (очередь показа) или жетон в пути в паузе показа;
 *          N — вместе с этим проходом;
 *   lit  — жетон лежит и ждёт у входа: ромба, слияния, шлюза, человека; N — уже пройдено;
 *   done — жетоны были и забраны: пройдена N раз.
 */
export function edgeLook(id) {
  if (!hasServerPaint()) return { cls: '', pass: 0 };
  const edge = display.edges.get(id)?.look;
  if (!edge || edge.none) return { cls: '', pass: 0 };
  const pass = Number(edge.pass) || 0;
  /* Прогон закрыт — это проверяется раньше любой анимации: жетон, вернувшийся на
     стрелку при остановке, никуда уже не побежит, и показ прошлого прохода тоже
     не бежит. Бегущий пунктир врал бы, что работа идёт. */
  const closed = runClosed();
  if (edge.pulse && !closed) return { cls: 'live', pass };
  if (edge.state === 'done') return { cls: 'done', pass: Number(edge.passed ?? pass) || pass };
  if (edge.state !== 'lit') return { cls: '', pass: 0 };

  // Сколько раз по стрелке уже прошли: забранные жетоны (у старого ответа — pass − 1).
  const passed = Number(edge.passed ?? pass - 1) || 0;
  const before = { cls: passed > 0 ? 'done' : '', pass: passed };
  if (closed) return before;
  if (holding(edge.at)) return before;                         // пауза показа: сначала вспыхивает блок
  if (travelling(edge.at)) return { cls: 'live', pass };      // пауза показа: жетон в пути
  return { cls: 'lit', pass: passed };
}

/* ── Каждый переход на экране — не меньше N секунд ───────────────
   Сервер может сменить состояние блока за секунду: выдан → в работе →
   принят. Покажи мы только последнее, человек не увидит, что переход был.
   Поэтому между сервером и холстом стоит очередь показа:
     — каждое новое состояние блока и стрелки держится на экране свой срок
       и мигает всё это время;
     — что пришло за эти секунды, встаёт в очередь и показывается следом,
       по порядку: ни один статус не теряется, последний доходит всегда;
     — в одной картине переход идёт по схеме волной (stage): закрылся блок,
       побежала его стрелка, открылся следующий — каждый шаг на полсрока позже;
     — задержка ограничена (lagMax): отстала очередь — переходы показываются
       короче, а всё, что ждёт дольше lagMax, сворачивается до последнего из
       просроченного: прошлое не выдаёт себя за живой статус дольше lagMax;
     — остановка, конец и срыв важнее накопленного: закрытый прогон ложится
       сразу, без очереди и бегущих стрелок, сорванный блок — вне очереди;
     — реквизиты той же стадии (адрес попытки, причина, забранные жетоны)
       обновляются на месте, без нового перехода и вспышки.
   Первая картина прогона (открыли страницу, сменили прогон, вернулись на
   спрятанную вкладку, сбросили прогон) ложится сразу, без очереди и мигания:
   это не переход, а исходное состояние. Кроме прогона, начатого только что
   (JUST_STARTED_MS): его начало показываем переходами — стартер мигает.

   Это фича наглядности, а не часть прогона: срок задаёт человек в настройках
   (core/settings.js → runShowMs(), по умолчанию 3 с). Выключена — очереди
   нет, на экран сразу ложится последнее состояние сервера. */

const showMs = () => runShowMs();
const MIN_HOLD = 400;                                     // короче переход глаз не заметит
const lagMax = () => Math.max(6000, 2 * showMs());        // дольше прошлое не держит экран

/* id → { look, since, hold, fresh }: что сейчас на экране, с какого мига и на сколько.
   fresh — состояние пришло переходом, а не первой картиной: оно мигает. */
const display = { blocks: new Map(), edges: new Map(), run: null };
/* id → [{ look, got, after }, …]: что пришло, пока предыдущее ещё держится.
   got — когда пришло, after — раньше этого мига не показывать (волна). */
const waiting = { blocks: new Map(), edges: new Map() };
let showTimer = null;

/* Подпись стадии: сменилась — это переход, он держится свой срок и мигает. */
const blockStage = (b) => b.none ? 'none'
  : `${b.state}|${b.round}|${b.lap ?? ''}|${b.branch || ''}|${b.wait || ''}|${b.cover ?? ''}|${b.result || ''}`;
const edgeStage = (e) => e.none ? 'none' : `${e.state}|${e.pass}`;
/* Полная подпись: ещё и реквизиты — адрес попытки, причина, забранные жетоны. */
const blockSig = (b) => b.none ? 'none' : `${blockStage(b)}|${b.attempt ?? ''}|${b.error || ''}`;
const edgeSig = (e) => e.none ? 'none' : `${edgeStage(e)}|${e.passed ?? ''}`;
const SIGS = { blocks: [blockStage, blockSig], edges: [edgeStage, edgeSig] };

/** Новое состояние мигает, пока держится свой срок показа. */
function fresh(item) {
  return !!item && item.fresh && performance.now() - item.since < item.hold;
}

/* Прогон начат не раньше этого срока — его первую картину смотрят вживую. */
const JUST_STARTED_MS = 20000;

/** Очередь показа прежнего прогона больше не наша. */
function clearShow() {
  clearTimeout(showTimer);
  showTimer = null;
  display.run = null;
  for (const kind of ['blocks', 'edges']) { display[kind].clear(); waiting[kind].clear(); }
}

/** Разложить свежую картину сервера по очередям показа. reset — живой сброс того же прогона. */
function enqueuePaint(paint, reset) {
  const first = display.run !== paint.run || reset || quietNext;
  if (first) {
    clearShow();
    display.run = paint.run;
  }
  /* Прогон только что начат (begin leader), и мы смотрим его вживую: первая
     картина — не «исходное состояние», а переходы. Стартер мигает принятым,
     потом бежит его стрелка, потом мигает выданный первый блок. */
  const started = timeOf(state.run?.startedAt);
  const live = first && !reset && !quietNext && state.run?.state === 'running'
    && started > 0 && paint.now - started < JUST_STARTED_MS;
  /* Прогон закрыт (остановлен, пройден, сорван): конец важнее накопленной анимации —
     картина ложится сразу, очередь и бегущие стрелки прошлых проходов снимаются. */
  const closed = !LIVE.has(paint.state);
  const quiet = (first && !live) || !showMs() || closed;
  const now = performance.now();
  const blocks = enqueueKind('blocks', paint.blocks, quiet, now);
  const edges = enqueueKind('edges', paint.edges, quiet, now);
  if (!quiet) stage(blocks, edges, now);
  pumpShow();
}

/** Сравнить картину с экраном, новое — в очередь. Отдаёт id → то, что встало в очередь сейчас. */
function enqueueKind(kind, incoming, quiet, now) {
  const [stageOf, sigOf] = SIGS[kind];
  const added = new Map();
  /* Сверяется всё: что в картине, что на экране и что ещё ждёт показа. Пропавшее
     из картины (сброс шага) — тоже переход, в том числе у узла, чей показ ещё в очереди. */
  const ids = new Set([...incoming.keys(), ...display[kind].keys(), ...waiting[kind].keys()]);
  for (const id of ids) {
    const look = incoming.get(id) || { none: true };
    if (quiet) {
      display[kind].set(id, { look, since: now, hold: 0, fresh: false });
      waiting[kind].delete(id);
      continue;
    }
    // Узла нет в полной картине: его отложенные переходы устарели — старый статус не вернётся.
    if (look.none) waiting[kind].delete(id);
    const queue = waiting[kind].get(id) || [];
    const shown = display[kind].get(id);
    const tail = queue[queue.length - 1];
    const last = tail ? tail.look : shown?.look;
    if (!last && look.none) continue;                   // не было и нет
    if (last && sigOf(last) === sigOf(look)) continue;  // ничего не сменилось
    // Та же стадия, новые реквизиты (адрес, причина, забранные жетоны): запись — на месте.
    if (last && !last.pulse && stageOf(last) === stageOf(look)) {
      if (tail) tail.look = look;
      else shown.look = look;
      continue;
    }
    // Срыв виден сразу: прошлые переходы узла его не заслоняют.
    if (kind === 'blocks' && look.state === 'failed') {
      waiting[kind].delete(id);
      display[kind].set(id, { look, since: now, hold: showMs(), fresh: true });
      continue;
    }

    const items = [];
    /* Новый проход по стрелке. В круге leader done сразу выдаёт следующий блок и
       забирает жетон: «жетон на стрелке» живёт миллисекунды, и сервер почти
       всегда отдаёт стрелку уже пройденной. Поэтому каждый новый проход
       сначала показываем бегущей стрелкой — сами, раз сервер не успел. */
    if (kind === 'edges' && !look.none) {
      const before = last && !last.none ? Number(last.pass) || 0 : 0;
      if (Number(look.pass) > before) items.push({ look: { ...look, pulse: true }, got: now, after: now });
    }
    items.push({ look, got: now, after: now });
    queue.push(...items);
    waiting[kind].set(id, queue);
    added.set(id, items);
  }
  return added;
}

/* Что закрылось, а что открылось: у открытого узла проход стрелки — прошлый. */
const OPENED = new Set(['issued', 'running', 'submitted', 'ready']);

/**
 * Волна по схеме в одной картине: блок закрылся (ступень 0) → побежала его
 * стрелка (1) → открылся или решился следующий узел (2) → … Каждая ступень —
 * на полсрока позже прошлой, не дальше четвёртой. Стрелка открытого узла
 * бежит с нулевой ступени: её проход — от прошлого закрытия этого узла.
 */
function stage(blocks, edges, now) {
  const level = new Map();
  const lastLook = (items) => items[items.length - 1].look;
  for (let round = 0; round < 8; round++) {
    let moved = false;
    for (const [id, items] of edges) {
      if (!items[0].look.pulse) continue;
      const arrow = state.elements.get(id);
      if (!arrow) continue;
      const from = blocks.get(arrow.from);
      const mine = from && !OPENED.has(lastLook(from).state) ? Math.min(4, (level.get('b' + arrow.from) || 0) + 1) : 0;
      if ((level.get('e' + id) || 0) < mine) { level.set('e' + id, mine); moved = true; }
      if (!blocks.has(arrow.to)) continue;
      const to = Math.min(4, mine + 1);
      if ((level.get('b' + arrow.to) || 0) < to) { level.set('b' + arrow.to, to); moved = true; }
    }
    if (!moved) break;
  }
  const step = showMs() / 2;
  for (const [kind, list] of [['b', blocks], ['e', edges]]) {
    for (const [id, items] of list) {
      const after = now + (level.get(kind + id) || 0) * step;
      for (const item of items) item.after = after;
    }
  }
}

/** Вывести на экран всё, чей черёд настал, и завести будильник на следующее. */
function pumpShow() {
  clearTimeout(showTimer);
  showTimer = null;
  const now = performance.now();
  const hold = showMs();
  let changed = false;
  let next = Infinity;

  // Задержку выключили на ходу — очередь сразу сбрасывается до последнего состояния.
  if (!hold) {
    for (const kind of ['blocks', 'edges']) {
      for (const [id, queue] of waiting[kind]) {
        const look = queue.map((one) => one.look).filter((one) => !one.pulse).pop();
        if (look) display[kind].set(id, { look, since: now, hold: 0, fresh: false });
        changed = true;
      }
      waiting[kind].clear();
    }
    if (changed) { emit('paint'); emit('steps'); }
    return;
  }

  const limit = lagMax();
  for (const kind of ['blocks', 'edges']) {
    for (const [id, queue] of waiting[kind]) {
      /* Просроченное (ждёт дольше lagMax) — уже история: по одному переходу его не
         догнать. Сворачиваем до последнего из просроченного, он встаёт сразу. */
      const overdue = queue.findLastIndex((one) => one.got + limit <= now);
      if (overdue > 0) queue.splice(0, overdue);
      const shown = display[kind].get(id);
      const item = queue[0];
      // Своя очередь, волна и предел задержки: прошлое не держит экран дольше lagMax.
      const due = Math.min(Math.max(shown ? shown.since + shown.hold : now, item.after), item.got + limit);
      if (due > now) { next = Math.min(next, due); continue; }
      queue.shift();
      // Сзади ждут ещё — показываем короче: очередь догоняет сервер, но каждый переход виден.
      const behind = queue.length;
      const left = item.got + limit - now;
      const mine = behind ? Math.max(MIN_HOLD, Math.min(hold, left / (behind + 1))) : hold;
      display[kind].set(id, { look: item.look, since: now, hold: mine, fresh: true });
      changed = true;
      // Проснуться и тогда, когда последнее в очереди станет просроченным: оно встанет сразу.
      if (behind) next = Math.min(next, Math.max(now + mine, queue[0].after), queue[behind - 1].got + limit);
      else waiting[kind].delete(id);
    }
    // Мигание гаснет само по истечении срока — перерисовать и в этот миг.
    for (const item of display[kind].values()) {
      if (item.fresh && item.since + item.hold > now) next = Math.min(next, item.since + item.hold);
    }
  }
  if (changed) { emit('paint'); emit('steps'); }
  if (next !== Infinity) {
    showTimer = setTimeout(() => { pumpShow(); emit('steps'); }, Math.max(20, next - now + 20));
  }
}

/** Идёт ли ещё первая половина паузы показа после события в миг `at`. */
function holding(at) {
  const pause = state.paint?.pause || 0;
  return pause > 0 && at > 0 && serverNow() < at + pause * 1000;
}

/** Идёт ли вторая половина паузы показа: жетон в пути к следующему узлу. */
function travelling(at) {
  const pause = state.paint?.pause || 0;
  return pause > 0 && at > 0 && serverNow() < at + 2 * pause * 1000;
}

/* ── Перерисовка и границы паузы ─────────────────────────────── */

/* Холст перерисовывает подсветку по событию 'steps' (canvas/view.js) —
   тем же путём, что и для прежних прогонов. 'paint' — для полосы прогона. */
function repaint() {
  emit('paint');
  emit('steps');
  planPhase();
}

/** Завести будильник на ближайшую границу паузы: там меняется картинка. */
function planPhase() {
  clearTimeout(phaseTimer);
  phaseTimer = null;
  const pause = state.paint?.pause || 0;
  if (!pause) return;

  const now = serverNow();
  let next = Infinity;
  for (const item of [...state.paint.blocks.values(), ...state.paint.edges.values()]) {
    for (const halves of [1, 2]) {
      const edge = item.at + halves * pause * 1000;
      if (item.at > 0 && edge > now) next = Math.min(next, edge);
    }
  }
  if (next === Infinity) return;
  phaseTimer = setTimeout(repaint, next - now + 30);
}

/* ── Слежение за прогоном ────────────────────────────────────── */

/**
 * Перечитать прогон сейчас: после ручной команды человека (стоп, смена
 * состояния шага). Слежение закрытого прогона уже кончилось, а картинка
 * должна смениться и у него.
 */
export function refreshRun() {
  restart();
}

/** Сменился прогон: прежняя подсветка, снимок и очередь показа больше не наши. */
function restart() {
  follow++;
  followProject = state.run ? api.projectKey() : '';
  if (state.paint && state.paint.run !== state.run?.id) {
    state.paint = null;
    clearShow();
    clearTimeout(phaseTimer);
    emit('paint');
  }
  if (state.runState && Number(state.runState.run?.id) !== state.run?.id) {
    state.runState = null;
    emit('runstate');
  }
  if (state.run && Number(state.run.engine) === 2) watch(follow, state.run.id, followProject);
}

/**
 * Ждать перемен прогона и брать свежую картинку. run.state держит запрос,
 * пока версия не вырастет (до 25 с); выросла — спрашиваем run.paint.
 * Вкладку прятали — после возвращения картина берётся сразу и ложится без
 * повтора переходов. Прогон закрыт — последняя картинка остаётся, слежение кончается.
 *
 * Версия состояния и версия картинки — разные вещи: run.state мог прийти, а
 * run.paint — сорваться. Тогда картинка этой версии спрашивается снова, с короткой
 * задержкой и без долгого ожидания новой версии — и на паузе, и у закрытого прогона
 * (у закрытого — ограниченно: PAINT_TRIES раз).
 */
const PAINT_RETRY_MS = 1500;
const PAINT_TRIES = 20;

async function watch(token, runId, project) {
  let since = -1;       // версия последнего полученного run.state
  let painted = -1;     // версия, чья картинка уже пришла
  let misses = 0;       // сколько раз подряд картинка не пришла
  let wasHidden = false;
  while (token === follow) {
    if (document.hidden) { wasHidden = true; await sleep(1000); continue; }
    // Картинка отстала от состояния — долго не ждём: версия уже известна.
    const asap = wasHidden || painted < since;
    const answer = await quiet('run.state', { run: runId, since: asap ? -1 : since, wait: asap ? 0 : 25, full: 1 }, project);
    if (token !== follow) return;
    if (!answer) { await sleep(3000); continue; }
    applyState(answer);

    const version = Number(answer.run?.version ?? -1);
    const closed = !LIVE.has(answer.run?.state);
    if (version !== painted || wasHidden) {
      const paint = await quiet('run.paint', { run: runId }, project);
      if (token !== follow) return;
      if (paint) {
        quietNext = wasHidden;
        applyPaint(paint);
        quietNext = false;
        painted = version;
        misses = 0;
      } else {
        misses++;
      }
    }
    since = version;
    wasHidden = false;
    if (painted < since) {
      if (closed && misses >= PAINT_TRIES) return;
      await sleep(Math.min(PAINT_RETRY_MS * misses, 6000));
      continue;
    }
    if (closed) return;
  }
}

/** Запрос без всплывашек: слежение живёт долго, и сбой сети — не повод кричать. */
async function quiet(op, params, project) {
  try {
    const query = new URLSearchParams({ op, project, ...params });
    const response = await fetch('api.php?' + query, { headers: { Accept: 'application/json' } });
    const answer = await response.json();
    return answer.ok ? answer : null;
  } catch {
    return null;
  }
}

/** Время сервера «2026-09-21 14:06:02.500» в миллисекундах. Считаются только разности. */
function timeOf(text) {
  if (!text) return 0;
  const ms = Date.parse(String(text).replace(' ', 'T'));
  return Number.isFinite(ms) ? ms : 0;
}

const sleep = (ms) => new Promise((done) => setTimeout(done, ms));
