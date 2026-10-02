/* Карточка элемента: одна разметка на все типы и все виды.
   Отдаёт: drawElement(), updateElement(), coverUrl().
   Не делает: не решает, что показывать — это canvas/looks.js, и не пишет на сервер.

   Разметка нарочно повторяет прежний Гоблин: node → node-card → node-inner,
   заголовок, картинка секцией, описание, значки, материалы, ответ. Так вид
   «Классика» получает тот же облик без второго редактора, а остальные виды —
   те же самые узлы, только иначе одетые. */

import { state, on, stepOf } from 'goblin/core/state.js';
import { kindOf, stepMark, STEP_WORDS } from 'goblin/core/kinds.js';
import { lookRule } from 'goblin/canvas/looks.js';
import { box } from 'goblin/canvas/geometry.js';
import { paintOf, inkFor } from 'goblin/canvas/palette.js';
import { isCollapsed, isHiddenByCollapse, membersOf, membersDeep, depthOf } from 'goblin/core/containers.js';
import { isEditing } from 'goblin/edit/inplace.js';
import { assetIcon, coverAsset, assetUrl } from 'goblin/shell/assetview.js';
import { cliColor } from 'goblin/shell/agentview.js';
import { drawTable } from 'goblin/canvas/table.js';
import { renderVolume } from 'goblin/canvas/volume.js';
import { blockLook, reviewOf, hasServerPaint } from 'goblin/run/paint.js';
import { t, tn } from 'goblin/core/i18n.js';

/** Предел названия стартера на холсте: круг маленький. */
export const STARTER_TITLE_MAX = 30;

export function drawElement(element) {
  const node = document.createElement('div');
  node.dataset.id = element.id;
  node.innerHTML = `
    <div class="node-card">
      <div class="node-inner">
        <div class="node-title"></div>
        <img class="node-image-section" alt="" draggable="false" hidden>
        <div class="node-desc"></div>
        <div class="node-grid-wrap" hidden></div>
        <div class="node-badges"></div>
        <div class="node-data"></div>
        <div class="node-result" hidden></div>
        <!-- Итог формальных проверок сданного шага: смотреть перед приёмкой. -->
        <div class="node-review" hidden></div>
        <!-- Агент — внизу карточки: сверху читают название, а не исполнителя. -->
        <div class="node-brick" hidden><svg class="brick-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="8" width="16" height="11" rx="2.5"/><path d="M12 4v4M9 13h.01M15 13h.01M9.5 16.5h5"/></svg><span class="node-brick-name"></span><button class="node-brick-x" title="Снять исполнителя с блока">✕</button></div>
      </div>
    </div>
    <span class="node-yes" hidden>${t('editor.canvas.yes_caps')}</span>
    <span class="node-no" hidden>${t('editor.canvas.no_caps')}</span>
    <button class="node-fold" hidden title="${t('editor.canvas.fold_group')}"></button>
    <span class="node-count" hidden></span>
    <span class="node-seq-badge" title="${t('editor.canvas.seq_badge')}"></span>
    <span class="node-marks">
    <span class="node-md-badge" hidden title="${t('editor.canvas.md_badge')}">MD</span>
    <span class="node-clip" hidden title="${t('editor.canvas.clip_badge')}"></span>
    <span class="node-agent" hidden><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="8" width="16" height="11" rx="2.5"/><path d="M12 4v4M9 13h.01M15 13h.01M9.5 16.5h5"/></svg></span>
    <span class="node-step" hidden></span>
    </span>
    <i class="port port-top" data-side="t"></i><i class="port port-right" data-side="r"></i>
    <i class="port port-bottom" data-side="b"></i><i class="port port-left" data-side="l"></i>
    <i class="node-grip" title="${t('editor.canvas.grip')}"></i>`;
  updateElement(node, element, stepOf(element));
  return node;
}

export function updateElement(node, element, step = stepOf(element)) {
  // Пока текст правят прямо в карточке, её не трогают: иначе буквы прыгают.
  if (isEditing(node)) return;

  // Прогон нового движка (engine = 2): состояние блока и круг цикла говорит
  // сервер (run/paint.js), а не последняя попытка из state.steps. Вспышка —
  // свежий переход из очереди показа или пауза показа после события на блоке.
  const painted = blockLook(element);
  if (painted) step = painted.none ? null : painted;
  node.toggleAttribute('data-paint-flash', !!painted?.flash || blinking.has(element.no));

  // Схлопнутая группа прячет содержимое — как папка в проводнике.
  node.hidden = isHiddenByCollapse(element);

  const b = box(element);
  const s = element.style || {};
  const look = lookRule();

  node.style.left = b.x + 'px';
  node.style.top = b.y + 'px';
  node.style.width = b.w + 'px';
  node.style.height = b.h + 'px';
  node.style.zIndex = String(layerOf(element));

  // Классы: тип, форма, скин, вид карточки, выделение, состояние шага.
  // `el` оставлен как общее имя для жестов и проверок.
  // Переходные классы (тянут, доводят, правят текст) ставит не рисовалка —
  // поэтому они переживают перерисовку.
  const passing = ['dragging', 'settling', 'editing', 'resizing', 'drop-add', 'drop-remove']
    .filter((name) => node.classList.contains(name));
  node.className = ['node', 'el', element.type,
    // Стартер прогона — круг: только номер и имя.
    element.type === 'block' && element.props?.start ? 'starter' : '',
    s.shape ? 'shape-' + s.shape : '',
    s.skin ? 'skin-' + s.skin : '',
    s.preset && s.preset !== 'full' ? 'view-' + s.preset : '',
    state.selection.has(element.id) ? 'selected sel' : '',
    isCollapsed(element) ? 'collapsed' : '',
    ...passing,
  ].filter(Boolean).join(' ');

  // Цвет — ключ палитры. Красит токены, а не свойства: скин и статус
  // остаются сильнее цвета, как и было.
  const paint = paintOf(s.color);
  if (paint) {
    node.style.setProperty('--card-edge', paint.edge);
    node.style.setProperty('--card-fill', paint.fill);
    // Текст читается на любой заливке: на пастели он тёмный, на тёмной — светлый.
    const ink = inkFor(s.color);
    if (ink) {
      node.style.setProperty('--card-ink', ink.ink);
      node.style.setProperty('--card-ink-dim', ink.dim);
    } else {
      node.style.removeProperty('--card-ink');
      node.style.removeProperty('--card-ink-dim');
    }
  } else {
    node.style.removeProperty('--card-edge');
    node.style.removeProperty('--card-fill');
    node.style.removeProperty('--card-ink');
    node.style.removeProperty('--card-ink-dim');
  }

  const seq = node.querySelector('.node-seq-badge');
  seq.textContent = element.no;
  seq.hidden = !look.show.seq;

  node.querySelector('.node-md-badge').hidden = !(look.show.md && element.hasSpec);

  // Галочка сворачивания и счётчик состава — только у группы.
  const fold = node.querySelector('.node-fold');
  const count = node.querySelector('.node-count');
  if (element.type === 'group') {
    const inside = membersOf(element.id).length;
    fold.hidden = false;
    fold.textContent = isCollapsed(element) ? '▸' : '▾';
    count.hidden = false;
    count.textContent = tn('editor.canvas.objects', inside);
  } else {
    fold.hidden = true;
    count.hidden = true;
  }

  const title = node.querySelector('.node-title');
  title.textContent = element.title || kindOf(element).title;
  // Стартер — маленький круг: название не длиннее STARTER_TITLE_MAX, остальное — многоточием.
  if (element.type === 'block' && element.props?.start && title.textContent.length > STARTER_TITLE_MAX) {
    title.textContent = title.textContent.slice(0, STARTER_TITLE_MAX - 1) + '…';
    title.title = element.title;
  }
  // Стрелка живёт на самой плашке заголовка и следует за ней в любом скине.
  if (element.type === 'group') title.append(fold);
  else if (fold.parentElement !== node) node.append(fold);
  // Подпись области стоит слева или справа — как выбрано в её виде.
  if (element.type === 'area') {
    node.dataset.align = s.align === 'right' ? 'right' : 'left';
    node.dataset.valign = ['top', 'bottom'].includes(s.valign) ? s.valign : 'middle';   // по высоте: сверху, посередине, снизу
  }
  const desc = node.querySelector('.node-desc');
  desc.textContent = element.description || '';
  desc.hidden = !element.description || !look.show.desc;

  // Картинка отдельной секцией между заголовком и описанием — как раньше.
  const image = node.querySelector('.node-image-section');
  const url = look.show.cover ? coverUrl(element, painted) : null;
  if (url) {
    if (image.dataset.url !== url) { image.dataset.url = url; image.src = url; }
    image.hidden = false;
    node.classList.add('has-image');
  } else {
    image.hidden = true;
    node.classList.remove('has-image');
  }

  // Таблица рисуется рядами внутри карточки: шапка подсвечена, ряды чередуются.
  const grid = node.querySelector('.node-grid-wrap');
  if (element.type === 'table') {
    grid.hidden = false;
    drawTable(grid, element);
  } else {
    grid.hidden = true;
    grid.textContent = '';
  }

  // Ромб — развилка: у его боков написано, куда ведёт «да», а куда «нет».
  const yes = node.querySelector('.node-yes');
  const no = node.querySelector('.node-no');
  yes.hidden = no.hidden = element.type !== 'decision';

  // Назначенный агент — плашка на карточке, как прежний «кирпич».
  const brick = node.querySelector('.node-brick');
  const agent = state.agents.find((a) => a.id === element.agent);
  brick.hidden = !(look.show.agent && agent);
  /* Есть заглавная картинка — плашка исполнителя закрыла бы её, поэтому агент
     уходит значком в правый столбик, под MD и скрепку. Цвет — цвет его CLI. */
  const agentMark = node.querySelector('.node-agent');
  const onImage = !!(agent && url && state.look !== 'studio');
  agentMark.hidden = !onImage;
  brick.hidden = brick.hidden || onImage;
  /* Кто сейчас работает, видно на самой карточке: плашка исполнителя горит,
     пока его шаг в работе, и гаснет, когда шаг закрыт. */
  const busy = step && ['issued', 'running'].includes(step.state);
  brick.classList.toggle('busy', !!busy);
  node.classList.toggle('busy', !!busy);
  if (agent) {
    brick.querySelector('.node-brick-name').textContent = agent.name;
    brick.style.setProperty('--cli', cliColor(agent));
    agentMark.style.setProperty('--cli', cliColor(agent));
    agentMark.title = t('editor.canvas.executor', { name: agent.name });
    brick.title = busy
      ? t('editor.canvas.executor_busy', { name: agent.name })
      : t('editor.canvas.executor', { name: agent.name });
  }

  // Значки: материалы, свойства, id — по выбранному виду.
  const badges = [];
  // Шлюз — дверь в другую папку: её имя видно прямо на карточке.
  if (element.type === 'gateway') {
    const target = state.folders.find((f) => f.id === element.target);
    badges.push('⇢ ' + (target ? target.name || t('editor.canvas.folder_n', { id: target.id }) : t('editor.canvas.gateway_none')));
  }
  if (look.show.id) badges.push(`id ${element.id}`);
  if (look.show.props) {
    for (const [name, value] of Object.entries(element.props || {})) {
      badges.push(`${name}: ${String(value).slice(0, 18)}`);
    }
  }
  node.querySelector('.node-badges').innerHTML = badges.map((text) => `<span>${escape(text)}</span>`).join('');

  /* Материалы на карточке — одна скрепка с числом, значком в правом столбике
     под MD. Имена файлов её не красили, а места занимали много.
     Щёлкнул по скрепке — справа открылись «Материалы». Картинки других прогонов
     в счёт не идут: к картине этого прогона они не липнут (как и обложка). */
  node.querySelector('.node-data').textContent = '';
  const clip = node.querySelector('.node-clip');
  const files = (element.assets || []).filter((a) => a.role !== 'spec' && (!a.fromRun || a.fromRun === state.run?.id));
  clip.hidden = !(look.show.badges && files.length);
  clip.textContent = '📎 ' + files.length;

  /* Ответ исполнителя виден прямо на карточке, но коротко: длинные пути
     занимали пол-блока. Целиком он лежит в панели и в подсказке.
     В видах «Дизайнер» и «Показ» его не показываем вовсе: там карточка —
     это картинка с названием, и строка ответа её перекрывала. */
  const result = node.querySelector('.node-result');
  const said = step?.result || '';
  result.textContent = said.length > 64 ? said.slice(0, 63).trimEnd() + '…' : said;
  result.title = said;
  result.hidden = !said || look.show.result === false;

  /* Итог формальных проверок сданного шага (run.state → run/paint.js):
     «образец — да · арифметика — да». Человеку это нужно ровно перед приёмкой —
     и только пока карточка сама показывает «сдан». */
  const review = node.querySelector('.node-review');
  const checked = step?.state === 'submitted' ? reviewOf(element) : '';
  review.textContent = checked;
  review.hidden = !checked || look.show.result === false;

  paintStep(node, element, step);

  const showPorts = ['block', 'decision', 'gateway'].includes(element.type) && !state.viewOnly;
  for (const port of node.querySelectorAll('.port')) port.style.display = showPorts ? '' : 'none';

  // Уголок размера: у всего, кроме стрелки, и только когда схему можно править.
  node.querySelector('.node-grip').hidden = state.viewOnly || !!s.locked;
  renderVolume(node, element, b);
}

/**
 * Адрес обложки. Картинку прогона на холсте называет сам сервер (run.paint → cover) —
 * так у старого прогона она видна и после сброса схемы; иначе решает shell/assetview.js.
 */
export function coverUrl(element, painted = null) {
  const own = painted?.cover;
  if (own) return assetUrl(typeof own === 'object' ? own : { asset: own });
  return assetUrl(coverAsset(element));
}

/* Слои холста. Главное — вложенность: что лежит внутри, то и выше, иначе
   щелчок по вложенному доставался бы рамке и вместо него уезжала бы рамка.
   Именно из-за обратного порядка область внутри группы нельзя было двигать:
   карточка группы накрывала её собой.
   Внутри одной глубины порядок обычный: рамка — подложка, пометка над ней,
   блок сверху. Схлопнутая группа поднимается: она заменяет собой содержимое.
   Своё `z` из оформления работает внутри слоя, а не поперёк него. */
const KIND_LAYER = { note: 1 };

/* Два диапазона, между ними — слой стрелок (z-index 15 в css/canvas.css).
   Рамки лежат ниже стрелок: 1…10, чем глубже вложена, тем выше.
   Всё остальное — выше стрелок: 20 и дальше, теми же ступенями по глубине.
   Схлопнутая группа идёт в верхний диапазон: она уже не подложка, а карточка,
   и стрелки приходят к её краю. */
function layerOf(element) {
  const deep = Math.min(9, depthOf(element.id));
  const folded = isCollapsed(element) ? 1 : 0;
  const frame = (element.type === 'area' || element.type === 'group') && !folded;
  if (frame) return 1 + deep;
  const kind = KIND_LAYER[element.type] ?? 2;
  const own = Math.max(-1, Math.min(1, Number(element.style?.z) || 0));
  return 20 + deep * 10 + kind * 3 + folded + own;
}


const escape = (text) => String(text).replace(/[&<>]/g, (ch) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;' }[ch]));


/* ── Состояние шага на карточке ───────────────────────────────── */

/* Узлы прогона: у них бывает шаг. Рамки, пометки и таблицы — оформление схемы. */
const RUNNABLE = new Set(['block', 'decision', 'gateway']);

/**
 * Состояние шага на карточке: data-step — для общей таблицы вида (css/run.css),
 * data-branch — ветка решённого ромба, значок — второй признак рядом с цветом.
 * Словами — в подсказке значка и в панели. Выбран прогон, а шага нет — «не пройден».
 * Схлопнутая группа стоит вместо содержимого: на ней — самое срочное состояние внутри.
 */
function paintStep(node, element, step) {
  const mark = node.querySelector('.node-step');
  const folded = isCollapsed(element);
  if (folded) step = state.run ? insideLook(element) : null;
  if ((!RUNNABLE.has(element.type) && !folded) || (!step && !state.run)) {
    delete node.dataset.step;
    delete node.dataset.branch;
    mark.hidden = true;
    return;
  }
  const now = step?.state || 'none';
  node.dataset.step = now;
  const branch = element.type === 'decision' && now === 'accepted' ? branchOf(step) : '';
  if (branch) node.dataset.branch = branch; else delete node.dataset.branch;
  const wait = now === 'ready' ? step.wait || '' : '';
  if (wait) node.dataset.wait = wait; else delete node.dataset.wait;

  mark.hidden = now === 'none';
  if (mark.hidden) return;
  // Круг цикла (новый движок) — «↻ 3», номер попытки (прежний) — «3». У группы — только значок.
  const lap = folded ? 1 : Number(step.attempt) || 1;
  mark.textContent = stepMark(element, now, wait) + (lap > 1 ? (step.round ? ' ↻' : ' ') + lap : '');
  mark.classList.toggle('many', lap > 1);
  mark.title = [stateWord(element, now, branch, wait),
                lap > 1 ? t(step.round ? 'editor.canvas.round_n' : 'editor.canvas.attempt_n', { n: lap }) : '',
                step.tries > 1 ? t('editor.canvas.try_n', { address: `${element.no}.${step.tries}` }) : '',
                step.result || step.error || ''].filter(Boolean).join(' · ');
}

/* Срочность для схлопнутой группы (договор подсветки): что просит внимания — выше. */
const URGENT = ['failed', 'returned', 'submitted', 'running', 'issued', 'ready', 'cancelled', 'accepted'];

/** Самое срочное состояние среди спрятанных в группе узлов прогона. */
function insideLook(group) {
  let best = null;
  for (const member of membersDeep(group.id)) {
    if (!RUNNABLE.has(member.type)) continue;
    const painted = blockLook(member);
    const step = painted ? (painted.none ? null : painted) : stepOf(member);
    const rank = step ? URGENT.indexOf(step.state) : -1;
    if (rank >= 0 && (!best || rank < URGENT.indexOf(best.state))) best = step;
  }
  return best && { state: best.state };
}

/** Ветка решённого ромба: сервер называет её сам, у прежних прогонов — по выбранной стрелке. */
function branchOf(step) {
  const branch = step.branch || state.elements.get(step.chosen)?.branch || '';
  return ['yes', 'no'].includes(branch) ? branch : '';
}

/** Состояние словами: «ждёт» у ромба, шлюза и блока и в паузе показа значит разное. */
function stateWord(element, now, branch, wait = '') {
  if (now === 'ready') {
    if (wait === 'pause') return t('editor.canvas.wait_pause');
    if (wait === 'person') return t('editor.canvas.wait_person');
    if (element.type === 'decision') return t('editor.canvas.wait_decision');
    return element.type === 'gateway' ? t('editor.canvas.wait_gateway') : t('editor.canvas.wait_block');
  }
  if (branch) return t('editor.canvas.decided', { branch: t(branch === 'yes' ? 'editor.canvas.yes_caps' : 'editor.canvas.no_caps') });
  return STEP_WORDS[now] || now;
}


/* ── Мигание ромба у прежних прогонов ─────────────────────────── */

/* Прогоны engine = 1: сервер подсветку не шлёт, и ромб, решённый вот только
   что (событие 'decided' из опроса api/sync.js), три секунды мигает сам — у
   ромба нет фазы работы, иначе не видно, что прогон был здесь. Ветку карточка
   берёт из выбранной стрелки. Новый движок мигает очередью показа (run/paint.js). */
const blinking = new Set();

on('decided', ({ no }) => {
  if (hasServerPaint()) return;
  blinking.add(no);
  redrawOne(no);
  setTimeout(() => { blinking.delete(no); redrawOne(no); }, 3000);
});

function redrawOne(no) {
  const element = [...state.elements.values()].find((one) => one.no === no);
  if (!element) return;
  const node = document.querySelector(`.node[data-id="${element.id}"]`);
  if (node) updateElement(node, element);
}
