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
    
    $data = [
        'technician_id' => $input['technician_id'],
        'attendance_date' => date('Y-m-d'),
        'check_in_time' => $input['check_in_time'] ?? date('H:i:s'),
        'status' => $input['status'] ?? 'present',
        'notes' => $input['notes'] ?? null
    ];
    
    $result = $attendance->checkIn($data);
    
    echo json_encode($result);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>