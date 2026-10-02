/* Копирование и вставка: ⌘C, ⌘V, ⌘D.
   Отдаёт: copySelection(), pasteClipboard(), duplicateSelection(), clipboardSize().
   Не делает: не хранит ничего на сервере — буфер лежит в этом браузере,
   поэтому копировать можно между папками и проектами.

   Копируется то же, что и в прежнем Гоблине: сами элементы, стрелки, у которых
   оба конца попали в выделение, состав контейнеров внутри выделения и текст ТЗ.
   Номера не переносятся: у вставленного свои — это другие объекты. */

import { state, emit } from 'goblin/core/state.js';
import { box } from 'goblin/canvas/geometry.js';
import { membersDeep, isContainer } from 'goblin/core/containers.js';
import { gridStep } from 'goblin/core/settings.js';
import * as api from 'goblin/api/client.js';
import * as scene from 'goblin/edit/scene.js';
import { group as oneStep } from 'goblin/edit/history.js';
import { toast } from 'goblin/shell/topbar.js';
import { t } from 'goblin/core/i18n.js';

const STORE = 'goblin-clipboard';

export function clipboardSize() {
  return read()?.elements?.length || 0;
}

/** Снять копию выделенного вместе с содержимым контейнеров. */
export async function copySelection() {
  const picked = new Map();
  const add = (element) => {
    if (!element || picked.has(element.id)) return;
    picked.set(element.id, element);
    if (isContainer(element)) for (const inside of membersDeep(element.id)) picked.set(inside.id, inside);
  };
  for (const id of state.selection) add(state.elements.get(id));
  if (!picked.size) return 0;

  const refOf = new Map([...picked.keys()].map((id, n) => [id, 'c' + n]));
  const elements = [];

  for (const element of picked.values()) {
    if (element.type === 'arrow') continue;
    elements.push({
      ref: refOf.get(element.id),
      type: element.type,
      title: element.title || '',
      description: element.description || '',
      style: { ...(element.style || {}) },
      props: { ...(element.props || {}) },
      in: (element.in || []).map((cid) => refOf.get(cid)).filter(Boolean),
      spec: element.hasSpec ? await specTextOf(element.id) : '',
    });
  }
  // Стрелка копируется, только если оба её конца тоже скопированы.
  for (const element of state.elements.values()) {
    if (element.type !== 'arrow') continue;
    const from = refOf.get(element.from);
    const to = refOf.get(element.to);
    if (!from || !to) continue;
    elements.push({
      ref: refOf.get(element.id) || 'a' + element.id,
      type: 'arrow', title: element.title || '',
      from, to,
      ...(element.branch && element.branch !== 'flow' ? { branch: element.branch } : {}),
      ...(element.back ? { back: true } : {}),
      style: { ...(element.style || {}) },
    });
  }

  write({ elements, origin: cornerOf(elements) || { x: 0, y: 0 } });
  emit('clipboard');
  return elements.length;
}

/** Вставить копию. Точка не указана — кладём рядом с исходным местом. */
export function pasteClipboard(at = null) {
  const clip = read();
  if (!clip?.elements?.length || state.viewOnly || !state.folder) return 0;

  const step = gridStep();
  const shift = at
    ? { x: at.x - clip.origin.x, y: at.y - clip.origin.y }
    : { x: step * 2, y: step * 2 };

  const made = new Map();
  // Стартер в папке один: есть свой — копия стартера вставляется обычным блоком.
  let starterFree = ![...state.elements.values()].some((one) => one.type === 'block' && one.props?.start);
  oneStep(t('editor.history.paste'), () => {
    // Сначала всё, кроме стрелок: у них должны быть готовы концы.
    for (const item of clip.elements) {
      if (item.type === 'arrow') continue;
      const style = { ...item.style, x: (item.style.x || 0) + shift.x, y: (item.style.y || 0) + shift.y };
      const element = scene.createElement(item.type, centerOf(style), {
        title: item.title, description: item.description,
      });
      scene.patch(element.id, { style });
      const props = { ...(item.props || {}) };
      if (props.start && starterFree) starterFree = false;
      else delete props.start;
      if (Object.keys(props).length) scene.setProps(element.id, props);
      made.set(item.ref, element);
    }
    for (const item of clip.elements) {
      if (item.type !== 'arrow') continue;
      const from = made.get(item.from);
      const to = made.get(item.to);
      if (!from || !to) continue;
      const arrow = scene.connect(from.id, to.id, item.branch || null);
      if (arrow && (item.title || item.back)) {
        scene.patch(arrow.id, { ...(item.title ? { title: item.title } : {}), ...(item.back ? { back: true } : {}) });
      }
    }
    // Состав — последним: контейнеры уже на месте.
    for (const item of clip.elements) {
      if (!item.in?.length) continue;
      const element = made.get(item.ref);
      if (!element) continue;
      const inside = item.in.map((ref) => made.get(ref)?.id).filter((id) => id !== undefined);
      if (inside.length) scene.setMembers(element.id, inside);
    }
  });

  state.selection.clear();
  for (const element of made.values()) state.selection.add(element.id);
  emit('selection');

  // ТЗ прикладываем после того, как сервер выдал настоящие id.
  attachSpecs(clip, made);
  return made.size;
}

/** Скопировать и сразу вставить — ⌘D. */
export async function duplicateSelection() {
  const count = await copySelection();
  if (!count) return 0;
  return pasteClipboard();
}

/* ── Мелочи ───────────────────────────────────────────────────── */

/** Текст ТЗ читаем в момент копирования: у копии должно быть своё задание. */
async function specTextOf(elementId) {
  try {
    const answer = await api.get('asset.get', { element: elementId, role: 'spec', text: 1 });
    const spec = answer.assets?.[0];
    return typeof spec?.text === 'string' ? spec.text : '';
  } catch { return ''; }
}

/** Своё ТЗ у каждой копии: общий ассет правился бы сразу у обоих. */
async function attachSpecs(clip, made) {
  const withSpec = clip.elements.filter((item) => item.spec && made.has(item.ref));
  if (!withSpec.length) return;
  await scene.flush();

  for (const item of withSpec) {
    const element = made.get(item.ref);
    if (!element || element.id < 0) continue;
    try {
      await api.post('asset.create', {
        kind: 'spec', title: t('editor.clipboard.spec_title', { name: item.title || element.no }),
        text: item.spec, link: { element: element.id, role: 'spec' },
      });
      element.hasSpec = true;
      emit('element', element);
    } catch {}
  }
  if (withSpec.length) toast(t('editor.clipboard.spec_moved', { n: withSpec.length }));
}

/** Левый верхний угол всего скопированного: от него считается сдвиг. */
function cornerOf(elements) {
  const boxes = elements.filter((item) => item.type !== 'arrow').map((item) => box({ style: item.style }));
  if (!boxes.length) return null;
  return { x: Math.min(...boxes.map((b) => b.x)), y: Math.min(...boxes.map((b) => b.y)) };
}

/** createElement ждёт середину, а в буфере лежит угол. */
function centerOf(style) {
  return { x: (style.x || 0) + (style.width || 280) / 2, y: (style.y || 0) + (style.height || 180) / 2 };
}

function read() {
  try { return JSON.parse(localStorage.getItem(STORE) || 'null'); } catch { return null; }
}

function write(clip) {
  try { localStorage.setItem(STORE, JSON.stringify(clip)); } catch {}
}
