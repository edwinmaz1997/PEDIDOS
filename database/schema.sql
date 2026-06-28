-- ============================================================
-- PEDIDOS SYSTEM - Database Schema
-- Version: 1.0
-- ============================================================

SET NAMES utf8mb4;
SET time_zone = '+00:00';
SET foreign_key_checks = 0;

-- ------------------------------------------------------------
-- ROLES
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS roles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) NOT NULL UNIQUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO roles (name) VALUES ('admin'), ('negocio'), ('cliente'), ('repartidor');

-- ------------------------------------------------------------
-- USERS
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    role_id INT NOT NULL,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(150) NOT NULL UNIQUE,
    phone VARCHAR(20),
    password_hash VARCHAR(255) NOT NULL,
    is_active TINYINT(1) DEFAULT 1,
    email_verified TINYINT(1) DEFAULT 0,
    last_login TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (role_id) REFERENCES roles(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- SESSIONS (server-side session tokens)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS user_sessions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    token VARCHAR(255) NOT NULL UNIQUE,
    ip_address VARCHAR(45),
    user_agent TEXT,
    expires_at TIMESTAMP NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- RATE LIMITING
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS rate_limits (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ip_address VARCHAR(45) NOT NULL,
    endpoint VARCHAR(100) NOT NULL,
    attempts INT DEFAULT 1,
    window_start TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ip_endpoint (ip_address, endpoint)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- BUSINESS CATEGORIES
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS business_categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL UNIQUE,
    icon VARCHAR(50),
    color VARCHAR(20),
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO business_categories (name, icon, color) VALUES
('Restaurantes', 'utensils', '#FF6B6B'),
('Farmacias', 'pills', '#4ECDC4'),
('Supermercados', 'shopping-cart', '#45B7D1'),
('Ferreterías', 'wrench', '#96CEB4'),
('Ropa y Accesorios', 'shirt', '#FFEAA7'),
('Tecnología', 'laptop', '#DDA0DD'),
('Belleza y Salud', 'heart', '#98D8C8'),
('Otros', 'store', '#B0B0B0');

-- ------------------------------------------------------------
-- BUSINESSES
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS businesses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    category_id INT NOT NULL,
    name VARCHAR(150) NOT NULL,
    slug VARCHAR(150) NOT NULL UNIQUE,
    description TEXT,
    what_we_offer TEXT,
    address TEXT,
    city VARCHAR(100),
    zone VARCHAR(50),
    phone VARCHAR(20),
    whatsapp VARCHAR(20),
    email VARCHAR(150),
    website VARCHAR(200),
    facebook_url VARCHAR(300) NULL,
    instagram_url VARCHAR(300) NULL,
    tiktok_url VARCHAR(300) NULL,
    whatsapp_url VARCHAR(300) NULL,
    latitude DECIMAL(10, 8),
    longitude DECIMAL(11, 8),
    google_maps_url TEXT,
    logo VARCHAR(255),
    cover_photo VARCHAR(255),
    opening_hours JSON,
    is_active TINYINT(1) DEFAULT 1,
    is_verified TINYINT(1) DEFAULT 0,
    accepts_delivery TINYINT(1) DEFAULT 0,
    delivery_fee DECIMAL(10,2) DEFAULT 15.00,
    service_fee DECIMAL(10,2) DEFAULT 5.00,
    rating DECIMAL(3,2) DEFAULT 0.00,
    total_reviews INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id),
    FOREIGN KEY (category_id) REFERENCES business_categories(id),
    INDEX idx_slug (slug),
    INDEX idx_category (category_id),
    FULLTEXT INDEX ft_search (name, description, what_we_offer)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- BUSINESS PHOTOS
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS business_photos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    business_id INT NOT NULL,
    photo_url VARCHAR(255) NOT NULL,
    caption VARCHAR(200),
    sort_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (business_id) REFERENCES businesses(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- PRODUCTS / SERVICES (for search/filter + ordering)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS product_categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    business_id INT NOT NULL,
    name VARCHAR(100) NOT NULL,
    icon VARCHAR(10) DEFAULT '🏷️',
    sort_order INT DEFAULT 0,
    FOREIGN KEY (business_id) REFERENCES businesses(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS products_services (
    id INT AUTO_INCREMENT PRIMARY KEY,
    business_id INT NOT NULL,
    name VARCHAR(150) NOT NULL,
    description TEXT,
    price DECIMAL(10,2),
    photo VARCHAR(255),
    category_id INT DEFAULT NULL,
    is_available TINYINT(1) DEFAULT 1,
    sort_order INT DEFAULT 0,
    has_variants TINYINT(1) DEFAULT 0,
    requires_boleta TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (business_id) REFERENCES businesses(id) ON DELETE CASCADE,
    FULLTEXT INDEX ft_product_search (name, description)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- PRODUCT VARIANTS
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS product_variants (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    name VARCHAR(100) NOT NULL COMMENT 'Ej: 12 oz, 16 oz, Grande',
    price DECIMAL(10,2) NOT NULL,
    is_available TINYINT(1) DEFAULT 1,
    sort_order INT DEFAULT 0,
    FOREIGN KEY (product_id) REFERENCES products_services(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- ORDERS
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_number VARCHAR(20) NOT NULL UNIQUE,
    client_id INT NOT NULL,
    business_id INT NOT NULL,
    delivery_type ENUM('pickup','delivery') DEFAULT 'pickup',
    delivery_address TEXT,
    notes TEXT,
    subtotal DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    service_fee DECIMAL(10,2) DEFAULT 5.00,
    delivery_fee DECIMAL(10,2) DEFAULT 0.00,
    total DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    status ENUM('pendiente','aceptado','en_preparacion','listo','en_camino','entregado','cancelado') DEFAULT 'pendiente',
    business_response TEXT,
    estimated_time INT COMMENT 'minutos estimados',
    accepted_at TIMESTAMP NULL COMMENT 'cuando el negocio aceptó el pedido',
    preparation_started_at TIMESTAMP NULL COMMENT 'cuando el negocio inició preparación',
    boleta_url VARCHAR(500) NULL COMMENT 'foto de boleta para pedidos de compras',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (client_id) REFERENCES users(id),
    FOREIGN KEY (business_id) REFERENCES businesses(id),
    INDEX idx_client (client_id),
    INDEX idx_business (business_id),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- ORDER ITEMS
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS order_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    product_id INT,
    product_name VARCHAR(150) NOT NULL,
    quantity INT NOT NULL DEFAULT 1,
    unit_price DECIMAL(10,2),
    notes TEXT,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products_services(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- ORDER STATUS LOG
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS order_status_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    status VARCHAR(50) NOT NULL,
    message TEXT,
    changed_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    FOREIGN KEY (changed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- DELIVERIES
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS deliveries (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL UNIQUE,
    repartidor_id INT,
    status ENUM('disponible','asignado','recogido','entregado') DEFAULT 'disponible',
    assigned_at TIMESTAMP NULL,
    picked_up_at TIMESTAMP NULL,
    delivered_at TIMESTAMP NULL,
    notes TEXT,
    FOREIGN KEY (order_id) REFERENCES orders(id),
    FOREIGN KEY (repartidor_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_repartidor (repartidor_id),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- REVIEWS
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS reviews (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    client_id INT NOT NULL,
    business_id INT NOT NULL,
    rating TINYINT NOT NULL CHECK (rating BETWEEN 1 AND 5),
    comment TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (order_id) REFERENCES orders(id),
    FOREIGN KEY (client_id) REFERENCES users(id),
    FOREIGN KEY (business_id) REFERENCES businesses(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- NOTIFICATIONS
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    type VARCHAR(50) NOT NULL,
    title VARCHAR(150) NOT NULL,
    message TEXT NOT NULL,
    data JSON,
    is_read TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_unread (user_id, is_read)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET foreign_key_checks = 1;

-- ------------------------------------------------------------
-- PASSWORD RESETS
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS password_resets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(150) NOT NULL,
    token VARCHAR(100) NOT NULL UNIQUE,
    expires_at TIMESTAMP NOT NULL,
    used TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_token (token),
    INDEX idx_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- ORDER MESSAGES (chat por pedido)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS order_messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    sender_id INT NOT NULL,
    sender_role ENUM('cliente','negocio','admin') NOT NULL,
    message TEXT NOT NULL,
    is_read TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    FOREIGN KEY (sender_id) REFERENCES users(id),
    INDEX idx_order (order_id),
    INDEX idx_unread (order_id, is_read)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- DELIVERY ZONES (per business)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS delivery_zones (
    id INT AUTO_INCREMENT PRIMARY KEY,
    business_id INT NOT NULL,
    name VARCHAR(100) NOT NULL,
    description TEXT,
    price DECIMAL(10,2) NOT NULL DEFAULT 15.00,
    is_active TINYINT(1) DEFAULT 1,
    sort_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (business_id) REFERENCES businesses(id) ON DELETE CASCADE,
    INDEX idx_business (business_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- DELIVERY ZONES (global, managed by admin)
-- ------------------------------------------------------------
DROP TABLE IF EXISTS delivery_zones;
CREATE TABLE IF NOT EXISTS delivery_zones (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    description TEXT,
    price DECIMAL(10,2) NOT NULL DEFAULT 15.00,
    is_active TINYINT(1) DEFAULT 1,
    sort_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Default zones
INSERT INTO delivery_zones (name, description, price, sort_order) VALUES
('Casco Urbano', 'Área central de la ciudad', 15.00, 1),
('Cruce', 'Zona de cruce / intersección principal', 20.00, 2),
('Reparto', 'Colonias y repartos', 25.00, 3),
('Centro 2', 'Zona centro secundaria', 40.00, 4);

-- Business zones coverage (which zones each business covers)
CREATE TABLE IF NOT EXISTS business_delivery_zones (
    id INT AUTO_INCREMENT PRIMARY KEY,
    business_id INT NOT NULL,
    zone_id INT NOT NULL,
    custom_price DECIMAL(10,2) DEFAULT NULL COMMENT 'NULL = use global zone price',
    UNIQUE KEY uq_biz_zone (business_id, zone_id),
    FOREIGN KEY (business_id) REFERENCES businesses(id) ON DELETE CASCADE,
    FOREIGN KEY (zone_id) REFERENCES delivery_zones(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Email verification codes
CREATE TABLE IF NOT EXISTS email_verifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(255) NOT NULL,
    code CHAR(6) NOT NULL,
    expires_at TIMESTAMP NOT NULL,
    used TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_email (email),
    INDEX idx_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Business type (pedidos / servicios)
ALTER TABLE businesses ADD COLUMN IF NOT EXISTS business_type ENUM('pedidos','servicios') DEFAULT 'pedidos' AFTER category_id;

-- Services offered by service-type businesses
CREATE TABLE IF NOT EXISTS business_services (
    id INT AUTO_INCREMENT PRIMARY KEY,
    business_id INT NOT NULL,
    name VARCHAR(150) NOT NULL,
    description TEXT,
    photo_url VARCHAR(500) NULL,
    price_from DECIMAL(10,2) DEFAULT NULL COMMENT 'Precio referencial desde',
    price_to DECIMAL(10,2) DEFAULT NULL COMMENT 'Precio referencial hasta',
    is_available TINYINT(1) DEFAULT 1,
    sort_order INT DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (business_id) REFERENCES businesses(id) ON DELETE CASCADE,
    INDEX idx_business (business_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Agregar tipo delivery al ENUM de business_type
ALTER TABLE businesses MODIFY COLUMN business_type ENUM('pedidos','servicios','delivery') DEFAULT 'pedidos';

-- ------------------------------------------------------------
-- PROMOTIONS
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS promotions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    business_id INT NOT NULL,
    title VARCHAR(150) NOT NULL,
    description TEXT NOT NULL,
    discount_type ENUM('percentage','fixed') DEFAULT 'fixed',
    starts_at DATE NOT NULL,
    ends_at DATE NOT NULL,
    is_active TINYINT(1) DEFAULT 1,
    image_url VARCHAR(500) NULL,
    notified_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (business_id) REFERENCES businesses(id) ON DELETE CASCADE,
    INDEX idx_business (business_id),
    INDEX idx_dates (starts_at, ends_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS promotion_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    promotion_id INT NOT NULL,
    product_id INT,
    product_name VARCHAR(150) NOT NULL,
    original_price DECIMAL(10,2) NOT NULL,
    promo_price DECIMAL(10,2) NOT NULL,
    FOREIGN KEY (promotion_id) REFERENCES promotions(id) ON DELETE CASCADE,
    FOREIGN KEY (product_id) REFERENCES products_services(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
