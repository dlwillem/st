/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `applicatiesoorten` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `label` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `sort_order` int NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_app_code` (`code`)
) ENGINE=InnoDB AUTO_INCREMENT=30 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `audit_log` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned DEFAULT NULL,
  `user_name` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `action` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `entity_type` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `entity_id` int unsigned DEFAULT NULL,
  `detail` text COLLATE utf8mb4_unicode_ci,
  `ip_address` varchar(45) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_audit_user` (`user_id`),
  KEY `idx_audit_entity` (`entity_type`,`entity_id`),
  KEY `idx_audit_created` (`created_at`),
  CONSTRAINT `fk_audit_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=375 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `categorieen` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `type` enum('functional','non_functional','other') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'other',
  `sort_order` int NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_categorieen_code` (`code`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `demo_open_scores` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `ronde_id` int unsigned NOT NULL,
  `question_id` int unsigned NOT NULL,
  `deelnemer_id` int unsigned NOT NULL,
  `answer_text` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_dos_unique` (`ronde_id`,`question_id`,`deelnemer_id`),
  KEY `idx_dos_ronde` (`ronde_id`),
  KEY `fk_dos_question` (`question_id`),
  KEY `fk_dos_deeln` (`deelnemer_id`),
  CONSTRAINT `fk_dos_deeln` FOREIGN KEY (`deelnemer_id`) REFERENCES `scoring_deelnemers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_dos_question` FOREIGN KEY (`question_id`) REFERENCES `traject_demo_questions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_dos_ronde` FOREIGN KEY (`ronde_id`) REFERENCES `scoring_rondes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `demo_question_catalog` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `block` tinyint unsigned NOT NULL,
  `sort_order` int NOT NULL DEFAULT '0',
  `text` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_dqc_block` (`block`,`sort_order`)
) ENGINE=InnoDB AUTO_INCREMENT=20 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `demo_scores` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `ronde_id` int unsigned NOT NULL,
  `question_id` int unsigned NOT NULL,
  `deelnemer_id` int unsigned NOT NULL,
  `score` tinyint unsigned NOT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ds_unique` (`ronde_id`,`question_id`,`deelnemer_id`),
  KEY `idx_ds_ronde` (`ronde_id`),
  KEY `idx_ds_question` (`question_id`),
  KEY `fk_ds_deeln` (`deelnemer_id`),
  CONSTRAINT `fk_ds_deeln` FOREIGN KEY (`deelnemer_id`) REFERENCES `scoring_deelnemers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ds_question` FOREIGN KEY (`question_id`) REFERENCES `traject_demo_questions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ds_ronde` FOREIGN KEY (`ronde_id`) REFERENCES `scoring_rondes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=31 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `leverancier_answers` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `traject_id` int unsigned NOT NULL,
  `leverancier_id` int unsigned NOT NULL,
  `requirement_id` int unsigned NOT NULL,
  `answer_choice` enum('volledig','deels','niet','nvt') COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `answer_text` text COLLATE utf8mb4_unicode_ci,
  `evidence_url` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `imported_at` datetime DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_la` (`leverancier_id`,`requirement_id`),
  KEY `idx_la_traject` (`traject_id`),
  KEY `idx_la_req` (`requirement_id`),
  CONSTRAINT `fk_la_leverancier` FOREIGN KEY (`leverancier_id`) REFERENCES `leveranciers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_la_req` FOREIGN KEY (`requirement_id`) REFERENCES `requirements` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_la_traject` FOREIGN KEY (`traject_id`) REFERENCES `trajecten` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=423 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `leverancier_uploads` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `traject_id` int unsigned NOT NULL,
  `leverancier_id` int unsigned NOT NULL,
  `original_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `stored_path` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL,
  `uploaded_by` int unsigned DEFAULT NULL,
  `uploaded_at` datetime NOT NULL,
  `rows_total` int unsigned NOT NULL DEFAULT '0',
  `rows_auto` int unsigned NOT NULL DEFAULT '0',
  `rows_manual` int unsigned NOT NULL DEFAULT '0',
  `rows_ko_fail` int unsigned NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_lev_upload` (`leverancier_id`),
  KEY `idx_lev_upload_trj` (`traject_id`),
  KEY `fk_lu_usr` (`uploaded_by`),
  CONSTRAINT `fk_lu_lev` FOREIGN KEY (`leverancier_id`) REFERENCES `leveranciers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_lu_trj` FOREIGN KEY (`traject_id`) REFERENCES `trajecten` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_lu_usr` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `leveranciers` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `traject_id` int unsigned NOT NULL,
  `name` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `contact_name` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `contact_email` varchar(190) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `website` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `notes` text COLLATE utf8mb4_unicode_ci,
  `status` enum('actief','onder_review','afgewezen') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'actief',
  `ko_failed_reason` text COLLATE utf8mb4_unicode_ci,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_lev_traject` (`traject_id`),
  CONSTRAINT `fk_lev_traject` FOREIGN KEY (`traject_id`) REFERENCES `trajecten` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=13 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `login_attempts` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `email` varchar(190) COLLATE utf8mb4_unicode_ci NOT NULL,
  `ip_address` varchar(45) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `success` tinyint(1) NOT NULL DEFAULT '0',
  `attempted_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_la_email` (`email`,`attempted_at`),
  KEY `idx_la_ip` (`ip_address`,`attempted_at`)
) ENGINE=InnoDB AUTO_INCREMENT=15 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `password_resets` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned NOT NULL,
  `token_hash` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `expires_at` datetime NOT NULL,
  `used_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_pr_token` (`token_hash`),
  KEY `idx_pr_user` (`user_id`),
  CONSTRAINT `fk_pr_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `quotes` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `tekst` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `auteur` varchar(190) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_quotes_tekst` (`tekst`(255))
) ENGINE=InnoDB AUTO_INCREMENT=51 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `requirements` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `traject_id` int unsigned NOT NULL,
  `subcategorie_id` int unsigned NOT NULL,
  `code` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `title` varchar(300) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `type` enum('eis','wens','ko') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'eis',
  `sort_order` int NOT NULL DEFAULT '0',
  `created_at` datetime NOT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_req_traject` (`traject_id`),
  KEY `idx_req_sub` (`subcategorie_id`),
  KEY `idx_req_code` (`traject_id`,`code`),
  CONSTRAINT `fk_req_sub` FOREIGN KEY (`subcategorie_id`) REFERENCES `subcategorieen` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_req_traject` FOREIGN KEY (`traject_id`) REFERENCES `trajecten` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=114 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `scores` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `ronde_id` int unsigned NOT NULL,
  `leverancier_id` int unsigned NOT NULL,
  `requirement_id` int unsigned NOT NULL,
  `deelnemer_id` int unsigned DEFAULT NULL,
  `score` tinyint unsigned NOT NULL,
  `notes` text COLLATE utf8mb4_unicode_ci,
  `source` enum('manual','auto') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'manual',
  `created_at` datetime NOT NULL,
  `updated_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_scores_unique` (`ronde_id`,`leverancier_id`,`requirement_id`,`deelnemer_id`),
  KEY `idx_scores_ronde` (`ronde_id`),
  KEY `idx_scores_lev` (`leverancier_id`),
  KEY `fk_sc_req` (`requirement_id`),
  KEY `fk_sc_deeln` (`deelnemer_id`),
  CONSTRAINT `fk_sc_deeln` FOREIGN KEY (`deelnemer_id`) REFERENCES `scoring_deelnemers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_sc_lev` FOREIGN KEY (`leverancier_id`) REFERENCES `leveranciers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_sc_req` FOREIGN KEY (`requirement_id`) REFERENCES `requirements` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_sc_ronde` FOREIGN KEY (`ronde_id`) REFERENCES `scoring_rondes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=347 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `scoring_deelnemers` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `ronde_id` int unsigned NOT NULL,
  `leverancier_id` int unsigned NOT NULL,
  `traject_deelnemer_id` int unsigned DEFAULT NULL,
  `name` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(190) COLLATE utf8mb4_unicode_ci NOT NULL,
  `token` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `token_expires` datetime NOT NULL,
  `invited_at` datetime NOT NULL,
  `completed_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_deeln_token` (`token`),
  KEY `idx_deeln_ronde` (`ronde_id`),
  KEY `fk_deeln_lev` (`leverancier_id`),
  KEY `idx_sd_td` (`traject_deelnemer_id`),
  CONSTRAINT `fk_deeln_lev` FOREIGN KEY (`leverancier_id`) REFERENCES `leveranciers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_deeln_ronde` FOREIGN KEY (`ronde_id`) REFERENCES `scoring_rondes` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_sd_td` FOREIGN KEY (`traject_deelnemer_id`) REFERENCES `traject_deelnemers` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=31 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `scoring_rondes` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `traject_id` int unsigned NOT NULL,
  `leverancier_id` int unsigned NOT NULL,
  `scope` enum('FUNC','NFR','VEND','LIC','SUP','DEMO') COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `start_date` date DEFAULT NULL,
  `end_date` date DEFAULT NULL,
  `status` enum('concept','open','gesloten') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'concept',
  `closed_at` datetime DEFAULT NULL,
  `closed_by` int unsigned DEFAULT NULL,
  `created_by` int unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_ronde_tls` (`traject_id`,`leverancier_id`,`scope`),
  KEY `idx_ronde_traject` (`traject_id`),
  KEY `fk_ronde_closer` (`closed_by`),
  KEY `fk_ronde_creator` (`created_by`),
  KEY `idx_ronde_leverancier` (`leverancier_id`),
  CONSTRAINT `fk_ronde_closer` FOREIGN KEY (`closed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_ronde_creator` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_ronde_leverancier` FOREIGN KEY (`leverancier_id`) REFERENCES `leveranciers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_ronde_traject` FOREIGN KEY (`traject_id`) REFERENCES `trajecten` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=11 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `settings` (
  `key` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `value` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `subcategorie_templates` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `categorie_id` int unsigned NOT NULL,
  `applicatiesoort_id` int unsigned DEFAULT NULL,
  `name` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `sort_order` int NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `idx_subtpl_cat` (`categorie_id`),
  KEY `idx_subtpl_app` (`applicatiesoort_id`),
  CONSTRAINT `fk_subtpl_app` FOREIGN KEY (`applicatiesoort_id`) REFERENCES `applicatiesoorten` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_subtpl_cat` FOREIGN KEY (`categorie_id`) REFERENCES `categorieen` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=140 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `subcategorieen` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `categorie_id` int unsigned NOT NULL,
  `traject_id` int unsigned NOT NULL,
  `applicatiesoort_id` int unsigned DEFAULT NULL,
  `name` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `sort_order` int NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  KEY `idx_sub_cat` (`categorie_id`),
  KEY `idx_sub_traject` (`traject_id`),
  KEY `idx_sub_app` (`applicatiesoort_id`),
  CONSTRAINT `fk_sub_app` FOREIGN KEY (`applicatiesoort_id`) REFERENCES `applicatiesoorten` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_sub_cat` FOREIGN KEY (`categorie_id`) REFERENCES `categorieen` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_sub_traject` FOREIGN KEY (`traject_id`) REFERENCES `trajecten` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=190 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `traject_deelnemer_scopes` (
  `traject_deelnemer_id` int unsigned NOT NULL,
  `scope` enum('FUNC','NFR','VEND','LIC','SUP') COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`traject_deelnemer_id`,`scope`),
  CONSTRAINT `fk_tds_td` FOREIGN KEY (`traject_deelnemer_id`) REFERENCES `traject_deelnemers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `traject_deelnemers` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `traject_id` int unsigned NOT NULL,
  `user_id` int unsigned DEFAULT NULL,
  `name` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(190) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_td_traject_email` (`traject_id`,`email`),
  KEY `idx_td_traject` (`traject_id`),
  KEY `idx_td_user` (`user_id`),
  CONSTRAINT `fk_td_traject` FOREIGN KEY (`traject_id`) REFERENCES `trajecten` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_td_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=16 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `traject_demo_questions` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `traject_id` int unsigned NOT NULL,
  `block` tinyint unsigned NOT NULL,
  `sort_order` int NOT NULL DEFAULT '0',
  `text` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `active` tinyint(1) NOT NULL DEFAULT '1',
  `source_catalog_id` int unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_tdq_traject` (`traject_id`,`block`,`sort_order`),
  KEY `idx_tdq_source` (`traject_id`,`source_catalog_id`),
  CONSTRAINT `fk_tdq_traject` FOREIGN KEY (`traject_id`) REFERENCES `trajecten` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=115 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `trajecten` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `status` enum('concept','actief','afgerond','gearchiveerd') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'concept',
  `start_date` date DEFAULT NULL,
  `end_date` date DEFAULT NULL,
  `created_by` int unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime DEFAULT NULL,
  `demo_weight_pct` decimal(5,2) NOT NULL DEFAULT '20.00',
  PRIMARY KEY (`id`),
  KEY `idx_trajecten_status` (`status`),
  KEY `fk_trajecten_user` (`created_by`),
  CONSTRAINT `fk_trajecten_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `users` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(190) COLLATE utf8mb4_unicode_ci NOT NULL,
  `password_hash` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `role` enum('architect','business_owner','business_analist','key_user') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'business_analist',
  `active` tinyint(1) NOT NULL DEFAULT '1',
  `last_login` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_users_email` (`email`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `weights` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `traject_id` int unsigned NOT NULL,
  `categorie_id` int unsigned DEFAULT NULL,
  `subcategorie_id` int unsigned DEFAULT NULL,
  `weight` decimal(7,3) NOT NULL DEFAULT '0.000',
  PRIMARY KEY (`id`),
  KEY `idx_w_traject` (`traject_id`),
  KEY `fk_w_cat` (`categorie_id`),
  KEY `fk_w_sub` (`subcategorie_id`),
  CONSTRAINT `fk_w_cat` FOREIGN KEY (`categorie_id`) REFERENCES `categorieen` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_w_sub` FOREIGN KEY (`subcategorie_id`) REFERENCES `subcategorieen` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_w_traject` FOREIGN KEY (`traject_id`) REFERENCES `trajecten` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=260 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
