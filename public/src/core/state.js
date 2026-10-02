/* Правда клиента: что открыто, что выбрано, что нарисовано.
   Отдаёт: state, on(), emit(), setScheme(), putElement(), dropElement(), byNo(), arrowsOf().
   Не делает: ни DOM, ни сети — только данные и оповещение.

   Правило: массивы и объекты внутри state не заменяются целиком, а правятся
   на месте — иначе чужие ссылки на них устаревают.

   Ярлыки (type 'link') лежат отдельно — в state.links, и оповещают событием
   'links'. Холст, раскладка, группы и копирование их не видят: у ярлыка нет
   своего места на холсте, он висит на владельце (canvas/link.js). */

export const state = {
  project: null,      // {id, key, title, rev, strictChecks…}
  projects: [],       // мои проекты: свои и те, куда заходил; первый — правленный последним
  pickedProject: null, // ключ проекта, чьи свойства показывает панель (щелчок по плитке)
  runFolders: new Set(),  // папки проекта, где идёт прогон (pulse → runFolders)
  runProjects: new Set(), // ключи проектов, где идёт прогон (pulse → runProjects)
  welcome: false,     // панель показывает приветствие («…» над списком проектов)
  folders: [],        // все папки проекта
  folder: null,       // открытая папка
  elements: new Map(),// id → элемент (блок, ромб, стрелка, группа, область, пометка)
  links: new Map(),   // id → ярлык: свои ярлыки папки и входящие из других папок
  agents: [],
  selection: new Set(),
  run: null,          // выбранный прогон
  steps: new Map(),   // номер элемента → последний шаг
  rev: 0,
  tool: null,         // выбранный инструмент рисования
  color: '',          // выбранный цвет
  dirty: false,
  viewOnly: document.body.classList.contains('view-only'),
  variant: 'canvas',   // холст, список или прогон — способ работы
  look: 'work',        // рабочий, классика, дизайнер, разработчик — вид карточек
  iso: false,         // ортографический объёмный вид
};

const listeners = new Map();

export function on(event, fn) {
  if (!listeners.has(event)) listeners.set(event, new Set());
  listeners.get(event).add(fn);
  return () => listeners.get(event).delete(fn);
}

export function emit(event, data) {
  for (const fn of listeners.get(event) || []) fn(data);
}

/** Схема папки пришла целиком. */
export function setScheme(elements) {
  state.elements.clear();
  state.links.clear();
  for (const element of elements) {
    (element.type === 'link' ? state.links : state.elements).set(element.id, element);
  }
  emit('scheme');
  emit('links');
}

export function putElement(element) {
  // Ярлык — в свою полку: карточкой на холсте он не рисуется.
  if (element.type === 'link' || state.links.has(element.id)) {
    const old = state.links.get(element.id);
    state.links.set(element.id, old ? Object.assign(old, element) : element);
    emit('links');
    return;
  }
  const old = state.elements.get(element.id);
  state.elements.set(element.id, old ? Object.assign(old, element) : element);
  emit('element', state.elements.get(element.id));
}

export function dropElement(id) {
  if (state.links.delete(id)) { emit('links'); return; }
  const element = state.elements.get(id);
  if (!element) return;
  state.elements.delete(id);
  state.selection.delete(id);
  emit('drop', element);
}

export function byNo(no) {
  for (const element of state.elements.values()) if (element.no === Number(no)) return element;
  return null;
}

/** Стрелки, у которых этот элемент — конец или начало. */
export function arrowsOf(id) {
  const out = [];
  for (const element of state.elements.values()) {
    if (element.type === 'arrow' && (element.from === id || element.to === id)) out.push(element);
  }
  return out;
}

export function elementsOf(type) {
  return [...state.elements.values()].filter((element) => element.type === type);
}

/** Шаг прогона для элемента: по нему считается вся подсветка. */
export function stepOf(element) {
  return state.steps.get(element.no) || null;
}

export function setDirty(value) {
  if (state.dirty === value) return;
  state.dirty = value;
  emit('dirty', value);
}
