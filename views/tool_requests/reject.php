<?php
// views/tool_requests/reject.php - Reject a tool request
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

// Get rejection reason from request body
$input = json_decode(file_get_contents('php://input'), true);
$rejection_reason = $input['reason'] ?? 'No reason provided';

try {
    $conn = new PDO("mysql:host=localhost;dbname=savant_motors_pos", "root", "");
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Check if request exists and is pending
    $stmt = $conn->prepare("
        SELECT id, status FROM tool_requests WHERE id = ?
    ");
    $stmt->execute([$request_id]);
    $request = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$request) {
        throw new Exception('Request not found');
    }
    
    if ($request['status'] !== 'pending') {
        throw new Exception('Request is already ' . $request['status']);
    }
    
    // Update request status to rejected with reason
    $stmt = $conn->prepare("
        UPDATE tool_requests 
        SET status = 'rejected', 
            rejection_reason = ?,
            updated_at = NOW() 
        WHERE id = ?
    ");
    $stmt->execute([$rejection_reason, $request_id]);
    
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'message' => 'Request rejected']);
    
} catch (Exception $e) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>