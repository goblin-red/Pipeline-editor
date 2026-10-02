/* Контейнеры: группа и область. Кто в ком лежит и что из этого следует.
   Отдаёт: isContainer(), canContain(), holds(), outsideOf(), membersOf(),
           membersDeep(), carrySet(), planDrop(), aroundContainer(), depthOf(),
           isCollapsed(), collapsedOwner(), isHiddenByCollapse(), anchorOf(),
           containerBounds(), COLLAPSED.
   Не делает: не двигает, не рисует и не пишет на сервер — только отвечает
   на вопросы о составе.

   Группа и область устроены одинаково: обе держат состав списком, обе везут
   его с собой, куда бы ни уехали, и в обе можно положить что угодно, включая
   другую рамку. Разница только в облике и в том, что схлопывается лишь группа.

   Родитель у объекта один — самая тесная рамка, накрывшая его целиком.
   Внешние рамки достаются по цепочке: блок лежит в области, область — в группе,
   значит блок в группе тоже, но опосредованно. Поэтому рамка уезжает вместе
   со своим содержимым и нигде не теряет его по дороге. */

import { state } from 'goblin/core/state.js';
import { box } from 'goblin/canvas/geometry.js';

/** Размер схлопнутой группы: та же табличка, что была в прежнем Гоблине. */
export const COLLAPSED = { width: 280, height: 112 };

export function isContainer(element) {
  return !!element && (element.type === 'group' || element.type === 'area');
}

/** Лежит ли элемент целиком снаружи контейнера — только тогда его выпускают. */
export function outsideOf(container, element) {
  const cb = box(container);
  const eb = box(element);
  return eb.x >= cb.x + cb.w || eb.y >= cb.y + cb.h
      || eb.x + eb.w <= cb.x || eb.y + eb.h <= cb.y;
}

/**
 * Лежит ли элемент в контейнере целиком — единственная мера вхождения.
 * Схлопнутая группа тоже принимает: это закрытая папка, а не запертая дверь.
 */
export function holds(container, element) {
  if (!isContainer(container) || container.id === element.id) return false;
  if (container.style?.locked) return false;
  if (!canContain(container.type, element.type)) return false;
  const cb = box(container);
  const eb = box(element);
  return eb.x >= cb.x && eb.y >= cb.y
    && eb.x + eb.w <= cb.x + cb.w && eb.y + eb.h <= cb.y + cb.h;
}

/** Что во что кладётся. Зеркало правила сервера в lib/elements/kinds.php. */
export function canContain(containerType, childType) {
  if (childType === 'arrow') return false;
  return containerType === 'group' || containerType === 'area';
}

/** Кто лежит прямо в этом контейнере — по явному составу. */
export function membersOf(id) {
  const out = [];
  for (const element of state.elements.values()) {
    if (element.type !== 'arrow' && (element.in || []).includes(id)) out.push(element);
  }
  return out;
}

/** Весь состав вглубь: группа в группе, область в группе и так далее. */
export function membersDeep(id, seen = new Set()) {
  const out = [];
  for (const element of membersOf(id)) {
    if (seen.has(element.id)) continue;
    seen.add(element.id);
    out.push(element);
    if (isContainer(element)) out.push(...membersDeep(element.id, seen));
  }
  return out;
}

/**
 * Что поедет, если потянуть за выделенное.
 * Контейнер везёт своё содержимое — иначе группа уезжала бы пустой рамкой.
 * Возвращает Map: id → true у того, кого тянут прямо, false у попутчика.
 */
export function carrySet(ids) {
  const out = new Map();
  const add = (id, direct) => {
    const element = state.elements.get(id);
    if (!element || element.type === 'arrow') return;
    if (out.has(id)) { if (direct) out.set(id, true); return; }
    out.set(id, direct);
    if (isContainer(element)) for (const member of membersOf(id)) add(member.id, false);
  };
  for (const id of ids) add(id, true);
  return out;
}

/**
 * Куда попадёт перетаскиваемое, если отпустить прямо сейчас.
 * Правила:
 *   родитель один — самая тесная рамка, накрывшая объект целиком;
 *   не накрыла ни одна, но прежнюю он задевает краем — остаётся у неё;
 *   целиком снаружи и ни в чём новом — выходит на холст;
 *   нельзя внутрь себя и внутрь собственного состава — вышло бы кольцо.
 *
 * Попутчиков не считаем: они едут внутри своей рамки и родителя не меняют —
 * рамка переехала вместе с ними, а их родителем осталась она же.
 *
 * `addOnly` — правка размера: рамку тянут, а не раскладывают вещи, поэтому
 * она может подобрать бесхозного, но никого не выбрасывает и не переманивает.
 *
 * Возвращает список правок: {id, into, added, removed} — по ним же рисуется
 * подсказка и по ним же правка уходит на сервер.
 */
export function planDrop(carry, { addOnly = false } = {}) {
  const all = [...state.elements.values()];
  const changes = [];
  const tightest = (list) => list.slice()
    .sort((a, b) => box(a).w * box(a).h - box(b).w * box(b).h)[0] || null;

  for (const [id, direct] of carry) {
    if (!direct) continue;
    const element = state.elements.get(id);
    if (!element || element.type === 'arrow') continue;

    const own = isContainer(element) ? new Set(membersDeep(element.id).map((m) => m.id)) : null;
    const allowed = (container) => !!container && (!own || !own.has(container.id));

    const covering = all.filter((c) => holds(c, element) && allowed(c));
    const kept = (element.in || []).map((cid) => state.elements.get(cid))
      .filter((c) => allowed(c) && !outsideOf(c, element));
    const parent = tightest(covering) || tightest(kept);

    const was = element.in || [];
    let result = parent ? [parent.id] : [];
    // Тянут рамку за уголок: она подбирает только ничейных и никого не теряет.
    if (addOnly && (!result.length || (was.length && !was.includes(result[0])))) result = was;

    const added = result.filter((cid) => !was.includes(cid));
    const removed = was.filter((cid) => !result.includes(cid));
    if (added.length || removed.length) changes.push({ id, into: result, added, removed });
  }
  return changes;
}

/**
 * Кого затронет изменение размера контейнера: тех, кто в нём лежал,
 * и тех, кого он теперь накрыл. Остальных не трогаем — иначе одна правка
 * рамки перетряхивала бы состав всей папки.
 */
export function aroundContainer(container) {
  const own = new Set(membersDeep(container.id).map((m) => m.id));
  const out = new Map();
  for (const element of state.elements.values()) {
    if (element.type === 'arrow' || element.id === container.id) continue;
    // Своё содержимое глубже первого уровня не трогаем: у него свой родитель.
    if (own.has(element.id) && !(element.in || []).includes(container.id)) continue;
    if ((element.in || []).includes(container.id) || holds(container, element)) out.set(element.id, true);
  }
  return out;
}

/**
 * Насколько глубоко элемент вложен: 0 — лежит на холсте, 1 — в рамке,
 * 2 — в рамке внутри рамки. По этому числу считается слой на холсте,
 * чтобы содержимое всегда лежало выше своей рамки и мышь доставалась ему.
 */
export function depthOf(id, seen = new Set()) {
  const element = state.elements.get(id);
  if (!element || !(element.in || []).length) return 0;
  let deepest = 0;
  for (const containerId of element.in) {
    if (seen.has(containerId)) continue;
    seen.add(containerId);
    deepest = Math.max(deepest, 1 + depthOf(containerId, seen));
  }
  return deepest;
}

/** Схлопнута ли группа. У области схлопывания нет — она полоса, а не папка. */
export function isCollapsed(element) {
  return element?.type === 'group' && !!element.style?.collapsed;
}

/**
 * Самая внешняя схлопнутая группа над элементом — она его и прячет.
 * Ищем наружу: вложенная схлопнутая группа внутри схлопнутой не считается.
 */
export function collapsedOwner(id, seen = new Set()) {
  const element = state.elements.get(id);
  if (!element) return null;
  let found = null;
  for (const containerId of element.in || []) {
    if (seen.has(containerId)) continue;
    seen.add(containerId);
    const container = state.elements.get(containerId);
    if (!container) continue;
    const outer = collapsedOwner(containerId, seen);
    if (outer) return outer;
    if (isCollapsed(container)) found = container;
  }
  return found;
}

export function isHiddenByCollapse(element) {
  return element.type !== 'arrow' && !!collapsedOwner(element.id);
}

/** За что цепляется стрелка: за сам элемент или за схлопнувшую его группу. */
export function anchorOf(id) {
  return collapsedOwner(id) || state.elements.get(id) || null;
}

/** Рамка вокруг набора элементов с полями. */
export function containerBounds(elements, pad = 28, top = 56) {
  const boxes = elements.filter((e) => e && e.type !== 'arrow').map(box);
  if (!boxes.length) return null;
  const x = Math.min(...boxes.map((b) => b.x)) - pad;
  const y = Math.min(...boxes.map((b) => b.y)) - top;
  return {
    x, y,
    width: Math.max(...boxes.map((b) => b.x + b.w)) - x + pad,
    height: Math.max(...boxes.map((b) => b.y + b.h)) - y + pad,
  };
}
