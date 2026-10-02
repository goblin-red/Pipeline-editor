/* Настройки холста: что человек включил под себя.
   Отдаёт: settings, setSetting(), resetSettings(), gridStep(), gridInk(), snapTo(),
           SETTING_LIST.
   Не делает: ничего не рисует и не ходит на сервер — только помнит выбор
   в этом браузере и сообщает о нём событием 'settings'.

   Одно место на все переключатели: добавил строку в SETTING_LIST — она сама
   появилась в окне настроек и сама сохранилась. */

import { emit } from 'goblin/core/state.js';
import { t } from 'goblin/core/i18n.js';

const STORE = 'goblin-settings';

/* Описание переключателей: имя, что значит по-человечески и каким бывает.
   Порядок здесь — порядок в окне настроек. */
export const SETTING_LIST = [
  { name: 'snap', type: 'flag', title: t('editor.canvasopts.snap_title'),
    hint: t('editor.canvasopts.snap_hint') },
  { name: 'grid', type: 'flag', title: t('editor.canvasopts.grid_title'),
    hint: t('editor.canvasopts.grid_hint') },
  { name: 'gridStep', type: 'number', title: t('editor.canvasopts.gridStep_title'), min: 5, max: 100,
    hint: t('editor.canvasopts.gridStep_hint') },
  { name: 'gridInk', type: 'range', title: t('editor.canvasopts.gridInk_title'), min: 0, max: 2, step: 0.05,
    hint: t('editor.canvasopts.gridInk_hint') },
  { name: 'trackpadPan', type: 'flag', title: t('editor.canvasopts.trackpadPan_title'),
    hint: t('editor.canvasopts.trackpadPan_hint') },
  { name: 'pinchZoom', type: 'flag', title: t('editor.canvasopts.pinchZoom_title'),
    hint: t('editor.canvasopts.pinchZoom_hint') },
  { name: 'zoomSpeed', type: 'range', title: t('editor.canvasopts.zoomSpeed_title'), min: 0.2, max: 2, step: 0.1,
    hint: t('editor.canvasopts.zoomSpeed_hint') },
  { name: 'spacePan', type: 'flag', title: t('editor.canvasopts.spacePan_title'),
    hint: t('editor.canvasopts.spacePan_hint') },
  { name: 'assetsView', type: 'choice', title: t('editor.canvasopts.assetsView_title'),
    options: [['tiles', t('editor.canvasopts.opt_thumbs')], ['list', t('editor.canvasopts.opt_list')]],
    hint: t('editor.canvasopts.assetsView_hint') },
  { name: 'arrowPorts', type: 'choice', title: t('editor.canvasopts.arrowPorts_title'),
    options: [['auto', t('editor.canvasopts.opt_auto')], ['manual', t('editor.canvasopts.opt_manual')]],
    hint: t('editor.canvasopts.arrowPorts_hint') },
  { name: 'agentsView', type: 'choice', title: t('editor.canvasopts.agentsView_title'),
    options: [['list', t('editor.canvasopts.opt_list')], ['tiles', t('editor.canvasopts.opt_tiles')]],
    hint: t('editor.canvasopts.agentsView_hint') },
  { name: 'runShow', type: 'flag', title: t('editor.canvasopts.runShow_title'),
    hint: t('editor.canvasopts.runShow_hint') },
  { name: 'runShowSec', type: 'number', title: t('editor.canvasopts.runShowSec_title'), min: 0.5, max: 10, step: 0.5,
    hint: t('editor.canvasopts.runShowSec_hint') },
  { name: 'runFollow', type: 'flag', title: t('editor.canvasopts.runFollow_title'),
    hint: t('editor.canvasopts.runFollow_hint') },
];

const DEFAULTS = {
  snap: true,
  grid: true,
  gridStep: 20,
  gridInk: 1,
  trackpadPan: true,
  pinchZoom: true,
  zoomSpeed: 1,
  spacePan: true,
  arrowPorts: 'auto',
  assetsView: 'tiles',
  agentsView: 'list',
  runShow: true,
  runShowSec: 3,
  runFollow: false,
};

export const settings = { ...DEFAULTS, ...load() };

export function setSetting(name, value) {
  if (!(name in DEFAULTS)) return;
  settings[name] = value;
  save();
  emit('settings', name);
}

export function resetSettings() {
  Object.assign(settings, DEFAULTS);
  save();
  emit('settings', null);
}

/** Шаг сетки в единицах схемы: им же липнут блоки при отпускании. */
export function gridStep() {
  const value = Number(settings.gridStep);
  return Number.isFinite(value) && value >= 2 ? value : DEFAULTS.gridStep;
}

/** Заметность точек сетки: множитель прозрачности, который крутят в настройках. */
export function gridInk() {
  const value = Number(settings.gridInk);
  return Number.isFinite(value) && value >= 0 ? value : DEFAULTS.gridInk;
}

/** Задержка показа прогона в мс: 0 — выключена, статус ложится сразу. */
export function runShowMs() {
  if (!settings.runShow) return 0;
  const value = Number(settings.runShowSec);
  return Number.isFinite(value) && value > 0 ? Math.min(value, 10) * 1000 : DEFAULTS.runShowSec * 1000;
}

/** Округление к сетке. Выключено прилипание — значение не трогаем. */
export function snapTo(value) {
  if (!settings.snap) return Math.round(value);
  const step = gridStep();
  return Math.round(value / step) * step;
}

function load() {
  try { return JSON.parse(localStorage.getItem(STORE) || '{}') || {}; } catch { return {}; }
}

function save() {
  try { localStorage.setItem(STORE, JSON.stringify(settings)); } catch {}
}
