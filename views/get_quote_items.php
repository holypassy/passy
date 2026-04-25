<?php
// get_quote_items.php - Fetch items for a vendor quote
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    echo json_encode([]);
    exit();
}

try {
    $conn = new PDO("mysql:host=localhost;dbname=savant_motors_pos", "root", "");
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $quote_id = $_GET['quote_id'] ?? 0;
    
    $stmt = $conn->prepare("
        SELECT * FROM vendor_quote_items 
        WHERE quote_id = ? 
        ORDER BY id
    ");
    $stmt->execute([$quote_id]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode($items);
} catch(Exception $e) {
    error_log("get_quote_items error: " . $e->getMessage());
    echo json_encode([]);
}
?>