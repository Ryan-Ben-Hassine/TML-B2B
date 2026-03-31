CREATE TABLE IF NOT EXISTS `users` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `username` VARCHAR(255) NOT NULL,
  `password` VARCHAR(255) NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS `admin_sessions` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT NOT NULL,
  `token` VARCHAR(128) NOT NULL UNIQUE,
  `expires_at` DATETIME NOT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS `settings` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `site_name` VARCHAR(255) DEFAULT '3D Print Shop',
  `hero_title` VARCHAR(255) DEFAULT 'Impressions 3D premium livrées',
  `hero_subtitle` VARCHAR(255) DEFAULT 'Des modèles de qualité livrés chez vous.',
  `tml_api_key` VARCHAR(500) DEFAULT '',
  `order_flow` VARCHAR(20) DEFAULT 'auto',
  `admin_slug` VARCHAR(100) DEFAULT 'manage',
  `collect_email` TINYINT(1) DEFAULT 0,
  `primary_color` VARCHAR(20) DEFAULT '#007BFF',
  `thanks_message` TEXT,
  `logo_url` VARCHAR(500) DEFAULT '',
  `favicon_url` VARCHAR(500) DEFAULT '',
  `currency` VARCHAR(20) DEFAULT 'TND',
  `delivery_fee` DECIMAL(10,2) DEFAULT 0.00,
  `mobile_access_key` VARCHAR(255) DEFAULT NULL,
  `home_section_title` VARCHAR(255) DEFAULT 'Nos produits',
  `home_cta_label` VARCHAR(255) DEFAULT 'Découvrir la collection',
  `home_search_placeholder` VARCHAR(255) DEFAULT 'Rechercher des produits...',
  `home_empty_label` VARCHAR(255) DEFAULT 'Aucun produit trouvé.',
  `home_all_label` VARCHAR(50) DEFAULT 'Tous',
  `home_sort_featured_label` VARCHAR(100) DEFAULT 'En vedette',
  `home_sort_low_label` VARCHAR(100) DEFAULT 'Prix : croissant',
  `home_sort_high_label` VARCHAR(100) DEFAULT 'Prix : décroissant',
  `home_view_details_label` VARCHAR(100) DEFAULT 'Voir détails',
  `feature1_title` VARCHAR(255) DEFAULT 'Impression 3D experte',
  `feature1_subtitle` VARCHAR(255) DEFAULT 'Des modèles précis avec des matériaux premium',
  `feature2_title` VARCHAR(255) DEFAULT 'Livraison rapide',
  `feature2_subtitle` VARCHAR(255) DEFAULT 'Expédition fiable partout en Tunisie',
  `feature3_title` VARCHAR(255) DEFAULT 'Paiement sécurisé',
  `feature3_subtitle` VARCHAR(255) DEFAULT 'Commande manuelle ou synchronisation instantanée TML'
);

CREATE TABLE IF NOT EXISTS `categories` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `name` VARCHAR(255) NOT NULL
);

CREATE TABLE IF NOT EXISTS `hero_slides` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `image_url` VARCHAR(500),
  `title` VARCHAR(255),
  `subtitle` VARCHAR(255),
  `target_url` VARCHAR(255)
);

CREATE TABLE IF NOT EXISTS `products` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `title` VARCHAR(255) NOT NULL,
  `description` TEXT,
  `image_url` VARCHAR(500),
  `price` DECIMAL(10,2) NOT NULL,
  `model_id` INT NOT NULL DEFAULT 0,
  `tml_product_id` INT DEFAULT 0,
  `is_tml_product` TINYINT(1) DEFAULT 0,
  `material` VARCHAR(50) DEFAULT 'PLA',
  `quality` VARCHAR(50) DEFAULT 'Standard',
  `head_name` VARCHAR(100) DEFAULT 'Revo 0.4 Basic',
  `category_id` INT DEFAULT NULL,
  `colors` TEXT DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS `orders` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `product_id` INT,
  `name` VARCHAR(255),
  `email` VARCHAR(255),
  `phone` VARCHAR(50),
  `address` TEXT,
  `city_id` INT,
  `zone_id` INT,
  `selected_color` VARCHAR(100) DEFAULT NULL,
  `status` VARCHAR(50) DEFAULT 'Pending',
  `total_price` DECIMAL(10,2) DEFAULT NULL,
  `tml_order_id` VARCHAR(255) DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS `locations` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `city_id` INT NOT NULL,
  `city_name` VARCHAR(255) NOT NULL,
  `zone_id` INT NOT NULL,
  `zone_name` VARCHAR(255) NOT NULL
);

-- Insert default settings row if not exists
INSERT INTO `settings` (`id`, `site_name`) SELECT 1, '3D Print Shop' WHERE NOT EXISTS (SELECT 1 FROM `settings` WHERE `id` = 1);
