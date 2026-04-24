<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

require_once '../../core/Database.php';
require_once '../../app/models/Tool.php';
require_once '../../app/models/ToolAssignment.php';
require_once '../../utils/Auth.php';

use App\Models\Tool;
use App\Models\ToolAssignment;
use Utils\Auth;

$auth = new Auth();
$user = $auth->authenticate();

if (!$user) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

$method = $_SERVER['REQUEST_METHOD'];
$toolModel = new Tool();
$assignmentModel = new ToolAssignment();

switch ($method) {
    case 'GET':
        if (isset($_GET['id'])) {
            $tool = $toolModel->getWithDetails($_GET['id']);
            if ($tool) {
                echo json_encode($tool);
            } else {
                http_response_code(404);
                echo json_encode(['error' => 'Tool not found']);
            }
        } elseif (isset($_GET['status']) && $_GET['status'] === 'taken') {
            $takenTools = $toolModel->getTakenTools();
            echo json_encode($takenTools);
        } elseif (isset($_GET['stats'])) {
            $stats = $toolModel->getStatistics();
            echo json_encode($stats);
        } elseif (isset($_GET['categories'])) {
            $categories = $toolModel->getCategories();
            echo json_encode($categories);
        } else {
            $page = $_GET['page'] ?? 1;
            $perPage = $_GET['per_page'] ?? 15;
            $tools = $toolModel->paginate($page, $perPage);
            echo json_encode($tools);
        }
        break;
        
    case 'POST':
        $data = json_decode(file_get_contents('php://input'), true);
        
        $validation = new \Core\Validation();
        if (!$validation->validate($data, [
            'tool_name' => 'required|min:2',
            'category' => 'required'
        ])) {
            http_response_code(422);
            echo json_encode(['errors' => $validation->errors()]);
            break;
        }
        
        $data['tool_code'] = $toolModel->generateToolCode();
        $data['created_by'] = $user['id'];
        $toolId = $toolModel->create($data);
        
        http_response_code(201);
        echo json_encode(['id' => $toolId, 'message' => 'Tool created successfully']);
        break;
        
    case 'PUT':
        if (!isset($_GET['id'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Tool ID required']);
            break;
        }
        
        $data = json_decode(file_get_contents('php://input'), true);
        
        if (isset($data['action'])) {
            switch ($data['action']) {
                case 'assign':
                    $assignmentModel->assignTool(
                        $_GET['id'],
                        $data['technician_id'],
                        $data['request_id'] ?? null,
                        $data['expected_return_date'] ?? null
                    );
                    echo json_encode(['message' => 'Tool assigned successfully']);
                    break;
                case 'return':
                    $assignmentModel->returnTool($data['assignment_id'], $data['condition'] ?? null);
                    echo json_encode(['message' => 'Tool returned successfully']);
                    break;
                case 'maintenance':
                    $toolModel->updateStatus($_GET['id'], 'maintenance');
                    echo json_encode(['message' => 'Tool marked for maintenance']);
                    break;
                default:
                    http_response_code(400);
                    echo json_encode(['error' => 'Invalid action']);
            }
        } else {
            $toolModel->update($_GET['id'], $data);
            echo json_encode(['message' => 'Tool updated successfully']);
        }
        break;
        
    case 'DELETE':
        if (!isset($_GET['id'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Tool ID required']);
            break;
        }
        
        $toolModel->update($_GET['id'], ['is_active' => 0]);
        echo json_encode(['message' => 'Tool deactivated successfully']);
        break;
        
    default:
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        break;
}