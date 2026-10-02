-- Журнал вызовов платных моделей: DeepSeek и Jev — строка на вызов (lib/ai/calls.php), админка → «ИИ».
-- Без внешних ключей: расход остаётся и после удаления проекта или человека.
CREATE TABLE api_calls (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  created_at datetime(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  service enum('deepseek','jev') NOT NULL,
  what varchar(24) NOT NULL DEFAULT '',
  user_id bigint(20) unsigned DEFAULT NULL,
  project_id bigint(20) unsigned DEFAULT NULL,
  model varchar(80) NOT NULL DEFAULT '',
  tokens_in int(10) unsigned NOT NULL DEFAULT 0,
  tokens_out int(10) unsigned NOT NULL DEFAULT 0,
  ms int(10) unsigned NOT NULL DEFAULT 0,
  ok tinyint(1) NOT NULL DEFAULT 1,
  error varchar(300) NOT NULL DEFAULT '',
  PRIMARY KEY (id),
  KEY ix_api_calls_at (created_at),
  KEY ix_api_calls_user (user_id, created_at),
  KEY ix_api_calls_project (project_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Прошлое DeepSeek — по заданиям ИИ (одно задание могло быть двумя вызовами: переспрос формата).
INSERT INTO api_calls (created_at, service, what, user_id, project_id, model, tokens_in, tokens_out, ms, ok, error)
SELECT j.created_at, 'deepseek', j.kind, j.user_id, c.project_id, j.model, j.tokens_prompt, j.tokens_completion,
       GREATEST(0, COALESCE(TIMESTAMPDIFF(MICROSECOND, j.started_at, j.finished_at) DIV 1000, 0)),
       j.state <> 'failed', LEFT(COALESCE(j.error, ''), 300)
  FROM ai_jobs j JOIN ai_chats c ON c.id = j.chat_id
 WHERE j.started_at IS NOT NULL;

-- Прошлое Jev — по ответам в журналах прогонов (токенов там не записано).
INSERT INTO api_calls (created_at, service, what, user_id, project_id, ms, ok)
SELECT e.`at`, 'jev', IF(e.meta LIKE '%jev-branch%', 'branch', 'step'), p.owner_id, e.project_id,
       COALESCE(e.took_ms, 0), 1
  FROM run_events e JOIN projects p ON p.id = e.project_id
 WHERE e.kind = 'jev-say';
