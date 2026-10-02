/* Окна: одно правило открытия и закрытия на все.
   Отдаёт: openText(), openSettings().
   Не делает: ничего не сохраняет сам — кроме настроек, которые и есть окно. */

import { SETTING_LIST, settings, setSetting, resetSettings } from 'goblin/core/settings.js';

/* Общее правило на все окна: щелчок мимо окна закрывает его. Окно текста и
   окно материала при этом успевают сохранить написанное — у них свой
   обработчик, он срабатывает раньше этого. */
document.addEventListener('click', (event) => {
  const dialog = event.target;
  if (dialog instanceof HTMLDialogElement && dialog.open && outside(dialog, event)) dialog.close();
});

/**
 * Большое поле для текста: им правятся ТЗ и любые заметки.
 * Закрывается щелчком мимо окна и клавишей Esc, и текст при этом сохраняется:
 * человек писал его не для того, чтобы потерять по неловкому движению.
 * Возвращает текст, пустую строку («убрать») или null, если правок не было.
 */
export function openText({ title, titleContext = '', value = '', hint = '', allowDelete = false, code = null }) {
  const dialog = document.getElementById('dialog-text');
  const body = document.getElementById('dialog-text-body');
  setDialogTitle(document.getElementById('dialog-text-title'), title, titleContext);

  const note = document.getElementById('dialog-text-hint');
  note.textContent = hint;
  note.hidden = !hint;
  const drop = document.getElementById('dialog-text-drop');
  drop.hidden = !allowDelete;

  body.value = value;
  paintCode(body, code);          // зеркало рисуем по уже вставленному тексту
  dialog.showModal();
  body.focus();

  return new Promise((resolve) => {
    let answer;
    const done = (result) => { answer = result; dialog.close(); };
    document.getElementById('dialog-text-save').onclick = () => done(body.value);
    drop.onclick = () => done('');
    dialog.onclick = (event) => { if (outside(dialog, event)) done(body.value); };
    dialog.onclose = () => {
      dialog.onclose = null;
      dialog.onclick = null;
      // Закрыли Esc или мимо — считаем это «готово», а не «отмена».
      resolve(answer === undefined ? (body.value === value ? null : body.value) : answer);
    };
  });
}

export function setDialogTitle(node, title, context = '') {
  node.textContent = title;
  if (context) {
    const extra = document.createElement('span');
    extra.className = 'dialog-title-context';
    extra.textContent = ' · ' + context;
    node.append(extra);
  }
}

/* ── Подсветка JSON прямо в поле ──────────────────────────────── */

/*
 * В <textarea> цветного текста не бывает: под ним лежит зеркало — тот же
 * текст, но разрисованный, а буквы самого поля прозрачные. Зеркало повторяет
 * шрифт, отступы и прокрутку, поэтому подмены не видно.
 *
 * code: { lang: 'json' | 'md', locked: [...], free: [...] } — чем красить.
 * У json locked/free говорят, какие ключи верхнего уровня трогать нельзя.
 * Без code поле остаётся обычным.
 */
export function paintCode(body, code) {
  // Поле один раз переезжает в коробку: в ней зеркало ложится точно под текст.
  let box = body.parentElement;
  if (!box.classList.contains('code-box')) {
    box = document.createElement('div');
    box.className = 'code-box';
    body.before(box);
    box.append(body);
  }
  let mirror = box.querySelector('.code-mirror');
  if (!code) {
    body.classList.remove('code-on');
    mirror?.remove();
    body.oninput = body.onscroll = null;
    return;
  }
  if (!mirror) {
    mirror = document.createElement('pre');
    mirror.className = 'code-mirror';
    mirror.setAttribute('aria-hidden', 'true');
    body.before(mirror);
  }
  body.classList.add('code-on');
  const paint = code.lang === 'md' ? mdHtml : jsonHtml;
  const draw = () => {
    fitMirror(body, mirror);
    mirror.innerHTML = paint(body.value, code);
    mirror.scrollTop = body.scrollTop;
    mirror.scrollLeft = body.scrollLeft;
  };
  body.oninput = draw;
  body.onscroll = () => { mirror.scrollTop = body.scrollTop; mirror.scrollLeft = body.scrollLeft; };
  draw();
}

/*
 * Зеркало обязано мерить текст ровно так же, как поле: шрифт, межстрочье,
 * отступы, перенос слов. Иначе строки расходятся и каретка стоит не там,
 * где написано — в скине «студия» поле брало 12,5/17,5, а зеркало 12/19,8.
 * Берём метрики у самого поля, а не повторяем их в стилях каждого скина.
 */
function fitMirror(body, mirror) {
  const from = getComputedStyle(body);
  const same = ['fontFamily', 'fontSize', 'fontWeight', 'fontStyle', 'lineHeight', 'letterSpacing',
    'wordSpacing', 'tabSize', 'textIndent', 'whiteSpace', 'wordBreak', 'overflowWrap',
    'paddingTop', 'paddingRight', 'paddingBottom', 'paddingLeft', 'borderWidth'];
  for (const name of same) mirror.style[name] = from[name];
  mirror.style.borderStyle = 'solid';
  mirror.style.borderColor = 'transparent';
}

/** Текст JSON в html: строки, числа, ключи — своими цветами. */
function jsonHtml(text, code) {
  const locked = code.locked || [];
  const free = code.free || [];
  let top = null;
  const out = text.split('\n').map((line) => {
    const key = line.match(/^  "([^"]+)"\s*:/);
    if (key) top = key[1];
    const cls = top && locked.includes(top) ? ' line-off' : (top && free.includes(top) ? ' line-free' : '');
    return `<span class="code-line${cls}">${paintLine(line)}</span>`;
  }).join('\n');
  return out + '\n';                     // хвостовая строка: иначе зеркало короче поля
}

/** Markdown в html: заголовки, списки, выделения, код и ссылки. */
function mdHtml(text) {
  let fence = false;
  const out = text.split('\n').map((line) => {
    const safe = escapeHtml(line);
    if (/^\s*```/.test(line)) { fence = !fence; return `<span class="code-line m-fence">${safe}</span>`; }
    if (fence) return `<span class="code-line m-code">${safe}</span>`;
    return `<span class="code-line">${mdLine(safe)}</span>`;
  }).join('\n');
  return out + '\n';
}

function mdLine(safe) {
  // Заголовок красит всю строку — так видно уровни с одного взгляда.
  const head = safe.match(/^(#{1,6})\s+(.*)$/);
  if (head) return `<b class="m-head m-h${head[1].length}">${head[0]}</b>`;
  if (/^\s*&gt;/.test(safe)) return `<b class="m-quote">${safe}</b>`;

  // Один проход: кавычки в созданных HTML-тегах не становятся токенами.
  return safe.replace(/(`+)[^`]*\1|!?\[[^\]]*\]\([^)]*\)|\*\*\*[^*]+\*\*\*|\*\*[^*]+\*\*|__[^_]+__|\*[^*]+\*|_[^_]+_|~~[^~]+~~|"(?:\\.|[^"\\])*"|'(?:\\.|[^'\\])*'|«[^»]*»|“[^”]*”|^\s*(?:[-*+]|\d+[.)])(?=\s)|\[[ xX]\]|https?:\/\/[^\s]+/g, (bit) => {
    let cls = 'm-em';
    if (bit.startsWith('`')) cls = 'm-code';
    else if (/^(?:!?\[.*\]\(|https?:)/.test(bit)) cls = 'm-link';
    else if (/^(?:\*\*|__)/.test(bit)) cls = 'm-strong';
    else if (/^["'«“]/.test(bit)) cls = 'm-quote';
    else if (/^(?:\s*(?:[-*+]|\d+[.)])|\[[ xX]\])$/.test(bit)) cls = 'm-mark';
    return `<b class="${cls}">${bit}</b>`;
  });
}

const escapeHtml = (line) => String(line)
  .replace(/[&<>]/g, (ch) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;' }[ch]));

function paintLine(line) {
  const safe = escapeHtml(line);
  return safe.replace(/("(?:\\.|[^"\\])*")(\s*:)?|\b(-?\d+(?:\.\d+)?)\b|\b(true|false|null)\b/g,
    (all, str, colon, num, word) => {
      if (str) return colon ? `<b class="j-key">${str}</b>${colon}` : `<b class="j-str">${str}</b>`;
      if (num) return `<b class="j-num">${num}</b>`;
      return `<b class="j-word">${word}</b>`;
    });
}

/* Где началось движение мышью. Без этого выделение текста, доведённое
   за край окна, закрывало его: браузер считает такое отпускание щелчком
   по подложке. Держим только тот случай, о котором знаем точно, — нажали
   внутри окна; щелчок без предварительного нажатия закрывает как прежде. */
let pressedInside = false;
document.addEventListener('pointerdown', (event) => {
  const dialog = event.target instanceof Element ? event.target.closest('dialog') : null;
  pressedInside = !!dialog && dialog.open && !past(dialog, event);
}, true);

/** Точка события лежит за рамкой окна. */
function past(dialog, event) {
  const box = dialog.getBoundingClientRect();
  return event.clientX < box.left || event.clientX > box.right
      || event.clientY < box.top || event.clientY > box.bottom;
}

/** Щелчок пришёлся мимо окна: у <dialog> подложка — это он сам. */
export function outside(dialog, event) {
  if (event.target !== dialog) return false;
  if (pressedInside) return false;         // начали в окне — это не щелчок мимо
  return past(dialog, event);
}

/** Настройки холста: список строится из core/settings.js, руками ничего не дублируется. */
export function openSettings() {
  const dialog = document.getElementById('dialog-settings');
  const body = document.getElementById('dialog-settings-body');
  const draw = () => {
    body.textContent = '';
    for (const rule of SETTING_LIST) body.append(row(rule));
  };
  draw();
  document.getElementById('dialog-settings-reset').onclick = () => { resetSettings(); draw(); };
  for (const button of dialog.querySelectorAll('[data-close]')) button.onclick = () => dialog.close();
  dialog.showModal();
}

/* Строка настройки: слева подпись и пояснение, справа сам переключатель.
   Так список читается сверху вниз, а органы управления стоят в одну колонку. */
function row(rule) {
  const holder = document.createElement('label');
  holder.className = 'setting';

  const text = document.createElement('div');
  text.className = 'setting-text';
  const title = document.createElement('span');
  title.className = 'setting-name';
  title.textContent = rule.title;
  text.append(title);
  if (rule.hint) {
    const hint = document.createElement('p');
    hint.className = 'setting-hint muted';
    hint.textContent = rule.hint;
    text.append(hint);
  }

  const side = document.createElement('div');
  side.className = 'setting-control';
  side.append(control(rule));

  holder.append(text, side);
  return holder;
}

function control(rule) {
  if (rule.type === 'flag') {
    const node = document.createElement('input');
    node.type = 'checkbox';
    node.checked = !!settings[rule.name];
    node.onchange = () => setSetting(rule.name, node.checked);
    return node;
  }
  if (rule.type === 'choice') {
    const node = document.createElement('select');
    node.className = 'input setting-input';
    for (const [value, word] of rule.options) node.append(new Option(word, value));
    node.value = settings[rule.name];
    node.onchange = () => setSetting(rule.name, node.value);
    return node;
  }
  const node = document.createElement('input');
  node.type = rule.type === 'range' ? 'range' : 'number';
  node.className = 'setting-input';
  node.min = rule.min;
  node.max = rule.max;
  if (rule.step) node.step = rule.step;
  node.value = settings[rule.name];
  node.oninput = () => setSetting(rule.name, Number(node.value));
  return node;
}
