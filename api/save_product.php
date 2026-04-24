<?php
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../models/Product.php';

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

if (empty($input['item_name'])) {
    echo json_encode(['success' => false, 'message' => 'Product name is required']);
    exit();
}

if (empty($input['selling_price']) || $input['selling_price'] <= 0) {
    echo json_encode(['success' => false, 'message' => 'Valid selling price is required']);
    exit();
}

try {
    $productModel = new Product();
    
    // If updating existing product
    if (!empty($input['product_id'])) {
        $result = $productModel->update($input['product_id'], $input);
        $message = 'Product updated successfully';
    } else {
        $result = $productModel->create($input);
        $message = 'Product created successfully';
    }
    
    if ($result) {
        echo json_encode(['success' => true, 'message' => $message]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to save product']);
    }
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>