<?php
// Конфигурация платежных систем

// Определяем режим тестирования
$test_mode = true; // true для тестирования, false для продакшена

// ERIP API настройки
if (!defined('ERIP_TEST_MODE')) {
    define('ERIP_TEST_MODE', $test_mode);
}

if (!defined('ERIP_API_URL')) {
    define('ERIP_API_URL', $test_mode ? 'https://test-api.erip.by' : 'https://api.erip.by');
}

if (!defined('ERIP_MERCHANT_ID')) {
    define('ERIP_MERCHANT_ID', $test_mode ? 'test-merchant-id' : 'your-merchant-id');
}

if (!defined('ERIP_SECRET_KEY')) {
    define('ERIP_SECRET_KEY', $test_mode ? 'test-secret-key' : 'your-secret-key');
}

// Belkart API настройки
if (!defined('BELKART_TEST_MODE')) {
    define('BELKART_TEST_MODE', $test_mode);
}

if (!defined('BELKART_API_URL')) {
    define('BELKART_API_URL', $test_mode ? 'https://test-api.belkart.by' : 'https://api.belkart.by');
}

if (!defined('BELKART_MERCHANT_ID')) {
    define('BELKART_MERCHANT_ID', $test_mode ? 'test-belkart-merchant-id' : 'your-belkart-merchant-id');
}

if (!defined('BELKART_SECRET_KEY')) {
    define('BELKART_SECRET_KEY', $test_mode ? 'test-belkart-secret-key' : 'your-belkart-secret-key');
}

// Банковские реквизиты
if (!defined('BANK_NAME')) {
    define('BANK_NAME', 'ОАО "Банк"');
}

if (!defined('BANK_ACCOUNT')) {
    define('BANK_ACCOUNT', 'BY12ABCD12345678901234567890');
}

if (!defined('BANK_CODE')) {
    define('BANK_CODE', 'ABCD1234');
}

if (!defined('RECIPIENT_NAME')) {
    define('RECIPIENT_NAME', 'ИП Иванов И.И.');
}

if (!defined('RECIPIENT_UNP')) {
    define('RECIPIENT_UNP', '123456789');
}

// Уведомления
if (!defined('ADMIN_EMAIL')) {
    define('ADMIN_EMAIL', 'admin@your-domain.com');
}

if (!defined('ADMIN_PHONE')) {
    define('ADMIN_PHONE', '+375291234567');
}

// SMS API настройки
// ============================================
// ИНСТРУКЦИЯ ПО ПОЛУЧЕНИЮ SMS API:
// 1. SMSC.ru (рекомендуется): https://smsc.ru
//    - Зарегистрируйтесь на сайте
//    - В личном кабинете → API → Получите ключ
//    - Пополните баланс (минимум 100-200 рублей)
//    - API ключ выглядит как: "1234567890abcdef"
//
// 2. Beeline API: https://beeline.by/business/solutions/sms-api/
//    - Обратитесь в Beeline Business
//    - После регистрации получите API ключ
//
// 3. MTS API: https://www.mts.by/business/solutions/sms/
//    - Обратитесь в MTS Business
//    - После регистрации получите API ключ
//
// 4. A1 API: https://www.a1.by/ru/business/solutions/sms-api
//    - Обратитесь в A1 Business
//    - После регистрации получите API ключ
// ============================================

if (!defined('SMS_API_KEY')) {
    // Замените на ваш реальный API ключ
    define('SMS_API_KEY', $test_mode ? 'test-api-key-123456' : 'your-real-sms-api-key');
}

if (!defined('SMS_PROVIDER')) {
    // Доступные провайдеры: smsc, beeline, mts, a1
    define('SMS_PROVIDER', 'smsc'); // smsc, beeline, mts, a1
}

if (!defined('SMS_SENDER')) {
    // Имя отправителя SMS (должно быть одобрено провайдером)
    define('SMS_SENDER', 'SUBRENT'); // Имя отправителя SMS
}

if (!defined('SMS_TEST_MODE')) {
    define('SMS_TEST_MODE', $test_mode);
}

// Безопасность
if (!defined('WEBHOOK_SECRET')) {
    define('WEBHOOK_SECRET', 'your-webhook-secret-key');
}

if (!defined('SSL_VERIFY')) {
    define('SSL_VERIFY', true);
}

// Логирование
if (!defined('PAYMENT_LOG_FILE')) {
    define('PAYMENT_LOG_FILE', 'logs/payment.log');
}

if (!defined('ERROR_LOG_FILE')) {
    define('ERROR_LOG_FILE', 'logs/error.log');
}
?> 