/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*M!100616 SET @OLD_NOTE_VERBOSITY=@@NOTE_VERBOSITY, NOTE_VERBOSITY=0 */;
DROP TABLE IF EXISTS `account`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `account` (
  `account_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `account_name` varchar(50) DEFAULT NULL,
  `default` int(11) DEFAULT NULL,
  `prefix` varchar(6) DEFAULT NULL,
  PRIMARY KEY (`account_id`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `bank`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `bank` (
  `bank_id` int(11) NOT NULL AUTO_INCREMENT,
  `bank_name` varchar(100) DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `created_by` int(10) unsigned DEFAULT NULL,
  `modified_at` datetime DEFAULT NULL,
  `modified_by` int(10) unsigned DEFAULT NULL,
  `active` tinyint(1) DEFAULT 1,
  `account_number` varchar(64) DEFAULT NULL,
  `default` tinyint(1) DEFAULT 0,
  PRIMARY KEY (`bank_id`) USING BTREE,
  KEY `fk_bnk_created` (`created_by`),
  KEY `fk_bnk_modified_at` (`modified_by`),
  CONSTRAINT `fk_bnk_created` FOREIGN KEY (`created_by`) REFERENCES `users` (`usr_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `bank_entry`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `bank_entry` (
  `bnk_entry_id` int(11) NOT NULL AUTO_INCREMENT,
  `bank_id` int(11) DEFAULT NULL,
  `amount` decimal(16,2) DEFAULT NULL,
  `date` date DEFAULT NULL,
  `created_by` int(10) unsigned DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `modified_by` int(10) unsigned DEFAULT NULL,
  `modified_at` datetime DEFAULT NULL,
  `description` varchar(255) DEFAULT NULL,
  `account_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`bnk_entry_id`),
  KEY `fk_bnk` (`bank_id`),
  KEY `fk_ety_created` (`created_by`),
  KEY `fk_ety_modified` (`modified_by`),
  CONSTRAINT `fk_bnk` FOREIGN KEY (`bank_id`) REFERENCES `bank` (`bank_id`),
  CONSTRAINT `fk_ety_created` FOREIGN KEY (`created_by`) REFERENCES `users` (`usr_id`),
  CONSTRAINT `fk_ety_modified` FOREIGN KEY (`modified_by`) REFERENCES `users` (`usr_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `booking`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `booking` (
  `booking_id` int(11) NOT NULL AUTO_INCREMENT,
  `vessel` int(11) DEFAULT NULL,
  `booking_number` varchar(128) DEFAULT NULL,
  `customer_reference` varchar(64) DEFAULT NULL,
  `client` int(11) DEFAULT NULL,
  `loading_port` int(11) DEFAULT NULL,
  `loading_EDT` date DEFAULT NULL,
  `dicharge_port` varchar(50) DEFAULT NULL,
  `dicharge_port_id` int(11) DEFAULT NULL,
  `dicharge_ETA` date DEFAULT NULL,
  `container_type` int(11) DEFAULT NULL,
  `commodity` varchar(50) DEFAULT NULL,
  `set_point` varchar(50) DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `modified_at` datetime DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `modified_by` int(11) DEFAULT NULL,
  `pick_up_place` varchar(50) DEFAULT NULL,
  `carrier` varchar(50) DEFAULT NULL,
  `carrier_id` int(11) DEFAULT NULL,
  `transport_id` int(11) DEFAULT NULL,
  `pick_up_place_id` int(10) unsigned NOT NULL,
  `entrusts_letter_file` varchar(255) DEFAULT NULL,
  `warranty_file` varchar(255) DEFAULT NULL,
  `payments_file` varchar(255) DEFAULT NULL,
  `empty_maneuver_file` varchar(255) DEFAULT NULL,
  `maneuver_full_file` varchar(255) DEFAULT NULL,
  `commercial_bills_file` varchar(255) DEFAULT NULL,
  `petition_file` varchar(255) DEFAULT NULL,
  `swb_file` varchar(255) DEFAULT NULL,
  `booking_type` int(11) DEFAULT NULL,
  `HB` varchar(50) DEFAULT NULL,
  `final_destination` varchar(50) DEFAULT NULL,
  `shipper_is` longtext DEFAULT NULL,
  `shipper_should` longtext DEFAULT NULL,
  `consignee_is` longtext DEFAULT NULL,
  `consignee_should` longtext DEFAULT NULL,
  `notify_party_is` longtext DEFAULT NULL,
  `notify_party_should` longtext DEFAULT NULL,
  `description_is` longtext DEFAULT NULL,
  `description_should` longtext DEFAULT NULL,
  `email_notification` varchar(1000) CHARACTER SET utf32 COLLATE utf32_croatian_ci DEFAULT '',
  `is_draft` tinyint(1) DEFAULT 1,
  `arrival` datetime DEFAULT NULL,
  `realeased_from_shiping` datetime DEFAULT NULL,
  `customs_cleared` datetime DEFAULT NULL,
  `truck_service_request` datetime DEFAULT NULL,
  `delivered_consigned` datetime DEFAULT NULL,
  `remarks` longtext DEFAULT NULL,
  `locked` tinyint(1) DEFAULT 0,
  `custom_brocker_id` int(11) DEFAULT NULL,
  `final_destination_id` int(11) DEFAULT NULL,
  `mode` int(11) DEFAULT 10,
  `operador_id` int(10) unsigned DEFAULT NULL,
  `unidad_id` int(10) unsigned DEFAULT NULL,
  `caja_id` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`booking_id`) USING BTREE,
  KEY `fk_vessel` (`vessel`) USING BTREE,
  KEY `fk_loading_port` (`loading_port`) USING BTREE,
  KEY `fk_cont_type` (`container_type`) USING BTREE,
  KEY `fk_pickup_place_id_idx` (`pick_up_place_id`) USING BTREE,
  CONSTRAINT `fk_cont_type` FOREIGN KEY (`container_type`) REFERENCES `container_types` (`contType_id`),
  CONSTRAINT `fk_loading_port` FOREIGN KEY (`loading_port`) REFERENCES `loading_ports` (`port_id`),
  CONSTRAINT `fk_vessel` FOREIGN KEY (`vessel`) REFERENCES `vessel` (`vessel_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `booking_continuity`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `booking_continuity` (
  `cont_id` int(11) NOT NULL AUTO_INCREMENT,
  `booking` int(11) DEFAULT NULL,
  `pickup_date` datetime DEFAULT NULL,
  `modality` int(11) DEFAULT NULL,
  `vacuum_maneuver` varchar(50) DEFAULT NULL,
  `doc_cut_of` datetime DEFAULT NULL,
  `SI_date` datetime DEFAULT NULL,
  `draf_client` datetime DEFAULT NULL,
  `gated_IN` datetime DEFAULT NULL,
  `cleared` datetime DEFAULT NULL,
  `departure` datetime DEFAULT NULL,
  `bl_payment` datetime DEFAULT NULL,
  `swb` datetime DEFAULT NULL,
  `delivered` datetime DEFAULT NULL,
  `gated_out` datetime DEFAULT NULL,
  `insurance` datetime DEFAULT NULL,
  `corrected_draft` datetime DEFAULT NULL,
  `modified_by` int(11) DEFAULT NULL,
  `modified_at` datetime DEFAULT NULL,
  `vgm` datetime DEFAULT NULL,
  PRIMARY KEY (`cont_id`) USING BTREE,
  UNIQUE KEY `fk_booking` (`booking`) USING BTREE,
  KEY `fk_mod` (`modality`) USING BTREE,
  CONSTRAINT `booking_continuity_ibfk_1` FOREIGN KEY (`booking`) REFERENCES `booking` (`booking_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `booking_continuity_ibfk_2` FOREIGN KEY (`modality`) REFERENCES `modality` (`modality_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `booking_continuity_history`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `booking_continuity_history` (
  `change_type` varchar(10) DEFAULT NULL,
  `change_date` datetime DEFAULT NULL,
  `cont_id` int(11) NOT NULL,
  `booking` int(11) DEFAULT NULL,
  `pickup_date` datetime DEFAULT NULL,
  `modality` int(11) DEFAULT NULL,
  `vacuum_maneuver` varchar(50) DEFAULT NULL,
  `doc_cut_of` datetime DEFAULT NULL,
  `SI_date` datetime DEFAULT NULL,
  `draf_client` datetime DEFAULT NULL,
  `gated_IN` datetime DEFAULT NULL,
  `cleared` datetime DEFAULT NULL,
  `departure` datetime DEFAULT NULL,
  `bl_payment` datetime DEFAULT NULL,
  `swb` datetime DEFAULT NULL,
  `delivered` datetime DEFAULT NULL,
  `gated_out` datetime DEFAULT NULL,
  `insurance` datetime DEFAULT NULL,
  `corrected_draft` datetime DEFAULT NULL,
  `modified_by` int(11) DEFAULT NULL,
  `modified_at` datetime DEFAULT NULL,
  `vgm` varchar(255) DEFAULT NULL,
  KEY `fk_mod` (`modality`) USING BTREE,
  KEY `fk_booking` (`booking`) USING BTREE,
  CONSTRAINT `booking_continuity_history_ibfk_1` FOREIGN KEY (`booking`) REFERENCES `booking` (`booking_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `booking_continuity_history_ibfk_2` FOREIGN KEY (`modality`) REFERENCES `modality` (`modality_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `booking_history`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `booking_history` (
  `change_type` varchar(16) DEFAULT NULL,
  `change_date` datetime DEFAULT NULL,
  `booking_id` int(11) NOT NULL,
  `vessel` int(11) DEFAULT NULL,
  `booking_number` varchar(50) DEFAULT NULL,
  `customer_reference` varchar(64) DEFAULT NULL,
  `client` int(11) DEFAULT NULL,
  `loading_port` int(11) DEFAULT NULL,
  `loading_EDT` date DEFAULT NULL,
  `dicharge_port` varchar(50) DEFAULT NULL,
  `dicharge_port_id` int(11) DEFAULT NULL,
  `dicharge_ETA` date DEFAULT NULL,
  `container_type` int(11) DEFAULT NULL,
  `commodity` varchar(50) DEFAULT NULL,
  `set_point` varchar(50) DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `modified_at` datetime DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `modified_by` int(11) DEFAULT NULL,
  `pick_up_place` varchar(50) DEFAULT NULL,
  `carrier` varchar(50) DEFAULT NULL,
  `carrier_id` int(11) DEFAULT NULL,
  `transport_id` int(11) DEFAULT NULL,
  `pick_up_place_id` int(10) unsigned NOT NULL,
  `entrusts_letter_file` varchar(255) DEFAULT NULL,
  `warranty_file` varchar(255) DEFAULT NULL,
  `payments_file` varchar(255) DEFAULT NULL,
  `empty_maneuver_file` varchar(255) DEFAULT NULL,
  `maneuver_full_file` varchar(255) DEFAULT NULL,
  `commercial_bills_file` varchar(255) DEFAULT NULL,
  `petition_file` varchar(255) DEFAULT NULL,
  `swb_file` varchar(255) DEFAULT NULL,
  `booking_type` int(11) DEFAULT NULL,
  `HB` varchar(50) DEFAULT NULL,
  `final_destination` varchar(50) DEFAULT NULL,
  `shipper_is` longtext DEFAULT NULL,
  `shipper_should` longtext DEFAULT NULL,
  `consignee_is` longtext DEFAULT NULL,
  `consignee_should` longtext DEFAULT NULL,
  `notify_party_is` longtext DEFAULT NULL,
  `notify_party_should` longtext DEFAULT NULL,
  `description_is` longtext DEFAULT NULL,
  `description_should` longtext DEFAULT NULL,
  `email_notification` varchar(1000) CHARACTER SET utf32 COLLATE utf32_croatian_ci DEFAULT '',
  `is_draft` tinyint(1) DEFAULT 1,
  `arrival` datetime DEFAULT NULL,
  `realeased_from_shiping` datetime DEFAULT NULL,
  `customs_cleared` datetime DEFAULT NULL,
  `truck_service_request` datetime DEFAULT NULL,
  `delivered_consigned` datetime DEFAULT NULL,
  `remarks` longtext DEFAULT NULL,
  `locked` tinyint(1) DEFAULT 0,
  `custom_brocker_id` int(11) DEFAULT NULL,
  `final_destination_id` int(11) DEFAULT NULL,
  `mode` int(11) DEFAULT NULL,
  KEY `fk_vessel` (`vessel`) USING BTREE,
  KEY `fk_loading_port` (`loading_port`) USING BTREE,
  KEY `fk_cont_type` (`container_type`) USING BTREE,
  KEY `fk_pickup_place_id_idx` (`pick_up_place_id`) USING BTREE,
  CONSTRAINT `booking_history_ibfk_1` FOREIGN KEY (`container_type`) REFERENCES `container_types` (`contType_id`),
  CONSTRAINT `booking_history_ibfk_2` FOREIGN KEY (`loading_port`) REFERENCES `loading_ports` (`port_id`),
  CONSTRAINT `booking_history_ibfk_3` FOREIGN KEY (`vessel`) REFERENCES `vessel` (`vessel_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `cache`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `cache` (
  `id` char(128) NOT NULL,
  `expire` int(11) DEFAULT NULL,
  `data` blob DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `campo_expediente`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `campo_expediente` (
  `campo_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `clave` varchar(40) NOT NULL,
  `etiqueta` varchar(60) NOT NULL,
  `tipo` varchar(10) NOT NULL DEFAULT 'text',
  `opciones` varchar(255) DEFAULT NULL,
  `grupo` varchar(40) DEFAULT NULL,
  `orden` smallint(5) unsigned NOT NULL DEFAULT 0,
  `obligatorio` tinyint(1) NOT NULL DEFAULT 0,
  `activo` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`campo_id`),
  UNIQUE KEY `campo_expediente_clave_unique` (`clave`),
  KEY `campo_expediente_activo_orden_index` (`activo`,`orden`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `carrier`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `carrier` (
  `carrier_id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `email` varchar(50) NOT NULL,
  `password` varchar(64) NOT NULL,
  `created_at` datetime DEFAULT NULL,
  `modified_at` datetime DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `modified_by` int(11) DEFAULT NULL,
  `auth_key` varchar(128) DEFAULT NULL,
  `password_reset_token` varchar(64) DEFAULT NULL,
  PRIMARY KEY (`carrier_id`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `carrier_booking`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `carrier_booking` (
  `carrbybook_id` int(11) NOT NULL AUTO_INCREMENT,
  `booking_id` int(11) DEFAULT NULL,
  `carrier_id` int(11) DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `modified_at` datetime DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `modified_by` int(11) DEFAULT NULL,
  PRIMARY KEY (`carrbybook_id`) USING BTREE,
  KEY `carrierID_idx` (`carrier_id`) USING BTREE,
  KEY `bookID_idx` (`booking_id`) USING BTREE,
  CONSTRAINT `booking` FOREIGN KEY (`booking_id`) REFERENCES `booking` (`booking_id`),
  CONSTRAINT `carrier_type` FOREIGN KEY (`carrier_id`) REFERENCES `carrier` (`carrier_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `charge`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `charge` (
  `charge_id` int(11) NOT NULL AUTO_INCREMENT,
  `transaction` int(11) DEFAULT NULL,
  `service_id` int(11) DEFAULT NULL,
  `type` int(11) DEFAULT NULL,
  `tax_code` varchar(25) DEFAULT NULL,
  `description` varchar(100) DEFAULT NULL,
  `quantity` decimal(16,4) DEFAULT NULL,
  `unit` decimal(16,4) DEFAULT NULL,
  `price` decimal(16,4) DEFAULT NULL,
  `prepaid` int(11) DEFAULT NULL,
  `price_confirmation` decimal(16,4) DEFAULT NULL,
  PRIMARY KEY (`charge_id`) USING BTREE,
  KEY `fk_chk_type` (`type`) USING BTREE,
  KEY `fk_tran` (`transaction`) USING BTREE,
  CONSTRAINT `fk_chk_type` FOREIGN KEY (`type`) REFERENCES `charge_type` (`charge_type_id`) ON DELETE SET NULL ON UPDATE SET NULL,
  CONSTRAINT `fk_tran` FOREIGN KEY (`transaction`) REFERENCES `transaction` (`transc_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `charge_type`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `charge_type` (
  `charge_type_id` int(11) NOT NULL AUTO_INCREMENT,
  `charge_type_name` varchar(25) DEFAULT NULL,
  `tax_rate` decimal(7,4) NOT NULL,
  `tax_retention` decimal(7,4) NOT NULL,
  `tax_name` varchar(25) DEFAULT NULL,
  `product_code` varchar(64) DEFAULT NULL,
  `non_deductible` tinyint(1) NOT NULL DEFAULT 0,
  `deleted` tinyint(1) DEFAULT 0,
  PRIMARY KEY (`charge_type_id`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `check_list`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `check_list` (
  `trash` tinyint(1) DEFAULT NULL,
  `check_id` int(11) NOT NULL AUTO_INCREMENT,
  `modified_by` int(11) DEFAULT NULL,
  `booking` int(11) DEFAULT NULL,
  `booking_number_chk_date` datetime DEFAULT NULL,
  `booking_number_chk_by` int(11) DEFAULT NULL,
  `pickup_date_chk_date` datetime DEFAULT NULL,
  `pickup_date_chk_by` int(11) DEFAULT NULL,
  `modality_chk_date` datetime DEFAULT NULL,
  `modality_chk_by` int(11) DEFAULT NULL,
  `doc_cut_of_chk_date` datetime DEFAULT NULL,
  `doc_cut_of_chk_by` int(11) DEFAULT NULL,
  `SI_date_chk_date` datetime DEFAULT NULL,
  `SI_date_chk_by` int(11) DEFAULT NULL,
  `cleared_chk_date` datetime DEFAULT NULL,
  `cleared_chk_by` int(11) DEFAULT NULL,
  `departure_chk_date` datetime DEFAULT NULL,
  `departure_chk_by` int(11) DEFAULT NULL,
  `bl_payment_chk_date` datetime DEFAULT NULL,
  `bl_payment_chk_by` int(11) DEFAULT NULL,
  `swb_chk_date` datetime DEFAULT NULL,
  `swb_chk_by` int(11) DEFAULT NULL,
  `vessel_chk_date` datetime DEFAULT NULL,
  `vessel_chk_by` int(11) DEFAULT NULL,
  `number_chk_date` datetime DEFAULT NULL,
  `number_chk_by` int(11) DEFAULT NULL,
  `client_chk_date` datetime DEFAULT NULL,
  `client_chk_by` int(11) DEFAULT NULL,
  `loading_port_chk_date` datetime DEFAULT NULL,
  `loading_port_chk_by` int(11) DEFAULT NULL,
  `loading_EDT_chk_date` datetime DEFAULT NULL,
  `loading_EDT_chk_by` int(11) DEFAULT NULL,
  `dicharge_port_chk_date` datetime DEFAULT NULL,
  `dicharge_port_chk_by` int(11) DEFAULT NULL,
  `container_type_chk_date` datetime DEFAULT NULL,
  `container_type_chk_by` int(11) DEFAULT NULL,
  `commodity_chk_date` datetime DEFAULT NULL,
  `commodity_chk_by` int(11) DEFAULT NULL,
  `set_point_chk_date` datetime DEFAULT NULL,
  `set_point_chk_by` int(11) DEFAULT NULL,
  `dicharge_ETA_chk_date` datetime DEFAULT NULL,
  `dicharge_ETA_chk_by` int(11) DEFAULT NULL,
  `vacuum_maneuver_chk_date` datetime DEFAULT NULL,
  `vacuum_maneuver_chk_by` int(11) DEFAULT NULL,
  `draf_client_chk_date` datetime DEFAULT NULL,
  `draf_client_chk_by` int(11) DEFAULT NULL,
  `gated_IN_chk_date` datetime DEFAULT NULL,
  `gated_IN_chk_by` int(11) DEFAULT NULL,
  `gated_out_chk_date` datetime DEFAULT NULL,
  `gated_out_chk_by` int(11) DEFAULT NULL,
  `delivered_chk_date` datetime DEFAULT NULL,
  `delivered_chk_by` int(11) DEFAULT NULL,
  `pick_up_place_chk_date` datetime DEFAULT NULL,
  `pick_up_place_chk_by` int(11) DEFAULT NULL,
  `insurance_chk_date` datetime DEFAULT NULL,
  `insurance_chk_by` int(11) DEFAULT NULL,
  `corrected_draft_chk_date` datetime DEFAULT NULL,
  `corrected_draft_chk_by` int(11) DEFAULT NULL,
  `vgm_chk_date` datetime DEFAULT NULL,
  `vgm_chk_by` int(11) DEFAULT NULL,
  PRIMARY KEY (`check_id`) USING BTREE,
  KEY `fk_booking_chk` (`booking`) USING BTREE,
  KEY `fk_booking_chk_by` (`booking_number_chk_by`) USING BTREE,
  KEY `fk_pickup_chk_by` (`pickup_date_chk_by`) USING BTREE,
  KEY `fk_modality_chk_by` (`modality_chk_by`) USING BTREE,
  KEY `fk_doc_cut_of_chk_by` (`doc_cut_of_chk_by`) USING BTREE,
  KEY `fk_SI_date_chk_by` (`SI_date_chk_by`) USING BTREE,
  KEY `fk_cleared_chk_by` (`cleared_chk_by`) USING BTREE,
  KEY `fk_departure_chk_by` (`departure_chk_by`) USING BTREE,
  KEY `fk_bl_payment_chk_by` (`bl_payment_chk_by`) USING BTREE,
  KEY `fk_swb_chk_by` (`swb_chk_by`) USING BTREE,
  KEY `fk_vessel_chk_by` (`vessel_chk_by`) USING BTREE,
  KEY `fk_number_chk_by` (`number_chk_by`) USING BTREE,
  KEY `fk_client_chk_by` (`client_chk_by`) USING BTREE,
  KEY `fk_loading_port_chk_by` (`loading_port_chk_by`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `check_list_history`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `check_list_history` (
  `change_type` varchar(10) DEFAULT NULL,
  `change_date` datetime DEFAULT NULL,
  `trash` tinyint(1) DEFAULT NULL,
  `check_id` int(11) NOT NULL,
  `modified_by` int(11) DEFAULT NULL,
  `booking` int(11) DEFAULT NULL,
  `booking_number_chk_date` datetime DEFAULT NULL,
  `booking_number_chk_by` int(11) DEFAULT NULL,
  `pickup_date_chk_date` datetime DEFAULT NULL,
  `pickup_date_chk_by` int(11) DEFAULT NULL,
  `modality_chk_date` datetime DEFAULT NULL,
  `modality_chk_by` int(11) DEFAULT NULL,
  `doc_cut_of_chk_date` datetime DEFAULT NULL,
  `doc_cut_of_chk_by` int(11) DEFAULT NULL,
  `SI_date_chk_date` datetime DEFAULT NULL,
  `SI_date_chk_by` int(11) DEFAULT NULL,
  `cleared_chk_date` datetime DEFAULT NULL,
  `cleared_chk_by` int(11) DEFAULT NULL,
  `departure_chk_date` datetime DEFAULT NULL,
  `departure_chk_by` int(11) DEFAULT NULL,
  `bl_payment_chk_date` datetime DEFAULT NULL,
  `bl_payment_chk_by` int(11) DEFAULT NULL,
  `swb_chk_date` datetime DEFAULT NULL,
  `swb_chk_by` int(11) DEFAULT NULL,
  `vessel_chk_date` datetime DEFAULT NULL,
  `vessel_chk_by` int(11) DEFAULT NULL,
  `number_chk_date` datetime DEFAULT NULL,
  `number_chk_by` int(11) DEFAULT NULL,
  `client_chk_date` datetime DEFAULT NULL,
  `client_chk_by` int(11) DEFAULT NULL,
  `loading_port_chk_date` datetime DEFAULT NULL,
  `loading_port_chk_by` int(11) DEFAULT NULL,
  `loading_EDT_chk_date` datetime DEFAULT NULL,
  `loading_EDT_chk_by` int(11) DEFAULT NULL,
  `dicharge_port_chk_date` datetime DEFAULT NULL,
  `dicharge_port_chk_by` int(11) DEFAULT NULL,
  `container_type_chk_date` datetime DEFAULT NULL,
  `container_type_chk_by` int(11) DEFAULT NULL,
  `commodity_chk_date` datetime DEFAULT NULL,
  `commodity_chk_by` int(11) DEFAULT NULL,
  `set_point_chk_date` datetime DEFAULT NULL,
  `set_point_chk_by` int(11) DEFAULT NULL,
  `dicharge_ETA_chk_date` datetime DEFAULT NULL,
  `dicharge_ETA_chk_by` int(11) DEFAULT NULL,
  `vacuum_maneuver_chk_date` datetime DEFAULT NULL,
  `vacuum_maneuver_chk_by` int(11) DEFAULT NULL,
  `draf_client_chk_date` datetime DEFAULT NULL,
  `draf_client_chk_by` int(11) DEFAULT NULL,
  `gated_IN_chk_date` datetime DEFAULT NULL,
  `gated_IN_chk_by` int(11) DEFAULT NULL,
  `gated_out_chk_date` datetime DEFAULT NULL,
  `gated_out_chk_by` int(11) DEFAULT NULL,
  `delivered_chk_date` datetime DEFAULT NULL,
  `delivered_chk_by` int(11) DEFAULT NULL,
  `pick_up_place_chk_date` datetime DEFAULT NULL,
  `pick_up_place_chk_by` int(11) DEFAULT NULL,
  `insurance_chk_date` datetime DEFAULT NULL,
  `insurance_chk_by` int(11) DEFAULT NULL,
  `corrected_draft_chk_date` datetime DEFAULT NULL,
  `corrected_draft_chk_by` int(11) DEFAULT NULL,
  `vgm_chk_date` datetime DEFAULT NULL,
  `vgm_chk_by` int(11) DEFAULT NULL,
  KEY `fk_booking_chk` (`booking`) USING BTREE,
  KEY `fk_booking_chk_by` (`booking_number_chk_by`) USING BTREE,
  KEY `fk_pickup_chk_by` (`pickup_date_chk_by`) USING BTREE,
  KEY `fk_modality_chk_by` (`modality_chk_by`) USING BTREE,
  KEY `fk_doc_cut_of_chk_by` (`doc_cut_of_chk_by`) USING BTREE,
  KEY `fk_SI_date_chk_by` (`SI_date_chk_by`) USING BTREE,
  KEY `fk_cleared_chk_by` (`cleared_chk_by`) USING BTREE,
  KEY `fk_departure_chk_by` (`departure_chk_by`) USING BTREE,
  KEY `fk_bl_payment_chk_by` (`bl_payment_chk_by`) USING BTREE,
  KEY `fk_swb_chk_by` (`swb_chk_by`) USING BTREE,
  KEY `fk_vessel_chk_by` (`vessel_chk_by`) USING BTREE,
  KEY `fk_number_chk_by` (`number_chk_by`) USING BTREE,
  KEY `fk_client_chk_by` (`client_chk_by`) USING BTREE,
  KEY `fk_loading_port_chk_by` (`loading_port_chk_by`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `client`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `client` (
  `client_id` int(11) NOT NULL AUTO_INCREMENT,
  `rfc` varchar(25) DEFAULT NULL,
  `account_id` int(11) DEFAULT NULL,
  `email` varchar(50) NOT NULL,
  `password` varchar(128) DEFAULT NULL,
  `fullName` varchar(100) NOT NULL,
  `address` varchar(255) DEFAULT NULL,
  `ExtNumber` varchar(10) DEFAULT NULL,
  `IntNumber` varchar(10) DEFAULT NULL,
  `colony` varchar(50) DEFAULT NULL,
  `locality` varchar(50) DEFAULT NULL,
  `state` varchar(25) DEFAULT NULL,
  `city` varchar(25) DEFAULT NULL,
  `postal_code` varchar(25) DEFAULT NULL,
  `phone` int(11) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `modified_by` int(11) DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `modified_at` datetime DEFAULT NULL,
  `address2` varchar(255) DEFAULT NULL,
  `country` varchar(25) DEFAULT NULL,
  `auth_key` varchar(128) DEFAULT NULL,
  `password_reset_token` varchar(64) DEFAULT NULL,
  `verification_code` varchar(250) DEFAULT NULL,
  `role` tinyint(4) DEFAULT NULL,
  `email_notification` varchar(1000) DEFAULT NULL,
  `notification_notes` longtext DEFAULT NULL,
  `pay_method` varchar(5) DEFAULT NULL,
  `invoice_use` varchar(5) DEFAULT NULL,
  `pay_form` varchar(5) DEFAULT NULL,
  `Invoice_recipient` varchar(255) DEFAULT NULL,
  `activate_processed` tinyint(1) DEFAULT 0,
  `match_pickup_place` tinyint(1) DEFAULT 0,
  `regimen_fiscal_id` varchar(3) DEFAULT NULL,
  PRIMARY KEY (`client_id`) USING BTREE,
  KEY `fullName` (`fullName`) USING BTREE
) ENGINE=MyISAM DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `company`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `company` (
  `company_id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(150) NOT NULL,
  `business_name` varchar(255) DEFAULT NULL,
  `rfc` varchar(15) DEFAULT NULL,
  `regimen_fiscal` varchar(5) DEFAULT '601',
  `postal_code` varchar(10) DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `active` tinyint(1) DEFAULT 1,
  PRIMARY KEY (`company_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_uca1400_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `configuracion`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `configuracion` (
  `clave` varchar(60) NOT NULL,
  `valor` text DEFAULT NULL,
  `modified_at` datetime DEFAULT NULL,
  `modified_by` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`clave`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `container_types`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `container_types` (
  `contType_id` int(11) NOT NULL AUTO_INCREMENT,
  `container_name` varchar(25) DEFAULT NULL,
  PRIMARY KEY (`contType_id`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `containers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `containers` (
  `container_ID` int(11) NOT NULL AUTO_INCREMENT,
  `quantity` int(11) NOT NULL,
  `comodity` varchar(25) NOT NULL,
  `container_type` int(11) NOT NULL,
  `booking` int(11) NOT NULL,
  `created_by` int(11) NOT NULL,
  `modified_by` int(11) NOT NULL,
  `created_at` date NOT NULL,
  `modified_at` date NOT NULL,
  `number` varchar(50) DEFAULT NULL,
  `seal` varchar(50) DEFAULT NULL,
  `pick_up_date` datetime DEFAULT NULL,
  PRIMARY KEY (`container_ID`) USING BTREE,
  KEY `containers_ibfk_1` (`container_type`) USING BTREE,
  KEY `containers_ibfk_2` (`booking`) USING BTREE,
  CONSTRAINT `containers_ibfk_1` FOREIGN KEY (`container_type`) REFERENCES `container_types` (`contType_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `containers_ibfk_2` FOREIGN KEY (`booking`) REFERENCES `booking` (`booking_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `containers_history`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `containers_history` (
  `change_type` varchar(10) DEFAULT NULL,
  `change_date` datetime DEFAULT NULL,
  `container_ID` int(11) NOT NULL,
  `quantity` int(11) NOT NULL,
  `comodity` varchar(25) NOT NULL,
  `container_type` int(11) NOT NULL,
  `booking` int(11) NOT NULL,
  `created_by` int(11) NOT NULL,
  `modified_by` int(11) NOT NULL,
  `created_at` date NOT NULL,
  `modified_at` date NOT NULL,
  `number` varchar(50) DEFAULT NULL,
  `seal` varchar(50) DEFAULT NULL,
  `pick_up_date` datetime DEFAULT NULL,
  KEY `containers_ibfk_1` (`container_type`) USING BTREE,
  KEY `containers_ibfk_2` (`booking`) USING BTREE,
  CONSTRAINT `containers_history_ibfk_1` FOREIGN KEY (`container_type`) REFERENCES `container_types` (`contType_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `dicharge_port`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `dicharge_port` (
  `dicharge_port_id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) DEFAULT NULL,
  `deleted` tinyint(4) NOT NULL DEFAULT 0,
  `latitud` decimal(9,6) DEFAULT NULL,
  `longitud` decimal(9,6) DEFAULT NULL,
  PRIMARY KEY (`dicharge_port_id`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `empleado`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `empleado` (
  `empleado_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `nombre` varchar(120) NOT NULL,
  `numero` varchar(30) DEFAULT NULL,
  `puesto` varchar(60) DEFAULT NULL,
  `departamento` varchar(60) DEFAULT NULL,
  `rfc` varchar(20) DEFAULT NULL,
  `curp` varchar(20) DEFAULT NULL,
  `nss` varchar(20) DEFAULT NULL,
  `ingreso` date DEFAULT NULL,
  `salario_diario` decimal(12,4) DEFAULT NULL,
  `banco` varchar(40) DEFAULT NULL,
  `clabe` varchar(20) DEFAULT NULL,
  `operador_id` int(10) unsigned DEFAULT NULL,
  `activo` tinyint(1) NOT NULL DEFAULT 1,
  `notas` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`empleado_id`),
  KEY `empleado_activo_nombre_index` (`activo`,`nombre`),
  KEY `empleado_operador_id_index` (`operador_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `exchange`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `exchange` (
  `exchange_id` int(11) NOT NULL AUTO_INCREMENT,
  `exchange_value` decimal(11,4) NOT NULL,
  `date_exchange` date NOT NULL,
  `account` int(11) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `modified_by` int(11) DEFAULT NULL,
  `created_at` date DEFAULT NULL,
  `modified_at` date DEFAULT NULL,
  `url` longtext DEFAULT NULL,
  `taken_date` date DEFAULT NULL,
  PRIMARY KEY (`exchange_id`) USING BTREE,
  UNIQUE KEY `uq_date` (`date_exchange`,`account`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `fields_by_client`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `fields_by_client` (
  `customer_field_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `client_id` int(10) unsigned DEFAULT NULL,
  `field_id` int(11) DEFAULT NULL,
  PRIMARY KEY (`customer_field_id`) USING BTREE,
  KEY `fk_field` (`field_id`) USING BTREE,
  KEY `fk_field_client` (`client_id`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `file_fields`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `file_fields` (
  `field_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `field` varchar(25) DEFAULT NULL,
  `label` varchar(25) DEFAULT NULL,
  `default` tinyint(1) DEFAULT NULL,
  PRIMARY KEY (`field_id`) USING BTREE,
  KEY `field` (`field`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `files_by_booking`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `files_by_booking` (
  `booking_file_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `booking_id` int(11) DEFAULT NULL,
  `field_id` int(10) unsigned DEFAULT NULL,
  `value` longtext DEFAULT NULL,
  PRIMARY KEY (`booking_file_id`) USING BTREE,
  KEY `fk_bk_field` (`booking_id`) USING BTREE,
  KEY `fk_val_field` (`field_id`) USING BTREE,
  CONSTRAINT `fk_bk_field` FOREIGN KEY (`booking_id`) REFERENCES `booking` (`booking_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_val_field` FOREIGN KEY (`field_id`) REFERENCES `file_fields` (`field_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `final_destination`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `final_destination` (
  `final_destination_id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(64) DEFAULT NULL,
  `deleted` tinyint(3) unsigned DEFAULT 0,
  `latitud` decimal(9,6) DEFAULT NULL,
  `longitud` decimal(9,6) DEFAULT NULL,
  PRIMARY KEY (`final_destination_id`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `gasto_viaje`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `gasto_viaje` (
  `gasto_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `booking` int(10) unsigned DEFAULT NULL,
  `tipo` varchar(15) NOT NULL DEFAULT 'combustible',
  `fecha` date NOT NULL,
  `unidad_id` int(10) unsigned DEFAULT NULL,
  `operador_id` int(10) unsigned DEFAULT NULL,
  `provider_id` int(10) unsigned DEFAULT NULL,
  `descripcion` varchar(120) DEFAULT NULL,
  `litros` decimal(10,2) DEFAULT NULL,
  `precio_litro` decimal(10,4) DEFAULT NULL,
  `odometro` int(10) unsigned DEFAULT NULL,
  `importe` decimal(16,4) NOT NULL DEFAULT 0.0000,
  `forma_pago` varchar(20) DEFAULT NULL,
  `folio` varchar(40) DEFAULT NULL,
  `created_by` int(10) unsigned DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  PRIMARY KEY (`gasto_id`),
  KEY `gasto_viaje_booking_index` (`booking`),
  KEY `gasto_viaje_unidad_id_odometro_index` (`unidad_id`,`odometro`),
  KEY `gasto_viaje_tipo_fecha_index` (`tipo`,`fecha`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `hito`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `hito` (
  `hito_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `clave` varchar(40) NOT NULL,
  `etiqueta` varchar(60) NOT NULL,
  `orden` smallint(5) unsigned NOT NULL DEFAULT 0,
  `activo` tinyint(1) NOT NULL DEFAULT 1,
  `columna_legado` varchar(40) DEFAULT NULL,
  PRIMARY KEY (`hito_id`),
  UNIQUE KEY `hito_clave_unique` (`clave`),
  KEY `hito_activo_orden_index` (`activo`,`orden`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `hito_por_expediente`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `hito_por_expediente` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `booking` int(10) unsigned NOT NULL,
  `hito_id` int(10) unsigned NOT NULL,
  `fecha` datetime DEFAULT NULL,
  `modified_by` int(10) unsigned DEFAULT NULL,
  `modified_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `hito_por_expediente_booking_hito_id_unique` (`booking`,`hito_id`),
  KEY `hito_por_expediente_hito_id_index` (`hito_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `holiday`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `holiday` (
  `holiday_id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(255) DEFAULT NULL,
  `start_date` date DEFAULT NULL,
  `end_date` date DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `modified_at` datetime DEFAULT NULL,
  `modified_by` int(11) DEFAULT NULL,
  PRIMARY KEY (`holiday_id`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `invoice_use`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `invoice_use` (
  `code` varchar(4) DEFAULT NULL,
  `name` varchar(64) DEFAULT NULL,
  `fisica` varchar(4) DEFAULT NULL,
  `moral` varchar(4) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `liquidacion`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `liquidacion` (
  `liquidacion_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `numero` varchar(20) NOT NULL,
  `operador_id` int(10) unsigned NOT NULL,
  `desde` date NOT NULL,
  `hasta` date NOT NULL,
  `estado` varchar(10) NOT NULL DEFAULT 'abierta',
  `pagada_en` datetime DEFAULT NULL,
  `bank_id` int(10) unsigned DEFAULT NULL,
  `created_by` int(10) unsigned DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `notas` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`liquidacion_id`),
  KEY `liquidacion_operador_id_estado_index` (`operador_id`,`estado`),
  KEY `liquidacion_desde_index` (`desde`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `liquidacion_renglon`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `liquidacion_renglon` (
  `renglon_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `liquidacion_id` int(10) unsigned NOT NULL,
  `booking` int(10) unsigned DEFAULT NULL,
  `concepto` varchar(120) NOT NULL,
  `tipo` varchar(12) NOT NULL DEFAULT 'percepcion',
  `importe` decimal(16,4) NOT NULL DEFAULT 0.0000,
  `fecha` date DEFAULT NULL,
  PRIMARY KEY (`renglon_id`),
  KEY `liquidacion_renglon_liquidacion_id_index` (`liquidacion_id`),
  KEY `liquidacion_renglon_booking_index` (`booking`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `loading_ports`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `loading_ports` (
  `port_id` int(11) NOT NULL AUTO_INCREMENT,
  `port_name` varchar(100) DEFAULT NULL,
  `deleted` tinyint(1) NOT NULL DEFAULT 0,
  `latitud` decimal(9,6) DEFAULT NULL,
  `longitud` decimal(9,6) DEFAULT NULL,
  PRIMARY KEY (`port_id`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `mantenimiento`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `mantenimiento` (
  `mantenimiento_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `folio` varchar(20) NOT NULL,
  `unidad_id` int(10) unsigned NOT NULL,
  `tipo` varchar(12) NOT NULL DEFAULT 'preventivo',
  `estado` varchar(10) NOT NULL DEFAULT 'abierto',
  `entrada` date NOT NULL,
  `salida` date DEFAULT NULL,
  `odometro` int(10) unsigned DEFAULT NULL,
  `taller` varchar(10) NOT NULL DEFAULT 'interno',
  `provider_id` int(10) unsigned DEFAULT NULL,
  `descripcion` varchar(200) NOT NULL,
  `mano_obra` decimal(14,2) NOT NULL DEFAULT 0.00,
  `notas` varchar(255) DEFAULT NULL,
  `created_by` int(10) unsigned DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  PRIMARY KEY (`mantenimiento_id`),
  KEY `mantenimiento_unidad_id_entrada_index` (`unidad_id`,`entrada`),
  KEY `mantenimiento_estado_index` (`estado`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `mantenimiento_refaccion`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `mantenimiento_refaccion` (
  `renglon_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `mantenimiento_id` int(10) unsigned NOT NULL,
  `refaccion_id` int(10) unsigned NOT NULL,
  `cantidad` decimal(12,2) NOT NULL DEFAULT 1.00,
  `costo` decimal(12,4) NOT NULL DEFAULT 0.0000,
  PRIMARY KEY (`renglon_id`),
  KEY `mantenimiento_refaccion_mantenimiento_id_index` (`mantenimiento_id`),
  KEY `mantenimiento_refaccion_refaccion_id_index` (`refaccion_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `migrations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `migrations` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `migration` varchar(255) NOT NULL,
  `batch` int(11) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `modality`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `modality` (
  `modality_id` int(11) NOT NULL AUTO_INCREMENT,
  `modality_name` varchar(15) DEFAULT NULL,
  PRIMARY KEY (`modality_id`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `model_has_permissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `model_has_permissions` (
  `permission_id` bigint(20) unsigned NOT NULL,
  `model_type` varchar(255) NOT NULL,
  `model_id` bigint(20) unsigned NOT NULL,
  PRIMARY KEY (`permission_id`,`model_id`,`model_type`),
  KEY `model_has_permissions_model_id_model_type_index` (`model_id`,`model_type`),
  CONSTRAINT `model_has_permissions_permission_id_foreign` FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `model_has_roles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `model_has_roles` (
  `role_id` bigint(20) unsigned NOT NULL,
  `model_type` varchar(255) NOT NULL,
  `model_id` bigint(20) unsigned NOT NULL,
  PRIMARY KEY (`role_id`,`model_id`,`model_type`),
  KEY `model_has_roles_model_id_model_type_index` (`model_id`,`model_type`),
  CONSTRAINT `model_has_roles_role_id_foreign` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `movimiento_refaccion`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `movimiento_refaccion` (
  `movimiento_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `refaccion_id` int(10) unsigned NOT NULL,
  `tipo` varchar(10) NOT NULL,
  `cantidad` decimal(12,2) NOT NULL,
  `costo` decimal(12,4) NOT NULL DEFAULT 0.0000,
  `fecha` date NOT NULL,
  `mantenimiento_id` int(10) unsigned DEFAULT NULL,
  `provider_id` int(10) unsigned DEFAULT NULL,
  `folio` varchar(40) DEFAULT NULL,
  `notas` varchar(255) DEFAULT NULL,
  `created_by` int(10) unsigned DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  PRIMARY KEY (`movimiento_id`),
  KEY `movimiento_refaccion_refaccion_id_fecha_index` (`refaccion_id`,`fecha`),
  KEY `movimiento_refaccion_mantenimiento_id_index` (`mantenimiento_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `nomina`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `nomina` (
  `nomina_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `numero` varchar(20) NOT NULL,
  `desde` date NOT NULL,
  `hasta` date NOT NULL,
  `periodicidad` varchar(12) NOT NULL DEFAULT 'quincenal',
  `estado` varchar(10) NOT NULL DEFAULT 'abierta',
  `pagada_en` datetime DEFAULT NULL,
  `created_by` int(10) unsigned DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `notas` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`nomina_id`),
  KEY `nomina_estado_index` (`estado`),
  KEY `nomina_desde_index` (`desde`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `nomina_renglon`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `nomina_renglon` (
  `renglon_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `nomina_id` int(10) unsigned NOT NULL,
  `empleado_id` int(10) unsigned NOT NULL,
  `concepto` varchar(120) NOT NULL,
  `tipo` varchar(12) NOT NULL DEFAULT 'percepcion',
  `importe` decimal(16,4) NOT NULL DEFAULT 0.0000,
  `liquidacion_id` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`renglon_id`),
  KEY `nomina_renglon_nomina_id_index` (`nomina_id`),
  KEY `nomina_renglon_nomina_id_empleado_id_index` (`nomina_id`,`empleado_id`),
  KEY `nomina_renglon_liquidacion_id_index` (`liquidacion_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `operador`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `operador` (
  `operador_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `nombre` varchar(120) NOT NULL,
  `numero` varchar(30) DEFAULT NULL,
  `rfc` varchar(20) DEFAULT NULL,
  `curp` varchar(20) DEFAULT NULL,
  `nss` varchar(20) DEFAULT NULL,
  `telefono` varchar(40) DEFAULT NULL,
  `licencia` varchar(40) DEFAULT NULL,
  `licencia_tipo` varchar(20) DEFAULT NULL,
  `licencia_vence` date DEFAULT NULL,
  `examen_medico_vence` date DEFAULT NULL,
  `ingreso` date DEFAULT NULL,
  `activo` tinyint(1) NOT NULL DEFAULT 1,
  `notas` varchar(255) DEFAULT NULL,
  `tarifa_tipo` varchar(12) DEFAULT NULL,
  `tarifa_valor` decimal(12,4) DEFAULT NULL,
  PRIMARY KEY (`operador_id`),
  KEY `operador_activo_nombre_index` (`activo`,`nombre`),
  KEY `operador_licencia_vence_index` (`licencia_vence`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `password_reset_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `password_reset_tokens` (
  `email` varchar(255) NOT NULL,
  `token` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `pay_form`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `pay_form` (
  `code` varchar(4) DEFAULT NULL,
  `name` varchar(64) DEFAULT NULL,
  `description` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `pay_method`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `pay_method` (
  `code` varchar(4) DEFAULT NULL,
  `name` varchar(64) DEFAULT NULL,
  `description` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `payment_request`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `payment_request` (
  `request_id` int(11) NOT NULL AUTO_INCREMENT,
  `number` varchar(64) NOT NULL,
  `amount` decimal(18,2) NOT NULL,
  `paid` tinyint(1) DEFAULT 0,
  `created_at` datetime DEFAULT NULL,
  `modified_at` datetime DEFAULT NULL,
  `created_by` int(10) unsigned DEFAULT NULL,
  `modified_by` int(10) unsigned DEFAULT NULL,
  `provider_id` int(11) DEFAULT NULL,
  `date` date DEFAULT NULL,
  `currency_id` int(10) unsigned DEFAULT NULL,
  `bank_id` int(11) DEFAULT NULL,
  `type` tinyint(4) DEFAULT NULL,
  `client_id` int(11) DEFAULT NULL,
  `temp_number` int(11) DEFAULT NULL,
  `isPartial` tinyint(1) DEFAULT 0,
  `payments` longtext DEFAULT NULL,
  `total_to_pay` decimal(18,4) DEFAULT NULL,
  `custom_tc` tinyint(1) DEFAULT 0,
  `tc_value` decimal(16,4) DEFAULT NULL,
  `opened` tinyint(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`request_id`) USING BTREE,
  KEY `fk_req_bnk` (`bank_id`),
  KEY `fk_req_acc` (`currency_id`),
  CONSTRAINT `fk_req_acc` FOREIGN KEY (`currency_id`) REFERENCES `account` (`account_id`),
  CONSTRAINT `fk_req_bnk` FOREIGN KEY (`bank_id`) REFERENCES `bank` (`bank_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `payment_terms`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `payment_terms` (
  `pay_terms_id` int(11) NOT NULL AUTO_INCREMENT,
  `pay_terms` varchar(50) DEFAULT NULL,
  PRIMARY KEY (`pay_terms_id`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `payments_by_transaction`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `payments_by_transaction` (
  `request_id` int(11) NOT NULL DEFAULT 0,
  `transc_id` int(11) NOT NULL,
  `amount` decimal(16,4) DEFAULT NULL,
  `created_at` date DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `modified_at` datetime DEFAULT NULL,
  `modified_by` int(11) DEFAULT NULL,
  `paid` tinyint(1) DEFAULT 0,
  UNIQUE KEY `unique_transaction` (`request_id`,`transc_id`) USING BTREE,
  CONSTRAINT `fk_request` FOREIGN KEY (`request_id`) REFERENCES `payment_request` (`request_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `permissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
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
/*!40101 SET character_set_client = utf8mb4 */;
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
DROP TABLE IF EXISTS `pickup_place`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `pickup_place` (
  `pick_id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `address1` varchar(255) DEFAULT NULL,
  `address2` varchar(255) DEFAULT NULL,
  `city` varchar(25) DEFAULT NULL,
  `state` varchar(25) DEFAULT NULL,
  `country` varchar(25) DEFAULT NULL,
  `postal_code` varchar(25) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `modified_by` int(11) DEFAULT NULL,
  `created_at` date DEFAULT NULL,
  `modified_at` date DEFAULT NULL,
  `latitud` decimal(9,6) DEFAULT NULL,
  `longitud` decimal(9,6) DEFAULT NULL,
  PRIMARY KEY (`pick_id`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `provider`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `provider` (
  `provider_id` int(11) NOT NULL AUTO_INCREMENT,
  `rfc` varchar(25) DEFAULT NULL,
  `account_id` int(11) DEFAULT NULL,
  `fullName` varchar(100) DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `state` varchar(25) DEFAULT NULL,
  `city` varchar(25) DEFAULT NULL,
  `email` varchar(50) DEFAULT NULL,
  `postal_code` varchar(25) DEFAULT NULL,
  `phone` int(11) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `modified_by` int(11) DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `modified_at` datetime DEFAULT NULL,
  `password` varchar(64) DEFAULT NULL,
  `auth_key` varchar(128) DEFAULT NULL,
  `password_reset_token` varchar(64) DEFAULT NULL,
  `verification_code` varchar(250) NOT NULL DEFAULT '',
  `dismiss_route` tinyint(1) DEFAULT NULL,
  `type_id` tinyint(4) DEFAULT NULL,
  `price_type` tinyint(4) DEFAULT NULL,
  `match_pickup_place` tinyint(1) DEFAULT 0,
  PRIMARY KEY (`provider_id`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `providers_by_booking`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `providers_by_booking` (
  `prov_by_booking_id` int(11) NOT NULL AUTO_INCREMENT,
  `booking` int(11) DEFAULT NULL,
  `provider` int(11) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `modified_by` int(11) DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `modified_at` datetime DEFAULT NULL,
  PRIMARY KEY (`prov_by_booking_id`) USING BTREE,
  KEY `fk_booking_id` (`booking`) USING BTREE,
  KEY `fk_provider_id_idx` (`provider`) USING BTREE,
  CONSTRAINT `fk_booking_id` FOREIGN KEY (`booking`) REFERENCES `booking` (`booking_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_provid_id` FOREIGN KEY (`provider`) REFERENCES `provider` (`provider_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `refaccion`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `refaccion` (
  `refaccion_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `codigo` varchar(40) NOT NULL,
  `nombre` varchar(120) NOT NULL,
  `categoria` varchar(40) DEFAULT NULL,
  `medida` varchar(20) DEFAULT NULL,
  `ubicacion` varchar(40) DEFAULT NULL,
  `existencia` decimal(12,2) NOT NULL DEFAULT 0.00,
  `minimo` decimal(12,2) NOT NULL DEFAULT 0.00,
  `costo` decimal(12,4) NOT NULL DEFAULT 0.0000,
  `activo` tinyint(1) NOT NULL DEFAULT 1,
  `notas` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`refaccion_id`),
  UNIQUE KEY `refaccion_codigo_unique` (`codigo`),
  KEY `refaccion_activo_nombre_index` (`activo`,`nombre`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `role_has_permissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
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
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `roles` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `guard_name` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `roles_name_guard_name_unique` (`name`,`guard_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `service`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `service` (
  `service_id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(20) DEFAULT NULL,
  `price` decimal(16,4) DEFAULT NULL,
  `account_id` int(11) DEFAULT NULL,
  `description` varchar(255) DEFAULT NULL,
  `start_date` date DEFAULT NULL,
  `end_date` date DEFAULT NULL,
  `charge_type_id` int(11) DEFAULT NULL,
  `provider_id` int(11) DEFAULT NULL,
  `created_at` datetime DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `modified_at` datetime DEFAULT NULL,
  `modified_by` int(11) DEFAULT NULL,
  `active` tinyint(1) DEFAULT 1,
  `client_id` int(11) DEFAULT NULL,
  `type` int(11) DEFAULT NULL,
  `max` decimal(16,4) DEFAULT NULL,
  `min` decimal(16,4) DEFAULT NULL,
  `loading_port_id` int(11) DEFAULT NULL,
  `dicharge_port_id` int(11) DEFAULT NULL,
  `pickup_place_id` int(11) DEFAULT NULL,
  `auto_include` tinyint(1) DEFAULT NULL,
  `price_type` tinyint(4) DEFAULT NULL,
  `final_destination_id` int(11) DEFAULT NULL,
  `container_type_id` int(11) DEFAULT NULL,
  `contract` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`service_id`) USING BTREE,
  KEY `fk_srv_provider` (`provider_id`) USING BTREE,
  CONSTRAINT `fk_srv_provider` FOREIGN KEY (`provider_id`) REFERENCES `provider` (`provider_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `session`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `session` (
  `id` char(40) NOT NULL,
  `expire` int(11) DEFAULT NULL,
  `data` blob DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `solicitud_demo`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `solicitud_demo` (
  `solicitud_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `nombre` varchar(100) NOT NULL,
  `empresa` varchar(100) DEFAULT NULL,
  `correo` varchar(120) NOT NULL,
  `telefono` varchar(40) DEFAULT NULL,
  `mensaje` varchar(500) DEFAULT NULL,
  `origen` varchar(60) DEFAULT NULL,
  `atendida` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`solicitud_id`),
  KEY `solicitud_demo_created_at_index` (`created_at`),
  KEY `solicitud_demo_atendida_index` (`atendida`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `tax_code`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `tax_code` (
  `tax_code_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `tax_code` varchar(255) DEFAULT NULL,
  `tax_rate` decimal(6,2) DEFAULT NULL,
  `tax_retention` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`tax_code_id`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `transaction`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `transaction` (
  `transc_id` int(11) NOT NULL AUTO_INCREMENT,
  `tran_date` date DEFAULT NULL,
  `tran_number` varchar(128) DEFAULT NULL,
  `account` int(11) DEFAULT NULL,
  `company_id` int(11) DEFAULT NULL,
  `booking` int(11) DEFAULT NULL,
  `vendor` int(10) unsigned DEFAULT NULL,
  `xml_attach` varchar(255) DEFAULT NULL,
  `pdf_attach` varchar(255) NOT NULL DEFAULT '',
  `bill_address` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `modified_at` datetime DEFAULT NULL,
  `modified_by` int(11) DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `payment_terms` int(11) DEFAULT NULL,
  `tran_type` int(11) DEFAULT NULL,
  `open` tinyint(3) unsigned DEFAULT 1,
  `customer` int(11) DEFAULT NULL,
  `active` tinyint(1) DEFAULT 1,
  `seal` varchar(128) DEFAULT NULL,
  `invoice` int(11) DEFAULT NULL,
  `paid` tinyint(1) unsigned zerofill DEFAULT 0,
  `cancelled` tinyint(1) unsigned zerofill DEFAULT 0,
  `payment_request` tinyint(1) DEFAULT 0,
  `request_at` datetime DEFAULT NULL,
  `paid_at` datetime DEFAULT NULL,
  `request_id` int(11) DEFAULT NULL,
  `bank_id` int(11) DEFAULT NULL,
  `paid_amount` decimal(18,4) DEFAULT NULL,
  `invoice_type` int(11) DEFAULT 1,
  `processed` tinyint(1) DEFAULT NULL,
  `cancel_reason_id` varchar(2) DEFAULT NULL,
  `new_seal` varchar(128) DEFAULT NULL,
  `custom_tc` decimal(10,4) DEFAULT NULL,
  PRIMARY KEY (`transc_id`,`pdf_attach`) USING BTREE,
  KEY `fk_tran_vendor` (`vendor`) USING BTREE,
  KEY `fk_tran_booking` (`booking`) USING BTREE,
  KEY `transc_id` (`transc_id`),
  CONSTRAINT `fk_tran_booking` FOREIGN KEY (`booking`) REFERENCES `booking` (`booking_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `unidad`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `unidad` (
  `unidad_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `numero` varchar(30) NOT NULL,
  `tipo` varchar(20) NOT NULL DEFAULT 'tractor',
  `placas` varchar(20) DEFAULT NULL,
  `marca` varchar(40) DEFAULT NULL,
  `modelo` varchar(40) DEFAULT NULL,
  `anio` varchar(4) DEFAULT NULL,
  `serie` varchar(40) DEFAULT NULL,
  `permiso_sct` varchar(40) DEFAULT NULL,
  `seguro_vence` date DEFAULT NULL,
  `verificacion_vence` date DEFAULT NULL,
  `kilometraje` int(10) unsigned DEFAULT NULL,
  `servicio_cada_km` int(10) unsigned DEFAULT NULL,
  `ultimo_servicio_km` int(10) unsigned DEFAULT NULL,
  `ultimo_servicio` date DEFAULT NULL,
  `activo` tinyint(1) NOT NULL DEFAULT 1,
  `notas` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`unidad_id`),
  KEY `unidad_activo_numero_index` (`activo`,`numero`),
  KEY `unidad_seguro_vence_index` (`seguro_vence`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `user_preferences`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `user_preferences` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `usr_id` int(10) unsigned NOT NULL,
  `theme` varchar(10) NOT NULL DEFAULT 'system',
  `locale` varchar(5) DEFAULT NULL,
  `settings` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`settings`)),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `user_preferences_usr_id_unique` (`usr_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `users` (
  `usr_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(100) DEFAULT NULL,
  `email` varchar(45) DEFAULT NULL,
  `username` varchar(45) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci DEFAULT NULL,
  `password` varchar(128) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci DEFAULT NULL,
  `two_factor_secret` text DEFAULT NULL,
  `two_factor_recovery_codes` text DEFAULT NULL,
  `two_factor_confirmed_at` timestamp NULL DEFAULT NULL,
  `unit` varchar(255) DEFAULT NULL,
  `auth_key` varchar(128) DEFAULT NULL,
  `password_reset_token` varchar(64) CHARACTER SET utf8mb3 COLLATE utf8mb3_unicode_ci DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `modified_by` int(11) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `modified_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `last_login` datetime DEFAULT NULL,
  `role` tinyint(4) DEFAULT NULL,
  `access` tinyint(4) DEFAULT NULL,
  `client_id` int(11) DEFAULT NULL,
  `provider_id` int(11) DEFAULT NULL,
  `status` tinyint(1) DEFAULT 1,
  `remember_token` varchar(100) DEFAULT NULL,
  PRIMARY KEY (`usr_id`) USING BTREE,
  UNIQUE KEY `username` (`username`) USING BTREE,
  UNIQUE KEY `email` (`email`) USING BTREE,
  KEY `created_by` (`created_by`,`modified_by`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_uca1400_ai_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `valor_por_expediente`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `valor_por_expediente` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `booking` int(10) unsigned NOT NULL,
  `campo_id` int(10) unsigned NOT NULL,
  `valor` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `valor_por_expediente_booking_campo_id_unique` (`booking`,`campo_id`),
  KEY `valor_por_expediente_campo_id_index` (`campo_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `vessel`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `vessel` (
  `vessel_id` int(11) NOT NULL AUTO_INCREMENT,
  `vessel_name` varchar(100) DEFAULT NULL,
  PRIMARY KEY (`vessel_id`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb3 COLLATE=utf8mb3_unicode_ci ROW_FORMAT=DYNAMIC;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*M!100616 SET NOTE_VERBOSITY=@OLD_NOTE_VERBOSITY */;

/*M!999999\- enable the sandbox mode */ 
SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0;
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (1,'2026_07_23_000001_add_laravel_auth_columns_to_users_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (2,'2026_07_23_000002_create_password_reset_tokens_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (3,'2026_07_23_185357_create_permission_tables',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (4,'2026_07_23_185357_create_personal_access_tokens_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (5,'2026_08_24_000001_create_user_preferences_table',2);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (6,'2026_08_25_000001_add_locale_to_user_preferences',3);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (7,'2026_08_28_000001_create_hitos_tables',4);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (8,'2026_08_28_000002_create_campos_propios_expediente',5);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (9,'2026_08_28_000004_add_coordinates_to_places',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (10,'2026_08_28_000005_create_solicitud_demo',7);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (11,'2026_08_28_000006_create_flota_propia',8);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (12,'2026_08_28_000007_create_liquidacion_operadores',9);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (13,'2026_08_28_000008_create_configuracion',10);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (14,'2026_08_28_000009_create_gasto_viaje',11);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (15,'2026_08_28_000010_create_nomina',12);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (16,'2026_09_03_000001_create_taller',13);
COMMIT;
SET AUTOCOMMIT=@OLD_AUTOCOMMIT;
