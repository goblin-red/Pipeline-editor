/* Админка: общее — запросы, узлы, форматы чисел и дат, метки, сообщения, подтверждение.
   Отдаёт: api(), act(), el(), fmt, badge(), toast(), confirmBox(), editorUrl(), stateWord().
   Не делает: не рисует разделы — это public/src/admin/sections/. */

import { t, lang } from 'goblin/core/i18n.js';

/** Данные раздела: GET admin.php?api=… */
export async function api(name, params = {}) {
  const query = new URLSearchParams({ api: name });
  for (const [key, value] of Object.entries(params)) if (value !== undefined && value !== null && value !== '') query.set(key, value);
  const answer = await (await fetch('admin.php?' + query, { headers: { Accept: 'application/json' } })).json();
  if (!answer.ok) throw new Error(answer.error || t('admin.core.failure'));
  return answer;
}

/** Действие: POST admin.php?api=action {do, …}. Сообщение сервера — всплывашкой. */
export async function act(name, body = {}) {
  let answer;
  try {
    answer = await (await fetch('admin.php?api=action', {
      method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ do: name, ...body }),
    })).json();
  } catch {
    toast(t('admin.core.no_answer'), true);
    throw new Error(t('admin.core.no_answer'));
  }
  if (!answer.ok) { toast(answer.error || t('admin.core.refused'), true); throw new Error(answer.error); }
  if (answer.say) toast(answer.say);
  return answer;
}

/** Узел: el('div', 'класс', 'текст' | [узлы]). */
export function el(tag, cls = '', content = null) {
  const node = document.createElement(tag);
  if (cls) node.className = cls;
  if (Array.isArray(content)) node.append(...content.filter((one) => one !== null && one !== undefined && one !== false));
  else if (content instanceof Node) node.append(content);
  else if (content !== null && content !== undefined) node.textContent = String(content);
  return node;
}

const LOCALE = lang === 'ru' ? 'ru-RU' : 'en-US';
const two = (n) => String(n).padStart(2, '0');
const parse = (text) => (text ? new Date(String(text).replace(' ', 'T')) : null);

export const fmt = {
  num: (n) => (n === null || n === undefined ? '—' : Number(n).toLocaleString(LOCALE)),
  mb: (n) => (n === null || n === undefined ? '—' : t('admin.fmt.mb', { n: Number(n).toLocaleString(LOCALE) })),
  /** 22.09.2026 14:05 */
  date(text) {
    const d = parse(text);
    if (!d || Number.isNaN(d.getTime())) return '—';
    return `${two(d.getDate())}.${two(d.getMonth() + 1)}.${d.getFullYear()} ${two(d.getHours())}:${two(d.getMinutes())}`;
  },
  day(text) {
    const d = parse(text);
    return d ? `${two(d.getDate())}.${two(d.getMonth() + 1)}` : '';
  },
  /** «5 мин назад», «вчера», «3 дн. назад» */
  ago(text) {
    const d = parse(text);
    if (!d || Number.isNaN(d.getTime())) return '—';
    const s = Math.round((Date.now() - d.getTime()) / 1000);
    if (s < 60) return t('admin.fmt.just_now');
    if (s < 3600) return t('admin.fmt.min_ago', { n: Math.round(s / 60) });
    if (s < 86400) return t('admin.fmt.hour_ago', { n: Math.round(s / 3600) });
    if (s < 172800) return t('admin.fmt.yesterday');
    if (s < 86400 * 60) return t('admin.fmt.day_ago', { n: Math.round(s / 86400) });
    return fmt.date(text).slice(0, 10);
  },
  /** 95 → «1 мин 35 с» */
  dur(sec) {
    if (sec === null || sec === undefined) return '—';
    const s = Math.max(0, Number(sec));
    if (s < 60) return t('admin.fmt.sec', { n: s });
    if (s < 3600) return t('admin.fmt.min_sec', { m: Math.floor(s / 60), s: s % 60 });
    if (s < 86400) return t('admin.fmt.hour_min', { h: Math.floor(s / 3600), m: Math.floor((s % 3600) / 60) });
    return t('admin.fmt.day_hour', { d: Math.floor(s / 86400), h: Math.floor((s % 86400) / 3600) });
  },
  time: (text) => (text ? parse(text).getTime() : 0),
};

const STATES = {
  running: [t('admin.state.running'), 'accent live'], paused: [t('admin.state.paused'), 'warn'], done: [t('admin.state.done'), 'ok'], stopped: [t('admin.state.stopped'), ''],
  failed: [t('admin.state.failed'), 'bad'], accepted: [t('admin.state.accepted'), 'ok'], issued: [t('admin.state.issued'), 'info'], submitted: [t('admin.state.submitted'), 'info'],
  returned: [t('admin.state.returned'), 'warn'], cancelled: [t('admin.state.cancelled'), ''], queued: [t('admin.state.queued'), 'info'], proposal: [t('admin.state.proposal'), 'warn'],
  applied: [t('admin.state.applied'), 'ok'], live: [t('admin.state.live'), 'ok'], expired: [t('admin.state.expired'), ''], revoked: [t('admin.state.revoked'), 'bad'],
};

/* Состояние пропуска — код live | expired | revoked. Прежний сервер отдаёт русское
   слово: принимаем и его, чтобы подписи и фильтры не зависели от языка. */
const TOKEN_WORDS = { живой: 'live', истёк: 'expired', отозван: 'revoked' };
export const tokenState = (state) => TOKEN_WORDS[state] || state;

export const stateWord = (state) => (STATES[state] ? STATES[state][0] : state || '—');

/** Метка состояния прогона, шага, задания или пропуска. */
export function badge(state, text = null) {
  const [word, cls] = STATES[state] || [state || '—', ''];
  return el('span', 'adm-badge ' + cls, text ?? word);
}

let toastTimer = null;
export function toast(text, bad = false) {
  const node = document.getElementById('adm-toast');
  node.textContent = text;
  node.classList.toggle('bad', bad);
  node.hidden = false;
  clearTimeout(toastTimer);
  toastTimer = setTimeout(() => { node.hidden = true; }, 4500);
}

/**
 * Своё окно подтверждения (системный confirm блокирует страницу).
 * input — поле ввода ({label, value, type}); secret — показать строку крупно для копирования.
 * Отдаёт: false — отмена; true — да; строку — значение поля.
 */
export function confirmBox({ title, text = '', ok = t('admin.core.yes'), danger = false, input = null, secret = null, cancel = t('common.cancel') }) {
  return new Promise((resolve) => {
    const field = input ? el('input', 'input') : null;
    if (field) { field.value = input.value || ''; field.type = input.type || 'text'; field.placeholder = input.placeholder || ''; }
    const yes = el('button', 'btn ' + (danger ? 'btn-danger' : 'btn-accent'), ok);
    const no = cancel ? el('button', 'btn btn-quiet', cancel) : null;
    const card = el('div', 'adm-modal-card', [
      el('h3', '', title),
      text ? el('p', '', text) : null,
      secret ? el('div', 'adm-secret', secret) : null,
      field ? el('label', 'field', [el('span', '', input.label || ''), field]) : null,
      el('div', 'row', [no, yes]),
    ]);
    const modal = el('div', 'adm-modal', card);
    const close = (value) => { modal.remove(); resolve(value); };
    yes.onclick = () => close(field ? field.value : true);
    if (no) no.onclick = () => close(false);
    modal.onclick = (event) => { if (event.target === modal) close(false); };
    modal.onkeydown = (event) => {
      if (event.key === 'Escape') close(false);
      if (event.key === 'Enter' && field) close(field.value);
    };
    document.body.append(modal);
    (field || yes).focus();
  });
}

/** Адрес схемы в редакторе. */
export const editorUrl = (key, folder = null) => `index.php#p=${encodeURIComponent(key)}${folder ? '&f=' + folder : ''}`;

/** Ссылка, открывающаяся в новой вкладке. */
export function outLink(text, href) {
  const a = el('a', 'adm-link', text);
  a.href = href;
  a.target = '_blank';
  a.onclick = (event) => event.stopPropagation();
  return a;
}

/** Запомнить в адресе, что открыто: раздел и карточка (без перерисовки страницы). */
export function remember(section, id = null) {
  history.replaceState(null, '', '#' + section + (id ? '/' + id : ''));
}
