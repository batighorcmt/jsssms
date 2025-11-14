-- Migration: Add unique constraints for exam room invigilation
-- Ensures one teacher per plan/date and one room per plan/date

CREATE TABLE IF NOT EXISTS `exam_room_invigilation` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `duty_date` DATE NOT NULL,
  `plan_id` INT NOT NULL,
  `room_id` INT NOT NULL,
  `teacher_user_id` INT NOT NULL,
  `assigned_by` INT NOT NULL DEFAULT 0,
  `assigned_at` TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  INDEX `idx_invig_plan_date` (`plan_id`, `duty_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Clean duplicates if any (keep highest id)
DELETE t1 FROM `exam_room_invigilation` t1
JOIN `exam_room_invigilation` t2
  ON t1.`duty_date`=t2.`duty_date` AND t1.`plan_id`=t2.`plan_id` AND t1.`room_id`=t2.`room_id`
 WHERE t1.`id` < t2.`id`;

DELETE t1 FROM `exam_room_invigilation` t1
JOIN `exam_room_invigilation` t2
  ON t1.`duty_date`=t2.`duty_date` AND t1.`plan_id`=t2.`plan_id` AND t1.`teacher_user_id`=t2.`teacher_user_id`
 WHERE t1.`id` < t2.`id`;

-- Add unique keys (ignore if already exist)
ALTER TABLE `exam_room_invigilation`
  ADD UNIQUE KEY `uniq_invig_date_plan_room` (`duty_date`, `plan_id`, `room_id`),
  ADD UNIQUE KEY `uniq_invig_date_plan_teacher` (`duty_date`, `plan_id`, `teacher_user_id`);
