/* Палитра: как ключ цвета превращается в заливку и обводку.
   Отдаёт: PALETTE, paintOf(), colorName(), edgeColor().
   Не делает: ничего не красит сама — отдаёт значения, красит карточка.

   Ключи прежнего Гоблина сохранены полностью: пастельные 1–6 (те же, что
   в Obsidian Canvas), контрастные шестнадцатеричные, полоски и градиенты.
   К ним добавлены двенадцать именованных из config.txt. Схемы, нарисованные
   раньше, открываются в тех же цветах — иначе перенос был бы враньём. */

import { t } from 'goblin/core/i18n.js';

/* Пастельные 1–6: с ними цвет переживает выгрузку в .canvas. */
const PASTEL = {
  '1': { fill: '#ffd9dc', edge: '#e28a90', name: t('editor.palette.red') },
  '2': { fill: '#ffe3c4', edge: '#e0a366', name: t('editor.palette.orange') },
  '3': { fill: '#fff4bd', edge: '#d9c258', name: t('editor.palette.yellow') },
  '4': { fill: '#d5f4cd', edge: '#7cbd6b', name: t('editor.palette.green') },
  '5': { fill: '#cdeeff', edge: '#6bb6dd', name: t('editor.palette.sky') },
  '6': { fill: '#e5d8ff', edge: '#a78ade', name: t('editor.palette.violet') },
};

/* Контрастные: ими красили роли и выделяли важное. */
const STRONG = {
  '#23b5d3': { fill: '#23b5d3', edge: '#62d5ec', name: t('editor.palette.cyan') },
  '#2ec4a6': { fill: '#2ec4a6', edge: '#6ee3c9', name: t('editor.palette.mint') },
  '#f4a261': { fill: '#f4a261', edge: '#ffc18d', name: t('editor.palette.amber') },
  '#e76f51': { fill: '#e76f51', edge: '#ff9a7f', name: t('editor.palette.coral') },
  '#5f6caf': { fill: '#5f6caf', edge: '#8e9ce0', name: t('editor.palette.indigo') },
  '#8a9a5b': { fill: '#8a9a5b', edge: '#b3c77b', name: t('editor.palette.olive') },
  '#708090': { fill: '#708090', edge: '#a2b0bd', name: t('editor.palette.steel') },
};

/* Приглушённые средние: не светлые пятна, а тихие тона чуть выше тёмных —
   ими отделяют группы, не пересвечивая холст. */
const SOFT = {
  '#3f4c66': { fill: '#3f4c66', edge: '#6e7e9e', name: t('editor.palette.bluegray') },
  '#3c5449': { fill: '#3c5449', edge: '#6a8d7c', name: t('editor.palette.m_moss') },
  '#3a5154': { fill: '#3a5154', edge: '#68898d', name: t('editor.palette.m_teal') },
  '#4a4162': { fill: '#4a4162', edge: '#7c6f9c', name: t('editor.palette.m_lavender') },
  '#5b4144': { fill: '#5b4144', edge: '#8f6a6e', name: t('editor.palette.m_rose') },
  '#5b4a33': { fill: '#5b4a33', edge: '#8f7852', name: t('editor.palette.m_sand') },
  '#54415a': { fill: '#54415a', edge: '#856b8d', name: t('editor.palette.m_plum') },
  '#464b57': { fill: '#464b57', edge: '#787f8d', name: t('editor.palette.m_graphite') },
};

/* Тёмные глубокие: карточка остаётся тёмной, цвет читается как настроение,
   а не как заливка. Текст на них — светлый. */
const DEEP = {
  '#26344a': { fill: '#26344a', edge: '#4f6a88', name: t('editor.palette.night_blue') },
  '#20372e': { fill: '#20372e', edge: '#3f7059', name: t('editor.palette.d_moss') },
  '#1f3a3c': { fill: '#1f3a3c', edge: '#3d7376', name: t('editor.palette.d_teal') },
  '#33284a': { fill: '#33284a', edge: '#6553a0', name: t('editor.palette.d_plum') },
  '#452a2e': { fill: '#452a2e', edge: '#8a5158', name: t('editor.palette.d_burgundy') },
  '#4a3520': { fill: '#4a3520', edge: '#916740', name: t('editor.palette.d_ochre') },
  '#3f3a1f': { fill: '#3f3a1f', edge: '#7d733d', name: t('editor.palette.d_gold') },
  '#412a38': { fill: '#412a38', edge: '#80536d', name: t('editor.palette.d_prune') },
  '#233746': { fill: '#233746', edge: '#456b87', name: t('editor.palette.d_azure') },
  '#2f3a22': { fill: '#2f3a22', edge: '#5c7343', name: t('editor.palette.d_khaki') },
  '#3b2f27': { fill: '#3b2f27', edge: '#735c4c', name: t('editor.palette.d_coffee') },
  '#2b303a': { fill: '#2b303a', edge: '#555d70', name: t('editor.palette.d_graphite') },

  /* Три нейтральных: ими глушат вспомогательные блоки. */
  '#101216': { fill: '#101216', edge: '#3c4250', name: t('editor.palette.black') },
  '#22252e': { fill: '#22252e', edge: '#4a5060', name: t('editor.palette.dark_gray') },
  '#3a3f4b': { fill: '#3a3f4b', edge: '#6b7384', name: t('editor.palette.grayish') },
};

/* Заливки-узоры: полоски и градиенты. У градиентов цвет держится первую
   шестую часть и сходит на нет к середине — дальше карточка чистая. Прозрачный конец пишется тем же цветом с
   нулевой прозрачностью: со словом transparent переход уходил в грязно-чёрное,
   и казалось, будто краска обрывается. */
const PATTERNS = {
  'stripe-diag': { fill: 'repeating-linear-gradient(135deg,#343b50 0 12px,#273044 12px 24px)', edge: '#8296c9', name: t('editor.palette.stripe_diag') },
  'stripe-diag-reverse': { fill: 'repeating-linear-gradient(45deg,#493a43 0 10px,#302c39 10px 20px)', edge: '#d58b9d', name: t('editor.palette.stripe_diag_rev') },
  'stripe-horizontal': { fill: 'repeating-linear-gradient(0deg,#263d3b 0 8px,#1e302f 8px 16px)', edge: '#62b8a9', name: t('editor.palette.stripe_horizontal') },
  'stripe-vertical': { fill: 'repeating-linear-gradient(90deg,#453d2f 0 9px,#302d29 9px 18px)', edge: '#c2a36e', name: t('editor.palette.stripe_vertical') },

  /* Диагональные полоски — четыре тихих набора: ими метят особые блоки,
     не вводя нового цвета. */
  'stripe-gray': { fill: 'repeating-linear-gradient(135deg,#242832 0 12px,#1e212a 12px 24px)', edge: '#6b7280', name: t('editor.palette.stripe_gray') },
  'stripe-green': { fill: 'repeating-linear-gradient(135deg,#222e27 0 12px,#1d2521 12px 24px)', edge: '#587d64', name: t('editor.palette.stripe_green') },
  'stripe-red': { fill: 'repeating-linear-gradient(135deg,#302527 0 12px,#261e1f 12px 24px)', edge: '#855c5c', name: t('editor.palette.stripe_red') },
  'stripe-blue': { fill: 'repeating-linear-gradient(135deg,#232b38 0 12px,#1d222c 12px 24px)', edge: '#63708e', name: t('editor.palette.stripe_blue') },
  'gradient-ocean': { fill: 'linear-gradient(135deg,#132c37 0%,#132c37 12%,#132c3766 30%,#132c3700 55%)', edge: '#67c6c9', name: t('editor.palette.ocean') },
  'gradient-sunset': { fill: 'linear-gradient(135deg,#392025 0%,#392025 12%,#39202566 30%,#39202500 55%)', edge: '#f09a6c', name: t('editor.palette.sunset') },
  'gradient-forest': { fill: 'linear-gradient(145deg,#17281e 0%,#17281e 12%,#17281e66 30%,#17281e00 55%)', edge: '#9db973', name: t('editor.palette.forest') },
  'gradient-steel': { fill: 'linear-gradient(135deg,#222832 0%,#222832 12%,#22283266 30%,#22283200 55%)', edge: '#9aa8c7', name: t('editor.palette.steel_grad') },

  /* Пастельные переходы: два тихих цвета, без резкой границы. */
  'gradient-soft-sky': { fill: 'linear-gradient(135deg,#242b3a 0%,#242b3a 12%,#242b3a66 30%,#242b3a00 55%)', edge: '#cfdcef', name: t('editor.palette.soft_sky') },
  'gradient-soft-mint': { fill: 'linear-gradient(135deg,#1d2823 0%,#1d2823 12%,#1d282366 30%,#1d282300 55%)', edge: '#cfe4cf', name: t('editor.palette.soft_mint') },
  'gradient-soft-peach': { fill: 'linear-gradient(135deg,#2c2418 0%,#2c2418 12%,#2c241866 30%,#2c241800 55%)', edge: '#f0d0c2', name: t('editor.palette.soft_peach') },
  'gradient-soft-rose': { fill: 'linear-gradient(135deg,#2c1f20 0%,#2c1f20 12%,#2c1f2066 30%,#2c1f2000 55%)', edge: '#e0cbe2', name: t('editor.palette.soft_rose') },
  'gradient-soft-sand': { fill: 'linear-gradient(135deg,#28221b 0%,#28221b 12%,#28221b66 30%,#28221b00 55%)', edge: '#e6dcc2', name: t('editor.palette.soft_sand') },
  'gradient-soft-sage': { fill: 'linear-gradient(135deg,#1e2431 0%,#1e2431 12%,#1e243166 30%,#1e243100 55%)', edge: '#c6dbd8', name: t('editor.palette.soft_sage') },

  /* Тёмные переходы: тот же приём, но вглубь. */
  'gradient-deep-night': { fill: 'linear-gradient(135deg,#121924 0%,#121924 12%,#12192466 30%,#12192400 55%)', edge: '#5b6a92', name: t('editor.palette.deep_night') },
  'gradient-deep-moss': { fill: 'linear-gradient(135deg,#0f1a16 0%,#0f1a16 12%,#0f1a1666 30%,#0f1a1600 55%)', edge: '#587a53', name: t('editor.palette.deep_moss') },
  'gradient-deep-ember': { fill: 'linear-gradient(135deg,#211416 0%,#211416 12%,#21141666 30%,#21141600 55%)', edge: '#8f5f46', name: t('editor.palette.deep_ember') },
  'gradient-deep-lagoon': { fill: 'linear-gradient(135deg,#0e1b1d 0%,#0e1b1d 12%,#0e1b1d66 30%,#0e1b1d00 55%)', edge: '#3f7480', name: t('editor.palette.deep_lagoon') },
  'gradient-deep-plum': { fill: 'linear-gradient(135deg,#1f141b 0%,#1f141b 12%,#1f141b66 30%,#1f141b00 55%)', edge: '#6d5468', name: t('editor.palette.deep_plum') },
  'gradient-deep-coal': { fill: 'linear-gradient(135deg,#14171b 0%,#14171b 12%,#14171b66 30%,#14171b00 55%)', edge: '#565e70', name: t('editor.palette.deep_coal') },

  /* Четыре серых — из светлого в прозрачное, в разные стороны: карточка
     растворяется в холсте, а не кладётся на него плашкой. */
  'gradient-gray-down': { fill: 'linear-gradient(180deg,rgba(40,44,54,.9) 0%,rgba(40,44,54,.9) 12%,rgba(40,44,54,.36) 30%,rgba(40,44,54,0) 55%)', edge: '#767e90', name: t('editor.palette.gray_down') },
  'gradient-gray-up': { fill: 'linear-gradient(0deg,rgba(40,44,54,.9) 0%,rgba(40,44,54,.9) 12%,rgba(40,44,54,.36) 30%,rgba(40,44,54,0) 55%)', edge: '#767e90', name: t('editor.palette.gray_up') },
  'gradient-gray-side': { fill: 'linear-gradient(90deg,rgba(40,44,54,.9) 0%,rgba(40,44,54,.9) 12%,rgba(40,44,54,.36) 30%,rgba(40,44,54,0) 55%)', edge: '#767e90', name: t('editor.palette.gray_side') },
  'gradient-gray-diagonal': { fill: 'linear-gradient(135deg,rgba(40,44,54,.9) 0%,rgba(40,44,54,.9) 12%,rgba(40,44,54,.36) 30%,rgba(40,44,54,0) 55%)', edge: '#767e90', name: t('editor.palette.gray_diagonal') },
  'gradient-gray-middle': { fill: 'linear-gradient(135deg,rgba(40,44,54,0) 0%,rgba(40,44,54,.36) 22%,rgba(40,44,54,.9) 50%,rgba(40,44,54,.36) 78%,rgba(40,44,54,0) 100%)', edge: '#767e90', name: t('editor.palette.gray_middle') },
  'gradient-gray-core': { fill: 'linear-gradient(135deg,#0d0f13 0%,#14161c 26%,#2a2e38 50%,#14161c 74%,#0d0f13 100%)', edge: '#767e90', name: t('editor.palette.gray_core') },
};

/* Двенадцать именованных — те, что в config.txt и в панели свойств. */
const NAMED = {
  blue: '--c-blue', green: '--c-green', teal: '--c-teal', purple: '--c-purple',
  red: '--c-red', orange: '--c-orange', yellow: '--c-yellow', pink: '--c-pink',
  cyan: '--c-cyan', lime: '--c-lime', brown: '--c-brown', gray: '--c-gray',
};

/* Цвета стрелки — это роли, а не краски: они меняются вместе с темой. */
const EDGE_ROLES = { muted: 'var(--ink-faint)', accent: 'var(--accent)', link: 'var(--info)' };

/*
 * Что показывать в палитре. Список короткий и намеренно тихий: три светлых
 * на подсветку, остальное — приглушённое и тёмное, плюс переходы. Всё
 * остальное (яркие именованные, контрастные, полоски) paintOf() по-прежнему
 * понимает: старые схемы открываются в своих цветах, их просто не предлагают.
 */
export const PALETTE = ['',
  '#3f4c66', '#3c5449', '#3a5154', '#4a4162',               // приглушённые средние
  '#5b4144', '#5b4a33', '#54415a', '#464b57',
  '#26344a', '#20372e', '#1f3a3c', '#33284a',               // тёмные
  '#452a2e', '#4a3520', '#233746', '#2b303a',
  '#101216', '#22252e', '#3a3f4b',                          // чёрный, тёмно-серый, сероватый
  'gradient-deep-night', 'gradient-deep-moss', 'gradient-deep-lagoon', 'gradient-deep-ember',
  'gradient-gray-down', 'gradient-gray-up', 'gradient-gray-side', 'gradient-gray-diagonal',
  'gradient-gray-core',                                     // серое пятно по центру
  'stripe-gray', 'stripe-green', 'stripe-red', 'stripe-blue',
];

/** Заливка и обводка по ключу. Пустой ключ — цвета нет. */
export function paintOf(key) {
  if (!key) return null;
  if (PASTEL[key]) return PASTEL[key];
  if (SOFT[key]) return SOFT[key];
  if (DEEP[key]) return DEEP[key];
  if (STRONG[key]) return STRONG[key];
  if (PATTERNS[key]) return PATTERNS[key];
  if (NAMED[key]) {
    return {
      fill: `color-mix(in srgb, var(${NAMED[key]}) 16%, var(--surface))`,
      edge: `var(${NAMED[key]})`,
      name: key,
    };
  }
  if (/^#[0-9a-f]{3,8}$/i.test(key)) return { fill: key, edge: key, name: key };
  if (EDGE_ROLES[key]) return { fill: 'transparent', edge: EDGE_ROLES[key], name: key };
  return null;
}

/** Светлая ли заливка: по ней выбирается цвет текста на карточке. */
export function inkFor(key) {
  const paint = paintOf(key);
  if (!paint) return null;
  const hex = String(paint.fill).match(/#([0-9a-f]{6})/i);
  if (!hex) return null;                       // узор или токен — чернила не трогаем
  const value = parseInt(hex[1], 16);
  const r = (value >> 16) & 255, g = (value >> 8) & 255, b = value & 255;
  const light = (0.299 * r + 0.587 * g + 0.114 * b) / 255 > 0.62;
  return light ? { ink: '#14171d', dim: '#4a515e' } : { ink: '#f2f4f8', dim: '#c6ccd8' };
}

/**
 * Покрасить кружок образца. Узор идёт картинкой, а не сокращённым background:
 * иначе он стирал подложку, и прозрачные градиенты выглядели пустыми.
 */
export function paintSwatch(node, key) {
  const paint = paintOf(key);
  node.style.backgroundImage = '';
  node.style.backgroundColor = '';
  node.style.removeProperty('--swatch-edge');
  if (!paint) return;
  if (/gradient|url\(/.test(paint.fill)) node.style.backgroundImage = paint.fill;
  else node.style.backgroundColor = paint.fill;
  node.style.setProperty('--swatch-edge', paint.edge);
}

export function colorName(key) {
  return paintOf(key)?.name || t('editor.palette.none');
}

/** Цвет стрелки: роль, ключ палитры или свой оттенок. */
export function edgeColor(key) {
  if (!key) return 'currentColor';
  if (EDGE_ROLES[key]) return EDGE_ROLES[key];
  return paintOf(key)?.edge || 'currentColor';
}
