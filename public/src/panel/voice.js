/* Голос в чате помощника: живая диктовка в поле и чтение ответа вслух.
   Отдаёт: dictate(), stopDictation(), dropDictation(), isDictating(), isStarting(), onDictationLost(),
           speak(), stopSpeaking().
   Не делает: не знает ключей — с OpenAI сервер даёт временный пропуск на распознавание (voice.session)
   и готовый звук ответа (voice.speak), lib/ai/voice.php.

   Движок — настройка установки voice_engine (админка → «Голос»), страница кладёт её в window.GOBLIN_VOICE:
   browser — встроенные в браузер распознавание (SpeechRecognition) и озвучка (speechSynthesis), бесплатно;
   openai  — микрофон → WebRTC → OpenAI, слова приходят событиями; звук ответа готовит сервер.

   Диктовка: слова встают в поле по ходу речи на место каретки (fieldWriter). «Стоп» дожидается итога.
   Кодовое слово в конце речи — как в рации, на языке интерфейса: «Приём» по-русски, «Roger» по-английски.
   Слово убирается из поля, диктовка
   кончается, зовётся onWord — чат отправляет вопрос. Без onWord (значок выключен) слово — просто текст.

   Отмена — как у тест-прогона: номер вызова. dropDictation() и stopSpeaking() меняют номер,
   и запоздавшее подключение микрофона или звук ответа узнают, что они уже чужие. */

import * as api from 'goblin/api/client.js';
import { t } from 'goblin/core/i18n.js';

let live = null;     // идущая диктовка: { drop, stop }
let player = null;   // звучащий ответ (OpenAI)
let dictation = 0;   // номер последнего dictate(); отмена — новый номер
let starting = 0;    // номер подключения, которое ещё идёт; 0 — не подключаемся
let voiceCall = 0;   // номер последнего speak(); stopSpeaking() его меняет
let lost = null;     // кого позвать, когда диктовка оборвалась сама (сеть, микрофон)
let lines = [];      // звучащие фразы браузера: Chrome без ссылки на фразу теряет её «конец»

const engine = () => (globalThis.GOBLIN_VOICE === 'openai' ? 'openai' : 'browser');
export { engine as voiceEngine };

/* Кодовое слово — отдельным словом в самом конце («…сделай так, приём.»), одно на язык интерфейса:
   в русской версии «Приём», в английской «Roger». Перед ним — не буква и не цифра. */
const CODE_WORDS = {
  ru: /(^|[^\p{L}\p{N}])при[её]м[^\p{L}\p{N}]*$/iu,
  en: /(^|[^\p{L}\p{N}])roger(?: that)?[^\p{L}\p{N}]*$/iu,
};
const codeWord = () => CODE_WORDS[document.documentElement.lang] || CODE_WORDS.en;

/** Язык речи для браузера: ru → ru-RU, en → en-US. */
const speechLang = (lang) => ({ ru: 'ru-RU', en: 'en-US' })[lang] || navigator.language || 'ru-RU';

export const isDictating = () => !!live;
/** Микрофон подключается: говорить ещё нельзя, но отправка вопроса должна оборвать и это. */
export const isStarting = () => starting !== 0;

/** Браузер оборвал диктовку сам (сеть, микрофон отняли): fn(error) гасит кнопку и говорит причину. */
export function onDictationLost(fn) {
  lost = fn;
}

/**
 * Начать диктовку в поле: вернётся, когда можно говорить. Слова — по ходу речи; итог — после stopDictation().
 * Отдаёт false, если диктовку оборвали, пока микрофон подключался (dropDictation): поле не трогается.
 */
export async function dictate(field, { onWord = null } = {}) {
  stopSpeaking();
  const mine = ++dictation;
  starting = mine;
  try {
    return await (engine() === 'openai' ? connect(field, mine, onWord) : listen(field, mine, onWord));
  } finally {
    if (starting === mine) starting = 0;
  }
}

/* Текст до и после каретки остаётся, сказанное встаёт между ними. Поправил человек поле во время
   диктовки — его правка главнее: новая точка вставки — каретка, уже сказанное больше не рисуем
   (иначе стёртое возвращалось бы), а перебитую реплику берём только с места правки. */
function fieldWriter(field, gone) {
  let before = '', after = '', pad = '';
  const parts = new Map();    // реплика → её текст с последней точки вставки
  const edited = new Map();   // перебитая правкой реплика → сколько её букв было до правки
  let dropped = false;        // вопрос уже отправили — поле больше не трогаем

  const anchor = () => {
    before = field.value.slice(0, field.selectionStart ?? field.value.length);
    after = field.value.slice(field.selectionEnd ?? field.value.length);
    pad = before && !/\s$/.test(before) ? ' ' : '';
  };
  const said = () => [...parts.values()].join(' ').replace(/\s+/g, ' ').trim();
  const paint = (text = said()) => {
    if (dropped || gone()) return;
    field.value = before + (text ? pad + text : '') + after;
    const caret = (before + (text ? pad + text : '')).length;
    field.setSelectionRange(caret, caret);
    field.scrollTop = field.scrollHeight;
  };
  // Правку руками ловит только событие input: наши присваивания value его не зовут.
  const onEdit = () => {
    for (const [id, text] of parts) edited.set(id, (edited.get(id) || 0) + text.length);
    parts.clear();
    anchor();
  };
  anchor();
  field.addEventListener('input', onEdit);

  return {
    /** Кусочек реплики (OpenAI шлёт добавки). */
    add(id, delta) { parts.set(id, (parts.get(id) || '') + delta); paint(); },
    /** Реплика целиком (браузер шлёт её заново по ходу речи): перебитая — с места правки. */
    put(id, text) { parts.set(id, text.slice(edited.get(id) || 0)); paint(); },
    /** Итог реплики (OpenAI): перебитую не трогаем, иначе вернулось бы стёртое. */
    done(id, text) { if (!edited.has(id)) parts.set(id, text); paint(); },
    /** Сказанное уже в поле: дальше пишем после него (браузер начал слушать заново). */
    settle() { parts.clear(); anchor(); },
    /** Сказанное кончается кодовым словом. */
    ends() { return !dropped && codeWord().test(said()); },
    /** Убрать кодовое слово из поля (с запятой перед ним); дальше поле не трогаем. Отдаёт, было ли слово. */
    word() {
      if (!this.ends()) return false;
      paint(said().replace(codeWord(), '$1').replace(/[\s,;:—-]+$/u, ''));
      dropped = true;
      return true;
    },
    drop() { dropped = true; },
    close() { field.removeEventListener('input', onEdit); },
  };
}

/* ── Браузер: распознаёт сам, ключ не нужен (Chrome, Edge, Safari) ── */

/* Chrome перестаёт слушать после долгой тишины — пока человек не нажал «стоп», слушаем заново:
   новый круг пишет после уже сказанного. Круг, закончившийся сразу, — сбой, а не тишина. */
async function listen(field, mine, onWord) {
  const gone = () => mine !== dictation;
  const Recognition = globalThis.SpeechRecognition || globalThis.webkitSpeechRecognition;
  if (!Recognition) throw new Error(t('editor.ai.mic_no_browser'));
  const writer = fieldWriter(field, gone);
  let current = null;    // идущий круг распознавания
  let round = 0;         // номер круга: реплики разных кругов не путаются
  let stopping = false;
  let ended = null;      // «стоп» ждёт, когда браузер отдаст итог
  let self = null;

  const close = () => {
    writer.close();
    if (live === self) live = null;
  };

  // Один круг: обещание «микрофон слушает».
  const start = () => new Promise((resolve, reject) => {
    const one = new Recognition();
    const mark = ++round;
    let began = 0;
    let failure = null;
    one.lang = speechLang(document.documentElement.lang);
    one.continuous = true;
    one.interimResults = true;
    one.onstart = () => { began = Date.now(); resolve(); };
    one.onresult = (event) => {
      for (let i = event.resultIndex; i < event.results.length; i++) {
        writer.put(mark + ':' + i, event.results[i][0].transcript);
      }
      // Кодовое слово — только в законченной фразе: черновик «приём» мог быть началом «приём у врача».
      if (onWord && self && event.results[event.results.length - 1]?.isFinal && writer.word()) {
        self.drop();
        onWord();
      }
    };
    one.onerror = (event) => {
      if (event.error === 'no-speech' || event.error === 'aborted') return;   // тишина или наш стоп — не сбой
      failure = new Error(event.error === 'network' ? t('editor.ai.mic_network') : event.error);
      if (event.error === 'not-allowed' || event.error === 'service-not-allowed') failure.name = 'NotAllowedError';
      reject(failure);
    };
    one.onend = () => {
      if (current !== one) return;   // оборвали нарочно (dropDictation) — уже закрыто
      if (!began) {
        current = null;
        reject(failure || new Error(t('editor.ai.mic_stopped')));
        if (stopping) { close(); ended?.(); }   // «стоп», пока новый круг ещё не начался
        return;
      }
      if (!stopping && !failure && !gone() && Date.now() - began > 1000) {
        writer.settle();
        start().catch((error) => { if (!stopping) { close(); lost?.(error); } });
        return;
      }
      current = null;
      close();
      if (stopping) ended?.();
      else if (!gone()) lost?.(failure || new Error(t('editor.ai.mic_stopped')));
    };
    current = one;
    one.start();
  });

  try {
    await start();
  } catch (error) {
    writer.close();
    if (gone()) return false;   // оборвали, пока подключались: сбой уже никому не нужен
    throw error;
  }

  self = {
    // Оборвать сразу, без итога: вопрос уже ушёл, поздние слова в поле не пишем.
    drop() {
      writer.drop();
      stopping = true;
      const one = current;
      current = null;
      one?.abort();
      close();
    },
    async stop() {
      // Браузер отдаёт итог и заканчивает круг — ждём не дольше 4 секунд.
      stopping = true;
      if (current) {
        current.stop();
        await new Promise((resolve) => { ended = resolve; setTimeout(resolve, 4000); });
      }
      if (live === self) close();
    },
  };
  // Вопрос ушёл, пока микрофон подключался: слушать уже нечего, поле не трогаем.
  if (gone()) {
    self.drop();
    return false;
  }
  live = self;
  return true;
}

/* ── OpenAI: микрофон → WebRTC → OpenAI ── */

/** Подключение микрофона к OpenAI; после каждого ожидания — не оборвали ли нас. */
async function connect(field, mine, onWord) {
  const gone = () => mine !== dictation;
  const pass = await api.post('voice.session', {});
  if (gone()) return false;
  const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
  if (gone()) { for (const track of stream.getTracks()) track.stop(); return false; }
  const pc = new RTCPeerConnection();
  for (const track of stream.getTracks()) pc.addTrack(track, stream);
  const events = pc.createDataChannel('oai-events');

  const writer = fieldWriter(field, gone);
  let settled = null;
  /* OpenAI шлёт слова кусочками без конца фразы: кодовое слово в конце ждёт секунду тишины —
     иначе «приём у врача» ушёл бы на «приём». */
  let wordWait = null;
  const watchWord = () => {
    clearTimeout(wordWait);
    if (!onWord || !writer.ends()) return;
    wordWait = setTimeout(() => {
      if (live !== self || !writer.word()) return;
      self.drop();
      onWord();
    }, 1000);
  };
  events.onmessage = (event) => {
    const message = JSON.parse(event.data);
    if (globalThis.GOBLIN_VOICE_DEBUG) console.log('[голос]', message.type, JSON.stringify(message).slice(0, 300));
    if (message.type === 'conversation.item.input_audio_transcription.delta') {
      writer.add(message.item_id, message.delta);
      watchWord();
    } else if (message.type === 'conversation.item.input_audio_transcription.completed') {
      writer.done(message.item_id, message.transcript || '');
      settled?.();
    } else if (message.type === 'error') {
      console.warn('[голос]', message.error?.message || message);
    }
  };

  try {
    await pc.setLocalDescription(await pc.createOffer());
    const response = await fetch(pass.url + '/realtime/calls', {
      method: 'POST',
      body: pc.localDescription.sdp,
      headers: { Authorization: 'Bearer ' + pass.value, 'Content-Type': 'application/sdp' },
    });
    if (!response.ok) throw new Error((await response.text()).slice(0, 200) || 'HTTP ' + response.status);
    await pc.setRemoteDescription({ type: 'answer', sdp: await response.text() });
    // Говорить можно, когда канал открыт: сказанное раньше OpenAI ещё не слышит.
    await new Promise((resolve, reject) => {
      if (events.readyState === 'open') { resolve(); return; }
      events.onopen = resolve;
      setTimeout(() => reject(new Error('timeout')), 10000);
    });
  } catch (error) {
    writer.close();
    for (const track of stream.getTracks()) track.stop();
    pc.close();
    if (gone()) return false;   // оборвали, пока подключались: сбой уже никому не нужен
    throw error;
  }

  const self = {
    // Оборвать сразу, без итога: вопрос уже ушёл, поздние слова в поле не пишем.
    drop() {
      writer.drop();
      events.onmessage = null;
      settled?.();
      close();
    },
    async stop() {
      // Сдать остаток звука и дождаться итога — не дольше 4 секунд.
      if (events.readyState === 'open') events.send(JSON.stringify({ type: 'input_audio_buffer.commit' }));
      await new Promise((resolve) => { settled = resolve; setTimeout(resolve, 4000); });
      if (live === self) close();
    },
  };
  // Закрывает только своё: запоздавшее подключение не гасит диктовку, начатую после него.
  const close = () => {
    clearTimeout(wordWait);
    writer.close();
    for (const track of stream.getTracks()) track.stop();
    pc.close();
    if (live === self) live = null;
  };
  // Вопрос ушёл, пока микрофон подключался: слушать уже нечего, поле не трогаем.
  if (gone()) {
    events.onmessage = null;
    close();
    return false;
  }
  live = self;
  return true;
}

/** Оборвать диктовку без итога — вопрос отправили, пока микрофон слушал или ещё подключался. */
export function dropDictation() {
  dictation++;
  starting = 0;
  live?.drop();
}

/** Закончить диктовку: текст в поле — итоговый. */
export async function stopDictation() {
  if (live) await live.stop();
}

/* ── Чтение вслух ── */

/**
 * Прочитать ответ вслух. Разметку (звёздочки, решётки, код) голос не читает.
 * OpenAI: звук готовится на сервере; выключили динамик за это время — готовый звук не играет.
 * onDone — когда ответ прозвучал до конца (или читать было нечего); оборванный stopSpeaking() его не зовёт.
 */
export async function speak(text, { onDone = null } = {}) {
  stopSpeaking();
  const mine = ++voiceCall;
  const plain = String(text || '')
    .replace(/```[\s\S]*?```/g, ' ')
    .replace(/[*_`#>|]+/g, ' ')
    .replace(/^\s*[-•]\s+/gm, '')
    .replace(/\s+/g, ' ')
    .trim();
  if (!plain) { onDone?.(); return; }
  if (engine() === 'browser') return sayInBrowser(plain, mine, onDone);

  const sound = await api.postAudio('voice.speak', { text: plain });
  if (mine !== voiceCall) return;
  const audio = new Audio(URL.createObjectURL(sound));
  audio.onended = () => {
    URL.revokeObjectURL(audio.src);
    if (player === audio) player = null;
    if (mine === voiceCall) onDone?.();
  };
  player = audio;
  try {
    await audio.play();
  } catch (error) {
    if (mine !== voiceCall) return;   // выключили, пока звук запускался, — это не сбой
    throw error;
  }
}

/* Озвучка браузера. Язык — по буквам ответа (кириллицы больше — русский). Chrome обрывает
   длинную фразу на голосах Google секунд через 15 — читаем кусками до 200 знаков, очередью. */
async function sayInBrowser(plain, mine, onDone) {
  const synth = globalThis.speechSynthesis;
  if (!synth) throw new Error(t('editor.ai.speak_no_browser'));
  const cyrillic = (plain.match(/[а-яё]/gi) || []).length;
  const latin = (plain.match(/[a-z]/gi) || []).length;
  const lang = cyrillic >= latin ? 'ru' : 'en';
  const voice = await voiceFor(synth, lang);
  if (mine !== voiceCall) return;   // выключили, пока искали голос
  synth.cancel();
  // Конец — последняя фраза договорена или озвучка сломалась сама; оборвали (stopSpeaking) — не конец.
  let finished = false;
  const finish = () => {
    if (finished || mine !== voiceCall) return;
    finished = true;
    lines = [];
    onDone?.();
  };
  lines = pieces(plain).map((piece) => {
    const line = new SpeechSynthesisUtterance(piece);
    line.lang = voice?.lang || speechLang(lang);
    if (voice) line.voice = voice;
    line.onerror = (event) => { if (!['interrupted', 'canceled'].includes(event.error)) finish(); };
    return line;
  });
  lines.at(-1).onend = finish;
  for (const line of lines) synth.speak(line);
}

/** Голос языка: голоса Google в Chrome звучат живее системных; иначе — системный для языка. */
async function voiceFor(synth, lang) {
  let voices = synth.getVoices();
  if (!voices.length) {
    // Chrome отдаёт список не сразу.
    await new Promise((resolve) => {
      synth.addEventListener('voiceschanged', resolve, { once: true });
      setTimeout(resolve, 1500);
    });
    voices = synth.getVoices();
  }
  const fit = voices.filter((voice) => voice.lang.toLowerCase().startsWith(lang));
  return fit.find((voice) => /google/i.test(voice.name)) || fit.find((voice) => voice.default) || fit[0] || null;
}

/** Текст кусками по предложениям, каждый не длиннее size; длинное предложение режется по пробелу. */
function pieces(text, size = 200) {
  const out = [];
  let piece = '';
  for (const sentence of text.match(/[^.!?…;:]+[.!?…;:]*\s*/g) || [text]) {
    if (piece && (piece + sentence).length > size) { out.push(piece.trim()); piece = ''; }
    piece += sentence;
    while (piece.length > size) {
      const space = piece.lastIndexOf(' ', size);
      const cut = space > 40 ? space : size;
      out.push(piece.slice(0, cut).trim());
      piece = piece.slice(cut);
    }
  }
  if (piece.trim()) out.push(piece.trim());
  return out;
}

export function stopSpeaking() {
  voiceCall++;
  globalThis.speechSynthesis?.cancel();
  if (!player) return;
  player.pause();
  URL.revokeObjectURL(player.src);
  player = null;
}
