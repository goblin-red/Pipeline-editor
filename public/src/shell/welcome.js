/* Приветствие: первый заход человека без учётной записи на новый пустой холст — окно с двумя путями:
   пустой холст (первая папка со стартером) или готовая схема из каталога.
   Отдаёт: welcomeIfNew().
   Не делает: не показывается вошедшему, по чужой ссылке, в режиме просмотра и второй раз —
   браузер помнит, что окно уже было (goblin-welcome). */

import { state } from 'goblin/core/state.js';
import { createFolder } from 'goblin/left/folders.js';
import { openNewScheme } from 'goblin/shell/newscheme.js';
import { t } from 'goblin/core/i18n.js';

const SEEN = 'goblin-welcome';

/** opened — ответ loadProject(): null — проекта по ссылке ещё нет, это новый пустой холст. */
export function welcomeIfNew(opened) {
  const dialog = document.getElementById('dialog-welcome');
  if (!dialog || opened !== null || state.me || document.body.classList.contains('view-only') || seen()) return;
  remember();

  for (const x of dialog.querySelectorAll('[data-close]')) x.onclick = () => dialog.close();
  dialog.querySelector('#welcome-blank').onclick = async () => {
    dialog.close();
    await createFolder(t('editor.welcome.first_folder'));
  };
  dialog.querySelector('#welcome-catalog').onclick = () => {
    dialog.close();
    openNewScheme('catalog');
  };
  dialog.showModal();
}

function seen() {
  try { return localStorage.getItem(SEEN) === '1'; } catch { return false; }
}

function remember() {
  try { localStorage.setItem(SEEN, '1'); } catch { /* без памяти окно покажется и в следующий раз */ }
}
