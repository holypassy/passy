<?php
// delete_permission.php - Delete a Permission
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    echo json_encode(['success' => false, 'message' => 'Not logged in']);
    exit();
}

if ($_SESSION['role'] !== 'admin') {
    echo json_encode(['success' => false, 'message' => 'Admin access required']);
    exit();
}

$data = json_decode(file_get_contents('php://input'), true);
$permission_id = isset($data['permission_id']) ? (int)$data['permission_id'] : 0;

if ($permission_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid permission ID']);
    exit();
}

try {
    $conn = new PDO("mysql:host=localhost;dbname=savant_motors_pos", "root", "");
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $conn->beginTransaction();
    
    // First, delete all user associations
    $stmt = $conn->prepare("DELETE FROM user_permissions WHERE permission_id = ?");
    $stmt->execute([$permission_id]);
    
    // Then delete the permission
    $stmt = $conn->prepare("DELETE FROM permissions WHERE id = ?");
    $stmt->execute([$permission_id]);
    
    $conn->commit();
    
    echo json_encode(['success' => true, 'message' => 'Permission deleted successfully']);
    
} catch(PDOException $e) {
    $conn->rollBack();
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>