<?php
require_once __DIR__ . '/../models/PickupReminder.php';
require_once __DIR__ . '/../models/ReminderHistory.php';
require_once __DIR__ . '/../models/Customer.php';
require_once __DIR__ . '/../models/JobCard.php';
require_once __DIR__ . '/../middleware/AuthMiddleware.php';
require_once __DIR__ . '/../middleware/CorsMiddleware.php';
require_once __DIR__ . '/../helpers/Validator.php';
require_once __DIR__ . '/../helpers/Response.php';
require_once __DIR__ . '/../helpers/NotificationHelper.php';

class PickupController {
    private $reminderModel;
    private $historyModel;
    private $customerModel;
    private $jobCardModel;
    private $notificationHelper;
    
    public function __construct() {
        CorsMiddleware::handle();
        $this->reminderModel = new PickupReminder();
        $this->historyModel = new ReminderHistory();
        $this->customerModel = new Customer();
        $this->jobCardModel = new JobCard();
        $this->notificationHelper = new NotificationHelper();
    }
    
    // ==================== REMINDER ENDPOINTS ====================
    
    public function getAll() {
        AuthMiddleware::authenticate();
        
        $filters = [
            'status' => $_GET['status'] ?? null,
            'pickup_type' => $_GET['pickup_type'] ?? null,
            'search' => $_GET['search'] ?? null,
            'assigned_to' => $_GET['assigned_to'] ?? null,
            'from_date' => $_GET['from_date'] ?? null,
            'to_date' => $_GET['to_date'] ?? null,
            'limit' => $_GET['limit'] ?? 100,
            'offset' => $_GET['offset'] ?? 0
        ];
        
        $reminders = $this->reminderModel->getAll($filters);
        $stats = $this->reminderModel->getStatistics();
        
        Response::json([
            'success' => true,
            'data' => $reminders,
            'statistics' => $stats,
            'filters' => $filters
        ]);
    }
    
    public function getOne($id) {
        AuthMiddleware::authenticate();
        
        $reminder = $this->reminderModel->findById($id);
        
        if (!$reminder) {
            Response::json(['success' => false, 'message' => 'Reminder not found'], 404);
        }
        
        $history = $this->historyModel->getByReminderId($id);
        $reminder['history'] = $history;
        
        Response::json(['success' => true, 'data' => $reminder]);
    }
    
    public function create() {
        AuthMiddleware::authenticate();
        
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            Response::json(['success' => false, 'message' => 'Method not allowed'], 405);
        }
        
        $input = json_decode(file_get_contents('php://input'), true);
        
        $validation = Validator::validate($input, [
            'customer_id' => 'required|numeric',
            'vehicle_reg' => 'required|min:3',
            'pickup_date' => 'required|date',
            'reminder_date' => 'required|date'
        ]);
        
        if ($validation !== true) {
            Response::json(['success' => false, 'errors' => $validation], 400);
        }
        
        $session = AuthMiddleware::getCurrentUser();
        $input['created_by'] = $session['id'];
        
        $result = $this->reminderModel->create($input);
        
        if ($result) {
            Response::json(['success' => true, 'message' => 'Pickup reminder created successfully', 'id' => $result]);
        } else {
            Response::json(['success' => false, 'message' => 'Failed to create reminder'], 500);
        }
    }
    
    public function update($id) {
        AuthMiddleware::requireRole(['admin', 'manager']);
        
        if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
            Response::json(['success' => false, 'message' => 'Method not allowed'], 405);
        }
        
        $reminder = $this->reminderModel->findById($id);
        if (!$reminder) {
            Response::json(['success' => false, 'message' => 'Reminder not found'], 404);
        }
        
        $input = json_decode(file_get_contents('php://input'), true);
        
        $result = $this->reminderModel->update($id, $input);
        
        if ($result) {
            Response::json(['success' => true, 'message' => 'Reminder updated successfully']);
        } else {
            Response::json(['success' => false, 'message' => 'Failed to update reminder'], 500);
        }
    }
    
    public function updateStatus($id) {
        AuthMiddleware::requireRole(['admin', 'manager']);
        
        if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
            Response::json(['success' => false, 'message' => 'Method not allowed'], 405);
        }
        
        $input = json_decode(file_get_contents('php://input'), true);
        
        if (empty($input['status'])) {
            Response::json(['success' => false, 'message' => 'Status required'], 400);
        }
        
        $result = $this->reminderModel->updateStatus($id, $input['status']);
        
        if ($result) {
            Response::json(['success' => true, 'message' => 'Status updated successfully']);
        } else {
            Response::json(['success' => false, 'message' => 'Failed to update status'], 500);
        }
    }
    
    public function sendReminder($id) {
        AuthMiddleware::authenticate();
        
        $reminder = $this->reminderModel->findById($id);
        
        if (!$reminder) {
            Response::json(['success' => false, 'message' => 'Reminder not found'], 404);
        }
        
        // Generate message
        $message = $this->notificationHelper->generatePickupMessage($reminder);
        
        $results = [];
        
        // Send email
        if ($reminder['reminder_type'] == 'email' || $reminder['reminder_type'] == 'both') {
            if (!empty($reminder['email'])) {
                $emailResult = $this->notificationHelper->sendEmail(
                    $reminder['email'],
                    'Vehicle Pickup Reminder - SAVANT MOTORS',
                    $message
                );
                
                $this->historyModel->create([
                    'reminder_id' => $reminder['id'],
                    'reminder_type' => 'email',
                    'sent_to' => $reminder['email'],
                    'message' => $message,
                    'sent_status' => $emailResult['success'] ? 'sent' : 'failed',
                    'error_message' => $emailResult['error'] ?? null
                ]);
                
                $results['email'] = $emailResult;
            }
        }
        
        // Send SMS
        if ($reminder['reminder_type'] == 'sms' || $reminder['reminder_type'] == 'both') {
            if (!empty($reminder['telephone'])) {
                $smsResult = $this->notificationHelper->sendSMS(
                    $reminder['telephone'],
                    $message
                );
                
                $this->historyModel->create([
                    'reminder_id' => $reminder['id'],
                    'reminder_type' => 'sms',
                    'sent_to' => $reminder['telephone'],
                    'message' => $message,
                    'sent_status' => $smsResult['success'] ? 'sent' : 'failed',
                    'error_message' => $smsResult['error'] ?? null
                ]);
                
                $results['sms'] = $smsResult;
            }
        }
        
        // Mark as sent if at least one channel succeeded
        $sent = ($results['email']['success'] ?? false) || ($results['sms']['success'] ?? false);
        if ($sent) {
            $this->reminderModel->markReminderSent($id);
        }
        
        Response::json([
            'success' => $sent,
            'message' => $sent ? 'Reminder sent successfully' : 'Failed to send reminder',
            'details' => $results
        ]);
    }
    
    public function processDailyReminders() {
        // This can be called via cron or API
        $reminders = $this->reminderModel->getTodayReminders();
        
        $results = [];
        
        foreach ($reminders as $reminder) {
            $message = $this->notificationHelper->generatePickupMessage($reminder);
            $result = $this->sendReminder($reminder['id']);
            $results[] = [
                'reminder_id' => $reminder['id'],
                'customer' => $reminder['full_name'],
                'result' => $result
            ];
        }
        
        Response::json([
            'success' => true,
            'message' => count($reminders) . ' reminders processed',
            'results' => $results
        ]);
    }
    
    public function delete($id) {
        AuthMiddleware::requireRole(['admin']);
        
        $result = $this->reminderModel->delete($id);
        
        if ($result) {
            Response::json(['success' => true, 'message' => 'Reminder deleted successfully']);
        } else {
            Response::json(['success' => false, 'message' => 'Failed to delete reminder'], 500);
        }
    }
    
    public function getStatistics() {
        AuthMiddleware::authenticate();
        
        $stats = $this->reminderModel->getStatistics();
        $historyStats = $this->historyModel->getStatistics();
        
        Response::json([
            'success' => true,
            'data' => [
                'reminders' => $stats,
                'delivery' => $historyStats
            ]
        ]);
    }
    
    public function getDuePickups() {
        AuthMiddleware::authenticate();
        
        $pickups = $this->reminderModel->getDuePickups();
        
        Response::json(['success' => true, 'data' => $pickups]);
    }
    
    public function getMyPickups() {
        AuthMiddleware::authenticate();
        
        $session = AuthMiddleware::getCurrentUser();
        $pickups = $this->reminderModel->getByAssignedTo($session['id']);
        
        Response::json(['success' => true, 'data' => $pickups]);
    }
}
?>