<?php
// get_technician.php
session_start();
header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

if (!isset($_GET['id']) || empty($_GET['id'])) {
    echo json_encode(['success' => false, 'message' => 'Technician ID is required']);
    exit();
}

try {
    $conn = new PDO("mysql:host=localhost;dbname=savant_motors_pos", "root", "");
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $stmt = $conn->prepare("SELECT * FROM technicians WHERE id = ?");
    $stmt->execute([$_GET['id']]);
    $technician = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($technician) {
        echo json_encode($technician);
    } else {
        echo json_encode(['success' => false, 'message' => 'Technician not found']);
    }
    
} catch(PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>