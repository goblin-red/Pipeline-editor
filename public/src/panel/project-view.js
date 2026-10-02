/* Проект в панели: настройки открытого проекта и список своих проектов.
   Отдаёт: projectSections().
   Не делает: не читает список проектов сам — его рисует shell/projects.js.

   Показывается в панели, только когда папка не открыта. Отдельной вкладки
   «Проекты» справа больше нет: список — в левой плашке, настройки — в кабинете. */

import { state } from 'goblin/core/state.js';
import * as api from 'goblin/api/client.js';
import { el, wrap, field, input, checkbox, kv, note, pick } from 'goblin/panel/parts.js';
import { fillProjects, newProject } from 'goblin/shell/projects.js';
import { loadMine } from 'goblin/api/sync.js';
import { t } from 'goblin/core/i18n.js';

/** Язык агентов проекта: на нём инструкции ведущего и worker, задания прогона и встроенный ИИ. */
function langFields(project, save) {
  // Своя плашка выбора, как у прочих полей панели: названия языков — на своих языках.
  const select = pick([{ key: 'ru', label: 'Русский' }, { key: 'en', label: 'English' }], project.lang || 'ru', false);
  select.disabled = state.viewOnly;
  select.onchange = async () => {
    const was = project.lang || 'ru';
    try { await save({ lang: select.value }); project.lang = select.value; }
    catch { select.value = was; }   // причину уже показал общий обработчик ошибок api
  };
  return [field(t('editor.projview.agent_lang'), select), note(t('editor.projview.agent_lang_note'))];
}

/**
 * Где лежат материалы (только сайт): снята — у человека в папке схемы (in/), по умолчанию;
 * стоит — на сервере, с лимитами бесплатного аккаунта (решение хозяина 01.10.2026, shell/localdir.js::putFile).
 */
function filesFields(project, save) {
  if (!project.agentsRemote) return [];
  const limit = project.filesLimit || {};
  const hint = t('editor.projview.files_server_hint') + (limit.count || limit.kb
    ? ' ' + t('editor.projview.files_server_limit', { n: limit.count || '∞', kb: limit.kb || '∞' }) : '');
  return [checkbox(t('editor.projview.files_server'), project.filesServer, async (value) => {
    await save({ filesServer: value });
    project.filesServer = value;
  }, hint)];
}

/**
 * Свойства любого проекта, не только открытого: щелчок по плитке в списке
 * проектов показывает их в панели. Чужой проект читаем с сервера по ключу,
 * правки уходят с тем же ключом. onEnter — войти в проект (к его папкам).
 */
export function projectCard(key, onEnter) {
  const box = el('div');
  box.append(el('p', 'muted', t('editor.projects.loading')));
  const here = key === state.project?.key;
  const load = here
    ? Promise.resolve({ project: state.project, folders: state.folders })
    : api.get('project.get', { project: key }).catch(() => null);

  load.then((answer) => {
    // Пока читали, человек мог щёлкнуть другой проект.
    if (state.pickedProject !== key) return;
    box.textContent = '';
    const project = answer?.project;
    if (!project) { box.append(el('p', 'muted', t('editor.projview.not_found'))); return; }

    const save = (fields) => api.post('project.update', { project: key, ...fields });
    const enter = el('button', 'btn btn-accent btn-wide', here ? t('editor.projview.to_folders') : t('editor.projview.open'));
    enter.onclick = onEnter;
    box.append(wrap(t('editor.settings.project'), [
      field(t('editor.asset.name'), input(project.title || '',
        // Новое имя должно появиться и на плитке — перечитываем список.
        (value) => save({ title: value }).then(() => loadMine(true)))),
      checkbox(t('editor.projview.strict'), project.strictChecks,
        (value) => save({ strictChecks: value }),
        t('editor.projview.strict_hint')),
      ...langFields(project, save),
      ...filesFields(project, save),
      kv({ [t('editor.projview.link')]: '#p=' + key, [t('editor.projview.folders')]: (answer.folders || []).length, [t('editor.projview.next_no')]: project.nextNo }),
      enter,
    ], true));
  });
  return box;
}

/** Секции «Проект» и «Мои проекты». open — раскрыть их сразу. */
export function projectSections({ open = false } = {}) {
  const out = [];

  if (state.project) {
    out.push(wrap(t('editor.settings.project'), [
      field(t('editor.asset.name'), input(state.project.title || '',
        (value) => api.post('project.update', { title: value }))),
      checkbox(t('editor.projview.strict'), state.project.strictChecks,
        (value) => api.post('project.update', { strictChecks: value }),
        t('editor.projview.strict_hint')),
      ...langFields(state.project, (fields) => api.post('project.update', fields)),
      ...filesFields(state.project, (fields) => api.post('project.update', fields)),
      kv({ [t('editor.projview.link')]: '#p=' + state.project.key, [t('editor.projview.next_no')]: state.project.nextNo }),
    ], open));
  }

  const holder = el('div');
  fillProjects(holder);
  // Кнопка над списком: новый проект заводится прямо отсюда, без кабинета.
  const add = el('button', 'btn btn-quiet btn-wide', t('editor.projview.create'));
  add.onclick = () => { add.disabled = true; newProject(); };
  out.push(wrap(t('editor.projview.mine'), [add, holder], open));

  return out;
}
