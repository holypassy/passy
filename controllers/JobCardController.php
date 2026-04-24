<?php
require_once __DIR__ . '/../models/JobCard.php';
require_once __DIR__ . '/../models/JobCardItem.php';
require_once __DIR__ . '/../models/Customer.php';
require_once __DIR__ . '/../models/Technician.php';
require_once __DIR__ . '/../middleware/AuthMiddleware.php';
require_once __DIR__ . '/../middleware/CorsMiddleware.php';
require_once __DIR__ . '/../helpers/Validator.php';
require_once __DIR__ . '/../helpers/Response.php';

class JobCardController {
    private $jobCardModel;
    private $jobItemModel;
    private $customerModel;
    private $technicianModel;
    
    public function __construct() {
        CorsMiddleware::handle();
        $this->jobCardModel = new JobCard();
        $this->jobItemModel = new JobCardItem();
        $this->customerModel = new Customer();
        $this->technicianModel = new Technician();
    }
    
    // ==================== JOB CARD ENDPOINTS ====================
    
    public function getAll() {
        AuthMiddleware::authenticate();
        
        $filters = [
            'search' => $_GET['search'] ?? null,
            'status' => $_GET['status'] ?? null,
            'priority' => $_GET['priority'] ?? null,
            'assigned_technician' => $_GET['technician_id'] ?? null,
            'from_date' => $_GET['from_date'] ?? null,
            'to_date' => $_GET['to_date'] ?? null,
            'limit' => $_GET['limit'] ?? 100,
            'offset' => $_GET['offset'] ?? 0
        ];
        
        $jobCards = $this->jobCardModel->getAll($filters);
        $stats = $this->jobCardModel->getStatistics($filters['from_date'], $filters['to_date']);
        
        Response::json([
            'success' => true,
            'data' => $jobCards,
            'statistics' => $stats,
            'filters' => $filters
        ]);
    }
    
    public function getOne($id) {
        AuthMiddleware::authenticate();
        
        $jobCard = $this->jobCardModel->findById($id);
        
        if (!$jobCard) {
            Response::json(['success' => false, 'message' => 'Job card not found'], 404);
        }
        
        $items = $this->jobItemModel->getByJobId($id);
        $jobCard['items'] = $items;
        
        Response::json(['success' => true, 'data' => $jobCard]);
    }
    
    public function create() {
        AuthMiddleware::authenticate();
        
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            Response::json(['success' => false, 'message' => 'Method not allowed'], 405);
        }
        
        $input = json_decode(file_get_contents('php://input'), true);
        
        $validation = Validator::validate($input, [
            'customer_id' => 'required|numeric'
        ]);
        
        if ($validation !== true) {
            Response::json(['success' => false, 'errors' => $validation], 400);
        }
        
        $session = AuthMiddleware::getCurrentUser();
        $input['created_by'] = $session['id'];
        
        $result = $this->jobCardModel->create($input);
        
        if ($result) {
            $jobCardId = $this->jobCardModel->conn->lastInsertId();
            Response::json([
                'success' => true, 
                'message' => 'Job card created successfully',
                'id' => $jobCardId,
                'job_number' => $input['job_number'] ?? null
            ]);
        } else {
            Response::json(['success' => false, 'message' => 'Failed to create job card'], 500);
        }
    }
    
    public function update($id) {
        AuthMiddleware::requireRole(['admin', 'manager']);
        
        if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
            Response::json(['success' => false, 'message' => 'Method not allowed'], 405);
        }
        
        $jobCard = $this->jobCardModel->findById($id);
        if (!$jobCard) {
            Response::json(['success' => false, 'message' => 'Job card not found'], 404);
        }
        
        $input = json_decode(file_get_contents('php://input'), true);
        
        $result = $this->jobCardModel->update($id, $input);
        
        if ($result) {
            Response::json(['success' => true, 'message' => 'Job card updated successfully']);
        } else {
            Response::json(['success' => false, 'message' => 'Failed to update job card'], 500);
        }
    }
    
    public function updateStatus($id) {
        AuthMiddleware::requireRole(['admin', 'manager', 'technician']);
        
        if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
            Response::json(['success' => false, 'message' => 'Method not allowed'], 405);
        }
        
        $input = json_decode(file_get_contents('php://input'), true);
        
        $validation = Validator::validate($input, [
            'status' => 'required|in:pending,in_progress,completed,cancelled'
        ]);
        
        if ($validation !== true) {
            Response::json(['success' => false, 'errors' => $validation], 400);
        }
        
        $jobCard = $this->jobCardModel->findById($id);
        if (!$jobCard) {
            Response::json(['success' => false, 'message' => 'Job card not found'], 404);
        }
        
        $completedDate = $input['status'] === 'completed' ? date('Y-m-d') : null;
        $result = $this->jobCardModel->updateStatus($id, $input['status'], $completedDate);
        
        if ($result) {
            Response::json(['success' => true, 'message' => 'Job status updated successfully']);
        } else {
            Response::json(['success' => false, 'message' => 'Failed to update status'], 500);
        }
    }
    
    public function delete($id) {
        AuthMiddleware::requireRole(['admin']);
        
        $result = $this->jobCardModel->delete($id);
        
        if ($result) {
            Response::json(['success' => true, 'message' => 'Job card deleted successfully']);
        } else {
            Response::json(['success' => false, 'message' => 'Failed to delete job card'], 500);
        }
    }
    
    // ==================== JOB ITEM ENDPOINTS ====================
    
    public function getJobItems($jobCardId) {
        AuthMiddleware::authenticate();
        
        $items = $this->jobItemModel->getByJobId($jobCardId);
        $total = $this->jobItemModel->getTotalByJobId($jobCardId);
        
        Response::json([
            'success' => true,
            'data' => $items,
            'total' => $total
        ]);
    }
    
    public function addJobItem() {
        AuthMiddleware::authenticate();
        
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            Response::json(['success' => false, 'message' => 'Method not allowed'], 405);
        }
        
        $input = json_decode(file_get_contents('php://input'), true);
        
        $validation = Validator::validate($input, [
            'job_card_id' => 'required|numeric',
            'description' => 'required|min:3',
            'quantity' => 'required|numeric|min:0.01',
            'unit_price' => 'required|numeric|min:0'
        ]);
        
        if ($validation !== true) {
            Response::json(['success' => false, 'errors' => $validation], 400);
        }
        
        $result = $this->jobItemModel->create($input);
        
        if ($result) {
            Response::json(['success' => true, 'message' => 'Item added successfully']);
        } else {
            Response::json(['success' => false, 'message' => 'Failed to add item'], 500);
        }
    }
    
    public function updateJobItem($id) {
        AuthMiddleware::authenticate();
        
        if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
            Response::json(['success' => false, 'message' => 'Method not allowed'], 405);
        }
        
        $input = json_decode(file_get_contents('php://input'), true);
        
        $result = $this->jobItemModel->update($id, $input);
        
        if ($result) {
            Response::json(['success' => true, 'message' => 'Item updated successfully']);
        } else {
            Response::json(['success' => false, 'message' => 'Failed to update item'], 500);
        }
    }
    
    public function deleteJobItem($id) {
        AuthMiddleware::authenticate();
        
        $result = $this->jobItemModel->delete($id);
        
        if ($result) {
            Response::json(['success' => true, 'message' => 'Item deleted successfully']);
        } else {
            Response::json(['success' => false, 'message' => 'Failed to delete item'], 500);
        }
    }
    
    // ==================== STATISTICS ENDPOINTS ====================
    
    public function getStatistics() {
        AuthMiddleware::authenticate();
        
        $startDate = $_GET['start_date'] ?? null;
        $endDate = $_GET['end_date'] ?? null;
        
        $stats = $this->jobCardModel->getStatistics($startDate, $endDate);
        
        Response::json(['success' => true, 'data' => $stats]);
    }
    
    public function getTechnicianJobs($technicianId) {
        AuthMiddleware::authenticate();
        
        $startDate = $_GET['start_date'] ?? null;
        $endDate = $_GET['end_date'] ?? null;
        
        $jobs = $this->jobCardModel->getJobsByTechnician($technicianId, $startDate, $endDate);
        
        Response::json(['success' => true, 'data' => $jobs]);
    }
}
?>