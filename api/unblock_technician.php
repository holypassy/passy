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

$input = json_decode(file_get_contents('php://input'), true);

if (empty($input['technician_id'])) {
    echo json_encode(['success' => false, 'message' => 'Technician ID required']);
    exit();
}

try {
    $technicianModel = new Technician();
    $result = $technicianModel->unblock($input['technician_id']);
    
    if ($result) {
        echo json_encode(['success' => true, 'message' => 'Technician unblocked successfully']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to unblock technician']);
    }
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>