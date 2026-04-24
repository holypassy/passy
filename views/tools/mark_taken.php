<?php
// mark_taken.php - Mark an approved request tool as taken
session_start();

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$input = json_decode(file_get_contents('php://input'), true);
$tool_id = $input['tool_id'] ?? 0;
$expected_return_date = $input['expected_return_date'] ?? date('Y-m-d H:i:s', strtotime('+7 days'));
$notes = $input['notes'] ?? '';

if (!$tool_id) {
    echo json_encode(['success' => false, 'message' => 'Missing tool ID']);
    exit();
}

try {
    $conn = new PDO("mysql:host=localhost;dbname=savant_motors_pos", "root", "");
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $conn->beginTransaction();

    // ── Guard: block if quantity is 0 ────────────────────────────────────
    $qtyCheck = $conn->prepare("SELECT quantity, tool_name FROM tools WHERE id = ?");
    $qtyCheck->execute([$tool_id]);
    $toolRow = $qtyCheck->fetch(PDO::FETCH_ASSOC);

    if (!$toolRow) {
        $conn->rollBack();
        echo json_encode(['success' => false, 'message' => 'Tool not found']);
        exit();
    }
    $currentQty = (int)($toolRow['quantity'] ?? 1);
    if ($currentQty <= 0) {
        $conn->rollBack();
        echo json_encode(['success' => false, 'message' => 'Cannot take "' . $toolRow['tool_name'] . '" — quantity is 0. Please restock first.']);
        exit();
    }
    
    // Generate unique assignment number
    $prefix = 'ASGN';
    $year = date('Y');
    $month = date('m');
    
    // Get the last assignment number for this month/year
    $stmt = $conn->prepare("
        SELECT assignment_number FROM tool_assignments 
        WHERE assignment_number LIKE ? 
        ORDER BY id DESC LIMIT 1
    ");
    $pattern = $prefix . $year . $month . '%';
    $stmt->execute([$pattern]);
    $lastAssignment = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($lastAssignment && $lastAssignment['assignment_number']) {
        // Extract the sequence number and increment
        $lastNumber = intval(substr($lastAssignment['assignment_number'], -4));
        $sequence = str_pad($lastNumber + 1, 4, '0', STR_PAD_LEFT);
    } else {
        $sequence = '0001';
    }
    
    $assignment_number = $prefix . $year . $month . $sequence;
    
    // Get the request and technician info
    $stmt = $conn->prepare("
        SELECT tr.id, tr.technician_id, tr.request_number, tr.expected_duration_days
        FROM tool_requests tr
        INNER JOIN tool_request_items tri ON tr.id = tri.request_id
        WHERE tri.tool_id = ? AND tr.status = 'approved'
        LIMIT 1
    ");
    $stmt->execute([$tool_id]);
    $request = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$request) {
        throw new Exception('No approved request found for this tool');
    }
    
    // Create assignment record with assignment_number
    $assigned_by = $_SESSION['user_id'] ?? 1;
    $expected_return = date('Y-m-d H:i:s', strtotime($expected_return_date));
    
    $stmt = $conn->prepare("
        INSERT INTO tool_assignments (assignment_number, tool_id, technician_id, assigned_by, assigned_date, expected_return_date, request_id, status, notes)
        VALUES (?, ?, ?, ?, NOW(), ?, ?, 'active', ?)
    ");
    $stmt->execute([$assignment_number, $tool_id, $request['technician_id'], $assigned_by, $expected_return, $request['id'], $notes]);
    
    // Update tool: decrement quantity; set status to 'taken' only when qty reaches 0
    $newQty = $currentQty - 1;
    $newStatus = ($newQty <= 0) ? 'taken' : 'available';
    $stmt = $conn->prepare("UPDATE tools SET quantity = ?, status = ? WHERE id = ?");
    $stmt->execute([$newQty, $newStatus, $tool_id]);
    
    $conn->commit();
    
    echo json_encode(['success' => true, 'message' => 'Tool marked as taken with assignment #' . $assignment_number]);
    
} catch (Exception $e) {
    if (isset($conn)) $conn->rollBack();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>