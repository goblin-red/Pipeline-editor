-- Иерархия ролей папки: как распределены роли в прогоне.
-- solo — ведущий делает всё сам; leader-worker — ведущий и воркер.
-- Потом добавятся схемы с консультантом и аудитором.
-- По ней ссылка 🔗 (docs.run) показывает строку «Иерархия ролей».

ALTER TABLE folders
  ADD COLUMN role_scheme VARCHAR(24) NOT NULL DEFAULT 'solo' AFTER run_env;
