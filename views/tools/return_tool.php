<?php
// return_tool.php - Return a tool
session_start();

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$input = json_decode(file_get_contents('php://input'), true);
$tool_id = $input['tool_id'] ?? 0;
$assignment_id = $input['assignment_id'] ?? 0;
$condition = $input['condition'] ?? 'Good';
$notes = $input['notes'] ?? '';

if (!$tool_id || !$assignment_id) {
    echo json_encode(['success' => false, 'message' => 'Missing required fields']);
    exit();
}

try {
    $conn = new PDO("mysql:host=localhost;dbname=savant_motors_pos", "root", "");
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Check what columns exist in tool_assignments
    $columns = $conn->query("SHOW COLUMNS FROM tool_assignments")->fetchAll(PDO::FETCH_COLUMN);
    $hasReturnCondition = in_array('return_condition', $columns);
    $hasReturnNotes = in_array('return_notes', $columns);
    
    $conn->beginTransaction();
    
    // Update assignment with return info
    if ($hasReturnCondition && $hasReturnNotes) {
        $stmt = $conn->prepare("
            UPDATE tool_assignments 
            SET actual_return_date = NOW(), 
                status = 'returned',
                return_condition = ?,
                return_notes = ?
            WHERE id = ? AND tool_id = ?
        ");
        $stmt->execute([$condition, $notes, $assignment_id, $tool_id]);
    } elseif ($hasReturnCondition) {
        $stmt = $conn->prepare("
            UPDATE tool_assignments 
            SET actual_return_date = NOW(), 
                status = 'returned',
                return_condition = ?
            WHERE id = ? AND tool_id = ?
        ");
        $stmt->execute([$condition, $assignment_id, $tool_id]);
    } elseif ($hasReturnNotes) {
        $stmt = $conn->prepare("
            UPDATE tool_assignments 
            SET actual_return_date = NOW(), 
                status = 'returned',
                return_notes = ?
            WHERE id = ? AND tool_id = ?
        ");
        $stmt->execute([$notes, $assignment_id, $tool_id]);
    } else {
        $stmt = $conn->prepare("
            UPDATE tool_assignments 
            SET actual_return_date = NOW(), 
                status = 'returned'
            WHERE id = ? AND tool_id = ?
        ");
        $stmt->execute([$assignment_id, $tool_id]);
    }
    
    // Update tool: increment quantity back and set status to available
    $stmt = $conn->prepare("
        UPDATE tools 
        SET quantity = COALESCE(quantity, 0) + 1,
            status   = 'available'
        WHERE id = ?
    ");
    $stmt->execute([$tool_id]);
    
    // Check if all tools from the request are returned (if request_id exists)
    $hasRequestId = in_array('request_id', $columns);
    if ($hasRequestId) {
        $stmt = $conn->prepare("
            SELECT request_id FROM tool_assignments WHERE id = ?
        ");
        $stmt->execute([$assignment_id]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result && $result['request_id']) {
            $stmt = $conn->prepare("
                SELECT COUNT(*) as pending_returns
                FROM tool_assignments 
                WHERE request_id = ? AND actual_return_date IS NULL
            ");
            $stmt->execute([$result['request_id']]);
            $pending = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($pending['pending_returns'] == 0) {
                // All tools returned - update request status to completed
                $stmt = $conn->prepare("
                    UPDATE tool_requests 
                    SET status = 'completed', updated_at = NOW() 
                    WHERE id = ?
                ");
                $stmt->execute([$result['request_id']]);
            }
        }
    }
    
    $conn->commit();
    
    echo json_encode(['success' => true, 'message' => 'Tool returned successfully']);
    
} catch (Exception $e) {
    if (isset($conn)) $conn->rollBack();
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>