-- Migration: 004_remove_billing_status.sql
-- Removes payment-related columns from billing table since payments are out-of-scope.

-- Drop foreign key constraint on processed_by then remove related columns
ALTER TABLE `billing` 
  DROP FOREIGN KEY IF EXISTS `fk_billing_processed_by`;
ALTER TABLE `billing` 
  DROP INDEX IF EXISTS `fk_billing_processed_by`;
ALTER TABLE `billing` 
  DROP COLUMN IF EXISTS `processed_by`;
ALTER TABLE `billing` 
  DROP COLUMN IF EXISTS `status`;