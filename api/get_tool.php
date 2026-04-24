<?php
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../models/Tool.php';

header('Content-Type: application/json');

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$id) {
    echo json_encode(['success' => false, 'message' => 'Tool ID required']);
    exit();
}

try {
    $toolModel = new Tool();
    $tool = $toolModel->findById($id);
    
    if (!$tool) {
        echo json_encode(['success' => false, 'message' => 'Tool not found']);
        exit();
    }
    
    echo json_encode($tool);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>