<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

require_once '../../core/Database.php';
require_once '../../app/models/Interaction.php';
require_once '../../app/models/Customer.php';
require_once '../../utils/Auth.php';

use App\Models\Interaction;
use App\Models\Customer;
use Utils\Auth;

$auth = new Auth();
$user = $auth->authenticate();

if (!$user) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

$method = $_SERVER['REQUEST_METHOD'];
$interactionModel = new Interaction();
$customerModel = new Customer();

switch ($method) {
    case 'GET':
        if (isset($_GET['customer_id'])) {
            $interactions = $interactionModel->getByCustomer($_GET['customer_id'], $_GET['limit'] ?? 50);
            echo json_encode($interactions);
        } elseif (isset($_GET['pending'])) {
            $followups = $interactionModel->getPendingFollowups();
            echo json_encode($followups);
        } elseif (isset($_GET['stats'])) {
            $startDate = $_GET['start_date'] ?? null;
            $endDate = $_GET['end_date'] ?? null;
            $stats = $interactionModel->getInteractionStats($startDate, $endDate);
            echo json_encode($stats);
        } else {
            http_response_code(400);
            echo json_encode(['error' => 'Missing parameters']);
        }
        break;
        
    case 'POST':
        $data = json_decode(file_get_contents('php://input'), true);
        
        $validation = new \Core\Validation();
        if (!$validation->validate($data, [
            'customer_id' => 'required|numeric',
            'interaction_date' => 'required',
            'interaction_type' => 'required',
            'summary' => 'required'
        ])) {
            http_response_code(422);
            echo json_encode(['errors' => $validation->errors()]);
            break;
        }
        
        $data['created_by'] = $user['id'];
        $interactionId = $interactionModel->create($data);
        
        // Update customer's last contact date
        $customerModel->update($data['customer_id'], [
            'last_contact_date' => date('Y-m-d H:i:s'),
            'next_follow_up_date' => $data['follow_up_date'] ?? null
        ]);
        
        http_response_code(201);
        echo json_encode(['id' => $interactionId, 'message' => 'Interaction recorded successfully']);
        break;
        
    default:
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        break;
}