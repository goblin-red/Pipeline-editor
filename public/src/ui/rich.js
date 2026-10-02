/* Разметка ответов ИИ: заголовки, списки, жирное, курсив, код — в HTML; реплика разговора целиком.
   Отдаёт: rich(), escape(), chatLine().
   Не делает: не ходит в сеть и не знает, чей это текст: чат помощника, окно «Новая схема»
   и страница разговора (chat.php) зовут одно и то же. */

import { t } from 'goblin/core/i18n.js';

/**
 * Разметка ответа: заголовки, списки, жирное, код.
 *
 * Свой разбор вместо библиотеки: ИИ пишет простым языком разметки,
 * и трёх правил хватает, а лишний пакет в страницу тянуть незачем.
 */
export function rich(text) {
  const rows = String(text).split('\n');
  const out = [];
  let list = null;     // какой список открыт: ul или ol
  let code = null;     // строки внутри тройных кавычек

  const closeList = () => { if (list) { out.push(`</${list}>`); list = null; } };
  const openList = (kind) => { if (list !== kind) { closeList(); out.push(`<${kind}>`); list = kind; } };

  for (const row of rows) {
    if (row.trim().startsWith('```')) {
      if (code === null) { closeList(); code = []; } else { out.push(`<pre>${escape(code.join('\n'))}</pre>`); code = null; }
      continue;
    }
    if (code !== null) { code.push(row); continue; }

    const line = row.trim();
    if (!line) { closeList(); continue; }

    const head = line.match(/^(#{1,4})\s+(.*)$/);
    if (head) { closeList(); out.push(`<h4>${inline(head[2])}</h4>`); continue; }

    const bullet = line.match(/^[-–—*•]\s+(.*)$/);
    if (bullet) { openList('ul'); out.push(`<li>${inline(bullet[1])}</li>`); continue; }

    const number = line.match(/^(\d+)[.)]\s+(.*)$/);
    if (number) { openList('ol'); out.push(`<li>${inline(number[2])}</li>`); continue; }

    closeList();
    out.push(`<p>${inline(line)}</p>`);
  }
  if (code !== null) out.push(`<pre>${escape(code.join('\n'))}</pre>`);
  closeList();
  return out.join('');
}

/** Внутри строки: жирное, курсив и код. Всё остальное — просто текст. */
function inline(text) {
  return escape(text)
    .replace(/`([^`]+)`/g, '<code>$1</code>')
    .replace(/\*\*([^*]+)\*\*/g, '<b>$1</b>')
    .replace(/(^|\s)\*([^*]+)\*/g, '$1<i>$2</i>');
}

export function escape(text) {
  return String(text).replace(/[&<>]/g, (ch) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;' }[ch]));
}

/**
 * Реплика разговора с помощником: кто, текст (ответ ИИ — разметкой), ход мысли свёрнутым, расход.
 * Своя реплика и служебные строки (kind) — как есть: их пишет человек или сервер, не модель.
 */
export function chatLine(who, text, { mine = false, kind = '', tokens = null, think = '' } = {}) {
  const node = document.createElement('div');
  node.className = 'ai-line ' + (mine ? 'me' : 'it') + ' ' + kind;
  const body = mine || kind ? `<span>${escape(text)}</span>` : `<div class="ai-rich">${rich(text)}</div>`;
  node.innerHTML = `<b>${escape(who)}</b>${body}`;

  // Ход мысли — свёрнутым: обычно не нужен, но когда ответ странный — сразу видно почему.
  if (think) {
    const box = document.createElement('details');
    box.className = 'ai-think';
    const head = document.createElement('summary');
    head.textContent = t('editor.ai.reasoning');
    const words = document.createElement('div');
    words.textContent = think;
    box.append(head, words);
    node.append(box);
  }
  if (tokens) {
    const count = document.createElement('small');
    count.className = 'ai-tokens';
    count.textContent = t('editor.ai.tokens', { model: tokens.model || t('editor.ai.ai'), in: tokens.in, out: tokens.out })
      + (tokens.cached ? t('editor.ai.cached', { n: tokens.cached }) : '');
    node.append(count);
  }
  return node;
}
