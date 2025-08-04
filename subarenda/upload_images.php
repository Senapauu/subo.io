<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once 'config.php';

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Только POST запросы');
    }

    $data = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($data['images']) || !is_array($data['images'])) {
        throw new Exception('Нет изображений для загрузки');
    }

    $uploadedUrls = [];
    $uploadDir = 'uploads/';
    
    // Создаем папку, если её нет
    if (!file_exists($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }

    foreach ($data['images'] as $index => $imageData) {
        // Проверяем, что это base64 изображение
        if (strpos($imageData, 'data:image/') !== 0) {
            // Если это URL, используем как есть
            $uploadedUrls[] = $imageData;
            continue;
        }

        // Извлекаем тип изображения и данные
        $imageInfo = explode(',', $imageData);
        $imageType = explode(';', explode(':', $imageInfo[0])[1])[0];
        $imageExtension = explode('/', $imageType)[1];
        
        // Декодируем base64
        $imageBinary = base64_decode($imageInfo[1]);
        
        // Генерируем уникальное имя файла
        $filename = uniqid() . '_' . time() . '.' . $imageExtension;
        $filepath = $uploadDir . $filename;
        
        // Сохраняем файл
        if (file_put_contents($filepath, $imageBinary)) {
            $uploadedUrls[] = $filepath;
        } else {
            throw new Exception('Ошибка сохранения файла: ' . $filename);
        }
    }

    echo json_encode([
        'success' => true,
        'images' => $uploadedUrls
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?> 