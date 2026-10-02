-- Прогон простого пути: ведёт агент-ведущий обычными GET (lib/api/simple.php).
-- Пометка driver = 'lead' отличает его от старого круга утилиты:
-- у такого прогона ветку любого ромба решает Jev (lib/engine/advance.php).

ALTER TABLE runs
  MODIFY COLUMN driver ENUM('manual','utility','lead') NOT NULL DEFAULT 'manual';
