<?php
require_once __DIR__ . '/../models/Tool.php';
require_once __DIR__ . '/../models/ToolAssignment.php';
require_once __DIR__ . '/../middleware/AuthMiddleware.php';
require_once __DIR__ . '/../middleware/CorsMiddleware.php';
require_once __DIR__ . '/../helpers/Validator.php';
require_once __DIR__ . '/../helpers/Response.php';

class ToolInventoryController {
    private $toolModel;
    private $assignmentModel;
    
    public function __construct() {
        CorsMiddleware::handle();
        $this->toolModel = new Tool();
        $this->assignmentModel = new ToolAssignment();
    }
    
    public function getInventory() {
        AuthMiddleware::authenticate();
        
        $filters = [
            'search' => $_GET['search'] ?? null,
            'category' => $_GET['category'] ?? null,
            'status' => $_GET['status'] ?? null,
            'limit' => $_GET['limit'] ?? 100,
            'offset' => $_GET['offset'] ?? 0
        ];
        
        $tools = $this->toolModel->getAll($filters);
        $stats = $this->toolModel->getStatistics();
        $categoryStats = $this->toolModel->getCategoryStats();
        $recentTools = $this->toolModel->getRecentTools();
        $maintenanceNeeded = $this->toolModel->getMaintenanceNeeded();
        $activeAssignments = $this->assignmentModel->getActiveAssignments();
        
        Response::json([
            'success' => true,
            'data' => [
                'tools' => $tools,
                'statistics' => $stats,
                'categories' => $categoryStats,
                'recent_tools' => $recentTools,
                'maintenance_needed' => $maintenanceNeeded,
                'active_assignments' => $activeAssignments
            ],
            'filters' => $filters
        ]);
    }
    
    public function getTool($id) {
        AuthMiddleware::authenticate();
        
        $tool = $this->toolModel->findById($id);
        
        if (!$tool) {
            Response::json(['success' => false, 'message' => 'Tool not found'], 404);
        }
        
        $assignments = $this->assignmentModel->getByToolId($id);
        $tool['assignments'] = $assignments;
        
        Response::json(['success' => true, 'data' => $tool]);
    }
    
    public function addTool() {
        AuthMiddleware::requireRole(['admin', 'manager']);
        
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            Response::json(['success' => false, 'message' => 'Method not allowed'], 405);
        }
        
        $input = json_decode(file_get_contents('php://input'), true);
        
        $validation = Validator::validate($input, [
            'tool_name' => 'required|min:3'
        ]);
        
        if ($validation !== true) {
            Response::json(['success' => false, 'errors' => $validation], 400);
        }
        
        $result = $this->toolModel->create($input);
        
        if ($result) {
            $toolId = $this->toolModel->conn->lastInsertId();
            $tool = $this->toolModel->findById($toolId);
            $stats = $this->toolModel->getStatistics();
            
            Response::json([
                'success' => true,
                'message' => 'Tool added successfully',
                'tool' => $tool,
                'statistics' => $stats
            ]);
        } else {
            Response::json(['success' => false, 'message' => 'Failed to add tool'], 500);
        }
    }
    
    public function updateTool($id) {
        AuthMiddleware::requireRole(['admin', 'manager']);
        
        if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
            Response::json(['success' => false, 'message' => 'Method not allowed'], 405);
        }
        
        $tool = $this->toolModel->findById($id);
        if (!$tool) {
            Response::json(['success' => false, 'message' => 'Tool not found'], 404);
        }
        
        $input = json_decode(file_get_contents('php://input'), true);
        
        $result = $this->toolModel->update($id, $input);
        
        if ($result) {
            Response::json(['success' => true, 'message' => 'Tool updated successfully']);
        } else {
            Response::json(['success' => false, 'message' => 'Failed to update tool'], 500);
        }
    }
    
    public function deleteTool($id) {
        AuthMiddleware::requireRole(['admin']);
        
        $tool = $this->toolModel->findById($id);
        if (!$tool) {
            Response::json(['success' => false, 'message' => 'Tool not found'], 404);
        }
        
        // Check if tool has active assignments
        $activeAssignments = $this->assignmentModel->getByToolId($id);
        $hasActive = array_filter($activeAssignments, function($a) {
            return $a['status'] === 'assigned' && is_null($a['actual_return_date']);
        });
        
        if (!empty($hasActive)) {
            Response::json([
                'success' => false,
                'message' => 'Cannot delete tool with active assignments. Please return the tool first.'
            ], 400);
        }
        
        $result = $this->toolModel->delete($id);
        
        if ($result) {
            Response::json(['success' => true, 'message' => 'Tool deleted successfully']);
        } else {
            Response::json(['success' => false, 'message' => 'Failed to delete tool'], 500);
        }
    }
    
    public function getStatistics() {
        AuthMiddleware::authenticate();
        
        $stats = $this->toolModel->getStatistics();
        $categoryStats = $this->toolModel->getCategoryStats();
        $assignmentStats = $this->assignmentModel->getStatistics();
        $overdueTools = $this->assignmentModel->getOverdueTools();
        
        Response::json([
            'success' => true,
            'data' => [
                'overall' => $stats,
                'categories' => $categoryStats,
                'assignments' => $assignmentStats,
                'overdue_tools' => $overdueTools
            ]
        ]);
    }
    
    public function getCategories() {
        AuthMiddleware::authenticate();
        
        $categories = $this->toolModel->getCategoryStats();
        
        Response::json(['success' => true, 'data' => $categories]);
    }
    
    public function getMaintenanceNeeded() {
        AuthMiddleware::authenticate();
        
        $tools = $this->toolModel->getMaintenanceNeeded();
        
        Response::json(['success' => true, 'data' => $tools]);
    }
    
    public function getRecentTools() {
        AuthMiddleware::authenticate();
        
        $days = isset($_GET['days']) ? (int)$_GET['days'] : 30;
        $tools = $this->toolModel->getRecentTools($days);
        
        Response::json(['success' => true, 'data' => $tools]);
    }
}
?>