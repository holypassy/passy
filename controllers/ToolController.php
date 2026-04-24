<?php
namespace App\Controllers;

use Core\Controller;
use App\Models\Tool;
use App\Models\ToolAssignment;
use App\Models\ToolRequest;
use Utils\CSVExporter;
use Utils\PDFGenerator;

class ToolController extends Controller {
    private $toolModel;
    private $assignmentModel;
    private $requestModel;
    
    public function __construct() {
        $this->toolModel = new Tool();
        $this->assignmentModel = new ToolAssignment();
        $this->requestModel = new ToolRequest();
    }
    
    public function index() {
        $tools = $this->toolModel->getAllWithStatus();
        $stats = $this->toolModel->getStatistics();
        $categories = $this->toolModel->getCategories();
        $pendingRequests = $this->requestModel->getPendingRequests();
        
        $this->view('tools/index', [
            'tools' => $tools,
            'stats' => $stats,
            'categories' => $categories,
            'pendingRequests' => $pendingRequests
        ]);
    }
    
    public function create() {
        $this->view('tools/create');
    }
    
    public function store() {
        $data = $this->sanitize($_POST);
        
        $rules = [
            'tool_name' => 'required|min:2|max:255',
            'category' => 'required',
            'purchase_price' => 'numeric'
        ];
        
        $errors = $this->validate($data, $rules);
        
        if (!empty($errors)) {
            $_SESSION['errors'] = $errors;
            $this->redirect('/tools/create');
            return;
        }
        
        $data['tool_code'] = $this->toolModel->generateToolCode();
        $data['created_by'] = $this->getCurrentUser();
        $data['current_value'] = $data['purchase_price'] ?? 0;
        $data['status'] = 'available';
        
        $toolId = $this->toolModel->create($data);
        
        $_SESSION['success'] = 'Tool added successfully!';
        $this->redirect("/tools/view/{$toolId}");
    }
    
    public function view($id) {
        $tool = $this->toolModel->getWithDetails($id);
        
        if (!$tool) {
            $_SESSION['error'] = 'Tool not found';
            $this->redirect('/tools');
            return;
        }
        
        $this->view('tools/view', ['tool' => $tool]);
    }
    
    public function edit($id) {
        $tool = $this->toolModel->find($id);
        
        if (!$tool) {
            $_SESSION['error'] = 'Tool not found';
            $this->redirect('/tools');
            return;
        }
        
        $this->view('tools/edit', ['tool' => $tool]);
    }
    
    public function update($id) {
        $data = $this->sanitize($_POST);
        
        $this->toolModel->update($id, $data);
        
        $_SESSION['success'] = 'Tool updated successfully!';
        $this->redirect("/tools/view/{$id}");
    }
    
    public function delete($id) {
        $this->toolModel->update($id, ['is_active' => 0]);
        
        $_SESSION['success'] = 'Tool deactivated successfully!';
        $this->redirect('/tools');
    }
    
    public function taken() {
        $takenTools = $this->toolModel->getTakenTools();
        
        $this->view('tools/taken', ['takenTools' => $takenTools]);
    }
    
    public function assign() {
        $data = json_decode(file_get_contents('php://input'), true);
        
        $toolId = $data['tool_id'] ?? 0;
        $requestId = $data['request_id'] ?? null;
        $expectedReturnDate = $data['expected_return_date'] ?? null;
        
        // Get request details to get technician
        if ($requestId) {
            $request = $this->requestModel->find($requestId);
            if (!$request) {
                $this->json(['success' => false, 'message' => 'Request not found'], 404);
                return;
            }
            $technicianId = $request['technician_id'];
        } else {
            $technicianId = $data['technician_id'] ?? 0;
        }
        
        if (!$toolId || !$technicianId) {
            $this->json(['success' => false, 'message' => 'Tool and technician are required'], 422);
            return;
        }
        
        try {
            $assignmentId = $this->assignmentModel->assignTool($toolId, $technicianId, $requestId, $expectedReturnDate);
            $this->json(['success' => true, 'message' => 'Tool assigned successfully', 'assignment_id' => $assignmentId]);
        } catch (\Exception $e) {
            $this->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }
    
    public function return($id) {
        $data = json_decode(file_get_contents('php://input'), true);
        $condition = $data['condition'] ?? null;
        
        try {
            $this->assignmentModel->returnTool($id, $condition);
            $this->json(['success' => true, 'message' => 'Tool returned successfully']);
        } catch (\Exception $e) {
            $this->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }
    
    public function maintenance($id) {
        $this->toolModel->updateStatus($id, 'maintenance');
        $_SESSION['success'] = 'Tool marked for maintenance';
        $this->redirect("/tools/view/{$id}");
    }
    
    public function export() {
        $format = $_GET['format'] ?? 'csv';
        $tools = $this->toolModel->getAllWithStatus();
        
        $exporter = new CSVExporter();
        
        if ($format === 'csv') {
            $exporter->exportTools($tools);
        } elseif ($format === 'pdf') {
            $pdf = new PDFGenerator();
            $pdf->generateToolReport($tools);
        }
    }
}