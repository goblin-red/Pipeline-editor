/* Страница разговора (public/chat.php): реплики тем же видом, что в чате помощника.
   Отдаёт: ничего — рисует window.GOBLIN_CHAT в #chat-log при загрузке.
   Не делает: не ходит на сервер — разговор страница получает сразу. */

import { chatLine } from 'goblin/ui/rich.js';
import { t } from 'goblin/core/i18n.js';

const chat = globalThis.GOBLIN_CHAT || { messages: [] };
const log = document.getElementById('chat-log');

for (const message of chat.messages) {
  const mine = message.role === 'user';
  const node = chatLine(mine ? t('editor.ai.you') : t('editor.ai.ai'), message.body,
    { mine, tokens: message.tokens, think: message.think });
  const at = document.createElement('small');
  at.className = 'chat-at';
  at.textContent = message.at;
  node.append(at);
  log.append(node);
}
if (!chat.messages.length) log.textContent = t('editor.chatpage.empty');
