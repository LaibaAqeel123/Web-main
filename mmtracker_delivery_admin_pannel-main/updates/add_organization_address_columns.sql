-- Add missing columns to Organizations table
ALTER TABLE `Organizations` ADD COLUMN `city` varchar(100) DEFAULT NULL AFTER `address`;
ALTER TABLE `Organizations` ADD COLUMN `postal_code` varchar(20) DEFAULT NULL AFTER `city`;
ALTER TABLE `Organizations` ADD COLUMN `country` varchar(100) DEFAULT 'United Kingdom' AFTER `postal_code`; 