/* Мышь и клавиши: выбрать, двигать, рисовать, соединять.
   Отдаёт: initPointer().
   Не делает: не пишет на сервер напрямую — только через edit/scene.js.

   Жесты: щелчок — выбрать, двойной по карточке — править текст, двойной
   по пустому месту — поставить элемент, тянуть элемент — двигать, тянуть
   за уголок — менять размер, тянуть от порта — стрелка, тянуть пустоту —
   рамка выделения, зажатый пробел — холст ходит за мышью. С инструментом
   «ярлык» тянут от объекта к объекту — рождается ярлык; щелчок по кружку
   ярлыка — переход (canvas/link.js), холст его не трогает.
   Блок едет плавно, за пальцем, а к точкам сетки встаёт, когда его отпустили. */

import { state, on, emit, stepOf } from 'goblin/core/state.js';
import { settings, snapTo } from 'goblin/core/settings.js';
import { toCanvas, panBy, drawOne, nodeOf, camera } from 'goblin/canvas/view.js';
import { projectedBounds, surfaceElement } from 'goblin/canvas/projection.js';
import { box, boxesOverlap } from 'goblin/canvas/geometry.js';
import { minSize, isLinkEnd } from 'goblin/core/kinds.js';
import * as scene from 'goblin/edit/scene.js';
import { createLink } from 'goblin/edit/links.js';

/* Выбрать один элемент. Если он и так выбран один — молчим: лишнее событие
   пересобирает правую панель, и её прокрутка дёргается (щелчок по значку MD
   на уже выбранной карточке). */
function pickOne(id) {
  if (state.selection.size === 1 && state.selection.has(id)) return;
  state.selection.clear();
  state.selection.add(id);
  emit('selection');
}
import { carrySet, planDrop, isHiddenByCollapse, isCollapsed } from 'goblin/core/containers.js';
import { openSection } from 'goblin/panel/parts.js';
import { assetsTitle } from 'goblin/panel/assets-view.js';
import { showDrop, clearDrop, commitDrop, affected } from 'goblin/edit/drop.js';
import { toast } from 'goblin/shell/topbar.js';
import { openSpec, openAsset, coverAsset } from 'goblin/shell/assetview.js';
import { editInPlace, editCell } from 'goblin/edit/inplace.js';
import { placeAt } from 'goblin/edit/place.js';
import { groupSelected, ungroupSelected, toggleCollapse } from 'goblin/edit/grouping.js';
import { group as oneStep, undo, redo } from 'goblin/edit/history.js';
import { copySelection, pasteClipboard, duplicateSelection } from 'goblin/edit/clipboard.js';
import { t } from 'goblin/core/i18n.js';

let mode = null;     // move | resize | marquee | pan | link (стрелка) | shortcut (ярлык)
let start = null;
let moved = false;
let linkFrom = null;
let linkSide = null;   // дырка, с которой потянули стрелку
let ghost = null;
let spaceHeld = false;
let frame = null;    // кадр отрисовки: за один кадр рисуем один раз
let toolOff = null;  // отложенный сброс инструмента: второй щелчок (двойной) его отменяет

/* Что лежит поверх холста и живёт своей жизнью: указатель таким кнопкам
   отдаётся целиком, холст в их щелчки не вмешивается. Кнопки самой карточки
   сюда не входят — они разбираются в openUnderPointer(). */
const UI_OVER_CANVAS = '.canvas-tools, .runbar, .run-chip, .run-follow, .empty-hint, .iso-overlay, .iso-tools';

/**
 * Щелчок по пустому месту левой плашки или правой панели снимает выбранный
 * инструмент — как щелчок по пустому холсту. Кнопки, поля и плитки не в счёт:
 * у них своё дело (кнопка инструмента сама переключает выбор).
 */
function initToolReset() {
  document.addEventListener('click', (event) => {
    if (!state.tool) return;
    const target = event.target;
    if (!target.closest?.('#rail, #panel')) return;
    if (target.closest('button, input, select, textarea, a, label, summary, [contenteditable], [draggable="true"]')) return;
    state.tool = null;
    emit('tool');
  });
}

export function initPointer() {
  initToolReset();
  const wrap = document.getElementById('canvas-wrap');
  const marquee = document.getElementById('marquee');
  initLinkTool();

  wrap.addEventListener('pointerdown', (event) => {
    // В поле правки текста мышь принадлежит тексту, а не холсту.
    if (event.target.isContentEditable) { mode = null; return; }
    // Кнопки поверх холста (масштаб, «показать всё») — не жест по схеме.
    // Иначе холст забирал указатель себе, и щелчок до кнопки не доходил.
    if (event.target.closest(UI_OVER_CANVAS)) { mode = null; return; }
    // Кружок ярлыка — это переход по щелчку, а не жест по карточке.
    if (event.target.closest('.link-stub')) { mode = null; return; }

    const port = event.target.closest('.port');
    const node = event.target.closest('.el');
    const arrow = event.target.closest('g[data-id]');
    start = { x: event.clientX, y: event.clientY, canvas: toCanvas(event.clientX, event.clientY),
              target: event.target };
    moved = false;

    // Пробел зажат — что бы ни было под курсором, тянем холст.
    if (spaceHeld && settings.spacePan) { mode = 'pan'; wrap.setPointerCapture(event.pointerId); return; }
    if (state.viewOnly && !node) { mode = 'pan'; return; }

    // 1. Уголок размера — до всего остального: он лежит поверх карточки.
    if (node && !state.viewOnly && event.target.closest('.node-grip')) {
      const id = Number(node.dataset.id);
      const element = state.elements.get(id);
      if (element) {
        pickOne(id);
        mode = 'resize';
        start.size = { id, ...box(element), style: { ...element.style } };
        nodeOf(id)?.classList.add('resizing');
        wrap.setPointerCapture(event.pointerId);
        return;
      }
    }
    // 2. Стрелка от порта.
    if (port && node && !state.viewOnly) {
      mode = 'link';
      linkFrom = Number(node.dataset.id);
      linkSide = port.dataset.side || null;      // с какой дырки потянули
      ghost = makeGhost(start.canvas);
      document.body.classList.add('linking');   // рамки гаснут: к ним стрелка не идёт
      wrap.setPointerCapture(event.pointerId);
      return;
    }
    // 2а. Инструмент «ярлык»: тянут от блока, группы или области к другому такому же.
    if (node && state.tool === 'link' && !state.viewOnly) {
      const from = state.elements.get(Number(node.dataset.id));
      if (from && isLinkEnd(from.type)) {
        mode = 'shortcut';
        linkFrom = from.id;
        ghost = makeGhost(start.canvas, 'link-ghost');
        wrap.setPointerCapture(event.pointerId);
        return;
      }
    }
    // 3. Элемент: выбрать и, если потянут, двигать.
    if (node) {
      const id = Number(node.dataset.id);
      if (event.shiftKey) { state.selection.add(id); emit('selection'); }
      else if (state.selection.has(id)) state.selection.add(id);   // уже выбран — панель не трогаем
      else pickOne(id);
      if (state.viewOnly) { mode = 'click'; return; }
      mode = 'move';
      // Группа и область едут вместе с содержимым — иначе рамка уезжает пустой.
      start.carry = carrySet(state.selection);
      start.boxes = new Map([...start.carry.keys()].map((sid) => {
        const element = state.elements.get(sid);
        return [sid, element ? { ...box(element), style: { ...element.style } } : null];
      }));
      wrap.setPointerCapture(event.pointerId);
      return;
    }
    // 4. Стрелка на холсте: выбрать.
    if (arrow) {
      const id = Number(arrow.dataset.id);
      if (event.shiftKey) { state.selection.add(id); emit('selection'); }
      else pickOne(id);
      mode = null;
      return;
    }
    // 5. Пустота: рамка выделения или панорама. Выбранный инструмент здесь
    //    ничего не ставит: элемент рождается двойным щелчком, иначе он выскакивал
    //    при каждом промахе мимо карточки.
    mode = event.button === 1 || event.altKey ? 'pan' : 'marquee';
    if (!event.shiftKey) { state.selection.clear(); emit('selection'); }
    wrap.setPointerCapture(event.pointerId);
  });

  wrap.addEventListener('pointermove', (event) => {
    hover = toCanvas(event.clientX, event.clientY);
    // Зажатый пробел двигает холст без всякой кнопки — просто веди мышью.
    if (spaceHeld && settings.spacePan && mode !== 'move') {
      panBy(event.movementX, event.movementY);
      return;
    }
    if (!mode || !start) return;
    const dx = event.clientX - start.x;
    const dy = event.clientY - start.y;
    if (Math.abs(dx) + Math.abs(dy) > 3) moved = true;

    if (mode === 'pan') { panBy(event.movementX, event.movementY); return; }

    // Блок едет ровно за пальцем, без ступенек: прилипание будет при отпускании.
    if (mode === 'move') {
      if (!moved) return;
      const here = toCanvas(event.clientX, event.clientY);
      start.shift = { x: here.x - start.canvas.x, y: here.y - start.canvas.y };
      dragFrame();
      return;
    }

    // Размер тянется от левого верхнего угла: он остаётся на месте.
    if (mode === 'resize') {
      if (!moved) return;
      const here = toCanvas(event.clientX, event.clientY);
      const element = state.elements.get(start.size.id);
      if (!element) return;
      element.style = { ...element.style, ...resizedTo(element, here) };
      sizeFrame(element);
      return;
    }

    if ((mode === 'link' || mode === 'shortcut') && ghost) {
      const here = toCanvas(event.clientX, event.clientY);
      const from = state.elements.get(linkFrom);
      const b = box(surfaceElement(from));
      ghost.setAttribute('d', `M ${b.x + b.w / 2} ${b.y + b.h / 2} L ${here.x} ${here.y}`);
      return;
    }

    if (mode === 'marquee') {
      const rect = wrap.getBoundingClientRect();
      Object.assign(marquee.style, {
        left: Math.min(start.x, event.clientX) - rect.left + 'px',
        top: Math.min(start.y, event.clientY) - rect.top + 'px',
        width: Math.abs(dx) + 'px',
        height: Math.abs(dy) + 'px',
      });
      marquee.hidden = false;
    }
  });

  wrap.addEventListener('pointerup', (event) => {
    const here = toCanvas(event.clientX, event.clientY);
    const target = start?.target;

    if (mode === 'move' && moved) {
      start.shift={x:here.x-start.canvas.x,y:here.y-start.canvas.y};
      dropMoved();
    }
    if (mode === 'resize') {
      if (moved) {
        const element = state.elements.get(start.size.id);
        if (element) element.style = { ...element.style, ...resizedTo(element, here) };
      }
      dropResized();
    }

    // Щёлкнули, не потянув: значок ТЗ и плашка материала открывают своё окно.
    if ((mode === 'move' || mode === 'click') && !moved && target) openUnderPointer(target);

    if (mode === 'link') {
      ghost?.remove();
      ghost = null;
      document.body.classList.remove('linking');
      const under = document.elementFromPoint(event.clientX, event.clientY);
      const hit = under?.closest('.el');
      const toId = hit ? Number(hit.dataset.id) : null;
      // В ручном режиме стрелка запоминает обе дырки: ту, с которой тянули,
      // и ту, на которую бросили. В обычном стороны выбираются сами.
      const dropSide = under?.closest('.port')?.dataset.side || null;
      const manual = settings.arrowPorts === 'manual' && linkSide && dropSide;
      if (toId && toId !== linkFrom) {
        scene.connect(linkFrom, toId, null,
          manual ? { fromSide: linkSide, toSide: dropSide } : null);
      }
      linkFrom = null;
      linkSide = null;
    }

    // Ярлык: протянули и отпустили на другом объекте — он и есть цель.
    // Щёлкнули, не потянув, — это «щёлк — щёлк»: первый щелчок выбирает
    // владельца, второй (можно в другой папке) — цель. Правила концов
    // подскажет edit/links.js, окончательно проверит сервер.
    if (mode === 'shortcut') {
      ghost?.remove();
      ghost = null;
      const hit = document.elementFromPoint(event.clientX, event.clientY)?.closest('.el');
      const toId = hit ? Number(hit.dataset.id) : null;
      if (moved) {
        if (toId && toId !== linkFrom) createLink(linkFrom, toId);
      } else {
        clickLink(linkFrom);
      }
      linkFrom = null;
    }

    if (mode === 'marquee' && moved) {
      const area = {
        x: Math.min(start.canvas.x, here.x), y: Math.min(start.canvas.y, here.y),
        w: Math.abs(here.x - start.canvas.x), h: Math.abs(here.y - start.canvas.y),
      };
      for (const element of state.elements.values()) {
        if (element.type === 'arrow' || isHiddenByCollapse(element)) continue;
        if (camera.iso) {
          const rect=wrap.getBoundingClientRect();
          const screen={x:(Math.min(start.x,event.clientX)-rect.left-camera.x)/camera.zoom,
            y:(Math.min(start.y,event.clientY)-rect.top-camera.y)/camera.zoom,
            w:Math.abs(event.clientX-start.x)/camera.zoom,h:Math.abs(event.clientY-start.y)/camera.zoom};
          if(boxesOverlap(screen,projectedBounds(element,true)))state.selection.add(element.id);
        } else if (boxesOverlap(area, box(element))) state.selection.add(element.id);
      }
      emit('selection');
    }

    // Одиночный щелчок по пустому месту снимает выбранный инструмент. Не сразу:
    // если следом придёт второй щелчок — это двойной, и он ставит элемент
    // выбранного инструмента (сброс отменяет dblclick).
    if (mode === 'marquee' && !moved && state.tool) {
      clearTimeout(toolOff);
      toolOff = setTimeout(() => { toolOff = null; state.tool = null; emit('tool'); }, 320);
    }

    marquee.hidden = true;
    mode = null;
    start = null;
  });

  // Двойной щелчок правит название и описание прямо в карточке — в любом виде.
  // У группы он делает другое: схлопывает и раскрывает её.
  // Цель ищем точкой, а не по event.target: захват указателя на первом щелчке
  // переадресует двойной щелчок холсту, и карточки в target уже нет.
  wrap.addEventListener('dblclick', (event) => {
    // Это двойной щелчок, а не одиночный: выбранный инструмент не сбрасываем.
    clearTimeout(toolOff);
    toolOff = null;
    const hit = document.elementFromPoint(event.clientX, event.clientY);
    if (!hit || hit.closest('.node-grip, .node-fold, .port, .link-stub')) return;
    if (hit.closest('.canvas-tools, .runbar')) return;   // кнопки поверх холста
    const node = hit.closest('.el');
    const clicked = node ? state.elements.get(Number(node.dataset.id)) : null;

    /* Группа открывается и закрывается двойным щелчком, но не одинаково:
       схлопнутую раскрывает щелчок в любое её место — она вся как закрытая
       папка; раскрытую схлопывает только шапка с названием, потому что внутри
       рамки работают с её содержимым и захлопывать её там было бы под руку.
       Имя и описание группы правятся в панели свойств, а не на холсте. */
    if (clicked?.type === 'group') {
      if (state.viewOnly) return;
      if (isCollapsed(clicked) || hit.closest('.node-title')) {
        event.preventDefault();
        toggleCollapse(clicked);
      }
      return;
    }

    // Картинка открывается двойным щелчком — и в режиме просмотра тоже.
    if (node && hit.closest('.node-image-section')) {
      const shown = state.elements.get(Number(node.dataset.id));
      const asset = shown && coverAsset(shown);
      if (asset) {
        event.preventDefault();
        openAsset(asset, shown);
        return;
      }
    }

    if (state.viewOnly) return;
    // Пустое место — значит, тут хотят новый элемент: какой выбран, тот и ставим.
    // Инструмент после этого остаётся выбранным: обычно ставят несколько подряд,
    // а снимается он одиночным щелчком по пустому месту, повторным щелчком
    // по кнопке или клавишей Esc.
    if (!node) {
      event.preventDefault();
      // Ярлык сам по себе не стоит: он крепится к блоку. Пустое место — не блок.
      if (state.tool === 'link') {
        toast(pendingLink ? t('editor.pointer.pick_target')
                          : t('editor.pointer.link_on_block'), true);
        return;
      }
      placeAt(state.tool && state.tool !== 'arrow' ? state.tool : 'block',
        toCanvas(event.clientX, event.clientY));
      return;
    }
    const element = clicked;
    if (!element) return;
    event.preventDefault();

    // Клетка таблицы правится на месте, а не в панели: правят то, что видят.
    const cell = hit.closest('.node-grid [data-kind]');
    if (cell) {
      editCell(node, element, cell, { x: event.clientX, y: event.clientY });
      return;
    }

    editInPlace(node, element, fieldAt(node, hit, event.clientY),
      { x: event.clientX, y: event.clientY });
  });

  wrap.addEventListener('pointerleave', () => { hover = null; });

  document.addEventListener('keydown', onKey);
  document.addEventListener('keyup', onKeyUp);
  window.addEventListener('blur', releaseSpace);
}

/**
 * Что правим: верх карточки — название, всё, что ниже, — описание.
 * Пустое описание не показывается, поэтому попасть по нему нельзя —
 * выручает высота: щёлкнул ниже заголовка, значит хотел описание.
 */
function fieldAt(node, hit, clientY) {
  if (hit.closest('.node-desc')) return 'description';
  if (hit.closest('.node-title')) return 'title';
  const title = node.querySelector('.node-title').getBoundingClientRect();
  return clientY > title.bottom + 4 ? 'description' : 'title';
}

/* ── Перемещение ──────────────────────────────────────────────── */

/** Один кадр — одна отрисовка: иначе на быстрой мыши видны рывки. */
function dragFrame() {
  if (frame) return;
  frame = requestAnimationFrame(() => {
    frame = null;
    if (mode !== 'move' || !start?.shift) return;
    for (const [id, origin] of start.boxes) {
      if (!origin) continue;
      const element = state.elements.get(id);
      if (!element) continue;
      element.style = { ...element.style, x: origin.x + start.shift.x, y: origin.y + start.shift.y };
      nodeOf(id)?.classList.add('dragging');
      drawOne(element);
    }
    showDrop(planDrop(start.carry));
  });
}

/** Размер перерисовывается тем же кадром, что и перенос. */
function sizeFrame() {
  if (frame) return;
  frame = requestAnimationFrame(() => {
    frame = null;
    if (mode !== 'resize') return;
    const element = state.elements.get(start.size.id);
    if (!element) return;
    drawOne(element);
    // Размер только подбирает: тянуть рамку и растерять её содержимое нельзя.
    showDrop(planDrop(affected(element), { addOnly: true }));
  });
}

/** Отпустили уголок: размер округляется к сетке и уезжает на сервер. */
function dropResized() {
  cancelAnimationFrame(frame);
  frame = null;
  clearDrop();
  const element = state.elements.get(start.size.id);
  nodeOf(start.size.id)?.classList.remove('resizing');
  if (!element || !moved) return;

  const least = minSize(element.type);
  const width = Math.max(least.width, snapTo(element.style.width));
  const height = Math.max(least.height, snapTo(element.style.height));
  oneStep(t('editor.history.resize'), () => {
    // Вернём исходные поля перед patch, чтобы undo запомнил начало жеста.
    for(const key of ['width','height']) {
      if(Object.hasOwn(start.size.style,key))element.style[key]=start.size.style[key];
      else delete element.style[key];
    }
    scene.patch(element.id, { style: { width, height } });
    // Рамка могла накрыть соседей — подбираем их. Выбросить не может никого.
    commitDrop(affected(element), { addOnly: true });
  });
  emit('panel');
}

/** Отпустили: блок встаёт на ближайшие точки и уезжает на сервер. */
function dropMoved() {
  cancelAnimationFrame(frame);
  frame = null;

  clearDrop();
  const moving = [...start.boxes.keys()].map((id) => state.elements.get(id)).filter(Boolean);
  const carry = start.carry;
  for (const element of moving) {
    nodeOf(element.id)?.classList.remove('dragging');
    nodeOf(element.id)?.classList.add('settling');
  }
  oneStep(t('editor.history.move'), () => {
    for (const element of moving) {
      const original=start.boxes.get(element.id);
      const x=snapTo(original.x+(start.shift?.x||0)),y=snapTo(original.y+(start.shift?.y||0));
      for(const key of ['x','y']) {
        if(Object.hasOwn(original.style,key))element.style[key]=original.style[key];
        else delete element.style[key];
      }
      scene.patch(element.id,{style:{x,y}});
    }
    commitDrop(carry);
  });
  setTimeout(() => {
    for (const element of moving) nodeOf(element.id)?.classList.remove('settling');
  }, 200);
}

/* ── Размер уголком ────────────────────────────────────────────── */

/**
 * Новый размер, пока тянут уголок: от начального плюс сдвиг, не меньше наименьшего.
 * Стартер — всегда круг: ширина и высота равны, круг растёт по большему сдвигу.
 */
function resizedTo(element, here) {
  const least = minSize(element.type);
  const width = Math.max(least.width, start.size.w + (here.x - start.canvas.x));
  const height = Math.max(least.height, start.size.h + (here.y - start.canvas.y));
  if (element.type === 'block' && element.props?.start) {
    const side = Math.max(width, height);
    return { width: side, height: side };
  }
  return { width, height };
}

/* ── Щелчки по частям карточки ────────────────────────────────── */

function openUnderPointer(target) {
  const node = target.closest?.('.el');
  if (!node) return;
  const element = state.elements.get(Number(node.dataset.id));
  if (!element) return;

  if (target.closest('.node-fold')) { toggleCollapse(element); return; }
  // Скрепка на карточке — короткий путь к материалам: выбираем элемент,
  // раскрываем секцию и просим панель показать свойства.
  if (target.closest('.node-clip')) {
    pickOne(element.id);
    openSection(assetsTitle(element));
    emit('props-tab');
    emit('panel');
    return;
  }
  // Крестик на плашке исполнителя — снять агента с этого блока.
  if (target.closest('.node-brick-x')) {
    if (state.viewOnly) return;
    scene.patch(element.id, { agent: null });
    return;
  }
  if (target.closest('.node-md-badge')) { openSpec(element); return; }
  // Картинка — исключение: она занимает пол-карточки, и одиночный щелчок по ней
  // должен просто выделять блок. Своё окно она открывает двойным щелчком.
  if (target.closest('.node-image-section')) return;
  const chip = target.closest('.node-chip');
  if (chip) {
    const asset = (element.assets || []).find((a) => String(a.link) === chip.dataset.link);
    if (asset) openAsset(asset, element);
  }
}

function makeGhost(at, cls = 'arrow') {
  const svg = document.getElementById('arrows');
  const path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
  path.setAttribute('class', cls);
  path.setAttribute('d', `M ${at.x} ${at.y} L ${at.x} ${at.y}`);
  svg.append(path);
  return path;
}

/* ── Инструмент «ярлык» ───────────────────────────────────────── */

/**
 * Кнопка «ярлык» (#cl-link) в сетке инструментов («Классика», «Студия»).
 * Сетку строит left/tools.js из KINDS, ярлык идёт отдельно: его не ставят на
 * холст, а тянут от объекта к объекту. Строками кнопка — .tool[data-tool="link"],
 * её ведёт сам left/tools.js. Класс body.tool-link меняет курсор над карточками.
 */
function initLinkTool() {
  const button = document.getElementById('cl-link');
  if (button) {
    button.onclick = () => {
      if (state.viewOnly) return;
      state.tool = state.tool === 'link' ? null : 'link';
      emit('tool');
    };
  }
  on('tool', () => {
    const active = state.tool === 'link';
    button?.classList.toggle('on', active);
    document.body.classList.toggle('tool-link', active);
    if (active) toast(t('editor.pointer.link_tool'));
    else dropPendingLink();
  });
  // Сменили папку, пока ждали цель: подсветка владельца уходит вместе с узлом,
  // а сам выбор живёт дальше — цель как раз ищут в другой папке.
  on('folder', () => { if (pendingLink) toast(t('editor.pointer.link_from', { name: pendingLink.title || t('editor.links.no', { no: pendingLink.no }) })); });
}

/* «Щёлк — щёлк»: владелец ярлыка, выбранный первым щелчком. Снимок, а не id:
   за целью могут уйти в другую папку, где владельца в state.elements нет. */
let pendingLink = null;

function clickLink(id) {
  const element = state.elements.get(id);
  if (!element || !isLinkEnd(element.type)) return;

  if (!pendingLink) {
    pendingLink = { id: element.id, no: element.no, type: element.type,
                    title: element.title || '', folder: state.folder?.id };
    markPending(true);
    toast(t('editor.pointer.link_from_now', { name: element.title || t('editor.links.no', { no: element.no }) }));
    return;
  }
  if (pendingLink.id === id) { dropPendingLink(); toast(t('editor.mobile.link_cancelled')); return; }

  const owner = pendingLink;
  dropPendingLink();
  createLink(owner.id, id, { near: owner });
}

function markPending(on) {
  const node = pendingLink && nodeOf(pendingLink.id);
  if (!node) return;
  if (on) node.dataset.linkFrom = '1';
  else delete node.dataset.linkFrom;
}

function dropPendingLink() {
  if (!pendingLink) return;
  markPending(false);
  pendingLink = null;
}

/* ── Клавиши ──────────────────────────────────────────────────── */

/* Где сейчас мышь: вставка ложится под курсор, а не в угол схемы. */
let hover = null;

/** Последнее место курсора на холсте — или ничего, если он ушёл со холста. */
function lastPoint() { return hover; }

function onKey(event) {
  const typing = /input|textarea|select/i.test(event.target.tagName) || event.target.isContentEditable;
  if (typing) return;
  // Открыто окно — клавиши принадлежат ему. Esc закрывал окно и заодно снимал
  // выделение на холсте, так что вместе с окном схлопывалась и правая панель.
  if (document.querySelector('dialog[open]')) return;

  // Отмена и возврат. ⌘Z — назад, ⇧⌘Z и ⌘Y — вперёд.
  if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'z') {
    event.preventDefault();
    event.shiftKey ? redo() : undo();
    return;
  }
  if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'y') {
    event.preventDefault();
    redo();
    return;
  }

  // Копирование и вставка. Вставка ложится под курсор, если он на холсте.
  if ((event.metaKey || event.ctrlKey) && !state.viewOnly) {
    const key = event.key.toLowerCase();
    if (key === 'c') { event.preventDefault(); copySelection().then((n) => n && toast(t('editor.pointer.copied', { n }))); return; }
    if (key === 'v') { event.preventDefault(); const n = pasteClipboard(lastPoint()); if (n) toast(t('editor.pointer.pasted', { n })); return; }
    if (key === 'd') { event.preventDefault(); duplicateSelection().then((n) => n && toast(t('editor.pointer.duplicated', { n }))); return; }
  }

  // Собрать группу и разобрать её — те же сочетания, что были.
  if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'g') {
    event.preventDefault();
    event.shiftKey ? ungroupSelected() : groupSelected();
    return;
  }
  if (event.metaKey || event.ctrlKey) return;

  // Пробел: пока держат — холст ходит за мышью.
  if (event.code === 'Space') {
    if (!spaceHeld) { spaceHeld = true; document.body.classList.add('space-pan'); }
    event.preventDefault();
    return;
  }

  if (event.key === 'Delete' || event.key === 'Backspace') {
    if (state.viewOnly || !state.selection.size) return;
    event.preventDefault();
    scene.remove(state.selection);
    state.selection.clear();
    emit('selection');
    return;
  }
  if (event.key === 'Escape') { state.tool = null; state.selection.clear(); emit('tool'); emit('selection'); return; }
  // Цифры — инструменты на холсте и виды — один обработчик на весь редактор: shell/topbar.js.
}

function onKeyUp(event) {
  if (event.code === 'Space') releaseSpace();
}

function releaseSpace() {
  if (!spaceHeld) return;
  spaceHeld = false;
  document.body.classList.remove('space-pan');
}
