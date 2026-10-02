/* Полоса прогона: показывает server state/steps/paint.
   Отдаёт: initRunbar(), mountRunbar(), parkRunbar().
   Не делает: ничего не пишет. Прогон ведёт leader; из браузера у человека две
   ручные команды — «Остановить прогон» (panel/tabs.js) и состояние шага в
   карточке (panel/panel.js).

   Здесь же — номер и состояние показанного прогона в углу холста (#run-chip):
   какой прогон на экране и идёт ли он, видно, не открывая панель. */

import { state, on, emit } from 'goblin/core/state.js';
import { STEP_MARKS, STEP_WORDS, RUN_WORDS, RUN_MARKS } from 'goblin/core/kinds.js';
import { t } from 'goblin/core/i18n.js';
import 'goblin/run/follow.js';   // кнопка «Следить» у плашки и слежение холста

let inPanel = false;

export function mountRunbar(holder) {
  const bar = document.getElementById('runbar');
  if (!bar) return;
  inPanel = true;
  bar.classList.add('in-panel');
  holder.append(bar);
  bar.hidden = false;
  document.body.classList.remove('run-open');
}

export function parkRunbar() {
  const bar = document.getElementById('runbar');
  if (!bar) return;
  inPanel = false;
  bar.classList.remove('in-panel');
  document.body.append(bar);
  bar.hidden = true;
  document.body.classList.remove('run-open');
}

export function initRunbar() {
  const bar = document.getElementById('runbar');
  const track = document.getElementById('run-track');
  const stateLabel = document.getElementById('run-state');
  const count = document.getElementById('run-count');
  const chip = document.getElementById('run-chip');
  // Щелчок прячет плашку до смены прогона или его состояния.
  if (chip) chip.onclick = () => { chipHidden = chipKey(state.run); chip.hidden = true; };

  const drawRun = () => {
    bar.hidden = !inPanel;
    document.body.classList.remove('run-open');
    stateLabel.textContent = state.run ? RUN_WORDS[state.run.state] || state.run.state : '';
    const wait = document.getElementById('run-wait');
    const said = state.runState?.run?.waitFor || '';
    if (wait) { wait.textContent = said; wait.hidden = !said; }
    drawChip(chip);
  };

  const drawSteps = () => {
    track.textContent = '';
    const steps = [...state.steps.values()].sort((a, b) => a.no - b.no);
    for (const step of steps) {
      const chip = document.createElement('button');
      chip.className = 'run-step';
      chip.dataset.state = step.state;
      const who = state.agents.find((item) => item.id === step.agent);
      chip.innerHTML = `<b>${step.no}</b> ${STEP_MARKS[step.state] || '·'} ${escape(step.title || '')}`
        + (who ? `<i class="run-step-who">${escape(who.name)}</i>` : '');
      chip.title = [STEP_WORDS[step.state] || step.state, who ? t('editor.runbar.executor', { name: who.name }) : '',
                    step.result || step.error || ''].filter(Boolean).join(' · ');
      chip.onclick = () => {
        const element = [...state.elements.values()].find((one) => one.no === step.no);
        if (!element) return;
        state.selection.clear();
        state.selection.add(element.id);
        emit('selection');
      };
      track.append(chip);
    }
    const done = steps.filter((step) => step.state === 'accepted').length;
    count.textContent = steps.length ? t('editor.runbar.accepted_count', { done, total: steps.length }) : '';
  };

  on('run', drawRun);
  on('runstate', drawRun);
  on('steps', drawSteps);
  drawRun();
  drawSteps();
}

/* Какую плашку человек спрятал щелчком: прогон и его состояние. Сменились — плашка снова видна. */
let chipHidden = '';
const chipKey = (run) => run ? run.id + ':' + run.state : '';

/** Угол холста: «● r12» цветом состояния прогона, слово состояния — при наведении (css/run.css, .run-chip). */
function drawChip(chip) {
  if (!chip) return;
  const run = state.run;
  // Состояние прогона — и холсту: в паузе и у закрытого «работа» не движется (css/run.css).
  const wrap = document.getElementById('canvas-wrap');
  if (wrap && run) wrap.dataset.run = run.state;
  else if (wrap) delete wrap.dataset.run;
  chip.hidden = !run || chipKey(run) === chipHidden;
  if (!run) return;
  chip.dataset.run = run.state;
  chip.innerHTML = `<i>${RUN_MARKS[run.state] || '·'}</i><b>r${escape(run.no)}</b><span>${escape(RUN_WORDS[run.state] || run.state)}</span>`;
}

function escape(text) {
  return String(text).replace(/[&<>]/g, (ch) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;' }[ch]));
}
