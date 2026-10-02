/* Вход редактора: порядок старта и ничего больше.
   Отдаёт: страницу, готовую к работе.
   Не делает: не хранит логики — она в модулях по доменам. */

import * as api from 'goblin/api/client.js';
import { state, on } from 'goblin/core/state.js';
import { loadProject, loadFolder, startPolling } from 'goblin/api/sync.js';
import { welcomeIfNew } from 'goblin/shell/welcome.js';
import { drawOne } from 'goblin/canvas/view.js';
import { mountVariant, currentVariant } from 'goblin/core/variants.js';
import { initPanel } from 'goblin/panel/panel.js';
import { initLeft } from 'goblin/left/left.js';
import { initTopbar, toast } from 'goblin/shell/topbar.js';
import { initRunbar } from 'goblin/shell/runbar.js';
import { initLocalDir } from 'goblin/shell/localdir.js';
import { initSides } from 'goblin/shell/sides.js';
import { initTips } from 'goblin/shell/tips.js';
import { cliRows } from 'goblin/shell/agentview.js';
import { initPanelTabs } from 'goblin/panel/tabs.js';
import { initAi } from 'goblin/panel/ai.js';
import { flush } from 'goblin/edit/scene.js';
import { initStudio } from 'goblin/shell/studio.js';
import { initMobile } from 'goblin/mobile/mobile.js';
import { t } from 'goblin/core/i18n.js';

api.onError((message) => toast(message, true));
on('refused', (message) => toast(message, true));   // правку не пустило правило схемы

initTopbar();
initLeft();
initPanel();
initRunbar();
initLocalDir();
initSides();
initTips();
// Цвета инструментов нужны и на холсте (плашка исполнителя), и в панели.
cliRows();
initPanelTabs();
initAi();
initStudio();
initMobile();
startPolling();

// Выделение меняет вид карточек — перерисовываем только то, что было выбрано.
let lastSelection = new Set();
on('selection', () => {
  const now = new Set(state.selection);
  for (const id of new Set([...lastSelection, ...now])) {
    const element = state.elements.get(id);
    if (element && state.variant === 'canvas') drawOne(element);
  }
  lastSelection = now;
});

// Пачка не применилась целиком — перечитываем папку, чтобы не разойтись с сервером.
on('resync', async () => {
  toast(t('editor.main.edit_failed'), true);
  await loadFolder(state.folder?.id);
});

// Перед уходом дописываем то, что не успело уйти.
window.addEventListener('beforeunload', (event) => {
  if (!state.dirty) return;
  flush(true);      // keepalive: иначе запрос умрёт вместе с вкладкой
  event.preventDefault();
  event.returnValue = '';
});

window.addEventListener('hashchange', () => {
  const wanted = api.folderId();
  if (wanted && wanted !== state.folder?.id) loadFolder(wanted);
});

(async function start() {
  const opened = await loadProject();
  await loadFolder();
  await mountVariant(currentVariant());
  welcomeIfNew(opened);   // первый заход гостя на пустой холст — окно «с чего начать»
})();
