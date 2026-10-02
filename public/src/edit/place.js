/* Как объект попадает на холст: двойным щелчком по пустому месту
   и перетаскиванием значка с плашки инструментов.
   Отдаёт: initPlacing(), placeAt().
   Не делает: не рисует и не пишет на сервер — только зовёт edit/scene.js.

   Оба пути ведут к одному: элемент встаёт под курсором с названием по умолчанию
   (тип по-русски: «Блок», «Ромб»), выделяется и сразу открывает название на правку,
   выделенным целиком, — начал печатать, и оно заменилось. На телефоне (скин
   «Мобильный») правка не открывается: клавиатура не выскакивает на каждую постановку. */

import { state, emit } from 'goblin/core/state.js';
import { KINDS } from 'goblin/core/kinds.js';
import { toCanvas, nodeOf } from 'goblin/canvas/view.js';
import * as scene from 'goblin/edit/scene.js';
import { editInPlace } from 'goblin/edit/inplace.js';
import { commitDrop } from 'goblin/edit/drop.js';
import { toast } from 'goblin/shell/topbar.js';
import * as api from 'goblin/api/client.js';
import { reloadElement } from 'goblin/panel/assets-view.js';
import { t } from 'goblin/core/i18n.js';

const KIND_DATA = 'application/x-goblin-kind';
/* Агента тянут из панели прямо на блок: так его и назначают исполнителем. */
export const AGENT_DATA = 'application/x-goblin-agent';
/* Материал тянут из панели (материалы папки или блока) на блок — он прикрепляется. */
export const ASSET_DATA = 'application/x-goblin-asset';
/* Файл рабочей папки из панели: {path, name, kind}. На блоке становится материалом. */
export const WORKFILE_DATA = 'application/x-goblin-workfile';
/* Кто принимает материалы: у кого в панели есть раздел «Материалы». */
const TAKES_ASSETS = ['block', 'group', 'table'];

export function initPlacing() {
  const wrap = document.getElementById('canvas-wrap');
  if (!wrap) return;

  // Значок с плашки тянут на холст. Обработчик один на страницу:
  // кнопки инструментов рисуются заново при смене вида.
  document.addEventListener('dragstart', (event) => {
    const button = event.target.closest?.('[data-type]');
    if (!button || state.viewOnly || !KINDS[button.dataset.type]) return;
    event.dataTransfer.setData(KIND_DATA, button.dataset.type);
    event.dataTransfer.setData('text/plain', button.dataset.type);
    event.dataTransfer.effectAllowed = 'copy';
    document.body.classList.add('placing');
  });

  document.addEventListener('dragend', () => document.body.classList.remove('placing'));

  wrap.addEventListener('dragover', (event) => {
    if (state.viewOnly) return;
    event.preventDefault();                       // без этого сброс не случится
    event.dataTransfer.dropEffect = 'copy';
    // Тянут агента — подсвечиваем блок под курсором: только блок его и примет.
    if (document.body.classList.contains('dragging-agent')) {
      markTarget(event, ['block'], (item) => !item.props?.start);
    }
    // Тянут материал — подсвечиваем того, кто его примет.
    if (document.body.classList.contains('dragging-asset')) markTarget(event, TAKES_ASSETS);
  });

  wrap.addEventListener('dragleave', clearTarget);

  wrap.addEventListener('drop', (event) => {
    if (state.viewOnly) return;
    clearTarget();

    // Агент: кладётся на блок и становится его исполнителем.
    const agentId = Number(event.dataTransfer.getData(AGENT_DATA));
    if (agentId) {
      event.preventDefault();
      document.body.classList.remove('placing', 'dragging-agent');
      const element = elementAt(event);
      if (!element) { toast(t('editor.place.agent_on_block'), true); return; }
      if (element.type !== 'block') { toast(t('editor.place.executor_block_only'), true); return; }
      if (element.props?.start) { toast(t('editor.place.starter_leader'), true); return; }

      // Отпустили на выделенном — агент достаётся всему выделению: так одним
      // движением назначают исполнителя целой цепочке блоков.
      const blocks = pickedBlocks(element).filter((block) => !block.props?.start);
      for (const block of blocks) scene.patch(block.id, { agent: agentId });
      const agent = state.agents.find((item) => item.id === agentId);
      toast(blocks.length > 1
        ? t('editor.place.assigned_many', { name: agent?.name || t('editor.place.agent'), n: blocks.length })
        : t('editor.place.assigned_one', { name: agent?.name || t('editor.place.agent'), no: element.no }));
      return;
    }

    // Материал: прикрепляется к блоку под курсором (или ко всему выделению).
    const assetId = Number(event.dataTransfer.getData(ASSET_DATA));
    if (assetId) {
      event.preventDefault();
      document.body.classList.remove('placing', 'dragging-asset');
      attachDropped(assetId, elementAt(event));
      return;
    }

    const workfile = event.dataTransfer.getData(WORKFILE_DATA);
    if (workfile) {
      event.preventDefault();
      document.body.classList.remove('placing', 'dragging-asset');
      attachWorkfile(JSON.parse(workfile), elementAt(event));
      return;
    }

    const type = event.dataTransfer.getData(KIND_DATA) || event.dataTransfer.getData('text/plain');
    if (!KINDS[type] || type === 'arrow') return;
    event.preventDefault();
    document.body.classList.remove('placing');
    placeAt(type, toCanvas(event.clientX, event.clientY));
  });
}

/** Файл рабочей папки: запись материала на него (есть — та же) и прикрепить как материал. */
async function attachWorkfile(file, element) {
  if (!element) { toast(t('editor.place.file_on_block'), true); return; }
  let answer;
  try {
    answer = await api.batch([{ op: 'asset.create', kind: file.kind || 'file', title: file.name, uri: file.path }]);
  } catch {
    return;                                        // причину уже сказал слой API
  }
  const id = Number(answer.results?.[0]?.id);
  if (id) await attachDropped(id, element);
}

/**
 * Материал отпустили на холсте: прикрепить к элементу под курсором —
 * или ко всему выделению, если отпустили на выделенном. Уже прикреплённый
 * второй раз не цепляется.
 */
async function attachDropped(assetId, element) {
  if (!element) { toast(t('editor.place.asset_on_block'), true); return; }
  if (!TAKES_ASSETS.includes(element.type)) { toast(t('editor.place.asset_owners'), true); return; }

  const all = pickedBlocks(element, TAKES_ASSETS);
  const targets = all.filter((item) => !(item.assets || []).some((one) => Number(one.asset) === assetId));
  try {
    if (targets.length) {
      await api.batch(targets.map((item) => ({
        op: 'asset.link', asset: assetId, element: item.id, role: 'attachment',
      })));
    }
    for (const item of targets) await reloadElement(item.id);

    // Картинка, брошенная на блок, сразу становится его заглавной (прежняя — обычным вложением).
    let covered = 0;
    for (const item of all) {
      if (item.type !== 'block') continue;
      const link = (state.elements.get(item.id)?.assets || []).find((one) => Number(one.asset) === assetId);
      if (link?.kind !== 'image' || link.role === 'cover') continue;
      await api.post('asset.role', { id: link.link, role: 'cover' });
      await reloadElement(item.id);
      covered++;
    }
    if (!targets.length && !covered) { toast(t('editor.place.asset_attached')); return; }
  } catch {
    return;                                        // причину уже сказал слой API
  }
  emit('panel');
  toast(targets.length > 1
    ? t('editor.place.attached_many', { n: targets.length })
    : t('editor.place.attached_one', { no: element.no }));
}

/**
 * Кому достанется агент или материал: одному элементу под курсором или всему
 * выделению, если отпустили на выделенном. `types` — кто принимает (агента — блок).
 */
function pickedBlocks(element, types = ['block']) {
  if (!state.selection.has(element.id)) return [element];
  const chosen = [...state.selection]
    .map((id) => state.elements.get(id))
    .filter((item) => item && types.includes(item.type));
  return chosen.length ? chosen : [element];
}

/** Карточка под курсором — по точке, а не по цели события. */
function elementAt(event) {
  const node = document.elementFromPoint(event.clientX, event.clientY)?.closest('.el');
  return node ? state.elements.get(Number(node.dataset.id)) : null;
}

let marked = [];
function markTarget(event, types = ['block'], eligible = () => true) {
  const element = elementAt(event);
  const targets = element && types.includes(element.type) && eligible(element)
    ? pickedBlocks(element, types).filter(eligible) : [];
  const nodes = targets.map((item) => document.querySelector(`.el[data-id="${item.id}"]`)).filter(Boolean);
  if (nodes.length === marked.length && nodes.every((node, i) => node === marked[i])) return;
  clearTarget();
  marked = nodes;
  for (const node of marked) node.classList.add('drop-add');
}

function clearTarget() {
  for (const node of marked) node.classList.remove('drop-add');
  marked = [];
}

/**
 * Поставить элемент и отдать ему название на правку.
 * Сюда же приходит двойной щелчок по пустому холсту (edit/pointer.js).
 */
/** Выделить весь текст поля правки: печать заменит название по умолчанию. */
function selectAllIn(target) {
  if (!target || !target.isContentEditable) return;
  const range = document.createRange();
  range.selectNodeContents(target);
  const picked = window.getSelection();
  picked.removeAllRanges();
  picked.addRange(range);
}

export function placeAt(type, point) {
  if (state.viewOnly || !state.folder) return null;

  // Пустая карточка на холсте ничего не говорит — сразу название по умолчанию.
  const kind = KINDS[type];
  const title = type !== 'arrow' && kind ? kind.title[0].toUpperCase() + kind.title.slice(1) : '';
  const element = scene.createElement(type, point, title ? { title } : {});
  // Поставили внутрь рамки — значит, в её составе: иначе рамка уехала бы,
  // а новый элемент остался стоять на холсте один.
  if (type !== 'arrow') commitDrop(new Map([[element.id, true]]));
  state.selection.clear();
  state.selection.add(element.id);
  emit('selection');

  // Стрелке названия не дают: у неё первичное — концы, а не подпись.
  if (type !== 'arrow' && document.body.dataset.look !== 'mobile') {
    const node = nodeOf(element.id);
    if (node) {
      editInPlace(node, element, 'title');
      selectAllIn(node.querySelector('.node-title'));
    }
  }
  return element;
}
