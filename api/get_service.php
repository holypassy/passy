<?php
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../models/Service.php';

header('Content-Type: application/json');

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$id) {
    echo json_encode(['success' => false, 'message' => 'Service ID required']);
    exit();
}

try {
    $serviceModel = new Service();
    $service = $serviceModel->findById($id);
    
    if (!$service) {
        echo json_encode(['success' => false, 'message' => 'Service not found']);
        exit();
    }
    
    echo json_encode($service);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>