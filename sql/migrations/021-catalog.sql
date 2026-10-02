-- Каталог готовых схем: разделы с паспортами, у заготовок — паспорт и отметка «в каталоге».
-- Паспорт — знание о схеме для человека и для конструктора: для чего она, что на входе и выходе,
-- какие сервисы нужны, что настраивается, что спросить у человека, на чём спотыкались прогоны.
-- Из паспортов сервер собирает раздел «Опыт каталога» для конструктора (lib/templates/catalog.php).

CREATE TABLE IF NOT EXISTS template_categories (
  `key`      varchar(32)  NOT NULL,
  title      varchar(64)  NOT NULL,
  icon       varchar(32)  NOT NULL DEFAULT '',
  about      varchar(512) NOT NULL DEFAULT '',
  passport   longtext     NOT NULL DEFAULT '{}',
  sort       int          NOT NULL DEFAULT 0,
  updated_at datetime(3)  NOT NULL DEFAULT current_timestamp(3) ON UPDATE current_timestamp(3),
  PRIMARY KEY (`key`),
  KEY ix_categories_sort (sort)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE templates
  ADD COLUMN passport   longtext    NOT NULL DEFAULT '{}' AFTER body,
  ADD COLUMN in_catalog tinyint(1)  NOT NULL DEFAULT 0    AFTER passport,
  ADD COLUMN checked_at datetime(3) NULL                  AFTER in_catalog,
  ADD KEY ix_templates_catalog (in_catalog, category, sort);

INSERT IGNORE INTO template_categories (`key`, title, icon, about, sort) VALUES
  ('sites',  'Сайты и лендинги',     'globe',    'Лендинги, страницы товара, многостраничные сайты и доработка готовых', 10),
  ('photo',  'Фото',                 'image',    'Генерация, ретушь, вырезка фона, серии карточек товара',               20),
  ('video',  'Видео',                'film',     'Ролики из картинок, нарезка, озвученные клипы, анимация',              30),
  ('texts',  'Тексты и контент',     'text',     'Статьи, описания, переводы, редактура и контент-планы',                40),
  ('social', 'Соцсети и маркетинг',  'megaphone','Посты с картинками, рекламные креативы, серии публикаций',             50),
  ('docs',   'Документы и отчёты',   'file',     'Отчёты, КП, презентации и сводки из данных',                           60),
  ('data',   'Данные и API',         'database', 'Запросы к сервисам, выгрузки, таблицы и сводки',                       70),
  ('audio',  'Аудио и озвучка',      'music',    'Озвучка текста, подкасты, музыка и звуковые дорожки',                  80),
  ('code',   'Код и автоматизация',  'code',     'Скрипты, мини-приложения, проверки и автоматизация рутины',            90),
  ('basics', 'Основы Гоблина',       'book',     'Учебные схемы: цепочка, развилка, цикл, параллель, проверка',         100);
