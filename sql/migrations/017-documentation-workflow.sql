-- Статус материала и явные дополнительные связи; NULL — ещё не классифицирован.
ALTER TABLE documentation_pages
 ADD COLUMN IF NOT EXISTS status ENUM('proposed','approved','implemented') NULL DEFAULT NULL,
 ADD COLUMN IF NOT EXISTS reviewed_at DATE NULL DEFAULT NULL,
 ADD COLUMN IF NOT EXISTS source_note TEXT NULL,
 ADD COLUMN IF NOT EXISTS references_json LONGTEXT NULL CHECK (references_json IS NULL OR JSON_VALID(references_json));
ALTER TABLE documentation_callouts
 ADD COLUMN IF NOT EXISTS approval ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
 ADD COLUMN IF NOT EXISTS reviewed_by VARCHAR(255) NOT NULL DEFAULT '',
 ADD COLUMN IF NOT EXISTS reviewed_at DATETIME(3) NULL DEFAULT NULL;
