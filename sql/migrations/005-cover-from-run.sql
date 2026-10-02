-- Обложка знает, откуда она.
--
-- У блока бывают две обложки: оформление схемы, которое поставил человек,
-- и картинка-результат, которую принёс прогон. Владелец ссылки при этом один
-- и тот же — элемент, поэтому происхождение пишем отдельным столбцом:
-- он не участвует в ck_links_owner.

ALTER TABLE asset_links
  ADD COLUMN made_by_run BIGINT UNSIGNED NULL AFTER step_id,
  ADD KEY idx_links_made_by_run (made_by_run);

-- Разовая пометка прежних обложек: если та же картинка была результатом шага,
-- значит обложку поставил прогон, а не человек.
UPDATE asset_links c
   JOIN (SELECT l.asset_id, MAX(s.run_id) AS run_id
           FROM asset_links l JOIN run_steps s ON s.id = l.step_id
          WHERE l.role = 'result' GROUP BY l.asset_id) r
     ON r.asset_id = c.asset_id
    SET c.made_by_run = r.run_id
  WHERE c.role = 'cover' AND c.element_id IS NOT NULL AND c.made_by_run IS NULL;
