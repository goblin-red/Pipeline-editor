/* Вид «Прогон»: экран наблюдения за работой.
   Отдаёт: mount(), unmount().
   Не делает: ничего не ведёт — прогон ведёт агент-leader. Человек смотрит,
              может поставить паузу и остановить.

   Зачем: во время прогона от холста толку мало — важно, что сделано,
   что сейчас в работе, чего ждём и какие результаты уже приняты. */

import { state, on, emit, stepOf } from 'goblin/core/state.js';
import { STEP_MARKS, STEP_WORDS, RUN_WORDS, KINDS, iconOf } from 'goblin/core/kinds.js';
import { runLogFull } from 'goblin/shell/runlog.js';
import { t } from 'goblin/core/i18n.js';

let root = null;
let off = [];

export function mount() {
  document.getElementById('canvas-wrap').hidden = true;
  document.getElementById('rail').hidden = false;

  root = document.createElement('section');
  root.className = 'ui-run';
  root.innerHTML = `
    <header class="ui-run-head">
      <h2 id="run-title">${t('editor.run.none')}</h2>
      <span class="muted" id="run-when"></span>
      <div class="row" id="run-actions"></div>
    </header>
    <div class="ui-run-grid" id="run-grid"></div>
    <h3 class="ui-run-sub">${t('editor.run.log')}</h3>
    <div class="ui-run-log" id="run-log"></div>`;
  document.querySelector('.workspace').insertBefore(root, document.getElementById('panel'));

  /* В центре — только выбранный прогон: шаги и его журнал. Архив всех
     прогонов живёт в левой плашке, чтобы не мешать чтению этого. */
  const stopLog = runLogFull(root.querySelector('#run-log'));

  off = [on('steps', render), on('run', render), on('scheme', render), stopLog];
  render();
}

export function unmount() {
  for (const stop of off) stop();
  off = [];
  root?.remove();
  root = null;
}

function render() {
  if (!root) return;
  const run = state.run;
  root.querySelector('#run-title').textContent = run
    ? t('editor.run.title', { no: run.no, state: RUN_WORDS[run.state] || run.state })
    : t('editor.run.none_hint');
  // Полная дата начала — как в шапке журнала: «22.09.2026 11:00:54».
  const at = String(run?.startedAt || '');
  root.querySelector('#run-when').textContent = at
    ? t('editor.run.started', { at: `${at.slice(8, 10)}.${at.slice(5, 7)}.${at.slice(0, 4)} ${at.slice(11, 19)}` }) : '';

  const actions = root.querySelector('#run-actions');
  actions.textContent = '';
  if (run && ['running', 'paused'].includes(run.state)) actions.textContent = t('editor.run.driven_by');

  // Карточки шагов по порядку номеров: сделанное, текущее, ожидающее.
  const grid = root.querySelector('#run-grid');
  grid.textContent = '';
  const elements = [...state.elements.values()]
    .filter((element) => ['block', 'decision', 'gateway'].includes(element.type))
    .sort((a, b) => a.no - b.no);

  for (const element of elements) {
    const step = stepOf(element);
    const card = document.createElement('article');
    card.className = 'ui-step-card';
    card.dataset.state = step ? step.state : 'none';

    const agent = state.agents.find((a) => a.id === (step?.agent ?? element.agent));
    card.innerHTML = `
      <header><b class="mono">${element.no}</b> ${iconOf(element)}
        <span>${escape(element.title || KINDS[element.type]?.title || '')}</span></header>
      <p class="ui-step-state">${step ? `${STEP_MARKS[step.state]} ${STEP_WORDS[step.state]}` : '· ' + t('editor.kinds.step_none')}</p>
      ${agent ? `<p class="muted">🤖 ${escape(agent.name)}</p>` : ''}
      ${step?.result ? `<p class="ui-result">${escape(step.result)}</p>` : ''}
      ${step?.error ? `<p class="ui-error">${escape(step.error)}</p>` : ''}
      ${step ? `<p class="muted mono">${when(step)}</p>` : ''}`;

    card.onclick = () => { state.selection.clear(); state.selection.add(element.id); emit('selection'); };
    grid.append(card);
  }
}

function when(step) {
  if (!step.opened) return '';
  const from = new Date(step.opened.replace(' ', 'T'));
  const to = step.closed ? new Date(step.closed.replace(' ', 'T')) : new Date();
  const seconds = Math.max(0, Math.round((to - from) / 1000));
  const shown = seconds < 60 ? t('editor.run.seconds', { n: seconds }) : t('editor.run.minutes', { n: Math.round(seconds / 60) });
  return `${step.opened.slice(11, 16)} · ${shown}${step.attempt > 1 ? ` · ${t('editor.canvas.attempt_n', { n: step.attempt })}` : ''}`;
}

const escape = (text) => String(text).replace(/[&<>]/g, (ch) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;' }[ch]));
