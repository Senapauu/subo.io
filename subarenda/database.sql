-- База данных для СубарендаМинск
CREATE DATABASE IF NOT EXISTS subarenda_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE subarenda_db;

-- Таблица пользователей
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(255) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    name VARCHAR(255) NOT NULL,
    phone VARCHAR(20),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Таблица квартир
CREATE TABLE apartments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    address VARCHAR(255) NOT NULL,
    district VARCHAR(100) NOT NULL,
    rooms INT NOT NULL,
    price DECIMAL(10,2) NOT NULL,
    description TEXT,
    images TEXT, -- JSON массив изображений
    status ENUM('available', 'booked') DEFAULT 'available',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Таблица бронирований
CREATE TABLE bookings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    apartment_id INT NOT NULL,
    user_id INT,
    name VARCHAR(255) NOT NULL,
    phone VARCHAR(20) NOT NULL,
    email VARCHAR(255),
    check_in DATE NOT NULL,
    check_out DATE NOT NULL,
    nights INT NOT NULL,
    guests INT DEFAULT 1,
    total_amount DECIMAL(10,2) NOT NULL,
    message TEXT,
    status ENUM('pending', 'confirmed', 'cancelled') DEFAULT 'pending',
    payment_status ENUM('pending', 'paid', 'failed') DEFAULT 'pending',
    payment_method VARCHAR(50),
    payment_id INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (apartment_id) REFERENCES apartments(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
);

-- Таблица платежей
CREATE TABLE payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    booking_id INT NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    currency VARCHAR(3) DEFAULT 'BYN',
    method ENUM('webpay', 'bank-transfer', 'erip') NOT NULL,
    status ENUM('pending', 'completed', 'failed') DEFAULT 'pending',
    transaction_id VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE CASCADE
);

-- Таблица настроек админ-панели
CREATE TABLE settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(100) UNIQUE NOT NULL,
    setting_value TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Вставка начальных данных
INSERT INTO apartments (title, address, district, rooms, price, description, images) VALUES
('Уютная 2-комнатная в центре', 'ул. Ленина, 15', 'Центральный', 2, 100.00, 'Современная квартира в центре города с отличным ремонтом', '["apartment1.jpg", "apartment1-2.jpg", "apartment1-3.jpg"]'),
('Светлая 1-комнатная у метро', 'пр. Независимости, 45', 'Московский', 1, 80.00, 'Удобная квартира рядом с метро и торговым центром', '["apartment2.jpg", "apartment2-2.jpg"]'),
('Просторная 3-комнатная с видом', 'ул. Калиновского, 78', 'Фрунзенский', 3, 150.00, 'Большая квартира с панорамными окнами и современной техникой', '["apartment3.jpg", "apartment3-2.jpg", "apartment3-3.jpg"]'),
('Компактная студия в тихом районе', 'ул. Богдановича, 12', 'Ленинский', 1, 60.00, 'Уютная студия для комфортного проживания', '["apartment4.jpg"]'),
('Двухуровневая квартира в новостройке', 'ул. Притыцкого, 156', 'Партизанский', 2, 120.00, 'Современная двухуровневая квартира с дизайнерским ремонтом', '["apartment5.jpg", "apartment5-2.jpg"]');

-- Вставка настроек по умолчанию
INSERT INTO settings (setting_key, setting_value) VALUES
('admin_password', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi'), -- password
('contact_phone', '+375 (25) 726-25-93'),
('commission_percent', '15'),
('erip_service_code', '1234567'),
('bank_account', 'BY12VTBB30120000000000000000'),
('bank_name', 'ОАО "Банк ВТБ (Беларусь)"'),
('company_name', 'ООО "СубарендаМинск"');

-- Создание индексов для оптимизации
CREATE INDEX idx_apartments_status ON apartments(status);
CREATE INDEX idx_apartments_district ON apartments(district);
CREATE INDEX idx_bookings_status ON bookings(status);
CREATE INDEX idx_bookings_payment_status ON bookings(payment_status);
CREATE INDEX idx_payments_status ON payments(status);
CREATE INDEX idx_payments_method ON payments(method); 