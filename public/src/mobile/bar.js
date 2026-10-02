/* Полоски поверх холста мобильного скина.
   Отдаёт: initBar().
   Не делает: ничего не решает сам — открывает шторку, окно ТЗ и зовёт
   удаление той же кнопкой, что в панели (#btn-delete).

   Снизу — что выбрано и что с ним сделать: «Свойства», «ТЗ», «Удалить»;
   у стрелки из ромба — ветка «ДА» / «НЕТ» вместо «Свойств»; у группы —
   «Разгруппировать» (свернуть и развернуть — значком на самой группе);
   выделено несколько — «Группировать» и «Удалить».
   В шапке справа (на телефоне — под шапкой) — какой инструмент в руке и как им
   работать; снимают его повторным касанием плитки в «Инструментах».
   Панель при выборе не выезжает: холст главный, свойства — по просьбе. */

import { state, on, emit } from 'goblin/core/state.js';
import { kindOf, iconOf } from 'goblin/core/kinds.js';
import { openSpec } from 'goblin/shell/assetview.js';
import * as scene from 'goblin/edit/scene.js';
import { canGroup, groupSelected, ungroupSelected, toggleCollapse } from 'goblin/edit/grouping.js';
import { openDrawer } from 'goblin/mobile/drawer.js';
import { toolInfo } from 'goblin/mobile/tools.js';
import { setSelecting } from 'goblin/mobile/touch.js';
import { t } from 'goblin/core/i18n.js';

let bar, chip;

export function initBar() {
  bar = document.createElement('div');
  bar.className = 'm-bar';
  bar.hidden = true;
  chip = document.createElement('div');
  chip.className = 'm-chip';
  chip.hidden = true;
  document.body.append(bar);
  // Подсказка инструмента — в шапке, на месте названия папки (mobile/mobile.js).
  const folder = document.querySelector('.m-top .m-folder');
  if (folder) folder.before(chip); else document.body.append(chip);

  for (const event of ['selection', 'element', 'scheme', 'folder', 'look']) on(event, drawBar);
  on('tool', () => { drawBar(); drawChip(); });
  on('selection', drawChip);   // «Группировать»: сколько отмечено
  drawBar();
  drawChip();
}

/* ── Что выбрано ─────────────────────────────────────────────── */

function drawBar() {
  const picked = [...state.selection].map((id) => state.elements.get(id)).filter(Boolean);
  // Пока отмечают блоки для группы, «Удалить» внизу только мешает.
  bar.hidden = !picked.length || state.tool === 'm-group';
  if (bar.hidden) return;
  bar.textContent = '';
  if (picked.length > 1) { drawMany(picked); return; }

  const one = picked[0];
  const kind = kindOf(one);
  const name = document.createElement('span');
  name.className = 'm-bar-name';
  name.textContent = `${iconOf(one)} ${one.title || kind.title}`;
  bar.append(name);

  // Стрелка из ромба: главное — её ветка, выбирается одним касанием.
  const fork = one.type === 'arrow' && state.elements.get(one.from)?.type === 'decision';
  if (fork && !state.viewOnly) {
    for (const [value, word] of [['yes', t('editor.canvas.yes_caps')], ['no', t('editor.canvas.no_caps')]]) {
      const pick = button(word, () => scene.patch(one.id, { branch: one.branch === value ? 'flow' : value }));
      pick.classList.add('m-bar-branch', 'm-bar-' + value);
      pick.classList.toggle('on', one.branch === value);
      bar.append(pick);
    }
  } else if (one.type === 'group' && !state.viewOnly) {
    // Группа: разобрать. Свернуть и развернуть — значком на её рамке, свойства — двойным касанием.
    bar.append(button(t('editor.mobile.ungroup'), () => ungroupSelected()));
  } else {
    bar.append(button(t('editor.mobile.props'), () => openDrawer('props')));
  }
  // ТЗ — у того, что исполняется или держит задание: блок, ромб, область (у группы места нет).
  if (kind.sections?.includes('spec') && one.type !== 'group') bar.append(button(t('editor.list.spec'), () => openSpec(one)));
  if (!state.viewOnly) {
    const drop = button(t('common.delete'), () => document.getElementById('btn-delete')?.click());
    drop.classList.add('m-bar-danger');
    bar.append(drop);
  }
}

/** Собрать выделенное в группу — сразу свёрнутую: на телефоне она занимает мало места,
    раскрывают её значком в углу. */
function makeGroup() {
  const group = groupSelected();
  if (group) toggleCollapse(group);
  return group;
}

/** Выделено несколько (рамкой): собрать в группу или удалить разом. */
function drawMany(picked) {
  const name = document.createElement('span');
  name.className = 'm-bar-name';
  name.textContent = t('editor.mobile.picked', { n: picked.length });
  bar.append(name);
  if (state.viewOnly) return;
  if (canGroup()) {
    bar.append(button(t('editor.mobile.group'), () => { if (makeGroup()) setSelecting(false); }));
  }
  const drop = button(t('common.delete'), () => document.getElementById('btn-delete')?.click());
  drop.classList.add('m-bar-danger');
  bar.append(drop);
}

function button(text, action) {
  const node = document.createElement('button');
  node.className = 'btn m-bar-btn';
  node.textContent = text;
  node.onclick = action;
  return node;
}

/* ── Что в руке ──────────────────────────────────────────────── */

const HOW = {
  arrow: t('editor.mobile.how_arrow'),
  link: t('editor.mobile.how_link'),
  'm-select': t('editor.mobile.how_select'),
  'm-ungroup': t('editor.mobile.how_ungroup'),
};

function drawChip() {
  const kind = state.tool && toolInfo(state.tool);
  chip.hidden = !kind;
  if (!kind) return;
  chip.textContent = '';
  const icon = document.createElement('i');
  icon.className = 'm-chip-icon';
  icon.innerHTML = kind.icon;
  const text = document.createElement('span');
  chip.append(icon, text);

  if (state.tool === 'm-group') {
    // Отмечают касаниями, собирают кнопкой: сколько отмечено — видно сразу.
    const count = [...state.selection].filter((id) => state.elements.get(id)?.type !== 'arrow').length;
    text.textContent = t('editor.mobile.how_group', { n: count });
    const make = document.createElement('button');
    make.className = 'btn m-chip-go';
    make.textContent = t('editor.mobile.gather');
    make.disabled = !canGroup();
    make.onclick = () => {
      if (!makeGroup()) return;
      state.tool = null;
      emit('tool');
    };
    chip.append(make);
  } else {
    text.textContent = `${kind.title}: ${HOW[state.tool] || t('editor.mobile.how_default')}`;
  }
}
