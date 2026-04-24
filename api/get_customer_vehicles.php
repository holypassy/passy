<?php
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../models/JobCard.php';

header('Content-Type: application/json');

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

$customerId = isset($_GET['customer_id']) ? (int)$_GET['customer_id'] : 0;

if (!$customerId) {
    echo json_encode(['error' => 'Customer ID required']);
    exit();
}

try {
    $database = Database::getInstance();
    $conn = $database->getConnection();
    
    $stmt = $conn->prepare("
        SELECT DISTINCT vehicle_reg, vehicle_make, vehicle_model 
        FROM job_cards 
        WHERE customer_id = :customer_id 
          AND vehicle_reg IS NOT NULL
        ORDER BY created_at DESC
        LIMIT 5
    ");
    $stmt->execute([':customer_id' => $customerId]);
    $vehicles = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'vehicles' => $vehicles
    ]);
    
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
?>