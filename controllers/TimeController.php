<?php
require_once __DIR__ . '/../models/Technician.php';
require_once __DIR__ . '/../models/Attendance.php';
require_once __DIR__ . '/../models/Overtime.php';
require_once __DIR__ . '/../models/DailyReport.php';
require_once __DIR__ . '/../middleware/AuthMiddleware.php';
require_once __DIR__ . '/../middleware/CorsMiddleware.php';
require_once __DIR__ . '/../helpers/Validator.php';
require_once __DIR__ . '/../helpers/Response.php';
require_once __DIR__ . '/../helpers/DateHelper.php';

class TimeController {
    private $technicianModel;
    private $attendanceModel;
    private $overtimeModel;
    private $reportModel;
    
    public function __construct() {
        CorsMiddleware::handle();
        $this->technicianModel = new Technician();
        $this->attendanceModel = new Attendance();
        $this->overtimeModel = new Overtime();
        $this->reportModel = new DailyReport();
    }
    
    // ==================== ATTENDANCE ENDPOINTS ====================
    
    public function getAttendance() {
        AuthMiddleware::authenticate();
        
        $date = $_GET['date'] ?? date('Y-m-d');
        $filters = [
            'technician_id' => $_GET['technician_id'] ?? null,
            'status' => $_GET['status'] ?? null
        ];
        
        $attendance = $this->attendanceModel->getByDate($date, $filters);
        
        Response::json([
            'success' => true,
            'data' => $attendance,
            'date' => $date
        ]);
    }
    
    public function checkIn() {
        AuthMiddleware::authenticate();
        
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            Response::json(['success' => false, 'message' => 'Method not allowed'], 405);
        }
        
        $input = json_decode(file_get_contents('php://input'), true);
        
        $validation = Validator::validate($input, [
            'technician_id' => 'required|numeric',
            'check_in_time' => 'required'
        ]);
        
        if ($validation !== true) {
            Response::json(['success' => false, 'errors' => $validation], 400);
        }
        
        $data = [
            'technician_id' => $input['technician_id'],
            'attendance_date' => date('Y-m-d'),
            'check_in_time' => $input['check_in_time'],
            'status' => $input['status'] ?? 'present',
            'notes' => $input['notes'] ?? null
        ];
        
        $result = $this->attendanceModel->checkIn($data);
        
        if ($result['success']) {
            Response::json(['success' => true, 'message' => 'Check-in successful', 'id' => $result['id']]);
        } else {
            Response::json(['success' => false, 'message' => $result['message']], 400);
        }
    }
    
    public function checkOut() {
        AuthMiddleware::authenticate();
        
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            Response::json(['success' => false, 'message' => 'Method not allowed'], 405);
        }
        
        $input = json_decode(file_get_contents('php://input'), true);
        
        $validation = Validator::validate($input, [
            'technician_id' => 'required|numeric'
        ]);
        
        if ($validation !== true) {
            Response::json(['success' => false, 'errors' => $validation], 400);
        }
        
        $checkOutTime = $input['check_out_time'] ?? date('H:i:s');
        $result = $this->attendanceModel->checkOut($input['technician_id'], date('Y-m-d'), $checkOutTime);
        
        if ($result['success']) {
            Response::json(['success' => true, 'message' => 'Check-out successful']);
        } else {
            Response::json(['success' => false, 'message' => $result['message']], 400);
        }
    }
    
    public function getWeeklySummary() {
        AuthMiddleware::authenticate();
        
        $date = $_GET['date'] ?? date('Y-m-d');
        $weekStart = DateHelper::getWeekStart($date);
        $weekEnd = DateHelper::getWeekEnd($date);
        
        $summary = $this->attendanceModel->getWeeklySummary($weekStart, $weekEnd);
        
        Response::json([
            'success' => true,
            'data' => $summary,
            'week' => [
                'start' => $weekStart,
                'end' => $weekEnd
            ]
        ]);
    }
    
    public function getMonthlyStats() {
        AuthMiddleware::authenticate();
        
        $date = $_GET['date'] ?? date('Y-m-d');
        $monthStart = DateHelper::getMonthStart($date);
        $monthEnd = DateHelper::getMonthEnd($date);
        
        $stats = $this->attendanceModel->getMonthlyStatistics($monthStart, $monthEnd);
        $overtimeStats = $this->overtimeModel->getStatistics($monthStart, $monthEnd);
        
        Response::json([
            'success' => true,
            'data' => [
                'attendance' => $stats,
                'overtime' => $overtimeStats
            ]
        ]);
    }
    
    // ==================== OVERTIME ENDPOINTS ====================
    
    public function getOvertimeRequests() {
        AuthMiddleware::authenticate();
        
        $filters = [
            'status' => $_GET['status'] ?? null,
            'technician_id' => $_GET['technician_id'] ?? null,
            'from_date' => $_GET['from_date'] ?? null,
            'to_date' => $_GET['to_date'] ?? null
        ];
        
        $requests = $this->overtimeModel->getAll($filters);
        
        Response::json([
            'success' => true,
            'data' => $requests
        ]);
    }
    
    public function createOvertimeRequest() {
        AuthMiddleware::authenticate();
        
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            Response::json(['success' => false, 'message' => 'Method not allowed'], 405);
        }
        
        $input = json_decode(file_get_contents('php://input'), true);
        $session = AuthMiddleware::getCurrentUser();
        
        $validation = Validator::validate($input, [
            'technician_id' => 'required|numeric',
            'overtime_date' => 'required|date',
            'hours_requested' => 'required|numeric|min:0.5|max:12',
            'reason' => 'required|min:5'
        ]);
        
        if ($validation !== true) {
            Response::json(['success' => false, 'errors' => $validation], 400);
        }
        
        $input['requested_by'] = $session['id'];
        
        $result = $this->overtimeModel->create($input);
        
        if ($result) {
            Response::json(['success' => true, 'message' => 'Overtime request submitted successfully']);
        } else {
            Response::json(['success' => false, 'message' => 'Failed to submit request'], 500);
        }
    }
    
    public function approveOvertime($id) {
        AuthMiddleware::requireRole(['admin', 'manager']);
        
        $session = AuthMiddleware::getCurrentUser();
        $result = $this->overtimeModel->approve($id, $session['id']);
        
        if ($result) {
            Response::json(['success' => true, 'message' => 'Overtime approved']);
        } else {
            Response::json(['success' => false, 'message' => 'Failed to approve'], 500);
        }
    }
    
    public function rejectOvertime($id) {
        AuthMiddleware::requireRole(['admin', 'manager']);
        
        $input = json_decode(file_get_contents('php://input'), true);
        $session = AuthMiddleware::getCurrentUser();
        
        $reason = $input['reason'] ?? null;
        $result = $this->overtimeModel->reject($id, $session['id'], $reason);
        
        if ($result) {
            Response::json(['success' => true, 'message' => 'Overtime rejected']);
        } else {
            Response::json(['success' => false, 'message' => 'Failed to reject'], 500);
        }
    }
    
    // ==================== DAILY REPORT ENDPOINTS ====================
    
    public function getDailyReports() {
        AuthMiddleware::authenticate();
        
        $date = $_GET['date'] ?? date('Y-m-d');
        $filters = [
            'technician_id' => $_GET['technician_id'] ?? null
        ];
        
        $reports = $this->reportModel->getByDate($date, $filters);
        
        Response::json([
            'success' => true,
            'data' => $reports,
            'date' => $date
        ]);
    }
    
    public function createDailyReport() {
        AuthMiddleware::authenticate();
        
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            Response::json(['success' => false, 'message' => 'Method not allowed'], 405);
        }
        
        $input = json_decode(file_get_contents('php://input'), true);
        
        $validation = Validator::validate($input, [
            'technician_id' => 'required|numeric'
        ]);
        
        if ($validation !== true) {
            Response::json(['success' => false, 'errors' => $validation], 400);
        }
        
        $input['report_date'] = date('Y-m-d');
        
        $result = $this->reportModel->create($input);
        
        if ($result['success']) {
            Response::json(['success' => true, 'message' => 'Daily report submitted successfully']);
        } else {
            Response::json(['success' => false, 'message' => $result['message']], 400);
        }
    }
    
    // ==================== TECHNICIAN ENDPOINTS ====================
    
    public function getTechnicians() {
        AuthMiddleware::authenticate();
        
        $filters = [
            'status' => $_GET['status'] ?? 'active',
            'department' => $_GET['department'] ?? null,
            'search' => $_GET['search'] ?? null
        ];
        
        $technicians = $this->technicianModel->getAll($filters);
        $counts = $this->technicianModel->getCount();
        
        Response::json([
            'success' => true,
            'data' => $technicians,
            'statistics' => $counts
        ]);
    }
    
    public function getTechnician($id) {
        AuthMiddleware::authenticate();
        
        $technician = $this->technicianModel->findById($id);
        
        if (!$technician) {
            Response::json(['success' => false, 'message' => 'Technician not found'], 404);
        }
        
        Response::json(['success' => true, 'data' => $technician]);
    }
}
?>