/* На скольких блоках стоит агент.
   Отдаёт: agentUsed().
   Не делает: не ходит на сервер — считает по тому, что уже в состоянии.

   Открытая папка считается по живым карточкам на экране, остальные — по
   числам от сервера (agent.used: id папки => сколько). Иначе счётчик отставал
   бы до следующей загрузки проекта: назначения меняются прямо на холсте. */

import { state } from 'goblin/core/state.js';

export function agentUsed(agent) {
  if (!agent) return 0;
  const here = state.folder?.id ?? null;
  let total = 0;
  for (const [folder, many] of Object.entries(agent.used || {})) {
    if (Number(folder) !== here) total += Number(many) || 0;
  }
  for (const element of state.elements.values()) if (element.agent === agent.id) total += 1;
  return total;
}
