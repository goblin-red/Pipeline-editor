-- Лента прогона живёт, пока жив её прогон: внешний ключ run_events.run_id → runs.
-- Прогоны удаляются только каскадом — с проектом (fk_runs_project) и с папкой (fk_runs_folder).
-- С проектом события уходили и раньше (fk_events_project), а с папкой оставались сиротами:
-- run_id смотрел на удалённый прогон. На 30.09 сирот в goblin_v2 — 0; в других базах их
-- убирает первая строка, дальше события уносит само удаление прогона.
-- Индекс под ключ уже есть: idx_events_run (run_id, id).

DELETE e FROM run_events e LEFT JOIN runs r ON r.id = e.run_id WHERE r.id IS NULL;

ALTER TABLE run_events
  ADD CONSTRAINT fk_events_run FOREIGN KEY (run_id) REFERENCES runs (id) ON DELETE CASCADE;
