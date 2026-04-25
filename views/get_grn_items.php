<?php
// get_grn_items.php - Fetch items for a goods received note
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    echo json_encode([]);
    exit();
}

try {
    $conn = new PDO("mysql:host=localhost;dbname=savant_motors_pos", "root", "");
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $grn_id = $_GET['grn_id'] ?? 0;
    
    $stmt = $conn->prepare("
        SELECT gri.*, p.product_name 
        FROM goods_received_items gri
        LEFT JOIN inventory p ON gri.product_id = p.id
        WHERE gri.grn_id = ?
    ");
    $stmt->execute([$grn_id]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode($items);
} catch(Exception $e) {
    echo json_encode([]);
}
?>