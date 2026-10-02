-- Передача через шлюз: каким прогоном папки-цели получен переход (шаг шлюза, state = accepted).
-- Пусто — посылка ждёт: следующий прогон цели заберёт её целиком (runArrival) и отметит здесь свой id.
-- Переходы до миграции считаются доставленными (0): прежние прогоны цели их уже видели.
ALTER TABLE run_steps ADD COLUMN delivered_run_id bigint(20) unsigned DEFAULT NULL AFTER verdict;
UPDATE run_steps s JOIN elements e ON e.id = s.element_id AND e.type = 'gateway'
   SET s.delivered_run_id = 0
 WHERE s.state = 'accepted';
