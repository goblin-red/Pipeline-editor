-- Типизированные врезки: один оригинал в статье, планы также видны в общей подборке.
CREATE TABLE IF NOT EXISTS documentation_callouts (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  page_id BIGINT UNSIGNED NOT NULL,
  entry_key VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  kind ENUM('future_plan','agent_correction') NOT NULL,
  body_html MEDIUMTEXT NOT NULL,
  author_name VARCHAR(255) NOT NULL DEFAULT '',
  sort_order INT NOT NULL DEFAULT 0,
  created_at DATETIME(3) NOT NULL DEFAULT NOW(3),
  updated_at DATETIME(3) NOT NULL DEFAULT NOW(3) ON UPDATE NOW(3),
  PRIMARY KEY (id),
  UNIQUE KEY uq_doc_callout_key (page_id, entry_key),
  KEY ix_doc_callout_page (page_id, sort_order, id),
  KEY ix_doc_callout_kind (kind, page_id),
  CONSTRAINT fk_doc_callout_page FOREIGN KEY (page_id)
    REFERENCES documentation_pages (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
