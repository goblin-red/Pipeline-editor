-- Готовые схемы: библиотека заготовок, из которых разворачивается папка.
-- Живёт вне проектов: одна и та же заготовка нужна во многих проектах,
-- а внутри проекта ей пришлось бы лежать двадцать раз.

CREATE TABLE templates (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `key`        VARCHAR(64)  NOT NULL,                       -- человеческий ключ: flow-review-loop
  `title`      VARCHAR(255) NOT NULL,
  `family`     ENUM('scheme','flow') NOT NULL DEFAULT 'scheme',  -- схема-образец или демо-прогон
  `category`   VARCHAR(64)  NOT NULL DEFAULT '',
  `about`      VARCHAR(512) NOT NULL DEFAULT '',
  `body`       LONGTEXT     NOT NULL,                       -- {"elements":[…]} в понятиях v2
  `sort`       INT          NOT NULL DEFAULT 0,
  `builtin`    TINYINT(1)   NOT NULL DEFAULT 0,             -- встроенную нельзя удалить
  `owner_id`   BIGINT UNSIGNED NULL,                        -- кто сохранил свою
  `created_at` DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
  `updated_at` DATETIME(3)  NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_templates_key` (`key`),
  KEY `ix_templates_shelf` (`family`, `sort`, `id`),
  CONSTRAINT `fk_templates_owner` FOREIGN KEY (`owner_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
