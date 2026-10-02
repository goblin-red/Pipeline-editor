/* Ярлыки на холсте: короткая бирка «↗ номер» у края объекта.
   Отдаёт: initLinks(), paintLinks(), paintLinksOf(), followLink(), LINK_NAME_MAX.
   Не делает: не создаёт и не удаляет ярлыки (edit/links.js) и не участвует
              в прогоне — движок ярлыков не видит.

   На бирке — значок и номер объекта на другом конце: у владельца справа
   сплошная «↗ 57», у цели слева контурная «↙ 12» («на меня ссылаются»),
   входящий шлюз — «⬡ N». Наведение раскрывает бирку: «· папка · имя»
   (имя — до LINK_NAME_MAX знаков). Цель в другой папке — отросток пунктиром.
   Ярлыков у объекта может быть несколько — встают столбиком.

   Отростки живут внутри узла карточки, поэтому сами едут за ней при переносе,
   прячутся вместе со схлопнутой группой и поднимаются в объёмном виде. */

import { state, on, emit } from 'goblin/core/state.js';
import { nodeOf, flyTo } from 'goblin/canvas/view.js';
import { linksFrom, linksTo, linkName, endName, endNo } from 'goblin/edit/links.js';
import { setVariant } from 'goblin/core/variants.js';
import { loadFolder } from 'goblin/api/sync.js';
import { toast } from 'goblin/shell/topbar.js';
import { t } from 'goblin/core/i18n.js';

let frame = null;

export function initLinks() {
  // Поменялись сами ярлыки или имена папок — перерисовываем все отростки.
  on('links', schedule);
  on('folders', schedule);

  // Щелчок по кружку — переход. Холст жест не начинает (edit/pointer.js).
  document.getElementById('canvas-wrap').addEventListener('click', (event) => {
    const stub = event.target.closest('.link-stub');
    if (!stub) return;
    event.stopPropagation();
    const link = state.links.get(Number(stub.dataset.link));
    if (link) followLink(link, stub.dataset.side);
  });
}

/** Отложить полную перерисовку до кадра: ярлыки меняются пачками. */
function schedule() {
  if (frame) return;
  frame = requestAnimationFrame(() => { frame = null; paintLinks(); });
}

/** Все отростки папки заново. Зовёт view.js после полной отрисовки. */
export function paintLinks() {
  const layer = document.getElementById('layer-elements');
  for (const stale of layer.querySelectorAll('.link-stubs')) stale.remove();
  for (const node of layer.querySelectorAll('[data-link-sig]')) delete node.dataset.linkSig;

  const ends = new Set();
  for (const link of state.links.values()) { ends.add(link.from); ends.add(link.to); }
  for (const id of ends) paintNode(id);
}

/**
 * Объект перерисовали — обновить его отростки и те, где стоит его имя:
 * у цели подписан владелец, у владельца — цель.
 */
export function paintLinksOf(id) {
  if (!state.links.size) return;
  paintNode(id);
  for (const link of linksFrom(id)) paintNode(link.to);
  for (const link of linksTo(id)) paintNode(link.from);
}

/** Отростки одного узла. Ничего не поменялось — DOM не трогаем: при переносе это каждый кадр. */
function paintNode(id) {
  const node = nodeOf(id);
  if (!node) return;

  const stubs = [
    ...linksFrom(id).map((link) => stubOf(link, 'out')),
    ...linksTo(id).map((link) => stubOf(link, 'in')),
  ];
  const sig = JSON.stringify(stubs);
  const has = node.querySelector(':scope > .link-stubs');
  if (node.dataset.linkSig === sig && (has || !stubs.length)) return;

  for (const old of node.querySelectorAll(':scope > .link-stubs')) old.remove();
  node.dataset.linkSig = sig;
  for (const side of ['out', 'in']) {
    const list = stubs.filter((stub) => stub.side === side);
    if (!list.length) continue;
    const column = document.createElement('div');
    column.className = 'link-stubs ' + side;
    for (const stub of list) column.append(stubNode(stub));
    node.append(column);
  }
}

/* Имя на бирке — не длиннее 20 знаков: бирка не должна перекрывать схему.
   Полное имя остаётся во всплывающей подсказке. */
export const LINK_NAME_MAX = 20;
const cut = (text) => text.length > LINK_NAME_MAX ? text.slice(0, LINK_NAME_MAX - 1) + '…' : text;

/** Что показать на отростке: только данные, без DOM — по ним же считается подпись узла. */
function stubOf(link, side) {
  const far = side === 'out' ? link.toFolder : link.fromFolder;
  const other = far && far !== state.folder?.id;
  return {
    link: link.id, side, name: cut(linkName(link, side)), other, gate: !!link.gate,
    no: endNo(link, side === 'out' ? 'to' : 'from'),
    folder: other ? cut(folderName(far)) : '',
    hint: hintOf(link, side, other ? folderName(far) : ''),
  };
}

function stubNode(stub) {
  const button = document.createElement('button');
  button.type = 'button';
  button.className = `link-stub ${stub.side}` + (stub.other ? ' other' : '');
  button.dataset.link = stub.link;
  button.dataset.side = stub.side;
  button.setAttribute('aria-label', stub.hint);   // подсказку показывает сама бирка — раскрывается при наведении
  const tag = document.createElement('span');
  tag.className = 'link-tag';
  const icon = document.createElement('b');
  icon.className = 'link-icon';
  icon.textContent = stub.gate ? '⬡' : stub.side === 'out' ? '↗' : '↙';   // входящий шлюз — значок шлюза
  tag.append(icon);
  // Коротко: значок и номер блока на другом конце. Наведение раскрывает бирку — видно название.
  const name = document.createElement('span');
  name.className = 'link-name';
  name.textContent = stub.no ?? stub.name;
  const title = document.createElement('span');
  title.className = 'link-title';
  title.textContent = '· ' + (stub.folder ? stub.folder + ' · ' : '') + stub.name;
  tag.append(name, title);
  button.append(tag);
  return button;
}

function hintOf(link, side, folder) {
  const where = folder ? t('editor.link.in_folder', { name: folder }) : '';
  return side === 'out'
    ? t('editor.link.hint_out', { no: link.no, name: endName(link, 'to'), where })
    : t('editor.link.hint_in', { name: endName(link, 'from'), where });
}

function folderName(id) {
  return state.folders.find((folder) => folder.id === id)?.name || t('editor.canvas.folder_n', { id });
}

/**
 * Перейти по ярлыку. У владельца ведёт к цели, у цели — обратно к владельцу.
 * Цель в другой папке — сперва открываем ту папку. Объект выделяется,
 * камера плавно перелетает к нему на масштабе 80 %.
 */
export async function followLink(link, side = 'out') {
  const id = side === 'out' ? link.to : link.from;
  const folder = side === 'out' ? link.toFolder : link.fromFolder;

  if (state.variant !== 'canvas') await setVariant('canvas');
  if (folder && folder !== state.folder?.id) await loadFolder(folder);

  const target = state.elements.get(id);
  if (!target) { toast(t('editor.link.target_gone'), true); return; }

  state.selection.clear();
  state.selection.add(target.id);
  emit('selection');
  flyTo(target, 0.8);   // масштаб 80 % и плавный перелёт к цели

  // Короткая вспышка: видно, куда именно привёл ярлык. Атрибут, а не класс:
  // классы карточки canvas/element.js пересобирает при каждой перерисовке.
  const node = nodeOf(target.id);
  if (node) {
    node.dataset.linkArrived = '1';
    setTimeout(() => delete node.dataset.linkArrived, 1400);
  }
}
