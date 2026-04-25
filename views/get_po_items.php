<?php
// get_po_items.php - Fetch items for a purchase order
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    echo json_encode(['items' => []]);
    exit();
}

try {
    $conn = new PDO("mysql:host=localhost;dbname=savant_motors_pos", "root", "");
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $po_id = $_GET['po_id'] ?? 0;
    
    // Try to get items from purchase_order_items table
    $stmt = $conn->prepare("
        SELECT poi.*, p.product_name 
        FROM purchase_order_items poi
        LEFT JOIN inventory p ON poi.product_id = p.id
        WHERE poi.po_id = ?
    ");
    $stmt->execute([$po_id]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode(['items' => $items]);
} catch(Exception $e) {
    echo json_encode(['items' => []]);
}
?>