-- Тип связи определяет группу в нижнем блоке технических ссылок.
-- Целью остаётся статья документации, в том числе описание ресурса в бэкэнде.
ALTER TABLE documentation_links
  ADD COLUMN IF NOT EXISTS resource_type ENUM('related', 'php', 'html', 'css', 'javascript', 'database')
  NOT NULL DEFAULT 'related' AFTER label;
