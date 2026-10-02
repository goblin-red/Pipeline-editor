/* Группы: собрать из выделенного, разобрать обратно, схлопнуть и раскрыть.
   Отдаёт: groupSelected(), ungroupSelected(), toggleCollapse(), canGroup().
   Не делает: не рисует — только правит модель через edit/scene.js.

   Как было в прежнем Гоблине: выделил несколько объектов → они уехали
   в рамку с заголовком; щелчок по галочке — рамка схлопнулась в табличку
   и спрятала содержимое; ещё раз — раскрылась. */

import { state, emit } from 'goblin/core/state.js';
import { box } from 'goblin/canvas/geometry.js';
import { COLLAPSED, canContain, containerBounds, isCollapsed, membersOf, membersDeep } from 'goblin/core/containers.js';
import * as scene from 'goblin/edit/scene.js';
import { group as oneStep } from 'goblin/edit/history.js';
import { t } from 'goblin/core/i18n.js';

export function canGroup() {
  return picked().length > 1;
}

function picked() {
  return [...state.selection].map((id) => state.elements.get(id))
    .filter((element) => element && element.type !== 'arrow');
}

/** Выделенное уезжает в новую группу. Её рамка охватывает всех с полями. */
export function groupSelected() {
  if (state.viewOnly) return null;
  const items = picked();
  if (items.length < 2) return null;

  // Вложенных участников брать не нужно: они уже едут со своим контейнером.
  const inner = new Set();
  for (const item of items) if (item.type === 'group' || item.type === 'area') {
    for (const member of membersDeep(item.id)) inner.add(member.id);
  }
  const roots = items.filter((item) => !inner.has(item.id));
  if (roots.length < 2) return null;

  const bounds = containerBounds(roots);
  let group = null;
  oneStep(t('editor.history.group'), () => {
    group = scene.createElement('group', { x: bounds.x + bounds.width / 2, y: bounds.y + bounds.height / 2 },
      { title: t('editor.tools.group_section') });
    scene.patch(group.id, { style: { ...bounds, z: 0, collapsed: false } });

    // Родитель у объекта один, поэтому новая группа встаёт на место прежнего.
    // Сама она занимает его место в иерархии: если всех собирали внутри одной
    // рамки, группа ложится в неё же — иначе содержимое выпрыгнуло бы наружу.
    const parents = new Set(roots.flatMap((item) => item.in || []));
    const common = parents.size === 1 && roots.every((item) => (item.in || []).length === 1)
      ? [...parents][0] : null;
    if (common) scene.setMembers(group.id, [common]);
    for (const item of roots) {
      if (!canContain('group', item.type)) continue;
      scene.setMembers(item.id, [group.id]);
    }
  });

  state.selection.clear();
  state.selection.add(group.id);
  emit('selection');
  return group;
}

/** Рамка исчезает, содержимое остаётся на месте и выделяется. */
export function ungroupSelected() {
  if (state.viewOnly) return;
  const groups = picked().filter((item) => item.type === 'group');
  if (!groups.length) return;

  const freed = [];
  oneStep(t('editor.history.ungroup'), () => {
    for (const group of groups) {
      if (isCollapsed(group)) expand(group);      // сначала показать, потом отпустить
      for (const member of membersOf(group.id)) {
        const rest = (member.in || []).filter((id) => id !== group.id);
        scene.setMembers(member.id, [...rest, ...(group.in || [])]);
        freed.push(member.id);
      }
    }
    scene.remove(groups.map((group) => group.id));
  });

  state.selection.clear();
  for (const id of freed) state.selection.add(id);
  emit('selection');
}

/** Галочка на рамке: схлопнуть в табличку или раскрыть обратно. */
export function toggleCollapse(group) {
  if (!group || group.type !== 'group' || state.viewOnly) return;
  isCollapsed(group) ? expand(group) : collapse(group);
}

function collapse(group) {
  const b = box(group);
  scene.patch(group.id, { style: {
    collapsed: true,
    expandedWidth: b.w, expandedHeight: b.h,
    width: group.style?.collapsedWidth || COLLAPSED.width,
    height: group.style?.collapsedHeight || COLLAPSED.height,
  } });
  emit('scheme');     // спрятанное содержимое перерисовывается целиком
}

function expand(group) {
  const b = box(group);
  const members = membersOf(group.id);
  const around = containerBounds([group, ...members], 24, 24);
  scene.patch(group.id, { style: {
    collapsed: false,
    collapsedWidth: b.w, collapsedHeight: b.h,
    width: Math.max(group.style?.expandedWidth || 0, around?.width || 0, COLLAPSED.width),
    height: Math.max(group.style?.expandedHeight || 0, around?.height || 0, COLLAPSED.height),
  } });
  emit('scheme');
}
