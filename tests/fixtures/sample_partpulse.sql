-- phpMyAdmin SQL Dump
-- version 5.2.1
-- Host: localhost:3306
-- Generation Time: Oct 16, 2026 at 05:30 AM
-- Server version: 8.0.46
-- SYNTHETIC TEST DATA — not real PartPulse records.

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";

/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `partpulse_orders`
--

CREATE TABLE `users` (
  `id` int NOT NULL,
  `username` varchar(50) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `name` varchar(100) NOT NULL,
  `email` varchar(100) NOT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `role` enum('admin','procurement','requester','manager','accounting') NOT NULL,
  `building` varchar(10) DEFAULT NULL,
  `active` tinyint(1) DEFAULT '1'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `users` (`id`, `username`, `password_hash`, `name`, `email`, `phone`, `role`, `building`, `active`) VALUES
(1, 'admin', '$2y$10$SECRETSECRETSECRETSECRETSECRETSECRETSECRETSECRET', 'Test Admin', 'admin@example.com', '+359000000', 'admin', NULL, 1),
(2, 'req', '$2y$10$ANOTHERSECRETHASHANOTHERSECRETHASHANOTHERSECRETH', 'Иван Тестов', 'ivan@example.com', NULL, 'requester', 'CT', 1);

CREATE TABLE `buildings` (
  `id` int NOT NULL,
  `code` varchar(10) NOT NULL,
  `name` varchar(100) NOT NULL,
  `active` tinyint(1) DEFAULT '1'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `buildings` (`id`, `code`, `name`, `active`) VALUES
(1, 'CT', 'Cotton Tape', 1),
(2, 'WW', 'Wet Wipes', 1);

CREATE TABLE `suppliers` (
  `id` int NOT NULL,
  `name` varchar(150) NOT NULL,
  `contact_person` varchar(100) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `country` varchar(2) DEFAULT NULL,
  `is_eu` tinyint(1) DEFAULT '0',
  `active` tinyint(1) DEFAULT '1'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `suppliers` (`id`, `name`, `contact_person`, `email`, `phone`, `country`, `is_eu`, `active`) VALUES
(10, 'Bearing Co; Ltd', 'Secret Contact', 'sales@example.com', '+359111', 'BG', 1, 1),
(11, 'O\'Brien \"Tools\"', NULL, NULL, NULL, 'IE', 1, 1);

CREATE TABLE `cost_centers` (
  `id` int NOT NULL,
  `building_code` varchar(20) NOT NULL,
  `code` varchar(50) NOT NULL,
  `name` varchar(100) NOT NULL,
  `active` tinyint(1) DEFAULT '1'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `cost_centers` VALUES
(5, 'CT', 'CT-01', 'Line 1', 1);

CREATE TABLE `orders` (
  `id` int NOT NULL,
  `building` varchar(10) NOT NULL,
  `cost_center_id` int DEFAULT NULL,
  `item_description` text NOT NULL,
  `part_number` varchar(100) DEFAULT NULL,
  `category` varchar(50) DEFAULT NULL,
  `quantity` int NOT NULL,
  `date_needed` date DEFAULT NULL,
  `expected_delivery_date` date DEFAULT NULL,
  `notes` text,
  `priority` enum('Low','Normal','High','Urgent') DEFAULT 'Normal',
  `requester_id` int NOT NULL,
  `requester_name` varchar(100) NOT NULL,
  `requester_email` varchar(100) NOT NULL,
  `status` varchar(40) DEFAULT 'New',
  `supplier` varchar(100) DEFAULT NULL,
  `supplier_id` int DEFAULT NULL,
  `unit_price` decimal(10,2) DEFAULT '0.00',
  `total_price` decimal(12,2) DEFAULT '0.00',
  `assigned_to_user_id` int DEFAULT NULL,
  `submission_date` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `delivery_confirmed_at` timestamp NULL DEFAULT NULL,
  `is_template` tinyint(1) DEFAULT '0'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `orders` (`id`, `building`, `cost_center_id`, `item_description`, `part_number`, `category`, `quantity`, `date_needed`, `expected_delivery_date`, `notes`, `priority`, `requester_id`, `requester_name`, `requester_email`, `status`, `supplier`, `supplier_id`, `unit_price`, `total_price`, `assigned_to_user_id`, `submission_date`, `updated_at`, `delivery_confirmed_at`, `is_template`) VALUES
(101, 'CT', 5, 'Лагер 6205-2RS; spare (INSERT INTO `x` VALUES (1);)', '6205', 'Bearings', 4, '2026-10-05', '2026-10-06', 'private note -- keep out', 'Normal', 2, 'Old Name', 'ivan@example.com', 'Delivered', NULL, 10, 12.50, 50.00, 1, '2026-10-01 06:00:00', '2026-10-06 08:00:00', NULL, 0),
(102, 'WW', NULL, 'Belt \"XPZ\" 1250\nsecond line', NULL, NULL, 2, '2026-10-20', NULL, NULL, 'Urgent', 2, 'Old Name', 'ivan@example.com', 'Ordered', NULL, 11, 30.00, 60.00, 1, '2026-10-02 07:15:00', '2026-10-03 09:00:00', NULL, 0),
(103, 'CT', 5, 'Sensor M18', 'XS618', 'Electrical', 1, NULL, NULL, NULL, 'High', 1, 'Test Admin', 'admin@example.com', 'New', NULL, NULL, NULL, NULL, NULL, '2026-10-10 22:30:00', '2026-10-10 22:30:00', NULL, 0),
(104, 'CT', NULL, 'Template row', NULL, NULL, 1, NULL, NULL, NULL, 'Normal', 1, 'Test Admin', 'admin@example.com', 'New', NULL, NULL, NULL, NULL, NULL, '2026-10-01 00:00:00', '2026-10-01 00:00:00', NULL, 1),
(105, 'WW', NULL, 'Gloves', NULL, 'PPE', 10, NULL, NULL, NULL, 'Low', 2, 'Old Name', 'ivan@example.com', 'Cancelled', NULL, NULL, NULL, NULL, NULL, '2026-09-01 08:00:00', '2026-10-04 08:00:00', NULL, 0);

CREATE TABLE `order_history` (
  `id` int NOT NULL,
  `order_id` int NOT NULL,
  `changed_by` varchar(150) NOT NULL DEFAULT 'system',
  `field_name` varchar(100) NOT NULL,
  `old_value` text,
  `new_value` text,
  `changed_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `order_history` (`id`, `order_id`, `changed_by`, `field_name`, `old_value`, `new_value`, `changed_at`) VALUES
(1, 101, 'Иван Тестов', 'status', NULL, 'New', '2026-10-01 06:00:00'),
(2, 101, 'admin', 'status', 'New', 'Ordered', '2026-10-02 06:00:00'),
(3, 101, 'Test Admin', 'expected_delivery_date', NULL, 'Tue Oct 06 2026 00:00:00 GMT+0000 (Coordinated Universal Time)', '2026-10-02 06:01:00'),
(4, 101, 'Test Admin', 'status', 'Ordered', 'Delivered', '2026-10-06 08:00:00'),
(5, 102, 'Test Admin', 'status', 'New', 'Ordered', '2026-10-03 09:00:00'),
(6, 102, 'Test Admin', 'supplier_notes', NULL, 'Call Mr. X on his private phone', '2026-10-03 09:00:10'),
(7, 102, 'Test Admin', 'total_price', '0.00', '60.00', NULL);

CREATE TABLE `orders_audit_log` (
  `id` int NOT NULL,
  `order_id` int NOT NULL,
  `field_name` varchar(50) NOT NULL,
  `old_value` varchar(100) DEFAULT NULL,
  `new_value` varchar(100) DEFAULT NULL,
  `changed_by` int NOT NULL,
  `changed_at` datetime NOT NULL,
  `reason` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `orders_audit_log` (`id`, `order_id`, `field_name`, `old_value`, `new_value`, `changed_by`, `changed_at`, `reason`) VALUES
(1, 105, 'status', 'New', 'Cancelled', 1, '2026-10-04 08:00:00', 'Auto-cancelled: stale');

CREATE TABLE `user_login_log` (
  `id` int NOT NULL,
  `user_id` int NOT NULL,
  `ip` varchar(45) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO `user_login_log` VALUES (1, 1, '10.0.0.1');

DELIMITER $$
CREATE TRIGGER `trg_test` BEFORE UPDATE ON `orders` FOR EACH ROW BEGIN SET NEW.updated_at = NOW(); END$$
DELIMITER ;

ALTER TABLE `orders` ADD PRIMARY KEY (`id`);
COMMIT;
