<?php
// get_user_permissions.php - Get user permissions (updated to include custom)
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

$user_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($user_id <= 0) {
    echo json_encode(['error' => 'Invalid user ID']);
    exit();
}

try {
    $conn = new PDO("mysql:host=localhost;dbname=savant_motors_pos", "root", "");
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Get user details
    $stmt = $conn->prepare("SELECT id, full_name, username, email, role FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        echo json_encode(['error' => 'User not found']);
        exit();
    }
    
    // Get all permissions (including custom ones)
    $perms = $conn->query("
        SELECT id, permission_key, permission_name, description, category 
        FROM permissions 
        WHERE is_active = 1 
        ORDER BY category, permission_name
    ")->fetchAll(PDO::FETCH_ASSOC);
    
    // Get user's granted permissions
    $userPerms = $conn->prepare("SELECT permission_id FROM user_permissions WHERE user_id = ? AND granted = 1");
    $userPerms->execute([$user_id]);
    $granted = $userPerms->fetchAll(PDO::FETCH_COLUMN);
    
    // Add granted flag to each permission
    foreach ($perms as &$perm) {
        $perm['granted'] = in_array($perm['id'], $granted);
    }
    
    echo json_encode([
        'id' => $user['id'],
        'full_name' => $user['full_name'],
        'username' => $user['username'],
        'email' => $user['email'],
        'role' => $user['role'],
        'permissions' => $perms,
        'granted_permissions' => $granted
    ]);
    
} catch(PDOException $e) {
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}
?>