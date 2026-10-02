-- Применена. Новый тип элемента: ярлык-ссылка (link).
-- Концы from_id / to_id теперь обязательны и у стрелки, и у ярлыка.
-- Перед применением убедиться, что список типов совпадает с живой базой:
--   SHOW CREATE TABLE elements;

ALTER TABLE elements
  MODIFY COLUMN type ENUM('block','decision','gateway','arrow','group','area','note','table','link') NOT NULL;

ALTER TABLE elements DROP CONSTRAINT ck_elements_arrow;
ALTER TABLE elements ADD CONSTRAINT ck_elements_arrow
  CHECK ((type IN ('arrow','link')) = (from_id IS NOT NULL AND to_id IS NOT NULL));

-- ck_elements_branch остаётся как есть: branch и back бывают только у стрелки, у ярлыка их нет.
