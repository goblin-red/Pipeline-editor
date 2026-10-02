/* Левая плашка: архив прогонов папки.
   Что делает: рисует список прогонов; выбранный — тот, что показывают
   холст и панель.
   Отдаёт: initRuns().
   Не делает: не решает, когда раздел виден, — это left/left.js
   (при вкладке «Прогон» справа). */

import { runsList } from 'goblin/shell/runlog.js';

export function initRuns(holder) {
  if (holder) runsList(holder, true);
}
