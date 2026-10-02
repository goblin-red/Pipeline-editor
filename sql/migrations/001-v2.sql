-- ───────────────────────────────────────────────────────────────
--  Гоблин v2 — схема базы целиком. 21 таблица.
--
--  Иерархия: проект → папка (холст) → [группа] → [область] → элемент
--            → вторичное: ассеты и свойства.
--
--  Два идентификатора и только два:
--    id   — строка таблицы, ею адресуется всё в API;
--    no   — человеческий номер объекта внутри проекта, с 10.
--
--  Правила, общие для всей базы:
--    • у каждой таблицы проекта есть project_id с внешним ключом CASCADE;
--    • полиморфных «owner_type + owner_id» нет нигде;
--    • rev есть у строк верхнего уровня: folders, elements, agents,
--      assets, runs, run_steps;
--    • время ставит только сервер, NOW(3);
--    • закрытые списки — ENUM, списки из config.txt — VARCHAR.
--
--  Накатывается на пустую базу: mariadb -uroot goblin_v2 < sql/schema.sql
-- ───────────────────────────────────────────────────────────────

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ── Люди и пропуска ───────────────────────────────────────────

CREATE TABLE users (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  email         VARCHAR(255) NOT NULL,
  pass_hash     VARCHAR(255) NOT NULL,
  name          VARCHAR(255) NOT NULL DEFAULT '',
  created_at    DATETIME(3) NOT NULL DEFAULT NOW(3),
  last_login_at DATETIME(3) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Структура ─────────────────────────────────────────────────

CREATE TABLE projects (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  url_key       VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  title         VARCHAR(255) NOT NULL DEFAULT '',
  owner_id      BIGINT UNSIGNED NULL,
  rev           BIGINT UNSIGNED NOT NULL DEFAULT 0,
  next_no       INT UNSIGNED NOT NULL DEFAULT 10,
  next_run_no   INT UNSIGNED NOT NULL DEFAULT 1,
  guest_write   TINYINT(1) NOT NULL DEFAULT 1,
  ai_confirm    TINYINT(1) NOT NULL DEFAULT 1,
  strict_checks TINYINT(1) NOT NULL DEFAULT 1,
  created_at    DATETIME(3) NOT NULL DEFAULT NOW(3),
  updated_at    DATETIME(3) NOT NULL DEFAULT NOW(3) ON UPDATE NOW(3),
  PRIMARY KEY (id),
  UNIQUE KEY uq_projects_key (url_key),
  KEY ix_projects_owner (owner_id),
  CONSTRAINT fk_projects_owner FOREIGN KEY (owner_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE folders (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  project_id   BIGINT UNSIGNED NOT NULL,
  parent_id    BIGINT UNSIGNED NULL,
  name         VARCHAR(255) NOT NULL DEFAULT '',
  sort         INT NOT NULL DEFAULT 0,
  work_dir     VARCHAR(1024) NOT NULL DEFAULT '',
  work_url     VARCHAR(1024) NOT NULL DEFAULT '',
  share_scheme TINYINT(1) NOT NULL DEFAULT 0,
  style        LONGTEXT NOT NULL DEFAULT '{}' CHECK (JSON_VALID(style)),
  camera       LONGTEXT NULL CHECK (camera IS NULL OR JSON_VALID(camera)),
  content_rev  BIGINT UNSIGNED NOT NULL DEFAULT 0,
  rev          BIGINT UNSIGNED NOT NULL DEFAULT 0,
  created_at   DATETIME(3) NOT NULL DEFAULT NOW(3),
  updated_at   DATETIME(3) NOT NULL DEFAULT NOW(3) ON UPDATE NOW(3),
  PRIMARY KEY (id),
  KEY ix_folders_rev (project_id, rev),
  KEY ix_folders_tree (parent_id, sort),
  CONSTRAINT fk_folders_project FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE,
  CONSTRAINT fk_folders_parent  FOREIGN KEY (parent_id)  REFERENCES folders (id)  ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Агенты проекта (бывшие «кирпичи») ─────────────────────────

CREATE TABLE agents (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  project_id  BIGINT UNSIGNED NOT NULL,
  name        VARCHAR(255) NOT NULL DEFAULT '',
  role        VARCHAR(16) NOT NULL DEFAULT 'worker',
  cli         VARCHAR(32) NOT NULL DEFAULT '',
  model       VARCHAR(64) NOT NULL DEFAULT '',
  effort      VARCHAR(32) NOT NULL DEFAULT '',
  permission  VARCHAR(32) NOT NULL DEFAULT '',
  sandbox     VARCHAR(32) NOT NULL DEFAULT '',
  close_after ENUM('keep','close') NOT NULL DEFAULT 'keep',
  style       LONGTEXT NOT NULL DEFAULT '{}' CHECK (JSON_VALID(style)),
  rev         BIGINT UNSIGNED NOT NULL DEFAULT 0,
  created_at  DATETIME(3) NOT NULL DEFAULT NOW(3),
  updated_at  DATETIME(3) NOT NULL DEFAULT NOW(3) ON UPDATE NOW(3),
  PRIMARY KEY (id),
  KEY ix_agents_rev (project_id, rev),
  KEY ix_agents_role (project_id, role),
  CONSTRAINT fk_agents_project FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Схема ─────────────────────────────────────────────────────

CREATE TABLE elements (
  id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  project_id       BIGINT UNSIGNED NOT NULL,
  folder_id        BIGINT UNSIGNED NOT NULL,
  `no`             INT UNSIGNED NOT NULL,
  type             ENUM('block','decision','gateway','arrow','group','area','note') NOT NULL,
  title            VARCHAR(512) NOT NULL DEFAULT '',
  description      TEXT NULL,
  agent_id         BIGINT UNSIGNED NULL,
  from_id          BIGINT UNSIGNED NULL,
  to_id            BIGINT UNSIGNED NULL,
  branch           ENUM('flow','yes','no') NULL,
  back             TINYINT(1) NOT NULL DEFAULT 0,
  target_folder_id BIGINT UNSIGNED NULL,
  style            LONGTEXT NOT NULL DEFAULT '{}' CHECK (JSON_VALID(style)),
  rev              BIGINT UNSIGNED NOT NULL DEFAULT 0,
  created_at       DATETIME(3) NOT NULL DEFAULT NOW(3),
  updated_at       DATETIME(3) NOT NULL DEFAULT NOW(3) ON UPDATE NOW(3),
  PRIMARY KEY (id),
  UNIQUE KEY uq_elements_no (project_id, `no`),
  KEY ix_elements_rev (project_id, rev),
  KEY ix_elements_folder (folder_id),
  KEY ix_elements_from (from_id),
  KEY ix_elements_to (to_id),
  KEY ix_elements_target (target_folder_id),
  KEY ix_elements_agent (agent_id),
  CONSTRAINT ck_elements_arrow  CHECK ((type = 'arrow') = (from_id IS NOT NULL AND to_id IS NOT NULL)),
  CONSTRAINT ck_elements_branch CHECK (type = 'arrow' OR (branch IS NULL AND back = 0)),
  CONSTRAINT ck_elements_gate   CHECK (type = 'gateway' OR target_folder_id IS NULL),
  CONSTRAINT ck_elements_agent  CHECK (type = 'block' OR agent_id IS NULL),
  CONSTRAINT fk_elements_project FOREIGN KEY (project_id)       REFERENCES projects (id) ON DELETE CASCADE,
  CONSTRAINT fk_elements_folder  FOREIGN KEY (folder_id)        REFERENCES folders (id)  ON DELETE CASCADE,
  CONSTRAINT fk_elements_agent   FOREIGN KEY (agent_id)         REFERENCES agents (id)   ON DELETE SET NULL,
  CONSTRAINT fk_elements_from    FOREIGN KEY (from_id)          REFERENCES elements (id) ON DELETE CASCADE,
  CONSTRAINT fk_elements_to      FOREIGN KEY (to_id)            REFERENCES elements (id) ON DELETE CASCADE,
  CONSTRAINT fk_elements_target  FOREIGN KEY (target_folder_id) REFERENCES folders (id)  ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE members (
  container_id BIGINT UNSIGNED NOT NULL,
  element_id   BIGINT UNSIGNED NOT NULL,
  sort         INT NOT NULL DEFAULT 0,
  PRIMARY KEY (container_id, element_id),
  KEY ix_members_element (element_id),
  CONSTRAINT fk_members_container FOREIGN KEY (container_id) REFERENCES elements (id) ON DELETE CASCADE,
  CONSTRAINT fk_members_element   FOREIGN KEY (element_id)   REFERENCES elements (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE props (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  element_id BIGINT UNSIGNED NOT NULL,
  name       VARCHAR(64) NOT NULL,
  type       ENUM('text','number','bool','list','json') NOT NULL DEFAULT 'text',
  value      MEDIUMTEXT NOT NULL,
  sort       INT NOT NULL DEFAULT 0,
  updated_at DATETIME(3) NOT NULL DEFAULT NOW(3) ON UPDATE NOW(3),
  PRIMARY KEY (id),
  UNIQUE KEY uq_props (element_id, name),
  KEY ix_props_lookup (name, value(191)),
  CONSTRAINT fk_props_element FOREIGN KEY (element_id) REFERENCES elements (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Материалы ─────────────────────────────────────────────────

CREATE TABLE assets (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  project_id    BIGINT UNSIGNED NOT NULL,
  kind          VARCHAR(16) NOT NULL DEFAULT 'file',
  title         VARCHAR(255) NOT NULL DEFAULT '',
  uri           VARCHAR(4096) NULL,
  file_key      CHAR(48) CHARACTER SET ascii COLLATE ascii_bin NULL,
  body          LONGTEXT NULL,
  mime          VARCHAR(96) NOT NULL DEFAULT '',
  bytes         BIGINT UNSIGNED NULL,
  sha256        CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  original_name VARCHAR(255) NULL,
  rev           BIGINT UNSIGNED NOT NULL DEFAULT 0,
  created_at    DATETIME(3) NOT NULL DEFAULT NOW(3),
  updated_at    DATETIME(3) NOT NULL DEFAULT NOW(3) ON UPDATE NOW(3),
  PRIMARY KEY (id),
  UNIQUE KEY uq_assets_file (file_key),
  KEY ix_assets_rev (project_id, rev),
  KEY ix_assets_uri (project_id, uri(191)),
  CONSTRAINT ck_assets_source CHECK (uri IS NOT NULL OR file_key IS NOT NULL OR body IS NOT NULL),
  CONSTRAINT fk_assets_project FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Прогон ────────────────────────────────────────────────────

CREATE TABLE runs (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  project_id    BIGINT UNSIGNED NOT NULL,
  folder_id     BIGINT UNSIGNED NOT NULL,
  `no`          INT UNSIGNED NOT NULL,
  title         VARCHAR(255) NOT NULL DEFAULT '',
  state         ENUM('running','paused','stopped','done','failed') NOT NULL DEFAULT 'running',
  lead_agent_id BIGINT UNSIGNED NULL,
  started_by    BIGINT UNSIGNED NULL,
  work_dir      VARCHAR(1024) NOT NULL DEFAULT '',
  work_url      VARCHAR(1024) NOT NULL DEFAULT '',
  summary       VARCHAR(500) NOT NULL DEFAULT '',
  tokens_in     BIGINT UNSIGNED NULL,
  tokens_out    BIGINT UNSIGNED NULL,
  stop_at       DATETIME(3) NULL,
  seen_at       DATETIME(3) NULL,
  started_at    DATETIME(3) NOT NULL DEFAULT NOW(3),
  finished_at   DATETIME(3) NULL,
  rev           BIGINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_runs_no (project_id, `no`),
  KEY ix_runs_folder (folder_id, started_at),
  KEY ix_runs_rev (project_id, rev),
  CONSTRAINT fk_runs_project FOREIGN KEY (project_id)    REFERENCES projects (id) ON DELETE CASCADE,
  CONSTRAINT fk_runs_folder  FOREIGN KEY (folder_id)     REFERENCES folders (id)  ON DELETE CASCADE,
  CONSTRAINT fk_runs_lead    FOREIGN KEY (lead_agent_id) REFERENCES agents (id)   ON DELETE SET NULL,
  CONSTRAINT fk_runs_user    FOREIGN KEY (started_by)    REFERENCES users (id)    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE run_steps (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  project_id     BIGINT UNSIGNED NOT NULL,
  run_id         BIGINT UNSIGNED NOT NULL,
  element_id     BIGINT UNSIGNED NULL,
  element_no     INT UNSIGNED NOT NULL,
  element_title  VARCHAR(512) NOT NULL DEFAULT '',
  attempt        SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  state          ENUM('issued','running','submitted','accepted','returned','failed','cancelled') NOT NULL DEFAULT 'issued',
  via_edge_id    BIGINT UNSIGNED NULL,
  chosen_edge_id BIGINT UNSIGNED NULL,
  agent_id       BIGINT UNSIGNED NULL,
  spec_hash      CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  note           VARCHAR(500) NOT NULL DEFAULT '',
  result         VARCHAR(200) NULL,
  error          TEXT NULL,
  ran_on         VARCHAR(120) NOT NULL DEFAULT '',
  session        VARCHAR(120) NOT NULL DEFAULT '',
  tokens_in      BIGINT UNSIGNED NULL,
  tokens_out     BIGINT UNSIGNED NULL,
  tokens_shared  BIGINT UNSIGNED NULL,
  notes          LONGTEXT NULL CHECK (notes IS NULL OR JSON_VALID(notes)),
  opened_at      DATETIME(3) NOT NULL DEFAULT NOW(3),
  started_at     DATETIME(3) NULL,
  submitted_at   DATETIME(3) NULL,
  finished_at    DATETIME(3) NULL,
  rev            BIGINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_steps_attempt (run_id, element_no, attempt),
  KEY ix_steps_rev (project_id, rev),
  KEY ix_steps_element (element_id),
  KEY ix_steps_agent (agent_id),
  KEY ix_steps_state (run_id, state),
  CONSTRAINT fk_steps_project FOREIGN KEY (project_id)     REFERENCES projects (id) ON DELETE CASCADE,
  CONSTRAINT fk_steps_run     FOREIGN KEY (run_id)         REFERENCES runs (id)     ON DELETE CASCADE,
  CONSTRAINT fk_steps_element FOREIGN KEY (element_id)     REFERENCES elements (id) ON DELETE SET NULL,
  CONSTRAINT fk_steps_via     FOREIGN KEY (via_edge_id)    REFERENCES elements (id) ON DELETE SET NULL,
  CONSTRAINT fk_steps_chosen  FOREIGN KEY (chosen_edge_id) REFERENCES elements (id) ON DELETE SET NULL,
  CONSTRAINT fk_steps_agent   FOREIGN KEY (agent_id)       REFERENCES agents (id)   ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE run_jobs (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  project_id        BIGINT UNSIGNED NOT NULL,
  run_id            BIGINT UNSIGNED NOT NULL,
  step_id           BIGINT UNSIGNED NOT NULL,
  ref               VARCHAR(32) NOT NULL,
  provider          VARCHAR(64) NOT NULL DEFAULT '',
  tool              VARCHAR(64) NOT NULL DEFAULT '',
  model             VARCHAR(64) NOT NULL DEFAULT '',
  request           LONGTEXT NULL CHECK (request IS NULL OR JSON_VALID(request)),
  job_id            VARCHAR(128) NOT NULL DEFAULT '',
  state             ENUM('sent','done','failed','rejected') NOT NULL DEFAULT 'sent',
  response_asset_id BIGINT UNSIGNED NULL,
  paid              TINYINT(1) NOT NULL DEFAULT 1,
  error             VARCHAR(500) NOT NULL DEFAULT '',
  created_at        DATETIME(3) NOT NULL DEFAULT NOW(3),
  updated_at        DATETIME(3) NOT NULL DEFAULT NOW(3) ON UPDATE NOW(3),
  PRIMARY KEY (id),
  UNIQUE KEY uq_jobs_ref (step_id, ref),
  KEY ix_jobs_job (run_id, job_id),
  CONSTRAINT fk_jobs_project  FOREIGN KEY (project_id)        REFERENCES projects (id)  ON DELETE CASCADE,
  CONSTRAINT fk_jobs_run      FOREIGN KEY (run_id)            REFERENCES runs (id)      ON DELETE CASCADE,
  CONSTRAINT fk_jobs_step     FOREIGN KEY (step_id)           REFERENCES run_steps (id) ON DELETE CASCADE,
  CONSTRAINT fk_jobs_response FOREIGN KEY (response_asset_id) REFERENCES assets (id)    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE asset_links (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  project_id BIGINT UNSIGNED NOT NULL,
  asset_id   BIGINT UNSIGNED NOT NULL,
  role       ENUM('spec','code','input','reference','cover','attachment','result','preview') NOT NULL DEFAULT 'attachment',
  output     VARCHAR(64) NOT NULL DEFAULT '',
  sort       INT NOT NULL DEFAULT 0,
  element_id BIGINT UNSIGNED NULL,
  folder_id  BIGINT UNSIGNED NULL,
  run_id     BIGINT UNSIGNED NULL,
  step_id    BIGINT UNSIGNED NULL,
  created_at DATETIME(3) NOT NULL DEFAULT NOW(3),
  PRIMARY KEY (id),
  KEY ix_links_element (element_id, sort),
  KEY ix_links_folder (folder_id),
  KEY ix_links_run (run_id),
  KEY ix_links_step (step_id),
  KEY ix_links_asset (asset_id),
  CONSTRAINT ck_links_owner CHECK (
    (element_id IS NOT NULL) + (folder_id IS NOT NULL) + (run_id IS NOT NULL) + (step_id IS NOT NULL) = 1
  ),
  CONSTRAINT fk_links_project FOREIGN KEY (project_id) REFERENCES projects (id)  ON DELETE CASCADE,
  CONSTRAINT fk_links_asset   FOREIGN KEY (asset_id)   REFERENCES assets (id)    ON DELETE CASCADE,
  CONSTRAINT fk_links_element FOREIGN KEY (element_id) REFERENCES elements (id)  ON DELETE CASCADE,
  CONSTRAINT fk_links_folder  FOREIGN KEY (folder_id)  REFERENCES folders (id)   ON DELETE CASCADE,
  CONSTRAINT fk_links_run     FOREIGN KEY (run_id)     REFERENCES runs (id)      ON DELETE CASCADE,
  CONSTRAINT fk_links_step    FOREIGN KEY (step_id)    REFERENCES run_steps (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE tokens (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  token_hash   CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  scope        ENUM('session','admin','project','run','step') NOT NULL,
  tail         VARCHAR(8) NOT NULL DEFAULT '',
  label        VARCHAR(64) NOT NULL DEFAULT '',
  user_id      BIGINT UNSIGNED NULL,
  project_id   BIGINT UNSIGNED NULL,
  run_id       BIGINT UNSIGNED NULL,
  step_id      BIGINT UNSIGNED NULL,
  agent_id     BIGINT UNSIGNED NULL,
  created_by   BIGINT UNSIGNED NULL,
  created_at   DATETIME(3) NOT NULL DEFAULT NOW(3),
  last_used_at DATETIME(3) NULL,
  expires_at   DATETIME(3) NULL,
  revoked_at   DATETIME(3) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_tokens_hash (token_hash),
  KEY ix_tokens_project (project_id, scope),
  KEY ix_tokens_run (run_id),
  KEY ix_tokens_step (step_id),
  KEY ix_tokens_user (user_id),
  CONSTRAINT ck_tokens_scope CHECK (
    (scope = 'session' AND user_id IS NOT NULL AND project_id IS NULL AND run_id IS NULL AND step_id IS NULL)
    OR (scope = 'admin' AND user_id IS NULL AND project_id IS NULL AND run_id IS NULL AND step_id IS NULL)
    OR (scope = 'project' AND project_id IS NOT NULL AND run_id IS NULL AND step_id IS NULL)
    OR (scope = 'run' AND project_id IS NOT NULL AND run_id IS NOT NULL AND step_id IS NULL)
    OR (scope = 'step' AND project_id IS NOT NULL AND run_id IS NOT NULL AND step_id IS NOT NULL)
  ),
  CONSTRAINT fk_tokens_user    FOREIGN KEY (user_id)    REFERENCES users (id)     ON DELETE CASCADE,
  CONSTRAINT fk_tokens_project FOREIGN KEY (project_id) REFERENCES projects (id)  ON DELETE CASCADE,
  CONSTRAINT fk_tokens_run     FOREIGN KEY (run_id)     REFERENCES runs (id)      ON DELETE CASCADE,
  CONSTRAINT fk_tokens_step    FOREIGN KEY (step_id)    REFERENCES run_steps (id) ON DELETE CASCADE,
  CONSTRAINT fk_tokens_agent   FOREIGN KEY (agent_id)   REFERENCES agents (id)    ON DELETE SET NULL,
  CONSTRAINT fk_tokens_author  FOREIGN KEY (created_by) REFERENCES users (id)     ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Встроенный ИИ ─────────────────────────────────────────────

CREATE TABLE ai_chats (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  kind        ENUM('assistant','constructor') NOT NULL,
  project_id  BIGINT UNSIGNED NULL,
  project_key VARCHAR(32) NOT NULL DEFAULT '',
  user_id     BIGINT UNSIGNED NULL,
  token_id    BIGINT UNSIGNED NULL,
  requester   VARCHAR(80) NOT NULL DEFAULT '',
  title       VARCHAR(200) NOT NULL DEFAULT 'Новый чат',
  goal        TEXT NULL,
  state       VARCHAR(16) NOT NULL DEFAULT 'open',
  created_at  DATETIME(3) NOT NULL DEFAULT NOW(3),
  updated_at  DATETIME(3) NOT NULL DEFAULT NOW(3) ON UPDATE NOW(3),
  PRIMARY KEY (id),
  KEY ix_chats_project (project_id, updated_at),
  KEY ix_chats_user (user_id, updated_at),
  KEY ix_chats_requester (requester, created_at),
  CONSTRAINT fk_chats_project FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE SET NULL,
  CONSTRAINT fk_chats_user    FOREIGN KEY (user_id)    REFERENCES users (id)    ON DELETE SET NULL,
  CONSTRAINT fk_chats_token   FOREIGN KEY (token_id)   REFERENCES tokens (id)   ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE ai_messages (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  chat_id    BIGINT UNSIGNED NOT NULL,
  role       ENUM('user','assistant','system') NOT NULL,
  body       MEDIUMTEXT NOT NULL,
  created_at DATETIME(3) NOT NULL DEFAULT NOW(3),
  PRIMARY KEY (id),
  KEY ix_messages_chat (chat_id, id),
  CONSTRAINT fk_messages_chat FOREIGN KEY (chat_id) REFERENCES ai_chats (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE ai_jobs (
  id                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  chat_id           BIGINT UNSIGNED NOT NULL,
  kind              ENUM('reply','questionnaire','diagram','play') NOT NULL DEFAULT 'reply',
  request_id        CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
  state             ENUM('queued','running','proposal','applied','done','cancelled','failed') NOT NULL DEFAULT 'queued',
  user_id           BIGINT UNSIGNED NULL,
  token_id          BIGINT UNSIGNED NULL,
  context           LONGTEXT NULL CHECK (context IS NULL OR JSON_VALID(context)),
  response          LONGTEXT NULL CHECK (response IS NULL OR JSON_VALID(response)),
  ops               LONGTEXT NULL CHECK (ops IS NULL OR JSON_VALID(ops)),
  preview           LONGTEXT NULL CHECK (preview IS NULL OR JSON_VALID(preview)),
  message_id        BIGINT UNSIGNED NULL,
  error             TEXT NULL,
  model             VARCHAR(80) NOT NULL DEFAULT '',
  tokens_prompt     BIGINT UNSIGNED NOT NULL DEFAULT 0,
  tokens_completion BIGINT UNSIGNED NOT NULL DEFAULT 0,
  tokens_total      BIGINT UNSIGNED NOT NULL DEFAULT 0,
  tokens_cached     BIGINT UNSIGNED NOT NULL DEFAULT 0,
  usage_known       TINYINT(1) NOT NULL DEFAULT 0,
  created_at        DATETIME(3) NOT NULL DEFAULT NOW(3),
  started_at        DATETIME(3) NULL,
  finished_at       DATETIME(3) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ai_jobs_request (chat_id, request_id),
  KEY ix_ai_jobs_chat (chat_id, created_at),
  KEY ix_ai_jobs_user (user_id, created_at),
  KEY ix_ai_jobs_state (state),
  CONSTRAINT fk_ai_jobs_chat    FOREIGN KEY (chat_id)    REFERENCES ai_chats (id)    ON DELETE CASCADE,
  CONSTRAINT fk_ai_jobs_user    FOREIGN KEY (user_id)    REFERENCES users (id)       ON DELETE SET NULL,
  CONSTRAINT fk_ai_jobs_token   FOREIGN KEY (token_id)   REFERENCES tokens (id)      ON DELETE SET NULL,
  CONSTRAINT fk_ai_jobs_message FOREIGN KEY (message_id) REFERENCES ai_messages (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Служебные ─────────────────────────────────────────────────

CREATE TABLE journal (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  project_id   BIGINT UNSIGNED NOT NULL,
  rev          BIGINT UNSIGNED NOT NULL DEFAULT 0,
  run_id       BIGINT UNSIGNED NULL,
  op_scope     VARCHAR(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL DEFAULT '',
  operation_id VARCHAR(64) NULL,
  request_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  via          ENUM('editor','api','assistant','constructor','tool','system','legacy') NOT NULL DEFAULT 'api',
  user_id      BIGINT UNSIGNED NULL,
  token_id     BIGINT UNSIGNED NULL,
  agent_id     BIGINT UNSIGNED NULL,
  label        VARCHAR(64) NOT NULL DEFAULT '',
  warnings     LONGTEXT NULL CHECK (warnings IS NULL OR JSON_VALID(warnings)),
  created_at   DATETIME(3) NOT NULL DEFAULT NOW(3),
  PRIMARY KEY (id),
  UNIQUE KEY uq_journal_op (project_id, op_scope, operation_id),
  KEY ix_journal_project (project_id, id),
  KEY ix_journal_time (project_id, created_at),
  KEY ix_journal_agent (agent_id, created_at),
  CONSTRAINT fk_journal_project FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE,
  CONSTRAINT fk_journal_run     FOREIGN KEY (run_id)     REFERENCES runs (id)     ON DELETE SET NULL,
  CONSTRAINT fk_journal_user    FOREIGN KEY (user_id)    REFERENCES users (id)    ON DELETE SET NULL,
  CONSTRAINT fk_journal_token   FOREIGN KEY (token_id)   REFERENCES tokens (id)   ON DELETE SET NULL,
  CONSTRAINT fk_journal_agent   FOREIGN KEY (agent_id)   REFERENCES agents (id)   ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE journal_ops (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  journal_id BIGINT UNSIGNED NOT NULL,
  n          SMALLINT UNSIGNED NOT NULL,
  op         VARCHAR(40) NOT NULL,
  target_id  BIGINT UNSIGNED NULL,
  target_no  INT UNSIGNED NULL,
  target_ref VARCHAR(40) NOT NULL DEFAULT '',
  folder_id  BIGINT UNSIGNED NULL,
  args       LONGTEXT NULL CHECK (args IS NULL OR JSON_VALID(args)),
  prev       LONGTEXT NULL CHECK (prev IS NULL OR JSON_VALID(prev)),
  result     LONGTEXT NULL CHECK (result IS NULL OR JSON_VALID(result)),
  PRIMARY KEY (id),
  UNIQUE KEY uq_journal_ops (journal_id, n),
  KEY ix_journal_ops_target (target_id),
  KEY ix_journal_ops_folder (folder_id),
  CONSTRAINT fk_journal_ops_journal FOREIGN KEY (journal_id) REFERENCES journal (id) ON DELETE CASCADE,
  CONSTRAINT fk_journal_ops_folder  FOREIGN KEY (folder_id)  REFERENCES folders (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE deletions (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  project_id BIGINT UNSIGNED NOT NULL,
  entity     ENUM('folder','element','agent','asset','run') NOT NULL,
  entity_id  BIGINT UNSIGNED NOT NULL,
  `no`       INT UNSIGNED NULL,
  folder_id  BIGINT UNSIGNED NULL,
  title      VARCHAR(512) NOT NULL DEFAULT '',
  rev        BIGINT UNSIGNED NOT NULL,
  created_at DATETIME(3) NOT NULL DEFAULT NOW(3),
  PRIMARY KEY (id),
  KEY ix_deletions_rev (project_id, rev),
  KEY ix_deletions_entity (project_id, entity, entity_id),
  CONSTRAINT fk_deletions_project FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE visits (
  visitor    VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  url_key    VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  project_id BIGINT UNSIGNED NULL,
  user_id    BIGINT UNSIGNED NULL,
  hits       BIGINT UNSIGNED NOT NULL DEFAULT 0,
  first_seen DATETIME(3) NOT NULL DEFAULT NOW(3),
  last_seen  DATETIME(3) NOT NULL DEFAULT NOW(3) ON UPDATE NOW(3),
  user_agent VARCHAR(255) NOT NULL DEFAULT '',
  ip         VARCHAR(45) NOT NULL DEFAULT '',
  PRIMARY KEY (visitor, url_key),
  KEY ix_visits_project (project_id),
  KEY ix_visits_user (user_id, last_seen),
  KEY ix_visits_time (visitor, last_seen),
  CONSTRAINT fk_visits_project FOREIGN KEY (project_id) REFERENCES projects (id) ON DELETE SET NULL,
  CONSTRAINT fk_visits_user    FOREIGN KEY (user_id)    REFERENCES users (id)    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE schema_migrations (
  name       VARCHAR(128) NOT NULL,
  applied_at DATETIME(3) NOT NULL DEFAULT NOW(3),
  took_ms    INT NOT NULL DEFAULT 0,
  PRIMARY KEY (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
