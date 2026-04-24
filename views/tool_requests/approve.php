<?php
// views/tool_requests/approve.php - Approve a tool request and create assignments
session_start();

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit();
}

$request_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$request_id) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Request ID required']);
    exit();
}

try {
    $conn = new PDO("mysql:host=localhost;dbname=savant_motors_pos", "root", "");
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Start transaction
    $conn->beginTransaction();
    
    // Check if request exists and is pending
    $stmt = $conn->prepare("
        SELECT id, status, technician_id, request_number, expected_duration_days
        FROM tool_requests 
        WHERE id = ? FOR UPDATE
    ");
    $stmt->execute([$request_id]);
    $request = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$request) {
        throw new Exception('Request not found');
    }
    
    if ($request['status'] !== 'pending') {
        throw new Exception('Request is already ' . $request['status']);
    }
    
    // Update request status to approved
    $stmt = $conn->prepare("
        UPDATE tool_requests 
        SET status = 'approved', updated_at = NOW() 
        WHERE id = ?
    ");
    $stmt->execute([$request_id]);
    
    // Get all tools in this request
    $stmt = $conn->prepare("
        SELECT tri.*, t.tool_name as existing_tool_name, t.tool_code
        FROM tool_request_items tri
        LEFT JOIN tools t ON tri.tool_id = t.id
        WHERE tri.request_id = ?
    ");
    $stmt->execute([$request_id]);
    $tools = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calculate expected return date based on duration days
    $expected_return_date = date('Y-m-d H:i:s', strtotime("+{$request['expected_duration_days']} days"));
    $assigned_by = $_SESSION['user_id'] ?? 1;
    $notes = "Approved from request #{$request['request_number']}";
    
    // Check what columns exist in tool_assignments
    $columns = $conn->query("SHOW COLUMNS FROM tool_assignments")->fetchAll(PDO::FETCH_COLUMN);
    $hasRequestId = in_array('request_id', $columns);
    $hasNotes = in_array('notes', $columns);
    
    // For each tool, create an assignment
    foreach ($tools as $tool) {
        // Skip new tools (not in inventory) - they don't have a tool_id
        if (!$tool['is_new_tool'] && $tool['tool_id']) {
            // Generate unique assignment number
            $prefix = 'ASGN';
            $year = date('Y');
            $month = date('m');
            
            $stmt = $conn->prepare("
                SELECT assignment_number FROM tool_assignments 
                WHERE assignment_number LIKE ? 
                ORDER BY id DESC LIMIT 1
            ");
            $pattern = $prefix . $year . $month . '%';
            $stmt->execute([$pattern]);
            $lastAssignment = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($lastAssignment && $lastAssignment['assignment_number']) {
                $lastNumber = intval(substr($lastAssignment['assignment_number'], -4));
                $sequence = str_pad($lastNumber + 1, 4, '0', STR_PAD_LEFT);
            } else {
                $sequence = '0001';
            }
            
            $assignment_number = $prefix . $year . $month . $sequence;
            
            // Create assignment based on available columns
            if ($hasRequestId && $hasNotes) {
                $assignStmt = $conn->prepare("
                    INSERT INTO tool_assignments (assignment_number, tool_id, technician_id, assigned_by, assigned_date, expected_return_date, request_id, status, notes)
                    VALUES (?, ?, ?, ?, NOW(), ?, ?, 'active', ?)
                ");
                $assignStmt->execute([
                    $assignment_number,
                    $tool['tool_id'],
                    $request['technician_id'],
                    $assigned_by,
                    $expected_return_date,
                    $request_id,
                    $notes
                ]);
            } elseif ($hasRequestId) {
                $assignStmt = $conn->prepare("
                    INSERT INTO tool_assignments (assignment_number, tool_id, technician_id, assigned_by, assigned_date, expected_return_date, request_id, status)
                    VALUES (?, ?, ?, ?, NOW(), ?, ?, 'active')
                ");
                $assignStmt->execute([
                    $assignment_number,
                    $tool['tool_id'],
                    $request['technician_id'],
                    $assigned_by,
                    $expected_return_date,
                    $request_id
                ]);
            } elseif ($hasNotes) {
                $assignStmt = $conn->prepare("
                    INSERT INTO tool_assignments (assignment_number, tool_id, technician_id, assigned_by, assigned_date, expected_return_date, status, notes)
                    VALUES (?, ?, ?, ?, NOW(), ?, 'active', ?)
                ");
                $assignStmt->execute([
                    $assignment_number,
                    $tool['tool_id'],
                    $request['technician_id'],
                    $assigned_by,
                    $expected_return_date,
                    $notes
                ]);
            } else {
                $assignStmt = $conn->prepare("
                    INSERT INTO tool_assignments (assignment_number, tool_id, technician_id, assigned_by, assigned_date, expected_return_date, status)
                    VALUES (?, ?, ?, ?, NOW(), ?, 'active')
                ");
                $assignStmt->execute([
                    $assignment_number,
                    $tool['tool_id'],
                    $request['technician_id'],
                    $assigned_by,
                    $expected_return_date
                ]);
            }
            
            // Update tool status to taken
            $updateToolStmt = $conn->prepare("UPDATE tools SET status = 'taken' WHERE id = ?");
            $updateToolStmt->execute([$tool['tool_id']]);
        }
    }
    
    $conn->commit();
    
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'message' => 'Request approved and tools assigned successfully']);
    
} catch (Exception $e) {
    if (isset($conn)) $conn->rollBack();
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>