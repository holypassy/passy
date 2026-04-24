<?php
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../models/Customer.php';

header('Content-Type: application/json');

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$input = json_decode(file_get_contents('php://input'), true);

if (empty($input['full_name'])) {
    echo json_encode(['success' => false, 'message' => 'Customer name is required']);
    exit();
}

try {
    $customerModel = new Customer();
    
    $data = [
        'full_name' => $input['full_name'],
        'telephone' => $input['telephone'] ?? null,
        'email' => $input['email'] ?? null,
        'address' => $input['address'] ?? null
    ];
    
    $result = $customerModel->create($data);
    
    if ($result) {
        $newId = $customerModel->conn->lastInsertId();
        echo json_encode([
            'success' => true,
            'id' => $newId,
            'name' => $input['full_name'],
            'phone' => $input['telephone'] ?? '',
            'email' => $input['email'] ?? ''
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to add customer']);
    }
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>