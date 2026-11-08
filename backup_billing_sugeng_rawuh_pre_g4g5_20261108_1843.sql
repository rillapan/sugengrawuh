-- MySQL dump 10.13  Distrib 8.0.44, for macos12.7 (arm64)
--
-- Host: 127.0.0.1    Database: billing-sugeng-rawuh
-- ------------------------------------------------------
-- Server version	8.0.44

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!50503 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Table structure for table `custom_package_fnb`
--

DROP TABLE IF EXISTS `custom_package_fnb`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `custom_package_fnb` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `custom_package_id` bigint unsigned NOT NULL,
  `fnb_id` bigint unsigned NOT NULL,
  `quantity` int NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `custom_package_fnb_custom_package_id_foreign` (`custom_package_id`),
  KEY `custom_package_fnb_fnb_id_foreign` (`fnb_id`),
  CONSTRAINT `custom_package_fnb_custom_package_id_foreign` FOREIGN KEY (`custom_package_id`) REFERENCES `custom_packages` (`id`) ON DELETE CASCADE,
  CONSTRAINT `custom_package_fnb_fnb_id_foreign` FOREIGN KEY (`fnb_id`) REFERENCES `fnbs` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `custom_package_fnb`
--

LOCK TABLES `custom_package_fnb` WRITE;
/*!40000 ALTER TABLE `custom_package_fnb` DISABLE KEYS */;
/*!40000 ALTER TABLE `custom_package_fnb` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `custom_package_playstation`
--

DROP TABLE IF EXISTS `custom_package_playstation`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `custom_package_playstation` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `custom_package_id` bigint unsigned NOT NULL,
  `playstation_id` bigint unsigned NOT NULL,
  `lama_main` int NOT NULL COMMENT 'Duration in minutes',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `custom_package_playstation_custom_package_id_foreign` (`custom_package_id`),
  KEY `custom_package_playstation_playstation_id_foreign` (`playstation_id`),
  CONSTRAINT `custom_package_playstation_custom_package_id_foreign` FOREIGN KEY (`custom_package_id`) REFERENCES `custom_packages` (`id`) ON DELETE CASCADE,
  CONSTRAINT `custom_package_playstation_playstation_id_foreign` FOREIGN KEY (`playstation_id`) REFERENCES `playstations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `custom_package_playstation`
--

LOCK TABLES `custom_package_playstation` WRITE;
/*!40000 ALTER TABLE `custom_package_playstation` DISABLE KEYS */;
/*!40000 ALTER TABLE `custom_package_playstation` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `custom_package_price_group`
--

DROP TABLE IF EXISTS `custom_package_price_group`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `custom_package_price_group` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `custom_package_id` bigint unsigned NOT NULL,
  `price_group_id` bigint unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `custom_package_price_group_custom_package_id_foreign` (`custom_package_id`),
  KEY `custom_package_price_group_price_group_id_foreign` (`price_group_id`),
  CONSTRAINT `custom_package_price_group_custom_package_id_foreign` FOREIGN KEY (`custom_package_id`) REFERENCES `custom_packages` (`id`) ON DELETE CASCADE,
  CONSTRAINT `custom_package_price_group_price_group_id_foreign` FOREIGN KEY (`price_group_id`) REFERENCES `price_groups` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `custom_package_price_group`
--

LOCK TABLES `custom_package_price_group` WRITE;
/*!40000 ALTER TABLE `custom_package_price_group` DISABLE KEYS */;
/*!40000 ALTER TABLE `custom_package_price_group` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `custom_packages`
--

DROP TABLE IF EXISTS `custom_packages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `custom_packages` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `nama_paket` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `harga_total` decimal(10,2) NOT NULL,
  `deskripsi` text COLLATE utf8mb4_unicode_ci,
  `price_group_id` bigint unsigned DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `custom_packages_price_group_id_foreign` (`price_group_id`),
  CONSTRAINT `custom_packages_price_group_id_foreign` FOREIGN KEY (`price_group_id`) REFERENCES `price_groups` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `custom_packages`
--

LOCK TABLES `custom_packages` WRITE;
/*!40000 ALTER TABLE `custom_packages` DISABLE KEYS */;
/*!40000 ALTER TABLE `custom_packages` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `device_playstation`
--

DROP TABLE IF EXISTS `device_playstation`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `device_playstation` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `device_id` bigint unsigned NOT NULL,
  `playstation_id` bigint unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `device_playstation_device_id_playstation_id_unique` (`device_id`,`playstation_id`),
  KEY `device_playstation_playstation_id_foreign` (`playstation_id`),
  CONSTRAINT `device_playstation_device_id_foreign` FOREIGN KEY (`device_id`) REFERENCES `devices` (`id`) ON DELETE CASCADE,
  CONSTRAINT `device_playstation_playstation_id_foreign` FOREIGN KEY (`playstation_id`) REFERENCES `playstations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `device_playstation`
--

LOCK TABLES `device_playstation` WRITE;
/*!40000 ALTER TABLE `device_playstation` DISABLE KEYS */;
INSERT INTO `device_playstation` VALUES (1,1,3,NULL,NULL),(2,1,4,NULL,NULL),(3,2,3,NULL,NULL),(4,2,4,NULL,NULL);
/*!40000 ALTER TABLE `device_playstation` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `devices`
--

DROP TABLE IF EXISTS `devices`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `devices` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `nama` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `playstation_id` bigint unsigned NOT NULL,
  `status` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `devices`
--

LOCK TABLES `devices` WRITE;
/*!40000 ALTER TABLE `devices` DISABLE KEYS */;
INSERT INTO `devices` VALUES (1,'Meja 1 - Billiard',3,'Tersedia','2026-11-04 03:31:53','2026-11-04 13:21:03'),(2,'Meja 2 - Billiard',3,'Tersedia','2026-11-04 03:31:53','2026-11-04 06:55:14');
/*!40000 ALTER TABLE `devices` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `expense_categories`
--

DROP TABLE IF EXISTS `expense_categories`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `expense_categories` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `nama` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `deskripsi` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `expense_categories`
--

LOCK TABLES `expense_categories` WRITE;
/*!40000 ALTER TABLE `expense_categories` DISABLE KEYS */;
/*!40000 ALTER TABLE `expense_categories` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `expenses`
--

DROP TABLE IF EXISTS `expenses`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `expenses` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `expense_category_id` bigint unsigned DEFAULT NULL,
  `deskripsi` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `jumlah` decimal(15,2) NOT NULL,
  `tanggal` date NOT NULL,
  `metode_pembayaran` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `catatan` text COLLATE utf8mb4_unicode_ci,
  `user_id` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `expenses_user_id_foreign` (`user_id`),
  KEY `expenses_expense_category_id_foreign` (`expense_category_id`),
  CONSTRAINT `expenses_expense_category_id_foreign` FOREIGN KEY (`expense_category_id`) REFERENCES `expense_categories` (`id`) ON DELETE SET NULL,
  CONSTRAINT `expenses_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `expenses`
--

LOCK TABLES `expenses` WRITE;
/*!40000 ALTER TABLE `expenses` DISABLE KEYS */;
/*!40000 ALTER TABLE `expenses` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `failed_jobs`
--

DROP TABLE IF EXISTS `failed_jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `failed_jobs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `uuid` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `connection` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `queue` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `payload` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `exception` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `failed_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `failed_jobs_uuid_unique` (`uuid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `failed_jobs`
--

LOCK TABLES `failed_jobs` WRITE;
/*!40000 ALTER TABLE `failed_jobs` DISABLE KEYS */;
/*!40000 ALTER TABLE `failed_jobs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `fnbs`
--

DROP TABLE IF EXISTS `fnbs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `fnbs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `nama` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `harga_beli` int DEFAULT NULL,
  `harga_jual` int NOT NULL,
  `price_group_id` bigint unsigned DEFAULT NULL,
  `stok` int NOT NULL DEFAULT '0',
  `deskripsi` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fnbs_price_group_id_foreign` (`price_group_id`),
  CONSTRAINT `fnbs_price_group_id_foreign` FOREIGN KEY (`price_group_id`) REFERENCES `price_groups` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `fnbs`
--

LOCK TABLES `fnbs` WRITE;
/*!40000 ALTER TABLE `fnbs` DISABLE KEYS */;
INSERT INTO `fnbs` VALUES (1,'Mie Goreng',NULL,5000,1,-1,'jdiknwi','2026-11-04 05:56:46','2026-11-04 05:56:46');
/*!40000 ALTER TABLE `fnbs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `hourly_prices`
--

DROP TABLE IF EXISTS `hourly_prices`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `hourly_prices` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `playstation_id` bigint unsigned NOT NULL,
  `hour` int NOT NULL,
  `price` int NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `hourly_prices_playstation_id_hour_unique` (`playstation_id`,`hour`),
  CONSTRAINT `hourly_prices_playstation_id_foreign` FOREIGN KEY (`playstation_id`) REFERENCES `playstations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `hourly_prices`
--

LOCK TABLES `hourly_prices` WRITE;
/*!40000 ALTER TABLE `hourly_prices` DISABLE KEYS */;
/*!40000 ALTER TABLE `hourly_prices` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `members`
--

DROP TABLE IF EXISTS `members`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `members` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint unsigned NOT NULL,
  `jenis_kelamin` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `no_telepon` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `alamat` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `members_user_id_unique` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `members`
--

LOCK TABLES `members` WRITE;
/*!40000 ALTER TABLE `members` DISABLE KEYS */;
/*!40000 ALTER TABLE `members` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `migrations`
--

DROP TABLE IF EXISTS `migrations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `migrations` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `migration` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `batch` int NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=53 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `migrations`
--

LOCK TABLES `migrations` WRITE;
/*!40000 ALTER TABLE `migrations` DISABLE KEYS */;
INSERT INTO `migrations` VALUES (1,'2014_10_12_000000_create_users_table',1),(2,'2014_10_12_100000_create_password_resets_table',1),(3,'2019_08_19_000000_create_failed_jobs_table',1),(4,'2019_12_14_000001_create_personal_access_tokens_table',1),(5,'2023_02_21_025346_create_members_table',1),(6,'2023_02_21_030516_create_playstations_table',1),(7,'2023_02_21_030612_create_transactions_table',1),(8,'2023_02_25_053515_create_devices_table',1),(9,'2024_10_01_000000_add_fnb_total_to_transactions_table',1),(10,'2025_09_26_130345_add_harga_to_playstations_table',1),(11,'2025_09_26_130946_drop_stok_from_playstations_table',1),(12,'2025_09_27_114739_create_fnbs_table',1),(13,'2025_09_27_114806_create_stock_mutations_table',1),(14,'2025_09_27_114828_create_transaction_fnbs_table',1),(15,'2025_09_27_140000_modify_id_transaksi_in_transactions_table',1),(16,'2025_09_30_205303_modify_fnbs_table_remove_required_fields',1),(17,'2025_09_30_220210_add_tipe_transaksi_to_transactions_table',1),(18,'2025_09_30_223702_modify_jam_main_nullable_in_transactions_table',1),(19,'2025_10_01_091438_rename_id_to_id_transaksi_in_transactions_table',1),(20,'2025_10_03_114248_add_payment_status_to_transactions_table',1),(21,'2025_11_28_210057_add_harga_beli_to_transaction_fnbs_table',1),(22,'2025_11_30_113711_fix_transaction_fnb_foreign_key',1),(23,'2025_12_07_160523_create_price_groups_table',1),(24,'2025_12_07_160715_add_price_group_id_to_fnbs_table',1),(25,'2025_12_07_161545_make_harga_beli_nullable_in_fnbs_table',1),(26,'2025_12_07_172224_add_payment_method_to_transactions_table',1),(27,'2025_12_07_172804_add_no_telepon_to_transactions_table',1),(28,'2025_12_07_174820_create_expenses_table',1),(29,'2025_12_07_194411_create_expense_categories_table',1),(30,'2025_12_07_194647_add_expense_category_id_to_expenses_table',1),(31,'2025_12_08_205036_create_custom_packages_table',1),(32,'2025_12_08_205127_create_custom_package_device_table',1),(33,'2025_12_08_205131_create_custom_package_fnb_table',1),(34,'2025_12_08_210811_add_custom_package_id_to_transactions_table',1),(35,'2025_12_08_211555_update_tipe_transaksi_enum_in_transactions_table',1),(36,'2025_12_08_212315_make_device_id_nullable_in_transactions_table',1),(37,'2025_12_09_214648_fix_tipe_transaksi_enum_values',1),(38,'2025_12_09_220000_fix_custom_package_enum',1),(39,'2025_12_09_220100_change_custom_package_device_to_playstation',1),(40,'2025_12_10_193018_add_diskon_to_transactions_table',1),(41,'2025_12_10_210503_add_lost_time_start_to_transactions_table',1),(42,'2025_12_11_000000_update_users_table_for_roles',1),(43,'2025_12_13_223700_make_image_nullable_in_playstations_table',1),(44,'2025_12_15_203348_create_hourly_prices_table',1),(45,'2025_12_15_210000_add_price_group_to_custom_packages_table',1),(46,'2025_12_20_204543_create_custom_package_price_group_table',1),(47,'2025_12_20_212011_create_device_playstation_table',1),(48,'2025_12_23_215408_add_playstation_id_to_transactions_table',1),(49,'2025_12_23_223208_add_playstation_id_column_to_transactions_table',1),(50,'2025_12_24_000000_create_work_shifts_table',1),(51,'2025_12_24_000001_update_users_table_for_work_shifts',1),(52,'2025_12_24_000002_add_paid_at_to_transactions_table',1);
/*!40000 ALTER TABLE `migrations` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `password_resets`
--

DROP TABLE IF EXISTS `password_resets`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `password_resets` (
  `email` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `token` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  KEY `password_resets_email_index` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `password_resets`
--

LOCK TABLES `password_resets` WRITE;
/*!40000 ALTER TABLE `password_resets` DISABLE KEYS */;
/*!40000 ALTER TABLE `password_resets` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `personal_access_tokens`
--

DROP TABLE IF EXISTS `personal_access_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `personal_access_tokens` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tokenable_type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `tokenable_id` bigint unsigned NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `token` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `abilities` text COLLATE utf8mb4_unicode_ci,
  `last_used_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `personal_access_tokens_token_unique` (`token`),
  KEY `personal_access_tokens_tokenable_type_tokenable_id_index` (`tokenable_type`,`tokenable_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `personal_access_tokens`
--

LOCK TABLES `personal_access_tokens` WRITE;
/*!40000 ALTER TABLE `personal_access_tokens` DISABLE KEYS */;
/*!40000 ALTER TABLE `personal_access_tokens` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `playstations`
--

DROP TABLE IF EXISTS `playstations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `playstations` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `nama` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `image` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `harga` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `playstations`
--

LOCK TABLES `playstations` WRITE;
/*!40000 ALTER TABLE `playstations` DISABLE KEYS */;
INSERT INTO `playstations` VALUES (1,'PS4','post-images/ps4.jpg','2026-11-04 03:31:53','2026-11-04 03:31:53','15000'),(2,'PS5','post-images/ps5.jpg','2026-11-04 03:31:53','2026-11-04 03:31:53','20000'),(3,'BILLIARD pagi','post-images/billiard.jpg','2026-11-04 03:31:53','2026-11-04 03:31:53','25000'),(4,'BILLIARD sore','post-images/billiard.jpg','2026-11-04 03:31:53','2026-11-04 03:31:53','30000');
/*!40000 ALTER TABLE `playstations` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `price_groups`
--

DROP TABLE IF EXISTS `price_groups`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `price_groups` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `nama` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `harga` int NOT NULL,
  `deskripsi` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `price_groups`
--

LOCK TABLES `price_groups` WRITE;
/*!40000 ALTER TABLE `price_groups` DISABLE KEYS */;
INSERT INTO `price_groups` VALUES (1,'mie',5000,'mie goreng','2026-11-04 05:56:28','2026-11-04 05:56:28');
/*!40000 ALTER TABLE `price_groups` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `stock_mutations`
--

DROP TABLE IF EXISTS `stock_mutations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `stock_mutations` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `fnb_id` bigint unsigned NOT NULL,
  `type` enum('in','out') COLLATE utf8mb4_unicode_ci NOT NULL,
  `qty` int NOT NULL,
  `date` date NOT NULL,
  `note` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `stock_mutations_fnb_id_foreign` (`fnb_id`),
  CONSTRAINT `stock_mutations_fnb_id_foreign` FOREIGN KEY (`fnb_id`) REFERENCES `fnbs` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `stock_mutations`
--

LOCK TABLES `stock_mutations` WRITE;
/*!40000 ALTER TABLE `stock_mutations` DISABLE KEYS */;
INSERT INTO `stock_mutations` VALUES (1,1,'out',1,'2026-11-04','Penjualan - Transaksi #2','2026-11-04 05:57:01','2026-11-04 05:57:01');
/*!40000 ALTER TABLE `stock_mutations` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `transaction_fnbs`
--

DROP TABLE IF EXISTS `transaction_fnbs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `transaction_fnbs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `transaction_id` bigint unsigned NOT NULL,
  `fnb_id` bigint unsigned NOT NULL,
  `qty` int NOT NULL,
  `harga_jual` int NOT NULL,
  `harga_beli` int NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `transaction_fnbs_fnb_id_foreign` (`fnb_id`),
  KEY `transaction_fnbs_transaction_id_foreign` (`transaction_id`),
  CONSTRAINT `transaction_fnbs_fnb_id_foreign` FOREIGN KEY (`fnb_id`) REFERENCES `fnbs` (`id`) ON DELETE CASCADE,
  CONSTRAINT `transaction_fnbs_transaction_id_foreign` FOREIGN KEY (`transaction_id`) REFERENCES `transactions` (`id_transaksi`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `transaction_fnbs`
--

LOCK TABLES `transaction_fnbs` WRITE;
/*!40000 ALTER TABLE `transaction_fnbs` DISABLE KEYS */;
INSERT INTO `transaction_fnbs` VALUES (1,2,1,1,5000,0,'2026-11-04 05:57:01','2026-11-04 05:57:01');
/*!40000 ALTER TABLE `transaction_fnbs` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `transactions`
--

DROP TABLE IF EXISTS `transactions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `transactions` (
  `id_transaksi` bigint unsigned NOT NULL AUTO_INCREMENT,
  `status` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `member_id` bigint unsigned DEFAULT NULL,
  `nama` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `no_telepon` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `device_id` bigint unsigned DEFAULT NULL,
  `playstation_id` bigint unsigned DEFAULT NULL,
  `custom_package_id` bigint unsigned DEFAULT NULL,
  `diskon` decimal(5,2) NOT NULL DEFAULT '0.00',
  `user_id` bigint unsigned DEFAULT NULL,
  `harga` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `jam_main` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `waktu_mulai` time NOT NULL,
  `waktu_Selesai` time DEFAULT NULL,
  `lost_time_start` timestamp NULL DEFAULT NULL,
  `total` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `fnb_total` decimal(10,2) NOT NULL DEFAULT '0.00',
  `status_transaksi` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `tipe_transaksi` enum('prepaid','postpaid','custom_package') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'prepaid',
  `payment_status` enum('paid','unpaid') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'unpaid',
  `paid_at` timestamp NULL DEFAULT NULL,
  `payment_method` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id_transaksi`),
  KEY `transactions_custom_package_id_foreign` (`custom_package_id`),
  KEY `transactions_playstation_id_foreign` (`playstation_id`),
  CONSTRAINT `transactions_custom_package_id_foreign` FOREIGN KEY (`custom_package_id`) REFERENCES `custom_packages` (`id`) ON DELETE SET NULL,
  CONSTRAINT `transactions_playstation_id_foreign` FOREIGN KEY (`playstation_id`) REFERENCES `playstations` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `transactions`
--

LOCK TABLES `transactions` WRITE;
/*!40000 ALTER TABLE `transactions` DISABLE KEYS */;
INSERT INTO `transactions` VALUES (1,'user',NULL,'budi',NULL,1,3,NULL,0.00,3,'25000',NULL,'12:53:00','20:21:00','2026-11-04 05:53:44','0',0.00,'selesai','2026-11-04 05:53:44','2026-11-04 13:21:03','postpaid','unpaid',NULL,NULL),(2,'user',NULL,'SPM YULIA',NULL,2,3,NULL,0.00,3,'25000','1','12:54:00','13:54:00',NULL,'30000',0.00,'selesai','2026-11-04 05:54:54','2026-11-04 05:59:05','prepaid','paid','2026-11-04 05:59:05','tunai');
/*!40000 ALTER TABLE `transactions` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `users` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email_verified_at` timestamp NULL DEFAULT NULL,
  `password` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `role` enum('admin','owner','kasir') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'kasir',
  `shift` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `work_shift_id` bigint unsigned DEFAULT NULL,
  `remember_token` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `users_email_unique` (`email`),
  KEY `users_work_shift_id_foreign` (`work_shift_id`),
  CONSTRAINT `users_work_shift_id_foreign` FOREIGN KEY (`work_shift_id`) REFERENCES `work_shifts` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `users`
--

LOCK TABLES `users` WRITE;
/*!40000 ALTER TABLE `users` DISABLE KEYS */;
INSERT INTO `users` VALUES (1,'Operator 1','operator1@gmail.com',NULL,'$2y$10$HR4ncHkh3ON9jwMwMfVKy.Pr7jhfZL1tgLHhFbhEEXdAYhLyiG2hW','kasir',NULL,1,NULL,'2026-11-04 03:31:53','2026-11-04 06:16:56'),(2,'Operator 2','operator2@gmail.com',NULL,'$2y$10$CAhCg1hptc7iicwHCxK67exotlCrVl/dqGk3sm8O.6yDxfohC00U6','kasir',NULL,2,NULL,'2026-11-04 03:31:53','2026-11-04 06:16:58'),(3,'Admin','admin@gmail.com',NULL,'$2y$10$/JPp1MmcGetUXyJhqPefMu3Enot78yQ9vtqfZPlwPMGVaSdYWxwiW','admin',NULL,NULL,NULL,'2026-11-04 03:31:53','2026-11-04 03:31:53');
/*!40000 ALTER TABLE `users` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `work_shifts`
--

DROP TABLE IF EXISTS `work_shifts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `work_shifts` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `nama_shift` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `jam_mulai` time NOT NULL,
  `jam_selesai` time NOT NULL,
  `keterangan` text COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `work_shifts`
--

LOCK TABLES `work_shifts` WRITE;
/*!40000 ALTER TABLE `work_shifts` DISABLE KEYS */;
INSERT INTO `work_shifts` VALUES (1,'Pagi','06:00:00','14:00:00','Shift pagi 06:00 - 14:00','2026-11-04 03:31:53','2026-11-04 03:31:53'),(2,'Siang','14:00:00','22:00:00','Shift siang 14:00 - 22:00','2026-11-04 03:31:53','2026-11-04 03:31:53'),(3,'Malam','22:00:00','06:00:00','Shift malam 22:00 - 06:00 (overnight)','2026-11-04 03:31:53','2026-11-04 03:31:53');
/*!40000 ALTER TABLE `work_shifts` ENABLE KEYS */;
UNLOCK TABLES;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-11-08 18:43:55
