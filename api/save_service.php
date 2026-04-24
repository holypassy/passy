<?php
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../models/Service.php';

header('Content-Type: application/json');

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

if (!in_array($_SESSION['role'], ['admin', 'manager'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$input = $_POST;

if (empty($input['service_name'])) {
    echo json_encode(['success' => false, 'message' => 'Service name is required']);
    exit();
}

if (empty($input['standard_price']) || $input['standard_price'] <= 0) {
    echo json_encode(['success' => false, 'message' => 'Valid price is required']);
    exit();
}

try {
    $serviceModel = new Service();
    
    // If updating existing service
    if (!empty($input['service_id'])) {
        $result = $serviceModel->update($input['service_id'], $input);
        $message = 'Service updated successfully';
    } else {
        $result = $serviceModel->create($input);
        $message = 'Service created successfully';
    }
    
    if ($result) {
        echo json_encode(['success' => true, 'message' => $message]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to save service']);
    }
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>