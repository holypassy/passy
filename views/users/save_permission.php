<?php
// save_permission.php - Save or Update Permission
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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit();
}

try {
    $conn = new PDO("mysql:host=localhost;dbname=savant_motors_pos", "root", "");
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $permission_id = isset($_POST['permission_id']) ? (int)$_POST['permission_id'] : 0;
    $permission_name = trim($_POST['permission_name'] ?? '');
    $permission_key = trim($_POST['permission_key'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $category = $_POST['category'] ?? 'Custom';
    
    // Validation
    $errors = [];
    
    if (empty($permission_name)) {
        $errors[] = "Permission name is required";
    }
    if (empty($permission_key)) {
        $errors[] = "Permission key is required";
    }
    if (!preg_match('/^[a-z_]+$/', $permission_key)) {
        $errors[] = "Permission key must contain only lowercase letters and underscores";
    }
    
    // Check if permission key already exists
    if ($permission_id == 0) {
        $stmt = $conn->prepare("SELECT COUNT(*) FROM permissions WHERE permission_key = ?");
        $stmt->execute([$permission_key]);
        if ($stmt->fetchColumn() > 0) {
            $errors[] = "Permission key already exists";
        }
    } else {
        $stmt = $conn->prepare("SELECT COUNT(*) FROM permissions WHERE permission_key = ? AND id != ?");
        $stmt->execute([$permission_key, $permission_id]);
        if ($stmt->fetchColumn() > 0) {
            $errors[] = "Permission key already exists";
        }
    }
    
    if (!empty($errors)) {
        echo json_encode(['success' => false, 'message' => implode("\n", $errors)]);
        exit();
    }
    
    if ($permission_id == 0) {
        // Create new permission
        $stmt = $conn->prepare("
            INSERT INTO permissions (permission_key, permission_name, description, category, is_active, created_at) 
            VALUES (?, ?, ?, ?, 1, NOW())
        ");
        $stmt->execute([$permission_key, $permission_name, $description, $category]);
        
        echo json_encode(['success' => true, 'message' => 'Permission created successfully']);
    } else {
        // Update existing permission
        $stmt = $conn->prepare("
            UPDATE permissions 
            SET permission_key = ?, permission_name = ?, description = ?, category = ?, updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$permission_key, $permission_name, $description, $category, $permission_id]);
        
        echo json_encode(['success' => true, 'message' => 'Permission updated successfully']);
    }
    
} catch(PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>