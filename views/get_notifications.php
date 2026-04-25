<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    echo json_encode(['count' => 0]);
    exit();
}

try {
    $conn = new PDO("mysql:host=localhost;dbname=savant_motors_pos", "root", "");
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Count low stock products
    $stmt = $conn->query("
        SELECT COUNT(*) as count 
        FROM inventory 
        WHERE quantity <= reorder_level AND is_active = 1
    ");
    $lowStock = $stmt->fetchColumn();
    
    // Count pending purchases
    $stmt = $conn->query("
        SELECT COUNT(*) as count 
        FROM purchases 
        WHERE status = 'ordered'
    ");
    $pendingPurchases = $stmt->fetchColumn();
    
    $total = $lowStock + $pendingPurchases;
    
    echo json_encode(['count' => $total]);
} catch(PDOException $e) {
    echo json_encode(['count' => 0]);
}
?>