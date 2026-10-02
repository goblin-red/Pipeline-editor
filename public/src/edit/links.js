/* Правки ярлыков: создать, переименовать, удалить, вернуть — с отменой.
   Отдаёт: createLink(), renameLink(), removeLink(), takeLinksOf(), restoreLinks(),
           linksFrom(), linksTo(), linkName(), endName().
   Не делает: не рисует (canvas/link.js) и не решает правила концов — их
              проверяет сервер (lib/elements/kinds.php), здесь только подсказка.

   Ярлык живёт в state.links, а не в state.elements, поэтому идёт на сервер
   своим запросом, а не очередью edit/scene.js. Отмена — через тот же
   edit/history.js; шаг ищет ярлык по номеру, как и всё остальное. */

import * as api from 'goblin/api/client.js';
import { state, putElement, dropElement, byNo, emit } from 'goblin/core/state.js';
import { isLinkEnd } from 'goblin/core/kinds.js';
import { record } from 'goblin/edit/history.js';
import { flush } from 'goblin/edit/scene.js';
import { t } from 'goblin/core/i18n.js';

let counter = 0;

/* ── Чтение ──────────────────────────────────────────────────── */

/** Ярлыки, которые висят на объекте (он — владелец). */
export function linksFrom(id) {
  return [...state.links.values()].filter((link) => link.from === id);
}

/** Ярлыки, которые ведут на объект (он — цель). */
export function linksTo(id) {
  return [...state.links.values()].filter((link) => link.to === id);
}

/**
 * Подпись ярлыка. У владельца — своё название ярлыка, а нет его — название
 * цели. У цели — название владельца. Конец в этой папке берём из живой
 * модели: переименовали блок — подпись сменилась сразу.
 */
export function linkName(link, side = 'out') {
  if (side === 'out' && link.title) return link.title;
  return endName(link, side === 'out' ? 'to' : 'from');
}

/** Номер конца ярлыка: 'from' — владелец, 'to' — цель; конец в другой папке — из самого ярлыка. */
export function endNo(link, end) {
  return state.elements.get(link[end])?.no ?? link[end + 'No'] ?? null;
}

/** Название конца ярлыка: 'from' — владелец, 'to' — цель. */
export function endName(link, end) {
  const here = state.elements.get(link[end]);
  const title = here ? here.title : link[end + 'Title'];
  const no = here ? here.no : link[end + 'No'];
  return title || (no ? t('editor.links.no', { no }) : t('editor.folders.unnamed'));
}

/* ── Создание ────────────────────────────────────────────────── */

/**
 * Новый ярлык от объекта к объекту. Конец в другой папке передают целиком
 * ({id, no, type, title, folder}): цель — в `far`, владельца — в `near`
 * («щёлк — щёлк»: владельца выбрали, потом ушли в другую папку за целью).
 * В state.elements такого конца нет.
 */
export async function createLink(fromId, toId, { title = '', far = null, near = null } = {}) {
  if (state.viewOnly) return null;
  const from = state.elements.get(fromId) || (near && near.id === fromId ? near : null);
  const to = state.elements.get(toId) || (far && far.id === toId ? far : null);

  const wrong = !from || !isLinkEnd(from.type) ? t('editor.links.start_at')
    : !to || !isLinkEnd(to.type) ? t('editor.links.leads_to')
    : from.id === to.id ? t('editor.links.not_self')
    : null;
  if (wrong) { emit('refused', wrong); return null; }

  const twin = linksFrom(from.id).find((link) => link.to === to.id);
  if (twin) { emit('refused', t('editor.links.exists', { no: twin.no })); return null; }

  await settle(from, to);
  const answer = await send([{ op: 'element.create', type: 'link', from: from.id, to: to.id,
                               ...(title ? { title } : {}) }]);
  if (!answer) return null;

  const link = localLink(answer.results[0], from, to, title);
  putElement(link);

  const shot = snapshot(link);
  record({
    label: t('editor.history.link'),
    undo: () => removeByNo(shot.no),
    redo: () => restoreOne(shot),
  });
  return link;
}

/** Своё название ярлыка. Пустое — подписью снова станет название цели. */
export function renameLink(id, title) {
  const link = state.links.get(id);
  if (!link || state.viewOnly) return;
  const was = link.title || '';
  if (was === title) return;
  const no = link.no;
  setTitle(link, title);
  record({
    label: t('editor.history.link_name'),
    undo: () => { const back = linkByNo(no); if (back) setTitle(back, was); },
    redo: () => { const again = linkByNo(no); if (again) setTitle(again, title); },
  });
}

function setTitle(link, title) {
  putElement({ id: link.id, title });
  send([{ op: 'element.update', id: link.id, title }]);
}

/* ── Удаление и возврат ──────────────────────────────────────── */

export function removeLink(id) {
  const link = state.links.get(id);
  if (!link || state.viewOnly) return;
  const shot = snapshot(link);
  dropElement(id);
  send([{ op: 'element.delete', id }]);
  record({
    label: t('editor.history.link_delete'),
    undo: () => restoreOne(shot),
    redo: () => removeByNo(shot.no),
  });
}

function removeByNo(no) {
  const link = linkByNo(no);
  if (!link) return;
  dropElement(link.id);
  send([{ op: 'element.delete', id: link.id }]);
}

/**
 * Удаляют объект — его ярлыки сервер уносит каскадом. Здесь снимаем с них
 * слепки и убираем их из модели сразу, чтобы отмена удаления (edit/scene.js)
 * могла вернуть их через restoreLinks().
 */
export function takeLinksOf(id) {
  const gone = [...linksFrom(id), ...linksTo(id)];
  const shots = gone.map(snapshot);
  for (const link of gone) dropElement(link.id);
  return shots;
}

/** Вернуть ярлыки по слепкам — после того, как вернулись их концы. */
export function restoreLinks(shots) {
  for (const shot of shots) restoreOne(shot);
}

/**
 * Вернуть ярлык под прежним номером. Конец из этой папки ищем по номеру:
 * блок мог вернуться только что и получить новую строку в базе. Конец
 * в чужой папке не удалялся — его id прежний.
 */
async function restoreOne(shot) {
  if (linkByNo(shot.no)) return;
  const from = endOf(shot, 'from');
  const to = endOf(shot, 'to');
  if (!from || !to) return;                 // конца нет — ярлыку некуда

  await settle(from, to);
  const answer = await send([{ op: 'element.restore', no: shot.no, type: 'link',
                               from: from.id, to: to.id, title: shot.title, style: shot.style }]);
  if (!answer) return;
  putElement(localLink(answer.results[0], from, to, shot.title));
}

/** Конец ярлыка по слепку: из модели по номеру, иначе — чужой, по id. */
function endOf(shot, side) {
  const near = byNo(shot[side + 'No']);
  if (near && near.folder === shot[side + 'Folder']) return near;
  if (shot[side + 'Folder'] === state.folder?.id) return null;   // свой конец пропал
  return { id: shot[side], no: shot[side + 'No'], type: shot[side + 'Type'],
           title: shot[side + 'Title'], folder: shot[side + 'Folder'] };
}

/* ── Служебное ───────────────────────────────────────────────── */

function snapshot(link) {
  const out = { no: link.no, title: link.title || '', style: { ...(link.style || {}) } };
  for (const side of ['from', 'to']) {
    const here = state.elements.get(link[side]);
    out[side] = link[side];
    out[side + 'No'] = here ? here.no : link[side + 'No'];
    out[side + 'Type'] = here ? here.type : link[side + 'Type'];
    out[side + 'Title'] = here ? here.title : link[side + 'Title'];
    out[side + 'Folder'] = here ? here.folder : link[side + 'Folder'];
  }
  return out;
}

/** Ярлык в модели сразу после ответа сервера — в том же виде, что отдаёт схема. */
function localLink(result, from, to, title) {
  return {
    id: result.id, no: result.no, type: 'link', title: title || '',
    folder: from.folder, from: from.id, to: to.id, style: {},
    fromFolder: from.folder, fromNo: from.no, fromType: from.type, fromTitle: from.title || '',
    toFolder: to.folder, toNo: to.no, toType: to.type, toTitle: to.title || '',
  };
}

function linkByNo(no) {
  for (const link of state.links.values()) if (link.no === no) return link;
  return null;
}

/**
 * Концы только что нарисованы и ещё живут с временным id — сперва пусть
 * очередь правок уйдёт на сервер и вернёт настоящие.
 */
async function settle(...ends) {
  for (let tries = 0; tries < 30 && ends.some((end) => end.id < 0); tries++) {
    await flush();
    if (ends.some((end) => end.id < 0)) await new Promise((done) => setTimeout(done, 100));
  }
}

/** Одна пачка на сервер. Отказ уже показан всплывашкой — схему перечитываем. */
async function send(ops) {
  try {
    return await api.batch(ops, { opId: `ui-link-${Date.now().toString(36)}-${counter++}` });
  } catch {
    emit('resync');
    return null;
  }
}
