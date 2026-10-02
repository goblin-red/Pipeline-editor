/* Экран встроенного ИИ: чат по схеме, проверка проигрыванием.
   Отдаёт: initAi(), toggleAi(), openChat(), recentChats(), forgetChat(), play(), stopPlay(), stop().
   Новая схема по опросу — окно «Новая схема» (shell/newscheme.js), а не этот чат.
   Не делает: сам ничего не меняет — правки применяются только по кнопке.

   ИИ не управляет прогоном: он только рисует и правит схему. */

import { state, on, emit } from 'goblin/core/state.js';
import * as api from 'goblin/api/client.js';
import { loadFolder } from 'goblin/api/sync.js';
import { toast } from 'goblin/shell/topbar.js';
import { escape, chatLine } from 'goblin/ui/rich.js';
import { openNewScheme } from 'goblin/shell/newscheme.js';
import { dictate, stopDictation, dropDictation, isDictating, isStarting, onDictationLost, speak, stopSpeaking, voiceEngine } from 'goblin/panel/voice.js';
import { t } from 'goblin/core/i18n.js';

let screen, log, input, chatId = null, timer = null;
/* Предел ожидания ответа: свой handle, иначе старый предел гасил опрос нового вопроса. */
let timerCap = null;
/* Папка, к которой относится экран помощника: разговор живёт в папке, где его
   начали. Перешли в другую — экран переходит за ней (followFolder). */
let aiFolder = null;
/* Смотреть только открытую папку или весь проект — переключается значком
   и держится до конца разговора. */
let wholeProject = false;
/* Задание, которое сейчас считается: его и прерывает кнопка «Стоп». */
let busyJob = null;
// Ждём ответ на только что заданный вопрос: его и читаем вслух (если включён динамик), один раз.
let awaitingReply = false, spokenJob = null;
const refreshedJobs = new Set();
/* Тест-прогон: номер текущего проигрывания и идёт ли оно. Остановка
   просто меняет номер — запоздалый ответ узнаёт, что он уже чужой. */
let playing = 0, playBusy = false, playWaitOff = null;
/* Когда начали ждать ответ: по нему в строке ожидания бегут секунды. */
let waitStart = 0;

/* Частые команды: обычные просьбы, которые не хочется набирать заново. */
const QUICK = [
  t('editor.ai.quick_1'),
  t('editor.ai.quick_2'),
  t('editor.ai.quick_3'),
  t('editor.ai.quick_4'),
  t('editor.ai.quick_5'),
  t('editor.ai.quick_6'),
  t('editor.ai.quick_7'),
  t('editor.ai.quick_8'),
  t('editor.ai.quick_9'),
  t('editor.ai.quick_10'),
  t('editor.ai.quick_11'),
];

export function initAi() {
  screen = document.getElementById('screen-ai');
  log = document.getElementById('ai-log');
  input = document.getElementById('ai-input');

  // Кнопки «ИИ» в шапке больше нет: помощника открывает вкладка ✦ правой панели.
  document.getElementById('btn-ai')?.addEventListener('click', toggleAi);
  /* Своей кнопки закрытия у помощника нет: он живёт вкладкой правой
     панели и закрывается переходом на любую другую вкладку. */
  on('panel-tab', (tab) => { if (tab !== 'ai') toggleAi(false); });
  // Чаты у каждой папки свои: перешли в другую — помощник переходит за ней.
  on('folder', followFolder);
  document.getElementById('ai-send').onclick = () => send();
  wireMic();
  // Кнопки «Проиграть» в чате нет: она не помещалась рядом с «Спросить».
  // Проигрывание запускается пунктом «Тест-прогон» в меню быстрых команд (wireTools).
  document.getElementById('ai-stop').onclick = stop;
  wireTools();
  // Enter отправляет, Shift+Enter переносит строку — как в любом чате.
  input.onkeydown = (event) => {
    if (event.key !== 'Enter' || event.shiftKey) return;
    event.preventDefault();
    send();
  };
}

/* Ряд значков под полем: каждый — короткий ход, а не отдельный экран. */
function wireTools() {
  const drop = document.getElementById('ai-drop');
  const hide = () => { drop.hidden = true; drop.textContent = ''; };
  const show = (rows) => {
    drop.textContent = '';
    for (const row of rows) {
      const item = document.createElement('button');
      item.type = 'button';
      item.textContent = row.title;
      item.onclick = () => { hide(); row.run(); };
      drop.append(item);
    }
    drop.hidden = !rows.length;
  };

  document.getElementById('ai-quick').onclick = () => {
    if (!drop.hidden) return hide();
    show([
      ...QUICK.map((text) => ({ title: text, run: () => { input.value = text; input.focus(); } })),
      // Тест-прогон: схема проигрывается на выдуманных ответах, ничего не записав (ai.play).
      { title: t('editor.ai.play_menu'), run: play },
      { title: t('editor.ai.build_menu'), run: () => openNewScheme('builder') },
    ]);
  };

  document.getElementById('ai-new').onclick = () => { hide(); openChat(null); input.focus(); };

  document.getElementById('ai-history').onclick = async () => {
    if (!drop.hidden) return hide();
    const chats = await recentChats(12);
    show(chats.length
      ? chats.map((chat) => ({ title: `${chat.title || t('editor.tabs.chat')} · ${String(chat.at || '').slice(0, 16)}`,
                               run: () => openChat(chat.id) }))
      : [{ title: t('editor.tabs.no_chats'), run: () => {} }]);
  };

  // Ссылка на разговор: страница chat.php — весь журнал разговора; копируем и сразу открываем.
  document.getElementById('ai-link').onclick = () => {
    if (!chatId) { toast(t('editor.ai.ask_first'), true); return; }
    const link = new URL('chat.php?project=' + encodeURIComponent(api.projectKey()) + '&chat=' + chatId, location.href).href;
    navigator.clipboard?.writeText(link).catch(() => {});
    window.open(link, '_blank', 'noopener');
    toast(t('editor.ai.link_copied'));
  };

  // Конструктор — окно «Новая схема», вкладка «Конструктор».
  document.getElementById('ai-build').onclick = () => { hide(); openNewScheme('builder'); };

  // Охват — переключатель «Папка | Проект»: что помощник видит, открытую папку или весь проект.
  const scope = document.getElementById('ai-scope');
  const drawScope = () => {
    for (const item of scope.querySelectorAll('[data-scope]')) {
      item.classList.toggle('on', (item.dataset.scope === 'project') === wholeProject);
    }
  };
  for (const item of scope.querySelectorAll('[data-scope]')) {
    item.onclick = () => { wholeProject = item.dataset.scope === 'project'; drawScope(); };
  }
  drawScope();
}

/** Открыть на экране помощника прежний разговор. */
export function openChat(id) {
  chatId = id || null;
  awaitingReply = false;   // открыли другой разговор — его прошлые ответы вслух не читаем
  // Лента чистится сразу: иначе «новый разговор» открывался с чужими
  // сообщениями на экране, пока не придёт первый ответ.
  if (log) log.textContent = '';
  toggleAi(true);
}

/** Последние разговоры открытой папки: свежие сверху. */
export async function recentChats(limit = 5) {
  if (!state.folder) return [];
  try {
    const answer = await api.get('ai.chat.get', { folder: state.folder.id });
    return (answer.chats || []).slice(0, limit);
  } catch { return []; }
}

/* Открыта другая папка: разговор прежней остаётся в её истории, экран чистый —
   следующий вопрос начнёт разговор уже этой папки. */
function followFolder() {
  const now = state.folder?.id ?? null;
  if (now === aiFolder) return;
  aiFolder = now;
  clearScreen();
}

/** Разговор удалили из истории: был открыт на экране — экран чистый. */
export function forgetChat(id) {
  if (chatId === id) clearScreen();
}

/* Экран без разговора: следующий вопрос начнёт новый. */
function clearScreen() {
  stopPlay();
  hush();
  chatId = null;
  stopWatch();
  busyJob = null;
  document.getElementById('ai-stop').hidden = true;
  const drop = document.getElementById('ai-drop');
  drop.hidden = true;
  drop.textContent = '';
  document.getElementById('ai-usage').textContent = t('editor.ai.usage');
  log.textContent = '';
}

/** Показать или спрятать экран помощника. Спрятали — микрофон и чтение вслух выключаются. */
export function toggleAi(force) {
  if (!screen) return;
  screen.hidden = force === undefined ? !screen.hidden : !force;
  document.body.classList.toggle('ai-open', !screen.hidden);
  if (!screen.hidden) { input.focus(); refresh(); }
  else hush();
}

/* Замолчать: диктовка обрывается без итога (и та, что ещё подключается), ответ вслух стихает. Разговор голосом кончается. */
function hush() {
  talking = false;
  dropDictation();
  stopSpeaking();
  document.getElementById('ai-mic')?.classList.remove('on', 'wait');
}

/* Читать ли ответы вслух — кнопка-динамик рядом с микрофоном, своя опция, не зависит от микрофона:
   включена — звучит каждый новый ответ, как бы ни был задан вопрос. По умолчанию выключена; выбор помнит браузер. */
let readAloud = false;
try { readAloud = localStorage.getItem('goblin-ai-read') === '1'; } catch {}

/* Ключевые слова: «Приём» или «Roger» в конце диктовки отправляет вопрос (panel/voice.js) — значок рации
   рядом с микрофоном. По умолчанию включены; выбор помнит браузер. */
let codeWords = true;
try { codeWords = localStorage.getItem('goblin-ai-words') !== '0'; } catch {}

/* Разговор голосом: вопрос ушёл кодовым словом — микрофон гаснет, ответ звучит (если включён динамик),
   затем микрофон включается сам. Кончается, когда человек выключил микрофон руками, выключил ключевые
   слова, нажал «Стоп» или ушёл с вкладки (hush). */
let talking = false;

/** Включить микрофон: пока соединяемся — приглушён; загорелся красным — можно говорить. */
async function listenMic() {
  const mic = document.getElementById('ai-mic');
  mic.classList.add('wait');
  try {
    // Кодовое слово сказано: диктовка уже закончена, слово убрано из поля — отправляем и ждём ответ.
    const onWord = codeWords ? () => {
      mic.classList.remove('wait', 'on');
      talking = true;
      if (input.value.trim()) send();
      else listenAgain();   // сказал одно «Приём» — спрашивать нечего, слушаем дальше
    } : null;
    // false — оборвали, пока подключались (отправили вопрос, ушли с вкладки): гореть нечему.
    if (await dictate(input, { onWord })) mic.classList.replace('wait', 'on');
    else mic.classList.remove('wait', 'on');
  } catch (error) {
    micFailed(error);
  }
}

/** Ответ на вопрос, заданный голосом, прозвучал (или пришёл без чтения вслух) — снова слушаем. */
function listenAgain() {
  if (!talking || !codeWords || screen?.hidden || isDictating() || isStarting()) return;
  listenMic();
}

/** Микрофон не заработал или браузер перестал слушать: кнопка гаснет, человеку — причина. */
function micFailed(error) {
  talking = false;
  document.getElementById('ai-mic')?.classList.remove('wait', 'on');
  const denied = error.name === 'NotAllowedError' || /permission/i.test(error.message);
  toast(denied ? t('editor.ai.mic_denied') : t('editor.ai.mic_failed', { why: error.message }), true);
}

/* Микрофон: первое нажатие — диктовка по ходу речи, второе — стоп; сказанное остаётся в поле, отправляет «Спросить» (panel/voice.js). */
function wireMic() {
  const words = document.getElementById('ai-words');
  if (words) {
    words.classList.toggle('on', codeWords);
    words.onclick = () => {
      codeWords = !codeWords;
      if (!codeWords) talking = false;
      words.classList.toggle('on', codeWords);
      try { localStorage.setItem('goblin-ai-words', codeWords ? '1' : '0'); } catch {}
    };
  }
  // Подсказка при наведении: чей голос работает — браузера или OpenAI (админка → «Голос»).
  const via = t('editor.ai.via_' + voiceEngine());
  const read = document.getElementById('ai-read');
  if (read) {
    read.title = t('editor.ai.read_aloud') + ' — ' + via;
    read.classList.toggle('on', readAloud);
    read.onclick = () => {
      readAloud = !readAloud;
      read.classList.toggle('on', readAloud);
      try { localStorage.setItem('goblin-ai-read', readAloud ? '1' : '0'); } catch {}
      if (!readAloud) stopSpeaking();
    };
  }
  const mic = document.getElementById('ai-mic');
  if (!mic) return;
  mic.title = t('editor.ai.mic') + ' — ' + via;
  // Браузер перестал слушать сам (сеть, микрофон): кнопка гаснет, человеку — причина.
  onDictationLost(micFailed);
  mic.onclick = async () => {
    // Выключил руками — разговор голосом кончился: после ответа микрофон сам не включится.
    if (isDictating()) {
      talking = false;
      mic.classList.replace('on', 'wait');
      await stopDictation();
      mic.classList.remove('wait');
      input.focus();   // текст остаётся в поле: отправляет только «Спросить» (или Enter)
      return;
    }
    // Нажали ещё раз, пока микрофон подключается, — передумали: подключение обрываем.
    if (isStarting()) { hush(); return; }
    listenMic();
  };
}

async function send() {
  const text = input.value.trim();
  if (!text) return;
  /* Отправили, пока микрофон слушал или ещё подключался: диктовку обрываем, иначе поздние
     слова (или подключение, закончившееся после отправки) вернули бы текст в поле. */
  if (isDictating() || isStarting()) {
    dropDictation();
    document.getElementById('ai-mic')?.classList.remove('on', 'wait');
  }
  awaitingReply = true;
  input.value = '';
  line(t('editor.ai.you'), text);
  waitStart = Date.now();
  waitLine();

  const folder = aiFolder;
  try {
    // Выделенное на холсте — номерами: «сделай ТЗ для выделенного» без этого не понять.
    const selected = [...state.selection].map((id) => state.elements.get(id)?.no).filter(Number.isFinite);
    const answer = await api.post('ai.chat.send', {
      // Папка — всегда: разговор живёт в ней и виден в её истории, даже когда ИИ смотрит весь проект.
      text, chat: chatId, folder: state.folder?.id,
      ...(wholeProject ? { whole: 1 } : { selected }),
    });
    // Пока вопрос уходил, открыли другую папку: разговор остался в прежней.
    if (aiFolder !== folder) return;
    chatId = answer.chat;
    watch();
  } catch (error) {
    if (aiFolder === folder) line(t('editor.ai.ai'), t('editor.ai.failed', { why: error.message }), 'bad');
  }
}

/** Ответ приходит фоном: спрашиваем сервер, пока задание не закроется. */
function watch() {
  stopWatch();
  timer = setInterval(refresh, 1500);
  // Модель думает до 3 минут, с повтором — до 6: ждём с запасом, дальше сервер закроет задание сам.
  timerCap = setTimeout(stopWatch, 420000);
}

/** Перестать спрашивать сервер: и опрос, и его предел — предел прошлого вопроса не гасит новый. */
function stopWatch() {
  clearInterval(timer);
  clearTimeout(timerCap);
  timer = timerCap = null;
}

async function refresh() {
  if (!chatId) return;
  // Идёт тест-прогон — его строки живут только в браузере, перерисовка их сотрёт.
  if (playBusy) return;
  const asked = chatId;
  const answer = await api.get('ai.chat.get', { chat: asked });
  // Пока ждали ответ, открыли другой разговор или другую папку — этот уже не на экране.
  if (chatId !== asked) return;
  const spent = answer.tokens || { in: 0, out: 0 };
  const usage = document.getElementById('ai-usage');
  if (usage) usage.textContent = t('editor.ai.usage_n', { in: spent.in, out: spent.out });
  const job = answer.jobs?.[0];
  // Пока ответ считается, рядом с «Спросить» стоит «Стоп».
  busyJob = job && ['queued', 'running'].includes(job.state) ? job.id : null;
  const stopButton = document.getElementById('ai-stop');
  if (stopButton) stopButton.hidden = !busyJob;

  log.textContent = '';
  for (const message of answer.messages) {
    line(message.role === 'user' ? t('editor.ai.you') : t('editor.ai.ai'), message.body, '', message.tokens, message.think || '');
  }
  /* Список рисуется заново с сервера, поэтому строку ожидания рисуем тут же:
     иначе она пропадала через полторы секунды и казалось, что ничего не идёт. */
  if (busyJob) waitLine(job.state === 'queued' ? t('editor.ai.queued') : t('editor.ai.thinking'));

  if (!job) return;
  if (job.state === 'applied' && !refreshedJobs.has(job.id)) {
    refreshedJobs.add(job.id);
    if (job.ops?.some(op => op.op === 'run.prepare' && Number(op.folder) === state.folder?.id)) api.setHash({ run: null });
    await loadFolder(state.folder?.id);
  }
  if (job.state === 'failed') {
    awaitingReply = false;
    line(t('editor.ai.ai'), t('editor.ai.failed', { why: job.error || '' }), 'bad');
    stopWatch();
    listenAgain();
    return;
  }
  // Ответ на только что заданный вопрос готов — читаем вслух, если включён динамик.
  if (awaitingReply && ['done', 'applied', 'proposal'].includes(job.state) && spokenJob !== job.id) {
    awaitingReply = false;
    spokenJob = job.id;
    const reply = [...answer.messages].reverse().find((message) => message.role !== 'user');
    // Спрашивали голосом — после ответа микрофон включается сам: когда ответ прозвучал или сразу, если динамик выключен.
    if (reply && readAloud) {
      speak(reply.body, { onDone: listenAgain })
        .catch((error) => { toast(t('editor.ai.speak_failed', { why: error.message }), true); listenAgain(); });
    } else {
      listenAgain();
    }
  }
  if (job.state === 'proposal') {
    stopWatch();
    const plan = document.createElement('div');
    plan.className = 'ai-plan';
    plan.innerHTML = `<b>${t('editor.ai.plan')}</b><ul>`
      + (job.preview || []).map((line) => `<li>${escape(line)}</li>`).join('') + '</ul>';

    const apply = document.createElement('button');
    apply.className = 'btn btn-accent';
    apply.textContent = t('editor.ai.apply');
    apply.onclick = async () => {
      apply.disabled = true;
      try {
      const result = await api.post('ai.chat.apply', { job: job.id });
      if (result.results?.some(item => item.prepared && item.id === state.folder?.id)) api.setHash({ run: null });
      await loadFolder(state.folder?.id);
      refreshedJobs.add(job.id);
      toast(t('editor.ai.applied'));
      await refresh();
      } catch (error) {
        line(t('editor.ai.ai'), t('editor.ai.not_applied', { why: error.message }), 'bad');
      } finally { apply.disabled = false; }
    };
    const cancel = document.createElement('button');
    cancel.className = 'btn btn-quiet';
    cancel.textContent = t('editor.ai.cancel');
    cancel.onclick = async () => { await api.post('ai.chat.cancel', { job: job.id }); refresh(); };

    plan.append(apply, cancel);
    log.append(plan);
  }
  if (job.state === 'applied' || job.state === 'done') stopWatch();
  log.scrollTop = log.scrollHeight;
}

/** Прервать ответ: задание снимается, разговор остаётся. Тест-прогон гасится сразу. */
export async function stop() {
  talking = false;
  if (playBusy) { stopPlay(); return; }
  if (!busyJob) return;
  const job = busyJob;
  busyJob = null;
  document.getElementById('ai-stop').hidden = true;
  stopWatch();
  try { await api.post('ai.chat.cancel', { job }); } catch {}
  toast(t('editor.ai.stopped'));
  refresh();
}

/** Проигрывание (тест-прогон): пройти схему на выдуманных исходах, ничего не записав. */
export async function play() {
  if (!state.folder) return;
  const mine = ++playing;
  playBusy = true;
  line(t('editor.ai.you'), t('editor.ai.play_say'));
  // Проигрывание идёт одним запросом: своя строка ожидания и своя кнопка «Стоп».
  playWaitOff = startWait(t('editor.ai.playing'));
  const stopButton = document.getElementById('ai-stop');
  if (stopButton) stopButton.hidden = false;
  try {
    const answer = await api.post('ai.play', { folder: state.folder.id });
    if (mine !== playing) return;   // остановили, пока считалось
    const trace = (answer.play.trace || [])
      .map((step) => `- ${step.no} — ${step.say}${step.issue ? ` **⚠ ${step.issue}**` : ''}`).join('\n');
    // Сначала — что нашла точная предпроверка сервера, потом трасса модели, вердикт и что поправить.
    const found = (answer.problems || []).length
      ? t('editor.ai.precheck', { list: answer.problems.map((one) => `- ${one}`).join('\n') }) : '';
    const fixes = (answer.play.fixes || []).filter(Boolean);
    line(t('editor.ai.ai'), t('editor.ai.verdict', { found, trace, verdict: answer.play.verdict, why: answer.play.why })
      + (fixes.length ? t('editor.ai.fixes', { list: fixes.map((one) => `- ${one}`).join('\n') }) : ''),
      '', null, answer.think || '');
  } catch (error) {
    if (mine === playing) line(t('editor.ai.ai'), t('editor.ai.failed', { why: error.message }), 'bad');
  } finally {
    if (mine === playing) { endPlayWait(); playBusy = false; }
  }
}

/** Убрать строку ожидания тест-прогона и спрятать «Стоп». */
function endPlayWait() {
  playWaitOff?.();
  playWaitOff = null;
  const stopButton = document.getElementById('ai-stop');
  if (stopButton && !busyJob) stopButton.hidden = true;
}

/**
 * Остановить тест-прогон. Отдаёт true, если он шёл.
 *
 * Проигрывание — один запрос ai.play: сервер его досчитает, но ответ
 * браузер уже не покажет. Настоящего прогона тест не касается.
 */
export function stopPlay() {
  if (!playBusy) return false;
  playing++;
  playBusy = false;
  endPlayWait();
  line(t('editor.ai.ai'), t('editor.ai.play_stopped'), 'bad');
  return true;
}

/** Строка ожидания: «думает… 12 с». Секунды идут от начала ожидания. */
function waitLine(word = t('editor.ai.thinking')) {
  const sec = waitStart ? Math.round((Date.now() - waitStart) / 1000) : 0;
  line(t('editor.ai.ai'), t('editor.ai.wait', { word, sec }), 'wait');
  return log.lastElementChild;
}

/**
 * Своя строка ожидания с бегущими секундами — для тест-прогона: он идёт
 * одним запросом, без опроса сервера, и обновлять её больше некому.
 * Отдаёт: убрать строку.
 */
function startWait(word) {
  waitStart = Date.now();
  const node = waitLine(word);
  const tick = setInterval(() => {
    const sec = Math.round((Date.now() - waitStart) / 1000);
    const span = node.querySelector('span');
    if (span) span.textContent = t('editor.ai.wait', { word, sec });
  }, 1000);
  return () => { clearInterval(tick); node.remove(); };
}

function line(who, text, kind = '', tokens = null, think = '') {
  log.append(chatLine(who, text, { mine: who === t('editor.ai.you'), kind, tokens, think }));
  log.scrollTop = log.scrollHeight;
}
