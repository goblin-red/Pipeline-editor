-- Гоблин: схема SQLite. Собрана из sql/schema.sql командой `php bin/server.php schema-sqlite` — руками не править.

PRAGMA foreign_keys = OFF;

-- ───────────────────────────────────────────────────────────────
--  Гоблин v2 — схема базы целиком. 28 таблиц.
--  Снята с живой базы (SHOW CREATE TABLE) и соответствует миграциям 001–034:
--  добавляя миграцию, обновляйте и этот файл.
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


-- ── Люди ──

CREATE TABLE "users" (
  "id" INTEGER PRIMARY KEY AUTOINCREMENT,
  "email" TEXT COLLATE NOCASE NOT NULL,
  "pass_hash" TEXT COLLATE NOCASE NOT NULL,
  "name" TEXT COLLATE NOCASE NOT NULL DEFAULT '',
  "access" TEXT NOT NULL DEFAULT '{}',
  "created_at" TEXT NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f','now','localtime')),
  "last_login_at" TEXT DEFAULT NULL
);
CREATE UNIQUE INDEX "users__uq_users_email" ON "users" ("email");


CREATE TABLE "projects" (
  "id" INTEGER PRIMARY KEY AUTOINCREMENT,
  "url_key" TEXT NOT NULL,
  "title" TEXT COLLATE NOCASE NOT NULL DEFAULT '',
  "lang" TEXT COLLATE NOCASE NOT NULL DEFAULT 'ru',
  "files_server" INTEGER NOT NULL DEFAULT 0,
  "owner_id" INTEGER DEFAULT NULL,
  "rev" INTEGER NOT NULL DEFAULT 0,
  "next_no" INTEGER NOT NULL DEFAULT 10,
  "next_run_no" INTEGER NOT NULL DEFAULT 1,
  "guest_write" INTEGER NOT NULL DEFAULT 1,
  "ai_confirm" INTEGER NOT NULL DEFAULT 1,
  "strict_checks" INTEGER NOT NULL DEFAULT 1,
  "created_at" TEXT NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f','now','localtime')),
  "updated_at" TEXT NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f','now','localtime')),
  "seen_at" TEXT DEFAULT NULL,
  CONSTRAINT "fk_projects_owner" FOREIGN KEY ("owner_id") REFERENCES "users" ("id") ON DELETE SET NULL
);
CREATE UNIQUE INDEX "projects__uq_projects_key" ON "projects" ("url_key");
CREATE INDEX "projects__ix_projects_owner" ON "projects" ("owner_id");
CREATE TRIGGER "tr_projects_updated_at" AFTER UPDATE ON "projects" FOR EACH ROW WHEN NEW."updated_at" IS OLD."updated_at"
BEGIN UPDATE "projects" SET "updated_at" = (strftime('%Y-%m-%d %H:%M:%f','now','localtime')) WHERE rowid = NEW.rowid; END;


-- ── Структура ──

CREATE TABLE "folders" (
  "id" INTEGER PRIMARY KEY AUTOINCREMENT,
  "project_id" INTEGER NOT NULL,
  "parent_id" INTEGER DEFAULT NULL,
  "name" TEXT COLLATE NOCASE NOT NULL DEFAULT '',
  "sort" INTEGER NOT NULL DEFAULT 0,
  "work_dir" TEXT COLLATE NOCASE NOT NULL DEFAULT '',
  "work_url" TEXT COLLATE NOCASE NOT NULL DEFAULT '',
  "share_scheme" INTEGER NOT NULL DEFAULT 0,
  "run_env" TEXT COLLATE NOCASE NOT NULL DEFAULT '',
  "role_scheme" TEXT COLLATE NOCASE NOT NULL DEFAULT 'solo',
  "style" TEXT NOT NULL DEFAULT '{}' CHECK (json_valid("style")),
  "camera" TEXT DEFAULT NULL CHECK ("camera" is null or json_valid("camera")),
  "content_rev" INTEGER NOT NULL DEFAULT 0,
  "rev" INTEGER NOT NULL DEFAULT 0,
  "created_at" TEXT NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f','now','localtime')),
  "updated_at" TEXT NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f','now','localtime')),
  CONSTRAINT "fk_folders_parent" FOREIGN KEY ("parent_id") REFERENCES "folders" ("id") ON DELETE CASCADE,
  CONSTRAINT "fk_folders_project" FOREIGN KEY ("project_id") REFERENCES "projects" ("id") ON DELETE CASCADE
);
CREATE INDEX "folders__ix_folders_rev" ON "folders" ("project_id","rev");
CREATE INDEX "folders__ix_folders_tree" ON "folders" ("parent_id","sort");
CREATE TRIGGER "tr_folders_updated_at" AFTER UPDATE ON "folders" FOR EACH ROW WHEN NEW."updated_at" IS OLD."updated_at"
BEGIN UPDATE "folders" SET "updated_at" = (strftime('%Y-%m-%d %H:%M:%f','now','localtime')) WHERE rowid = NEW.rowid; END;


-- ── Агенты проекта ──

CREATE TABLE "agents" (
  "id" INTEGER PRIMARY KEY AUTOINCREMENT,
  "project_id" INTEGER NOT NULL,
  "name" TEXT COLLATE NOCASE NOT NULL DEFAULT '',
  "role" TEXT COLLATE NOCASE NOT NULL DEFAULT 'worker',
  "cli" TEXT COLLATE NOCASE NOT NULL DEFAULT '',
  "model" TEXT COLLATE NOCASE NOT NULL DEFAULT '',
  "effort" TEXT COLLATE NOCASE NOT NULL DEFAULT '',
  "permission" TEXT COLLATE NOCASE NOT NULL DEFAULT '',
  "sandbox" TEXT COLLATE NOCASE NOT NULL DEFAULT '',
  "close_after" TEXT NOT NULL DEFAULT 'keep' CHECK ("close_after" IN ('keep','close')),
  "style" TEXT NOT NULL DEFAULT '{}' CHECK (json_valid("style")),
  "rev" INTEGER NOT NULL DEFAULT 0,
  "created_at" TEXT NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f','now','localtime')),
  "updated_at" TEXT NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f','now','localtime')),
  CONSTRAINT "fk_agents_project" FOREIGN KEY ("project_id") REFERENCES "projects" ("id") ON DELETE CASCADE
);
CREATE INDEX "agents__ix_agents_rev" ON "agents" ("project_id","rev");
CREATE INDEX "agents__ix_agents_role" ON "agents" ("project_id","role");
CREATE TRIGGER "tr_agents_updated_at" AFTER UPDATE ON "agents" FOR EACH ROW WHEN NEW."updated_at" IS OLD."updated_at"
BEGIN UPDATE "agents" SET "updated_at" = (strftime('%Y-%m-%d %H:%M:%f','now','localtime')) WHERE rowid = NEW.rowid; END;


-- ── Схема ──

CREATE TABLE "elements" (
  "id" INTEGER PRIMARY KEY AUTOINCREMENT,
  "project_id" INTEGER NOT NULL,
  "folder_id" INTEGER NOT NULL,
  "no" INTEGER NOT NULL,
  "type" TEXT NOT NULL CHECK ("type" IN ('block','decision','gateway','arrow','group','area','note','table','link')),
  "title" TEXT COLLATE NOCASE NOT NULL DEFAULT '',
  "description" TEXT DEFAULT NULL,
  "agent_id" INTEGER DEFAULT NULL,
  "from_id" INTEGER DEFAULT NULL,
  "to_id" INTEGER DEFAULT NULL,
  "branch" TEXT DEFAULT NULL CHECK ("branch" IN ('flow','yes','no') OR "branch" IS NULL),
  "back" INTEGER NOT NULL DEFAULT 0,
  "target_folder_id" INTEGER DEFAULT NULL,
  "style" TEXT NOT NULL DEFAULT '{}' CHECK (json_valid("style")),
  "rev" INTEGER NOT NULL DEFAULT 0,
  "created_at" TEXT NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f','now','localtime')),
  "updated_at" TEXT NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f','now','localtime')),
  CONSTRAINT "fk_elements_agent" FOREIGN KEY ("agent_id") REFERENCES "agents" ("id") ON DELETE SET NULL,
  CONSTRAINT "fk_elements_folder" FOREIGN KEY ("folder_id") REFERENCES "folders" ("id") ON DELETE CASCADE,
  CONSTRAINT "fk_elements_from" FOREIGN KEY ("from_id") REFERENCES "elements" ("id") ON DELETE CASCADE,
  CONSTRAINT "fk_elements_project" FOREIGN KEY ("project_id") REFERENCES "projects" ("id") ON DELETE CASCADE,
  CONSTRAINT "fk_elements_target" FOREIGN KEY ("target_folder_id") REFERENCES "folders" ("id") ON DELETE SET NULL,
  CONSTRAINT "fk_elements_to" FOREIGN KEY ("to_id") REFERENCES "elements" ("id") ON DELETE CASCADE,
  CONSTRAINT "ck_elements_branch" CHECK ("type" = 'arrow' or "branch" is null and "back" = 0),
  CONSTRAINT "ck_elements_gate" CHECK ("type" = 'gateway' or "target_folder_id" is null),
  CONSTRAINT "ck_elements_agent" CHECK ("type" = 'block' or "agent_id" is null),
  CONSTRAINT "ck_elements_arrow" CHECK ("type" in ('arrow','link') = ("from_id" is not null and "to_id" is not null))
);
CREATE UNIQUE INDEX "elements__uq_elements_no" ON "elements" ("project_id","no");
CREATE INDEX "elements__ix_elements_rev" ON "elements" ("project_id","rev");
CREATE INDEX "elements__ix_elements_folder" ON "elements" ("folder_id");
CREATE INDEX "elements__ix_elements_from" ON "elements" ("from_id");
CREATE INDEX "elements__ix_elements_to" ON "elements" ("to_id");
CREATE INDEX "elements__ix_elements_target" ON "elements" ("target_folder_id");
CREATE INDEX "elements__ix_elements_agent" ON "elements" ("agent_id");
CREATE INDEX "elements__ix_elements_folder_type" ON "elements" ("folder_id","type");
CREATE TRIGGER "tr_elements_updated_at" AFTER UPDATE ON "elements" FOR EACH ROW WHEN NEW."updated_at" IS OLD."updated_at"
BEGIN UPDATE "elements" SET "updated_at" = (strftime('%Y-%m-%d %H:%M:%f','now','localtime')) WHERE rowid = NEW.rowid; END;


CREATE TABLE "members" (
  "container_id" INTEGER NOT NULL,
  "element_id" INTEGER NOT NULL,
  "sort" INTEGER NOT NULL DEFAULT 0,
  PRIMARY KEY ("container_id","element_id"),
  CONSTRAINT "fk_members_container" FOREIGN KEY ("container_id") REFERENCES "elements" ("id") ON DELETE CASCADE,
  CONSTRAINT "fk_members_element" FOREIGN KEY ("element_id") REFERENCES "elements" ("id") ON DELETE CASCADE
);
CREATE INDEX "members__ix_members_element" ON "members" ("element_id");


CREATE TABLE "props" (
  "id" INTEGER PRIMARY KEY AUTOINCREMENT,
  "element_id" INTEGER NOT NULL,
  "name" TEXT COLLATE NOCASE NOT NULL,
  "type" TEXT NOT NULL DEFAULT 'text' CHECK ("type" IN ('text','number','bool','list','json')),
  "value" TEXT NOT NULL,
  "sort" INTEGER NOT NULL DEFAULT 0,
  "updated_at" TEXT NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f','now','localtime')),
  CONSTRAINT "fk_props_element" FOREIGN KEY ("element_id") REFERENCES "elements" ("id") ON DELETE CASCADE
);
CREATE UNIQUE INDEX "props__uq_props" ON "props" ("element_id","name");
CREATE INDEX "props__ix_props_lookup" ON "props" ("name","value");
CREATE TRIGGER "tr_props_updated_at" AFTER UPDATE ON "props" FOR EACH ROW WHEN NEW."updated_at" IS OLD."updated_at"
BEGIN UPDATE "props" SET "updated_at" = (strftime('%Y-%m-%d %H:%M:%f','now','localtime')) WHERE rowid = NEW.rowid; END;


-- ── Материалы ──

CREATE TABLE "assets" (
  "id" INTEGER PRIMARY KEY AUTOINCREMENT,
  "project_id" INTEGER NOT NULL,
  "kind" TEXT COLLATE NOCASE NOT NULL DEFAULT 'file',
  "title" TEXT COLLATE NOCASE NOT NULL DEFAULT '',
  "uri" TEXT COLLATE NOCASE DEFAULT NULL,
  "file_key" TEXT DEFAULT NULL,
  "body" TEXT DEFAULT NULL,
  "mime" TEXT COLLATE NOCASE NOT NULL DEFAULT '',
  "bytes" INTEGER DEFAULT NULL,
  "sha256" TEXT DEFAULT NULL,
  "original_name" TEXT COLLATE NOCASE DEFAULT NULL,
  "rev" INTEGER NOT NULL DEFAULT 0,
  "created_at" TEXT NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f','now','localtime')),
  "updated_at" TEXT NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f','now','localtime')),
  CONSTRAINT "fk_assets_project" FOREIGN KEY ("project_id") REFERENCES "projects" ("id") ON DELETE CASCADE,
  CONSTRAINT "ck_assets_source" CHECK ("uri" is not null or "file_key" is not null or "body" is not null)
);
CREATE UNIQUE INDEX "assets__uq_assets_file" ON "assets" ("file_key");
CREATE INDEX "assets__ix_assets_rev" ON "assets" ("project_id","rev");
CREATE INDEX "assets__ix_assets_uri" ON "assets" ("project_id","uri");
CREATE TRIGGER "tr_assets_updated_at" AFTER UPDATE ON "assets" FOR EACH ROW WHEN NEW."updated_at" IS OLD."updated_at"
BEGIN UPDATE "assets" SET "updated_at" = (strftime('%Y-%m-%d %H:%M:%f','now','localtime')) WHERE rowid = NEW.rowid; END;


-- ── Прогон ──

CREATE TABLE "runs" (
  "id" INTEGER PRIMARY KEY AUTOINCREMENT,
  "project_id" INTEGER NOT NULL,
  "folder_id" INTEGER NOT NULL,
  "no" INTEGER NOT NULL,
  "title" TEXT COLLATE NOCASE NOT NULL DEFAULT '',
  "state" TEXT NOT NULL DEFAULT 'running' CHECK ("state" IN ('running','paused','stopped','done','failed')),
  "lead_agent_id" INTEGER DEFAULT NULL,
  "started_by" INTEGER DEFAULT NULL,
  "work_dir" TEXT COLLATE NOCASE NOT NULL DEFAULT '',
  "work_url" TEXT COLLATE NOCASE NOT NULL DEFAULT '',
  "agent_dir" TEXT COLLATE NOCASE NOT NULL DEFAULT '',
  "summary" TEXT COLLATE NOCASE NOT NULL DEFAULT '',
  "tokens_in" INTEGER DEFAULT NULL,
  "tokens_out" INTEGER DEFAULT NULL,
  "stop_at" TEXT DEFAULT NULL,
  "seen_at" TEXT DEFAULT NULL,
  "started_at" TEXT NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f','now','localtime')),
  "finished_at" TEXT DEFAULT NULL,
  "rev" INTEGER NOT NULL DEFAULT 0,
  "engine" INTEGER NOT NULL DEFAULT 1,
  "driver" TEXT NOT NULL DEFAULT 'manual' CHECK ("driver" IN ('manual','utility','lead')),
  "judge" TEXT NOT NULL DEFAULT 'human' CHECK ("judge" IN ('human','formal')),
  "jev" TEXT NOT NULL DEFAULT 'off' CHECK ("jev" IN ('off','advisor','judge')),
  "version" INTEGER NOT NULL DEFAULT 0,
  "show_pause" REAL NOT NULL DEFAULT 0.0,
  "next_move_at" TEXT DEFAULT NULL,
  "wait_for" TEXT COLLATE NOCASE NOT NULL DEFAULT '',
  CONSTRAINT "fk_runs_folder" FOREIGN KEY ("folder_id") REFERENCES "folders" ("id") ON DELETE CASCADE,
  CONSTRAINT "fk_runs_lead" FOREIGN KEY ("lead_agent_id") REFERENCES "agents" ("id") ON DELETE SET NULL,
  CONSTRAINT "fk_runs_project" FOREIGN KEY ("project_id") REFERENCES "projects" ("id") ON DELETE CASCADE,
  CONSTRAINT "fk_runs_user" FOREIGN KEY ("started_by") REFERENCES "users" ("id") ON DELETE SET NULL
);
CREATE UNIQUE INDEX "runs__uq_runs_no" ON "runs" ("project_id","no");
CREATE INDEX "runs__ix_runs_folder" ON "runs" ("folder_id","started_at");
CREATE INDEX "runs__ix_runs_rev" ON "runs" ("project_id","rev");
CREATE INDEX "runs__fk_runs_lead" ON "runs" ("lead_agent_id");
CREATE INDEX "runs__fk_runs_user" ON "runs" ("started_by");


CREATE TABLE "run_steps" (
  "id" INTEGER PRIMARY KEY AUTOINCREMENT,
  "project_id" INTEGER NOT NULL,
  "run_id" INTEGER NOT NULL,
  "element_id" INTEGER DEFAULT NULL,
  "element_no" INTEGER NOT NULL,
  "element_title" TEXT COLLATE NOCASE NOT NULL DEFAULT '',
  "attempt" INTEGER NOT NULL DEFAULT 1,
  "state" TEXT NOT NULL DEFAULT 'issued' CHECK ("state" IN ('issued','running','submitted','accepted','returned','failed','cancelled')),
  "via_edge_id" INTEGER DEFAULT NULL,
  "chosen_edge_id" INTEGER DEFAULT NULL,
  "agent_id" INTEGER DEFAULT NULL,
  "spec_hash" TEXT DEFAULT NULL,
  "note" TEXT COLLATE NOCASE NOT NULL DEFAULT '',
  "result" TEXT DEFAULT NULL,
  "error" TEXT DEFAULT NULL,
  "ran_on" TEXT COLLATE NOCASE NOT NULL DEFAULT '',
  "who" TEXT COLLATE NOCASE NOT NULL DEFAULT '',
  "lead_self" INTEGER DEFAULT NULL,
  "session" TEXT COLLATE NOCASE NOT NULL DEFAULT '',
  "tokens_in" INTEGER DEFAULT NULL,
  "tokens_out" INTEGER DEFAULT NULL,
  "tokens_shared" INTEGER DEFAULT NULL,
  "notes" TEXT DEFAULT NULL CHECK ("notes" is null or json_valid("notes")),
  "opened_at" TEXT NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f','now','localtime')),
  "started_at" TEXT DEFAULT NULL,
  "submitted_at" TEXT DEFAULT NULL,
  "finished_at" TEXT DEFAULT NULL,
  "rev" INTEGER NOT NULL DEFAULT 0,
  "vars" TEXT DEFAULT NULL,
  "verdict" TEXT DEFAULT NULL,
  "delivered_run_id" INTEGER DEFAULT NULL,
  CONSTRAINT "fk_steps_agent" FOREIGN KEY ("agent_id") REFERENCES "agents" ("id") ON DELETE SET NULL,
  CONSTRAINT "fk_steps_chosen" FOREIGN KEY ("chosen_edge_id") REFERENCES "elements" ("id") ON DELETE SET NULL,
  CONSTRAINT "fk_steps_element" FOREIGN KEY ("element_id") REFERENCES "elements" ("id") ON DELETE SET NULL,
  CONSTRAINT "fk_steps_project" FOREIGN KEY ("project_id") REFERENCES "projects" ("id") ON DELETE CASCADE,
  CONSTRAINT "fk_steps_run" FOREIGN KEY ("run_id") REFERENCES "runs" ("id") ON DELETE CASCADE,
  CONSTRAINT "fk_steps_via" FOREIGN KEY ("via_edge_id") REFERENCES "elements" ("id") ON DELETE SET NULL
);
CREATE UNIQUE INDEX "run_steps__uq_steps_attempt" ON "run_steps" ("run_id","element_no","attempt");
CREATE INDEX "run_steps__ix_steps_rev" ON "run_steps" ("project_id","rev");
CREATE INDEX "run_steps__ix_steps_element" ON "run_steps" ("element_id");
CREATE INDEX "run_steps__ix_steps_agent" ON "run_steps" ("agent_id");
CREATE INDEX "run_steps__ix_steps_state" ON "run_steps" ("run_id","state");
CREATE INDEX "run_steps__fk_steps_via" ON "run_steps" ("via_edge_id");
CREATE INDEX "run_steps__fk_steps_chosen" ON "run_steps" ("chosen_edge_id");


CREATE TABLE "run_jobs" (
  "id" INTEGER PRIMARY KEY AUTOINCREMENT,
  "project_id" INTEGER NOT NULL,
  "run_id" INTEGER NOT NULL,
  "step_id" INTEGER NOT NULL,
  "ref" TEXT COLLATE NOCASE NOT NULL,
  "provider" TEXT COLLATE NOCASE NOT NULL DEFAULT '',
  "tool" TEXT COLLATE NOCASE NOT NULL DEFAULT '',
  "model" TEXT COLLATE NOCASE NOT NULL DEFAULT '',
  "request" TEXT DEFAULT NULL CHECK ("request" is null or json_valid("request")),
  "job_id" TEXT COLLATE NOCASE NOT NULL DEFAULT '',
  "state" TEXT NOT NULL DEFAULT 'sent' CHECK ("state" IN ('sent','done','failed','rejected')),
  "response_asset_id" INTEGER DEFAULT NULL,
  "paid" INTEGER NOT NULL DEFAULT 1,
  "error" TEXT COLLATE NOCASE NOT NULL DEFAULT '',
  "created_at" TEXT NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f','now','localtime')),
  "updated_at" TEXT NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f','now','localtime')),
  CONSTRAINT "fk_jobs_project" FOREIGN KEY ("project_id") REFERENCES "projects" ("id") ON DELETE CASCADE,
  CONSTRAINT "fk_jobs_response" FOREIGN KEY ("response_asset_id") REFERENCES "assets" ("id") ON DELETE SET NULL,
  CONSTRAINT "fk_jobs_run" FOREIGN KEY ("run_id") REFERENCES "runs" ("id") ON DELETE CASCADE,
  CONSTRAINT "fk_jobs_step" FOREIGN KEY ("step_id") REFERENCES "run_steps" ("id") ON DELETE CASCADE
);
CREATE UNIQUE INDEX "run_jobs__uq_jobs_ref" ON "run_jobs" ("step_id","ref");
CREATE INDEX "run_jobs__ix_jobs_job" ON "run_jobs" ("run_id","job_id");
CREATE INDEX "run_jobs__fk_jobs_project" ON "run_jobs" ("project_id");
CREATE INDEX "run_jobs__fk_jobs_response" ON "run_jobs" ("response_asset_id");
CREATE TRIGGER "tr_run_jobs_updated_at" AFTER UPDATE ON "run_jobs" FOR EACH ROW WHEN NEW."updated_at" IS OLD."updated_at"
BEGIN UPDATE "run_jobs" SET "updated_at" = (strftime('%Y-%m-%d %H:%M:%f','now','localtime')) WHERE rowid = NEW.rowid; END;


CREATE TABLE "run_marks" (
  "id" INTEGER PRIMARY KEY AUTOINCREMENT,
  "project_id" INTEGER NOT NULL,
  "run_id" INTEGER NOT NULL,
  "edge_id" INTEGER NOT NULL,
  "from_step_id" INTEGER NOT NULL,
  "pass" INTEGER NOT NULL DEFAULT 1,
  "taken_by_step_id" INTEGER DEFAULT NULL,
  "taken_at" TEXT DEFAULT NULL,
  "created_at" TEXT NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f','now','localtime')),
  CONSTRAINT "fk_marks_by" FOREIGN KEY ("taken_by_step_id") REFERENCES "run_steps" ("id") ON DELETE SET NULL,
  CONSTRAINT "fk_marks_edge" FOREIGN KEY ("edge_id") REFERENCES "elements" ("id") ON DELETE CASCADE,
  CONSTRAINT "fk_marks_from" FOREIGN KEY ("from_step_id") REFERENCES "run_steps" ("id") ON DELETE CASCADE,
  CONSTRAINT "fk_marks_project" FOREIGN KEY ("project_id") REFERENCES "projects" ("id") ON DELETE CASCADE,
  CONSTRAINT "fk_marks_run" FOREIGN KEY ("run_id") REFERENCES "runs" ("id") ON DELETE CASCADE
);
CREATE UNIQUE INDEX "run_marks__uq_marks_once" ON "run_marks" ("run_id","edge_id","from_step_id");
CREATE INDEX "run_marks__ix_marks_free" ON "run_marks" ("run_id","taken_by_step_id");
CREATE INDEX "run_marks__ix_marks_project" ON "run_marks" ("project_id");
CREATE INDEX "run_marks__fk_marks_edge" ON "run_marks" ("edge_id");
CREATE INDEX "run_marks__fk_marks_from" ON "run_marks" ("from_step_id");
CREATE INDEX "run_marks__fk_marks_by" ON "run_marks" ("taken_by_step_id");


CREATE TABLE "run_events" (
  "id" INTEGER PRIMARY KEY AUTOINCREMENT,
  "project_id" INTEGER NOT NULL,
  "run_id" INTEGER NOT NULL,
  "step_id" INTEGER DEFAULT NULL,
  "element_no" INTEGER DEFAULT NULL,
  "attempt" INTEGER DEFAULT NULL,
  "at" TEXT NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f','now','localtime')),
  "kind" TEXT COLLATE NOCASE NOT NULL,
  "actor" TEXT NOT NULL DEFAULT 'script' CHECK ("actor" IN ('lead','worker','human','script','judge')),
  "agent_id" INTEGER DEFAULT NULL,
  "title" TEXT COLLATE NOCASE NOT NULL DEFAULT '',
  "body" TEXT DEFAULT NULL,
  "meta" TEXT DEFAULT NULL,
  "took_ms" INTEGER DEFAULT NULL,
  CONSTRAINT "fk_events_project" FOREIGN KEY ("project_id") REFERENCES "projects" ("id") ON DELETE CASCADE,
  CONSTRAINT "fk_events_run" FOREIGN KEY ("run_id") REFERENCES "runs" ("id") ON DELETE CASCADE
);
CREATE INDEX "run_events__idx_events_run" ON "run_events" ("run_id","id");
CREATE INDEX "run_events__idx_events_step" ON "run_events" ("step_id");
CREATE INDEX "run_events__idx_events_kind" ON "run_events" ("run_id","kind");
CREATE INDEX "run_events__idx_events_pulse" ON "run_events" ("project_id","kind","id");


CREATE TABLE "asset_links" (
  "id" INTEGER PRIMARY KEY AUTOINCREMENT,
  "project_id" INTEGER NOT NULL,
  "asset_id" INTEGER NOT NULL,
  "role" TEXT NOT NULL DEFAULT 'attachment' CHECK ("role" IN ('spec','code','input','reference','cover','attachment','result','preview')),
  "output" TEXT COLLATE NOCASE NOT NULL DEFAULT '',
  "sort" INTEGER NOT NULL DEFAULT 0,
  "element_id" INTEGER DEFAULT NULL,
  "folder_id" INTEGER DEFAULT NULL,
  "run_id" INTEGER DEFAULT NULL,
  "step_id" INTEGER DEFAULT NULL,
  "made_by_run" INTEGER DEFAULT NULL,
  "created_at" TEXT NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f','now','localtime')),
  CONSTRAINT "fk_links_asset" FOREIGN KEY ("asset_id") REFERENCES "assets" ("id") ON DELETE CASCADE,
  CONSTRAINT "fk_links_element" FOREIGN KEY ("element_id") REFERENCES "elements" ("id") ON DELETE CASCADE,
  CONSTRAINT "fk_links_folder" FOREIGN KEY ("folder_id") REFERENCES "folders" ("id") ON DELETE CASCADE,
  CONSTRAINT "fk_links_project" FOREIGN KEY ("project_id") REFERENCES "projects" ("id") ON DELETE CASCADE,
  CONSTRAINT "fk_links_run" FOREIGN KEY ("run_id") REFERENCES "runs" ("id") ON DELETE CASCADE,
  CONSTRAINT "fk_links_step" FOREIGN KEY ("step_id") REFERENCES "run_steps" ("id") ON DELETE CASCADE,
  CONSTRAINT "ck_links_owner" CHECK (("element_id" is not null) + ("folder_id" is not null) + ("run_id" is not null) + ("step_id" is not null) = 1)
);
CREATE INDEX "asset_links__ix_links_element" ON "asset_links" ("element_id","sort");
CREATE INDEX "asset_links__ix_links_folder" ON "asset_links" ("folder_id");
CREATE INDEX "asset_links__ix_links_run" ON "asset_links" ("run_id");
CREATE INDEX "asset_links__ix_links_step" ON "asset_links" ("step_id");
CREATE INDEX "asset_links__ix_links_asset" ON "asset_links" ("asset_id");
CREATE INDEX "asset_links__fk_links_project" ON "asset_links" ("project_id");
CREATE INDEX "asset_links__idx_links_made_by_run" ON "asset_links" ("made_by_run");


-- ── Пропуска ──

CREATE TABLE "tokens" (
  "id" INTEGER PRIMARY KEY AUTOINCREMENT,
  "token_hash" TEXT NOT NULL,
  "scope" TEXT NOT NULL CHECK ("scope" IN ('session','admin','project','run','step','agent')),
  "tail" TEXT COLLATE NOCASE NOT NULL DEFAULT '',
  "label" TEXT COLLATE NOCASE NOT NULL DEFAULT '',
  "user_id" INTEGER DEFAULT NULL,
  "project_id" INTEGER DEFAULT NULL,
  "run_id" INTEGER DEFAULT NULL,
  "step_id" INTEGER DEFAULT NULL,
  "agent_id" INTEGER DEFAULT NULL,
  "created_by" INTEGER DEFAULT NULL,
  "created_at" TEXT NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f','now','localtime')),
  "last_used_at" TEXT DEFAULT NULL,
  "expires_at" TEXT DEFAULT NULL,
  "revoked_at" TEXT DEFAULT NULL,
  CONSTRAINT "fk_tokens_agent" FOREIGN KEY ("agent_id") REFERENCES "agents" ("id") ON DELETE SET NULL,
  CONSTRAINT "fk_tokens_author" FOREIGN KEY ("created_by") REFERENCES "users" ("id") ON DELETE SET NULL,
  CONSTRAINT "fk_tokens_project" FOREIGN KEY ("project_id") REFERENCES "projects" ("id") ON DELETE CASCADE,
  CONSTRAINT "fk_tokens_run" FOREIGN KEY ("run_id") REFERENCES "runs" ("id") ON DELETE CASCADE,
  CONSTRAINT "fk_tokens_step" FOREIGN KEY ("step_id") REFERENCES "run_steps" ("id") ON DELETE CASCADE,
  CONSTRAINT "fk_tokens_user" FOREIGN KEY ("user_id") REFERENCES "users" ("id") ON DELETE CASCADE,
  CONSTRAINT "ck_tokens_scope" CHECK ("scope" = 'session' and "user_id" is not null and "project_id" is null and "run_id" is null and "step_id" is null or "scope" = 'admin' and "user_id" is null and "project_id" is null and "run_id" is null and "step_id" is null or "scope" = 'project' and "project_id" is not null and "run_id" is null and "step_id" is null or "scope" = 'agent' and "project_id" is not null and "agent_id" is not null and "run_id" is null and "step_id" is null or "scope" = 'run' and "project_id" is not null and "run_id" is not null and "step_id" is null or "scope" = 'step' and "project_id" is not null and "run_id" is not null and "step_id" is not null)
);
CREATE UNIQUE INDEX "tokens__uq_tokens_hash" ON "tokens" ("token_hash");
CREATE INDEX "tokens__ix_tokens_project" ON "tokens" ("project_id","scope");
CREATE INDEX "tokens__ix_tokens_run" ON "tokens" ("run_id");
CREATE INDEX "tokens__ix_tokens_step" ON "tokens" ("step_id");
CREATE INDEX "tokens__ix_tokens_user" ON "tokens" ("user_id");
CREATE INDEX "tokens__fk_tokens_agent" ON "tokens" ("agent_id");
CREATE INDEX "tokens__fk_tokens_author" ON "tokens" ("created_by");


-- ── Заготовки ──

-- Каталог: разделы с паспортами (lib/templates/catalog.php). Заготовка в каталоге — in_catalog = 1.
CREATE TABLE "template_categories" (
  "key" TEXT COLLATE NOCASE NOT NULL,
  "title" TEXT COLLATE NOCASE NOT NULL,
  "icon" TEXT COLLATE NOCASE NOT NULL DEFAULT '',
  "about" TEXT COLLATE NOCASE NOT NULL DEFAULT '',
  "passport" TEXT NOT NULL DEFAULT '{}',
  "i18n" TEXT NOT NULL DEFAULT '{}',
  "sort" INTEGER NOT NULL DEFAULT 0,
  "updated_at" TEXT NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f','now','localtime')),
  PRIMARY KEY ("key")
);
CREATE INDEX "template_categories__ix_categories_sort" ON "template_categories" ("sort");
CREATE TRIGGER "tr_template_categories_updated_at" AFTER UPDATE ON "template_categories" FOR EACH ROW WHEN NEW."updated_at" IS OLD."updated_at"
BEGIN UPDATE "template_categories" SET "updated_at" = (strftime('%Y-%m-%d %H:%M:%f','now','localtime')) WHERE rowid = NEW.rowid; END;


-- Разделы — как в живой базе на 30.09: паспорт и перевод (i18n.en), чтобы свежая установка
-- показывала каталог и по-английски. Правят их в админке; посев — снимок, а не источник правды.
INSERT OR IGNORE INTO template_categories (`key`, title, icon, about, sort, passport, i18n) VALUES
  ('sites', 'Сайты и лендинги', 'globe', 'Лендинги, страницы товара, многостраничные сайты и доработка готовых', 10,
   '{"skeleton":"Бриф → тексты → картинки → сборка страницы → проверка (картинки на месте, один h1, форма) → ромб; нет — пересборка","questions":["Что продаём и кому?","Какое действие должен сделать посетитель?","Есть свои тексты и фото?","Каким сервисом делать картинки?"],"tune":["бриф","разделы страницы","стиль картинок","кругов пересборки"],"pitfalls":["Картинки подключать относительными путями рядом со страницей","Тексты — отдельным файлом, сборка берёт их дословно"]}',
   '{"en":{"title":"Sites and landing pages","about":"Landing pages, product pages, multi-page sites and finishing existing ones","passport":{"skeleton":"Brief → texts → images → page build → check (images in place, one h1, form) → decision; no — rebuild","questions":["What do we sell and to whom?","What action should the visitor take?","Do you have your own texts and photos?","Which service should make the images?"],"tune":["brief","page sections","image style","rebuild rounds"],"pitfalls":["Link images with relative paths next to the page","Texts go in a separate file, the build takes them word for word"]}}}'),
  ('photo', 'Фото', 'image', 'Генерация, ретушь, вырезка фона, серии карточек товара', 20,
   '{"skeleton":"Исходник из in/ или генерация → обработка (фон, размер, серия) → скриптовая сборка (PIL) → проверка размеров и краёв → ромб","questions":["Есть исходные фото?","Для какой площадки и каких размеров?","Какой стиль?","Каким сервисом генерировать или править?"],"tune":["стиль и сюжеты","размеры","поля и подписи"],"pitfalls":["Текст на картинку ставит скрипт, не генератор","Размеры проверять скриптом, не на глаз"]}',
   '{"en":{"title":"Photo","about":"Generation, retouching, background removal, series of product cards","passport":{"skeleton":"Source from in/ or generation → processing (background, size, series) → scripted build (PIL) → check of sizes and edges → decision","questions":["Do you have source photos?","For which platform and which sizes?","What style?","Which service should generate or edit?"],"tune":["style and subjects","sizes","margins and captions"],"pitfalls":["Text on the image is added by a script, not by the generator","Check sizes with a script, not by eye"]}}}'),
  ('video', 'Видео', 'film', 'Ролики из картинок, нарезка, озвученные клипы, анимация', 30,
   '{"skeleton":"Сценарий → кадры и озвучка параллельно → сборка ffmpeg → проверка ffprobe (длительность, потоки, размер) → ромб","questions":["О чём ролик и какой длины?","Есть исходное видео или картинки?","Какой формат: горизонтальный или вертикальный?","Нужна ли озвучка и каким голосом?"],"tune":["число кадров и длина","формат кадра","голос"],"pitfalls":["drawtext есть не во всех сборках ffmpeg — титры рисовать PNG и накладывать","Резать и склеивать с перекодированием в один формат"]}',
   '{"en":{"title":"Video","about":"Videos from images, cutting, voiced clips, animation","passport":{"skeleton":"Script → frames and voice-over in parallel → ffmpeg assembly → ffprobe check (duration, streams, size) → decision","questions":["What is the video about and how long?","Do you have source video or images?","Which format: horizontal or vertical?","Do you need a voice-over, and which voice?"],"tune":["number of frames and length","frame format","voice"],"pitfalls":["drawtext is not in every ffmpeg build — draw the titles as PNG and overlay them","Cut and join with re-encoding to one format"]}}}'),
  ('texts', 'Тексты и контент', 'text', 'Статьи, описания, переводы, редактура и контент-планы', 40,
   '{"skeleton":"Тема или исходник → черновик → проверка скриптом (объём, структура, длины) → ромб, нет — переделка → редактура","questions":["О чём текст и для кого?","Какой объём?","Какой тон?","Есть исходный текст или список?"],"tune":["тема и аудитория","границы объёма","тон и язык"],"pitfalls":["Объём считать скриптом — модель пишет короче обещанного","Проверка по каждому элементу пачки, а не в среднем"]}',
   '{"en":{"title":"Texts and content","about":"Articles, descriptions, translations, editing and content plans","passport":{"skeleton":"Topic or source → draft → script check (size, structure, lengths) → decision, no — redo → editing","questions":["What is the text about and for whom?","What length?","What tone?","Is there a source text or a list?"],"tune":["topic and audience","length limits","tone and language"],"pitfalls":["Count the length with a script — the model writes shorter than promised","Check every item of the batch, not the average"]}}}'),
  ('social', 'Соцсети и маркетинг', 'megaphone', 'Посты с картинками, рекламные креативы, серии публикаций', 50,
   '{"skeleton":"Бриф или оффер → тексты и картинки параллельно → вёрстка или сборка публикаций → проверка","questions":["Для какого бренда и соцсети?","Какие рубрики или оффер?","Сколько картинок?","Каким сервисом делать картинки?"],"tune":["бренд, рубрики, оффер","число картинок","размер креатива"],"pitfalls":["Картинки делать по плану, не по готовым текстам — ветки идут параллельно","Заголовок на картинку — скриптом с переносами по ширине"]}',
   '{"en":{"title":"Social media and marketing","about":"Posts with images, ad creatives, series of publications","passport":{"skeleton":"Brief or offer → texts and images in parallel → layout or assembly of publications → check","questions":["For which brand and social network?","Which rubrics or offer?","How many images?","Which service should make the images?"],"tune":["brand, rubrics, offer","number of images","creative size"],"pitfalls":["Make the images from the plan, not from the finished texts — the branches run in parallel","The headline goes on the image by a script with line breaks by width"]}}}'),
  ('docs', 'Документы и отчёты', 'file', 'Отчёты, КП, презентации и сводки из данных', 60,
   '{"skeleton":"Бриф или данные → расчёты и графики параллельно → текст → вёрстка HTML (печать A4) → проверка обязательных частей","questions":["Какой документ и для кого?","Какие данные или бриф?","Что обязательно должно быть в документе?"],"tune":["бриф или данные","разделы","оформление"],"pitfalls":["Цифры в выводах брать из файла расчётов","Цена, сроки, контакты — только из брифа"]}',
   '{"en":{"title":"Documents and reports","about":"Reports, proposals, presentations and summaries from data","passport":{"skeleton":"Brief or data → calculations and charts in parallel → text → HTML layout (A4 print) → check of required parts","questions":["Which document and for whom?","Which data or brief?","What must be in the document?"],"tune":["brief or data","sections","design"],"pitfalls":["Take the figures in the conclusions from the calculations file","Price, terms, contacts — only from the brief"]}}}'),
  ('data', 'Данные и API', 'database', 'Запросы к сервисам, выгрузки, таблицы и сводки', 70,
   '{"skeleton":"Параметры или таблица → запросы или разбор (параллельно, если источников несколько) → очистка или сводка → проверка тем же скриптом","questions":["Какие данные и откуда?","Что считать проблемой или нормой?","В каком виде нужен результат?"],"tune":["источники и параметры","правила чистки","формат результата"],"pitfalls":["curl всегда с таймаутом","Повторная проверка — тем же скриптом, что первая"]}',
   '{"en":{"title":"Data and APIs","about":"Requests to services, exports, tables and summaries","passport":{"skeleton":"Parameters or a table → requests or parsing (in parallel if there are several sources) → cleaning or a summary → check by the same script","questions":["Which data and from where?","What counts as a problem or as normal?","In what form do you need the result?"],"tune":["sources and parameters","cleaning rules","result format"],"pitfalls":["curl always with a timeout","Re-check with the same script as the first one"]}}}'),
  ('audio', 'Аудио и озвучка', 'music', 'Озвучка текста, подкасты, музыка и звуковые дорожки', 80,
   '{"skeleton":"Текст → разбивка на фразы → озвучка → склейка или сведение ffmpeg (loudnorm) → проверка длительности","questions":["Какой текст?","Какой голос и язык?","Нужна ли музыка?","Каким сервисом озвучивать?"],"tune":["голос","паузы","громкость и музыка"],"pitfalls":["Все куски к одной частоте перед склейкой","Голос громче подложки на 10–15 дБ"]}',
   '{"en":{"title":"Audio and voice-over","about":"Voice-over of a text, podcasts, music and sound tracks","passport":{"skeleton":"Text → split into phrases → voice-over → ffmpeg joining or mixing (loudnorm) → duration check","questions":["Which text?","Which voice and language?","Do you need music?","Which service should voice it?"],"tune":["voice","pauses","volume and music"],"pitfalls":["All pieces to one sample rate before joining","The voice louder than the background by 10–15 dB"]}}}'),
  ('code', 'Код и автоматизация', 'code', 'Скрипты, мини-приложения, проверки и автоматизация рутины', 90,
   '{"skeleton":"Требования → код и тесты параллельно (тесты по требованиям) → прогон тестов или проверка синтаксиса → ромб, нет — исправление кода","questions":["Что должна делать программа?","Язык и ограничения (без библиотек?)","Какие крайние случаи важны?"],"tune":["требования","язык","кругов исправления"],"pitfalls":["Тесты по требованиям, не по коду","Синтаксис JS проверять node --check до браузера"]}',
   '{"en":{"title":"Code and automation","about":"Scripts, mini apps, checks and routine automation","passport":{"skeleton":"Requirements → code and tests in parallel (tests from the requirements) → test run or syntax check → decision, no — fix the code","questions":["What should the program do?","Language and limits (no libraries?)","Which edge cases matter?"],"tune":["requirements","language","fix rounds"],"pitfalls":["Tests from the requirements, not from the code","Check JS syntax with node --check before the browser"]}}}'),
  ('basics', 'Основы Гоблина', 'book', 'Учебные схемы: цепочка, развилка, цикл, параллель, проверка', 100,
   '{"skeleton":"Учебные схемы: цепочка, ромб с ветками и слиянием any, цикл с back и max_attempts, параллель со слиянием all","questions":["Какой механизм показать?","Что должны делать блоки?"],"tune":["условия ромбов","пределы кругов","тема"],"pitfalls":["После ромба — join any, после параллели — join all","У блоков круга — max_attempts"]}',
   '{"en":{"title":"Goblin basics","about":"Learning schemes: a chain, a fork, a loop, parallel branches, a check","passport":{"skeleton":"Learning schemes: a chain, a decision with branches and an any-merge, a loop with back and max_attempts, parallel branches with an all-merge","questions":["Which mechanism to show?","What should the blocks do?"],"tune":["decision conditions","round limits","topic"],"pitfalls":["After a decision — join any, after parallel branches — join all","The blocks of a loop have max_attempts"]}}}');

CREATE TABLE "templates" (
  "id" INTEGER PRIMARY KEY AUTOINCREMENT,
  "key" TEXT COLLATE NOCASE NOT NULL,
  "title" TEXT COLLATE NOCASE NOT NULL,
  "family" TEXT NOT NULL DEFAULT 'scheme' CHECK ("family" IN ('scheme','flow')),
  "category" TEXT COLLATE NOCASE NOT NULL DEFAULT '',
  "about" TEXT COLLATE NOCASE NOT NULL DEFAULT '',
  "body" TEXT NOT NULL,
  "passport" TEXT NOT NULL DEFAULT '{}',
  "i18n" TEXT NOT NULL DEFAULT '{}',
  "in_catalog" INTEGER NOT NULL DEFAULT 0,
  "checked_at" TEXT DEFAULT NULL,
  "sort" INTEGER NOT NULL DEFAULT 0,
  "builtin" INTEGER NOT NULL DEFAULT 0,
  "owner_id" INTEGER DEFAULT NULL,
  "created_at" TEXT NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f','now','localtime')),
  "updated_at" TEXT NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f','now','localtime')),
  CONSTRAINT "fk_templates_owner" FOREIGN KEY ("owner_id") REFERENCES "users" ("id") ON DELETE SET NULL
);
CREATE UNIQUE INDEX "templates__uq_templates_key" ON "templates" ("key");
CREATE INDEX "templates__ix_templates_shelf" ON "templates" ("family","sort","id");
CREATE INDEX "templates__fk_templates_owner" ON "templates" ("owner_id");
CREATE INDEX "templates__ix_templates_catalog" ON "templates" ("in_catalog","category","sort");
CREATE TRIGGER "tr_templates_updated_at" AFTER UPDATE ON "templates" FOR EACH ROW WHEN NEW."updated_at" IS OLD."updated_at"
BEGIN UPDATE "templates" SET "updated_at" = (strftime('%Y-%m-%d %H:%M:%f','now','localtime')) WHERE rowid = NEW.rowid; END;


-- ── Встроенный ИИ ──

CREATE TABLE "ai_chats" (
  "id" INTEGER PRIMARY KEY AUTOINCREMENT,
  "kind" TEXT NOT NULL CHECK ("kind" IN ('assistant','constructor')),
  "project_id" INTEGER DEFAULT NULL,
  "project_key" TEXT COLLATE NOCASE NOT NULL DEFAULT '',
  "folder_id" INTEGER DEFAULT NULL,
  "user_id" INTEGER DEFAULT NULL,
  "token_id" INTEGER DEFAULT NULL,
  "requester" TEXT COLLATE NOCASE NOT NULL DEFAULT '',
  "title" TEXT COLLATE NOCASE NOT NULL DEFAULT 'Новый чат',
  "goal" TEXT DEFAULT NULL,
  "state" TEXT COLLATE NOCASE NOT NULL DEFAULT 'open',
  "created_at" TEXT NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f','now','localtime')),
  "updated_at" TEXT NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f','now','localtime')),
  CONSTRAINT "fk_chats_folder" FOREIGN KEY ("folder_id") REFERENCES "folders" ("id") ON DELETE SET NULL,
  CONSTRAINT "fk_chats_project" FOREIGN KEY ("project_id") REFERENCES "projects" ("id") ON DELETE SET NULL,
  CONSTRAINT "fk_chats_token" FOREIGN KEY ("token_id") REFERENCES "tokens" ("id") ON DELETE SET NULL,
  CONSTRAINT "fk_chats_user" FOREIGN KEY ("user_id") REFERENCES "users" ("id") ON DELETE SET NULL
);
CREATE INDEX "ai_chats__ix_chats_project" ON "ai_chats" ("project_id","updated_at");
CREATE INDEX "ai_chats__ix_chats_user" ON "ai_chats" ("user_id","updated_at");
CREATE INDEX "ai_chats__ix_chats_requester" ON "ai_chats" ("requester","created_at");
CREATE INDEX "ai_chats__fk_chats_token" ON "ai_chats" ("token_id");
CREATE INDEX "ai_chats__ix_chats_folder" ON "ai_chats" ("folder_id","updated_at");
CREATE TRIGGER "tr_ai_chats_updated_at" AFTER UPDATE ON "ai_chats" FOR EACH ROW WHEN NEW."updated_at" IS OLD."updated_at"
BEGIN UPDATE "ai_chats" SET "updated_at" = (strftime('%Y-%m-%d %H:%M:%f','now','localtime')) WHERE rowid = NEW.rowid; END;


CREATE TABLE "ai_messages" (
  "id" INTEGER PRIMARY KEY AUTOINCREMENT,
  "chat_id" INTEGER NOT NULL,
  "role" TEXT NOT NULL CHECK ("role" IN ('user','assistant','system')),
  "body" TEXT NOT NULL,
  "think" TEXT DEFAULT NULL,
  "created_at" TEXT NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f','now','localtime')),
  CONSTRAINT "fk_messages_chat" FOREIGN KEY ("chat_id") REFERENCES "ai_chats" ("id") ON DELETE CASCADE
);
CREATE INDEX "ai_messages__ix_messages_chat" ON "ai_messages" ("chat_id","id");


CREATE TABLE "ai_jobs" (
  "id" INTEGER PRIMARY KEY AUTOINCREMENT,
  "chat_id" INTEGER NOT NULL,
  "kind" TEXT NOT NULL DEFAULT 'reply' CHECK ("kind" IN ('reply','questionnaire','diagram','play')),
  "request_id" TEXT DEFAULT NULL,
  "state" TEXT NOT NULL DEFAULT 'queued' CHECK ("state" IN ('queued','running','proposal','applied','done','cancelled','failed')),
  "user_id" INTEGER DEFAULT NULL,
  "token_id" INTEGER DEFAULT NULL,
  "context" TEXT DEFAULT NULL CHECK ("context" is null or json_valid("context")),
  "response" TEXT DEFAULT NULL CHECK ("response" is null or json_valid("response")),
  "ops" TEXT DEFAULT NULL CHECK ("ops" is null or json_valid("ops")),
  "preview" TEXT DEFAULT NULL CHECK ("preview" is null or json_valid("preview")),
  "message_id" INTEGER DEFAULT NULL,
  "error" TEXT DEFAULT NULL,
  "model" TEXT COLLATE NOCASE NOT NULL DEFAULT '',
  "tokens_prompt" INTEGER NOT NULL DEFAULT 0,
  "tokens_completion" INTEGER NOT NULL DEFAULT 0,
  "tokens_total" INTEGER NOT NULL DEFAULT 0,
  "tokens_cached" INTEGER NOT NULL DEFAULT 0,
  "usage_known" INTEGER NOT NULL DEFAULT 0,
  "created_at" TEXT NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f','now','localtime')),
  "started_at" TEXT DEFAULT NULL,
  "finished_at" TEXT DEFAULT NULL,
  CONSTRAINT "fk_ai_jobs_chat" FOREIGN KEY ("chat_id") REFERENCES "ai_chats" ("id") ON DELETE CASCADE,
  CONSTRAINT "fk_ai_jobs_message" FOREIGN KEY ("message_id") REFERENCES "ai_messages" ("id") ON DELETE SET NULL,
  CONSTRAINT "fk_ai_jobs_token" FOREIGN KEY ("token_id") REFERENCES "tokens" ("id") ON DELETE SET NULL,
  CONSTRAINT "fk_ai_jobs_user" FOREIGN KEY ("user_id") REFERENCES "users" ("id") ON DELETE SET NULL
);
CREATE UNIQUE INDEX "ai_jobs__uq_ai_jobs_request" ON "ai_jobs" ("chat_id","request_id");
CREATE INDEX "ai_jobs__ix_ai_jobs_chat" ON "ai_jobs" ("chat_id","created_at");
CREATE INDEX "ai_jobs__ix_ai_jobs_user" ON "ai_jobs" ("user_id","created_at");
CREATE INDEX "ai_jobs__ix_ai_jobs_state" ON "ai_jobs" ("state");
CREATE INDEX "ai_jobs__fk_ai_jobs_token" ON "ai_jobs" ("token_id");
CREATE INDEX "ai_jobs__fk_ai_jobs_message" ON "ai_jobs" ("message_id");


CREATE TABLE "api_calls" (
  "id" INTEGER PRIMARY KEY AUTOINCREMENT,
  "created_at" TEXT NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f','now','localtime')),
  "service" TEXT NOT NULL CHECK ("service" IN ('deepseek','jev')),
  "what" TEXT COLLATE NOCASE NOT NULL DEFAULT '',
  "user_id" INTEGER DEFAULT NULL,
  "project_id" INTEGER DEFAULT NULL,
  "model" TEXT COLLATE NOCASE NOT NULL DEFAULT '',
  "tokens_in" INTEGER NOT NULL DEFAULT 0,
  "tokens_out" INTEGER NOT NULL DEFAULT 0,
  "ms" INTEGER NOT NULL DEFAULT 0,
  "ok" INTEGER NOT NULL DEFAULT 1,
  "error" TEXT COLLATE NOCASE NOT NULL DEFAULT ''
);
CREATE INDEX "api_calls__ix_api_calls_at" ON "api_calls" ("created_at");
CREATE INDEX "api_calls__ix_api_calls_user" ON "api_calls" ("user_id","created_at");
CREATE INDEX "api_calls__ix_api_calls_project" ON "api_calls" ("project_id","created_at");


-- ── Служебные ──

CREATE TABLE "journal" (
  "id" INTEGER PRIMARY KEY AUTOINCREMENT,
  "project_id" INTEGER NOT NULL,
  "rev" INTEGER NOT NULL DEFAULT 0,
  "run_id" INTEGER DEFAULT NULL,
  "op_scope" TEXT NOT NULL DEFAULT '',
  "operation_id" TEXT COLLATE NOCASE DEFAULT NULL,
  "request_hash" TEXT DEFAULT NULL,
  "via" TEXT NOT NULL DEFAULT 'api' CHECK ("via" IN ('editor','api','assistant','constructor','tool','system','legacy')),
  "user_id" INTEGER DEFAULT NULL,
  "token_id" INTEGER DEFAULT NULL,
  "agent_id" INTEGER DEFAULT NULL,
  "label" TEXT COLLATE NOCASE NOT NULL DEFAULT '',
  "warnings" TEXT DEFAULT NULL CHECK ("warnings" is null or json_valid("warnings")),
  "created_at" TEXT NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f','now','localtime')),
  CONSTRAINT "fk_journal_agent" FOREIGN KEY ("agent_id") REFERENCES "agents" ("id") ON DELETE SET NULL,
  CONSTRAINT "fk_journal_project" FOREIGN KEY ("project_id") REFERENCES "projects" ("id") ON DELETE CASCADE,
  CONSTRAINT "fk_journal_run" FOREIGN KEY ("run_id") REFERENCES "runs" ("id") ON DELETE SET NULL,
  CONSTRAINT "fk_journal_token" FOREIGN KEY ("token_id") REFERENCES "tokens" ("id") ON DELETE SET NULL,
  CONSTRAINT "fk_journal_user" FOREIGN KEY ("user_id") REFERENCES "users" ("id") ON DELETE SET NULL
);
CREATE UNIQUE INDEX "journal__uq_journal_op" ON "journal" ("project_id","op_scope","operation_id");
CREATE INDEX "journal__ix_journal_project" ON "journal" ("project_id","id");
CREATE INDEX "journal__ix_journal_time" ON "journal" ("project_id","created_at");
CREATE INDEX "journal__ix_journal_agent" ON "journal" ("agent_id","created_at");
CREATE INDEX "journal__fk_journal_run" ON "journal" ("run_id");
CREATE INDEX "journal__fk_journal_user" ON "journal" ("user_id");
CREATE INDEX "journal__fk_journal_token" ON "journal" ("token_id");


CREATE TABLE "journal_ops" (
  "id" INTEGER PRIMARY KEY AUTOINCREMENT,
  "journal_id" INTEGER NOT NULL,
  "n" INTEGER NOT NULL,
  "op" TEXT COLLATE NOCASE NOT NULL,
  "target_id" INTEGER DEFAULT NULL,
  "target_no" INTEGER DEFAULT NULL,
  "target_ref" TEXT COLLATE NOCASE NOT NULL DEFAULT '',
  "folder_id" INTEGER DEFAULT NULL,
  "args" TEXT DEFAULT NULL CHECK ("args" is null or json_valid("args")),
  "prev" TEXT DEFAULT NULL CHECK ("prev" is null or json_valid("prev")),
  "result" TEXT DEFAULT NULL CHECK ("result" is null or json_valid("result")),
  CONSTRAINT "fk_journal_ops_folder" FOREIGN KEY ("folder_id") REFERENCES "folders" ("id") ON DELETE SET NULL,
  CONSTRAINT "fk_journal_ops_journal" FOREIGN KEY ("journal_id") REFERENCES "journal" ("id") ON DELETE CASCADE
);
CREATE UNIQUE INDEX "journal_ops__uq_journal_ops" ON "journal_ops" ("journal_id","n");
CREATE INDEX "journal_ops__ix_journal_ops_target" ON "journal_ops" ("target_id");
CREATE INDEX "journal_ops__ix_journal_ops_folder" ON "journal_ops" ("folder_id");


CREATE TABLE "deletions" (
  "id" INTEGER PRIMARY KEY AUTOINCREMENT,
  "project_id" INTEGER NOT NULL,
  "entity" TEXT NOT NULL CHECK ("entity" IN ('folder','element','agent','asset','run')),
  "entity_id" INTEGER NOT NULL,
  "no" INTEGER DEFAULT NULL,
  "folder_id" INTEGER DEFAULT NULL,
  "title" TEXT COLLATE NOCASE NOT NULL DEFAULT '',
  "rev" INTEGER NOT NULL,
  "created_at" TEXT NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f','now','localtime')),
  CONSTRAINT "fk_deletions_project" FOREIGN KEY ("project_id") REFERENCES "projects" ("id") ON DELETE CASCADE
);
CREATE INDEX "deletions__ix_deletions_rev" ON "deletions" ("project_id","rev");
CREATE INDEX "deletions__ix_deletions_entity" ON "deletions" ("project_id","entity","entity_id");


CREATE TABLE "visits" (
  "visitor" TEXT NOT NULL,
  "url_key" TEXT NOT NULL,
  "project_id" INTEGER DEFAULT NULL,
  "user_id" INTEGER DEFAULT NULL,
  "hits" INTEGER NOT NULL DEFAULT 0,
  "first_seen" TEXT NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f','now','localtime')),
  "last_seen" TEXT NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f','now','localtime')),
  "user_agent" TEXT COLLATE NOCASE NOT NULL DEFAULT '',
  "ip" TEXT COLLATE NOCASE NOT NULL DEFAULT '',
  PRIMARY KEY ("visitor","url_key"),
  CONSTRAINT "fk_visits_project" FOREIGN KEY ("project_id") REFERENCES "projects" ("id") ON DELETE SET NULL,
  CONSTRAINT "fk_visits_user" FOREIGN KEY ("user_id") REFERENCES "users" ("id") ON DELETE SET NULL
);
CREATE INDEX "visits__ix_visits_project" ON "visits" ("project_id");
CREATE INDEX "visits__ix_visits_user" ON "visits" ("user_id","last_seen");
CREATE INDEX "visits__ix_visits_time" ON "visits" ("visitor","last_seen");
CREATE TRIGGER "tr_visits_last_seen" AFTER UPDATE ON "visits" FOR EACH ROW WHEN NEW."last_seen" IS OLD."last_seen"
BEGIN UPDATE "visits" SET "last_seen" = (strftime('%Y-%m-%d %H:%M:%f','now','localtime')) WHERE rowid = NEW.rowid; END;


-- ── Документация ──

CREATE TABLE "documentation_pages" (
  "id" INTEGER PRIMARY KEY AUTOINCREMENT,
  "lang" TEXT COLLATE NOCASE NOT NULL DEFAULT 'ru',
  "parent_id" INTEGER DEFAULT NULL,
  "slug" TEXT NOT NULL,
  "title" TEXT COLLATE NOCASE NOT NULL,
  "summary_html" TEXT NOT NULL,
  "body_html" TEXT NOT NULL,
  "keywords" TEXT NOT NULL DEFAULT '[]' CHECK (json_valid("keywords")),
  "sort_order" INTEGER NOT NULL DEFAULT 0,
  "created_at" TEXT NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f','now','localtime')),
  "updated_at" TEXT NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f','now','localtime')),
  "status" TEXT DEFAULT NULL CHECK ("status" IN ('proposed','approved','implemented') OR "status" IS NULL),
  "reviewed_at" TEXT DEFAULT NULL,
  "source_note" TEXT DEFAULT NULL,
  "references_json" TEXT DEFAULT NULL CHECK ("references_json" is null or json_valid("references_json")),
  CONSTRAINT "fk_documentation_parent" FOREIGN KEY ("parent_id") REFERENCES "documentation_pages" ("id")
);
CREATE UNIQUE INDEX "documentation_pages__uq_documentation_slug" ON "documentation_pages" ("lang","slug");
CREATE INDEX "documentation_pages__ix_documentation_tree" ON "documentation_pages" ("parent_id","sort_order","id");
CREATE TRIGGER "tr_documentation_pages_updated_at" AFTER UPDATE ON "documentation_pages" FOR EACH ROW WHEN NEW."updated_at" IS OLD."updated_at"
BEGIN UPDATE "documentation_pages" SET "updated_at" = (strftime('%Y-%m-%d %H:%M:%f','now','localtime')) WHERE rowid = NEW.rowid; END;


CREATE TABLE "documentation_links" (
  "id" INTEGER PRIMARY KEY AUTOINCREMENT,
  "source_page_id" INTEGER NOT NULL,
  "target_page_id" INTEGER NOT NULL,
  "label" TEXT COLLATE NOCASE NOT NULL DEFAULT '',
  "resource_type" TEXT NOT NULL DEFAULT 'related' CHECK ("resource_type" IN ('related','php','html','css','javascript','database')),
  "sort_order" INTEGER NOT NULL DEFAULT 0,
  "created_at" TEXT NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f','now','localtime')),
  "updated_at" TEXT NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f','now','localtime')),
  CONSTRAINT "fk_documentation_link_source" FOREIGN KEY ("source_page_id") REFERENCES "documentation_pages" ("id") ON DELETE CASCADE,
  CONSTRAINT "fk_documentation_link_target" FOREIGN KEY ("target_page_id") REFERENCES "documentation_pages" ("id") ON DELETE CASCADE
);
CREATE UNIQUE INDEX "documentation_links__uq_documentation_link" ON "documentation_links" ("source_page_id","target_page_id");
CREATE INDEX "documentation_links__ix_documentation_outgoing" ON "documentation_links" ("source_page_id","sort_order","id");
CREATE INDEX "documentation_links__ix_documentation_incoming" ON "documentation_links" ("target_page_id","source_page_id");
CREATE TRIGGER "tr_documentation_links_updated_at" AFTER UPDATE ON "documentation_links" FOR EACH ROW WHEN NEW."updated_at" IS OLD."updated_at"
BEGIN UPDATE "documentation_links" SET "updated_at" = (strftime('%Y-%m-%d %H:%M:%f','now','localtime')) WHERE rowid = NEW.rowid; END;


CREATE TABLE "documentation_callouts" (
  "id" INTEGER PRIMARY KEY AUTOINCREMENT,
  "page_id" INTEGER NOT NULL,
  "entry_key" TEXT NOT NULL,
  "kind" TEXT NOT NULL CHECK ("kind" IN ('future_plan','agent_correction')),
  "body_html" TEXT NOT NULL,
  "author_name" TEXT COLLATE NOCASE NOT NULL DEFAULT '',
  "sort_order" INTEGER NOT NULL DEFAULT 0,
  "created_at" TEXT NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f','now','localtime')),
  "updated_at" TEXT NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f','now','localtime')),
  "approval" TEXT NOT NULL DEFAULT 'pending' CHECK ("approval" IN ('pending','approved','rejected')),
  "reviewed_by" TEXT COLLATE NOCASE NOT NULL DEFAULT '',
  "reviewed_at" TEXT DEFAULT NULL,
  CONSTRAINT "fk_doc_callout_page" FOREIGN KEY ("page_id") REFERENCES "documentation_pages" ("id") ON DELETE CASCADE
);
CREATE UNIQUE INDEX "documentation_callouts__uq_doc_callout_key" ON "documentation_callouts" ("page_id","entry_key");
CREATE INDEX "documentation_callouts__ix_doc_callout_page" ON "documentation_callouts" ("page_id","sort_order","id");
CREATE INDEX "documentation_callouts__ix_doc_callout_kind" ON "documentation_callouts" ("kind","page_id");
CREATE TRIGGER "tr_documentation_callouts_updated_at" AFTER UPDATE ON "documentation_callouts" FOR EACH ROW WHEN NEW."updated_at" IS OLD."updated_at"
BEGIN UPDATE "documentation_callouts" SET "updated_at" = (strftime('%Y-%m-%d %H:%M:%f','now','localtime')) WHERE rowid = NEW.rowid; END;


CREATE TABLE "schema_migrations" (
  "name" TEXT COLLATE NOCASE NOT NULL,
  "applied_at" TEXT NOT NULL DEFAULT (strftime('%Y-%m-%d %H:%M:%f','now','localtime')),
  "took_ms" INTEGER NOT NULL DEFAULT 0,
  PRIMARY KEY ("name")
);



-- Новая база уже на уровне всех миграций.
INSERT INTO schema_migrations (name) VALUES
  ('001-v2.sql'),
  ('002-templates.sql'),
  ('003-table.sql'),
  ('004-agent-token.sql'),
  ('005-cover-from-run.sql'),
  ('006-run-events.sql'),
  ('007-engine.sql'),
  ('008-link.sql'),
  ('009-lead-driver.sql'),
  ('010-result-text.sql'),
  ('011-run-env.sql'),
  ('012-ai-think.sql'),
  ('013-ai-chat-folder.sql'),
  ('014-documentation.sql'),
  ('015-documentation-link-types.sql'),
  ('016-documentation-callouts.sql'),
  ('017-documentation-workflow.sql'),
  ('018-role-scheme.sql'),
  ('019-lead-seen.sql'),
  ('020-run-events.sql'),
  ('021-catalog.sql'),
  ('022-docs-lang.sql'),
  ('023-project-lang.sql'),
  ('024-catalog-i18n.sql'),
  ('025-user-access.sql'),
  ('026-run-events-run-fk.sql'),
  ('027-run-steps-who.sql'),
  ('028-run-steps-lead-self.sql'),
  ('029-runs-agent-dir.sql'),
  ('030-projects-seen-at.sql'),
  ('031-run-steps-delivered.sql'),
  ('032-folders-drop-lead-seen.sql'),
  ('033-api-calls.sql'),
  ('034-projects-files-server.sql');

PRAGMA foreign_keys = ON;
