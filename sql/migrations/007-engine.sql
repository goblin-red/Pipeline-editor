-- Применена. Движок прогона: жетоны, настройки прогона, переменные и решение у шага.
-- Применять только при нуле живых прогонов:
--   SELECT COUNT(*) FROM runs WHERE state IN ('running','paused');   -- должно быть 0
-- Перенести в sql/migrations/007-engine.sql и выполнить: php bin/server.php migrate --apply

CREATE TABLE run_marks (
  id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  project_id       BIGINT UNSIGNED NOT NULL,
  run_id           BIGINT UNSIGNED NOT NULL,
  edge_id          BIGINT UNSIGNED NOT NULL,
  from_step_id     BIGINT UNSIGNED NOT NULL,
  pass             INT UNSIGNED NOT NULL DEFAULT 1,
  taken_by_step_id BIGINT UNSIGNED NULL,
  taken_at         DATETIME(3) NULL,
  created_at       DATETIME(3) NOT NULL DEFAULT NOW(3),
  PRIMARY KEY (id),
  UNIQUE KEY uq_marks_once (run_id, edge_id, from_step_id),
  KEY ix_marks_free (run_id, taken_by_step_id),
  KEY ix_marks_project (project_id),
  CONSTRAINT fk_marks_project FOREIGN KEY (project_id)       REFERENCES projects(id)  ON DELETE CASCADE,
  CONSTRAINT fk_marks_run     FOREIGN KEY (run_id)           REFERENCES runs(id)      ON DELETE CASCADE,
  CONSTRAINT fk_marks_edge    FOREIGN KEY (edge_id)          REFERENCES elements(id)  ON DELETE CASCADE,
  CONSTRAINT fk_marks_from    FOREIGN KEY (from_step_id)     REFERENCES run_steps(id) ON DELETE CASCADE,
  CONSTRAINT fk_marks_by      FOREIGN KEY (taken_by_step_id) REFERENCES run_steps(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE runs
  ADD COLUMN engine       TINYINT UNSIGNED NOT NULL DEFAULT 1,
  ADD COLUMN driver       ENUM('manual','utility')      NOT NULL DEFAULT 'manual',
  ADD COLUMN judge        ENUM('human','formal')        NOT NULL DEFAULT 'human',
  ADD COLUMN jev          ENUM('off','advisor','judge') NOT NULL DEFAULT 'off',
  ADD COLUMN version      BIGINT UNSIGNED NOT NULL DEFAULT 0,
  ADD COLUMN show_pause   DECIMAL(4,1) NOT NULL DEFAULT 0,
  ADD COLUMN next_move_at DATETIME(3) NULL,
  ADD COLUMN wait_for     VARCHAR(255) NOT NULL DEFAULT '';

ALTER TABLE run_steps
  ADD COLUMN vars    LONGTEXT NULL,
  ADD COLUMN verdict LONGTEXT NULL;

ALTER TABLE elements ADD KEY ix_elements_folder_type (folder_id, type);
