<?php
/**
 * Webhook обработчик для ЕРИП
 * Получает уведомления о статусе платежей от ЕРИП
 */

// Подключаем конфигурацию
require_once 'payment_config.php';

// Логируем входящий webhook
error_log("ERIP Webhook received: " . file_get_contents('php://input'));

// Получаем данные webhook
$webhookData = json_decode(file_get_contents('php://input'), true);

if (!$webhookData) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON']);
    exit;
}

// Подключаем ЕРИП API
require_once 'erip_api.php';

try {
    $erip = new EripAPI();
    
    // Обрабатываем webhook
    $result = $erip->handleWebhook($webhookData);
    
    if ($result) {
        http_response_code(200);
        echo json_encode(['success' => true]);
    } else {
        http_response_code(400);
        echo json_encode(['error' => 'Webhook processing failed']);
    }
} catch (Exception $e) {
    error_log("ERIP Webhook Error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Internal server error']);
}
?> 