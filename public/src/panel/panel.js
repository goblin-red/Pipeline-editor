/* Правая панель: «что это за штука».
   Отдаёт: initPanel().
   Не делает: не рисует холст и не ходит на сервер мимо scene.js.

   Порядок секций один для всех типов: сверху первичное, ниже вторичное
   в раскрывающихся секциях. Какие секции показывать — решает core/kinds.js.

   Три экрана: свойства одного элемента, набор выделенных (panel/many.js)
   и папка с проектом. Управление собирается из panel/parts.js,
   материалы рисует panel/assets-view.js. */

import { state, on, emit, stepOf } from 'goblin/core/state.js';
import { kindOf, iconOf, sectionsOf, STEP_WORDS } from 'goblin/core/kinds.js';
import { membersOf, isCollapsed } from 'goblin/core/containers.js';
import * as scene from 'goblin/edit/scene.js';
import * as api from 'goblin/api/client.js';
import { toggleCollapse, ungroupSelected } from 'goblin/edit/grouping.js';
import { projectSections, projectCard } from 'goblin/panel/project-view.js';
import { assetsSection } from 'goblin/panel/assets-view.js';
import { linksSection } from 'goblin/panel/links-view.js';
import { openSpec } from 'goblin/shell/assetview.js';
import { openCode, infoSection } from 'goblin/shell/info.js';
import { openEmbed } from 'goblin/panel/embed.js';
import { PALETTE, paintOf, paintSwatch, colorName } from 'goblin/canvas/palette.js';
import { focusOn, dropOne, drawOne } from 'goblin/canvas/view.js';
import { setVariant } from 'goblin/core/variants.js';
import { openText } from 'goblin/shell/dialogs.js';
import { pickDir } from 'goblin/shell/dirpick.js';
import { loadProject, loadFolder } from 'goblin/api/sync.js';
import { toast } from 'goblin/shell/topbar.js';
import { refreshRun } from 'goblin/run/paint.js';
import { tableSection } from 'goblin/panel/table-edit.js';
import { thickness } from 'goblin/canvas/projection.js';
import { STARTER_TITLE_MAX } from 'goblin/canvas/element.js';
import { minSize } from 'goblin/core/kinds.js';
import { renderMany } from 'goblin/panel/many.js';
import { t } from 'goblin/core/i18n.js';
import { el, wrap, label, note, field, input, textarea, numberField,
         button, small, kv, escape, pick } from 'goblin/panel/parts.js';

let body, head, no, kindLabel;

export function initPanel() {
  body = document.getElementById('panel-body');
  head = document.getElementById('panel-head');
  no = document.getElementById('panel-no');
  kindLabel = document.getElementById('panel-kind');

  // «Показать на холсте»: из любого вида вернуться на холст и поставить
  // выбранный объект в середину экрана.
  document.getElementById('btn-focus').onclick = async () => {
    const element = current();
    if (!element) return;
    if (state.variant !== 'canvas') await setVariant('canvas');
    focusOn(element);
  };

  // Рамка одна, обликов два: группа держит содержимое списком и умеет
  // схлопываться, область — липучка под блоками. Кнопка меняет облик,
  // ничего не теряя: состав, ТЗ и материалы остаются на месте.
  document.getElementById('btn-frame').onclick = () => {
    const element = current();
    if (!element || state.viewOnly) return;
    if (element.type !== 'group' && element.type !== 'area') return;

    const next = element.type === 'group' ? 'area' : 'group';
    scene.patch(element.id, { type: next, style: { collapsed: null } });
    // Карточка собрана под прежний тип — пересобираем её целиком.
    if (state.variant === 'canvas') {
      dropOne(element.id);
      drawOne(state.elements.get(element.id));
    }
    toast(next === 'area' ? t('editor.panel.now_area') : t('editor.panel.now_group'));
  };

  document.getElementById('btn-delete').onclick = () => {
    if (!state.selection.size || state.viewOnly) return;
    scene.remove(state.selection);
    state.selection.clear();
    emit('selection');
  };

  on('selection', render);
  on('panel', render);        // вкладка «Свойства» в классике просит перерисовать
  on('element', render);
  on('steps', render);
  on('folder', render);
  on('folders', render);   // папку правят и со стороны — панель показывает свежее
  on('look', render);
  on('projection', render);
  on('links', render);        // ярлыки выбранного объекта — секция «Ярлыки»
  render();
}

function current() {
  if (state.selection.size !== 1) return null;
  return state.elements.get([...state.selection][0]) || null;
}

/* Что панель показывала в прошлый раз: сменился элемент — начинаем сверху. */
let shownKey = null;

/*
 * Секции, которые сейчас стоят в панели: имя → { node, stamp }.
 *
 * Панель свойств перерисовывается на каждое событие, а событий много: перенос
 * блока, ответ сервера, опрос. Если каждый раз собирать всё заново, прокрутка
 * сбивается, картинки материалов грузятся повторно, а каретка выпрыгивает из
 * поля. Поэтому секции собираются заново всегда, но в панель попадают только
 * те, что и вправду изменились: остальные остаются прежними узлами.
 */
const shown = new Map();

/*
 * Отпечаток секции — её разметка.
 *
 * Значения полей живут в свойствах, а не в атрибутах, поэтому сначала
 * переписываем их в атрибуты: иначе смена названия или галочки в разметке
 * не видна. Снимаем отпечаток только со свежесобранной секции — у той, что
 * уже стоит в панели, поля мог трогать человек, и его набор затирать нельзя.
 */
function stampOf(section) {
  for (const control of section.querySelectorAll('input, select, textarea')) {
    if (control.type === 'checkbox' || control.type === 'radio') {
      control.toggleAttribute('checked', control.checked);
    } else {
      control.setAttribute('value', control.value);
    }
  }
  // Раскрыта секция или свёрнута — дело человека, а не данных: в отпечаток
  // это не берём, иначе каждый щелчок по заголовку пересобирал бы секцию.
  return section.outerHTML.replace(/\sopen(?=[\s>])/g, '');
}

/*
 * Человек сейчас пишет внутри этого куска панели.
 *
 * Только поле ввода: нажатая кнопка тоже забирает фокус, и если считать её
 * занятостью, секция замрёт и не покажет то, что кнопка сделала, — звёздочка
 * «заглавная картинка» так и оставалась серой до обновления страницы.
 */
function typingIn(node) {
  const now = document.activeElement;
  return !!now && node.isConnected && node.contains(now)
    && now.matches('input, textarea, select, [contenteditable]');
}

/*
 * Поставить детей по порядку, не трогая тех, кто уже стоит на своём месте.
 *
 * Изменившуюся секцию меняем ровно одним действием. Если сначала вставить
 * новую рядом со старой, а потом убрать старую, панель на миг становится выше
 * на целую секцию — и браузер, удерживая содержимое под курсором, уводит
 * прокрутку вниз. Именно так щелчок по звёздочке «заглавная» перебрасывал
 * панель к разделу «Вид».
 */
function syncChildren(parent, nodes) {
  let at = 0;
  for (const node of nodes) {
    const here = parent.children[at];
    if (here !== node) {
      if (here && !nodes.includes(here)) parent.replaceChild(node, here);
      else parent.insertBefore(node, here || null);
    }
    at++;
  }
  while (parent.children.length > nodes.length) parent.lastElementChild.remove();
}

/* Секции выбранного элемента: меняем только изменившиеся. */
function showSections(element) {
  const nodes = [];
  for (const name of sectionsOf(element)) {
    const build = SECTIONS[name];
    if (!build) continue;

    const was = shown.get(name);
    const fresh = build(element);
    // Имя секции — на узле: мобильный скин по нему прячет вторичное (css/mobile.css).
    fresh.dataset.section = name;
    const stamp = stampOf(fresh);
    // Человек печатает прямо в этой секции — не выдёргиваем поле из-под рук.
    if (was && (was.stamp === stamp || typingIn(was.node))) { nodes.push(was.node); continue; }

    shown.set(name, { node: fresh, stamp });
    nodes.push(fresh);
  }
  syncChildren(body, nodes);
}

/*
 * То же для экранов, которые рисуют себя целиком, — папки и набора выделенных.
 * Собираем их во временную коробку и ставим, только если что-то изменилось.
 * Рисуют они прямо в панель, поэтому панель на время и подменяем коробкой.
 */
function showWhole(name, build) {
  const box = el('div');
  const real = body;
  body = box;
  try { build(box); } finally { body = real; }

  const stamp = stampOf(box);
  const was = shown.get(name);
  if (was && (was.stamp === stamp || typingIn(body))) return;

  shown.set(name, { node: box, stamp });
  syncChildren(body, [...box.children]);
}

function render() {
  // В классике панель занята другой вкладкой — не мешаем ей.
  const tabs = document.getElementById('panel-tabs');
  if (tabs && !tabs.hidden && !tabs.querySelector('[data-tab="props"]').classList.contains('on')) return;

  /* Показываем то же, что и в прошлый раз, — секции остаются на местах и
     прокрутка никуда не уезжает. Показываем другое (выбрали иной блок, ушли
     в папку) — начинаем с чистого листа и сверху: держать чужие секции нельзя,
     их поля правят прежний элемент. */
  const key = state.selection.size > 1 ? 'много'
    : current() ? 'элемент' + current().id
      : state.welcome ? 'привет' : state.pickedProject ? 'проект' : 'папка';
  // Пустое тело — его очистила другая вкладка: помнить показанное нельзя.
  if (key !== shownKey || !body.childElementCount) { shown.clear(); body.textContent = ''; body.scrollTop = 0; }
  shownKey = key;
  // Что сейчас на экране панели: мобильный скин прячет экран папки (css/mobile.css).
  body.dataset.screen = state.selection.size > 1 ? 'many' : current() ? 'element'
    : state.welcome ? 'welcome' : state.pickedProject ? 'project' : 'folder';

  const element = current();
  // Кнопка «Удалить» бессмысленна, когда ничего не выбрано, а «показать
  // на холсте» — когда выбран не один объект или это стрелка.
  document.getElementById('btn-delete').hidden = !state.selection.size;
  const one = current();
  document.getElementById('btn-focus').hidden = !one || one.type === 'arrow';
  // Менять облик есть смысл только у рамки.
  document.getElementById('btn-frame').hidden =
    !one || state.viewOnly || (one.type !== 'group' && one.type !== 'area');
  // Выделено несколько — показываем то, что делают с набором, а не свойства.
  if (state.selection.size > 1) {
    if (head) head.hidden = false;
    showWhole('много', (into) => renderMany({ body: into, no, kindLabel }));
    return;
  }
  if (!element && state.welcome) { renderWelcome(); return; }
  if (!element && state.pickedProject) { renderProject(); return; }
  if (!element) { showWhole('папка', renderFolder); return; }

  if (head) head.hidden = false;
  no.textContent = element.no;
  // Стартер прогона — во всех обликах подписан своим словом: это не просто блок.
  const starter = element.type === 'block' && !!element.props?.start;
  kindLabel.textContent = starter
    ? (state.look === 'studio' ? t('editor.panel.starter') : t('editor.panel.starter_caps'))
    : state.look === 'studio'
      ? element.title || kindOf(element).title
      : kindOf(element).title.toUpperCase();
  kindLabel.title = starter ? t('editor.panel.starter_title') : kindOf(element).title;
  showSections(element);
}

/* ── Секции ──────────────────────────────────────────────────── */

/** Поле названия. У стартера — не длиннее 30 знаков: круг на холсте маленький. */
function titleInput(element) {
  const node = input(element.title || '', (value) => scene.patch(element.id, { title: value }));
  if (element.type === 'block' && element.props?.start) {
    node.maxLength = STARTER_TITLE_MAX;
    node.title = t('editor.panel.starter_max', { max: STARTER_TITLE_MAX });
  }
  return node;
}

const SECTIONS = {
  main: (element) => wrap('', [
    field(t('editor.asset.name'), titleInput(element)),
    field(t('editor.panel.description'), textarea(element.description || '', (value) => scene.patch(element.id, { description: value }))),
    whereAndLinks(element),
  ], true),

  step: (element) => {
    const step = stepOf(element);
    // Прогон идёт, а шага у элемента ещё нет — состояние всё равно можно
    // поставить руками: человек видит схему целиком и знает, что было.
    if (!step) {
      return wrap(t('editor.panel.run_step'), [
        note(state.run ? t('editor.panel.no_step_yet') : t('editor.panel.no_step_in_run')),
        ...stepHands(element, null),
      ], true);
    }
    return wrap(t('editor.panel.step_title', { no: step.no, attempt: step.attempt }), [
      kv({
        [t('editor.panel.state')]: STEP_WORDS[step.state] || step.state,
        [t('editor.panel.step_executor')]: executorOf(element, step),
        [t('editor.panel.opened')]: step.opened || '—',
        [t('editor.panel.sent')]: step.sent || '—',
        [t('editor.panel.result')]: step.result || '—',
        [t('editor.panel.reason')]: step.error || '',
      }),
      // Исполнитель угадан (старая попытка без факта) — сказать прямо, а не выдавать за точный.
      ...(step.executor?.guess && executorOf(element, step) !== '—' ? [note(t('editor.timeline.guess_why'))] : []),
      ...stepHands(element, step),
    ], true);
  },

  assets: (element) => assetsSection(element),

  // Ярлыки: тематические связи объекта, в том числе с другими папками.
  shortcuts: (element) => linksSection(element),

  // ТЗ живёт своей секцией, а не среди материалов: это не вложение, а само
  // задание — тот самый md, который уходит worker.
  spec: (element) => wrap(t('editor.panel.spec_section'), [
    note(element.hasSpec
      ? t('editor.panel.spec_written')
      : element.type === 'decision'
        ? t('editor.panel.spec_empty_decision')
        : t('editor.panel.spec_empty')),
    small(element.hasSpec ? t('editor.panel.spec_open') : t('editor.panel.spec_write'), () => openSpec(element)),
  ], true),

  table: (element) => tableSection(element),

  /* Стартер прогона: блок с флажком start. Прогон начинается с него, worker он
     не выдаётся, его ТЗ — описание прогона для leader. В папке стартер один:
     галочка у других блоков гаснет (и сервер второй не даст поставить). */
  starter: (element) => {
    const on = !!element.props?.start;
    const other = [...state.elements.values()]
      .find((one) => one.id !== element.id && one.type === 'block' && one.props?.start);
    const box = el('input');
    box.type = 'checkbox';
    box.checked = on;
    box.disabled = state.viewOnly || (!on && !!other);
    box.onchange = () => {
      if (box.checked) {
        scene.setProps(element.id, { start: true });
        // Круг — ровный: стороны одинаковые.
        const side = Math.max(120, Math.min(element.style?.width ?? 160, element.style?.height ?? 160));
        scene.patch(element.id, { style: { width: side, height: side } });
      } else {
        scene.setProps(element.id, { start: null });
      }
    };
    const line = el('label', 'row');
    line.append(box, el('span', '', t('editor.panel.starter_title_short')));
    const hint = !on && other
      ? t('editor.panel.starter_exists', { no: other.no })
      : t('editor.panel.starter_hint');
    return wrap(t('editor.panel.starter'), [line, note(hint)], on);
  },

  agent: (element) => {
    const select = el('select', 'input');
    if (element.props?.start) {
      select.append(new Option(t('editor.panel.by_leader'), 'lead'));
      select.disabled = true;
      return wrap(t('editor.panel.executor'), [field(t('editor.panel.agent'), select)]);
    }
    select.append(new Option(t('editor.panel.unassigned'), ''));
    for (const agent of state.agents) {
      select.append(new Option(`${agent.name} · ${agent.cli || t('editor.agents.no_cli')}`, agent.id));
    }
    select.value = element.agent || '';
    select.onchange = () => scene.patch(element.id, { agent: select.value ? Number(select.value) : null });
    // Карточка действует только в команде, в Орке и SendMessage (folderUsesAgentCards на сервере).
    const solo = (state.folder?.roleScheme || 'solo') === 'solo';
    const env = state.folder?.runEnv || 'subagents';
    const hint = solo ? t('editor.panel.hint_solo')
      : env === 'subagents' ? t('editor.panel.hint_subagents')
      : t('editor.panel.hint_worker');
    return wrap(t('editor.panel.executor'), [field(t('editor.panel.agent'), select), note(hint)]);
  },

  props: (element) => {
    const props = element.props || {};
    // У каждого допа свой крестик: чистить значение, чтобы убрать свойство, —
    // приём неочевидный.
    const rows = Object.entries(props).map(([name, value]) => {
      const row = el('div', 'prop-row');
      // Крестик — в одной строке с полем: так он стоит по центру поля в любом скине.
      const line = el('div', 'prop-line');
      const off = el('button', 'asset-off', '⊗');
      off.title = t('editor.panel.prop_remove');
      off.disabled = state.viewOnly;
      off.onclick = () => scene.setProps(element.id, { [name]: null });
      line.append(propControl(element, name, value), off);
      row.append(field(name, line));
      return row;
    });
    return wrap(t('editor.panel.props'), [
      ...(rows.length ? rows : [note(t('editor.assetsview.empty'))]),
      addProp(element),
      note(t('editor.panel.prop_empty_note')),
    ], false, Object.keys(props).length);
  },

  look: (element) => {
    // Палитра одна на весь редактор: та же, что в рейке слева.
    const palette = el('div', 'colors');
    for (const color of PALETTE) {
      const paint = paintOf(color);
      const swatch = el('button', 'swatch' + ((element.style?.color || '') === color ? ' on' : ''));
      paintSwatch(swatch, color);
      swatch.title = colorName(color);
      swatch.onclick = () => scene.patch(element.id, { style: { color: color || null } });
      palette.append(swatch);
    }
    const size = el('div', 'row');
    size.append(
      numberField(state.iso ? t('editor.panel.length_x') : t('editor.panel.width'), element.style?.width ?? 280,
        (v) => scene.patch(element.id, { style: { width: Math.max(minSize(element.type).width,v) } })),
      numberField(state.iso ? t('editor.panel.depth_y') : t('editor.panel.height'), element.style?.height ?? 180,
        (v) => scene.patch(element.id, { style: { height: Math.max(minSize(element.type).height,v) } })),
      numberField(state.iso ? t('editor.panel.height_z') : t('editor.panel.thickness'), thickness(element),
        (v) => scene.patch(element.id, { style: { thick: Math.min(2000,Math.max(0,v)) } })),
    );
    const position = el('div', 'row');
    for(const [key,caption] of [['x','X'],['y','Y'],['z',t('editor.panel.lift_z')]]) {
      const control=numberField(caption,element.style?.[key]??0,(v)=>scene.patch(element.id,{style:{[key]:v}}));
      control.querySelector('input').dataset.coordinate=key;position.append(control);
    }
    const extra = [];
    // У области подпись стоит сбоку — с какой стороны, показывают два значка.
    if (element.type === 'area') {
      const now = element.style?.align === 'right' ? 'right' : 'left';
      const sides = el('div', 'switch switch-small');
      for (const [value, icon, hint] of [['left', '◧', t('editor.panel.caption_left')], ['right', '◨', t('editor.panel.caption_right')]]) {
        const button = el('button', 'switch-item switch-icon' + (now === value ? ' on' : ''), icon);
        button.title = hint;
        button.onclick = () => scene.patch(element.id, { style: { align: value } });
        sides.append(button);
      }
      // По высоте — сверху, посередине (так было всегда) или снизу.
      const heights = el('div', 'switch switch-small');
      const high = ['top', 'bottom'].includes(element.style?.valign) ? element.style.valign : 'middle';
      for (const [value, icon, label] of [['top', '⬒', t('editor.panel.caption_top')], ['middle', '⊟', t('editor.panel.caption_middle')],
                                          ['bottom', '⬓', t('editor.panel.caption_bottom')]]) {
        const button = el('button', 'switch-item switch-icon' + (high === value ? ' on' : ''), icon);
        button.setAttribute('aria-label', label);
        button.onclick = () => scene.patch(element.id, { style: { valign: value } });
        heights.append(button);
      }
      const places = el('div', 'row');
      places.append(sides, heights);
      extra.push(field(t('editor.panel.area_caption'), places));
    }
    return wrap(t('editor.panel.look'), [palette, size, position, ...extra,
      ...(state.iso?[note(t('editor.panel.iso_note'))]:[])]);
  },

  arrow: (element) => {
    const branch = el('select', 'input');
    branch.append(new Option(t('editor.panel.branch_flow'), 'flow'), new Option(t('editor.panel.branch_yes'), 'yes'), new Option(t('editor.panel.branch_no'), 'no'));
    branch.value = element.branch || 'flow';
    branch.onchange = () => scene.patch(element.id, { branch: branch.value });

    const back = el('input');
    back.type = 'checkbox';
    back.checked = !!element.back;
    back.onchange = () => scene.patch(element.id, { back: back.checked });
    const backRow = el('label', 'row');
    backRow.append(back, document.createTextNode(' ' + t('editor.panel.back_arrow')));

    const route = el('select', 'input');
    route.append(new Option(t('editor.panel.route_curve'), ''),
                 new Option(t('editor.panel.route_line'), 'line'),
                 new Option(t('editor.panel.route_simple'), 'line-simple'));
    const was = element.style?.route;
    route.value = was === 'line-simple' ? 'line-simple'
      : (['line', 'straight', 'orthogonal'].includes(was) ? 'line' : '');
    route.onchange = () => scene.patch(element.id, { style: { route: route.value || null } });

    // Дырки: «сама» — стрелка выбирает ближайшую, иначе держится выбранной.
    const ports = [];
    for (const [key, title] of [['fromSide', t('editor.panel.port_from')], ['toSide', t('editor.panel.port_to')]]) {
      const pick = el('select', 'input');
      pick.append(new Option(t('editor.panel.port_auto'), ''),
                  new Option(t('editor.panel.side_top'), 't'), new Option(t('editor.panel.side_right'), 'r'),
                  new Option(t('editor.panel.side_bottom'), 'b'), new Option(t('editor.panel.side_left'), 'l'));
      pick.value = element.style?.[key] || '';
      pick.onchange = () => scene.patch(element.id, { style: { [key]: pick.value || null } });
      ports.push(field(title, pick));
    }

    return wrap(t('editor.panel.leads_to'), [
      kv({ [t('editor.panel.from')]: nameOf(element.from), [t('editor.panel.to')]: nameOf(element.to) }),
      field(t('editor.panel.branch'), branch), field(t('editor.panel.line'), route), backRow, ...ports,
    ], true);
  },

  gateway: (element) => {
    const select = el('select', 'input');
    select.append(new Option(t('editor.panel.not_chosen'), ''));
    for (const folder of state.folders) select.append(new Option(folder.name || t('editor.canvas.folder_n', { id: folder.id }), folder.id));
    select.value = element.target || '';
    select.onchange = () => scene.patch(element.id, { target: select.value ? Number(select.value) : null });
    return wrap(t('editor.panel.door_leads'), [field(t('editor.panel.target_folder'), select)], true);
  },

  members: (element) => {
    const inside = membersOf(element.id);
    const rows = inside.map((other) => {
      const row = el('div', 'asset asset-open');
      row.innerHTML = `<span>${iconOf(other)}</span><span>${other.no} ${escape(other.title || '')}</span>`;
      row.onclick = () => { state.selection.clear(); state.selection.add(other.id); emit('selection'); };
      const off = el('button', 'asset-off', '⊗');
      off.title = t('editor.panel.take_out', { kind: kindOf(element).title });
      off.onclick = (event) => {
        event.stopPropagation();
        scene.setMembers(other.id, (other.in || []).filter((id) => id !== element.id));
      };
      row.append(off);
      return row;
    });
    const tools = el('div', 'row');
    if (element.type === 'group') {
      tools.append(
        small(isCollapsed(element) ? t('editor.panel.expand') : t('editor.panel.collapse'), () => toggleCollapse(element)),
        small(t('editor.mobile.ungroup_lc'), () => ungroupSelected()),
      );
    }
    return wrap(t('editor.panel.members'), [...(rows.length ? rows : [note(t('editor.panel.members_empty'))]), tools],
      false, inside.length);
  },

  raw: (element) => {
    const pre = el('pre', 'mono');
    pre.textContent = JSON.stringify(element, null, 2);
    pre.style.whiteSpace = 'pre-wrap';
    // Правка прямо в JSON — как ТЗ в отдельном окне: иногда это быстрее,
    // чем искать поле по секциям.
    const line = el('div', 'row');
    line.append(small(t('editor.panel.edit'), () => editRaw(element)));
    return wrap(t('editor.panel.all_about'), [line, pre]);
  },
};

/* Правка элемента текстом: показываем то же, что и в секции, а обратно берём
   только те поля, которые сервер разрешает менять. Остальное (id, номер,
   ревизии) — не наше дело, его переписывать нельзя. */
const RAW_FIELDS = ['title', 'description', 'style', 'props', 'agent', 'branch', 'back', 'target'];

async function editRaw(element) {
  const LOCKED = ['id', 'no', 'type', 'folder'];
  const was = JSON.stringify(element, null, 2);
  // Видно весь элемент, но серым — то, что сервер не примет; правится
  // подсвеченное. Так не приходится помнить список полей.
  const text = await openText({
    title: t('editor.panel.raw_title', { no: element.no }),
    hint: t('editor.panel.raw_hint'),
    value: was,
    code: { locked: LOCKED, free: RAW_FIELDS },
  });
  if (text === null || text === was) return;

  let next;
  try { next = JSON.parse(text); } catch { toast(t('editor.panel.not_json'), true); return; }

  const patch = {};
  for (const key of RAW_FIELDS) {
    if (!(key in next)) continue;
    if (JSON.stringify(next[key] ?? null) === JSON.stringify(element[key] ?? null)) continue;
    patch[key] = next[key] ?? null;
  }
  const locked = LOCKED.filter((key) => key in next
    && JSON.stringify(next[key] ?? null) !== JSON.stringify(element[key] ?? null));
  if (locked.length) toast(t('editor.panel.locked', { list: locked.join(', ') }), true);
  if (!Object.keys(patch).length) { if (!locked.length) toast(t('editor.panel.nothing_to_change')); return; }
  scene.patch(element.id, patch);
  emit('panel');
}

/*
 * Руками по шагу: человек ставит любое состояние сам. Список полный — от
 * «новый» до «не вышло», — потому что жизнь богаче кругов прогона: работу
 * могли сделать мимо схемы, шаг мог зависнуть, ветку выбрать на словах.
 */
const STEP_STATES = [
  ['none', t('editor.panel.step_new')],
  ['issued', t('editor.kinds.step_issued')],
  ['running', t('editor.kinds.step_running')],
  ['submitted', t('editor.kinds.step_submitted')],
  ['accepted', t('editor.kinds.step_accepted')],
  ['returned', t('editor.kinds.step_returned')],
  ['failed', t('editor.kinds.step_failed')],
  ['cancelled', t('editor.kinds.step_cancelled')],
];

function stepHands(element, step) {
  const now = step ? step.state : 'none';
  const run = state.run;
  // Править можно, только когда прогон выбран и схема не открыта «только смотреть».
  const can = !!run && !state.viewOnly;
  const pick = el('div', 'step-states');
  for (const [value, title] of STEP_STATES) {
    const button = el('button', 'step-state' + (value === now ? ' on' : ''), title);
    button.dataset.state = value;
    button.disabled = !can || value === now;
    button.onclick = () => setStepState(run, element, value, title, pick);
    pick.append(button);
  }
  return [label(t('editor.panel.run_state')), pick,
    note(can
      ? t('editor.panel.hands_ok', { no: run.no })
      : t('editor.panel.hands_no'))];
}

/**
 * Поставить шагу состояние руками (step.state, право человека).
 * Жетоны на стрелках сервер приводит в согласие сам; «новый» стирает попытку.
 */
async function setStepState(run, element, value, title, pick) {
  for (const button of pick.children) button.disabled = true;
  api.setHash({ run: run.id });   // правили этот прогон — его и держать на экране
  try {
    await api.post('step.state', { run: run.id, no: element.no, state: value });
    toast(t('editor.panel.step_set', { no: element.no, title }));
  } catch {
    // Причину уже показал общий обработчик ошибок api.
  }
  refreshRun();
}

/* ── Папка и проект, когда ничего не выбрано ─────────────────── */

/* Приветствие: «…» над списком проектов. Пока только логотип — позже
   здесь будут новости. */
function renderWelcome() {
  if (head) head.hidden = true;
  body.textContent = '';
  const box = el('div', 'panel-welcome');
  // Надпись логотипа — светлая на тёмной теме, тёмная на светлой (css/chrome.css, .logo-on-*).
  for (const [file, theme] of [['logo-vertical-dark.svg', 'dark'], ['logo-vertical.svg', 'light']]) {
    const logo = el('img', 'logo-on-' + theme);
    logo.src = 'img/' + file;
    logo.alt = t('editor.rail.logo');
    box.append(logo);
  }
  body.append(box);
}

/* Щёлкнули проект в списке проектов — показываем его свойства. Карточку
   держим, пока ключ тот же: панель перерисовывается часто, а чужой проект
   каждый раз читался бы с сервера заново и сбивал бы набор в поле имени. */
let projectBox = null;
let projectBoxKey = null;

function renderProject() {
  const key = state.pickedProject;
  if (projectBoxKey !== key) {
    projectBox = projectCard(key, () => emit('enter-project', key));
    projectBoxKey = key;
  }
  if (head) head.hidden = true;
  body.textContent = '';
  body.append(projectBox);
}

/* Ничего не выбрано — показываем папку. Шапку панели при этом убираем совсем:
   строка «— ПАПКА» ничего не сообщала, а место занимала. */
function renderFolder() {
  const folder = state.folder;
  if (head) head.hidden = true;
  body.textContent = '';

  if (!folder) {
    for (const section of projectSections({ open: true })) body.append(section);
    return;
  }

  body.append(wrap(t('editor.linksview.folder'), [
    field(t('editor.panel.folder_name'), input(folder.name || '', (value) => api.post('folder.update', { id: folder.id, name: value }))),
    // Веб-режим: рабочая папка — у агента на его компьютере, путь на сервере ни к чему.
    state.project?.agentsRemote && folder.agentPath
      ? field(t('editor.panel.agent_dir'), agentDirField(folder))
      : field(t('editor.dirpick.title'), workDirField(folder)),
    field(t('editor.panel.folder_arrows'), folderRoute(folder)),
    // Код папки — это о самой папке, поэтому живёт здесь, а не в меню плашки.
    small(t('editor.panel.folder_code'), openCode),
    button(t('editor.panel.get_script'), () => openEmbed(folder)),
    deleteFolder(folder),
  ], true));
  body.append(infoSection());
}

/**
 * Папка схемы у агента (веб): только показать — её заводит ведущий при начале прогона.
 * Finder браузер не откроет, поэтому кнопка копирует путь: полный — из последнего прогона
 * (его назвал сервер при begin), до первого прогона — относительный.
 */
function agentDirField(folder) {
  const path = el('input', 'input mono');
  path.readOnly = true;
  path.value = './' + folder.agentPath;
  path.onfocus = () => path.select();
  const copy = small(t('editor.panel.copy_path'), async () => {
    const last = (state.runs || []).filter((run) => run.folder === folder.id && run.agentDir)
      .sort((a, b) => b.no - a.no)[0];
    const full = last?.agentDir || path.value;
    try {
      await navigator.clipboard.writeText(full);
      toast(t('editor.panel.path_copied', { path: full }));
    } catch {
      path.select();
    }
  });
  copy.disabled = false;   // скопировать путь можно и без права правки
  const row = el('div', 'workdir-row');
  row.append(path, copy);
  const box = el('div', 'agentdir');
  const note = el('small', 'muted');
  note.textContent = t('editor.panel.agent_dir_hint');
  box.append(row, note);
  return box;
}

/** Рабочая папка: путь руками или «Обзор…» — папки на сервере. */
function workDirField(folder) {
  const save = (value) => api.post('folder.update', { id: folder.id, workDir: value });
  const path = input(folder.workDir || '', save);
  const browse = small(t('editor.panel.browse'), async () => {
    const picked = await pickDir(path.value.trim());
    if (picked === null || picked === path.value) return;
    path.value = picked;
    save(picked);
  });
  // Открыть в Finder: папку открывает сервер — он на этом же Mac.
  const open = small(t('editor.panel.open_folder'), async () => {
    await api.post('folder.open', { folder: folder.id });
    toast(t('editor.panel.folder_opened'));
  });
  const row = el('div', 'workdir-row');
  row.append(path, browse, open);
  return row;
}

/**
 * Удалить папку целиком.
 *
 * Уходит и вложенное: подпапки, блоки, стрелки. Поэтому спрашиваем, и в
 * вопросе честно говорим, сколько всего уедет. После удаления открываем
 * соседнюю папку — оставаться в несуществующей нельзя.
 */
function deleteFolder(folder) {
  const line = el('div', 'panel-danger');
  const kids = state.folders.filter((one) => one.parent === folder.id).length;

  const node = button(t('editor.panel.delete_folder'), async () => {
    const what = [t('editor.panel.delete_ask', { name: folder.name || folder.id })];
    if (kids) what.push(t('editor.panel.delete_kids', { n: kids }));
    what.push(t('editor.panel.delete_warn'));
    if (!confirm(what.join('\n'))) return;

    await api.post('folder.delete', { id: folder.id });
    await loadProject();

    const next = state.folders.find((one) => one.id !== folder.id);
    if (next) await loadFolder(next.id);
    else emit('folder');
    toast(t('editor.panel.deleted'));
  });
  node.classList.add('btn-danger');
  line.append(node);
  return line;
}

/**
 * Какими рисовать стрелки всей папки. Выбор папки старше выбора стрелки:
 * сначала смотрим сюда, и только на «как у стрелки» спрашиваем саму стрелку.
 */
function folderRoute(folder) {
  const rows = [{ key: 'curve', label: t('editor.panel.routes_curve') },
                { key: 'line', label: t('editor.panel.routes_line') },
                { key: 'line-simple', label: t('editor.panel.routes_simple') }];
  const was = rows.some((row) => row.key === folder.style?.route) ? folder.style.route : '';
  const node = pick(rows, was, true, t('editor.panel.routes_each'));
  node.disabled = state.viewOnly;
  node.onchange = async () => {
    const value = node.value || null;
    const live = state.folder || folder;  // папку мог обновить опрос сервера
    live.style = { ...(live.style || {}), route: value };
    if (value === null) delete live.style.route;
    emit('scheme');                       // перерисовать холст сразу
    await api.post('folder.update', { id: live.id, style: { route: value } });
  };
  return node;
}

/**
 * Вид поля допа: из config.txt (второй столбец — list, bool, number, select, text),
 * а у своего имени — по самому значению. Закрытый список вариантов — всегда select:
 * сервер такие значения проверяет и всё прочее отбивает целой пачкой правок.
 */
function propKind(name, value) {
  const def = propDef(name);
  if (def?.extra?.length) return 'select';
  if (def?.color && def.color !== 'text') return def.color;
  if (Array.isArray(value)) return 'list';
  if (typeof value === 'boolean') return 'bool';
  if (typeof value === 'number') return 'number';
  return 'text';
}

/** Строки многострочного поля → массив; пусто → null (свойство убирается). */
function linesOf(text) {
  const lines = String(text).split('\n').map((line) => line.trim()).filter(Boolean);
  return lines.length ? lines : null;
}

/**
 * Поле значения по виду допа. Отдаёт { node, read }:
 * read() — значение для сервера (массив, true/false, число, строка) или null.
 */
function propEditor(kind, name, value) {
  let node;
  let read;

  if (kind === 'select') {
    node = el('select', 'input');
    node.append(new Option(t('editor.panel.not_set_dash'), ''));
    for (const option of propDef(name)?.extra || []) node.append(new Option(option, option));
    node.value = String(value ?? '');
    read = () => node.value || null;
  } else if (kind === 'list') {
    // По строке на значение; сохраняется массивом.
    node = el('textarea', 'input');
    const list = Array.isArray(value) ? value : (value == null || value === '' ? [] : [String(value)]);
    node.value = list.join('\n');
    node.rows = Math.max(3, list.length + 1);
    node.placeholder = t('editor.panel.per_line');
    read = () => linesOf(node.value);
  } else if (kind === 'bool') {
    node = el('input');
    node.type = 'checkbox';
    node.checked = value === true || value === 1 || value === '1' || value === 'true';
    read = () => node.checked;
  } else if (kind === 'number') {
    node = el('input', 'input');
    node.type = 'number';
    node.value = value ?? '';
    read = () => (node.value.trim() === '' || !Number.isFinite(Number(node.value)) ? null : Number(node.value));
  } else {
    node = el('input', 'input');
    node.value = String(value ?? '');
    read = () => (node.value.trim() === '' ? null : node.value);
  }

  node.disabled = state.viewOnly;
  if (kind !== 'bool') node.autocomplete = 'off';
  return { node, read };
}

/** Поле значения существующего допа: правка уходит на сервер по change. */
function propControl(element, name, value) {
  const editor = propEditor(propKind(name, value), name, value);
  editor.node.onchange = () => scene.setProps(element.id, { [name]: editor.read() });
  return editor.node;
}

/**
 * Добавить доп: имя и значение сразу.
 * Имя выбирается из списка config.txt — так видно, что вообще бывает, и как
 * оно называется по-человечески. Нужно своё — последний пункт списка даёт
 * обычное поле. Доп без значения не существует, поэтому пустое не отправляем.
 */
function addProp(element) {
  const holder = el('div');
  // Две строки: сверху имя во всю ширину, снизу значение и кнопка.
  const line = el('div', 'prop-add');

  const pick = el('select', 'input');
  pick.append(new Option(t('editor.panel.prop_pick'), ''));
  const custom = el('input', 'input');
  custom.placeholder = t('editor.panel.own_name');
  custom.hidden = true;
  custom.autocomplete = 'off';

  // Поле значения — по виду выбранного свойства.
  const slot = el('div', 'prop-value');
  let editor;
  const reslot = () => {
    const name = pick.value === '*' ? '' : pick.value;
    editor = propEditor(name ? propKind(name) : 'text', name, null);
    if (editor.node.tagName === 'INPUT' && editor.node.type !== 'checkbox') {
      editor.node.placeholder = t('editor.panel.value');
      editor.node.onkeydown = onEnter;
    }
    slot.textContent = '';
    slot.append(editor.node);
  };

  // Список имён приходит с сервера один раз на страницу.
  propNames().then((names) => {
    for (const row of names) pick.append(new Option(`${row.label || row.key} · ${row.key}`, row.key));
    pick.append(new Option(t('editor.panel.own_name_dots'), '*'));
  });
  pick.onchange = () => {
    custom.hidden = pick.value !== '*';
    if (!custom.hidden) custom.focus();
    reslot();
  };

  const put = () => {
    const word = (pick.value === '*' ? custom.value : pick.value).trim();
    const next = editor.read();
    if (!word || next === null) return;
    scene.setProps(element.id, { [word]: next });
    pick.value = '';
    custom.value = '';
    custom.hidden = true;
    reslot();
  };
  // В многострочном поле Enter переводит строку, поэтому оно на Enter не отправляет.
  const onEnter = (event) => { if (event.key === 'Enter') put(); };
  custom.onkeydown = onEnter;
  reslot();

  line.append(pick, custom, slot, small(t('editor.panel.add'), put));
  holder.append(label(t('editor.panel.new_prop')), line);
  return holder;
}

/* Имена и допустимые значения допов из config.txt: спрашиваем один раз. */
let propList = [];
let namesAsked = null;
function propNames() {
  if (!namesAsked) {
    namesAsked = api.get('config.get')
      .then((answer) => {
        propList = answer.lists?.props || [];
        emit('panel');        // пришли списки — поля перерисуются нужным видом
        return propList;
      })
      .catch(() => []);
  }
  return namesAsked;
}

function propDef(name) {
  if (!namesAsked) propNames();
  return propList.find((row) => row.key === name) || null;
}

/* ── Мелочи ──────────────────────────────────────────────────── */

/** «Входит в» и «Связи» — одной строкой, двумя колонками. */
function whereAndLinks(element) {
  const row = el('div', 'where-row');
  row.append(...[where(element), links(element)].filter(Boolean));
  return row;
}

function where(element) {
  const folder = state.folders.find((f) => f.id === element.folder);
  const containers = (element.in || []).map((id) => state.elements.get(id)).filter(Boolean);
  const holder = el('div');
  holder.append(label(t('editor.panel.contained_in')));
  const line = el('div');
  line.innerHTML = `<span class="chip">📁 ${escape(folder?.name || t('editor.linksview.folder').toLowerCase())}</span>`
    + containers.map((c) => `<span class="chip">${iconOf(c)} ${escape(c.title || c.no)}</span>`).join('');
  holder.append(line);
  return holder;
}

function links(element) {
  if (element.type === 'arrow') return null;
  const incoming = [...state.elements.values()].filter((e) => e.type === 'arrow' && e.to === element.id);
  const outgoing = [...state.elements.values()].filter((e) => e.type === 'arrow' && e.from === element.id);
  const holder = el('div');
  holder.append(label(t('editor.panel.links')));
  const line = el('div');
  line.innerHTML = `<span class="chip">← ${incoming.length}</span><span class="chip">→ ${outgoing.length}</span>`;
  holder.append(line);
  return holder;
}

function nameOf(id) {
  const element = state.elements.get(id);
  return element ? `${element.no} ${element.title || kindOf(element).title}` : '—';
}

function agentName(id) {
  const agent = state.agents.find((a) => a.id === id);
  return agent ? agent.name : '—';
}

/**
 * Кто выполнял шаг. Ответ даёт сервер (run.state → steps[].executor, тот же, что
 * строка ленты времени). Прежнее правило по нынешней папке — только для данных без
 * поля. У ромба, шлюза и стартера исполнителя-работника нет.
 */
function executorOf(element, step) {
  if (element.type !== 'block' || element.props?.start) return '—';
  const { title, guess } = step.executor || {};
  if (title) return guess ? t('editor.timeline.guess', { name: title }) : title;
  return executorGuess(step);
}

/** Запасное правило для старых данных: карточка → ведущий сам → имя who → «worker». */
function executorGuess(step) {
  if (step.agent) return agentName(step.agent);
  const solo = (state.folder?.roleScheme || 'solo') === 'solo';
  const cards = !solo && ['orca', 'sendmessage'].includes(state.folder?.runEnv);
  if (solo || cards) {
    const lead = state.agents.find((a) => a.id === state.run?.lead);
    return lead ? lead.name : t('editor.timeline.lane_lead');
  }
  return step.who || 'worker';
}
