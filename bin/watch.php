#!/usr/bin/env php
<?php
/* Сторож прогона отдельной командой: то же, что `goblin watch`.
   Вся работа в lib/cli/watch.php — здесь только запуск.

   php bin/watch.php --folder 8545 [--every 5] [--timeout 600] [--accept 90] [--quiet] */

declare(strict_types=1);

require dirname(__DIR__) . '/lib/cli/net.php';
require dirname(__DIR__) . '/lib/cli/watch.php';

exit(cmdWatch(flags(array_slice($argv, 1))));
