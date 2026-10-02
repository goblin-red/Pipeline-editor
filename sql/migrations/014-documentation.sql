-- Материалы документации: дерево рубрик, постоянные адреса и связи.
-- Текст хранится отдельно от оформления PHP-страницы.
CREATE TABLE IF NOT EXISTS documentation_pages (
  id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  parent_id    BIGINT UNSIGNED NULL,
  slug         VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  title        VARCHAR(512) NOT NULL,
  summary_html MEDIUMTEXT NOT NULL,
  body_html    LONGTEXT NOT NULL,
  keywords     LONGTEXT NOT NULL DEFAULT '[]' CHECK (JSON_VALID(keywords)),
  sort_order   INT NOT NULL DEFAULT 0,
  created_at   DATETIME(3) NOT NULL DEFAULT NOW(3),
  updated_at   DATETIME(3) NOT NULL DEFAULT NOW(3) ON UPDATE NOW(3),
  PRIMARY KEY (id),
  UNIQUE KEY uq_documentation_slug (slug),
  KEY ix_documentation_tree (parent_id, sort_order, id),
  CONSTRAINT fk_documentation_parent FOREIGN KEY (parent_id)
    REFERENCES documentation_pages (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Каждая направленная ссылка — самостоятельная запись со своим id.
-- В авторском тексте [[ID|текст ссылки]] ссылается на эту запись.
CREATE TABLE IF NOT EXISTS documentation_links (
  id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  source_page_id BIGINT UNSIGNED NOT NULL,
  target_page_id BIGINT UNSIGNED NOT NULL,
  label          VARCHAR(512) NOT NULL DEFAULT '',
  sort_order     INT NOT NULL DEFAULT 0,
  created_at     DATETIME(3) NOT NULL DEFAULT NOW(3),
  updated_at     DATETIME(3) NOT NULL DEFAULT NOW(3) ON UPDATE NOW(3),
  PRIMARY KEY (id),
  UNIQUE KEY uq_documentation_link (source_page_id, target_page_id),
  KEY ix_documentation_outgoing (source_page_id, sort_order, id),
  KEY ix_documentation_incoming (target_page_id, source_page_id),
  CONSTRAINT fk_documentation_link_source FOREIGN KEY (source_page_id)
    REFERENCES documentation_pages (id) ON DELETE CASCADE,
  CONSTRAINT fk_documentation_link_target FOREIGN KEY (target_page_id)
    REFERENCES documentation_pages (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
