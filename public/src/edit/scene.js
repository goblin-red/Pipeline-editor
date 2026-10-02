/* Правки схемы: создать, изменить, соединить, удалить, вернуть.
   Отдаёт: createElement(), connect(), patch(), remove(), restore(), setProps(),
           setMembers(), flush(), queueSize().
   Не делает: не слушает мышь (edit/pointer.js) и не рисует (canvas/view.js).

   Всё уходит на сервер пачками: правки копятся полсекунды и отправляются одним
   запросом с ключом повтора. Пачка собирается один раз и помнит свои проект,
   папку, ключ и тело: оборвалась связь — летит та же пачка с тем же ключом, и
   сервер её дважды не применит; сервер отказал — папка перечитывается.

   Здесь же — единственное место, где правка записывается в историю отмен:
   каждая функция знает, как себя откатить, и никто больше об этом не думает. */

import * as api from 'goblin/api/client.js';
import { state, putElement, dropElement, setDirty, emit, byNo } from 'goblin/core/state.js';
import { canLink, defaultSize, kindOf } from 'goblin/core/kinds.js';
import { snapTo } from 'goblin/core/settings.js';
import { record } from 'goblin/edit/history.js';
import { rekeyNode } from 'goblin/canvas/view.js';
import { takeLinksOf, restoreLinks } from 'goblin/edit/links.js';
import { t } from 'goblin/core/i18n.js';

let queue = [];          // правки, ещё не собранные в пачку: { op, tempKey, project, folder }
let pending = null;      // собранная пачка: летит или ждёт повтора — { project, folder, opId, items, tries }
let timer = null;
let sending = false;
let tempId = -1;
let opCounter = 0;

/** Новый элемент. Пока сервер не ответил, он живёт с отрицательным id. */
export function createElement(type, at, extra = {}) {
  const size = defaultSize(type);
  const element = {
    id: tempId--,
    no: '…',
    type,
    title: '',
    folder: state.folder.id,
    style: { x: snapTo(at.x - size.width / 2), y: snapTo(at.y - size.height / 2), ...size,
             ...(state.color ? { color: state.color } : {}), z: type === 'area' ? 0 : 1 },
    ...extra,
  };
  putElement(element);
  push({ op: 'element.create', ref: 'e' + element.id, folder: element.folder, type,
         title: element.title, description: element.description || '', style: element.style }, element.id);
  rememberBirth(element, kindOf(element).title);
  return element;
}

/** Создание и удаление — одно и то же действие, только в разные стороны.
    Слепок снимаем в миг отмены: до неё элемент ещё живой и знает о себе всё. */
function rememberBirth(element, label) {
  let shot = null;
  record({
    label,
    undo: () => { shot = snapshot(element); removeByNo([element.no]); },
    redo: () => { if (shot) restore(shot); },
  });
}

/** Стрелка между двумя элементами. К рамкам она не цепляется. */
export function connect(fromId, toId, branch = null, style = null) {
  if (fromId === toId) return null;
  const from = state.elements.get(fromId);
  const to = state.elements.get(toId);
  const wrong = !canLink(from?.type) ? from : !canLink(to?.type) ? to : null;
  if (wrong) {
    emit('refused', t('editor.scene.arrow_ends', { kind: kindOf(wrong).title }));
    return null;
  }
  // Переход между двумя объектами один: вторая такая же стрелка ничего не
  // добавляет к схеме, а прогон по ней пришлось бы толковать дважды.
  const twin = [...state.elements.values()]
    .find((e) => e.type === 'arrow' && e.from === fromId && e.to === toId);
  if (twin) {
    emit('refused', t('editor.scene.arrow_exists', { no: twin.no }));
    return null;
  }

  const element = {
    id: tempId--, no: '…', type: 'arrow', title: '',
    folder: state.folder.id, from: fromId, to: toId, style: { ...(style || {}) },
    ...(branch ? { branch } : {}),
  };
  putElement(element);
  push({ op: 'element.create', ref: 'e' + element.id, folder: element.folder, type: 'arrow',
         from: ref(fromId), to: ref(toId), ...(branch ? { branch } : {}),
         ...(style ? { style } : {}) }, element.id);
  rememberBirth(element, t('editor.history.arrow'));
  return element;
}

/** Правка полей элемента: в модели сразу, на сервер — пачкой. */
export function patch(id, changes) {
  const element = state.elements.get(id);
  if (!element) return;

  const was = previousOf(element, changes);
  const next = { ...changes };
  if (changes.style) next.style = { ...element.style, ...changes.style };
  putElement({ id, ...next });
  push({ op: 'element.update', id: ref(id), ...changes });

  const no = element.no;
  record({
    label: t('editor.history.edit'),
    undo: () => patchByNo(no, was),
    redo: () => patchByNo(no, changes),
  });
}

/** Прежние значения ровно тех полей, которые правят. Ключа не было — вернём null. */
function previousOf(element, changes) {
  const was = {};
  for (const [name, value] of Object.entries(changes)) {
    if (name === 'style') {
      was.style = {};
      for (const key of Object.keys(value)) was.style[key] = element.style?.[key] ?? null;
      continue;
    }
    if (name === 'props') {
      was.props = {};
      for (const key of Object.keys(value)) was.props[key] = element.props?.[key] ?? null;
      continue;
    }
    was[name] = element[name] ?? null;
  }
  return was;
}

function patchByNo(no, changes) {
  const element = byNo(no);
  if (element) patch(element.id, changes);
}

/**
 * Удаление. Стрелки уходят следом за своими концами — и на сервере, и в модели.
 * Перед уходом снимаем слепок всего, что уносим: по нему работает отмена.
 */
export function remove(ids) {
  const gone = [];
  const links = [];       // ярлыки объекта: сервер уносит их каскадом, отмена вернёт
  for (const id of [...ids]) {
    const element = state.elements.get(id);
    if (!element) continue;

    if (element.type !== 'arrow') {
      for (const other of [...state.elements.values()]) {
        if (other.type === 'arrow' && (other.from === id || other.to === id)) {
          gone.push(snapshot(other));
          dropElement(other.id);
        }
      }
      links.push(...takeLinksOf(id));
    }
    gone.unshift(snapshot(element));    // сначала концы, потом стрелки
    dropElement(id);
    push({ op: 'element.delete', id: ref(id) });
  }
  if (!gone.length) return;

  const numbers = gone.map((item) => item.no);
  record({
    label: gone.length > 1 ? t('editor.history.delete_many', { n: gone.length }) : t('editor.history.delete'),
    undo: () => { for (const item of gone) restore(item); restoreLinks(links); },
    redo: () => removeByNo(numbers),
  });
}

function removeByNo(numbers) {
  const ids = numbers.map((no) => byNo(no)?.id).filter((id) => id !== undefined);
  if (ids.length) remove(ids);
}

/** Слепок элемента: всё, что нужно, чтобы вернуть его тем же самым. */
function snapshot(element) {
  return {
    no: element.no, type: element.type, folder: element.folder,
    title: element.title || '', description: element.description || '',
    style: { ...(element.style || {}) },
    props: { ...(element.props || {}) },
    in: [...(element.in || [])].map((id) => state.elements.get(id)?.no).filter((no) => no !== undefined),
    agent: element.agent ?? null,
    target: element.target ?? null,
    branch: element.branch ?? null,
    back: !!element.back,
    fromNo: element.type === 'arrow' ? state.elements.get(element.from)?.no : null,
    toNo: element.type === 'arrow' ? state.elements.get(element.to)?.no : null,
    assets: (element.assets || []).map((a) => ({ asset: a.asset, role: a.role, output: a.output || '' })),
  };
}

/**
 * Вернуть удалённое под прежним номером. Концы стрелки ищем по номерам:
 * блок мог вернуться только что и получить новую строку в базе.
 */
export function restore(shot) {
  if (byNo(shot.no)) return null;
  const from = shot.fromNo !== null && shot.fromNo !== undefined ? byNo(shot.fromNo) : null;
  const to = shot.toNo !== null && shot.toNo !== undefined ? byNo(shot.toNo) : null;
  if (shot.type === 'arrow' && (!from || !to)) return null;   // концов нет — стрелке некуда

  const inside = shot.in.map((no) => byNo(no)?.id).filter((id) => id !== undefined);
  const element = {
    id: tempId--, no: shot.no, type: shot.type, folder: shot.folder,
    title: shot.title, description: shot.description,
    style: { ...shot.style }, props: { ...shot.props }, in: inside,
    ...(shot.agent ? { agent: shot.agent } : {}),
    ...(shot.target ? { target: shot.target } : {}),
    ...(shot.branch ? { branch: shot.branch } : {}),
    ...(shot.back ? { back: true } : {}),
    ...(from ? { from: from.id } : {}),
    ...(to ? { to: to.id } : {}),
  };
  putElement(element);

  push({
    op: 'element.restore', ref: 'e' + element.id, no: shot.no, folder: shot.folder,
    type: shot.type, title: shot.title, description: shot.description,
    style: shot.style, props: shot.props, in: inside.map(ref),
    ...(shot.agent ? { agent: shot.agent } : {}),
    ...(shot.target ? { target: shot.target } : {}),
    ...(shot.branch ? { branch: shot.branch } : {}),
    ...(shot.back ? { back: true } : {}),
    ...(from ? { from: ref(from.id) } : {}),
    ...(to ? { to: ref(to.id) } : {}),
    ...(shot.assets.length ? { assets: shot.assets } : {}),
  }, element.id);

  record({
    label: t('editor.history.return'),
    undo: () => removeByNo([shot.no]),
    redo: () => restore(shot),
  });
  return element;
}

/**
 * Свойства и состав — те же правки, отдельных операций для них нет.
 * Присланные допы накладываются на прежние: сервер так и делает, и модель
 * должна делать так же, иначе правка одного допа стирала бы остальные.
 */
export function setProps(id, props) {
  const element = state.elements.get(id);
  const merged = { ...(element?.props || {}) };
  for (const [name, value] of Object.entries(props)) {
    if (value === null || value === '') delete merged[name];
    else merged[name] = value;
  }
  const was = element ? { ...(element.props || {}) } : {};

  putElement({ id, props: merged, ...(merged.start ? { agent: null } : {}) });
  push({ op: 'element.update', id: ref(id), props });

  const no = element?.no;
  record({
    label: t('editor.history.props'),
    undo: () => { const back = byNo(no); if (back) setProps(back.id, fillGaps(props, was)); },
    redo: () => { const again = byNo(no); if (again) setProps(again.id, props); },
  });
}

/** Отмена должна убрать то, чего раньше не было: пустое значение сносит доп. */
function fillGaps(patch, was) {
  const out = {};
  for (const name of Object.keys(patch)) out[name] = was[name] ?? null;
  return out;
}

/** Состав: контейнер может быть только что нарисован, поэтому — через ссылки. */
export function setMembers(id, containers) {
  const list = [...new Set(containers)];
  const element = state.elements.get(id);
  if (!element) return;

  const wasNumbers = (element.in || []).map((cid) => state.elements.get(cid)?.no)
    .filter((no) => no !== undefined);
  const nowNumbers = list.map((cid) => state.elements.get(cid)?.no).filter((no) => no !== undefined);

  putElement({ id, in: list });
  push({ op: 'element.update', id: ref(id), in: list.map(ref) });

  const no = element.no;
  record({
    label: t('editor.history.members'),
    undo: () => membersByNo(no, wasNumbers),
    redo: () => membersByNo(no, nowNumbers),
  });
}

function membersByNo(no, containerNumbers) {
  const element = byNo(no);
  if (!element) return;
  setMembers(element.id, containerNumbers.map((cno) => byNo(cno)?.id).filter((id) => id !== undefined));
}

/* ── Очередь ─────────────────────────────────────────────────── */

function ref(id) {
  return id < 0 ? 'e' + id : id;
}

function push(op, tempKey) {
  // Адрес правки — проект и папка, где её сделали: переход в другой проект его не меняет.
  queue.push({ op, tempKey, project: api.projectKey(), folder: state.folder?.id ?? null });
  setDirty(true);
  emit('queue', queue.length);
  clearTimeout(timer);
  timer = setTimeout(flush, 500);
}

/** Сколько правок ещё не дошло до сервера: в очереди и собранная пачка. */
export function queueSize() { return queue.length + (pending ? 1 : 0); }

/** Собрать пачку из головы очереди: только правки одного проекта, ключ и тело — навсегда её. */
function makeBatch() {
  const project = queue[0].project;
  const items = [];
  while (queue.length && queue[0].project === project) items.push(queue.shift());
  return { project, folder: items[0].folder, opId: `ui-${Date.now().toString(36)}-${opCounter++}`, items, tries: 0 };
}

/**
 * Отправить накопленное. Одна пачка за раз: порядок правок важен.
 * `leaving` — человек уходит со страницы: запрос должен пережить выгрузку вкладки.
 */
export async function flush(leaving = false) {
  if (sending || (!pending && !queue.length)) return;
  sending = true;
  clearTimeout(timer);
  pending ??= makeBatch();
  const batch = pending;

  try {
    const answer = await api.batch(batch.items.map((item) => item.op),
      { opId: batch.opId, project: batch.project }, leaving);
    pending = null;
    // Пока пачка летела, человек мог открыть другой проект или папку: ответ старой
    // пачки нельзя применять к новому экрану — иначе чужие id и чужая ревизия.
    if (api.projectKey() !== batch.project || (state.folder?.id ?? null) !== batch.folder) return;
    adoptIds(batch.items, answer.results || []);
    // Ревизию двигаем, только если между опросом и пачкой никто не писал: иначе
    // опрос пропустил бы чужие правки. Свои тогда вернутся опросом — это безвредно.
    if (Number(answer.rev) === Number(state.rev) + 1) state.rev = answer.rev;
  } catch (error) {
    if (error?.ok === false) {
      // Сервер отказал: пачка не применилась целиком. Правки, собранные поверх неё, — туда же:
      // папка перечитывается, чтобы не разойтись с сервером.
      pending = null;
      queue = queue.filter((item) => item.project !== batch.project);
      if (api.projectKey() === batch.project) emit('resync');
    } else {
      batch.tries++;            // связь оборвалась: та же пачка, тот же ключ — чуть позже
    }
  } finally {
    sending = false;
    setDirty(queue.length > 0 || !!pending);
    emit('queue', queueSize());
    if (pending) timer = setTimeout(flush, Math.min(15000, 1000 * 2 ** (pending.tries - 1)));
    else if (queue.length) timer = setTimeout(flush, 100);
  }
}

/** Сервер вернул настоящие id и номера — заменяем временные. */
function adoptIds(items, results) {
  let reselect = false;
  results.forEach((result, index) => {
    const tempKey = items[index]?.tempKey;
    if (!tempKey || !result?.id) return;
    const element = state.elements.get(tempKey);
    if (!element) return;
    // Выделение держится на id: подменив его молча, мы бы сняли выделение
    // со всего только что нарисованного.
    const picked = state.selection.has(tempKey);
    rekeyNode(tempKey, result.id);   // узел переживает подмену id
    dropElement(tempKey);
    element.id = result.id;
    element.no = result.no ?? element.no;
    relink(tempKey, result.id);
    // Правки, сделанные, пока пачка летела, ещё зовут элемент временным «e-5»:
    // следующей пачкой сервер его не узнает и откажет — подставляем настоящий id.
    swapRef(queue, ref(tempKey), result.id);
    putElement(element);
    if (picked) { state.selection.add(result.id); reselect = true; }
  });
  if (reselect) emit('selection');
}

/** Во всех правках очереди временная ссылка («e-5») меняется на настоящий id. */
function swapRef(items, from, to) {
  const walk = (value) => Array.isArray(value) ? value.map(walk)
    : value && typeof value === 'object' ? Object.fromEntries(Object.entries(value).map(([key, one]) => [key, walk(one)]))
    : value === from ? to : value;
  for (const item of items) item.op = walk(item.op);
}

/** Временный id заменился настоящим: чиним ссылки стрелок и состав контейнеров. */
function relink(tempKey, realId) {
  for (const element of state.elements.values()) {
    if (element.type === 'arrow') {
      if (element.from === tempKey) element.from = realId;
      if (element.to === tempKey) element.to = realId;
      continue;
    }
    if (element.in?.includes(tempKey)) {
      element.in = element.in.map((id) => (id === tempKey ? realId : id));
    }
  }
}
