/* Виды интерфейса: один и тот же Гоблин, разные способы работать.
   Отдаёт: VARIANTS, currentVariant(), setVariant(), mountVariant().
   Не делает: не хранит данные — все виды работают на одном состоянии и одном API.

   Вид — это только оболочка над общим ядром: данные, состояние, правки и
   отрисовка холста у всех одни. Поэтому новый вид добавляется одним файлом
   и одной строкой здесь, а не вторым редактором. */

import { state, emit } from 'goblin/core/state.js';
import { setHash, hash } from 'goblin/api/client.js';
import { t } from 'goblin/core/i18n.js';

/* Значок рисуется путём: в шапке виды стоят значками, подпись — в подсказке. */
export const VARIANTS = {
  canvas: {
    title: t('editor.variants.canvas'),
    about: t('editor.variants.canvas_about'),
    key: '1',
    icon: '<rect x="3.5" y="4.5" width="7" height="6" rx="1"/><rect x="13.5" y="13.5" width="7" height="6" rx="1"/>'
        + '<path d="M7 10.5v3.5a2 2 0 0 0 2 2h4.5"/>',
  },
  list: {
    title: t('editor.variants.list'),
    about: t('editor.variants.list_about'),
    key: '2',
    icon: '<path d="M9 6h11M9 12h11M9 18h11M4.5 6h.01M4.5 12h.01M4.5 18h.01"/>',
  },
  hierarchy: {
    title: t('editor.variants.hierarchy'),
    about: t('editor.variants.hierarchy_about'),
    key: '3',
    icon: '<rect x="3.5" y="4.5" width="6.5" height="15" rx="1.5"/><rect x="14" y="4.5" width="6.5" height="9" rx="1.5"/>'
        + '<path d="M10 9h4"/>',
  },
  run: {
    title: t('editor.variants.run'),
    about: t('editor.variants.run_about'),
    key: '4',
    // Значка в шапке нет: в прогон заходят из панели, кнопкой «открыть
    // журнал целиком». Вид при этом обычный и работает как все.
    hidden: true,
    icon: '<circle cx="12" cy="12" r="8.5"/><path d="M10 8.5v7l5.5-3.5z"/>',
  },
  // Лента времени прогона: строки — агенты, отрезки — какой блок брал и когда (ui/timeline.js).
  timeline: {
    title: t('editor.variants.timeline'),
    about: t('editor.variants.timeline_about'),
    key: '5',
    icon: '<path d="M3.5 4.5v15"/><path d="M6.5 7.5h7M9.5 12h10M6.5 16.5h5.5"/>',
  },
};

const STORE = 'goblin-variant';
let mounted = null;
let turn = 0;          // поколение переключения: активен только вид последнего запроса
let wanted = null;     // последний запрошенный вид — может ещё грузиться

export function currentVariant() {
  const asked = hash().ui;
  if (asked && VARIANTS[asked]) return asked;
  try {
    const saved = localStorage.getItem(STORE);
    if (saved && VARIANTS[saved]) return saved;
  } catch {}
  return 'canvas';
}

export async function setVariant(name) {
  if (!VARIANTS[name] || name === (wanted ?? state.variant)) return;
  setHash({ ui: name === 'canvas' ? null : name });
  try { localStorage.setItem(STORE, name); } catch {}
  await mountVariant(name);
}

/**
 * Включить вид: дождаться модуля, выключить прежний, собрать новый.
 * Пока модуль грузился, попросили другой вид — этот уже не нужен: после
 * любой гонки активен ровно один вид, последний запрошенный.
 */
export async function mountVariant(name = currentVariant()) {
  const mine = ++turn;
  wanted = name;
  const module = await import(`goblin/ui/${name}.js`);
  if (mine !== turn) return;

  if (mounted?.unmount) mounted.unmount();
  document.body.dataset.ui = name;
  state.variant = name;
  mounted = module;
  module.mount();
  emit('variant', name);
}
