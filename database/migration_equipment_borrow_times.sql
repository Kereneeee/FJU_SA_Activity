-- Migration: add borrow_start, borrow_end, reservation_id to equipment_borrow
-- Run once on the live database.

ALTER TABLE `equipment_borrow`
  ADD COLUMN IF NOT EXISTS `borrow_start`    DATETIME     NULL AFTER `quantity`,
  ADD COLUMN IF NOT EXISTS `borrow_end`      DATETIME     NULL AFTER `borrow_start`,
  ADD COLUMN IF NOT EXISTS `reservation_id`  INT(11)      NULL AFTER `borrow_end`;

-- FK to reservations (SET NULL so deleting a reservation doesn't orphan borrow rows)
ALTER TABLE `equipment_borrow`
  ADD CONSTRAINT IF NOT EXISTS `equipment_borrow_ibfk_res`
    FOREIGN KEY (`reservation_id`) REFERENCES `reservations` (`reservation_id`)
    ON DELETE SET NULL;
