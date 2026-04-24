<?php
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../models/Technician.php';

header('Content-Type: application/json');

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

if (!in_array($_SESSION['role'], ['admin', 'manager'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$input = $_POST; // Form data

if (empty($input['full_name'])) {
    echo json_encode(['success' => false, 'message' => 'Full name is required']);
    exit();
}

if (empty($input['phone'])) {
    echo json_encode(['success' => false, 'message' => 'Phone number is required']);
    exit();
}

try {
    $technicianModel = new Technician();
    
    // If updating existing technician
    if (!empty($input['technician_id'])) {
        $result = $technicianModel->update($input['technician_id'], $input);
        $message = 'Technician updated successfully';
    } else {
        $result = $technicianModel->create($input);
        $message = 'Technician created successfully';
    }
    
    if ($result) {
        echo json_encode(['success' => true, 'message' => $message]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to save technician']);
    }
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>