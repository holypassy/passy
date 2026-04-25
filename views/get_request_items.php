<?php
// get_request_items.php - Fetch items for a purchase request
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    echo json_encode([]);
    exit();
}

try {
    $conn = new PDO("mysql:host=localhost;dbname=savant_motors_pos", "root", "");
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $request_id = $_GET['request_id'] ?? 0;
    
    $stmt = $conn->prepare("
        SELECT * FROM purchase_request_items 
        WHERE request_id = ? 
        ORDER BY id
    ");
    $stmt->execute([$request_id]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode($items);
} catch(Exception $e) {
    echo json_encode([]);
}
?>