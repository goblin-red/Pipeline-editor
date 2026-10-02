-- Документация на двух языках: у статьи — язык, адрес (slug) уникален внутри языка.
-- Русские статьи — md_backend/документация/ru/content.json, английские — en/content.json;
-- publish.php --lang=… публикует свой язык, страница чтения показывает язык человека (cookie goblin_lang).

ALTER TABLE documentation_pages
  ADD COLUMN lang char(2) CHARACTER SET ascii NOT NULL DEFAULT 'ru' AFTER id,
  DROP INDEX uq_documentation_slug,
  ADD UNIQUE KEY uq_documentation_slug (lang, slug);
