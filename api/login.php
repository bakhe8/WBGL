<?php

/**
 * Login API
 */

require_once __DIR__ . '/../app/Support/autoload.php';

use App\Support\AuthService;

header('Content-Type: application/json; charset=utf-8');

try {
    $input = json_decode(file_get_contents('php://input'), true);

    $username = $input['username'] ?? '';
    $password = $input['password'] ?? '';

    if (empty($username) || empty($password)) {
        throw new \Exception('يرجى إدخال اسم المستخدم وكلمة المرور');
    }

    if (AuthService::login($username, $password)) {
        echo json_encode([
            'success' => true,
            'message' => 'تم تسجيل الدخول بنجاح'
        ]);
    } else {
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'message' => 'اسم المستخدم أو كلمة المرور غير صحيحة'
        ]);
    }
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
