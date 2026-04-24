<?php
namespace App\Controllers;

use Core\Controller;
use App\Models\ToolRequest;
use App\Models\Tool;
use App\Models\Technician;

class ToolRequestController extends Controller {
    private $requestModel;
    private $toolModel;
    private $technicianModel;
    
    public function __construct() {
        $this->requestModel = new ToolRequest();
        $this->toolModel = new Tool();
        $this->technicianModel = new Technician();
    }
    
    public function index() {
        $userId = $this->getCurrentUser();
        $userRole = $_SESSION['role'] ?? 'user';
        
        // Get pending requests (for admin/manager)
        $pendingRequests = [];
        if (in_array($userRole, ['admin', 'manager'])) {
            $pendingRequests = $this->requestModel->getPendingRequests();
        }
        
        // Get user's own requests
        if ($userRole == 'technician') {
            $technician = $this->technicianModel->findByUserId($userId);
            if ($technician) {
                $myRequests = $this->requestModel->getByTechnician($technician['id']);
            } else {
                $myRequests = [];
            }
        } else {
            $myRequests = $this->requestModel->getRecentRequests(20);
        }
        
        // Get available tools
        $availableTools = $this->toolModel->all(['status' => 'available', 'is_active' => 1], 'tool_name');
        
        // Get technicians for dropdown
        $technicians = $this->technicianModel->getActiveTechnicians();
        
        $this->view('tool_requests/index', [
            'pendingRequests' => $pendingRequests,
            'myRequests' => $myRequests,
            'availableTools' => $availableTools,
            'technicians' => $technicians,
            'userRole' => $userRole
        ]);
    }
    
    public function create() {
        $availableTools = $this->toolModel->all(['status' => 'available', 'is_active' => 1], 'tool_name');
        $technicians = $this->technicianModel->getActiveTechnicians();
        
        $this->view('tool_requests/create', [
            'availableTools' => $availableTools,
            'technicians' => $technicians
        ]);
    }
    
    public function store() {
        $data = $this->sanitize($_POST);
        
        $rules = [
            'technician_id' => 'required|numeric',
            'number_plate' => 'required',
            'reason' => 'required',
            'tools' => 'required'
        ];
        
        $errors = $this->validate($data, $rules);
        
        if (!empty($errors)) {
            $_SESSION['errors'] = $errors;
            $this->redirect('/tool-requests/create');
            return;
        }
        
        $tools = json_decode($data['tools'], true);
        
        if (empty($tools)) {
            $_SESSION['error'] = 'Please add at least one tool';
            $this->redirect('/tool-requests/create');
            return;
        }
        
        $requestData = [
            'technician_id' => $data['technician_id'],
            'number_plate' => $data['number_plate'],
            'reason' => $data['reason'],
            'instructions' => $data['instructions'] ?? null,
            'urgency' => $data['urgency'] ?? 'medium',
            'expected_duration_days' => $data['expected_duration_days'] ?? 1,
            'requested_by' => $this->getCurrentUser()
        ];
        
        $requestId = $this->requestModel->createWithTools($requestData, $tools);
        
        $_SESSION['success'] = 'Tool request submitted successfully!';
        $this->redirect("/tool-requests/view/{$requestId}");
    }
    
    public function view($id) {
        $request = $this->requestModel->getWithDetails($id);
        
        if (!$request) {
            $_SESSION['error'] = 'Request not found';
            $this->redirect('/tool-requests');
            return;
        }
        
        $this->view('tool_requests/view', ['request' => $request]);
    }
    
    public function approve($id) {
        if (!in_array($_SESSION['role'], ['admin', 'manager'])) {
            $this->json(['error' => 'Unauthorized'], 403);
            return;
        }
        
        $this->requestModel->approve($id, $this->getCurrentUser());
        
        $this->json(['success' => true, 'message' => 'Request approved successfully']);
    }
    
    public function reject($id) {
        if (!in_array($_SESSION['role'], ['admin', 'manager'])) {
            $this->json(['error' => 'Unauthorized'], 403);
            return;
        }
        
        $data = json_decode(file_get_contents('php://input'), true);
        $reason = $data['reason'] ?? '';
        
        $this->requestModel->reject($id, $reason, $this->getCurrentUser());
        
        $this->json(['success' => true, 'message' => 'Request rejected']);
    }
    
    public function fulfill($id) {
        $this->requestModel->update($id, ['status' => 'fulfilled']);
        $_SESSION['success'] = 'Request marked as fulfilled';
        $this->redirect("/tool-requests/view/{$id}");
    }
    
    public function cancel($id) {
        $this->requestModel->update($id, ['status' => 'cancelled']);
        $_SESSION['success'] = 'Request cancelled';
        $this->redirect('/tool-requests');
    }
    
    public function getDetails($id) {
        $request = $this->requestModel->getWithDetails($id);
        
        if ($request) {
            $this->json(['success' => true, 'data' => $request]);
        } else {
            $this->json(['success' => false, 'message' => 'Request not found'], 404);
        }
    }
}