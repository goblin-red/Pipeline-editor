-- Личный доступ к моделям: у пользователя свои адрес API, модель и ключ DeepSeek и голоса OpenAI.
-- JSON {поле: значение}, поля — ACCESS_FIELDS (lib/ai/access.php); пустое поле — общий доступ установки.
ALTER TABLE users ADD COLUMN access longtext NOT NULL DEFAULT '{}' AFTER name;
