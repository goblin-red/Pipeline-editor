-- Каталог на других языках: перевод заготовки и раздела — в поле i18n, основные поля остаются русскими.
-- {"en": {"title": …, "about": …, "body": {…}, "passport": {…}}}; раздел — без body.
-- Язык выбирает проект (projects.lang): ТЗ схемы читают его агенты. Перевода нет — русская версия.
ALTER TABLE templates           ADD COLUMN i18n longtext NOT NULL DEFAULT '{}' AFTER passport;
ALTER TABLE template_categories ADD COLUMN i18n longtext NOT NULL DEFAULT '{}' AFTER passport;
