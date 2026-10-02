<?php
/* Язык интерфейса: словари lang/<язык>/<часть>.json и выбор языка человека.
   Отдаёт: lang(), t(), tn(), langAgents(), langForAgent(), ta(), tna(), langFile(), langDict(), langScript(), LANGS.
   Не делает: не выбирает язык сам — человеку его ставит cookie, агентам — проект.

   Язык выбирает каждый человек сам: cookie goblin_lang (ставит src/core/i18n.js); нет — язык установки
   lang_default (config.php: ru; на сайте canvas.goblin.red — en).
   Словарь языка накрывает русский: нет перевода — остаётся русский текст, ключ наружу не выходит.
   Ключ — «часть.место.смысл»: editor.settings.title. Части — по файлам словаря (editor, admin, …).

   Два языка в одном запросе: t() — язык человека (интерфейс, его ошибки), ta() — язык агентов
   проекта (projects.lang): инструкции прогона, задания, лента, ответы ведущему. requireProject()
   ставит его сам; вне запроса (фоновые задания) — язык проекта задания или русский.
   Отвечаем агенту (langForAgent: простой путь, пропуск leader или worker) — и t() говорит
   на языке проекта: ошибки сервера агент читает на том же языке, что и весь прогон. */

declare(strict_types=1);

const LANGS = ['ru', 'en'];

/** Язык человека: cookie, иначе язык установки (lang_default). Ответ агенту — язык проекта (langForAgent). */
function lang(): string
{
    if (langForAgent()) return langAgents();
    $asked = (string) ($_COOKIE['goblin_lang'] ?? '');
    if (in_array($asked, LANGS, true)) return $asked;
    $default = (string) (config()['lang_default'] ?? 'ru');
    return in_array($default, LANGS, true) ? $default : 'ru';
}

/** Адресат ответа — агент? С аргументом — отметить (dispatch() в lib/api/router.php). */
function langForAgent(?bool $set = null): bool
{
    static $agent = false;
    if ($set !== null) $agent = $set;
    return $agent;
}

/** Язык агентов проекта; с аргументом — поставить. */
function langAgents(?string $set = null): string
{
    static $lang = 'ru';
    if ($set !== null) $lang = in_array($set, LANGS, true) ? $set : 'ru';
    return $lang;
}

/** Текст для агентов — на языке проекта. */
function ta(string $key, array $vars = []): string
{
    return langFill(langDict(langAgents())[$key] ?? $key, $vars);
}

/** Число со словом для агентов — на языке проекта. */
function tna(string $key, int $n, array $vars = []): string
{
    $lang = langAgents();
    $dict = langDict($lang);
    $form = langPlural($lang, $n);
    return langFill($dict["$key.$form"] ?? $dict["$key.other"] ?? $dict["$key.many"] ?? $key, ['n' => $n] + $vars);
}

function langFill(string $text, array $vars): string
{
    foreach ($vars as $name => $value) $text = str_replace('{' . $name . '}', (string) $value, $text);
    return $text;
}

/** Файл на языке: <папка>/<язык>/<имя>, нет перевода — русский. Так лежат md-инструкции (instructions/ru|en). */
function langFile(string $dir, string $name, ?string $lang = null): string
{
    $path = $dir . '/' . ($lang ?? lang()) . '/' . $name;
    return is_file($path) ? $path : $dir . '/ru/' . $name;
}

/** Словарь языка поверх русского. */
function langDict(?string $lang = null): array
{
    static $cache = [];
    $lang ??= lang();
    if (!isset($cache[$lang])) {
        $cache[$lang] = $lang === 'ru' ? langFiles('ru') : array_merge(langFiles('ru'), langFiles($lang));
    }
    return $cache[$lang];
}

function langFiles(string $lang): array
{
    $dict = [];
    foreach (glob(dirname(__DIR__, 2) . '/lang/' . $lang . '/*.json') ?: [] as $file) {
        $dict = array_merge($dict, json_decode((string) file_get_contents($file), true) ?: []);
    }
    return $dict;
}

/** Текст по ключу; {имя} в тексте заменяется значением из $vars. */
function t(string $key, array $vars = []): string
{
    return langFill(langDict()[$key] ?? $key, $vars);
}

/** Число со словом: формы key.one / key.few / key.many (русский) или key.one / key.other; {n} — само число. */
function tn(string $key, int $n, array $vars = []): string
{
    $dict = langDict();
    $form = langPlural(lang(), $n);
    return langFill($dict["$key.$form"] ?? $dict["$key.other"] ?? $dict["$key.many"] ?? $key, ['n' => $n] + $vars);
}

/** Форма числа — как Intl.PluralRules в браузере. */
function langPlural(string $lang, int $n): string
{
    if ($lang !== 'ru') return $n === 1 ? 'one' : 'other';
    $last = $n % 10;
    $tens = $n % 100;
    if ($last === 1 && $tens !== 11) return 'one';
    if ($last >= 2 && $last <= 4 && ($tens < 12 || $tens > 14)) return 'few';
    return 'many';
}

/** Словарь для браузера — только нужные части (editor, common, …), встраивается в страницу. */
function langScript(array $parts): string
{
    $dict = array_filter(langDict(), static fn(string $key) => in_array(strstr($key, '.', true), $parts, true), ARRAY_FILTER_USE_KEY);
    $data = json_encode(['lang' => lang(), 'dict' => $dict], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG);
    return '<script>window.GOBLIN_I18N = ' . $data . ';</script>';
}
