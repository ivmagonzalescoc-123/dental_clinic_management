-- Migration: 003_drop_payment_table.sql
-- Removes the payment table because this deployment only generates invoices (no payment records).

DROP TABLE IF EXISTS `payment`;