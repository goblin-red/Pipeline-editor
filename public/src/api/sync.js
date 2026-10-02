/* Загрузка папки и слежение за изменениями.
   Отдаёт: loadProject(), loadMine(), loadFolder(), loadRuns(), loadRun(), startPolling().
   Не делает: не рисует — только кладёт данные в состояние.

   Опрос идёт раз в секунду, пока в очереди правок пусто: свои же правки
   возвращать себе обратно незачем. Запрос один за раз, ответ применяется
   монотонно: ревизия назад не ходит, ответ, пока ждали которого сменили
   проект или папку, отбрасывается.

   Гость (без учётки): его проекты помнит этот браузер — ключи в localStorage. «Мои проекты»
   спрашиваются с этими ключами; вошёл — сервер забирает их к нему (lib/projects/guests.php). */

import * as api from 'goblin/api/client.js';
import { state, setScheme, putElement, dropElement, emit } from 'goblin/core/state.js';
import { queueSize } from 'goblin/edit/scene.js';
import { clearHistory } from 'goblin/edit/history.js';
import { refreshRun } from 'goblin/run/paint.js';

let runsTurn = 0;
let runTurn = 0;

/* ── Проекты гостя в этом браузере ── */

const GUEST_STORE = 'goblin-guest-projects';

function guestKeys() {
  try { return JSON.parse(localStorage.getItem(GUEST_STORE) || '[]').filter((key) => typeof key === 'string'); } catch { return []; }
}

function guestSave(keys) {
  try {
    if (keys.length) localStorage.setItem(GUEST_STORE, JSON.stringify(keys.slice(0, 50)));
    else localStorage.removeItem(GUEST_STORE);
  } catch { /* браузер не даёт хранить — гость просто не вернётся к проекту без ссылки */ }
}
let folderTurn = 0;

export async function loadProject() {
  // Пустой адрес: открываем тот проект, который правили последним. Новый ключ
  // придумывается только тогда, когда открывать нечего.
  if (!api.hasProjectKey()) {
    const mine = await loadMine();
    if (mine[0]) api.setHash({ p: mine[0].key });
  }

  let answer;
  try {
    answer = await api.get('project.get', {}, ['not_found']);
  } catch (error) {
    if (error.code !== 'not_found') throw error;
    // Новый ключ: проект заведётся первой правкой. Пустой холст без всплывашки; кто вошёл и режим — из списка.
    await loadMine(true);
    return null;
  }
  state.project = answer.project;
  state.me = answer.me || null;       // кто вошёл: кнопка входа в шапке
  // Гость — запомнить проект первым в списке; вошёл — отдать запомненные ему (сервер заберёт их к нему).
  if (!state.me) guestSave([answer.project.key, ...guestKeys().filter((key) => key !== answer.project.key)]);
  else if (guestKeys().length) loadMine(true).then(() => guestSave([]));
  state.folders.length = 0;
  state.folders.push(...answer.folders);
  state.agents = answer.agents || [];
  state.rev = answer.project.rev;
  emit('project');
  return answer;
}

/** Мои проекты: свои и посещённые. Спрашиваем один раз на страницу. */
export async function loadMine(force = false) {
  if (state.projects.length && !force) return state.projects;
  const answer = await api.get('project.get', { project: '', keys: guestKeys().join(',') }).catch(() => null);
  state.projects.length = 0;
  state.projects.push(...(answer?.projects || []));
  // Кто вошёл и режим — шапке, пока проекта нет (новый посетитель).
  if (answer) { state.me = answer.me || null; state.remote = !!answer.agentsRemote; }
  emit('projects');
  return state.projects;
}

export async function loadFolder(id) {
  const folderId = id || api.folderId() || state.folders[0]?.id;
  if (!folderId) return null;

  const turn = ++folderTurn;
  const projectKey = api.projectKey();
  const answer = await api.get('folder.get', { folder: folderId });
  if (turn !== folderTurn || api.projectKey() !== projectKey) return null;
  // Открыли другую папку — отменять там нечего: прошлое осталось в прежней.
  if (state.folder && state.folder.id !== answer.folder.id) {
    clearHistory();
    clearRunContext(true);
    // Выделенное осталось в прежней папке — здесь его нет.
    if (state.selection.size) { state.selection.clear(); emit('selection'); }
  }
  state.folder = answer.folder;
  api.setFolder(answer.folder.id);
  setScheme(answer.scheme);
  emit('folder');
  await loadRuns();
  return answer;
}

/** Прогоны папки: последний показывается на холсте. */
export async function loadRuns() {
  if (!state.folder) return;
  const turn = ++runsTurn;
  const folderId = state.folder.id;
  const projectKey = api.projectKey();
  const answer = await api.get('run.get', { folder: folderId });
  if (turn !== runsTurn || state.folder?.id !== folderId || api.projectKey() !== projectKey) return;
  state.runs = answer.runs || [];
  const prepared = Number(state.folder?.style?.preparedRun) || 0;
  /* Берём последний прогон, в котором что-то происходило: свежий пустой или
     сорванный на первом шаге иначе затирал бы всю подсветку папки. */
  const alive = state.runs.filter((run) => run.id > prepared);
  // Закреплённый в адресе — выбор человека (архив, остановка, ручной статус): он важнее живого.
  const pinned = Number(api.hash().run);
  const wanted = (state.runs.some((run) => run.id === pinned) ? pinned : 0)
    || alive.find((run) => run.state === 'running' || run.state === 'paused')?.id
    || alive.find((run) => (run.done ?? 0) > 0)?.id
    || alive.find((run) => (run.steps ?? 1) > 0)?.id
    || alive[0]?.id
    || null;
  await loadRun(wanted);
}

export async function loadRun(id) {
  const turn = ++runTurn;
  const folderId = state.folder?.id ?? null;
  const projectKey = api.projectKey();
  if (!id) { clearRunContext(); return; }

  const answer = await api.get('run.get', { run: id });
  if (turn !== runTurn || state.folder?.id !== folderId || api.projectKey() !== projectKey) return;
  if (Number(answer.run?.id) !== Number(id)) return;
  // Прогон чужой папки (устаревший выбор) не показываем: подсветка и лента легли бы не на ту схему.
  if (Number(answer.run.folder) !== Number(folderId)) return;
  if (state.run?.id === Number(id) && Number(state.run.version) > Number(answer.run?.version)) return;

  state.steps.clear();
  state.run = answer.run;
  state.runState = null;
  state.ready = answer.ready || [];
  for (const step of answer.steps) {
    const known = state.steps.get(step.no);
    if (!known || known.attempt <= step.attempt) state.steps.set(step.no, step);
  }
  emit('steps');
  emit('run');
}

function clearRunContext(clearRuns = false) {
  runsTurn++;
  runTurn++;
  state.steps.clear();
  state.ready = [];
  if (clearRuns) state.runs = [];
  state.run = null;
  state.runState = null;
  state.paint = null;
  emit('steps');
  emit('paint');
  emit('run');
}

/** Раз в секунду спрашиваем, что изменилось после нашей ревизии. */
export function startPolling() {
  let asking = false;
  setInterval(async () => {
    if (asking || document.hidden || queueSize() > 0 || !state.project) return;
    const key = state.project.key;
    const folderId = state.folder?.id ?? null;
    let answer;
    asking = true;
    try {
      answer = await api.get('changes', { since: state.rev });
    } catch { return; } finally { asking = false; }
    // Пока ждали ответ, открыли другой проект или папку — эта дельта уже чужая:
    // следующий опрос спросит заново с той же ревизии.
    if (state.project?.key !== key || (state.folder?.id ?? null) !== folderId) return;
    // Пока ждали ответ, человек успел что-то поправить: старая дельта затёрла бы
    // свежую правку (свёрнутая группа тут же разворачивалась). Ревизию не двигаем —
    // эти же перемены возьмёт следующий опрос, когда правки дойдут до сервера.
    if (queueSize() > 0) return;
    // Ревизия назад не ходит: поздний ответ старее того, что уже применено.
    if (Number(answer.rev) < Number(state.rev)) return;

    state.rev = answer.rev;
    const changed = answer.changed || {};
    let rereadRuns = !!changed.runs;

    for (const folder of changed.folders || []) {
      const known = state.folders.findIndex((f) => f.id === folder.id);
      const open = state.folder && folder.id === state.folder.id;
      /* Схему сбросили для нового прогона (run.prepare — кнопка «Сбросить схему»,
         begin leader, другая вкладка): у папки новая метка preparedRun. Прогоны
         до неё больше не показываем: старая подсветка гаснет сразу, в этот же
         опрос, а прогоны перечитываются — новый, если он есть, встаёт следом.
         Сами прогоны при этом не менялись, поэтому changed.runs пуст и одного его мало. */
      const prepared = Number(folder.style?.preparedRun || 0);
      if (open && Number(state.folder.style?.preparedRun || 0) !== prepared) {
        rereadRuns = true;
        api.setHash({ run: null });          // новый старт — прежний выбор прогона больше не держим
        if (state.run && state.run.id <= prepared) clearRunContext();
      }
      /* Открытую папку не подменяем новым объектом, а дописываем в старый:
         на него ссылаются поля панели, и правка ушла бы в выброшенную копию —
         экран после этого менялся только при обновлении страницы. */
      const looked = open && JSON.stringify(state.folder.style || null) !== JSON.stringify(folder.style || null);
      const fresh = open ? Object.assign(state.folder, folder) : folder;
      if (known >= 0) state.folders[known] = fresh; else state.folders.push(fresh);
      if (looked) emit('scheme');          // вид стрелок папки сменили — перерисовываем холст
      emit('folders');
    }
    for (const element of changed.elements || []) {
      // Ярлык из другой папки к объекту этой — тоже наш: он виден с обеих сторон.
      if (state.folder && (element.folder === state.folder.id
          || (element.type === 'link' && element.toFolder === state.folder.id))) putElement(element);
    }
    for (const gone of answer.deleted || []) {
      if (gone.entity === 'element') dropElement(gone.id);
      if (gone.entity === 'run') rereadRuns = true;   // удалили блок — история прогонов стёрта
    }
    // Сам проект: счётчики обновляются молча, а название и настройки из другой вкладки перерисовывают.
    if (changed.project && state.project) {
      const seen = ['title', 'owner', 'guestWrite', 'aiConfirm', 'strictChecks']
        .some((k) => JSON.stringify(state.project[k]) !== JSON.stringify(changed.project[k]));
      Object.assign(state.project, changed.project);
      if (seen) emit('project');
    }
    if (changed.agents) { state.agents = mergeById(state.agents, changed.agents); emit('agents'); }
    if (changed.steps) applyChangedSteps(changed.steps);
    if (rereadRuns) { await loadRuns(); }
    if ((answer.agents || []).length) emit('presence', answer.agents);
  }, 1000);
}

/**
 * Шаги из дельты. Новый движок (engine = 2): шаги и подсветку приносит одним
 * снимком run.state/run.paint (run/paint.js), дельта их только будит — закрытый
 * прогон за ними уже не следит. Прежний — шаги кладутся как есть.
 */
function applyChangedSteps(steps) {
  const mine = steps.filter((step) => state.run && step.run === state.run.id);
  if (!mine.length) return;
  if (Number(state.run.engine) === 2) {
    if (!['running', 'paused'].includes(state.run.state)) refreshRun();
    return;
  }
  for (const step of mine) {
    const known = state.steps.get(step.no);
    if (!known || known.attempt <= step.attempt) state.steps.set(step.no, step);

    /* Ромб решается мгновенно и фазы «в работе» не имеет, поэтому на
       холсте он молча перескакивал в «ветка ДА». Говорим карточке
       мигнуть: иначе не видно, что прогон сейчас был именно здесь.
       Ветка — из выбранной стрелки, а не из текста ответа: он на языке проекта. */
    const element = [...state.elements.values()].find((one) => one.no === step.no);
    if (element?.type === 'decision' && (!known || known.state !== step.state)) {
      emit('decided', { no: step.no, branch: state.elements.get(step.chosen)?.branch || '' });
    }
  }
  emit('steps');
}

function mergeById(list, incoming) {
  const map = new Map(list.map((item) => [item.id, item]));
  for (const item of incoming) map.set(item.id, item);
  return [...map.values()];
}
