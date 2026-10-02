-- Пропуск агента: воркер живёт им весь прогон и сам забирает свой шаг.
ALTER TABLE tokens MODIFY scope ENUM('session','admin','project','run','step','agent') NOT NULL;

-- Пропуск агента живёт в проекте и знает только своего агента: ни прогона,
-- ни шага у него нет — он сам находит открытый шаг.
ALTER TABLE tokens DROP CONSTRAINT ck_tokens_scope;
ALTER TABLE tokens ADD CONSTRAINT ck_tokens_scope CHECK (
  (scope = 'session' AND user_id IS NOT NULL AND project_id IS NULL AND run_id IS NULL AND step_id IS NULL)
  OR (scope = 'admin' AND user_id IS NULL AND project_id IS NULL AND run_id IS NULL AND step_id IS NULL)
  OR (scope = 'project' AND project_id IS NOT NULL AND run_id IS NULL AND step_id IS NULL)
  OR (scope = 'agent' AND project_id IS NOT NULL AND agent_id IS NOT NULL AND run_id IS NULL AND step_id IS NULL)
  OR (scope = 'run' AND project_id IS NOT NULL AND run_id IS NOT NULL AND step_id IS NULL)
  OR (scope = 'step' AND project_id IS NOT NULL AND run_id IS NOT NULL AND step_id IS NOT NULL)
);
