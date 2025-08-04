<?php
// Конфигурация для хостинга
// Скопируйте этот файл как config.php и измените настройки

// Устанавливаем кодировку
mb_internal_encoding('UTF-8');
mb_http_output('UTF-8');

// Отключаем вывод ошибок для продакшена
error_reporting(0);
ini_set('display_errors', 0);

// ========================================
// НАСТРОЙКИ БАЗЫ ДАННЫХ
// ========================================
// Получите эти данные у хостинг-провайдера
define('DB_HOST', 'localhost'); // Обычно localhost
define('DB_NAME', 'ВАША_БАЗА_ДАННЫХ'); // Имя вашей базы данных
define('DB_USER', 'ВАШ_ПОЛЬЗОВАТЕЛЬ'); // Пользователь базы данных
define('DB_PASS', 'ВАШ_ПАРОЛЬ'); // Пароль базы данных

// ========================================
// НАСТРОЙКИ САЙТА
// ========================================
// Замените на ваш домен
define('SITE_URL', 'https://ВАШ_ДОМЕН.by'); // Ваш домен с https
define('UPLOAD_PATH', 'uploads/');
define('MAX_FILE_SIZE', 5 * 1024 * 1024); // 5MB

// ========================================
// НАСТРОЙКИ БЕЗОПАСНОСТИ
// ========================================
define('SESSION_LIFETIME', 3600); // 1 час
define('PASSWORD_SALT', 'subarenda2024'); // Измените на уникальную строку

// ========================================
// НАСТРОЙКИ ПЛАТЕЖЕЙ
// ========================================
// ERIP настройки (получите у Национального банка)
define('ERIP_API_URL', 'https://api.erip.by'); // URL API ERIP
define('ERIP_MERCHANT_ID', 'ВАШ_MERCHANT_ID'); // Ваш Merchant ID
define('ERIP_SECRET_KEY', 'ВАШ_SECRET_KEY'); // Ваш Secret Key

// Belkart настройки (получите у банка)
define('BELKART_API_URL', 'https://api.belkart.by'); // URL API Belkart
define('BELKART_MERCHANT_ID', 'ВАШ_MERCHANT_ID'); // Ваш Merchant ID
define('BELKART_SECRET_KEY', 'ВАШ_SECRET_KEY'); // Ваш Secret Key

// ========================================
// НАСТРОЙКИ УВЕДОМЛЕНИЙ
// ========================================
define('ADMIN_EMAIL', 'admin@ВАШ_ДОМЕН.by'); // Email администратора
define('SUPPORT_EMAIL', 'support@ВАШ_ДОМЕН.by'); // Email поддержки

// ========================================
// ФУНКЦИИ (НЕ ИЗМЕНЯТЬ)
// ========================================

// Функция подключения к базе данных
function getDB() {
    static $pdo = null;
    
    if ($pdo === null) {
        try {
            $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4";
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false
            ]);
            
            // Устанавливаем кодировку для соединения
            $pdo->exec("SET NAMES utf8mb4");
            $pdo->exec("SET CHARACTER SET utf8mb4");
            $pdo->exec("SET character_set_connection=utf8mb4");
            $pdo->exec("SET character_set_client=utf8mb4");
            $pdo->exec("SET character_set_results=utf8mb4");
            $pdo->exec("SET collation_connection=utf8mb4_unicode_ci");
        } catch (PDOException $e) {
            die("Ошибка подключения к базе данных: " . $e->getMessage());
        }
    }
    
    return $pdo;
}

// Функция для безопасного вывода данных
function escape($data) {
    return htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
}

// Функция для генерации CSRF токена
function generateCSRFToken() {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

// Функция для проверки CSRF токена
function verifyCSRFToken($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

// Функция для хеширования паролей
function hashPassword($password) {
    return password_hash($password . PASSWORD_SALT, PASSWORD_DEFAULT);
}

// Функция для проверки пароля
function verifyPassword($password, $hash) {
    return password_verify($password . PASSWORD_SALT, $hash);
}

// Функция для получения настроек
function getSetting($key, $default = null) {
    $db = getDB();
    $stmt = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
    $stmt->execute([$key]);
    $result = $stmt->fetch();
    
    return $result ? $result['setting_value'] : $default;
}

// Функция для установки настроек
function setSetting($key, $value) {
    $db = getDB();
    $stmt = $db->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) 
                          ON DUPLICATE KEY UPDATE setting_value = ?");
    $stmt->execute([$key, $value, $value]);
}

// Инициализация сессии
session_start();

// Установка времени жизни сессии
ini_set('session.gc_maxlifetime', SESSION_LIFETIME);
session_set_cookie_params(SESSION_LIFETIME);
?> 