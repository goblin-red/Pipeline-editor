<?php
/* Редактор. Вся страница собирается в lib/web/page.php, данные клиент берёт сам.
   ?view=1 — только чтение. */

declare(strict_types=1);

require __DIR__ . '/../lib/boot.php';
require __DIR__ . '/../lib/web/page.php';

// Свежая копия с GitHub — сначала установщик.
if (installNeeded()) {
    header('Location: install.php');
    exit;
}

renderEditor(isset($_GET['view']));
