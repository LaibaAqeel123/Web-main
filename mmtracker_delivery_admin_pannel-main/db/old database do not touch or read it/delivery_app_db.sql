-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: localhost
-- Generation Time: Jun 03, 2025 at 02:02 PM
-- Server version: 10.4.28-MariaDB
-- PHP Version: 8.2.4

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `delivery_app_db`
--

-- --------------------------------------------------------

--
-- Table structure for table `ActivityLogs`
--

CREATE TABLE `ActivityLogs` (
  `id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `timestamp` timestamp NOT NULL DEFAULT current_timestamp(),
  `ip_address` varchar(45) DEFAULT NULL,
  `action_type` varchar(50) NOT NULL,
  `target_type` varchar(50) DEFAULT NULL,
  `target_id` int(11) DEFAULT NULL,
  `details` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `Addresses`
--

CREATE TABLE `Addresses` (
  `id` int(11) NOT NULL,
  `customer_id` int(11) NOT NULL,
  `address_line1` varchar(255) NOT NULL,
  `address_line2` varchar(255) DEFAULT NULL,
  `city` varchar(100) NOT NULL,
  `state` varchar(100) DEFAULT NULL,
  `postal_code` varchar(20) NOT NULL,
  `country` varchar(100) NOT NULL,
  `latitude` decimal(10,8) DEFAULT NULL,
  `longitude` decimal(11,8) DEFAULT NULL,
  `is_default` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `Addresses`
--

INSERT INTO `Addresses` (`id`, `customer_id`, `address_line1`, `address_line2`, `city`, `state`, `postal_code`, `country`, `latitude`, `longitude`, `is_default`, `created_at`, `updated_at`) VALUES
(10, 10, '221B Baker Street', '', 'London', 'Greater London', 'NW1 6XE', 'United Kingdom', NULL, NULL, 0, '2025-04-29 13:22:36', '2025-04-29 13:22:36'),
(11, 11, '1 Construction Way', 'Unit B', 'Manchester', 'Greater Manchester', 'M1 1AE', 'United Kingdom', NULL, NULL, 0, '2025-04-29 13:22:36', '2025-04-29 13:22:36'),
(12, 12, '89 Silent Film Rd', '', 'Birmingham', 'West Midlands', 'B1 1AA', 'United Kingdom', NULL, NULL, 0, '2025-04-29 13:22:36', '2025-04-29 13:22:36'),
(13, 11, '1 Construction Way', 'Unit B', 'Manchester', 'Greater Manchester', '12345', 'United Kingdom', NULL, NULL, 1, '2025-04-30 12:06:36', '2025-04-30 12:06:36');

-- --------------------------------------------------------

--
-- Table structure for table `ApiKeys`
--

CREATE TABLE `ApiKeys` (
  `id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `api_key` varchar(64) NOT NULL,
  `description` text DEFAULT NULL,
  `created_by` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `last_used_at` timestamp NULL DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `ApiKeys`
--

INSERT INTO `ApiKeys` (`id`, `company_id`, `api_key`, `description`, `created_by`, `created_at`, `last_used_at`, `is_active`) VALUES
(1, 2, '1f514b040daa2a10e55c528d51f3ac6c33691394fbcb1fbd4a8cdf2b2c2b5864', 'for wordpress', 25, '2025-01-31 19:41:37', NULL, 0),
(2, 2, '4c596b4c3a70bf9e107b1a3c89bec0487ae810abb75490e59d6813d8fbba9e11', 'asd', 25, '2025-01-31 19:50:01', NULL, 0),
(3, 2, '0ccea1044dbc722f90c35ce575b1a5a3d856f90322a79dbcac642a685ee47228', 'asdasd', 25, '2025-01-31 19:50:04', NULL, 0),
(4, 2, '087f5452d31055d5bac94ad0fc09c23379ec6bb2bb5d7bd37080ddaa7f28f2cf', 'testing', 25, '2025-01-31 21:02:01', '2025-01-31 21:26:03', 1);

-- --------------------------------------------------------

--
-- Table structure for table `Companies`
--

CREATE TABLE `Companies` (
  `id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `address` text DEFAULT NULL,
  `total_riders_allowed` int(11) DEFAULT NULL,
  `default_requires_image_proof` tinyint(1) NOT NULL DEFAULT 1,
  `default_requires_signature_proof` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `Companies`
--

INSERT INTO `Companies` (`id`, `name`, `phone`, `email`, `address`, `total_riders_allowed`, `default_requires_image_proof`, `default_requires_signature_proof`, `created_at`, `updated_at`) VALUES
(2, 'solo', '2342', 'sdfv@gmail.com', 'sdvs', 5, 1, 1, '2024-12-19 20:02:06', '2024-12-25 12:50:43'),
(4, 'zolo', '2903298', 'zolo@gm.c', 'isb', 4, 1, 1, '2024-12-21 23:54:31', '2024-12-21 23:54:31'),
(5, 'Bizz VPN', '+923338417624', 'mnaveed@gmail.com', 'islamabad', 4, 1, 1, '2024-12-25 19:18:44', '2024-12-25 19:18:44');

-- --------------------------------------------------------

--
-- Table structure for table `Customers`
--

CREATE TABLE `Customers` (
  `id` int(11) NOT NULL,
  `company_id` int(11) DEFAULT NULL,
  `name` varchar(255) NOT NULL,
  `email` varchar(255) DEFAULT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `Customers`
--

INSERT INTO `Customers` (`id`, `company_id`, `name`, `email`, `phone`, `created_at`, `updated_at`) VALUES
(10, 2, 'Alice Wonderland', 'alice@example.com', '+442079460123', '2025-04-29 13:22:35', '2025-04-29 13:22:35'),
(11, 2, 'Bob The Builder', 'bob@example.com', '+441614960998', '2025-04-29 13:22:36', '2025-04-29 13:22:36'),
(12, 2, 'Charlie Chaplin', 'charlie@silentfilms.com', '+441214965567', '2025-04-29 13:22:36', '2025-04-29 13:22:36');

-- --------------------------------------------------------

--
-- Table structure for table `ExtraItemsLog`
--

CREATE TABLE `ExtraItemsLog` (
  `id` int(11) NOT NULL,
  `manifest_id` int(11) DEFAULT NULL,
  `rider_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `quantity` int(11) NOT NULL DEFAULT 1,
  `reason` varchar(50) NOT NULL,
  `source_order_id` int(11) DEFAULT NULL,
  `source_manifest_id` int(11) DEFAULT NULL COMMENT 'Manifest ID if related to warehouse scan extra',
  `status` varchar(50) DEFAULT 'pending' COMMENT 'e.g., pending, assigned, returned, resolved, disposed',
  `added_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `offloaded_at` timestamp NULL DEFAULT NULL,
  `notes` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `ManifestOrders`
--

CREATE TABLE `ManifestOrders` (
  `id` int(11) NOT NULL,
  `manifest_id` int(11) NOT NULL,
  `order_id` int(11) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `ManifestOrders`
--

INSERT INTO `ManifestOrders` (`id`, `manifest_id`, `order_id`) VALUES
(31, 21, 16),
(32, 22, 17),
(35, 24, 21),
(36, 24, 20),
(37, 24, 19),
(38, 26, 26),
(39, 26, 25),
(40, 27, 30),
(41, 27, 27),
(42, 28, 59);

-- --------------------------------------------------------

--
-- Table structure for table `Manifests`
--

CREATE TABLE `Manifests` (
  `id` int(11) NOT NULL,
  `status` enum('pending','assigned','delivering','delivered') NOT NULL DEFAULT 'pending',
  `rider_id` int(11) DEFAULT NULL,
  `total_orders_assigned` int(11) DEFAULT 0,
  `company_id` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `warehouse_id` int(11) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `Manifests`
--

INSERT INTO `Manifests` (`id`, `status`, `rider_id`, `total_orders_assigned`, `company_id`, `created_at`, `updated_at`, `warehouse_id`) VALUES
(21, 'assigned', 33, 1, 2, '2025-01-12 17:10:16', '2025-01-12 17:10:16', NULL),
(22, 'delivering', 33, 1, 2, '2025-01-16 14:36:53', '2025-01-16 14:49:30', NULL),
(24, 'delivering', 33, 2, 2, '2025-01-24 22:28:18', '2025-01-29 09:20:05', 3),
(26, 'assigned', 33, 2, 2, '2025-01-31 21:27:01', '2025-01-31 21:27:01', 3),
(27, 'assigned', 33, 2, 2, '2025-03-02 10:36:25', '2025-03-02 10:36:25', 3),
(28, 'assigned', 33, 1, 2, '2025-04-30 10:03:17', '2025-04-30 10:03:17', 3);

-- --------------------------------------------------------

--
-- Table structure for table `NotificationLogs`
--

CREATE TABLE `NotificationLogs` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `title` varchar(255) NOT NULL,
  `message` text NOT NULL,
  `status` enum('success','failed') NOT NULL,
  `response` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `NotificationLogs`
--

INSERT INTO `NotificationLogs` (`id`, `user_id`, `title`, `message`, `status`, `response`, `created_at`) VALUES
(1, 33, 'Manifest Status Updated', 'Manifest #24 status has been updated to Delivering', 'failed', 'The registration token is not a valid FCM registration token', '2025-01-29 09:20:08'),
(2, 33, 'New Manifest Assigned', 'You have been assigned a new manifest #26 from W3 (London) with 2 orders', 'failed', 'The registration token is not a valid FCM registration token', '2025-01-31 21:27:05'),
(3, 33, 'New Manifest Assigned', 'You have been assigned a new manifest #27 from W3 (London) with 2 orders', 'failed', 'The registration token is not a valid FCM registration token', '2025-03-02 10:36:27'),
(4, 33, 'New Manifest Assigned', 'You have been assigned a new manifest #28 from W3 (London) with 1 orders', 'failed', 'The registration token is not a valid FCM registration token', '2025-04-30 10:03:19');

-- --------------------------------------------------------

--
-- Table structure for table `Orders`
--

CREATE TABLE `Orders` (
  `id` int(11) NOT NULL,
  `order_number` varchar(50) NOT NULL,
  `status` enum('pending','assigned','delivering','delivered','failed') NOT NULL DEFAULT 'pending',
  `notes` text DEFAULT NULL,
  `total_amount` decimal(10,2) DEFAULT NULL,
  `company_id` int(11) NOT NULL,
  `customer_id` int(11) DEFAULT NULL,
  `delivery_address_id` int(11) DEFAULT NULL,
  `organization_id` int(11) DEFAULT NULL,
  `drop_number` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `delivery_date` date DEFAULT NULL,
  `requires_image_proof` tinyint(1) DEFAULT NULL,
  `requires_signature_proof` tinyint(1) DEFAULT NULL,
  `proof_photo_url` varchar(255) DEFAULT NULL COMMENT 'Path to final delivery photo proof',
  `proof_signature_path` varchar(255) DEFAULT NULL COMMENT 'Path to final delivery signature proof',
  `latitude` decimal(10,7) DEFAULT NULL,
  `longitude` decimal(10,7) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `Orders`
--

INSERT INTO `Orders` (`id`, `order_number`, `status`, `notes`, `total_amount`, `company_id`, `customer_id`, `delivery_address_id`, `organization_id`, `drop_number`, `created_at`, `updated_at`, `delivery_date`, `requires_image_proof`, `requires_signature_proof`, `proof_photo_url`, `proof_signature_path`, `latitude`, `longitude`) VALUES
(16, 'ORD-20250109-2747', 'assigned', '', 27.00, 2, NULL, NULL, NULL, NULL, '2025-01-09 10:55:25', '2025-01-12 17:10:16', NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(17, 'ORD-20250116-BECA', 'delivering', '', 0.00, 2, NULL, NULL, NULL, NULL, '2025-01-16 14:28:39', '2025-01-16 14:49:30', NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(18, 'ORD-20250124-3B0F', 'pending', '', 9.00, 2, NULL, NULL, NULL, NULL, '2025-01-24 22:25:12', '2025-01-29 09:07:41', NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(19, 'ORD-20250128-AE4A', 'delivered', '', 2.00, 2, NULL, NULL, NULL, NULL, '2025-01-28 06:48:37', '2025-01-29 09:47:42', NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(20, 'ORD-20250128-C8BA', 'failed', '', 3.00, 2, NULL, NULL, NULL, NULL, '2025-01-28 06:49:07', '2025-01-29 09:46:46', NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(21, 'ORD-20250128-6D84', 'delivered', '', 3.00, 2, NULL, NULL, NULL, NULL, '2025-01-28 06:49:42', '2025-01-29 09:30:30', NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(22, 'ORD-20250130-343D', 'pending', '', 14.00, 2, NULL, NULL, NULL, NULL, '2025-01-30 19:20:51', '2025-01-30 20:27:42', NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(23, 'ORD-20250130-10CE', 'pending', '', 0.00, 2, NULL, NULL, NULL, NULL, '2025-01-30 22:58:32', '2025-01-30 22:58:32', NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(24, 'ORD-20250131-DFCF', 'pending', NULL, 109.97, 2, NULL, NULL, NULL, NULL, '2025-01-31 21:20:37', '2025-01-31 21:20:37', NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(25, 'ORD-20250131-C28A', 'assigned', NULL, 109.97, 2, NULL, NULL, NULL, NULL, '2025-01-31 21:21:22', '2025-01-31 21:27:01', NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(26, 'ORD-20250131-EE86', 'assigned', NULL, 109.97, 2, NULL, NULL, NULL, NULL, '2025-01-31 21:26:03', '2025-01-31 21:27:01', NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(27, 'ORD-20250301-3D24', 'assigned', '', 0.00, 2, NULL, NULL, NULL, NULL, '2025-03-01 15:34:55', '2025-03-02 10:36:25', '2025-03-26', NULL, NULL, NULL, NULL, NULL, NULL),
(30, 'ORD-20250302-6AC5', 'assigned', 'kakk', 0.00, 2, NULL, NULL, NULL, NULL, '2025-03-02 10:25:23', '2025-03-02 10:36:25', '2025-03-12', NULL, NULL, NULL, NULL, NULL, NULL),
(31, 'ORD-20250302-D970', 'pending', '', 0.00, 2, NULL, NULL, NULL, NULL, '2025-03-02 20:17:21', '2025-03-02 20:17:21', '2025-03-13', NULL, NULL, NULL, NULL, NULL, NULL),
(32, 'ORD-20250302-5863', 'pending', '', 0.00, 2, NULL, NULL, NULL, NULL, '2025-03-02 20:18:33', '2025-03-02 20:18:33', '2025-03-13', NULL, NULL, NULL, NULL, NULL, NULL),
(33, 'ORD-20250302-2DBE', 'pending', '', 0.00, 2, NULL, NULL, NULL, NULL, '2025-03-02 20:19:25', '2025-03-02 20:49:01', NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(34, 'ORD-20250302-A47D', 'pending', '', 0.00, 2, NULL, NULL, NULL, NULL, '2025-03-02 20:30:36', '2025-03-02 20:30:36', NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(35, 'ORD-20250302-2443', 'pending', '', 0.00, 2, NULL, NULL, NULL, NULL, '2025-03-02 20:32:40', '2025-03-02 20:32:40', NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(36, 'ORD-20250302-DFB3', 'pending', '', 0.00, 2, NULL, NULL, NULL, NULL, '2025-03-02 20:56:07', '2025-03-02 20:56:07', NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(37, 'ORD-20250302-8F17', 'pending', '', 0.00, 2, NULL, NULL, NULL, NULL, '2025-03-02 21:14:11', '2025-03-02 21:14:11', NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(38, 'ORD-20250302-EC35', 'pending', '', 0.00, 2, NULL, NULL, NULL, NULL, '2025-03-02 21:15:48', '2025-03-02 21:15:48', NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(39, 'ORD-20250302-1F1F', 'pending', '', 0.00, 2, NULL, NULL, NULL, NULL, '2025-03-02 21:16:30', '2025-03-02 21:16:30', NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(40, 'ORD-20250319-9E6E', 'pending', '', 0.00, 2, NULL, NULL, NULL, NULL, '2025-03-19 14:07:53', '2025-03-19 14:07:53', NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(41, 'ORD-20250427-BDE6', 'pending', '', 0.00, 2, NULL, NULL, 6, NULL, '2025-04-27 15:51:43', '2025-04-27 15:51:43', NULL, NULL, NULL, NULL, NULL, NULL, NULL),
(51, 'ORD-20250429-2993', 'pending', '0', 17.00, 2, 10, 10, NULL, NULL, '2025-04-29 13:22:36', '2025-04-29 13:22:36', NULL, 1, 0, NULL, NULL, NULL, NULL),
(52, 'ORD-20250429-57D8', 'pending', '0', 39.74, 2, 11, 11, NULL, NULL, '2025-04-29 13:22:36', '2025-04-29 13:22:36', NULL, 0, 0, NULL, NULL, NULL, NULL),
(53, 'ORD-20250429-4A3E', 'pending', '0', 43.50, 2, 12, 12, NULL, NULL, '2025-04-29 13:22:36', '2025-04-29 13:22:36', NULL, 1, 1, NULL, NULL, NULL, NULL),
(54, 'ORD-20250429-6C56', 'pending', '0', 17.00, 2, 10, 10, NULL, NULL, '2025-04-29 13:25:08', '2025-04-29 13:25:08', NULL, 1, 0, NULL, NULL, NULL, NULL),
(55, 'ORD-20250429-15C8', 'pending', '0', 39.74, 2, 11, 11, NULL, NULL, '2025-04-29 13:25:09', '2025-04-29 13:25:09', NULL, 0, 0, NULL, NULL, NULL, NULL),
(56, 'ORD-20250429-D153', 'pending', '0', 43.50, 2, 12, 12, NULL, NULL, '2025-04-29 13:25:09', '2025-04-29 13:25:09', NULL, 1, 1, NULL, NULL, NULL, NULL),
(57, 'ORD-20250429-33F7', 'pending', '0', 17.00, 2, 10, 10, NULL, NULL, '2025-04-29 14:37:29', '2025-04-29 14:37:29', NULL, 1, 0, NULL, NULL, NULL, NULL),
(58, 'ORD-20250429-B73B', 'pending', '0', 39.74, 2, 11, 11, NULL, NULL, '2025-04-29 14:37:30', '2025-04-29 14:37:30', NULL, 0, 0, NULL, NULL, NULL, NULL),
(59, 'ORD-20250429-AC5A', 'assigned', '0', 43.50, 2, 12, 12, NULL, NULL, '2025-04-29 14:37:30', '2025-04-30 10:03:17', NULL, 1, 1, NULL, NULL, NULL, NULL),
(60, 'ORD-20250430-5B11', 'pending', '', 0.00, 2, 11, 13, NULL, NULL, '2025-04-30 12:06:36', '2025-04-30 12:06:36', NULL, 1, 1, NULL, NULL, NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `OrderStatusLogs`
--

CREATE TABLE `OrderStatusLogs` (
  `id` int(11) NOT NULL,
  `order_id` int(11) NOT NULL,
  `status` enum('pending','assigned','delivering','delivered','failed') NOT NULL,
  `changed_by` int(11) NOT NULL,
  `reason` text DEFAULT NULL,
  `photo_url` text DEFAULT NULL,
  `signature_path` text DEFAULT NULL,
  `delivered_to` varchar(255) DEFAULT NULL,
  `changed_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `latitude` decimal(10,8) DEFAULT NULL,
  `longitude` decimal(11,8) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `OrderStatusLogs`
--

INSERT INTO `OrderStatusLogs` (`id`, `order_id`, `status`, `changed_by`, `reason`, `photo_url`, `signature_path`, `delivered_to`, `changed_at`, `latitude`, `longitude`) VALUES
(171, 16, 'pending', 25, NULL, NULL, NULL, NULL, '2025-01-09 10:55:25', NULL, NULL),
(172, 16, 'assigned', 25, NULL, NULL, NULL, NULL, '2025-01-09 10:55:37', NULL, NULL),
(173, 16, 'pending', 25, 'Manifest deleted', NULL, NULL, NULL, '2025-01-12 17:09:14', NULL, NULL),
(174, 16, 'assigned', 25, NULL, NULL, NULL, NULL, '2025-01-12 17:10:16', NULL, NULL),
(175, 17, 'pending', 25, NULL, NULL, NULL, NULL, '2025-01-16 14:28:39', NULL, NULL),
(176, 17, 'delivering', 25, NULL, NULL, NULL, NULL, '2025-01-16 14:36:53', NULL, NULL),
(177, 17, 'delivering', 25, NULL, NULL, NULL, NULL, '2025-01-16 14:46:05', NULL, NULL),
(178, 17, 'delivered', 33, NULL, NULL, NULL, 'Home Owner', '2025-01-16 14:46:09', NULL, NULL),
(179, 17, 'assigned', 25, NULL, NULL, NULL, NULL, '2025-01-16 14:46:19', NULL, NULL),
(180, 17, 'delivering', 25, NULL, NULL, NULL, NULL, '2025-01-16 14:46:26', NULL, NULL),
(181, 17, 'delivering', 25, NULL, NULL, NULL, NULL, '2025-01-16 14:46:27', NULL, NULL),
(182, 17, 'delivering', 33, NULL, NULL, NULL, '', '2025-01-16 14:46:41', NULL, NULL),
(183, 17, 'assigned', 25, NULL, NULL, NULL, NULL, '2025-01-16 14:49:22', NULL, NULL),
(184, 17, 'delivering', 25, NULL, NULL, NULL, NULL, '2025-01-16 14:49:30', NULL, NULL),
(186, 18, 'pending', 25, NULL, NULL, NULL, NULL, '2025-01-24 22:25:12', NULL, NULL),
(187, 18, 'assigned', 25, NULL, NULL, NULL, NULL, '2025-01-24 22:28:18', NULL, NULL),
(188, 18, 'assigned', 25, NULL, NULL, NULL, NULL, '2025-01-24 22:36:07', NULL, NULL),
(189, 19, 'pending', 25, NULL, NULL, NULL, NULL, '2025-01-28 06:48:37', NULL, NULL),
(190, 20, 'pending', 25, NULL, NULL, NULL, NULL, '2025-01-28 06:49:07', NULL, NULL),
(191, 21, 'pending', 25, NULL, NULL, NULL, NULL, '2025-01-28 06:49:42', NULL, NULL),
(192, 18, 'pending', 25, NULL, NULL, NULL, NULL, '2025-01-29 09:07:41', NULL, NULL),
(193, 21, 'assigned', 25, NULL, NULL, NULL, NULL, '2025-01-29 09:07:56', NULL, NULL),
(194, 20, 'assigned', 25, NULL, NULL, NULL, NULL, '2025-01-29 09:07:56', NULL, NULL),
(195, 19, 'assigned', 25, NULL, NULL, NULL, NULL, '2025-01-29 09:07:56', NULL, NULL),
(196, 21, 'delivering', 25, NULL, NULL, NULL, NULL, '2025-01-29 09:20:05', NULL, NULL),
(197, 20, 'delivering', 25, NULL, NULL, NULL, NULL, '2025-01-29 09:20:05', NULL, NULL),
(198, 19, 'delivering', 25, NULL, NULL, NULL, NULL, '2025-01-29 09:20:05', NULL, NULL),
(199, 21, 'delivered', 33, NULL, NULL, NULL, 'John Doe', '2025-01-29 09:30:30', NULL, NULL),
(200, 20, 'failed', 33, 'Ghar gaib ho gya ankho k samny sy', NULL, NULL, NULL, '2025-01-29 09:46:46', NULL, NULL),
(201, 19, 'delivered', 33, NULL, NULL, NULL, 'pta ni kon tha pkra dia', '2025-01-29 09:47:42', NULL, NULL),
(202, 22, 'pending', 25, NULL, NULL, NULL, NULL, '2025-01-30 19:20:51', NULL, NULL),
(203, 23, 'pending', 25, NULL, NULL, NULL, NULL, '2025-01-30 22:58:32', NULL, NULL),
(204, 27, 'pending', 25, NULL, NULL, NULL, NULL, '2025-03-01 15:34:55', NULL, NULL),
(207, 30, 'pending', 25, NULL, NULL, NULL, NULL, '2025-03-02 10:25:23', NULL, NULL),
(208, 31, 'pending', 25, NULL, NULL, NULL, NULL, '2025-03-02 20:17:21', NULL, NULL),
(209, 32, 'pending', 25, NULL, NULL, NULL, NULL, '2025-03-02 20:18:33', NULL, NULL),
(210, 33, 'pending', 25, NULL, NULL, NULL, NULL, '2025-03-02 20:19:25', NULL, NULL),
(211, 34, 'pending', 25, NULL, NULL, NULL, NULL, '2025-03-02 20:30:36', NULL, NULL),
(212, 35, 'pending', 25, NULL, NULL, NULL, NULL, '2025-03-02 20:32:40', NULL, NULL),
(213, 36, 'pending', 25, NULL, NULL, NULL, NULL, '2025-03-02 20:56:07', NULL, NULL),
(214, 37, 'pending', 25, NULL, NULL, NULL, NULL, '2025-03-02 21:14:11', NULL, NULL),
(215, 38, 'pending', 25, NULL, NULL, NULL, NULL, '2025-03-02 21:15:48', NULL, NULL),
(216, 39, 'pending', 25, NULL, NULL, NULL, NULL, '2025-03-02 21:16:30', NULL, NULL),
(217, 40, 'pending', 25, NULL, NULL, NULL, NULL, '2025-03-19 14:07:53', NULL, NULL),
(218, 41, 'pending', 1, NULL, NULL, NULL, NULL, '2025-04-27 15:51:43', NULL, NULL),
(219, 51, 'pending', 25, NULL, NULL, NULL, NULL, '2025-04-29 13:22:36', NULL, NULL),
(220, 52, 'pending', 25, NULL, NULL, NULL, NULL, '2025-04-29 13:22:36', NULL, NULL),
(221, 53, 'pending', 25, NULL, NULL, NULL, NULL, '2025-04-29 13:22:36', NULL, NULL),
(222, 54, 'pending', 25, NULL, NULL, NULL, NULL, '2025-04-29 13:25:08', NULL, NULL),
(223, 55, 'pending', 25, NULL, NULL, NULL, NULL, '2025-04-29 13:25:09', NULL, NULL),
(224, 56, 'pending', 25, NULL, NULL, NULL, NULL, '2025-04-29 13:25:09', NULL, NULL),
(225, 57, 'pending', 25, NULL, NULL, NULL, NULL, '2025-04-29 14:37:29', NULL, NULL),
(226, 58, 'pending', 25, NULL, NULL, NULL, NULL, '2025-04-29 14:37:30', NULL, NULL),
(227, 59, 'pending', 25, NULL, NULL, NULL, NULL, '2025-04-29 14:37:30', NULL, NULL),
(228, 60, 'pending', 25, NULL, NULL, NULL, NULL, '2025-04-30 12:06:36', NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `Organizations`
--

CREATE TABLE `Organizations` (
  `id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `company_id` int(11) NOT NULL,
  `address` text DEFAULT NULL,
  `address_line1` varchar(255) DEFAULT NULL,
  `address_line2` varchar(255) DEFAULT NULL,
  `city` varchar(100) DEFAULT NULL,
  `state` varchar(100) DEFAULT NULL,
  `postal_code` varchar(20) DEFAULT NULL,
  `country` varchar(100) DEFAULT 'United Kingdom',
  `phone` varchar(20) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `logo_url` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `Organizations`
--

INSERT INTO `Organizations` (`id`, `name`, `company_id`, `address`, `address_line1`, `address_line2`, `city`, `state`, `postal_code`, `country`, `phone`, `email`, `logo_url`, `is_active`, `created_at`, `updated_at`) VALUES
(1, 'solo Main Office', 2, 'sdvs', NULL, NULL, NULL, NULL, NULL, 'United Kingdom', '2342', 'sdfv@gmail.com', NULL, 1, '2025-04-26 19:59:43', '2025-04-26 19:59:43'),
(2, 'zolo Main Office', 4, 'isb', NULL, NULL, NULL, NULL, NULL, 'United Kingdom', '2903298', 'zolo@gm.c', NULL, 1, '2025-04-26 19:59:43', '2025-04-26 19:59:43'),
(3, 'Bizz VPN Main Office', 5, 'islamabad', NULL, NULL, NULL, NULL, NULL, 'United Kingdom', '+923338417624', 'mnaveed@gmail.com', NULL, 1, '2025-04-26 19:59:43', '2025-04-26 19:59:43'),
(4, 'Intro', 5, 'Alqaim Town, Pindorian, Khanna Kak', NULL, NULL, NULL, NULL, NULL, 'United Kingdom', '7911123456', '0', '0', 1, '2025-04-26 20:02:36', '2025-04-26 20:02:56'),
(5, 'Agen', 2, 'Alqaim Town, Pindorian, Khanna Kak', NULL, NULL, 'Islamabad', NULL, 'UB6 7DH', 'United Kingdom', '', 'mna25867@gmail.com', NULL, 1, '2025-04-27 09:26:30', '2025-04-27 09:26:30'),
(6, 'CloseBot', 2, 'Alqaim Town, Pindorian, Khanna Kak', NULL, NULL, 'Islamabad', NULL, 'UB6 7DH', 'United Kingdom', '7911123456', 'mna25867@gmail.com', NULL, 1, '2025-04-27 09:45:17', '2025-04-27 09:45:17'),
(7, 'Intro', 4, 'Alqaim Town, Pindorian, Khanna Kak', NULL, NULL, 'Islamabad', NULL, 'UB6 7DH', 'United Kingdom', '', 'mna25867@gmail.com', NULL, 1, '2025-04-27 09:46:46', '2025-04-27 09:46:46'),
(8, 'Age', 5, 'Alqaim Town, Pindorian, Khanna Kak', NULL, NULL, 'Islamabad', NULL, 'UB6 7DH', 'United Kingdom', '', 'mna25867@gmail.com', NULL, 1, '2025-04-27 09:51:27', '2025-04-27 09:51:27'),
(9, 'Age 2', 5, NULL, 'Alqaim Town, Pindorian, Khanna Kak', '', 'Greenford', 'London', 'UB6 7DH', 'United Kingdom', '+447911123456', '', NULL, 1, '2025-04-27 10:28:35', '2025-04-27 10:28:35'),
(10, 'Age3', 5, NULL, 'Alqaim Town, Pindorian, Khanna Kak', '', 'Greenford', 'London', 'UB6 7DH', 'United Kingdom', '+447911123456', 'mna25867@gmail.com', NULL, 1, '2025-04-27 10:29:16', '2025-04-27 10:29:16'),
(11, 'Intro2', 2, NULL, 'Alqaim Town, Pindorian, Khanna Kak', '', 'Greenford', 'London', 'UB6 7DH', 'United Kingdom', '+447911123456', '', NULL, 1, '2025-04-27 10:31:18', '2025-04-27 15:39:26'),
(12, 'Intro22', 5, NULL, 'Alqaim Town, Pindorian, Khanna Kak', '', 'Greenford', 'London', 'UB6 7DH', 'United Kingdom', '+447911123456', 'mna25867@gmail.com', NULL, 1, '2025-04-27 10:52:54', '2025-04-27 10:52:54');

-- --------------------------------------------------------

--
-- Table structure for table `PasswordResetTokens`
--

CREATE TABLE `PasswordResetTokens` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `token` varchar(255) NOT NULL,
  `expires_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `is_used` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `PasswordResetTokens`
--

INSERT INTO `PasswordResetTokens` (`id`, `user_id`, `token`, `expires_at`, `is_used`, `created_at`) VALUES
(1, 33, '$2y$10$mtSsHN3d8mjufImkTiEG9OgLyKfVJe3PXQOBpWPZrRRqg3tBck2lm', '2025-01-23 19:34:32', 0, '2025-01-23 23:19:32'),
(2, 1, '$2y$10$ry5bx8v6RZnLCGHrOuG1uuyLeAUa0SaqPMYkiUgkJ7Q8q.wWKjiZK', '2025-01-23 23:40:51', 1, '2025-01-23 23:36:27'),
(3, 1, '$2y$10$CMMNDcQJvFIG3OKyqk06Ouxc1d5cD2.Xa3MjpQ6yy7FDyXCAKn4Tq', '2025-01-23 19:55:51', 0, '2025-01-23 23:40:51');

-- --------------------------------------------------------

--
-- Table structure for table `ProductOrders`
--

CREATE TABLE `ProductOrders` (
  `id` int(11) NOT NULL,
  `order_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `quantity` int(11) NOT NULL DEFAULT 1,
  `picked_quantity` int(11) DEFAULT NULL,
  `missing_quantity` int(11) DEFAULT NULL,
  `price` decimal(10,2) NOT NULL,
  `delivered_quantity` int(11) DEFAULT 0,
  `delivery_missing_quantity` int(11) DEFAULT 0,
  `rejected_quantity` int(11) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `ProductOrders`
--

INSERT INTO `ProductOrders` (`id`, `order_id`, `product_id`, `quantity`, `picked_quantity`, `missing_quantity`, `price`, `delivered_quantity`, `delivery_missing_quantity`, `rejected_quantity`, `created_at`) VALUES
(43, 16, 25, 2, NULL, NULL, 2.00, 0, 0, 0, '2025-01-09 10:55:25'),
(44, 16, 26, 1, NULL, NULL, 23.00, 0, 0, 0, '2025-01-09 10:55:25'),
(45, 17, 26, 3, NULL, NULL, 323.00, 0, 0, 0, '2025-01-16 14:28:39'),
(47, 18, 27, 3, NULL, NULL, 3.00, 0, 0, 0, '2025-01-24 22:25:12'),
(51, 19, 25, 1, NULL, NULL, 2.00, 0, 0, 0, '2025-01-28 07:52:38'),
(52, 20, 27, 1, NULL, NULL, 3.00, 0, 0, 0, '2025-01-28 07:53:44'),
(53, 21, 27, 1, NULL, NULL, 3.00, 0, 0, 0, '2025-01-28 07:54:28'),
(56, 22, 25, 1, NULL, NULL, 2.00, 0, 0, 0, '2025-01-30 20:27:42'),
(57, 22, 26, 3, NULL, NULL, 4.00, 0, 0, 0, '2025-01-30 20:27:42'),
(58, 23, 25, 1, NULL, NULL, 6.00, 0, 0, 0, '2025-01-30 22:58:32'),
(59, 24, 28, 2, NULL, NULL, 29.99, 0, 0, 0, '2025-01-31 21:20:37'),
(60, 24, 29, 1, NULL, NULL, 49.99, 0, 0, 0, '2025-01-31 21:20:37'),
(61, 25, 28, 2, NULL, NULL, 29.99, 0, 0, 0, '2025-01-31 21:21:22'),
(62, 25, 29, 1, NULL, NULL, 49.99, 0, 0, 0, '2025-01-31 21:21:22'),
(63, 26, 28, 2, NULL, NULL, 29.99, 0, 0, 0, '2025-01-31 21:26:03'),
(64, 26, 29, 1, NULL, NULL, 49.99, 0, 0, 0, '2025-01-31 21:26:03'),
(76, 30, 26, 1, NULL, NULL, 0.00, 0, 0, 0, '2025-03-02 10:25:23'),
(77, 30, 25, 1, NULL, NULL, 0.00, 0, 0, 0, '2025-03-02 10:25:23'),
(78, 27, 30, 1, NULL, NULL, 0.00, 0, 0, 0, '2025-03-02 10:26:14'),
(79, 27, 30, 1, NULL, NULL, 0.00, 0, 0, 0, '2025-03-02 10:26:14'),
(80, 27, 26, 1, NULL, NULL, 0.00, 0, 0, 0, '2025-03-02 10:26:14'),
(81, 31, 25, 1, NULL, NULL, 0.00, 0, 0, 0, '2025-03-02 20:17:21'),
(82, 32, 27, 1, NULL, NULL, 0.00, 0, 0, 0, '2025-03-02 20:18:33'),
(84, 34, 26, 1, NULL, NULL, 0.00, 0, 0, 0, '2025-03-02 20:30:36'),
(85, 35, 25, 2, NULL, NULL, 0.00, 0, 0, 0, '2025-03-02 20:32:40'),
(86, 33, 26, 1, NULL, NULL, 0.00, 0, 0, 0, '2025-03-02 20:49:01'),
(87, 36, 28, 2, NULL, NULL, 0.00, 0, 0, 0, '2025-03-02 20:56:07'),
(88, 37, 30, 1, NULL, NULL, 0.00, 0, 0, 0, '2025-03-02 21:14:11'),
(89, 38, 25, 4, NULL, NULL, 0.00, 0, 0, 0, '2025-03-02 21:15:48'),
(90, 39, 30, 1, NULL, NULL, 0.00, 0, 0, 0, '2025-03-02 21:16:30'),
(91, 40, 25, 1, NULL, NULL, 0.00, 0, 0, 0, '2025-03-19 14:07:53'),
(92, 41, 30, 1, NULL, NULL, 0.00, 0, 0, 0, '2025-04-27 15:51:43'),
(93, 51, 31, 2, NULL, NULL, 4.50, 0, 0, 0, '2025-04-29 13:22:36'),
(94, 51, 32, 1, NULL, NULL, 8.00, 0, 0, 0, '2025-04-29 13:22:36'),
(95, 52, 33, 1, NULL, NULL, 15.99, 0, 0, 0, '2025-04-29 13:22:36'),
(96, 52, 34, 3, NULL, NULL, 5.50, 0, 0, 0, '2025-04-29 13:22:36'),
(97, 52, 35, 1, NULL, NULL, 7.25, 0, 0, 0, '2025-04-29 13:22:36'),
(98, 53, 36, 1, NULL, NULL, 25.00, 0, 0, 0, '2025-04-29 13:22:36'),
(99, 53, 37, 1, NULL, NULL, 18.50, 0, 0, 0, '2025-04-29 13:22:36'),
(100, 54, 31, 2, NULL, NULL, 4.50, 0, 0, 0, '2025-04-29 13:25:08'),
(101, 54, 32, 1, NULL, NULL, 8.00, 0, 0, 0, '2025-04-29 13:25:08'),
(102, 55, 33, 1, NULL, NULL, 15.99, 0, 0, 0, '2025-04-29 13:25:09'),
(103, 55, 34, 3, NULL, NULL, 5.50, 0, 0, 0, '2025-04-29 13:25:09'),
(104, 55, 35, 1, NULL, NULL, 7.25, 0, 0, 0, '2025-04-29 13:25:09'),
(105, 56, 36, 1, NULL, NULL, 25.00, 0, 0, 0, '2025-04-29 13:25:09'),
(106, 56, 37, 1, NULL, NULL, 18.50, 0, 0, 0, '2025-04-29 13:25:09'),
(107, 57, 31, 2, NULL, NULL, 4.50, 0, 0, 0, '2025-04-29 14:37:29'),
(108, 57, 32, 1, NULL, NULL, 8.00, 0, 0, 0, '2025-04-29 14:37:29'),
(109, 58, 33, 1, NULL, NULL, 15.99, 0, 0, 0, '2025-04-29 14:37:30'),
(110, 58, 34, 3, NULL, NULL, 5.50, 0, 0, 0, '2025-04-29 14:37:30'),
(111, 58, 35, 1, NULL, NULL, 7.25, 0, 0, 0, '2025-04-29 14:37:30'),
(112, 59, 36, 1, NULL, NULL, 25.00, 0, 0, 0, '2025-04-29 14:37:30'),
(113, 59, 37, 1, NULL, NULL, 18.50, 0, 0, 0, '2025-04-29 14:37:30'),
(126, 60, 36, 1, NULL, NULL, 0.00, 0, 0, 0, '2025-04-30 12:17:32'),
(127, 60, 31, 1, NULL, NULL, 0.00, 0, 0, 0, '2025-04-30 12:17:32');

-- --------------------------------------------------------

--
-- Table structure for table `Products`
--

CREATE TABLE `Products` (
  `id` int(11) NOT NULL,
  `external_id` varchar(255) DEFAULT NULL,
  `name` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `qrcode_number` varchar(100) DEFAULT NULL,
  `company_id` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `Products`
--

INSERT INTO `Products` (`id`, `external_id`, `name`, `description`, `qrcode_number`, `company_id`, `created_at`, `updated_at`) VALUES
(25, NULL, 'p1', 'p1 123', '123', 2, '2025-01-09 10:54:26', '2025-01-09 10:54:26'),
(26, NULL, 'p2', 'p2', '1234', 2, '2025-01-09 10:54:36', '2025-01-09 10:54:36'),
(27, NULL, 'p3', 'p3', '12345', 2, '2025-01-09 10:54:46', '2025-01-09 10:54:46'),
(28, 'WP-123', 'Test Product 1', 'This is a test product', 'PRD-EA50EEA0', 2, '2025-01-31 21:20:37', '2025-01-31 21:20:37'),
(29, 'WP-124', 'Test Product 2', 'Another test product', 'PRD-EA50F1D6', 2, '2025-01-31 21:20:37', '2025-01-31 21:20:37'),
(30, NULL, 'kuch b', 'kuch b', '92832', 2, '2025-03-01 10:55:02', '2025-03-01 10:55:02'),
(31, NULL, 'Carrot Cake Slice', 'Carrot Cake Slice', 'QR-BUNNY001', 2, '2025-04-29 13:22:36', '2025-04-29 13:22:36'),
(32, NULL, 'Mad Hatter Tea Bags (Box)', 'Mad Hatter Tea Bags (Box)', 'QR-TEA005', 2, '2025-04-29 13:22:36', '2025-04-29 13:22:36'),
(33, NULL, 'Hammer', 'Hammer', 'QR-TOOL010', 2, '2025-04-29 13:22:36', '2025-04-29 13:22:36'),
(34, NULL, 'Nails (1kg Box)', 'Nails (1kg Box)', 'QR-NAIL002', 2, '2025-04-29 13:22:36', '2025-04-29 13:22:36'),
(35, NULL, 'Measuring Tape', 'Measuring Tape', 'QR-TAPE001', 2, '2025-04-29 13:22:36', '2025-04-29 13:22:36'),
(36, NULL, 'Bowler Hat', 'Bowler Hat', 'QR-HAT001', 2, '2025-04-29 13:22:36', '2025-04-29 13:22:36'),
(37, NULL, 'Walking Cane', 'Walking Cane', 'QR-CANE001', 2, '2025-04-29 13:22:36', '2025-04-29 13:22:36');

-- --------------------------------------------------------

--
-- Table structure for table `ProductTracking`
--

CREATE TABLE `ProductTracking` (
  `id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `rider_id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `picked_quantity` int(11) DEFAULT 0,
  `missing_quantity` int(11) NOT NULL,
  `delivered_quantity` int(11) DEFAULT 0,
  `picked_at` timestamp NULL DEFAULT NULL,
  `delivered_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `RiderCompanies`
--

CREATE TABLE `RiderCompanies` (
  `id` int(11) NOT NULL,
  `rider_id` int(11) NOT NULL,
  `company_id` int(11) NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `RiderCompanies`
--

INSERT INTO `RiderCompanies` (`id`, `rider_id`, `company_id`, `is_active`) VALUES
(28, 33, 2, 1),
(29, 43, 2, 1);

-- --------------------------------------------------------

--
-- Table structure for table `RidersLocations`
--

CREATE TABLE `RidersLocations` (
  `id` int(11) NOT NULL,
  `rider_id` int(11) NOT NULL,
  `lat` decimal(9,6) NOT NULL,
  `lng` decimal(9,6) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `TokenBlacklist`
--

CREATE TABLE `TokenBlacklist` (
  `id` int(11) NOT NULL,
  `token` text NOT NULL,
  `user_id` int(11) NOT NULL,
  `expires_at` datetime NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `Users`
--

CREATE TABLE `Users` (
  `id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `username` varchar(50) NOT NULL,
  `email` varchar(255) NOT NULL,
  `phone` varchar(20) DEFAULT NULL,
  `password` varchar(255) NOT NULL,
  `user_type` enum('Super Admin','Admin','Rider') NOT NULL,
  `company_id` int(11) DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `admin_pin` varchar(255) DEFAULT NULL,
  `fcm_token` varchar(255) DEFAULT NULL,
  `fcm_token_updated_at` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `Users`
--

INSERT INTO `Users` (`id`, `name`, `username`, `email`, `phone`, `password`, `user_type`, `company_id`, `is_active`, `created_at`, `updated_at`, `admin_pin`, `fcm_token`, `fcm_token_updated_at`) VALUES
(1, 'Muhammad Naveed', 'mnaveedpaki', 'mnaveedpaki@gmail.com', '+923405138556', '$2y$10$WR.PROeS7zlbu/pOE564Zu/uGR7.HsvhSMigpm06OlH5TQidCrXTq', 'Super Admin', NULL, 1, '2024-12-19 19:43:41', '2024-12-21 23:29:15', NULL, NULL, NULL),
(3, 'Adeel Nawab', 'adeel', 'adeel@gmail.com', '0329032984', '$2y$10$ANA.7WgviFpqOnvcSsSLJeGnEWC95Df/L/dPCnnSQHhFhao.lo4nK', 'Admin', 2, 1, '2024-12-19 20:15:00', '2025-01-13 10:48:33', NULL, NULL, NULL),
(25, 'Muhammad Naveed', 'mn', 'mnaveedpaki1@gmail.com', '123123123', '$2y$10$cMn01YQeggZ1oRtWUuXDS.d4WI9nSdb7hNiv2FQBKWPjrrJKlkjcm', 'Admin', 2, 1, '2024-12-21 23:48:45', '2025-01-13 09:44:37', '$2y$10$zFENsTN/s5xXf348B.VGV.1NpGIYCg2zwLQ5XtpnIlJQA1DzIAzja', NULL, NULL),
(26, 'ali', 'ali', 'ali@gm.c', '29839878', '$2y$10$.PjeVKrzYC.ankSgW6C4wePKkssTD4tPyKQ6E0nu3q2fpwEExE1q2', 'Admin', 4, 1, '2024-12-21 23:57:06', '2024-12-22 07:38:38', NULL, NULL, NULL),
(29, 'Mohsin', 'mohsin', 'mohsin@gmail.com', '+923489876890', '$2y$10$4/LCKdUioOPqDEqGc.O5q.HAOvAWjUytE6Vgaze0PVXofy2x6qOgy', 'Admin', 5, 1, '2024-12-25 19:20:16', '2024-12-25 19:21:21', NULL, NULL, NULL),
(32, 'm n a v e e d', 'mnaveed', 'mnaveed@gmail.com', '+923338417624', '$2y$10$waUKu5S7e9IlFFzLnA4nHeJDQSFuaTSk1R9JxSwdiB000EAZEXrkW', 'Super Admin', NULL, 1, '2025-01-03 12:30:50', '2025-01-03 12:30:50', NULL, NULL, NULL),
(33, 'r1', 'r1', 'mna25867@gmail.com', '+922983928', '$2y$10$EWYQODs5S09qR8trRnlAVOt0MgEF.iozly3gWWy/UQ8odQxmynM0K', 'Rider', NULL, 1, '2025-01-09 10:54:01', '2025-01-25 20:20:07', NULL, 'j2n9329ciu3j2cju32983cj2', '2025-01-25 20:20:07'),
(34, 'sdf', 'dkfjv', 'jkdf@g.c', '+9239043940', '$2y$10$1O8Fh7iYnRY9TE8vEZbPe.sqhFf4D.lEIAHF2ZnAEtAnGo975nJw2', 'Admin', 2, 1, '2025-01-13 10:48:18', '2025-01-13 10:48:30', NULL, NULL, NULL),
(38, 'usr22', 'usr22', 'usr22@gmail.com', '', '$2y$10$X.kMP60jXyhJJCn7FUhJT.huisrUlAF0hlKc3mmjveGYzUusviQBW', 'Admin', 2, 1, '2025-03-02 11:03:25', '2025-03-02 11:03:39', '$2y$10$ZpdvPzFeA1r.cEePhpTMr.bC87jc81tCpkhAUazl3.XIGjiclD1xW', NULL, NULL),
(39, 'usr23', 'usr23', 'usr23@gmail.com', '+442423456789', '$2y$10$7D/a25ZgN83ywDTosk07keHEWsAAUFUtytj/dU4HIpaoB2BU.S/La', 'Admin', 2, 1, '2025-03-02 11:21:49', '2025-03-02 11:22:00', '$2y$10$6AWNVDklx7U3yeKh.B71FefTpgi5IhCOJT7i.ffKQ6ndllaOVCjD.', NULL, NULL),
(40, 'usr24', 'usr24', 'usr24@gmail.com', '+441111111111', '$2y$10$qvFqdyacYG8DMNK/pi2s6e00BeK.s0qimPxFMJtKgQvZwW9jv4Nyu', 'Admin', 2, 1, '2025-03-02 11:23:10', '2025-03-02 12:13:28', '$2y$10$hWIsezIsG.nKPoq19IJM0ebbE.biS.ghvigDBIpJjGG.W81M6pH4y', NULL, NULL),
(41, 'user25', 'user25', 'user25@gmail.com', '+441234123456', '$2y$10$Sxhfa.AiKt49uJyzAgbGA.dRk1ymQrgln1gK80CJVv1yhbL4a18PO', 'Admin', 2, 1, '2025-03-02 12:17:35', '2025-03-02 12:27:37', '$2y$10$F8z0IAu3WSWHemIW2syb7.EkoCveXSGYMWqE6sRyBJ2srqhmyWDb6', NULL, NULL),
(42, 'm n a v e e d m n a v e e d', 'mnbvc', 'mnbvc@gmail.com', '+441231231234', '$2y$10$wh6nep8uTwfLXA8YSfmEQeRgmw89Za9i3EZzde0fGx1B0JFjzTevG', 'Admin', 2, 1, '2025-03-02 12:29:24', '2025-03-02 12:29:55', '$2y$10$uQMlJa7voEESCIQenqlkg.4weqbC22eZs1hkn0tkwkpmvwXHsyfEe', NULL, NULL),
(43, 'rider33', 'rider33', 'rider33@gmail.com', '+441234512345', '$2y$10$scRhNZjxa0T85ot36SHZZuZynUXBGDjQn5RtRng5u5CDH0Y5ge6rC', 'Rider', NULL, 1, '2025-03-02 12:53:58', '2025-03-02 12:53:58', NULL, NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `UserTokens`
--

CREATE TABLE `UserTokens` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `token` text NOT NULL,
  `device_info` text DEFAULT NULL,
  `is_active` tinyint(1) DEFAULT 1,
  `expires_at` datetime NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `UserTokens`
--

INSERT INTO `UserTokens` (`id`, `user_id`, `token`, `device_info`, `is_active`, `expires_at`, `created_at`, `updated_at`) VALUES
(12, 33, 'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJ1c2VyX2lkIjozMywiaWF0IjoxNzM2NDMzODM3LCJleHAiOjE3MzY1MjAyMzd9.Or2m4H/nWxeCqYDYc6WLHvmr7n0iklHoq0wLcnRM3a0=', NULL, 0, '2025-01-10 15:43:57', '2025-01-09 14:43:57', '2025-01-09 14:47:45'),
(13, 33, 'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJ1c2VyX2lkIjozMywiaWF0IjoxNzM2NDM0MDY1LCJleHAiOjE3MzY1MjA0NjV9.kZVekI4bfrtcFStFgoMgTW4H0jvapfQ3WxQrtvToJhk=', NULL, 0, '2025-01-10 15:47:45', '2025-01-09 14:47:45', '2025-01-16 14:31:02'),
(14, 33, 'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJ1c2VyX2lkIjozMywiaWF0IjoxNzM3MDM3ODYyLCJleHAiOjE3MzcxMjQyNjJ9.AgjFW9PjqhKOJ4f0OkDICNKFOowqDB/z8sYowcSgfy0=', NULL, 0, '2025-01-17 15:31:02', '2025-01-16 14:31:02', '2025-01-18 19:18:44'),
(15, 33, 'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJ1c2VyX2lkIjozMywiaWF0IjoxNzM3MjI3OTI0LCJleHAiOjE3MzczMTQzMjR9.mrsk6ma2Uj16bSuzpnfLtJKotmlbK77YlTYcBM0Ancc=', NULL, 0, '2025-01-19 20:18:44', '2025-01-18 19:18:44', '2025-01-25 00:01:33'),
(16, 33, 'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJ1c2VyX2lkIjozMywiaWF0IjoxNzM3NzYzMjkzLCJleHAiOjE3Mzc4NDk2OTN9.bKp+923bhjXpqa0HyS6Dx8DZwlwiyHrKbVxqYKsLIpQ=', NULL, 0, '2025-01-26 01:01:33', '2025-01-25 00:01:33', '2025-01-25 00:27:00'),
(17, 33, 'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJ1c2VyX2lkIjozMywiaWF0IjoxNzM3NzY0ODIwLCJleHAiOjE3Mzc4NTEyMjB9.LEC/vIG2cNQM6ECEZB9BmMosjQzOhcAwZYdP8r+XLe8=', NULL, 0, '2025-01-26 01:27:00', '2025-01-25 00:27:00', '2025-01-25 11:09:58'),
(18, 33, 'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJ1c2VyX2lkIjozMywiaWF0IjoxNzM3ODAzMzk4LCJleHAiOjE3Mzc4ODk3OTh9.EZjI5J51pNOa8Z6LuxCjwvN9wNuSeXp0TTbRl8IdG3A=', NULL, 0, '2025-01-26 12:09:58', '2025-01-25 11:09:58', '2025-01-25 11:10:33'),
(19, 33, 'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJ1c2VyX2lkIjozMywiaWF0IjoxNzM3ODAzNDMzLCJleHAiOjE3Mzc4ODk4MzN9.NH+z8Eb81wQwakmb1ybKT2zj5ebpvk+ia1Bn0zBWslM=', NULL, 0, '2025-01-26 12:10:33', '2025-01-25 11:10:33', '2025-01-25 11:19:10'),
(20, 33, 'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJ1c2VyX2lkIjozMywiaWF0IjoxNzM3ODAzOTUwLCJleHAiOjE3Mzc4OTAzNTB9.jjiQP5Ix1f28xKrg5kUuKlEYtMtnTsjLzeGcECqRswc=', NULL, 0, '2025-01-26 12:19:10', '2025-01-25 11:19:10', '2025-01-25 20:11:55'),
(21, 33, 'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJ1c2VyX2lkIjozMywiaWF0IjoxNzM3ODM1OTE1LCJleHAiOjE3Mzc5MjIzMTV9.ebNtjGw/rbrBnDjmcVR+qK5oiTsKVJuVz1YczUYj5vE=', NULL, 0, '2025-01-26 21:11:55', '2025-01-25 20:11:55', '2025-01-25 20:13:25'),
(22, 33, 'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJ1c2VyX2lkIjozMywiaWF0IjoxNzM3ODM2MDA1LCJleHAiOjE3Mzc5MjI0MDV9.12kyB5Kxr+2InSNfH3EFoGOkgvoiddh2/MxB1WaNPUs=', NULL, 0, '2025-01-26 21:13:25', '2025-01-25 20:13:25', '2025-01-29 09:09:19'),
(23, 33, 'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJ1c2VyX2lkIjozMywiaWF0IjoxNzM4MTQxNzU5LCJleHAiOjE3MzgyMjgxNTl9.cB/Fb1yO3+6/37iOch6zvWHNQMNSvZblvJD/3oZ6Qlc=', NULL, 1, '2025-01-30 10:09:19', '2025-01-29 09:09:19', '2025-01-29 09:09:19');

-- --------------------------------------------------------

--
-- Table structure for table `Warehouses`
--

CREATE TABLE `Warehouses` (
  `id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `address` varchar(255) NOT NULL,
  `city` varchar(100) NOT NULL,
  `state` varchar(100) DEFAULT NULL,
  `postal_code` varchar(20) DEFAULT NULL,
  `country` varchar(100) DEFAULT NULL,
  `latitude` decimal(10,8) DEFAULT NULL,
  `longitude` decimal(11,8) DEFAULT NULL,
  `company_id` int(11) NOT NULL,
  `status` enum('active','inactive') DEFAULT 'active',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `Warehouses`
--

INSERT INTO `Warehouses` (`id`, `name`, `address`, `city`, `state`, `postal_code`, `country`, `latitude`, `longitude`, `company_id`, `status`, `created_at`, `updated_at`) VALUES
(3, 'W3', '74 London Road', 'London', 'London', 'W93 1HO', 'UK', 41.44698600, -72.23304600, 2, 'active', '2025-01-24 22:15:31', '2025-01-24 22:15:31');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `ActivityLogs`
--
ALTER TABLE `ActivityLogs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `Addresses`
--
ALTER TABLE `Addresses`
  ADD PRIMARY KEY (`id`),
  ADD KEY `customer_id` (`customer_id`);

--
-- Indexes for table `ApiKeys`
--
ALTER TABLE `ApiKeys`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_api_key` (`api_key`),
  ADD KEY `company_id` (`company_id`),
  ADD KEY `created_by` (`created_by`);

--
-- Indexes for table `Companies`
--
ALTER TABLE `Companies`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `Customers`
--
ALTER TABLE `Customers`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`),
  ADD UNIQUE KEY `phone` (`phone`),
  ADD KEY `company_id` (`company_id`);

--
-- Indexes for table `ExtraItemsLog`
--
ALTER TABLE `ExtraItemsLog`
  ADD PRIMARY KEY (`id`),
  ADD KEY `manifest_id` (`manifest_id`),
  ADD KEY `product_id` (`product_id`),
  ADD KEY `source_order_id` (`source_order_id`),
  ADD KEY `idx_rider_offloaded` (`rider_id`,`offloaded_at`),
  ADD KEY `fk_eil_manifest` (`source_manifest_id`);

--
-- Indexes for table `ManifestOrders`
--
ALTER TABLE `ManifestOrders`
  ADD PRIMARY KEY (`id`),
  ADD KEY `manifest_id` (`manifest_id`),
  ADD KEY `order_id` (`order_id`);

--
-- Indexes for table `Manifests`
--
ALTER TABLE `Manifests`
  ADD PRIMARY KEY (`id`),
  ADD KEY `rider_id` (`rider_id`),
  ADD KEY `company_id` (`company_id`),
  ADD KEY `warehouse_id` (`warehouse_id`);

--
-- Indexes for table `NotificationLogs`
--
ALTER TABLE `NotificationLogs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `Orders`
--
ALTER TABLE `Orders`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `order_number` (`order_number`),
  ADD KEY `company_id` (`company_id`),
  ADD KEY `delivery_date` (`delivery_date`),
  ADD KEY `idx_order_organization` (`organization_id`),
  ADD KEY `fk_order_customer` (`customer_id`),
  ADD KEY `fk_order_address` (`delivery_address_id`);

--
-- Indexes for table `OrderStatusLogs`
--
ALTER TABLE `OrderStatusLogs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `order_id` (`order_id`),
  ADD KEY `changed_by` (`changed_by`);

--
-- Indexes for table `Organizations`
--
ALTER TABLE `Organizations`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_org_company_id` (`company_id`),
  ADD KEY `idx_organization_company` (`company_id`);

--
-- Indexes for table `PasswordResetTokens`
--
ALTER TABLE `PasswordResetTokens`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `ProductOrders`
--
ALTER TABLE `ProductOrders`
  ADD PRIMARY KEY (`id`),
  ADD KEY `order_id` (`order_id`),
  ADD KEY `product_id` (`product_id`);

--
-- Indexes for table `Products`
--
ALTER TABLE `Products`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_qrcode_company` (`qrcode_number`,`company_id`),
  ADD UNIQUE KEY `unique_external_id_company` (`external_id`,`company_id`),
  ADD KEY `company_id` (`company_id`);

--
-- Indexes for table `ProductTracking`
--
ALTER TABLE `ProductTracking`
  ADD PRIMARY KEY (`id`),
  ADD KEY `product_id` (`product_id`),
  ADD KEY `rider_id` (`rider_id`),
  ADD KEY `company_id` (`company_id`);

--
-- Indexes for table `RiderCompanies`
--
ALTER TABLE `RiderCompanies`
  ADD PRIMARY KEY (`id`),
  ADD KEY `rider_id` (`rider_id`),
  ADD KEY `company_id` (`company_id`);

--
-- Indexes for table `RidersLocations`
--
ALTER TABLE `RidersLocations`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `TokenBlacklist`
--
ALTER TABLE `TokenBlacklist`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `Users`
--
ALTER TABLE `Users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`),
  ADD UNIQUE KEY `email` (`email`),
  ADD KEY `company_id` (`company_id`);

--
-- Indexes for table `UserTokens`
--
ALTER TABLE `UserTokens`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `Warehouses`
--
ALTER TABLE `Warehouses`
  ADD PRIMARY KEY (`id`),
  ADD KEY `company_id` (`company_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `ActivityLogs`
--
ALTER TABLE `ActivityLogs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `Addresses`
--
ALTER TABLE `Addresses`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=14;

--
-- AUTO_INCREMENT for table `ApiKeys`
--
ALTER TABLE `ApiKeys`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `Companies`
--
ALTER TABLE `Companies`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `Customers`
--
ALTER TABLE `Customers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT for table `ExtraItemsLog`
--
ALTER TABLE `ExtraItemsLog`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `ManifestOrders`
--
ALTER TABLE `ManifestOrders`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=43;

--
-- AUTO_INCREMENT for table `Manifests`
--
ALTER TABLE `Manifests`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=29;

--
-- AUTO_INCREMENT for table `NotificationLogs`
--
ALTER TABLE `NotificationLogs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `Orders`
--
ALTER TABLE `Orders`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=61;

--
-- AUTO_INCREMENT for table `OrderStatusLogs`
--
ALTER TABLE `OrderStatusLogs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=229;

--
-- AUTO_INCREMENT for table `Organizations`
--
ALTER TABLE `Organizations`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT for table `PasswordResetTokens`
--
ALTER TABLE `PasswordResetTokens`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `ProductOrders`
--
ALTER TABLE `ProductOrders`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=128;

--
-- AUTO_INCREMENT for table `Products`
--
ALTER TABLE `Products`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=38;

--
-- AUTO_INCREMENT for table `ProductTracking`
--
ALTER TABLE `ProductTracking`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `RiderCompanies`
--
ALTER TABLE `RiderCompanies`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=30;

--
-- AUTO_INCREMENT for table `RidersLocations`
--
ALTER TABLE `RidersLocations`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2539;

--
-- AUTO_INCREMENT for table `TokenBlacklist`
--
ALTER TABLE `TokenBlacklist`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `Users`
--
ALTER TABLE `Users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=44;

--
-- AUTO_INCREMENT for table `UserTokens`
--
ALTER TABLE `UserTokens`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=24;

--
-- AUTO_INCREMENT for table `Warehouses`
--
ALTER TABLE `Warehouses`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `ActivityLogs`
--
ALTER TABLE `ActivityLogs`
  ADD CONSTRAINT `activitylogs_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `Users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `Addresses`
--
ALTER TABLE `Addresses`
  ADD CONSTRAINT `addresses_ibfk_1` FOREIGN KEY (`customer_id`) REFERENCES `Customers` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `ApiKeys`
--
ALTER TABLE `ApiKeys`
  ADD CONSTRAINT `apikeys_ibfk_1` FOREIGN KEY (`company_id`) REFERENCES `Companies` (`id`),
  ADD CONSTRAINT `apikeys_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `Users` (`id`);

--
-- Constraints for table `Customers`
--
ALTER TABLE `Customers`
  ADD CONSTRAINT `customers_ibfk_1` FOREIGN KEY (`company_id`) REFERENCES `Companies` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `ExtraItemsLog`
--
ALTER TABLE `ExtraItemsLog`
  ADD CONSTRAINT `extraitemslog_ibfk_1` FOREIGN KEY (`manifest_id`) REFERENCES `Manifests` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `extraitemslog_ibfk_2` FOREIGN KEY (`rider_id`) REFERENCES `Users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `extraitemslog_ibfk_3` FOREIGN KEY (`product_id`) REFERENCES `Products` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `extraitemslog_ibfk_4` FOREIGN KEY (`source_order_id`) REFERENCES `Orders` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_eil_manifest` FOREIGN KEY (`source_manifest_id`) REFERENCES `Manifests` (`id`);

--
-- Constraints for table `ManifestOrders`
--
ALTER TABLE `ManifestOrders`
  ADD CONSTRAINT `manifestorders_ibfk_1` FOREIGN KEY (`manifest_id`) REFERENCES `Manifests` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `manifestorders_ibfk_2` FOREIGN KEY (`order_id`) REFERENCES `Orders` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `Manifests`
--
ALTER TABLE `Manifests`
  ADD CONSTRAINT `manifests_ibfk_1` FOREIGN KEY (`rider_id`) REFERENCES `Users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `manifests_ibfk_2` FOREIGN KEY (`company_id`) REFERENCES `Companies` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `manifests_ibfk_3` FOREIGN KEY (`warehouse_id`) REFERENCES `Warehouses` (`id`);

--
-- Constraints for table `NotificationLogs`
--
ALTER TABLE `NotificationLogs`
  ADD CONSTRAINT `notificationlogs_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `Users` (`id`);

--
-- Constraints for table `Orders`
--
ALTER TABLE `Orders`
  ADD CONSTRAINT `fk_order_address` FOREIGN KEY (`delivery_address_id`) REFERENCES `Addresses` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_order_customer` FOREIGN KEY (`customer_id`) REFERENCES `Customers` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_order_organization` FOREIGN KEY (`organization_id`) REFERENCES `Organizations` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `orders_ibfk_1` FOREIGN KEY (`company_id`) REFERENCES `Companies` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `OrderStatusLogs`
--
ALTER TABLE `OrderStatusLogs`
  ADD CONSTRAINT `orderstatuslogs_ibfk_1` FOREIGN KEY (`order_id`) REFERENCES `Orders` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `orderstatuslogs_ibfk_2` FOREIGN KEY (`changed_by`) REFERENCES `Users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `Organizations`
--
ALTER TABLE `Organizations`
  ADD CONSTRAINT `fk_org_company_id` FOREIGN KEY (`company_id`) REFERENCES `Companies` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `PasswordResetTokens`
--
ALTER TABLE `PasswordResetTokens`
  ADD CONSTRAINT `passwordresettokens_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `Users` (`id`);

--
-- Constraints for table `ProductOrders`
--
ALTER TABLE `ProductOrders`
  ADD CONSTRAINT `productorders_ibfk_1` FOREIGN KEY (`order_id`) REFERENCES `Orders` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `productorders_ibfk_2` FOREIGN KEY (`product_id`) REFERENCES `Products` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `Products`
--
ALTER TABLE `Products`
  ADD CONSTRAINT `products_ibfk_1` FOREIGN KEY (`company_id`) REFERENCES `Companies` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `ProductTracking`
--
ALTER TABLE `ProductTracking`
  ADD CONSTRAINT `producttracking_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `Products` (`id`),
  ADD CONSTRAINT `producttracking_ibfk_2` FOREIGN KEY (`rider_id`) REFERENCES `Users` (`id`),
  ADD CONSTRAINT `producttracking_ibfk_3` FOREIGN KEY (`company_id`) REFERENCES `Companies` (`id`);

--
-- Constraints for table `RiderCompanies`
--
ALTER TABLE `RiderCompanies`
  ADD CONSTRAINT `ridercompanies_ibfk_1` FOREIGN KEY (`rider_id`) REFERENCES `Users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `ridercompanies_ibfk_2` FOREIGN KEY (`company_id`) REFERENCES `Companies` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `TokenBlacklist`
--
ALTER TABLE `TokenBlacklist`
  ADD CONSTRAINT `tokenblacklist_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `Users` (`id`);

--
-- Constraints for table `Users`
--
ALTER TABLE `Users`
  ADD CONSTRAINT `users_ibfk_1` FOREIGN KEY (`company_id`) REFERENCES `Companies` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `UserTokens`
--
ALTER TABLE `UserTokens`
  ADD CONSTRAINT `usertokens_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `Users` (`id`);

--
-- Constraints for table `Warehouses`
--
ALTER TABLE `Warehouses`
  ADD CONSTRAINT `warehouses_ibfk_1` FOREIGN KEY (`company_id`) REFERENCES `Companies` (`id`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
