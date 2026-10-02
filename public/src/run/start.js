/* Окно «Начать прогон»: две ссылки-задания для агента — провести прогон и нарисовать
   или поправить схему — с пояснением, куда их отдать. Расширенная версия окошка 🔗 в шапке.
   Отдаёт: openStartDialog().
   Не делает: не запускает и не ведёт прогон — это делает leader по ссылке;
              браузер прогон только показывает. */

import { state } from 'goblin/core/state.js';
import { t } from 'goblin/core/i18n.js';
import { taskLink, taskOrder } from 'goblin/api/client.js';
import { toast } from 'goblin/shell/topbar.js';
import { el } from 'goblin/panel/parts.js';

const LINKS = [
  { role: 'lead', mark: '▶', name: 'editor.topbar.link_run', how: 'editor.start.run_how',
    copied: 'editor.topbar.run_link_copied' },
  { role: 'draw', mark: '✎', name: 'editor.topbar.link_draw', how: 'editor.start.draw_how',
    copied: 'editor.topbar.draw_link_copied' },
];

export function openStartDialog() {
  if (!state.folder) return;
  const dialog = document.getElementById('dialog-run');
  document.getElementById('run-check').replaceChildren(
    el('p', 'start-note', t('editor.start.intro')),
    ...LINKS.map(linkCard),
  );
  for (const button of dialog.querySelectorAll('[data-close]')) button.onclick = () => dialog.close();
  dialog.showModal();
}

/* Карточка ссылки: что это, куда отдать, сама ссылка и «Копировать». */
function linkCard(link) {
  const card = el('section', 'start-link');
  card.dataset.role = link.role;

  const field = el('input', 'start-link-url mono');
  field.readOnly = true;
  field.value = taskLink(link.role, state.folder.id);
  field.onfocus = () => field.select();

  const copy = el('button', 'btn btn-accent', t('editor.start.copy'));
  copy.onclick = () => {
    navigator.clipboard?.writeText(taskOrder(link.role, state.folder.id));
    toast(t(link.copied));
  };

  const row = el('div', 'start-link-row');
  row.append(field, copy);
  card.append(el('h4', 'start-link-name', link.mark + ' ' + t(link.name)), el('p', 'start-note', t(link.how)), row);
  return card;
}
