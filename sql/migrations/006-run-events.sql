-- Лента прогона: одно место, где видно всё.
--
-- Сервер, круг ведущего, сторож и воркер кладут сюда события одинаково.
-- У события есть заголовок в одну строку — его видно свёрнутым, — и тело:
-- пакет задания дословно, строка запуска, запрос к сервису, вывод терминала.

CREATE TABLE IF NOT EXISTS run_events (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  project_id  BIGINT UNSIGNED NOT NULL,
  run_id      BIGINT UNSIGNED NOT NULL,
  step_id     BIGINT UNSIGNED NULL,
  element_no  INT UNSIGNED NULL,
  attempt     SMALLINT UNSIGNED NULL,
  at          DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  kind        VARCHAR(24) NOT NULL,
  actor       ENUM('lead','worker','human','script','judge') NOT NULL DEFAULT 'script',
  agent_id    BIGINT UNSIGNED NULL,
  title       VARCHAR(255) NOT NULL DEFAULT '',
  body        LONGTEXT NULL,
  meta        LONGTEXT NULL,
  took_ms     INT UNSIGNED NULL,
  KEY idx_events_run (run_id, id),
  KEY idx_events_step (step_id),
  KEY idx_events_kind (run_id, kind)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
