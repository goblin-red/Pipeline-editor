#!/usr/bin/env php
<?php
/* Гоблин — командная строка агента.
   Отдаёт: одну программу на две роли. Кто ты, решает пропуск в окружении:
     GOBLIN_RUN   — leader: ведёт прогон, выдаёт задания, принимает работу;
     GOBLIN_STEP  — worker: выполняет один шаг и сдаёт результат;
     GOBLIN_AGENT — worker с долгим пропуском: берёт свои шаги сам.
     GOBLIN_WORKER_SESSION — постоянный id экземпляра worker для engine 2.

   Всегда нужны:
     GOBLIN_URL      адрес API, например http://localhost/goblin/api.php
     GOBLIN_PROJECT  ключ проекта из ссылки #p=
   Необязательно:
     GOBLIN_LEAD     имя сессии leader — уходит worker в записке к шагу.

   Своего состояния программа не хранит: всё живёт на сервере. Команды
   одинаково работают на localhost и на хостинге.

   Где что лежит:
     lib/cli/net.php     — HTTP, пропуска, разбор строки;
     lib/cli/lead.php    — команды leader;
     lib/cli/engine.php  — движок: state, go, drive, pace, history, work;
     lib/cli/worker.php  — команды worker;
     lib/cli/launch.php  — пакет задания и запуск worker;
     lib/cli/watch.php   — сторож прогона;
     lib/cli/help.php    — короткие подсказки. */

declare(strict_types=1);

$lib = dirname(__DIR__) . '/lib/cli';
require $lib . '/net.php';
require $lib . '/lead.php';
require $lib . '/engine.php';
require $lib . '/worker.php';
require $lib . '/launch.php';
require $lib . '/watch.php';
require $lib . '/help.php';

/* Таблица команд: имя → функция и роль. Роль здесь только для справки —
   права проверяет сервер по пропуску. Добавить команду = добавить строку. */
const COMMANDS = [
    // leader
    'read'    => ['cmdRead',    'leader'],
    'check'   => ['cmdCheck',   'leader'],
    'prepare' => ['cmdPrepare', 'leader'],
    'start'   => ['cmdStart',   'leader'],
    'next'    => ['cmdNext',    'leader'],
    'steps'   => ['cmdSteps',   'leader'],
    'log'     => ['cmdLog',     'leader'],
    'say'     => ['cmdSay',     'оба'],
    'status'  => ['cmdStatus',  'leader'],
    'open'    => ['cmdOpen',    'leader'],
    'launch'  => ['cmdLaunch',  'leader'],
    'wait'    => ['cmdWait',    'leader'],
    'accept'  => ['cmdAccept',  'leader'],
    'return'  => ['cmdReturn',  'leader'],
    'decide'  => ['cmdDecide',  'leader'],
    'jev'     => ['cmdJev',     'leader'],
    'pass'    => ['cmdPass',    'leader'],
    'cancel'  => ['cmdCancel',  'leader'],
    'reissue' => ['cmdReissue', 'leader'],
    'reset'   => ['cmdReset',   'leader'],
    'pause'   => ['cmdPause',   'leader'],
    'resume'  => ['cmdResume',  'leader'],
    'stop'    => ['cmdStop',    'leader'],
    'attach'  => ['cmdAttach',  'leader'],
    'finish'  => ['cmdFinish',  'leader'],
    'drive'   => ['cmdDriveRoute', 'leader'],
    'drive1'  => ['cmdDrive',      'leader'],
    // Явные aliases сохраняем до отдельной приёмки legacy/new маршрутов.
    'state'   => ['cmdState',   'leader'],
    'go'      => ['cmdGo',      'leader'],
    'drive2'  => ['cmdDrive2',  'leader'],
    'pace'    => ['cmdPace',    'leader'],
    'history' => ['cmdHistory', 'оба'],
    'report'  => ['cmdReport',  'leader'],
    'watch'   => ['cmdWatch',   'leader'],
    'token'   => ['cmdToken',   'leader'],
    // worker
    'work'    => ['cmdWorkRoute', 'worker'],
    'work1'   => ['cmdWork',      'worker'],
    'work2'   => ['cmdWork2',   'worker'],
    'task'    => ['cmdTask',    'worker'],
    'note'    => ['cmdNote',    'worker'],
    'job'     => ['cmdJob',     'worker'],
    'submit'  => ['cmdSubmit',  'worker'],
    'fail'    => ['cmdFail',    'worker'],
    // оба
    'help'    => ['cmdHelp',    'оба'],
];

exit(main(array_slice($argv, 1)));

function main(array $argv): int
{
    $name = $argv[0] ?? '';

    if ($name === '' || $name === '-h' || $name === '--help') {
        sayAbout();
        return 0;
    }
    if (!isset(COMMANDS[$name])) die_("нет такой команды: $name");

    $f = flags(array_slice($argv, 1));

    // `--help` у команды — просьба объяснить, а не работать. Без этой проверки
    // `goblin job add --help` заводил настоящую платную заявку. Справку отдаём
    // раньше проверки окружения: объяснять команду можно и без проекта.
    if (!empty($f['help']) || !empty($f['h'])) {
        say(hint($name));
        return 0;
    }

    // Справку отдаём и без проекта: `goblin help worker` — первая команда
    // нового worker, спотыкаться на ней не о чем.
    if ($name !== 'help' && cfg('project') === '') die_('не задан GOBLIN_PROJECT');

    /* Команду leader агентским пропуском не ведут. Если в окружении лежат
       оба пропуска, утилита раньше брала агентский (он старше по очереди) и
       leader получал «scope» или «Не указан прогон» — на этом спотыкались и
       люди, и агенты, совмещающие обе роли. Для leader команд агентский
       пропуск просто не смотрим; явный --token по-прежнему сильнее всего. */
    if (COMMANDS[$name][1] === 'leader' && cfg('agent') !== '' && cfg('run') !== '') {
        cfg('agent', '');
    }

    return (int) COMMANDS[$name][0]($f);
}

/** Общая справка: шапка файла и команды по ролям. */
function sayAbout(): void
{
    $doc = (string) (new ReflectionFunction('main'))->getFileName();
    $head = file_get_contents($doc) ?: '';
    if (preg_match('~/\*(.+?)\*/~s', $head, $found)) {
        say(trim(preg_replace('~^ {0,3}~m', '', $found[1])));
    }
    say('');
    foreach (['leader', 'worker', 'оба'] as $role) {
        $names = array_keys(array_filter(COMMANDS, static fn($c) => $c[1] === $role));
        say(ucfirst($role) . ': ' . implode(', ', $names));
    }
    say('');
    say('Подсказка по команде: goblin <команда> --help');
}
