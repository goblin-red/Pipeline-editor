/* Куда ляжет перетаскиваемое: подсказка на холсте и запись состава.
   Отдаёт: showDrop(), clearDrop(), commitDrop(), affected().
   Не делает: не решает правил — их знает core/containers.js; здесь только
   краска на карточках и отправка того, что подсказка показала.

   Зелёным — что прибавится в рамку, красным — что из неё уйдёт. Подсказка
   и запись считаются одной и той же функцией, поэтому показанное и есть
   сделанное. */

import { state } from 'goblin/core/state.js';
import { nodeOf } from 'goblin/canvas/view.js';
import { planDrop, carrySet, aroundContainer, isContainer, isCollapsed } from 'goblin/core/containers.js';
import * as scene from 'goblin/edit/scene.js';
import { toast } from 'goblin/shell/topbar.js';
import { t } from 'goblin/core/i18n.js';

const painted = new Set();

export function showDrop(changes) {
  clearDrop();
  for (const change of changes) {
    const node = nodeOf(change.id);
    if (node) {
      node.classList.add(change.added.length ? 'drop-add' : 'drop-remove');
      painted.add(node);
    }
    for (const id of change.added) mark(id, 'drop-add');
    for (const id of change.removed) mark(id, 'drop-remove');
  }
}

export function clearDrop() {
  for (const node of painted) node.classList.remove('drop-add', 'drop-remove');
  painted.clear();
}

function mark(id, name) {
  const node = nodeOf(id);
  if (!node) return;
  node.classList.add(name);
  painted.add(node);
}

/** Чей состав пересчитывать: у рамки — её округу, у элемента — его самого. */
export function affected(element) {
  return isContainer(element) ? aroundContainer(element) : carrySet([element.id]);
}

/**
 * Брошенное на рамку прилипает к ней — членство явное и всегда одно.
 * Попутчиков не трогаем: они ехали внутри своей рамки, её же и держатся.
 */
export function commitDrop(carry, options) {
  for (const change of planDrop(carry, options)) {
    scene.setMembers(change.id, change.into);
    // Лёг в схлопнутую группу и пропал с глаз — скажем, куда именно.
    const folded = change.added.map((id) => state.elements.get(id)).find(isCollapsed);
    if (folded) {
      const element = state.elements.get(change.id);
      toast(t('editor.drop.into_folded', { who: element?.no ?? t('editor.drop.object'), group: folded.no }));
    }
  }
}
