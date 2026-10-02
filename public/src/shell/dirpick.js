/* Обзор папок на сервере: выбрать рабочую папку прогона.
   Отдаёт: pickDir().
   Не делает: ничего не сохраняет — отдаёт выбранный путь тому, кто позвал.
   Браузер путей на диске не знает, поэтому папки даёт сервер (dir.list),
   и только внутри разрешённых корней (file_roots в config.php). */

import * as api from 'goblin/api/client.js';
import { FOLDER_ICON } from 'goblin/left/parts.js';
import { t } from 'goblin/core/i18n.js';

/** Открыть окно с папки start (пусто — корни). Отдаёт путь или null. */
export function pickDir(start = '') {
  const dialog = document.getElementById('dialog-dir');
  const where = document.getElementById('dialog-dir-path');
  const list = document.getElementById('dialog-dir-list');
  const up = document.getElementById('dialog-dir-up');
  const choose = document.getElementById('dialog-dir-choose');
  let now = '';

  const open = async (path) => {
    let answer;
    try {
      answer = await api.get('dir.list', { path });
    } catch {
      if (path) open('');          // папки нет или она вне корней — к корням
      return;
    }
    now = answer.path;
    where.textContent = now || t('editor.dirpick.allowed');
    up.disabled = answer.parent === null;
    up.onclick = () => open(answer.parent ?? '');
    choose.disabled = !now;
    list.textContent = '';
    for (const dir of answer.dirs) {
      const row = document.createElement('button');
      row.className = 'file-row';
      row.innerHTML = `<b>${FOLDER_ICON}</b><span></span>`;
      row.querySelector('span').textContent = dir.name;
      row.onclick = () => open(dir.path);
      list.append(row);
    }
    if (!answer.dirs.length) list.innerHTML = `<p class="muted dir-empty">${t('editor.dirpick.no_subfolders')}</p>`;
  };

  return new Promise((resolve) => {
    let picked = null;
    choose.onclick = () => { picked = now; dialog.close(); };
    for (const button of dialog.querySelectorAll('[data-close]')) button.onclick = () => dialog.close();
    dialog.onclose = () => { dialog.onclose = null; resolve(picked); };
    dialog.showModal();
    open(start);
  });
}
