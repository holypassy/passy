<?php
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../models/Product.php';

header('Content-Type: application/json');

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$id) {
    echo json_encode(['success' => false, 'message' => 'Product ID required']);
    exit();
}

try {
    $productModel = new Product();
    $product = $productModel->findById($id);
    
    if (!$product) {
        echo json_encode(['success' => false, 'message' => 'Product not found']);
        exit();
    }
    
    echo json_encode($product);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>