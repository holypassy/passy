<?php
session_start();
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../models/Attendance.php';

header('Content-Type: application/json');

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$input = json_decode(file_get_contents('php://input'), true);

if (empty($input['technician_id'])) {
    echo json_encode(['success' => false, 'message' => 'Technician ID required']);
    exit();
}

try {
    $attendance = new Attendance();
    $checkOutTime = $input['check_out_time'] ?? date('H:i:s');
    $result = $attendance->checkOut($input['technician_id'], date('Y-m-d'), $checkOutTime);
    
    echo json_encode($result);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>