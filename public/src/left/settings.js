/* Левая плашка: меню настроек.
   Что делает: вешает действия на пункты меню из views/editor/left.html — язык,
   каталог и конструктор, открыть/сохранить, кабинет, настройки холста, документация —
   и на кнопки справки в окне «Настройки холста» (views/editor/dialogs.html):
   инструкция, инструкция leader, списки.
   Отдаёт: initSettings().
   Не делает: не рисует пункты — разметка одна, в left.html; окна открывают
   их собственные модули. */

import * as api from 'goblin/api/client.js';
import { state } from 'goblin/core/state.js';
import { toast } from 'goblin/shell/topbar.js';
import { openSettings } from 'goblin/shell/dialogs.js';
import { openNewScheme } from 'goblin/shell/newscheme.js';
import { openLists, openHelp } from 'goblin/shell/info.js';
import { t, lang, setLang } from 'goblin/core/i18n.js';
import { openFilesWindow } from 'goblin/shell/files.js';

const ACTIONS = {
  'cl-templates': () => openNewScheme('catalog'),
  'cl-builder': () => openNewScheme('builder'),
  'cl-open': () => openFilesWindow('open'),
  'cl-save': () => openFilesWindow('save'),
  'cl-lists': openLists,
  // Инструкция leader открытой папки — сервер собирает её под состав команды и среду.
  'cl-docs': () => {
    if (!state.folder) { toast(t('editor.settings.open_folder_first')); return; }
    window.open('api.php?op=docs.get&file=leader&folder=' + state.folder.id + '&project=' + api.projectKey(), '_blank');
  },
  'cl-settings': openSettings,
  'cl-help': openHelp,
  'cl-documentation': () => { window.open('documentation.php', '_blank', 'noopener'); },
};

export function initSettings(holder) {
  // Пункты меню — в плашке, справка — в окне настроек холста: ищем по всей странице.
  for (const [id, action] of Object.entries(ACTIONS)) {
    const item = holder.querySelector('#' + id) || document.getElementById(id);
    if (item) item.onclick = action;
  }
  for (const button of holder.querySelectorAll('[data-lang]')) {
    button.classList.toggle('on', button.dataset.lang === lang);
    button.onclick = () => setLang(button.dataset.lang);
  }
}
