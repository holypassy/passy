<?php
require_once __DIR__ . '/../models/Technician.php';
require_once __DIR__ . '/../models/TechnicianAttendance.php';
require_once __DIR__ . '/../models/ToolAssignment.php';
require_once __DIR__ . '/../middleware/AuthMiddleware.php';
require_once __DIR__ . '/../middleware/CorsMiddleware.php';
require_once __DIR__ . '/../helpers/Validator.php';
require_once __DIR__ . '/../helpers/Response.php';

class TechnicianController {
    private $technicianModel;
    private $attendanceModel;
    private $assignmentModel;
    
    public function __construct() {
        CorsMiddleware::handle();
        $this->technicianModel = new Technician();
        $this->attendanceModel = new TechnicianAttendance();
        $this->assignmentModel = new ToolAssignment();
    }
    
    // ==================== TECHNICIAN ENDPOINTS ====================
    
    public function getAll() {
        AuthMiddleware::authenticate();
        
        $filters = [
            'search' => $_GET['search'] ?? null,
            'department' => $_GET['department'] ?? null,
            'status' => $_GET['status'] ?? null,
            'has_tools' => isset($_GET['has_tools']) ? (bool)$_GET['has_tools'] : null,
            'has_overdue' => isset($_GET['has_overdue']) ? (bool)$_GET['has_overdue'] : null,
            'limit' => $_GET['limit'] ?? 100,
            'offset' => $_GET['offset'] ?? 0
        ];
        
        $technicians = $this->technicianModel->getAll($filters);
        $stats = $this->technicianModel->getStatistics();
        $deptStats = $this->technicianModel->getDepartmentStats();
        $experienceStats = $this->technicianModel->getExperienceStats();
        $hireStats = $this->technicianModel->getHireStats();
        $medicalStats = $this->technicianModel->getMedicalStats();
        $departments = $this->technicianModel->getDepartments();
        
        // Get tool assignment stats
        $toolStats = $this->assignmentModel->getStatistics();
        
        Response::json([
            'success' => true,
            'data' => [
                'technicians' => $technicians,
                'statistics' => [
                    'overall' => $stats,
                    'departments' => $deptStats,
                    'experience' => $experienceStats,
                    'hires' => $hireStats,
                    'medical' => $medicalStats,
                    'tools' => $toolStats
                ],
                'departments' => $departments
            ],
            'filters' => $filters
        ]);
    }
    
    public function getOne($id) {
        AuthMiddleware::authenticate();
        
        $technician = $this->technicianModel->findById($id);
        
        if (!$technician) {
            Response::json(['success' => false, 'message' => 'Technician not found'], 404);
        }
        
        // Get attendance history (last 30 days)
        $endDate = date('Y-m-d');
        $startDate = date('Y-m-d', strtotime('-30 days'));
        $attendance = $this->attendanceModel->getByTechnicianId($id, $startDate, $endDate);
        
        // Get current tool assignments
        $assignments = $this->assignmentModel->getByTechnicianId($id);
        
        $technician['attendance'] = $attendance;
        $technician['current_assignments'] = $assignments;
        
        Response::json(['success' => true, 'data' => $technician]);
    }
    
    public function create() {
        AuthMiddleware::requireRole(['admin', 'manager']);
        
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            Response::json(['success' => false, 'message' => 'Method not allowed'], 405);
        }
        
        $input = json_decode(file_get_contents('php://input'), true);
        
        $validation = Validator::validate($input, [
            'full_name' => 'required|min:3',
            'phone' => 'required|min:9'
        ]);
        
        if ($validation !== true) {
            Response::json(['success' => false, 'errors' => $validation], 400);
        }
        
        $result = $this->technicianModel->create($input);
        
        if ($result) {
            $technicianId = $this->technicianModel->conn->lastInsertId();
            $technician = $this->technicianModel->findById($technicianId);
            
            Response::json([
                'success' => true,
                'message' => 'Technician created successfully',
                'data' => $technician
            ]);
        } else {
            Response::json(['success' => false, 'message' => 'Failed to create technician'], 500);
        }
    }
    
    public function update($id) {
        AuthMiddleware::requireRole(['admin', 'manager']);
        
        if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
            Response::json(['success' => false, 'message' => 'Method not allowed'], 405);
        }
        
        $technician = $this->technicianModel->findById($id);
        if (!$technician) {
            Response::json(['success' => false, 'message' => 'Technician not found'], 404);
        }
        
        $input = json_decode(file_get_contents('php://input'), true);
        
        $result = $this->technicianModel->update($id, $input);
        
        if ($result) {
            Response::json(['success' => true, 'message' => 'Technician updated successfully']);
        } else {
            Response::json(['success' => false, 'message' => 'Failed to update technician'], 500);
        }
    }
    
    public function block($id) {
        AuthMiddleware::requireRole(['admin', 'manager']);
        
        $technician = $this->technicianModel->findById($id);
        if (!$technician) {
            Response::json(['success' => false, 'message' => 'Technician not found'], 404);
        }
        
        $result = $this->technicianModel->block($id);
        
        if ($result) {
            Response::json(['success' => true, 'message' => 'Technician blocked successfully']);
        } else {
            Response::json(['success' => false, 'message' => 'Failed to block technician'], 500);
        }
    }
    
    public function unblock($id) {
        AuthMiddleware::requireRole(['admin', 'manager']);
        
        $technician = $this->technicianModel->findById($id);
        if (!$technician) {
            Response::json(['success' => false, 'message' => 'Technician not found'], 404);
        }
        
        $result = $this->technicianModel->unblock($id);
        
        if ($result) {
            Response::json(['success' => true, 'message' => 'Technician unblocked successfully']);
        } else {
            Response::json(['success' => false, 'message' => 'Failed to unblock technician'], 500);
        }
    }
    
    public function delete($id) {
        AuthMiddleware::requireRole(['admin']);
        
        $technician = $this->technicianModel->findById($id);
        if (!$technician) {
            Response::json(['success' => false, 'message' => 'Technician not found'], 404);
        }
        
        // Check if technician has active tool assignments
        $activeAssignments = $this->assignmentModel->getByTechnicianId($id);
        if (!empty($activeAssignments)) {
            Response::json([
                'success' => false,
                'message' => 'Cannot delete technician with active tool assignments. Please return all tools first.'
            ], 400);
        }
        
        $result = $this->technicianModel->delete($id);
        
        if ($result) {
            Response::json(['success' => true, 'message' => 'Technician deleted successfully']);
        } else {
            Response::json(['success' => false, 'message' => 'Failed to delete technician'], 500);
        }
    }
    
    // ==================== STATISTICS ENDPOINTS ====================
    
    public function getStatistics() {
        AuthMiddleware::authenticate();
        
        $stats = $this->technicianModel->getStatistics();
        $deptStats = $this->technicianModel->getDepartmentStats();
        $experienceStats = $this->technicianModel->getExperienceStats();
        $hireStats = $this->technicianModel->getHireStats();
        $medicalStats = $this->technicianModel->getMedicalStats();
        $toolStats = $this->assignmentModel->getStatistics();
        
        Response::json([
            'success' => true,
            'data' => [
                'overall' => $stats,
                'departments' => $deptStats,
                'experience' => $experienceStats,
                'hires' => $hireStats,
                'medical' => $medicalStats,
                'tools' => $toolStats
            ]
        ]);
    }
    
    public function getDepartments() {
        AuthMiddleware::authenticate();
        
        $departments = $this->technicianModel->getDepartments();
        
        Response::json(['success' => true, 'data' => $departments]);
    }
    
    public function getActiveTechnicians() {
        AuthMiddleware::authenticate();
        
        $technicians = $this->technicianModel->getActiveTechnicians();
        
        Response::json(['success' => true, 'data' => $technicians]);
    }
    
    // ==================== ATTENDANCE ENDPOINTS ====================
    
    public function getAttendance($technicianId) {
        AuthMiddleware::authenticate();
        
        $startDate = $_GET['start_date'] ?? date('Y-m-01');
        $endDate = $_GET['end_date'] ?? date('Y-m-d');
        
        $attendance = $this->attendanceModel->getByTechnicianId($technicianId, $startDate, $endDate);
        
        Response::json(['success' => true, 'data' => $attendance]);
    }
    
    public function recordAttendance() {
        AuthMiddleware::requireRole(['admin', 'manager']);
        
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            Response::json(['success' => false, 'message' => 'Method not allowed'], 405);
        }
        
        $input = json_decode(file_get_contents('php://input'), true);
        
        $validation = Validator::validate($input, [
            'technician_id' => 'required|numeric',
            'attendance_date' => 'required|date'
        ]);
        
        if ($validation !== true) {
            Response::json(['success' => false, 'errors' => $validation], 400);
        }
        
        $result = $this->attendanceModel->recordAttendance($input);
        
        if ($result) {
            Response::json(['success' => true, 'message' => 'Attendance recorded successfully']);
        } else {
            Response::json(['success' => false, 'message' => 'Failed to record attendance'], 500);
        }
    }
    
    // ==================== TOOL ASSIGNMENT ENDPOINTS ====================
    
    public function getToolAssignments($technicianId) {
        AuthMiddleware::authenticate();
        
        $assignments = $this->assignmentModel->getByTechnicianId($technicianId);
        
        Response::json(['success' => true, 'data' => $assignments]);
    }
}
?>