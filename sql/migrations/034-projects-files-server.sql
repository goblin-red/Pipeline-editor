-- Галочка проекта «Хранить материалы на сервере» (веб, решение хозяина 01.10.2026). По умолчанию — нет:
-- материалы лежат у человека в папке схемы (in/), на сервер — с лимитами бесплатного аккаунта (files_limit_*).
ALTER TABLE projects ADD COLUMN files_server tinyint(1) NOT NULL DEFAULT 0 AFTER lang;
