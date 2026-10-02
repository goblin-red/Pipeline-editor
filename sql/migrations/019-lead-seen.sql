-- Когда ведущий последний раз взял инструкцию прогона папки (docs.run, docs.get file=leader).
-- По ней холст подсвечивает стартер: ведущий на связи, прогон вот-вот начнётся.
-- Смысла схемы не меняет — ревизию не двигает.

ALTER TABLE folders
  ADD COLUMN lead_seen_at DATETIME(3) NULL AFTER role_scheme;
