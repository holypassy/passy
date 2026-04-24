<?php
require_once __DIR__ . '/../controllers/TimeController.php';

$controller = new TimeController();
$action = $_GET['action'] ?? '';
$id = isset($_GET['id']) ? (int)$_GET['id'] : null;

switch ($action) {
    // Technician endpoints
    case 'technicians':
        $controller->getTechnicians();
        break;
    case 'technician':
        if ($id) {
            $controller->getTechnician($id);
        } else {
            Response::json(['error' => 'Technician ID required'], 400);
        }
        break;
    
    // Attendance endpoints
    case 'attendance':
        $controller->getAttendance();
        break;
    case 'checkin':
        $controller->checkIn();
        break;
    case 'checkout':
        $controller->checkOut();
        break;
    case 'weekly-summary':
        $controller->getWeeklySummary();
        break;
    case 'monthly-stats':
        $controller->getMonthlyStats();
        break;
    
    // Overtime endpoints
    case 'overtime-requests':
        $controller->getOvertimeRequests();
        break;
    case 'create-overtime':
        $controller->createOvertimeRequest();
        break;
    case 'approve-overtime':
        if ($id) {
            $controller->approveOvertime($id);
        } else {
            Response::json(['error' => 'Overtime ID required'], 400);
        }
        break;
    case 'reject-overtime':
        if ($id) {
            $controller->rejectOvertime($id);
        } else {
            Response::json(['error' => 'Overtime ID required'], 400);
        }
        break;
    
    // Daily report endpoints
    case 'daily-reports':
        $controller->getDailyReports();
        break;
    case 'create-report':
        $controller->createDailyReport();
        break;
    
    default:
        Response::json(['error' => 'Endpoint not found'], 404);
}
?>