-- Лента прогона: индекс для пульса и привязка к проекту.
-- Пульс спрашивает «последнее событие таких-то видов в проекте» — ему нужен (project_id, kind, id).
-- События удалённых проектов копились без хозяина: убираем их и вешаем внешний ключ,
-- дальше их уносит само удаление проекта.

DELETE FROM run_events WHERE project_id NOT IN (SELECT id FROM projects);

ALTER TABLE run_events
  ADD KEY idx_events_pulse (project_id, kind, id),
  ADD CONSTRAINT fk_events_project FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE;
