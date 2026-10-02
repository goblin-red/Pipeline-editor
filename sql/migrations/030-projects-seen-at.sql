-- Когда проект открывали в последний раз (project.get, не чаще раза в час). По нему и по правкам
-- уборка удаляет проекты гостей, которые давно никто не открывал (guest_days в настройках).
ALTER TABLE projects ADD COLUMN seen_at datetime(3) DEFAULT NULL AFTER updated_at;
