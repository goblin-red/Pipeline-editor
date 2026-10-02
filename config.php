<?php
/* ───────────────────────────────────────────────────────────────
   Гоблин v2 — настройки установки: база, пути, адреса сервисов.
   Отдаёт: массив настроек для config().
   Не делает: не хранит пароли и ключи — они в secrets.php
              и кладутся поверх этого файла в config().

   Переменная окружения с тем же именем всегда сильнее значения,
   написанного здесь или в secrets.php.
   ─────────────────────────────────────────────────────────────── */

declare(strict_types=1);

$env = static fn(string $name, string $fallback): string
    => ($v = getenv($name)) === false || $v === '' ? $fallback : $v;

return [
    /* ── База ─────────────────────────────────────────────────── */
    // mysql — MySQL или MariaDB (поля ниже); sqlite — один файл sqlite_file (от корня Гоблина), сервер базы не нужен.
    'driver'      => $env('GOBLIN_DB_DRIVER', 'mysql'),
    'sqlite_file' => $env('GOBLIN_SQLITE_FILE', 'data/goblin.sqlite'),
    'host'    => $env('GOBLIN_DB_HOST', '127.0.0.1'),
    'port'    => (int) $env('GOBLIN_DB_PORT', '3306'),
    'name'    => $env('GOBLIN_DB_NAME', 'goblin_v2'),
    'user'    => $env('GOBLIN_DB_USER', 'root'),
    'pass'    => $env('GOBLIN_DB_PASS', ''),
    'charset' => 'utf8mb4',

    /* Старая база: из неё читает разовый перенос `bin/server.php v1-transfer`.
       После переноса строку можно удалить. */
    'v1_name' => $env('GOBLIN_V1_DB_NAME', 'goblin'),

    /* ── Пути ─────────────────────────────────────────────────── */
    // php-cli для фоновых заданий. Одно место: раньше worker искали его
    // двумя разными способами и один из них падал.
    'php_cli'          => $env('GOBLIN_PHP_CLI', PHP_BINDIR . '/php'),   // рядом с PHP сервера; установщик пишет найденный
    // Три инструкции: leader, worker и встроенному ИИ.
    'instructions_dir' => __DIR__ . '/instructions',
    // Рабочие папки прогонов.
    'workspace_dir'    => __DIR__ . '/workspace',
    // Здесь заводится рабочая папка новой папки схемы: <это>/<имя папки>/in/.
    'workfiles_dir'    => __DIR__ . '/public/workfiles',
    // Байты загруженных ассетов.
    'files_dir'        => __DIR__ . '/data/files',
    /* Корни, внутри которых лежат файлы схем и прогонов. Сервер читает, отдаёт и чистит
       только пути внутри них: рабочая папка, материал по локальному пути, уборка прогона.
       Работа лежит вне этих папок — допишите её сюда, иначе сервер её не увидит. */
    'file_roots'       => [__DIR__ . '/workspace', __DIR__ . '/public/workfiles'],
    /* Задания worker (<рабочая папка>/service/tasks/rN) копятся по прогонам как журнал.
       true — сброс схемы (begin, кнопка «Сбросить схему») убирает задания прошлых прогонов.
       Пока выключено: копим. */
    'tasks_autoclean'  => false,
    /* Где работают агенты. auto — по адресу сервера: localhost — на этом же компьютере (всё на диске сервера),
       иначе — на своих компьютерах (Гоблин в вебе): задания и материалы ссылками, папка — у агента.
       local / remote — задать вручную. */
    'agents_where'     => 'auto',
    /* Гости — люди без учётки. guest_days — удалять проект гостя, который не открывали столько дней
       (0 — не удалять: на своём компьютере все проекты «гостевые»). guest_ai — гостям платный ИИ
       (помощник, конструктор, голос, Jev). На хостинге: 30 и false — задаётся в админке. */
    'guest_days'       => 0,
    'guest_ai'         => true,
    /* Язык интерфейса, пока человек не выбрал свой (cookie goblin_lang): ru или en. От него же — язык агентов
       нового проекта. На сайте canvas.goblin.red — en (config_web.php). */
    'lang_default'     => 'ru',
    /* Первый заход (в браузере ничего не выбрано): скин и вкладка левой плашки в скинах с вкладками.
       Сайт canvas.goblin.red — «Классика» с вкладкой «Настройки» (config_web.php). На телефоне — всегда мобильный. */
    'look_default'     => 'work',
    'rail_default'     => 'folders',
    /* Материалы на сервере (веб) — лимиты бесплатного аккаунта: файлов на человека и размер файла в КБ;
       0 — без лимита. По умолчанию материалы лежат у человека (галочка проекта «Хранить на сервере»). */
    'files_limit_count' => 50,
    'files_limit_kb'    => 100,

    /* ── Встроенный ИИ: подключения к моделям ─────────────────── */
    // Подключение — любой API в формате OpenAI (chat/completions). Рабочее одно на всех — ai_connection;
    // админка переключает его и добавляет свои подключения. Из рабочего config() собирает
    // ai_api_url, ai_model, ai_timeout и ai_api_key — их и читает весь Гоблин.
    // timeout — сколько ждать, пока модель начнёт отвечать, секунд: дольше — обрыв, человеку «не начала
    // отвечать» (DeepSeek под нагрузкой держит запрос в очереди минутами). Начала писать — не обрываем.
    // Ключ подключения — в secrets.php: ai_key_<имя подключения>.
    'ai_connection'  => 'deepseek',
    'ai_connections' => [
        'deepseek' => [
            'name' => 'DeepSeek', 'url' => 'https://api.deepseek.com/chat/completions',
            'model' => 'deepseek-flash', 'timeout' => 40,
        ],
        'openrouter' => [
            'name' => 'OpenRouter', 'url' => 'https://openrouter.ai/api/v1/chat/completions',
            'model' => 'qwen/qwen3.7-flash', 'timeout' => 40,
        ],
    ],
    'ai_key_deepseek'   => $env('GOBLIN_DEEPSEEK_KEY', ''),
    'ai_key_openrouter' => $env('GOBLIN_OPENROUTER_KEY', ''),
    // Голос: диктовка в чат помощника и чтение ответов вслух. Движок — browser (встроенные в браузер,
    // бесплатно, без ключа) или openai (живое распознавание и озвучка OpenAI, поля ниже).
    // Ключ OpenAI — в secrets.php (voice_api_key); у пользователя могут быть свои — личный кабинет.
    'voice_engine'    => $env('GOBLIN_VOICE_ENGINE', 'browser'),
    'voice_api_url'   => $env('GOBLIN_VOICE_API_URL', 'https://api.openai.com/v1'),
    'voice_api_key'   => $env('GOBLIN_OPENAI_KEY', ''),
    'voice_model'     => $env('GOBLIN_VOICE_MODEL', 'gpt-live-transcribe'),
    'voice_tts_model' => $env('GOBLIN_VOICE_TTS_MODEL', 'gpt-4o-mini-tts'),
    'voice_tts_voice' => $env('GOBLIN_VOICE_TTS_VOICE', 'marin'),

    /* ── Сигнальщик сдачи (TypeSafe Jev) ──────────────────────── */
    /* Джев не принимает и не возвращает шаги. Он смотрит на сдачу теми же
       глазами, что leader, и поднимает флаг, когда ответ противоречит
       заданию или уже принятым шагам. Без ключа Гоблин работает как раньше. */
    'jev_api_url' => $env('GOBLIN_JEV_API_URL', 'https://api.typesafe.ai/v1/systemone'),
    'jev_api_key' => $env('GOBLIN_JEV_KEY', ''),   // ключ — в secrets.php,
    'jev_model'   => $env('GOBLIN_JEV_MODEL', 'jev-1.13.0'),   // точная версия: псевдоним jev-latest может переехать

    /* ── Публикация на хостинг ────────────────────────────────── */
    'ftp_host'     => $env('GOBLIN_FTP_HOST', ''),
    'ftp_user'     => $env('GOBLIN_FTP_USER', ''),
    'ftp_pass'     => $env('GOBLIN_FTP_PASSWORD', ''),   // пароль — в secrets.php,
    'ftp_root'     => $env('GOBLIN_FTP_ROOT', '/'),
    'ftp_port'     => (int) $env('GOBLIN_FTP_PORT', '21'),
    'ftp_protocol' => $env('GOBLIN_FTP_PROTOCOL', 'ftp'),
    'ftp_passive'  => true,

    /* ── Админка ──────────────────────────────────────────────── */
    // Хеш пароля — в secrets.php, там же сказано, как поставить новый.
    'admin_login'     => $env('GOBLIN_ADMIN_LOGIN', 'admin'),
    'admin_pass_hash' => $env('GOBLIN_ADMIN_PASS_HASH', ''),

    /* ── Бэкап ────────────────────────────────────────────────── */
    'backup_extra_databases' => [],
];
