<?php
/**
 * Интеграция с ЕРИП API
 * Система "Расчет" - Единое расчетное и информационное пространство
 */

require_once 'payment_config.php';

class EripAPI {
    private $apiUrl;
    private $merchantId;
    private $secretKey;
    private $testMode;
    
    public function __construct($merchantId = null, $secretKey = null) {
        $this->apiUrl = ERIP_API_URL;
        $this->merchantId = $merchantId ?: ERIP_MERCHANT_ID;
        $this->secretKey = $secretKey ?: ERIP_SECRET_KEY;
        $this->testMode = ERIP_TEST_MODE;
    }
    
    /**
     * Создание платежа в ЕРИП
     */
    public function createPayment($paymentData) {
        $requestData = [
            'merchant_id' => $this->merchantId,
            'service_code' => $this->getServiceCode(),
            'amount' => $paymentData['amount'],
            'currency' => 'BYN',
            'payment_id' => $paymentData['payment_id'],
            'description' => 'Оплата бронирования №' . $paymentData['booking_id'],
            'return_url' => 'http://localhost/subarenda/payment-success.html',
            'cancel_url' => 'http://localhost/subarenda/payment-cancel.html',
            'notification_url' => 'http://localhost/subarenda/erip-webhook.php'
        ];
        
        // Подписываем запрос
        $requestData['signature'] = $this->generateSignature($requestData);
        
        try {
            $response = $this->sendRequest('/payment/create', $requestData);
            
            if ($response['success']) {
                return [
                    'success' => true,
                    'payment_url' => $response['payment_url'],
                    'qr_code' => $response['qr_code'] ?? null,
                    'payment_id' => $response['erip_payment_id']
                ];
            } else {
                throw new Exception($response['message'] ?? 'Ошибка создания платежа');
            }
        } catch (Exception $e) {
            error_log("ERIP API Error: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Ошибка подключения к ЕРИП: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Проверка статуса платежа
     */
    public function checkPaymentStatus($eripPaymentId) {
        $requestData = [
            'merchant_id' => $this->merchantId,
            'payment_id' => $eripPaymentId
        ];
        
        $requestData['signature'] = $this->generateSignature($requestData);
        
        try {
            $response = $this->sendRequest('/payment/status', $requestData);
            
            if ($response['success']) {
                return [
                    'success' => true,
                    'status' => $response['status'],
                    'amount' => $response['amount'],
                    'paid_at' => $response['paid_at'] ?? null
                ];
            } else {
                throw new Exception($response['message'] ?? 'Ошибка проверки статуса');
            }
        } catch (Exception $e) {
            error_log("ERIP Status Check Error: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Ошибка проверки статуса: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Обработка webhook от ЕРИП
     */
    public function handleWebhook($webhookData) {
        // Проверяем подпись webhook
        if (!$this->verifyWebhookSignature($webhookData)) {
            error_log("ERIP Webhook: Invalid signature");
            return false;
        }
        
        $paymentId = $webhookData['payment_id'];
        $status = $webhookData['status'];
        $amount = $webhookData['amount'];
        
        // Обновляем статус платежа в базе данных
        try {
            require_once 'config.php';
            $db = getDB();
            
            $stmt = $db->prepare("
                UPDATE payments 
                SET status = ?, updated_at = NOW() 
                WHERE erip_payment_id = ?
            ");
            
            $paymentStatus = ($status === 'completed') ? 'completed' : 'failed';
            $stmt->execute([$paymentStatus, $paymentId]);
            
            // Отправляем уведомление клиенту
            if ($paymentStatus === 'completed') {
                require_once 'payment_notifications.php';
                $notification = new PaymentNotification($db);
                $notification->sendPaymentConfirmedNotification($paymentId);
            }
            
            return true;
        } catch (Exception $e) {
            error_log("ERIP Webhook DB Error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Генерация подписи для API запросов
     */
    private function generateSignature($data) {
        // Убираем signature из данных для подписи
        unset($data['signature']);
        
        // Сортируем параметры по алфавиту
        ksort($data);
        
        // Создаем строку для подписи
        $signatureString = '';
        foreach ($data as $key => $value) {
            $signatureString .= $key . '=' . $value . '&';
        }
        $signatureString .= 'secret=' . $this->secretKey;
        
        return hash('sha256', $signatureString);
    }
    
    /**
     * Проверка подписи webhook
     */
    private function verifyWebhookSignature($webhookData) {
        $receivedSignature = $webhookData['signature'] ?? '';
        $calculatedSignature = $this->generateSignature($webhookData);
        
        return hash_equals($receivedSignature, $calculatedSignature);
    }
    
    /**
     * Отправка запроса к API
     */
    private function sendRequest($endpoint, $data) {
        $url = $this->apiUrl . $endpoint;
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Accept: application/json'
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($response === false) {
            throw new Exception('Ошибка подключения к API');
        }
        
        $responseData = json_decode($response, true);
        
        if ($httpCode !== 200) {
            throw new Exception('HTTP Error: ' . $httpCode);
        }
        
        return $responseData;
    }
    
    /**
     * Получение кода услуги ЕРИП
     */
    private function getServiceCode() {
        // В реальном проекте этот код нужно получить в ЕРИП
        return '123456789'; // Замените на реальный код услуги
    }
}

// Пример использования
if (isset($_POST['action'])) {
    $erip = new EripAPI('your_merchant_id', 'your_secret_key');
    
    switch ($_POST['action']) {
        case 'create_payment':
            $paymentData = [
                'payment_id' => $_POST['payment_id'],
                'booking_id' => $_POST['booking_id'],
                'amount' => $_POST['amount']
            ];
            
            $result = $erip->createPayment($paymentData);
            echo json_encode($result);
            break;
            
        case 'check_status':
            $eripPaymentId = $_POST['erip_payment_id'];
            $result = $erip->checkPaymentStatus($eripPaymentId);
            echo json_encode($result);
            break;
    }
}
?> 