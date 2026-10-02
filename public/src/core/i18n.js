/* Язык интерфейса: текст по ключу, число со словом, смена языка.
   Отдаёт: t(), tn(), lang, setLang().
   Не делает: не грузит словари — страница кладёт их в window.GOBLIN_I18N (lib/core/i18n.php, langScript).

   Ключ — «часть.место.смысл»: editor.settings.title. Нет перевода — русский текст, нет и его — сам ключ. */

const data = globalThis.GOBLIN_I18N || { lang: 'ru', dict: {} };

export const lang = data.lang;

/** Текст по ключу; {имя} заменяется значением из vars. */
export function t(key, vars) {
  return fill(data.dict[key] ?? key, vars);
}

const plural = new Intl.PluralRules(lang);

/** Число со словом: формы key.one / key.few / key.many или key.one / key.other; {n} — само число. */
export function tn(key, n, vars) {
  const dict = data.dict;
  const form = plural.select(n);
  const text = dict[key + '.' + form] ?? dict[key + '.other'] ?? dict[key + '.many'] ?? key;
  return fill(text, { n, ...vars });
}

function fill(text, vars) {
  if (vars) for (const [name, value] of Object.entries(vars)) text = text.replaceAll('{' + name + '}', String(value));
  return text;
}

/** Сменить язык: cookie на год и перезагрузка — страница соберётся на новом языке целиком. */
export function setLang(next) {
  if (next === lang) return;
  document.cookie = `goblin_lang=${next}; path=/; max-age=31536000; samesite=lax`;
  location.reload();
}
