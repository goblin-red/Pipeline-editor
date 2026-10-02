-- Язык агентов и встроенного ИИ проекта: инструкции ведущего и worker, задания прогона, лента.
-- Интерфейс каждый человек выбирает сам (cookie goblin_lang); у прогона язык один — проекта.
ALTER TABLE projects
  ADD COLUMN lang char(2) CHARACTER SET ascii NOT NULL DEFAULT 'ru' AFTER title;
