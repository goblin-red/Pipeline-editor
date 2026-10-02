-- Папка разговора с ИИ: история чатов (панель ✦ и значок истории в окне чата)
-- показывает только разговоры открытой папки. Разговор помнит папку, где его начали.
--
-- Бывшим разговорам папку даёт их первое задание (ai_jobs.context.folder).
-- Нет задания или папку уже удалили — поле остаётся пустым, разговор виден только в кабинете.
-- updated_at = updated_at: иначе заполнение сдвинуло бы время всех разговоров на «сейчас».

ALTER TABLE ai_chats
  ADD COLUMN folder_id BIGINT UNSIGNED NULL AFTER project_key,
  ADD KEY ix_chats_folder (folder_id, updated_at),
  ADD CONSTRAINT fk_chats_folder FOREIGN KEY (folder_id) REFERENCES folders (id) ON DELETE SET NULL;

UPDATE ai_chats c
  JOIN ai_jobs j ON j.id = (SELECT MIN(j2.id) FROM ai_jobs j2 WHERE j2.chat_id = c.id)
  JOIN folders f ON f.id = JSON_VALUE(j.context, '$.folder') AND f.project_id = c.project_id
   SET c.folder_id = f.id, c.updated_at = c.updated_at;
