-- Среда прогона папки: как ведущий связан с воркерами.
-- orca — отдельные терминалы Orca; sendmessage — сессии Claude (SendMessage);
-- subagents — воркеры-субагенты внутри терминала ведущего; '' — не выбрана.
-- По ней ссылка 🔗 (docs.run) и op=invite дают агентам инструкцию только для этой среды.

ALTER TABLE folders
  ADD COLUMN run_env VARCHAR(16) NOT NULL DEFAULT '' AFTER share_scheme;
