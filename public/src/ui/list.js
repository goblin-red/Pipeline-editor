/* Вид «Список»: та же схема таблицей.
   Отдаёт: mount(), unmount().
   Не делает: не рисует холст — он для длинных схем, где картинка мешает.

   Зачем: в папке на сотню блоков глазами по холсту не находят ничего.
   Здесь всё видно строками: номер, тип, название, агент, ТЗ, состояние шага.
   Ярлыки идут своими строками со значком ↗ (свои) и ← (входящие из других
   папок); в счёт стрелок-связей они не входят. Щелчок по строке ярлыка — переход. */

import { state, on, emit, stepOf } from 'goblin/core/state.js';
import { KINDS, LINK_KIND, STEP_MARKS, STEP_WORDS, iconOf } from 'goblin/core/kinds.js';
import * as scene from 'goblin/edit/scene.js';
import { renameLink, endName } from 'goblin/edit/links.js';
import { followLink } from 'goblin/canvas/link.js';
import { openSpec } from 'goblin/shell/assetview.js';
import { t } from 'goblin/core/i18n.js';

let root = null;
let off = [];
let filter = '';

export function mount() {
  document.getElementById('canvas-wrap').hidden = true;
  document.getElementById('rail').hidden = false;

  root = document.createElement('section');
  root.className = 'ui-list';
  root.innerHTML = `
    <header class="ui-list-head">
      <input id="list-filter" class="input" placeholder="${t('editor.list.filter')}">
      <span class="muted" id="list-count"></span>
    </header>
    <div class="ui-list-body" id="list-body"></div>`;
  document.querySelector('.workspace').insertBefore(root, document.getElementById('panel'));

  root.querySelector('#list-filter').oninput = (event) => { filter = event.target.value.toLowerCase(); render(); };
  off = [on('scheme', render), on('element', render), on('drop', render), on('steps', render), on('selection', render),
         on('links', render)];
  render();
}

export function unmount() {
  for (const stop of off) stop();
  off = [];
  root?.remove();
  root = null;
}

function render() {
  if (!root) return;
  const body = root.querySelector('#list-body');
  const all = [...state.elements.values(), ...[...state.links.values()].filter((link) => !link.gate)].sort((a, b) => a.no - b.no);
  const rows = all.filter((element) => {
    if (!filter) return true;
    return String(element.no).startsWith(filter)
      || (element.title || '').toLowerCase().includes(filter)
      || ((element.type === 'link' ? LINK_KIND : KINDS[element.type])?.title || '').includes(filter);
  });

  body.textContent = '';
  for (const element of rows) {
    if (element.type === 'link') { body.append(linkRow(element)); continue; }
    const step = stepOf(element);
    const row = document.createElement('div');
    row.className = 'ui-row' + (state.selection.has(element.id) ? ' on' : '');
    row.dataset.id = element.id;

    const agent = state.agents.find((a) => a.id === element.agent);
    row.innerHTML = `
      <b class="mono">${element.no}</b>
      <span class="ui-kind" title="${KINDS[element.type]?.about || ''}">${iconOf(element)}</span>
      <input class="ui-title input" value="${escapeAttr(element.title || '')}" placeholder="${KINDS[element.type]?.title || ''}">
      <span class="ui-links">${arrowsWord(element)}</span>
      <span class="ui-agent">${agent ? escape(agent.name) : ''}</span>
      ${element.hasSpec ? `<button class="ui-spec" title="${t('editor.list.open_spec')}">${t('editor.list.spec')}</button>` : '<span></span>'}
      <span class="ui-step" data-state="${step ? step.state : ''}">${step ? `${STEP_MARKS[step.state]} ${STEP_WORDS[step.state]}` : ''}</span>`;

    row.onclick = (event) => {
      if (event.target.classList.contains('ui-title')) return;
      if (event.target.classList.contains('ui-spec')) { openSpec(element); return; }
      if (!event.shiftKey) state.selection.clear();
      state.selection.add(element.id);
      emit('selection');
    };
    const title = row.querySelector('.ui-title');
    title.onchange = () => scene.patch(element.id, { title: title.value });
    title.disabled = state.viewOnly;

    body.append(row);
  }
  root.querySelector('#list-count').textContent = t('editor.list.count', { shown: rows.length, total: all.length });
}

/**
 * Строка ярлыка. Свой — «владелец ↗ цель», входящий из другой папки — «← владелец».
 * Щелчок уводит по ярлыку; название правится здесь же.
 */
function linkRow(link) {
  const own = link.folder === state.folder?.id;
  const far = own ? link.toFolder : link.folder;
  const where = far !== state.folder?.id
    ? t('editor.link.in_folder', { name: state.folders.find((folder) => folder.id === far)?.name || far }) : '';
  const route = own
    ? `${link.fromNo ?? '?'} ↗ ${link.toNo ?? '?'}${where}`
    : `← ${link.fromNo ?? '?'} ${endName(link, 'from')}${where}`;

  const row = document.createElement('div');
  row.className = 'ui-row ui-row-link';
  row.dataset.link = link.id;
  row.innerHTML = `
    <b class="mono">${link.no}</b>
    <span class="ui-kind" title="${escapeAttr(LINK_KIND.about)}">${own ? LINK_KIND.icon : '←'}</span>
    <input class="ui-title input" value="${escapeAttr(link.title || '')}" placeholder="${escapeAttr(endName(link, 'to'))}">
    <span class="ui-links">${escape(route)}</span>
    <span class="ui-agent"></span>
    <span></span>
    <span class="ui-step"></span>`;

  row.onclick = (event) => {
    if (event.target.classList.contains('ui-title')) return;
    followLink(link, own ? 'out' : 'in');
  };
  row.title = own ? t('editor.list.go_target') : t('editor.list.go_source');
  const title = row.querySelector('.ui-title');
  title.onchange = () => renameLink(link.id, title.value.trim());
  title.disabled = state.viewOnly || !own;
  return row;
}

function arrowsWord(element) {
  if (element.type === 'arrow') {
    const from = state.elements.get(element.from);
    const to = state.elements.get(element.to);
    const branch = element.branch === 'yes' ? ' ' + t('editor.canvas.yes_caps') : element.branch === 'no' ? ' ' + t('editor.canvas.no_caps') : '';
    return `${from?.no ?? '?'} → ${to?.no ?? '?'}${branch}`;
  }
  const incoming = [...state.elements.values()].filter((e) => e.type === 'arrow' && e.to === element.id).length;
  const outgoing = [...state.elements.values()].filter((e) => e.type === 'arrow' && e.from === element.id).length;
  return `← ${incoming}   → ${outgoing}`;
}

const escape = (text) => String(text).replace(/[&<>]/g, (ch) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;' }[ch]));
const escapeAttr = (text) => escape(text).replace(/"/g, '&quot;');
