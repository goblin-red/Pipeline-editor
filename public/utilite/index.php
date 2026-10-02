<?php
/* Прежний адрес утилиты. Её работа переехала в кабинет:
   структура проектов и перенос папок — «Структура», списки config.txt — «Списки».
   Ссылку оставляем живой, чтобы старые закладки не ломались. */

declare(strict_types=1);

header('Location: ../account.php?section=structure', true, 302);
echo '<meta charset="utf-8"><p>Утилита переехала в кабинет: '
   . '<a href="../account.php?section=structure">Структура</a> и '
   . '<a href="../account.php?section=lists">Списки</a>.</p>';
