/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;
DROP TABLE IF EXISTS `addebiti_mensili`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `addebiti_mensili` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint(20) unsigned NOT NULL,
  `plan_id` bigint(20) unsigned NOT NULL,
  `periodo` varchar(7) NOT NULL,
  `iscritti_contati` int(10) unsigned NOT NULL,
  `iscritti_fatturati` int(10) unsigned NOT NULL,
  `prezzo_iscritto_cent` int(10) unsigned NOT NULL,
  `importo_cent` int(10) unsigned NOT NULL,
  `stato` varchar(20) NOT NULL DEFAULT 'da_incassare',
  `stripe_invoice_id` varchar(64) DEFAULT NULL,
  `errore` text DEFAULT NULL,
  `incassato_il` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `addebiti_mensili_tenant_id_periodo_unique` (`tenant_id`,`periodo`),
  UNIQUE KEY `addebiti_mensili_stripe_invoice_id_unique` (`stripe_invoice_id`),
  KEY `addebiti_mensili_plan_id_foreign` (`plan_id`),
  KEY `addebiti_mensili_stato_periodo_index` (`stato`,`periodo`),
  CONSTRAINT `addebiti_mensili_plan_id_foreign` FOREIGN KEY (`plan_id`) REFERENCES `plans` (`id`),
  CONSTRAINT `addebiti_mensili_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `ai_advices`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `ai_advices` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint(20) unsigned NOT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `date` date NOT NULL,
  `fascia` varchar(16) NOT NULL,
  `kind` varchar(32) NOT NULL DEFAULT 'daily',
  `context_hash` varchar(32) NOT NULL,
  `model` varchar(64) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ai_advices_fascia_unique` (`user_id`,`fascia`,`kind`),
  KEY `ai_advices_tenant_id_date_index` (`tenant_id`,`date`),
  CONSTRAINT `ai_advices_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `ai_advices_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `ai_credit_movements`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `ai_credit_movements` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint(20) unsigned NOT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `delta` int(11) NOT NULL,
  `saldo_dopo` bigint(20) unsigned NOT NULL,
  `causale` varchar(32) NOT NULL,
  `ai_usage_log_id` bigint(20) unsigned DEFAULT NULL,
  `operatore_id` bigint(20) unsigned DEFAULT NULL,
  `nota` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ai_credit_movements_ai_usage_log_id_foreign` (`ai_usage_log_id`),
  KEY `ai_credit_movements_operatore_id_foreign` (`operatore_id`),
  KEY `ai_credit_movements_tenant_id_created_at_index` (`tenant_id`,`created_at`),
  KEY `ai_credit_movements_user_id_foreign` (`user_id`),
  CONSTRAINT `ai_credit_movements_ai_usage_log_id_foreign` FOREIGN KEY (`ai_usage_log_id`) REFERENCES `ai_usage_logs` (`id`) ON DELETE SET NULL,
  CONSTRAINT `ai_credit_movements_operatore_id_foreign` FOREIGN KEY (`operatore_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `ai_credit_movements_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `ai_credit_movements_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `ai_usage_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `ai_usage_logs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint(20) unsigned DEFAULT NULL,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `provider` varchar(32) NOT NULL,
  `model` varchar(64) NOT NULL,
  `feature` varchar(32) NOT NULL,
  `input_tokens` int(10) unsigned NOT NULL DEFAULT 0,
  `output_tokens` int(10) unsigned NOT NULL DEFAULT 0,
  `cache_read_tokens` int(10) unsigned NOT NULL DEFAULT 0,
  `cache_creation_tokens` int(10) unsigned NOT NULL DEFAULT 0,
  `cost_millicents` bigint(20) unsigned NOT NULL DEFAULT 0,
  `duration_ms` int(10) unsigned NOT NULL DEFAULT 0,
  `success` tinyint(1) NOT NULL DEFAULT 1,
  `error_code` varchar(64) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `ai_usage_logs_tenant_id_created_at_index` (`tenant_id`,`created_at`),
  KEY `ai_usage_logs_tenant_id_feature_index` (`tenant_id`,`feature`),
  KEY `ai_usage_logs_user_id_created_at_index` (`user_id`,`created_at`),
  KEY `ai_usage_logs_quota_index` (`user_id`,`feature`,`created_at`),
  CONSTRAINT `ai_usage_logs_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `ai_usage_logs_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `allegati_cifrati`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `allegati_cifrati` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `conversation_id` bigint(20) unsigned NOT NULL,
  `sender_id` bigint(20) unsigned NOT NULL,
  `token` varchar(64) NOT NULL,
  `byte_totali` int(10) unsigned NOT NULL,
  `scade_il` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `allegati_cifrati_token_unique` (`token`),
  KEY `allegati_cifrati_sender_id_foreign` (`sender_id`),
  KEY `allegati_cifrati_conversation_id_created_at_index` (`conversation_id`,`created_at`),
  KEY `allegati_cifrati_scade_il_index` (`scade_il`),
  CONSTRAINT `allegati_cifrati_conversation_id_foreign` FOREIGN KEY (`conversation_id`) REFERENCES `conversations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `allegati_cifrati_sender_id_foreign` FOREIGN KEY (`sender_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `audit_logs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `audit_logs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint(20) unsigned DEFAULT NULL,
  `actor_id` bigint(20) unsigned DEFAULT NULL,
  `actor_label` varchar(255) DEFAULT NULL,
  `actor_email` varchar(255) DEFAULT NULL,
  `action` varchar(64) NOT NULL,
  `auditable_type` varchar(255) DEFAULT NULL,
  `auditable_id` bigint(20) unsigned DEFAULT NULL,
  `payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`payload`)),
  `ip` varchar(45) DEFAULT NULL,
  `user_agent` varchar(512) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `audit_logs_auditable_type_auditable_id_index` (`auditable_type`,`auditable_id`),
  KEY `audit_logs_tenant_id_created_at_index` (`tenant_id`,`created_at`),
  KEY `audit_logs_action_created_at_index` (`action`,`created_at`),
  KEY `audit_logs_actor_id_created_at_index` (`actor_id`,`created_at`),
  KEY `audit_logs_created_at_index` (`created_at`),
  CONSTRAINT `audit_logs_actor_id_foreign` FOREIGN KEY (`actor_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `audit_logs_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `cache`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `cache` (
  `key` varchar(255) NOT NULL,
  `value` mediumtext NOT NULL,
  `expiration` bigint(20) NOT NULL,
  PRIMARY KEY (`key`),
  KEY `cache_expiration_index` (`expiration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `cache_locks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `cache_locks` (
  `key` varchar(255) NOT NULL,
  `owner` varchar(255) NOT NULL,
  `expiration` bigint(20) NOT NULL,
  PRIMARY KEY (`key`),
  KEY `cache_locks_expiration_index` (`expiration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `campagne`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `campagne` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint(20) unsigned DEFAULT NULL,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `attiva` tinyint(1) NOT NULL DEFAULT 0,
  `budget_mensile_cent` int(10) unsigned NOT NULL,
  `speso_mese_cent` int(10) unsigned NOT NULL DEFAULT 0,
  `costo_visualizzazione_cent` int(10) unsigned NOT NULL,
  `mese` varchar(7) DEFAULT NULL,
  `esaurita_il` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `campagne_tenant_id_unique` (`tenant_id`),
  UNIQUE KEY `campagne_user_id_unique` (`user_id`),
  KEY `campagne_attiva_index` (`attiva`),
  CONSTRAINT `campagne_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `campagne_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `chat_keys`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `chat_keys` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `public_key` varchar(64) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `chat_keys_user_id_unique` (`user_id`),
  CONSTRAINT `chat_keys_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `comuni`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `comuni` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `codice` varchar(6) NOT NULL,
  `nome` varchar(80) NOT NULL,
  `nome_altro` varchar(80) DEFAULT NULL,
  `chiave` varchar(100) NOT NULL,
  `chiave_altro` varchar(100) DEFAULT NULL,
  `provincia` varchar(2) NOT NULL,
  `provincia_nome` varchar(60) NOT NULL,
  `regione` varchar(40) NOT NULL,
  `cap` varchar(5) DEFAULT NULL,
  `popolazione` int(10) unsigned DEFAULT NULL,
  `lat` decimal(9,6) DEFAULT NULL,
  `lng` decimal(9,6) DEFAULT NULL,
  `attivo` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `comuni_codice_unique` (`codice`),
  KEY `comuni_lat_lng_index` (`lat`,`lng`),
  KEY `comuni_attivo_chiave_index` (`attivo`,`chiave`),
  KEY `comuni_chiave_index` (`chiave`),
  KEY `comuni_chiave_altro_index` (`chiave_altro`),
  KEY `comuni_provincia_index` (`provincia`),
  KEY `comuni_regione_index` (`regione`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `conversations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `conversations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint(20) unsigned DEFAULT NULL,
  `trainer_id` bigint(20) unsigned NOT NULL,
  `member_id` bigint(20) unsigned NOT NULL,
  `tipo` varchar(16) NOT NULL DEFAULT 'iscritto',
  `messaggi_di` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`messaggi_di`)),
  `last_message_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `conversations_trainer_id_member_id_unique` (`trainer_id`,`member_id`),
  KEY `conversations_member_id_foreign` (`member_id`),
  KEY `conversations_tenant_id_last_message_at_index` (`tenant_id`,`last_message_at`),
  KEY `conversations_tipo_index` (`tipo`),
  CONSTRAINT `conversations_member_id_foreign` FOREIGN KEY (`member_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `conversations_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `conversations_trainer_id_foreign` FOREIGN KEY (`trainer_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `device_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `device_tokens` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint(20) unsigned DEFAULT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `token` varchar(512) NOT NULL,
  `platform` varchar(16) NOT NULL,
  `last_used_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `device_tokens_unique` (`user_id`,`token`),
  KEY `device_tokens_tenant_id_foreign` (`tenant_id`),
  CONSTRAINT `device_tokens_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `device_tokens_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `exercises`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `exercises` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint(20) unsigned DEFAULT NULL,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `name` varchar(160) NOT NULL,
  `slug_normalized` varchar(160) NOT NULL,
  `muscle_group` varchar(32) DEFAULT NULL,
  `secondary_muscles` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`secondary_muscles`)),
  `equipment` varchar(64) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `is_custom` tinyint(1) NOT NULL DEFAULT 0,
  `sostituito_da_id` bigint(20) unsigned DEFAULT NULL,
  `met` decimal(4,2) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `exercises_created_by_foreign` (`created_by`),
  KEY `exercises_tenant_id_name_index` (`tenant_id`,`name`),
  KEY `exercises_tenant_id_muscle_group_index` (`tenant_id`,`muscle_group`),
  KEY `exercises_slug_normalized_tenant_id_index` (`slug_normalized`,`tenant_id`),
  KEY `exercises_sostituito_da_id_index` (`sostituito_da_id`),
  CONSTRAINT `exercises_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `exercises_sostituito_da_id_foreign` FOREIGN KEY (`sostituito_da_id`) REFERENCES `exercises` (`id`) ON DELETE SET NULL,
  CONSTRAINT `exercises_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `failed_jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `failed_jobs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `uuid` varchar(255) NOT NULL,
  `connection` varchar(255) NOT NULL,
  `queue` varchar(255) NOT NULL,
  `payload` longtext NOT NULL,
  `exception` longtext NOT NULL,
  `failed_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `failed_jobs_uuid_unique` (`uuid`),
  KEY `failed_jobs_connection_queue_failed_at_index` (`connection`,`queue`,`failed_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `food_entries`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `food_entries` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint(20) unsigned NOT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `eaten_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `meal` varchar(24) NOT NULL,
  `description` varchar(255) NOT NULL,
  `grams` decimal(8,2) DEFAULT NULL,
  `qty` decimal(8,2) DEFAULT NULL,
  `unit` varchar(16) DEFAULT NULL,
  `kcal` decimal(8,2) DEFAULT NULL,
  `protein` decimal(7,2) DEFAULT NULL,
  `carbs` decimal(7,2) DEFAULT NULL,
  `fat` decimal(7,2) DEFAULT NULL,
  `kcal_100` decimal(7,2) DEFAULT NULL,
  `protein_100` decimal(6,2) DEFAULT NULL,
  `carbs_100` decimal(6,2) DEFAULT NULL,
  `fat_100` decimal(6,2) DEFAULT NULL,
  `source` varchar(16) NOT NULL DEFAULT 'manual',
  `ai_raw` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`ai_raw`)),
  `nutrition_plan_id` bigint(20) unsigned DEFAULT NULL,
  `food_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `food_entries_user_id_eaten_at_index` (`user_id`,`eaten_at`),
  KEY `food_entries_tenant_id_eaten_at_index` (`tenant_id`,`eaten_at`),
  KEY `food_entries_user_id_meal_eaten_at_index` (`user_id`,`meal`,`eaten_at`),
  KEY `food_entries_nutrition_plan_id_foreign` (`nutrition_plan_id`),
  KEY `food_entries_food_id_foreign` (`food_id`),
  KEY `food_entries_user_id_food_id_index` (`user_id`,`food_id`),
  CONSTRAINT `food_entries_food_id_foreign` FOREIGN KEY (`food_id`) REFERENCES `foods` (`id`) ON DELETE SET NULL,
  CONSTRAINT `food_entries_nutrition_plan_id_foreign` FOREIGN KEY (`nutrition_plan_id`) REFERENCES `nutrition_plans` (`id`) ON DELETE SET NULL,
  CONSTRAINT `food_entries_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `food_entries_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `food_favorites`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `food_favorites` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint(20) unsigned NOT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `description` varchar(255) NOT NULL,
  `is_meal` tinyint(1) NOT NULL DEFAULT 0,
  `items` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`items`)),
  `grams` decimal(8,2) DEFAULT NULL,
  `qty` decimal(8,2) DEFAULT NULL,
  `unit` varchar(16) DEFAULT NULL,
  `kcal` decimal(8,2) DEFAULT NULL,
  `protein` decimal(7,2) DEFAULT NULL,
  `carbs` decimal(7,2) DEFAULT NULL,
  `fat` decimal(7,2) DEFAULT NULL,
  `kcal_100` decimal(7,2) DEFAULT NULL,
  `protein_100` decimal(6,2) DEFAULT NULL,
  `carbs_100` decimal(6,2) DEFAULT NULL,
  `fat_100` decimal(6,2) DEFAULT NULL,
  `times_used` int(10) unsigned NOT NULL DEFAULT 0,
  `last_used_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `food_favorites_tenant_id_foreign` (`tenant_id`),
  KEY `food_favorites_user_id_is_meal_index` (`user_id`,`is_meal`),
  KEY `food_favorites_user_id_times_used_index` (`user_id`,`times_used`),
  CONSTRAINT `food_favorites_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `food_favorites_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `foods`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `foods` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `chiave` varchar(200) NOT NULL,
  `nome` varchar(120) NOT NULL,
  `marca` varchar(60) DEFAULT NULL,
  `kcal_100` decimal(7,2) NOT NULL,
  `protein_100` decimal(6,2) NOT NULL,
  `carbs_100` decimal(6,2) NOT NULL,
  `fat_100` decimal(6,2) NOT NULL,
  `basis` varchar(2) NOT NULL DEFAULT 'g',
  `origine` varchar(8) NOT NULL DEFAULT 'manuale',
  `fonte` varchar(40) DEFAULT NULL,
  `note` varchar(255) DEFAULT NULL,
  `immagine_url` varchar(255) DEFAULT NULL,
  `immagine_piccola_url` varchar(255) DEFAULT NULL,
  `codice_a_barre` varchar(20) DEFAULT NULL,
  `conferme` int(10) unsigned NOT NULL DEFAULT 1,
  `usi` int(10) unsigned NOT NULL DEFAULT 0,
  `pubblico` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `foods_chiave_unique` (`chiave`),
  KEY `foods_pubblico_chiave_index` (`pubblico`,`chiave`),
  KEY `foods_usi_index` (`usi`),
  KEY `foods_codice_a_barre_index` (`codice_a_barre`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `importazioni_da_documento`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `importazioni_da_documento` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `tenant_id` bigint(20) unsigned NOT NULL,
  `genere` varchar(16) NOT NULL DEFAULT 'piano',
  `documenti` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`documenti`)),
  `tipo` varchar(16) NOT NULL DEFAULT 'pdf',
  `nome_file` varchar(255) NOT NULL,
  `byte_totali` int(10) unsigned NOT NULL,
  `stato` varchar(16) NOT NULL DEFAULT 'in_coda',
  `paga_con_gettoni` tinyint(1) NOT NULL DEFAULT 0,
  `bozza` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`bozza`)),
  `modello_usato` varchar(64) DEFAULT NULL,
  `errore` text DEFAULT NULL,
  `dichiarato_il` timestamp NULL DEFAULT NULL,
  `consenso_documento_il` timestamp NULL DEFAULT NULL,
  `scade_il` datetime NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `importazioni_piani_tenant_id_foreign` (`tenant_id`),
  KEY `importazioni_piani_user_id_created_at_index` (`user_id`,`created_at`),
  KEY `importazioni_piani_scade_il_index` (`scade_il`),
  CONSTRAINT `importazioni_piani_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `importazioni_piani_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `job_batches`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `job_batches` (
  `id` varchar(255) NOT NULL,
  `name` varchar(255) NOT NULL,
  `total_jobs` int(11) NOT NULL,
  `pending_jobs` int(11) NOT NULL,
  `failed_jobs` int(11) NOT NULL,
  `failed_job_ids` longtext NOT NULL,
  `options` mediumtext DEFAULT NULL,
  `cancelled_at` int(11) DEFAULT NULL,
  `created_at` int(11) NOT NULL,
  `finished_at` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `jobs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `queue` varchar(255) NOT NULL,
  `payload` longtext NOT NULL,
  `attempts` smallint(5) unsigned NOT NULL,
  `reserved_at` int(10) unsigned DEFAULT NULL,
  `available_at` int(10) unsigned NOT NULL,
  `created_at` int(10) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  KEY `jobs_queue_index` (`queue`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `media`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `media` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint(20) unsigned DEFAULT NULL,
  `model_type` varchar(255) NOT NULL,
  `model_id` bigint(20) unsigned NOT NULL,
  `uuid` char(36) DEFAULT NULL,
  `collection_name` varchar(255) NOT NULL,
  `name` varchar(255) NOT NULL,
  `file_name` varchar(255) NOT NULL,
  `mime_type` varchar(255) DEFAULT NULL,
  `disk` varchar(255) NOT NULL,
  `conversions_disk` varchar(255) DEFAULT NULL,
  `size` bigint(20) unsigned NOT NULL,
  `manipulations` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`manipulations`)),
  `custom_properties` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`custom_properties`)),
  `generated_conversions` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`generated_conversions`)),
  `responsive_images` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`responsive_images`)),
  `order_column` int(10) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `media_uuid_unique` (`uuid`),
  KEY `media_model_type_model_id_index` (`model_type`,`model_id`),
  KEY `media_order_column_index` (`order_column`),
  KEY `media_tenant_id_collection_name_index` (`tenant_id`,`collection_name`),
  CONSTRAINT `media_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `messages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `messages` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `conversation_id` bigint(20) unsigned NOT NULL,
  `sender_id` bigint(20) unsigned NOT NULL,
  `envelope_version` tinyint(3) unsigned NOT NULL,
  `nonce` varchar(64) NOT NULL,
  `body` text NOT NULL,
  `media_id` bigint(20) unsigned DEFAULT NULL,
  `usa_e_getta` tinyint(1) NOT NULL DEFAULT 0,
  `read_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `visto_il` datetime DEFAULT NULL,
  `era_foto` tinyint(1) NOT NULL DEFAULT 0,
  `svuotato_il` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `messages_sender_id_foreign` (`sender_id`),
  KEY `messages_conversation_id_id_index` (`conversation_id`,`id`),
  KEY `messages_effimeri_index` (`usa_e_getta`,`svuotato_il`),
  CONSTRAINT `messages_conversation_id_foreign` FOREIGN KEY (`conversation_id`) REFERENCES `conversations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `messages_sender_id_foreign` FOREIGN KEY (`sender_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `migrations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `migrations` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `migration` varchar(255) NOT NULL,
  `batch` int(11) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `model_has_permissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `model_has_permissions` (
  `permission_id` bigint(20) unsigned NOT NULL,
  `model_type` varchar(255) NOT NULL,
  `model_id` bigint(20) unsigned NOT NULL,
  `tenant_id` bigint(20) unsigned NOT NULL,
  PRIMARY KEY (`tenant_id`,`permission_id`,`model_id`,`model_type`),
  KEY `model_has_permissions_model_id_model_type_index` (`model_id`,`model_type`),
  KEY `model_has_permissions_permission_id_foreign` (`permission_id`),
  KEY `model_has_permissions_team_foreign_key_index` (`tenant_id`),
  CONSTRAINT `model_has_permissions_permission_id_foreign` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `model_has_roles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `model_has_roles` (
  `role_id` bigint(20) unsigned NOT NULL,
  `model_type` varchar(255) NOT NULL,
  `model_id` bigint(20) unsigned NOT NULL,
  `tenant_id` bigint(20) unsigned NOT NULL,
  PRIMARY KEY (`tenant_id`,`role_id`,`model_id`,`model_type`),
  KEY `model_has_roles_model_id_model_type_index` (`model_id`,`model_type`),
  KEY `model_has_roles_role_id_foreign` (`role_id`),
  KEY `model_has_roles_team_foreign_key_index` (`tenant_id`),
  CONSTRAINT `model_has_roles_role_id_foreign` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `nutrition_plan_days`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `nutrition_plan_days` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `nutrition_plan_id` bigint(20) unsigned NOT NULL,
  `alternativa_di_id` bigint(20) unsigned DEFAULT NULL,
  `position` smallint(5) unsigned NOT NULL DEFAULT 0,
  `name` varchar(120) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `nutrition_plan_days_nutrition_plan_id_position_index` (`nutrition_plan_id`,`position`),
  KEY `nutrition_plan_days_alternativa_di_id_index` (`alternativa_di_id`),
  CONSTRAINT `nutrition_plan_days_alternativa_di_id_foreign` FOREIGN KEY (`alternativa_di_id`) REFERENCES `nutrition_plan_days` (`id`) ON DELETE CASCADE,
  CONSTRAINT `nutrition_plan_days_nutrition_plan_id_foreign` FOREIGN KEY (`nutrition_plan_id`) REFERENCES `nutrition_plans` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `nutrition_plan_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `nutrition_plan_items` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `nutrition_plan_meal_id` bigint(20) unsigned NOT NULL,
  `position` smallint(5) unsigned NOT NULL DEFAULT 0,
  `description` varchar(255) NOT NULL,
  `qty` decimal(8,2) DEFAULT NULL,
  `unit` varchar(16) DEFAULT NULL,
  `grams` decimal(8,2) DEFAULT NULL,
  `kcal` decimal(8,2) DEFAULT NULL,
  `protein` decimal(7,2) DEFAULT NULL,
  `carbs` decimal(7,2) DEFAULT NULL,
  `fat` decimal(7,2) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `alternativa_di_id` bigint(20) unsigned DEFAULT NULL,
  `origine_valori` varchar(8) NOT NULL DEFAULT 'manual',
  PRIMARY KEY (`id`),
  KEY `nutrition_plan_items_nutrition_plan_meal_id_position_index` (`nutrition_plan_meal_id`,`position`),
  KEY `nutrition_plan_items_alternativa_di_id_index` (`alternativa_di_id`),
  CONSTRAINT `nutrition_plan_items_alternativa_di_id_foreign` FOREIGN KEY (`alternativa_di_id`) REFERENCES `nutrition_plan_items` (`id`) ON DELETE CASCADE,
  CONSTRAINT `nutrition_plan_items_nutrition_plan_meal_id_foreign` FOREIGN KEY (`nutrition_plan_meal_id`) REFERENCES `nutrition_plan_meals` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `nutrition_plan_meals`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `nutrition_plan_meals` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `nutrition_plan_id` bigint(20) unsigned NOT NULL,
  `meal` varchar(24) NOT NULL,
  `position` smallint(5) unsigned NOT NULL DEFAULT 0,
  `title` varchar(160) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `nutrition_plan_day_id` bigint(20) unsigned NOT NULL,
  `alternativa_di_id` bigint(20) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `nutrition_plan_meals_nutrition_plan_id_position_index` (`nutrition_plan_id`,`position`),
  KEY `nutrition_plan_meals_nutrition_plan_day_id_foreign` (`nutrition_plan_day_id`),
  KEY `nutrition_plan_meals_alternativa_di_id_index` (`alternativa_di_id`),
  CONSTRAINT `nutrition_plan_meals_alternativa_di_id_foreign` FOREIGN KEY (`alternativa_di_id`) REFERENCES `nutrition_plan_meals` (`id`) ON DELETE CASCADE,
  CONSTRAINT `nutrition_plan_meals_nutrition_plan_day_id_foreign` FOREIGN KEY (`nutrition_plan_day_id`) REFERENCES `nutrition_plan_days` (`id`) ON DELETE CASCADE,
  CONSTRAINT `nutrition_plan_meals_nutrition_plan_id_foreign` FOREIGN KEY (`nutrition_plan_id`) REFERENCES `nutrition_plans` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `nutrition_plans`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `nutrition_plans` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint(20) unsigned NOT NULL,
  `member_id` bigint(20) unsigned DEFAULT NULL,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `name` varchar(160) NOT NULL,
  `tipo` varchar(16) NOT NULL DEFAULT 'piano',
  `rif_allievo` varchar(120) DEFAULT NULL,
  `origine_id` char(26) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `target_kcal` int(10) unsigned DEFAULT NULL,
  `target_protein_g` int(10) unsigned DEFAULT NULL,
  `target_carbs_g` int(10) unsigned DEFAULT NULL,
  `target_fat_g` int(10) unsigned DEFAULT NULL,
  `status` varchar(16) NOT NULL DEFAULT 'draft',
  `starts_at` date DEFAULT NULL,
  `ends_at` date DEFAULT NULL,
  `published_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `nutrition_plans_origine_id_unique` (`origine_id`),
  KEY `nutrition_plans_member_id_foreign` (`member_id`),
  KEY `nutrition_plans_created_by_foreign` (`created_by`),
  KEY `nutrition_plans_tenant_id_member_id_status_index` (`tenant_id`,`member_id`,`status`),
  CONSTRAINT `nutrition_plans_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `nutrition_plans_member_id_foreign` FOREIGN KEY (`member_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `nutrition_plans_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `password_reset_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `password_reset_tokens` (
  `email` varchar(255) NOT NULL,
  `token` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `permissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `permissions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `guard_name` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `permissions_name_guard_name_unique` (`name`,`guard_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `personal_access_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `personal_access_tokens` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tokenable_type` varchar(255) NOT NULL,
  `tokenable_id` bigint(20) unsigned NOT NULL,
  `name` text NOT NULL,
  `token` varchar(64) NOT NULL,
  `abilities` text DEFAULT NULL,
  `last_used_at` timestamp NULL DEFAULT NULL,
  `expires_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `personal_access_tokens_token_unique` (`token`),
  KEY `personal_access_tokens_tokenable_type_tokenable_id_index` (`tokenable_type`,`tokenable_id`),
  KEY `personal_access_tokens_expires_at_index` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `plan_exercises`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `plan_exercises` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `workout_plan_id` bigint(20) unsigned NOT NULL,
  `exercise_id` bigint(20) unsigned NOT NULL,
  `position` smallint(5) unsigned NOT NULL DEFAULT 0,
  `sets` smallint(5) unsigned DEFAULT NULL,
  `reps` varchar(32) DEFAULT NULL,
  `rest_sec` smallint(5) unsigned DEFAULT NULL,
  `target_weight` decimal(6,2) DEFAULT NULL,
  `serie` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`serie`)),
  `duration_sec` smallint(5) unsigned DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `workout_plan_day_id` bigint(20) unsigned NOT NULL,
  `alternativa_di_id` bigint(20) unsigned DEFAULT NULL,
  `carico` varchar(8) NOT NULL DEFAULT 'peso',
  PRIMARY KEY (`id`),
  KEY `plan_exercises_exercise_id_foreign` (`exercise_id`),
  KEY `plan_exercises_workout_plan_id_position_index` (`workout_plan_id`,`position`),
  KEY `plan_exercises_workout_plan_day_id_foreign` (`workout_plan_day_id`),
  KEY `plan_exercises_alternativa_di_id_index` (`alternativa_di_id`),
  CONSTRAINT `plan_exercises_alternativa_di_id_foreign` FOREIGN KEY (`alternativa_di_id`) REFERENCES `plan_exercises` (`id`) ON DELETE CASCADE,
  CONSTRAINT `plan_exercises_exercise_id_foreign` FOREIGN KEY (`exercise_id`) REFERENCES `exercises` (`id`),
  CONSTRAINT `plan_exercises_workout_plan_day_id_foreign` FOREIGN KEY (`workout_plan_day_id`) REFERENCES `workout_plan_days` (`id`) ON DELETE CASCADE,
  CONSTRAINT `plan_exercises_workout_plan_id_foreign` FOREIGN KEY (`workout_plan_id`) REFERENCES `workout_plans` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `plan_subscriptions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `plan_subscriptions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint(20) unsigned NOT NULL,
  `plan_id` bigint(20) unsigned NOT NULL,
  `stripe_subscription_id` varchar(64) DEFAULT NULL,
  `stripe_customer_id` varchar(64) DEFAULT NULL,
  `starts_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `ends_at` timestamp NULL DEFAULT NULL,
  `in_pausa_dal` timestamp NULL DEFAULT NULL,
  `in_pausa_per_tenant_id` bigint(20) unsigned DEFAULT NULL,
  `restavano_giorni` smallint(5) unsigned DEFAULT NULL,
  `pagamento_fallito_il` timestamp NULL DEFAULT NULL,
  `rinnova` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `plan_subscriptions_stripe_subscription_id_unique` (`stripe_subscription_id`),
  KEY `plan_subscriptions_plan_id_foreign` (`plan_id`),
  KEY `plan_subscriptions_tenant_id_ends_at_index` (`tenant_id`,`ends_at`),
  KEY `plan_subscriptions_stripe_customer_id_index` (`stripe_customer_id`),
  KEY `plan_subscriptions_in_pausa_per_tenant_id_foreign` (`in_pausa_per_tenant_id`),
  KEY `ps_in_pausa` (`in_pausa_dal`,`in_pausa_per_tenant_id`),
  CONSTRAINT `plan_subscriptions_in_pausa_per_tenant_id_foreign` FOREIGN KEY (`in_pausa_per_tenant_id`) REFERENCES `tenants` (`id`) ON DELETE SET NULL,
  CONSTRAINT `plan_subscriptions_plan_id_foreign` FOREIGN KEY (`plan_id`) REFERENCES `plans` (`id`),
  CONSTRAINT `plan_subscriptions_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `plans`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `plans` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(40) NOT NULL,
  `name` varchar(80) NOT NULL,
  `kind` varchar(16) NOT NULL DEFAULT 'person',
  `ai_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `ai_monthly_calls_per_member` int(10) unsigned DEFAULT NULL,
  `ai_monthly_photo_calls_per_member` int(10) unsigned DEFAULT NULL,
  `max_members` int(10) unsigned DEFAULT NULL,
  `max_trainers` int(10) unsigned DEFAULT NULL,
  `price_cents` int(10) unsigned NOT NULL DEFAULT 0,
  `prezzo_iscritto_cent` int(10) unsigned DEFAULT NULL,
  `minimo_iscritti` int(10) unsigned DEFAULT NULL,
  `is_public` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `plans_code_unique` (`code`),
  KEY `plans_kind_is_public_index` (`kind`,`is_public`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `profili_pubblici`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `profili_pubblici` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint(20) unsigned DEFAULT NULL,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `comune_id` bigint(20) unsigned NOT NULL,
  `titolo` varchar(120) NOT NULL,
  `descrizione` text DEFAULT NULL,
  `visibile` tinyint(1) NOT NULL DEFAULT 0,
  `campagna_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `profili_pubblici_tenant_id_unique` (`tenant_id`),
  UNIQUE KEY `profili_pubblici_user_id_unique` (`user_id`),
  KEY `profili_pubblici_comune_id_foreign` (`comune_id`),
  KEY `profili_pubblici_visibile_comune_id_index` (`visibile`,`comune_id`),
  KEY `profili_pubblici_campagna_id_foreign` (`campagna_id`),
  CONSTRAINT `profili_pubblici_campagna_id_foreign` FOREIGN KEY (`campagna_id`) REFERENCES `campagne` (`id`) ON DELETE SET NULL,
  CONSTRAINT `profili_pubblici_comune_id_foreign` FOREIGN KEY (`comune_id`) REFERENCES `comuni` (`id`),
  CONSTRAINT `profili_pubblici_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `profili_pubblici_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `chk_profili_pubblici_xor` CHECK (`tenant_id` is null <> (`user_id` is null))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `recovery_keys`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `recovery_keys` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `version` tinyint(3) unsigned NOT NULL,
  `kdf` varchar(32) NOT NULL,
  `ops_limit` int(10) unsigned NOT NULL,
  `mem_limit` bigint(20) unsigned NOT NULL,
  `salt` varchar(64) NOT NULL,
  `nonce` varchar(64) NOT NULL,
  `wrapped_key` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `recovery_keys_user_id_unique` (`user_id`),
  CONSTRAINT `recovery_keys_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `role_has_permissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `role_has_permissions` (
  `permission_id` bigint(20) unsigned NOT NULL,
  `role_id` bigint(20) unsigned NOT NULL,
  PRIMARY KEY (`permission_id`,`role_id`),
  KEY `role_has_permissions_role_id_foreign` (`role_id`),
  CONSTRAINT `role_has_permissions_permission_id_foreign` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `role_has_permissions_role_id_foreign` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `roles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `roles` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint(20) unsigned DEFAULT NULL,
  `name` varchar(255) NOT NULL,
  `guard_name` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `roles_tenant_id_name_guard_name_unique` (`tenant_id`,`name`,`guard_name`),
  KEY `roles_team_foreign_key_index` (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `sessions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `sessions` (
  `id` varchar(255) NOT NULL,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `payload` longtext NOT NULL,
  `last_activity` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `sessions_user_id_index` (`user_id`),
  KEY `sessions_last_activity_index` (`last_activity`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `social_identities`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `social_identities` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `provider` varchar(16) NOT NULL,
  `provider_user_id` varchar(191) NOT NULL,
  `email` varchar(255) DEFAULT NULL,
  `name` varchar(255) DEFAULT NULL,
  `last_login_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `social_identities_provider_provider_user_id_unique` (`provider`,`provider_user_id`),
  UNIQUE KEY `social_identities_user_id_provider_unique` (`user_id`,`provider`),
  CONSTRAINT `social_identities_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `stime_cibo`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `stime_cibo` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint(20) unsigned NOT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `stato` varchar(20) NOT NULL,
  `origine` varchar(10) NOT NULL,
  `pasto` varchar(20) DEFAULT NULL,
  `richiesta` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`richiesta`)),
  `risultato` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`risultato`)),
  `errore` varchar(60) DEFAULT NULL,
  `paga_con_gettoni` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `stime_cibo_tenant_id_foreign` (`tenant_id`),
  KEY `stime_cibo_user_id_stato_index` (`user_id`,`stato`),
  CONSTRAINT `stime_cibo_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `stime_cibo_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `stripe_events`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `stripe_events` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `event_id` varchar(255) NOT NULL,
  `type` varchar(255) NOT NULL,
  `payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`payload`)),
  `processed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `stripe_events_event_id_unique` (`event_id`),
  KEY `stripe_events_type_index` (`type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `tenant_invites`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `tenant_invites` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint(20) unsigned NOT NULL,
  `invitato_da` bigint(20) unsigned DEFAULT NULL,
  `ruolo` varchar(30) NOT NULL DEFAULT 'member',
  `token` varchar(64) NOT NULL,
  `email` varchar(255) DEFAULT NULL,
  `expires_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `used_at` timestamp NULL DEFAULT NULL,
  `revoked_at` timestamp NULL DEFAULT NULL,
  `rifiutato_il` timestamp NULL DEFAULT NULL,
  `accepted_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `tenant_invites_token_unique` (`token`),
  KEY `tenant_invites_invitato_da_foreign` (`invitato_da`),
  KEY `tenant_invites_accepted_by_foreign` (`accepted_by`),
  KEY `tenant_invites_tenant_id_used_at_index` (`tenant_id`,`used_at`),
  CONSTRAINT `tenant_invites_accepted_by_foreign` FOREIGN KEY (`accepted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `tenant_invites_invitato_da_foreign` FOREIGN KEY (`invitato_da`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `tenant_invites_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `tenants`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `tenants` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(120) NOT NULL,
  `slug` varchar(60) NOT NULL,
  `kind` varchar(16) NOT NULL DEFAULT 'gym',
  `proprietario_id` bigint(20) unsigned DEFAULT NULL,
  `join_code` varchar(12) NOT NULL,
  `status` varchar(20) NOT NULL DEFAULT 'trial',
  `plan` varchar(20) NOT NULL DEFAULT 'starter',
  `logo_path` varchar(255) DEFAULT NULL,
  `color_primary` varchar(7) NOT NULL DEFAULT '#111827',
  `color_secondary` varchar(7) NOT NULL DEFAULT '#6B7280',
  `color_accent` varchar(7) NOT NULL DEFAULT '#F59E0B',
  `contact_email` varchar(255) DEFAULT NULL,
  `locale` varchar(5) NOT NULL DEFAULT 'it',
  `timezone` varchar(64) NOT NULL DEFAULT 'Europe/Rome',
  `ai_monthly_calls_per_member` int(10) unsigned DEFAULT NULL,
  `ai_monthly_photo_calls_per_member` int(10) unsigned DEFAULT NULL,
  `ai_driver` varchar(20) DEFAULT NULL,
  `settings` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`settings`)),
  `trial_ends_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `tenants_slug_unique` (`slug`),
  UNIQUE KEY `tenants_join_code_unique` (`join_code`),
  KEY `tenants_status_index` (`status`),
  KEY `tenants_kind_index` (`kind`),
  KEY `tenants_proprietario_id_foreign` (`proprietario_id`),
  CONSTRAINT `tenants_proprietario_id_foreign` FOREIGN KEY (`proprietario_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `trainer_invites`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `trainer_invites` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint(20) unsigned NOT NULL,
  `trainer_id` bigint(20) unsigned NOT NULL,
  `token` varchar(64) NOT NULL,
  `email` varchar(255) DEFAULT NULL,
  `expires_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `used_at` timestamp NULL DEFAULT NULL,
  `revoked_at` timestamp NULL DEFAULT NULL,
  `rifiutato_il` timestamp NULL DEFAULT NULL,
  `accepted_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `trainer_invites_token_unique` (`token`),
  KEY `trainer_invites_tenant_id_foreign` (`tenant_id`),
  KEY `trainer_invites_accepted_by_foreign` (`accepted_by`),
  KEY `trainer_invites_trainer_id_used_at_index` (`trainer_id`,`used_at`),
  CONSTRAINT `trainer_invites_accepted_by_foreign` FOREIGN KEY (`accepted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `trainer_invites_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `trainer_invites_trainer_id_foreign` FOREIGN KEY (`trainer_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `trainer_member`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `trainer_member` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint(20) unsigned NOT NULL,
  `trainer_id` bigint(20) unsigned NOT NULL,
  `member_id` bigint(20) unsigned NOT NULL,
  `assigned_at` timestamp NULL DEFAULT NULL,
  `assigned_by` bigint(20) unsigned DEFAULT NULL,
  `disattivato_il` timestamp NULL DEFAULT NULL,
  `sovrapposto_dal` timestamp NULL DEFAULT NULL,
  `sovrapposto_in_tenant_id` bigint(20) unsigned DEFAULT NULL,
  `slot_deciso_il` timestamp NULL DEFAULT NULL,
  `quota_assegnata_il` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `trainer_member_trainer_id_member_id_unique` (`trainer_id`,`member_id`),
  KEY `trainer_member_member_id_foreign` (`member_id`),
  KEY `trainer_member_assigned_by_foreign` (`assigned_by`),
  KEY `trainer_member_tenant_id_trainer_id_index` (`tenant_id`,`trainer_id`),
  KEY `trainer_member_tenant_id_member_id_index` (`tenant_id`,`member_id`),
  KEY `trainer_member_sovrapposto_in_tenant_id_foreign` (`sovrapposto_in_tenant_id`),
  KEY `tm_sovrapposizioni_aperte` (`trainer_id`,`sovrapposto_dal`,`slot_deciso_il`),
  CONSTRAINT `trainer_member_assigned_by_foreign` FOREIGN KEY (`assigned_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `trainer_member_member_id_foreign` FOREIGN KEY (`member_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `trainer_member_sovrapposto_in_tenant_id_foreign` FOREIGN KEY (`sovrapposto_in_tenant_id`) REFERENCES `tenants` (`id`) ON DELETE SET NULL,
  CONSTRAINT `trainer_member_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `trainer_member_trainer_id_foreign` FOREIGN KEY (`trainer_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `users` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint(20) unsigned DEFAULT NULL,
  `librerie_viste` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`librerie_viste`)),
  `name` varchar(255) NOT NULL,
  `email` varchar(255) NOT NULL,
  `username` varchar(30) DEFAULT NULL,
  `phone` varchar(32) DEFAULT NULL,
  `avatar_path` varchar(255) DEFAULT NULL,
  `locale` varchar(5) NOT NULL DEFAULT 'it',
  `timezone` varchar(64) DEFAULT NULL,
  `comune_id` bigint(20) unsigned DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `ai_monthly_call_cap` int(10) unsigned DEFAULT NULL,
  `ai_monthly_photo_call_cap` int(10) unsigned DEFAULT NULL,
  `ai_credits` bigint(20) unsigned NOT NULL DEFAULT 0,
  `ai_enabled_override` tinyint(1) DEFAULT NULL,
  `is_super_admin` tinyint(1) NOT NULL DEFAULT 0,
  `last_login_at` timestamp NULL DEFAULT NULL,
  `workouts_migrated_at` timestamp NULL DEFAULT NULL,
  `email_verified_at` timestamp NULL DEFAULT NULL,
  `age_confirmed_at` timestamp NULL DEFAULT NULL,
  `terms_accepted_at` timestamp NULL DEFAULT NULL,
  `affiancamento_accettato_il` timestamp NULL DEFAULT NULL,
  `health_consent_at` timestamp NULL DEFAULT NULL,
  `ai_consent_at` timestamp NULL DEFAULT NULL,
  `ai_disclaimer_at` timestamp NULL DEFAULT NULL,
  `sleep_ai_consent_at` timestamp NULL DEFAULT NULL,
  `password` varchar(255) NOT NULL,
  `password_is_set` tinyint(1) NOT NULL DEFAULT 1,
  `remember_token` varchar(100) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `consiglio_automatico` tinyint(1) NOT NULL DEFAULT 1,
  `albo` varchar(32) DEFAULT NULL,
  `albo_numero` varchar(64) DEFAULT NULL,
  `albo_dichiarato_il` timestamp NULL DEFAULT NULL,
  `consensi_chiesti_il` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `users_tenant_id_email_unique` (`tenant_id`,`email`),
  UNIQUE KEY `users_username_unique` (`username`),
  KEY `users_tenant_id_is_active_index` (`tenant_id`,`is_active`),
  KEY `users_is_super_admin_index` (`is_super_admin`),
  KEY `users_comune_id_foreign` (`comune_id`),
  KEY `users_workouts_migrated_at_index` (`workouts_migrated_at`),
  CONSTRAINT `users_comune_id_foreign` FOREIGN KEY (`comune_id`) REFERENCES `comuni` (`id`) ON DELETE SET NULL,
  CONSTRAINT `users_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `visualizzazioni`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `visualizzazioni` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `campagna_id` bigint(20) unsigned NOT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `giorno` date NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `visualizzazioni_campagna_id_giorno_user_id_unique` (`campagna_id`,`giorno`,`user_id`),
  KEY `visualizzazioni_user_id_foreign` (`user_id`),
  KEY `visualizzazioni_campagna_id_giorno_index` (`campagna_id`,`giorno`),
  CONSTRAINT `visualizzazioni_campagna_id_foreign` FOREIGN KEY (`campagna_id`) REFERENCES `campagne` (`id`) ON DELETE CASCADE,
  CONSTRAINT `visualizzazioni_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `workout_plan_days`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `workout_plan_days` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `workout_plan_id` bigint(20) unsigned NOT NULL,
  `alternativa_di_id` bigint(20) unsigned DEFAULT NULL,
  `position` smallint(5) unsigned NOT NULL DEFAULT 0,
  `name` varchar(120) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `workout_plan_days_workout_plan_id_position_index` (`workout_plan_id`,`position`),
  KEY `workout_plan_days_alternativa_di_id_index` (`alternativa_di_id`),
  CONSTRAINT `workout_plan_days_alternativa_di_id_foreign` FOREIGN KEY (`alternativa_di_id`) REFERENCES `workout_plan_days` (`id`) ON DELETE CASCADE,
  CONSTRAINT `workout_plan_days_workout_plan_id_foreign` FOREIGN KEY (`workout_plan_id`) REFERENCES `workout_plans` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `workout_plan_imports`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `workout_plan_imports` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint(20) unsigned NOT NULL,
  `uploaded_by` bigint(20) unsigned DEFAULT NULL,
  `member_id` bigint(20) unsigned DEFAULT NULL,
  `media_id` bigint(20) unsigned DEFAULT NULL,
  `status` varchar(16) NOT NULL DEFAULT 'queued',
  `parsed_payload` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`parsed_payload`)),
  `model_used` varchar(64) DEFAULT NULL,
  `confidence` decimal(4,3) DEFAULT NULL,
  `escalated` tinyint(1) NOT NULL DEFAULT 0,
  `paga_con_gettoni` tinyint(1) NOT NULL DEFAULT 0,
  `error` text DEFAULT NULL,
  `workout_plan_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `workout_plan_imports_uploaded_by_foreign` (`uploaded_by`),
  KEY `workout_plan_imports_member_id_foreign` (`member_id`),
  KEY `workout_plan_imports_workout_plan_id_foreign` (`workout_plan_id`),
  KEY `workout_plan_imports_tenant_id_status_index` (`tenant_id`,`status`),
  CONSTRAINT `workout_plan_imports_member_id_foreign` FOREIGN KEY (`member_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `workout_plan_imports_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `workout_plan_imports_uploaded_by_foreign` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `workout_plan_imports_workout_plan_id_foreign` FOREIGN KEY (`workout_plan_id`) REFERENCES `workout_plans` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `workout_plans`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `workout_plans` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint(20) unsigned NOT NULL,
  `member_id` bigint(20) unsigned DEFAULT NULL,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `name` varchar(160) NOT NULL,
  `rif_allievo` varchar(120) DEFAULT NULL,
  `origine_id` char(26) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `status` varchar(16) NOT NULL DEFAULT 'draft',
  `source` varchar(16) NOT NULL DEFAULT 'manual',
  `starts_at` date DEFAULT NULL,
  `ends_at` date DEFAULT NULL,
  `published_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `workout_plans_origine_id_unique` (`origine_id`),
  KEY `workout_plans_member_id_foreign` (`member_id`),
  KEY `workout_plans_created_by_foreign` (`created_by`),
  KEY `workout_plans_tenant_id_member_id_status_index` (`tenant_id`,`member_id`,`status`),
  KEY `workout_plans_tenant_id_status_index` (`tenant_id`,`status`),
  CONSTRAINT `workout_plans_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `workout_plans_member_id_foreign` FOREIGN KEY (`member_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `workout_plans_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (1,'0001_01_01_000000_create_users_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (2,'0001_01_01_000001_create_cache_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (3,'0001_01_01_000002_create_jobs_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (4,'2026_08_08_180000_create_tenants_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (5,'2026_08_09_095336_create_permission_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (6,'2026_08_09_100000_add_tenant_fields_to_users_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (7,'2026_08_09_100100_create_profiles_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (8,'2026_08_09_100200_create_trainer_member_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (9,'2026_08_09_101000_add_is_super_admin_to_users_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (10,'2026_08_09_101417_create_personal_access_tokens_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (11,'2026_08_09_120000_add_username_to_users_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (12,'2026_08_09_123616_create_media_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (13,'2026_08_10_090000_create_audit_logs_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (14,'2026_08_10_091000_add_tenant_id_to_media_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (15,'2026_08_10_100000_create_exercises_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (16,'2026_08_10_100100_create_workout_plans_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (17,'2026_08_10_100200_create_workout_sessions_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (18,'2026_08_10_110000_create_food_entries_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (19,'2026_08_10_110100_create_nutrition_plans_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (20,'2026_08_10_120000_create_ai_usage_logs_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (21,'2026_08_10_120000_create_health_readings_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (22,'2026_08_10_120100_create_ai_advices_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (23,'2026_08_10_130000_create_workout_plan_imports_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (24,'2026_08_10_140000_create_conversations_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (25,'2026_08_10_150000_create_health_samples_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (26,'2026_08_10_170000_create_social_identities_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (27,'2026_08_10_190000_move_ai_quota_from_tenant_to_member',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (28,'2026_08_10_210000_add_password_is_set_to_users_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (29,'2026_08_12_090000_drop_health_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (30,'2026_08_12_100000_drop_body_metrics_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (31,'2026_08_12_230000_porta_gli_obiettivi_a_cinque',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (32,'2026_08_13_010000_registra_i_token_di_creazione_della_cache',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (33,'2026_08_13_090000_create_chat_crypto_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (34,'2026_08_13_120000_add_consents_to_users_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (35,'2026_08_13_150000_add_timezone_to_users_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (36,'2026_08_13_200000_aggiungi_il_tipo_al_tenant',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (37,'2026_08_13_210000_crea_i_piani_e_gli_abbonamenti',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (38,'2026_08_13_220000_crea_gli_inviti_personali',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (39,'2026_08_13_230000_disattivare_un_utente_chiude_i_messaggi',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (40,'2026_08_14_100000_il_listino_a_livelli',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (41,'2026_08_14_110000_la_quota_in_chiamate',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (42,'2026_08_14_120000_i_giorni_e_le_alternative_delle_schede',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (43,'2026_08_14_130000_i_giorni_e_le_alternative_dei_piani',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (44,'2026_08_14_140000_rif_allievo_e_origine_id',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (45,'2026_08_14_150000_via_le_colonne_che_nessuno_legge_piu',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (46,'2026_08_15_100000_l_ai_accesa_per_una_persona_sola',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (47,'2026_08_16_100000_il_sonno_nel_consiglio_del_giorno',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (48,'2026_08_17_090000_il_registro_degli_eventi_di_stripe',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (49,'2026_08_17_120000_il_catalogo_degli_alimenti',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (50,'2026_08_18_100000_i_comuni_e_la_citta_del_profilo',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (51,'2026_08_18_110000_il_catalogo_pubblico',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (52,'2026_08_18_120000_le_conversazioni_di_informazioni',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (53,'2026_08_18_130000_le_campagne_pubblicitarie',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (54,'2026_08_19_100000_gli_allegati_cifrati_della_chat',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (55,'2026_08_19_140000_i_consigli_alimentari',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (56,'2026_08_19_160000_lalbo_del_nutrizionista',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (57,'2026_08_19_180000_limportazione_dei_piani_alimentari',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (58,'2026_08_19_200000_i_messaggi_usa_e_getta',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (59,'2026_08_19_220000_quando_i_consensi_sono_stati_chiesti',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (60,'2026_08_21_090000_create_stime_cibo_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (61,'2026_08_21_210000_quando_gli_allenamenti_sono_traslocati',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (62,'2026_08_23_170000_gli_allenamenti_lasciano_il_server',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (63,'2026_08_23_210000_i_muscoli_secondari_degli_esercizi',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (64,'2026_08_25_220000_le_serie_riga_per_riga',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (65,'2026_08_26_120000_il_piano_plus_dice_quante_chiamate',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (66,'2026_08_27_110000_gli_abbonamenti_ricordano_stripe',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (67,'2026_08_27_200000_chi_accende_lai_sa_cosa_non_e',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (68,'2026_08_28_120000_le_librerie_che_una_persona_ha_visto',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (69,'2026_08_28_170000_un_esercizio_puo_essere_sostituito_da_un_altro',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (70,'2026_08_29_090000_limport_di_una_scheda_si_paga_a_gettoni',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (71,'2026_08_29_100000_quando_il_trainer_ha_dato_la_quota',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (72,'2026_08_29_150000_gli_inviti_della_palestra',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (73,'2026_08_30_120000_il_consiglio_va_a_fasce',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (74,'2026_09_03_120000_il_consiglio_non_si_conserva_qui',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (75,'2026_09_03_140000_si_importa_anche_da_fotografia',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (76,'2026_09_03_150000_non_si_importano_solo_piani',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (77,'2026_09_03_160000_il_consenso_a_mandare_questo_documento',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (78,'2026_09_12_090000_il_proprietario_del_tenant',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (79,'2026_09_12_100000_gli_allievi_entrano_nello_spazio_del_trainer',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (80,'2026_09_12_110000_il_ruolo_sull_invito_e_i_posti_trainer',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (81,'2026_09_13_090000_i_piani_a_due_tetti_e_il_consumo',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (82,'2026_09_16_120000_i_gettoni_sono_della_persona',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (83,'2026_09_17_100000_chi_ha_gia_un_trainer',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (84,'2026_09_17_140000_l_addebito_del_mese',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (85,'2026_09_18_100000_la_sovrapposizione_con_la_palestra',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (86,'2026_09_18_101000_l_abbonamento_si_mette_in_pausa',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (87,'2026_09_18_120000_ogni_trainer_ha_una_casa',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (88,'2026_09_18_130000_il_pagamento_non_riuscito',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (89,'2026_09_19_150000_il_profilo_non_ha_piu_i_dati_del_corpo',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (90,'2026_09_19_180000_via_la_tabella_dei_profili',1);

-- ⚡ Righe inserite dalle migrazioni (bin/schema-dei-test.sh)
INSERT INTO `plans` VALUES (1,'free','Gratuito','person',0,NULL,NULL,NULL,NULL,0,NULL,NULL,1,'2026-09-19 16:23:11','2026-09-19 16:23:11'),(2,'plus','Plus','person',1,150,15,NULL,NULL,499,NULL,NULL,1,'2026-09-19 16:23:11','2026-09-19 16:24:16'),(3,'trainer_free','Trainer — gratuito','trainer',0,NULL,NULL,NULL,NULL,0,NULL,NULL,1,'2026-09-19 16:23:11','2026-09-19 16:23:11'),(4,'trainer_pro','Trainer — pro','trainer',1,NULL,NULL,NULL,NULL,1999,NULL,NULL,1,'2026-09-19 16:23:11','2026-09-19 16:23:11'),(5,'gym','Palestra','gym',1,NULL,NULL,NULL,NULL,4999,NULL,NULL,1,'2026-09-19 16:23:11','2026-09-19 16:23:11');
