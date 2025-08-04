<?php
/**
 * Система уведомлений для платежей
 * Отправка SMS и Email уведомлений
 */

class PaymentNotification {
    private $db;
    
    public function __construct($db) {
        $this->db = $db;
    }
    
    /**
     * Отправить уведомление о создании платежа
     */
    public function sendPaymentCreatedNotification($paymentId) {
        try {
            // Получаем данные платежа
            $stmt = $this->db->prepare("
                SELECT p.*, b.name, b.phone, b.email, a.title as apartment_title
                FROM payments p
                JOIN bookings b ON p.booking_id = b.id
                JOIN apartments a ON b.apartment_id = a.id
                WHERE p.id = ?
            ");
            $stmt->execute([$paymentId]);
            $payment = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$payment) {
                throw new Exception('Платеж не найден');
            }
            
            // Отправляем уведомление клиенту
            $this->sendClientNotification($payment);
            
            // Отправляем уведомление администратору
            $this->sendAdminNotification($payment);
            
            return true;
        } catch (Exception $e) {
            error_log("Ошибка отправки уведомления о платеже: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Отправить уведомление о подтверждении платежа
     */
    public function sendPaymentConfirmedNotification($paymentId) {
        try {
            // Получаем данные платежа
            $stmt = $this->db->prepare("
                SELECT p.*, b.name, b.phone, b.email, a.title as apartment_title
                FROM payments p
                JOIN bookings b ON p.booking_id = b.id
                JOIN apartments a ON b.apartment_id = a.id
                WHERE p.id = ?
            ");
            $stmt->execute([$paymentId]);
            $payment = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$payment) {
                throw new Exception('Платеж не найден');
            }
            
            // Отправляем уведомление клиенту
            $this->sendClientConfirmationNotification($payment);
            
            return true;
        } catch (Exception $e) {
            error_log("Ошибка отправки уведомления о подтверждении платежа: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Отправить уведомление клиенту о создании платежа
     */
    private function sendClientNotification($payment) {
        $subject = "Платеж создан - СубарендаМинск";
        $message = "
            Здравствуйте, {$payment['name']}!
            
            Ваш платеж был успешно создан.
            
            Детали платежа:
            - Номер бронирования: {$payment['booking_id']}
            - Квартира: {$payment['apartment_title']}
            - Сумма: {$payment['amount']} {$payment['currency']}
            - Способ оплаты: " . $this->getMethodText($payment['method']) . "
            - Статус: Ожидает подтверждения
            
            Мы проверим ваш платеж в течение 1-2 часов и уведомим вас о результате.
            
            С уважением,
            Команда СубарендаМинск
        ";
        
        // Отправляем Email
        if ($payment['email']) {
            $this->sendEmail($payment['email'], $subject, $message);
        }
        
        // Отправляем SMS
        if ($payment['phone']) {
            $this->sendSMS($payment['phone'], "Платеж создан. Сумма: {$payment['amount']} {$payment['currency']}. Ожидайте подтверждения.");
        }
    }
    
    /**
     * Отправить уведомление клиенту о подтверждении платежа
     */
    private function sendClientConfirmationNotification($payment) {
        $subject = "Платеж подтвержден - СубарендаМинск";
        $message = "
            Здравствуйте, {$payment['name']}!
            
            Ваш платеж был успешно подтвержден!
            
            Детали платежа:
            - Номер бронирования: {$payment['booking_id']}
            - Квартира: {$payment['apartment_title']}
            - Сумма: {$payment['amount']} {$payment['currency']}
            - Способ оплаты: " . $this->getMethodText($payment['method']) . "
            - Статус: Подтвержден
            
            Ваше бронирование активно. Приятного отдыха!
            
            С уважением,
            Команда СубарендаМинск
        ";
        
        // Отправляем Email
        if ($payment['email']) {
            $this->sendEmail($payment['email'], $subject, $message);
        }
        
        // Отправляем SMS
        if ($payment['phone']) {
            $this->sendSMS($payment['phone'], "Платеж подтвержден! Бронирование активно. Приятного отдыха!");
        }
    }
    
    /**
     * Отправить уведомление администратору
     */
    private function sendAdminNotification($payment) {
        $subject = "Новый платеж - СубарендаМинск";
        $message = "
            Новый платеж создан:
            
            - ID платежа: {$payment['id']}
            - Номер бронирования: {$payment['booking_id']}
            - Клиент: {$payment['name']}
            - Телефон: {$payment['phone']}
            - Email: {$payment['email']}
            - Квартира: {$payment['apartment_title']}
            - Сумма: {$payment['amount']} {$payment['currency']}
            - Способ оплаты: " . $this->getMethodText($payment['method']) . "
            - Дата создания: " . date('d.m.Y H:i', strtotime($payment['created_at'])) . "
            
            Требуется проверка платежа.
        ";
        
        // Отправляем Email администратору
        $adminEmail = 'admin@subarendaminsk.by'; // Замените на реальный email
        $this->sendEmail($adminEmail, $subject, $message);
    }
    
    /**
     * Отправить Email
     */
    private function sendEmail($to, $subject, $message) {
        $headers = "From: noreply@subarendaminsk.by\r\n";
        $headers .= "Reply-To: noreply@subarendaminsk.by\r\n";
        $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
        
        mail($to, $subject, $message, $headers);
    }
    
    /**
     * Отправить SMS (отключено - SMS функциональность удалена)
     */
    private function sendSMS($phone, $message) {
        // SMS функциональность отключена
        // Для включения SMS уведомлений нужно настроить SMS API
        error_log("SMS notification would be sent to {$phone}: {$message}");
    }
    
    /**
     * Получить текст способа оплаты
     */
    private function getMethodText($method) {
        switch ($method) {
            case 'bank-transfer':
                return 'Банковский перевод';
            case 'erip':
                return 'ЕРИП';
            case 'card':
                return 'Онлайн-платеж';
            default:
                return 'Неизвестно';
        }
    }
}
?> 