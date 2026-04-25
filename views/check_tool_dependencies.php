<?php
// check_tool_dependencies.php
session_start();
header('Content-Type: application/json');

// Check if user is logged in
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access']);
    exit();
}

$tool_id = $_GET['tool_id'] ?? null;

if (!$tool_id) {
    echo json_encode(['success' => false, 'message' => 'Tool ID is required']);
    exit();
}

try {
    $conn = new PDO("mysql:host=localhost;dbname=savant_motors_pos", "root", "");
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Check active assignments
    $stmt = $conn->prepare("
        SELECT 
            ta.*,
            tech.full_name as technician_name,
            tech.technician_code
        FROM tool_assignments ta
        JOIN technicians tech ON ta.technician_id = tech.id
        WHERE ta.tool_id = ? AND ta.status = 'assigned' AND ta.actual_return_date IS NULL
    ");
    $stmt->execute([$tool_id]);
    $active_assignments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Check all assignments (including historical)
    $stmt = $conn->prepare("SELECT COUNT(*) as total FROM tool_assignments WHERE tool_id = ?");
    $stmt->execute([$tool_id]);
    $total_assignments = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    // Check maintenance records
    $stmt = $conn->prepare("SELECT COUNT(*) as total FROM tool_maintenance WHERE tool_id = ?");
    $stmt->execute([$tool_id]);
    $maintenance_records = $stmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    echo json_encode([
        'success' => true,
        'has_active_assignments' => count($active_assignments) > 0,
        'active_assignments' => $active_assignments,
        'total_assignments' => $total_assignments,
        'maintenance_records' => $maintenance_records,
        'can_delete' => count($active_assignments) === 0
    ]);
    
} catch(PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>