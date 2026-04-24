<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

require_once '../../core/Database.php';
require_once '../../app/models/PickupReminder.php';
require_once '../../app/models/ReminderHistory.php';
require_once '../../utils/Auth.php';

use App\Models\PickupReminder;
use App\Models\ReminderHistory;
use Utils\Auth;

$auth = new Auth();
$user = $auth->authenticate();

if (!$user) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

$method = $_SERVER['REQUEST_METHOD'];
$reminderModel = new PickupReminder();
$historyModel = new ReminderHistory();

switch ($method) {
    case 'GET':
        if (isset($_GET['id'])) {
            $reminder = $reminderModel->getWithDetails($_GET['id']);
            if ($reminder) {
                echo json_encode($reminder);
            } else {
                http_response_code(404);
                echo json_encode(['error' => 'Reminder not found']);
            }
        } elseif (isset($_GET['date'])) {
            $reminders = $reminderModel->getPickupsByDate($_GET['date']);
            echo json_encode($reminders);
        } elseif (isset($_GET['upcoming'])) {
            $days = $_GET['days'] ?? 7;
            $reminders = $reminderModel->getUpcomingPickups($days);
            echo json_encode($reminders);
        } elseif (isset($_GET['due'])) {
            $reminders = $reminderModel->getDueReminders();
            echo json_encode($reminders);
        } elseif (isset($_GET['stats'])) {
            $stats = $reminderModel->getStatistics();
            echo json_encode($stats);
        } else {
            $page = $_GET['page'] ?? 1;
            $filters = [
                'status' => $_GET['status'] ?? null,
                'pickup_type' => $_GET['pickup_type'] ?? null,
                'search' => $_GET['search'] ?? null
            ];
            $reminders = $reminderModel->getReminders($filters, $page, $_GET['per_page'] ?? 15);
            echo json_encode($reminders);
        }
        break;
        
    case 'POST':
        $data = json_decode(file_get_contents('php://input'), true);
        
        $validation = new \Core\Validation();
        if (!$validation->validate($data, [
            'customer_id' => 'required|numeric',
            'vehicle_reg' => 'required',
            'pickup_date' => 'required'
        ])) {
            http_response_code(422);
            echo json_encode(['errors' => $validation->errors()]);
            break;
        }
        
        $data['reminder_number'] = $reminderModel->generateReminderNumber();
        $data['status'] = 'scheduled';
        $data['created_by'] = $user['id'];
        
        $reminderId = $reminderModel->create($data);
        
        http_response_code(201);
        echo json_encode(['id' => $reminderId, 'message' => 'Reminder created successfully']);
        break;
        
    case 'PUT':
        if (!isset($_GET['id'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Reminder ID required']);
            break;
        }
        
        $data = json_decode(file_get_contents('php://input'), true);
        
        if (isset($data['action'])) {
            switch ($data['action']) {
                case 'send_reminder':
                    // Handle sending reminder
                    $reminderModel->markReminderSent($_GET['id']);
                    echo json_encode(['message' => 'Reminder sent']);
                    break;
                case 'update_status':
                    if (isset($data['status'])) {
                        $reminderModel->updateStatus($_GET['id'], $data['status']);
                        echo json_encode(['message' => 'Status updated']);
                    }
                    break;
                default:
                    http_response_code(400);
                    echo json_encode(['error' => 'Invalid action']);
            }
        } else {
            $reminderModel->update($_GET['id'], $data);
            echo json_encode(['message' => 'Reminder updated successfully']);
        }
        break;
        
    case 'DELETE':
        if (!isset($_GET['id'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Reminder ID required']);
            break;
        }
        
        $reminderModel->delete($_GET['id']);
        echo json_encode(['message' => 'Reminder deleted successfully']);
        break;
        
    default:
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        break;
}