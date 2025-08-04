<?php
// Устанавливаем кодировку
mb_internal_encoding('UTF-8');
mb_http_output('UTF-8');

require_once 'config.php';

// CORS заголовки для работы с AJAX
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json; charset=utf-8');

// Обработка preflight запросов
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Получаем метод запроса
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

try {
    $db = getDB();
    
    switch ($action) {
        case 'get_apartments':
            if ($method === 'GET') {
                $stmt = $db->query("SELECT * FROM apartments ORDER BY created_at DESC");
                $apartments = $stmt->fetchAll();
                
                // Преобразуем JSON строки в массивы
                foreach ($apartments as &$apartment) {
                    $apartment['images'] = json_decode($apartment['images'], true) ?: [];
                }
                
                echo json_encode(['success' => true, 'data' => $apartments]);
            }
            break;
            

            
        case 'create_booking':
            if ($method === 'POST') {
                $data = json_decode(file_get_contents('php://input'), true);
                
                // Логируем входящие данные
                error_log("CREATE_BOOKING: Получены данные: " . json_encode($data));
                
                // Валидация данных
                if (empty($data['apartment_id']) || empty($data['name']) || empty($data['phone']) || 
                    empty($data['check_in']) || empty($data['check_out'])) {
                    error_log("CREATE_BOOKING: Ошибка валидации - не все поля заполнены");
                    echo json_encode(['success' => false, 'message' => 'Заполните все обязательные поля']);
                    break;
                }
                
                // Проверяем формат дат
                $check_in = date('Y-m-d', strtotime($data['check_in']));
                $check_out = date('Y-m-d', strtotime($data['check_out']));
                
                if (!$check_in || !$check_out) {
                    echo json_encode(['success' => false, 'message' => 'Неверный формат дат']);
                    break;
                }
                
                // Проверяем, что дата выезда больше даты заезда
                if ($check_out <= $check_in) {
                    echo json_encode(['success' => false, 'message' => 'Дата выезда должна быть позже даты заезда']);
                    break;
                }
                
                // Проверяем доступность квартиры на выбранные даты
                $stmt = $db->prepare("
                    SELECT COUNT(*) as conflicts 
                    FROM bookings 
                    WHERE apartment_id = ? 
                    AND status IN ('pending', 'confirmed')
                    AND (
                        (check_in <= ? AND check_out >= ?) OR
                        (check_in <= ? AND check_out >= ?) OR
                        (check_in >= ? AND check_out <= ?)
                    )
                ");
                $stmt->execute([
                    $data['apartment_id'],
                    $check_in, $check_in,
                    $check_out, $check_out,
                    $check_in, $check_out
                ]);
                
                $conflicts = $stmt->fetch()['conflicts'];
                
                if ($conflicts > 0) {
                    echo json_encode(['success' => false, 'message' => 'Квартира уже забронирована на выбранные даты']);
                    break;
                }
                
                // Вычисляем количество ночей
                $nights = (strtotime($check_out) - strtotime($check_in)) / (24 * 60 * 60);
                
                // Получаем цену квартиры
                $stmt = $db->prepare("SELECT price, price_type FROM apartments WHERE id = ?");
                $stmt->execute([$data['apartment_id']]);
                $apartment = $stmt->fetch();
                
                if (!$apartment) {
                    echo json_encode(['success' => false, 'message' => 'Квартира не найдена']);
                    break;
                }
                
                // Вычисляем общую стоимость
                $total_amount = 0;
                if ($apartment['price_type'] === 'fixed') {
                    $total_amount = $apartment['price'] * $nights;
                } else {
                    // Для договорной цены используем базовую стоимость
                    $total_amount = 100 * $nights; // Базовая стоимость 100 BYN за ночь
                }
                
                // Создаем бронирование
                $stmt = $db->prepare("
                    INSERT INTO bookings (
                        apartment_id, user_id, name, phone, email, 
                        check_in, check_out, nights, guests, total_amount, message
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $data['apartment_id'],
                    $_SESSION['user_id'] ?? null,
                    $data['name'],
                    $data['phone'],
                    $data['email'] ?? '',
                    $check_in,
                    $check_out,
                    $nights,
                    $data['guests'] ?? 1,
                    $total_amount,
                    $data['message'] ?? ''
                ]);
                
                $booking_id = $db->lastInsertId();
                
                error_log("CREATE_BOOKING: Бронирование создано успешно. ID: {$booking_id}, Сумма: {$total_amount}, Ночей: {$nights}");
                
                echo json_encode([
                    'success' => true, 
                    'booking_id' => $booking_id,
                    'total_amount' => $total_amount,
                    'nights' => $nights
                ]);
            }
            break;
            
        case 'get_bookings':
            if ($method === 'GET') {
                $user_id = $_SESSION['user_id'] ?? null;
                
                if ($user_id) {
                    $stmt = $db->prepare("
                        SELECT b.*, a.title as apartment_title, a.address as apartment_address, a.district as apartment_district
                        FROM bookings b 
                        JOIN apartments a ON b.apartment_id = a.id 
                        WHERE b.user_id = ? 
                        ORDER BY b.created_at DESC
                    ");
                    $stmt->execute([$user_id]);
                } else {
                    $stmt = $db->query("
                        SELECT b.*, a.title as apartment_title, a.address as apartment_address, a.district as apartment_district
                        FROM bookings b 
                        JOIN apartments a ON b.apartment_id = a.id 
                        ORDER BY b.created_at DESC
                    ");
                }
                
                $bookings = $stmt->fetchAll();
                echo json_encode(['success' => true, 'data' => $bookings]);
            }
            break;
            
        case 'get_owner_bookings':
            if ($method === 'GET') {
                if (!isset($_SESSION['user_id'])) {
                    echo json_encode(['success' => false, 'message' => 'Необходимо войти в систему']);
                    break;
                }
                
                // Получаем бронирования для квартир, принадлежащих пользователю
                $stmt = $db->prepare("
                    SELECT b.*, a.title as apartment_title, a.address as apartment_address, a.district as apartment_district
                    FROM bookings b 
                    JOIN apartments a ON b.apartment_id = a.id 
                    WHERE a.user_id = ? 
                    ORDER BY b.created_at DESC
                ");
                $stmt->execute([$_SESSION['user_id']]);
                $bookings = $stmt->fetchAll();
                
                echo json_encode(['success' => true, 'data' => $bookings]);
            }
            break;
            
        case 'get_booking':
            if ($method === 'GET' && isset($_GET['id'])) {
                $stmt = $db->prepare("
                    SELECT b.*, a.title as apartment_title, a.address as apartment_address, a.district as apartment_district
                    FROM bookings b 
                    JOIN apartments a ON b.apartment_id = a.id 
                    WHERE b.id = ?
                ");
                $stmt->execute([$_GET['id']]);
                $booking = $stmt->fetch();
                
                if ($booking) {
                    echo json_encode(['success' => true, 'data' => $booking]);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Бронирование не найдено']);
                }
            }
            break;
            
        case 'update_booking_status':
            if ($method === 'POST') {
                $data = json_decode(file_get_contents('php://input'), true);
                
                if (empty($data['booking_id']) || empty($data['status'])) {
                    echo json_encode(['success' => false, 'message' => 'Неверные данные']);
                    break;
                }
                
                $valid_statuses = ['pending', 'confirmed', 'cancelled'];
                if (!in_array($data['status'], $valid_statuses)) {
                    echo json_encode(['success' => false, 'message' => 'Неверный статус']);
                    break;
                }
                
                $stmt = $db->prepare("UPDATE bookings SET status = ? WHERE id = ?");
                $stmt->execute([$data['status'], $data['booking_id']]);
                
                echo json_encode(['success' => true, 'message' => 'Статус обновлен']);
            }
            break;
            
        case 'get_apartment_bookings':
            if ($method === 'GET' && isset($_GET['apartment_id'])) {
                $stmt = $db->prepare("
                    SELECT check_in, check_out, status 
                    FROM bookings 
                    WHERE apartment_id = ? 
                    AND status IN ('pending', 'confirmed')
                    ORDER BY check_in ASC
                ");
                $stmt->execute([$_GET['apartment_id']]);
                $bookings = $stmt->fetchAll();
                
                echo json_encode(['success' => true, 'data' => $bookings]);
            }
            break;
            
        case 'get_booked_dates':
            if ($method === 'GET' && isset($_GET['apartment_id'])) {
                $stmt = $db->prepare("
                    SELECT check_in, check_out, status 
                    FROM bookings 
                    WHERE apartment_id = ? 
                    AND status IN ('pending', 'confirmed')
                    ORDER BY check_in ASC
                ");
                $stmt->execute([$_GET['apartment_id']]);
                $bookings = $stmt->fetchAll();
                
                // Преобразуем даты в формат для календаря
                $booked_dates = [];
                foreach ($bookings as $booking) {
                    $check_in = new DateTime($booking['check_in']);
                    $check_out = new DateTime($booking['check_out']);
                    
                    // Добавляем все даты в диапазоне
                    $current = clone $check_in;
                    while ($current < $check_out) {
                        $booked_dates[] = [
                            'date' => $current->format('Y-m-d'),
                            'status' => $booking['status']
                        ];
                        $current->add(new DateInterval('P1D'));
                    }
                }
                
                echo json_encode(['success' => true, 'data' => $booked_dates]);
            }
            break;
            
        case 'create_payment':
            if ($method === 'POST') {
                $data = json_decode(file_get_contents('php://input'), true);
                
                $stmt = $db->prepare("INSERT INTO payments (booking_id, amount, currency, method, status) VALUES (?, ?, ?, ?, 'pending')");
                $stmt->execute([
                    $data['booking_id'],
                    $data['amount'],
                    $data['currency'] ?? 'BYN',
                    $data['method']
                ]);
                
                $payment_id = $db->lastInsertId();
                
                // Обновляем статус бронирования
                $stmt = $db->prepare("UPDATE bookings SET payment_status = 'pending', payment_method = ?, payment_id = ? WHERE id = ?");
                $stmt->execute([$data['method'], $payment_id, $data['booking_id']]);
                
                echo json_encode(['success' => true, 'payment_id' => $payment_id]);
            }
            break;
            
        case 'update_payment_status':
            if ($method === 'POST') {
                $data = json_decode(file_get_contents('php://input'), true);
                
                $stmt = $db->prepare("UPDATE payments SET status = ? WHERE id = ?");
                $stmt->execute([$data['status'], $data['payment_id']]);
                
                // Обновляем статус бронирования
                $payment_status = $data['status'] === 'completed' ? 'paid' : 'failed';
                $stmt = $db->prepare("UPDATE bookings SET payment_status = ? WHERE payment_id = ?");
                $stmt->execute([$payment_status, $data['payment_id']]);
                
                echo json_encode(['success' => true]);
            }
            break;
            
        case 'get_payments':
            if ($method === 'GET') {
                $stmt = $db->query("
                    SELECT p.*, b.name as booking_name, b.phone as booking_phone, a.title as apartment_title
                    FROM payments p
                    JOIN bookings b ON p.booking_id = b.id
                    JOIN apartments a ON b.apartment_id = a.id
                    ORDER BY p.created_at DESC
                ");
                $payments = $stmt->fetchAll();
                echo json_encode(['success' => true, 'data' => $payments]);
            }
            break;
            
        case 'update_apartment_status':
            if ($method === 'POST') {
                $data = json_decode(file_get_contents('php://input'), true);
                
                $stmt = $db->prepare("UPDATE apartments SET status = ? WHERE id = ?");
                $stmt->execute([$data['status'], $data['apartment_id']]);
                
                echo json_encode(['success' => true]);
            }
            break;
            
        case 'update_booking_status':
            if ($method === 'POST') {
                $data = json_decode(file_get_contents('php://input'), true);
                
                $stmt = $db->prepare("UPDATE bookings SET status = ? WHERE id = ?");
                $stmt->execute([$data['status'], $data['booking_id']]);
                
                echo json_encode(['success' => true]);
            }
            break;
            
        case 'get_settings':
            if ($method === 'GET') {
                $stmt = $db->query("SELECT setting_key, setting_value FROM settings");
                $settings = [];
                while ($row = $stmt->fetch()) {
                    $settings[$row['setting_key']] = $row['setting_value'];
                }
                echo json_encode(['success' => true, 'data' => $settings]);
            }
            break;
            
        case 'update_setting':
            if ($method === 'POST') {
                $data = json_decode(file_get_contents('php://input'), true);
                setSetting($data['key'], $data['value']);
                echo json_encode(['success' => true]);
            }
            break;
            
        case 'login':
            if ($method === 'POST') {
                $input = file_get_contents('php://input');
                $data = json_decode($input, true);
                
                if (empty($data['login'])) {
                    echo json_encode(['success' => false, 'message' => 'Введите email или телефон']);
                    break;
                }
                
                $login = $data['login'];
                $password = $data['password'] ?? '';
                
                // Определяем тип входа (email или телефон)
                if (filter_var($login, FILTER_VALIDATE_EMAIL)) {
                    // Вход по email
                    $stmt = $db->prepare("SELECT * FROM users WHERE email = ?");
                } elseif (preg_match('/^\+375/', $login)) {
                    // Вход по телефону
                    $stmt = $db->prepare("SELECT * FROM users WHERE phone = ?");
                } else {
                    echo json_encode(['success' => false, 'message' => 'Неверный формат email или телефона']);
                    break;
                }
                
                $stmt->execute([$login]);
                $user = $stmt->fetch();
                
                if (!$user) {
                    echo json_encode(['success' => false, 'message' => 'Пользователь не найден']);
                    break;
                }
                
                // Вход по паролю
                if (!empty($password)) {
                    if (verifyPassword($password, $user['password'])) {
                        $_SESSION['user_id'] = $user['id'];
                        $_SESSION['user_name'] = $user['name'];
                        $_SESSION['user_email'] = $user['email'];
                        
                        echo json_encode(['success' => true, 'user' => [
                            'id' => $user['id'],
                            'name' => $user['name'],
                            'surname' => $user['surname'] ?? '',
                            'email' => $user['email'],
                            'phone' => $user['phone'] ?? '',
                            'isLoggedIn' => true,
                            'loginTime' => date('c')
                        ]]);
                    } else {
                        echo json_encode(['success' => false, 'message' => 'Неверный пароль']);
                    }
                } else {
                    echo json_encode(['success' => false, 'message' => 'Введите пароль']);
                }
            }
            break;
            
        case 'admin_login':
            if ($method === 'POST') {
                $input = file_get_contents('php://input');
                $data = json_decode($input, true);
                
                if (empty($data['email']) || empty($data['password'])) {
                    echo json_encode(['success' => false, 'message' => 'Введите email и пароль']);
                    break;
                }
                
                $stmt = $db->prepare("SELECT * FROM users WHERE email = ? AND role = 'admin'");
                $stmt->execute([$data['email']]);
                $admin = $stmt->fetch();
                
                if ($admin && verifyPassword($data['password'], $admin['password'])) {
                    $_SESSION['admin_id'] = $admin['id'];
                    $_SESSION['admin_name'] = $admin['name'];
                    $_SESSION['admin_email'] = $admin['email'];
                    $_SESSION['is_admin'] = true;
                    
                    echo json_encode(['success' => true, 'message' => 'Вход выполнен успешно']);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Неверный email или пароль, либо у вас нет прав администратора']);
                }
            }
            break;
            
        case 'check_admin':
            if ($method === 'GET') {
                if (isset($_SESSION['is_admin']) && $_SESSION['is_admin'] === true) {
                    echo json_encode(['success' => true, 'admin' => [
                        'id' => $_SESSION['admin_id'],
                        'name' => $_SESSION['admin_name'],
                        'email' => $_SESSION['admin_email']
                    ]]);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Нет доступа']);
                }
            }
            break;
            

            
        case 'register':
            if ($method === 'POST') {
                $input = file_get_contents('php://input');
                $data = json_decode($input, true);
                
                // Исправляем кодировку входящих данных
                if (isset($data['name'])) {
                    // Пробуем разные варианты декодирования
                    $name = $data['name'];
                    if (mb_detect_encoding($name, 'UTF-8', true) === false) {
                        $name = mb_convert_encoding($name, 'UTF-8', 'Windows-1251');
                    }
                    $data['name'] = $name;
                }
                if (isset($data['surname'])) {
                    $surname = $data['surname'];
                    if (mb_detect_encoding($surname, 'UTF-8', true) === false) {
                        $surname = mb_convert_encoding($surname, 'UTF-8', 'Windows-1251');
                    }
                    $data['surname'] = $surname;
                }
                
                // Проверяем, существует ли пользователь
                $stmt = $db->prepare("SELECT id FROM users WHERE email = ?");
                $stmt->execute([$data['email']]);
                if ($stmt->fetch()) {
                    echo json_encode(['success' => false, 'message' => 'Пользователь с таким email уже существует']);
                    break;
                }
                
                // Создаем пользователя
                $stmt = $db->prepare("INSERT INTO users (email, password, name, surname, phone, created_at) VALUES (?, ?, ?, ?, ?, NOW())");
                $stmt->execute([
                    $data['email'],
                    hashPassword($data['password']),
                    $data['name'],
                    $data['surname'] ?? '',
                    $data['phone'] ?? ''
                ]);
                
                $user_id = $db->lastInsertId();
                $_SESSION['user_id'] = $user_id;
                $_SESSION['user_name'] = $data['name'];
                $_SESSION['user_email'] = $data['email'];
                
                echo json_encode(['success' => true, 'user' => [
                    'id' => $user_id,
                    'name' => $data['name'],
                    'surname' => $data['surname'] ?? '',
                    'email' => $data['email'],
                    'phone' => $data['phone'] ?? '',
                    'isLoggedIn' => true,
                    'registrationTime' => date('c')
                ]]);
            }
            break;
            
        case 'logout':
            if ($method === 'POST') {
                session_destroy();
                echo json_encode(['success' => true]);
            }
            break;
            
        case 'check_auth':
            if ($method === 'GET') {
                if (isset($_SESSION['user_id'])) {
                    echo json_encode(['success' => true, 'user' => [
                        'id' => $_SESSION['user_id'],
                        'name' => $_SESSION['user_name'],
                        'email' => $_SESSION['user_email']
                    ]]);
                } else {
                    echo json_encode(['success' => false]);
                }
            }
            break;
            
        case 'create_apartment':
            if ($method === 'POST') {
                // Проверяем авторизацию
                if (!isset($_SESSION['user_id'])) {
                    echo json_encode(['success' => false, 'message' => 'Необходимо войти в систему']);
                    break;
                }
                
                $data = json_decode(file_get_contents('php://input'), true);
                
                // Валидация данных
                if (empty($data['title']) || empty($data['address']) || empty($data['price'])) {
                    echo json_encode(['success' => false, 'message' => 'Заполните все обязательные поля']);
                    break;
                }
                
                // Создаем квартиру
                $stmt = $db->prepare("INSERT INTO apartments (user_id, title, address, district, rooms, floor, area, description, price, price_type, images, amenities, status, latitude, longitude, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())");
                $stmt->execute([
                    $_SESSION['user_id'],
                    $data['title'],
                    $data['address'],
                    $data['district'] ?? '',
                    $data['rooms'] ?? '',
                    $data['floor'] ?? '',
                    $data['area'] ?? '',
                    $data['description'] ?? '',
                    $data['price'],
                    $data['priceType'] ?? 'fixed',
                    json_encode($data['images'] ?? []),
                    json_encode($data['amenities'] ?? []),
                    'pending',
                    $data['latitude'] ?? 53.9006,
                    $data['longitude'] ?? 27.5590
                ]);
                
                $apartment_id = $db->lastInsertId();
                echo json_encode(['success' => true, 'apartment_id' => $apartment_id]);
            }
            break;
            
        case 'update_user':
            if ($method === 'POST') {
                $data = json_decode(file_get_contents('php://input'), true);
                
                if (!isset($_SESSION['user_id'])) {
                    echo json_encode(['success' => false, 'message' => 'Необходимо войти в систему']);
                    break;
                }
                
                // Обновляем данные пользователя
                $stmt = $db->prepare("UPDATE users SET name = ?, email = ?, phone = ? WHERE id = ?");
                $stmt->execute([
                    $data['name'],
                    $data['email'],
                    $data['phone'] ?? '',
                    $_SESSION['user_id']
                ]);
                
                // Обновляем данные сессии
                $_SESSION['user_name'] = $data['name'];
                $_SESSION['user_email'] = $data['email'];
                
                echo json_encode(['success' => true]);
            }
            break;
            
        case 'get_user_apartments':
            if ($method === 'GET') {
                if (!isset($_SESSION['user_id'])) {
                    echo json_encode(['success' => false, 'message' => 'Необходимо войти в систему']);
                    break;
                }
                
                // Получаем квартиры пользователя
                $stmt = $db->prepare("SELECT * FROM apartments WHERE user_id = ? ORDER BY created_at DESC");
                $stmt->execute([$_SESSION['user_id']]);
                $userApartments = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                echo json_encode(['success' => true, 'data' => $userApartments]);
            }
            break;
            
        case 'delete_apartment':
            if ($method === 'POST') {
                if (!isset($_SESSION['user_id'])) {
                    echo json_encode(['success' => false, 'message' => 'Необходимо войти в систему']);
                    break;
                }
                
                $data = json_decode(file_get_contents('php://input'), true);
                
                if (empty($data['apartment_id'])) {
                    echo json_encode(['success' => false, 'message' => 'ID квартиры не указан']);
                    break;
                }
                
                // Проверяем, что квартира принадлежит пользователю
                $stmt = $db->prepare("SELECT id FROM apartments WHERE id = ? AND user_id = ?");
                $stmt->execute([$data['apartment_id'], $_SESSION['user_id']]);
                $apartment = $stmt->fetch();
                
                if (!$apartment) {
                    echo json_encode(['success' => false, 'message' => 'Квартира не найдена или у вас нет прав на её удаление']);
                    break;
                }
                
                // Удаляем квартиру
                $stmt = $db->prepare("DELETE FROM apartments WHERE id = ? AND user_id = ?");
                $stmt->execute([$data['apartment_id'], $_SESSION['user_id']]);
                
                if ($stmt->rowCount() > 0) {
                    echo json_encode(['success' => true, 'message' => 'Квартира успешно удалена']);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Ошибка при удалении квартиры']);
                }
            }
            break;
            
        case 'get_apartment':
            if ($method === 'POST') {
                if (!isset($_SESSION['user_id'])) {
                    echo json_encode(['success' => false, 'message' => 'Необходимо войти в систему']);
                    break;
                }
                
                $data = json_decode(file_get_contents('php://input'), true);
                
                if (empty($data['apartment_id'])) {
                    echo json_encode(['success' => false, 'message' => 'ID квартиры не указан']);
                    break;
                }
                
                // Получаем данные квартиры (только если она принадлежит пользователю)
                $stmt = $db->prepare("SELECT * FROM apartments WHERE id = ? AND user_id = ?");
                $stmt->execute([$data['apartment_id'], $_SESSION['user_id']]);
                $apartment = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($apartment) {
                    // Преобразуем JSON строки в массивы
                    $apartment['images'] = json_decode($apartment['images'], true) ?: [];
                    $apartment['amenities'] = json_decode($apartment['amenities'], true) ?: [];
                    echo json_encode(['success' => true, 'data' => $apartment]);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Квартира не найдена или у вас нет прав на её редактирование']);
                }
            }
            break;
            
        case 'update_apartment':
            if ($method === 'POST') {
                if (!isset($_SESSION['user_id'])) {
                    echo json_encode(['success' => false, 'message' => 'Необходимо войти в систему']);
                    break;
                }
                
                $data = json_decode(file_get_contents('php://input'), true);
                
                if (empty($data['apartment_id'])) {
                    echo json_encode(['success' => false, 'message' => 'ID квартиры не указан']);
                    break;
                }
                
                // Проверяем, что квартира принадлежит пользователю
                $stmt = $db->prepare("SELECT id FROM apartments WHERE id = ? AND user_id = ?");
                $stmt->execute([$data['apartment_id'], $_SESSION['user_id']]);
                $apartment = $stmt->fetch();
                
                if (!$apartment) {
                    echo json_encode(['success' => false, 'message' => 'Квартира не найдена или у вас нет прав на её редактирование']);
                    break;
                }
                
                // Обновляем данные квартиры
                $stmt = $db->prepare("UPDATE apartments SET 
                    title = ?, 
                    district = ?, 
                    address = ?, 
                    rooms = ?, 
                    floor = ?, 
                    area = ?, 
                    description = ?, 
                    images = ?, 
                    latitude = ?, 
                    longitude = ?, 
                    price = ?, 
                    price_type = ?, 
                    amenities = ?,
                    status = 'pending'
                    WHERE id = ? AND user_id = ?");
                
                $stmt->execute([
                    $data['title'],
                    $data['district'],
                    $data['address'],
                    $data['rooms'],
                    $data['floor'],
                    $data['area'],
                    $data['description'],
                    json_encode($data['images'] ?? []),
                    $data['latitude'],
                    $data['longitude'],
                    $data['price'],
                    $data['price_type'],
                    json_encode($data['amenities'] ?? []),
                    $data['apartment_id'],
                    $_SESSION['user_id']
                ]);
                
                if ($stmt->rowCount() > 0) {
                    echo json_encode(['success' => true, 'message' => 'Квартира успешно обновлена']);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Ошибка при обновлении квартиры']);
                }
            }
            break;
            
        case 'create_payment':
            if ($method === 'POST') {
                $data = json_decode(file_get_contents('php://input'), true);
                
                // Логируем входящие данные
                error_log("CREATE_PAYMENT: Получены данные: " . json_encode($data));
                
                // Валидация данных
                if (empty($data['payment']['booking_id']) || empty($data['payment']['amount']) || 
                    empty($data['payment']['method'])) {
                    error_log("CREATE_PAYMENT: Ошибка валидации - не все поля заполнены");
                    echo json_encode(['success' => false, 'message' => 'Заполните все обязательные поля']);
                    break;
                }
                
                // Проверяем, что бронирование существует
                $stmt = $db->prepare("SELECT * FROM bookings WHERE id = ?");
                $stmt->execute([$data['payment']['booking_id']]);
                $booking = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if (!$booking) {
                    echo json_encode(['success' => false, 'message' => 'Бронирование не найдено']);
                    break;
                }
                
                // Проверяем, что платеж еще не создан
                $stmt = $db->prepare("SELECT id FROM payments WHERE booking_id = ?");
                $stmt->execute([$data['payment']['booking_id']]);
                $existingPayment = $stmt->fetch();
                
                if ($existingPayment) {
                    echo json_encode(['success' => false, 'message' => 'Платеж для этого бронирования уже существует']);
                    break;
                }
                
                // Создаем платеж
                $stmt = $db->prepare("INSERT INTO payments (booking_id, amount, currency, method, status, created_at) VALUES (?, ?, ?, ?, 'pending', NOW())");
                $stmt->execute([
                    $data['payment']['booking_id'],
                    $data['payment']['amount'],
                    $data['payment']['currency'] ?? 'BYN',
                    $data['payment']['method']
                ]);
                
                $paymentId = $db->lastInsertId();
                
                // Обновляем статус бронирования
                $stmt = $db->prepare("UPDATE bookings SET payment_status = 'pending' WHERE id = ?");
                $stmt->execute([$data['payment']['booking_id']]);
                
                // Логируем успешное создание платежа
                error_log("CREATE_PAYMENT: Платеж создан с ID: " . $paymentId);
                
                // Отправляем уведомления
                try {
                    require_once 'payment_notifications.php';
                    $notification = new PaymentNotification($db);
                    $notification->sendPaymentCreatedNotification($paymentId);
                } catch (Exception $e) {
                    error_log("Ошибка отправки уведомления: " . $e->getMessage());
                }
                
                echo json_encode(['success' => true, 'payment_id' => $paymentId, 'message' => 'Платеж создан успешно']);
            }
            break;
            
        case 'get_payment':
            if ($method === 'GET') {
                $paymentId = $_GET['payment_id'] ?? null;
                
                if (!$paymentId) {
                    echo json_encode(['success' => false, 'message' => 'ID платежа не указан']);
                    break;
                }
                
                $stmt = $db->prepare("SELECT * FROM payments WHERE id = ?");
                $stmt->execute([$paymentId]);
                $payment = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($payment) {
                    echo json_encode(['success' => true, 'data' => $payment]);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Платеж не найден']);
                }
            }
            break;
            
        case 'get_payments':
            if ($method === 'GET') {
                $stmt = $db->query("SELECT p.*, b.apartment_id, b.check_in, b.check_out FROM payments p 
                                   LEFT JOIN bookings b ON p.booking_id = b.id 
                                   ORDER BY p.created_at DESC");
                $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                echo json_encode(['success' => true, 'data' => $payments]);
            }
            break;
            
        case 'update_payment_status':
            if ($method === 'POST') {
                $data = json_decode(file_get_contents('php://input'), true);
                
                if (empty($data['payment_id']) || empty($data['status'])) {
                    echo json_encode(['success' => false, 'message' => 'ID платежа и статус обязательны']);
                    break;
                }
                
                // Обновляем статус платежа
                $stmt = $db->prepare("UPDATE payments SET status = ?, updated_at = NOW() WHERE id = ?");
                $stmt->execute([$data['status'], $data['payment_id']]);
                
                if ($stmt->rowCount() > 0) {
                    // Получаем booking_id для обновления статуса бронирования
                    $stmt = $db->prepare("SELECT booking_id FROM payments WHERE id = ?");
                    $stmt->execute([$data['payment_id']]);
                    $payment = $stmt->fetch();
                    
                    if ($payment) {
                        // Обновляем статус бронирования
                        $bookingStatus = ($data['status'] === 'completed') ? 'confirmed' : 'pending';
                        $stmt = $db->prepare("UPDATE bookings SET payment_status = ?, status = ? WHERE id = ?");
                        $stmt->execute([$data['status'], $bookingStatus, $payment['booking_id']]);
                    }
                    
                    echo json_encode(['success' => true, 'message' => 'Статус платежа обновлен']);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Платеж не найден']);
                }
            }
            break;
            
        case 'get_booking':
            if ($method === 'GET') {
                $bookingId = $_GET['booking_id'] ?? null;
                
                if (!$bookingId) {
                    echo json_encode(['success' => false, 'message' => 'ID бронирования не указан']);
                    break;
                }
                
                $stmt = $db->prepare("SELECT b.*, a.title as apartment_title, a.address as apartment_address, 
                                            a.price as price_per_night FROM bookings b 
                                   LEFT JOIN apartments a ON b.apartment_id = a.id 
                                   WHERE b.id = ?");
                $stmt->execute([$bookingId]);
                $booking = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($booking) {
                    // Вычисляем количество ночей
                    $checkIn = new DateTime($booking['check_in']);
                    $checkOut = new DateTime($booking['check_out']);
                    $nights = $checkIn->diff($checkOut)->days;
                    $booking['nights'] = $nights;
                    
                    // Вычисляем общую сумму
                    $booking['total_amount'] = $booking['price_per_night'] * $nights;
                    
                    echo json_encode(['success' => true, 'data' => $booking]);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Бронирование не найдено']);
                }
            }
            break;
            
        case 'get_users':
            if ($method === 'GET') {
                // Проверяем права администратора
                if (!isset($_SESSION['user_id'])) {
                    echo json_encode(['success' => false, 'message' => 'Необходимо войти в систему']);
                    break;
                }
                
                $stmt = $db->prepare("SELECT role FROM users WHERE id = ?");
                $stmt->execute([$_SESSION['user_id']]);
                $user = $stmt->fetch();
                
                if (!$user || $user['role'] !== 'admin') {
                    echo json_encode(['success' => false, 'message' => 'Недостаточно прав']);
                    break;
                }
                
                $stmt = $db->query("SELECT id, name, surname, email, phone, created_at, role FROM users ORDER BY created_at DESC");
                $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                echo json_encode(['success' => true, 'data' => $users]);
            }
            break;
            
        case 'update_booking_status':
            if ($method === 'POST') {
                $data = json_decode(file_get_contents('php://input'), true);
                
                if (empty($data['booking_id']) || empty($data['status'])) {
                    echo json_encode(['success' => false, 'message' => 'ID бронирования и статус обязательны']);
                    break;
                }
                
                $valid_statuses = ['active', 'completed', 'cancelled'];
                if (!in_array($data['status'], $valid_statuses)) {
                    echo json_encode(['success' => false, 'message' => 'Неверный статус']);
                    break;
                }
                
                $stmt = $db->prepare("UPDATE bookings SET status = ?, updated_at = NOW() WHERE id = ?");
                $stmt->execute([$data['status'], $data['booking_id']]);
                
                if ($stmt->rowCount() > 0) {
                    echo json_encode(['success' => true, 'message' => 'Статус бронирования обновлен']);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Бронирование не найдено']);
                }
            }
            break;
            
        case 'check_admin':
            if ($method === 'GET') {
                if (isset($_SESSION['user_id'])) {
                    // Проверяем, является ли пользователь администратором
                    $stmt = $db->prepare("SELECT role FROM users WHERE id = ?");
                    $stmt->execute([$_SESSION['user_id']]);
                    $user = $stmt->fetch();
                    
                    if ($user && $user['role'] === 'admin') {
                        echo json_encode(['success' => true, 'message' => 'Доступ разрешен']);
                    } else {
                        echo json_encode(['success' => false, 'message' => 'Недостаточно прав']);
                    }
                } else {
                    echo json_encode(['success' => false, 'message' => 'Необходимо войти в систему']);
                }
            }
            break;
            
        case 'delete_user':
            if ($method === 'POST') {
                // Проверяем права администратора
                if (!isset($_SESSION['user_id'])) {
                    echo json_encode(['success' => false, 'message' => 'Необходимо войти в систему']);
                    break;
                }
                
                $stmt = $db->prepare("SELECT role FROM users WHERE id = ?");
                $stmt->execute([$_SESSION['user_id']]);
                $admin = $stmt->fetch();
                
                if (!$admin || $admin['role'] !== 'admin') {
                    echo json_encode(['success' => false, 'message' => 'Недостаточно прав']);
                    break;
                }
                
                $data = json_decode(file_get_contents('php://input'), true);
                
                if (empty($data['user_id'])) {
                    echo json_encode(['success' => false, 'message' => 'ID пользователя обязателен']);
                    break;
                }
                
                $userId = $data['user_id'];
                
                // Проверяем, не пытается ли администратор удалить сам себя
                if ($userId == $_SESSION['user_id']) {
                    echo json_encode(['success' => false, 'message' => 'Нельзя удалить самого себя']);
                    break;
                }
                
                // Проверяем, существует ли пользователь
                $stmt = $db->prepare("SELECT id, name, email, role FROM users WHERE id = ?");
                $stmt->execute([$userId]);
                $userToDelete = $stmt->fetch();
                
                if (!$userToDelete) {
                    echo json_encode(['success' => false, 'message' => 'Пользователь не найден']);
                    break;
                }
                
                // Проверяем, не пытается ли удалить другого администратора
                if ($userToDelete['role'] === 'admin') {
                    echo json_encode(['success' => false, 'message' => 'Нельзя удалить другого администратора']);
                    break;
                }
                
                                       // Удаляем связанные записи (сначала платежи, потом бронирования)
                       // Получаем все booking_id для данного пользователя
                       $stmt = $db->prepare("SELECT id FROM bookings WHERE user_id = ?");
                       $stmt->execute([$userId]);
                       $bookingIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
                       
                       if (!empty($bookingIds)) {
                           // Удаляем платежи, связанные с бронированиями пользователя
                           $placeholders = str_repeat('?,', count($bookingIds) - 1) . '?';
                           $stmt = $db->prepare("DELETE FROM payments WHERE booking_id IN ($placeholders)");
                           $stmt->execute($bookingIds);
                       }
                       
                       // Удаляем бронирования пользователя
                       $stmt = $db->prepare("DELETE FROM bookings WHERE user_id = ?");
                       $stmt->execute([$userId]);
                
                // Удаляем пользователя
                $stmt = $db->prepare("DELETE FROM users WHERE id = ?");
                $stmt->execute([$userId]);
                
                if ($stmt->rowCount() > 0) {
                    echo json_encode(['success' => true, 'message' => 'Пользователь успешно удален']);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Ошибка при удалении пользователя']);
                }
            }
            break;
            
        default:
            echo json_encode(['success' => false, 'message' => 'Неизвестное действие']);
    }
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Ошибка сервера: ' . $e->getMessage()]);
}
?> 