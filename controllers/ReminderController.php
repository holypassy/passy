<?php
require_once __DIR__ . '/../models/ServiceReminder.php';
require_once __DIR__ . '/../models/ReminderHistory.php';
require_once __DIR__ . '/../models/ServiceApplication.php';
require_once __DIR__ . '/../middleware/AuthMiddleware.php';
require_once __DIR__ . '/../middleware/CorsMiddleware.php';
require_once __DIR__ . '/../helpers/Validator.php';
require_once __DIR__ . '/../helpers/Response.php';
require_once __DIR__ . '/../vendor/EmailService.php';
require_once __DIR__ . '/../vendor/SMSService.php';

class ReminderController {
    private $reminderModel;
    private $historyModel;
    private $serviceModel;
    private $emailService;
    private $smsService;
    
    public function __construct() {
        CorsMiddleware::handle();
        $this->reminderModel = new ServiceReminder();
        $this->historyModel = new ReminderHistory();
        $this->serviceModel = new ServiceApplication();
        $this->emailService = new EmailService();
        $this->smsService = new SMSService();
    }
    
    // ==================== REMINDER ENDPOINTS ====================
    
    public function getAll() {
        AuthMiddleware::authenticate();
        
        $filters = [
            'sent' => isset($_GET['sent']) ? (int)$_GET['sent'] : null,
            'from_date' => $_GET['from_date'] ?? null,
            'to_date' => $_GET['to_date'] ?? null,
            'limit' => $_GET['limit'] ?? 100
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
    
    public function getPending() {
        AuthMiddleware::authenticate();
        
        $reminders = $this->reminderModel->getPendingReminders();
        
        Response::json([
            'success' => true,
            'data' => $reminders,
            'count' => count($reminders)
        ]);
    }
    
    public function getToday() {
        AuthMiddleware::authenticate();
        
        $reminders = $this->reminderModel->getTodayReminders();
        
        Response::json([
            'success' => true,
            'data' => $reminders
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
        AuthMiddleware::requireRole(['admin', 'manager']);
        
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            Response::json(['success' => false, 'message' => 'Method not allowed'], 405);
        }
        
        $input = json_decode(file_get_contents('php://input'), true);
        
        $validation = Validator::validate($input, [
            'service_application_id' => 'required|numeric',
            'reminder_date' => 'required|date'
        ]);
        
        if ($validation !== true) {
            Response::json(['success' => false, 'errors' => $validation], 400);
        }
        
        $session = AuthMiddleware::getCurrentUser();
        $input['created_by'] = $session['id'];
        
        $result = $this->reminderModel->create($input);
        
        if ($result) {
            Response::json(['success' => true, 'message' => 'Reminder created successfully']);
        } else {
            Response::json(['success' => false, 'message' => 'Failed to create reminder'], 500);
        }
    }
    
    public function createFromService() {
        AuthMiddleware::requireRole(['admin', 'manager']);
        
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            Response::json(['success' => false, 'message' => 'Method not allowed'], 405);
        }
        
        $input = json_decode(file_get_contents('php://input'), true);
        
        $validation = Validator::validate($input, [
            'service_id' => 'required|numeric',
            'reminder_days_before' => 'numeric|min:1|max:30'
        ]);
        
        if ($validation !== true) {
            Response::json(['success' => false, 'errors' => $validation], 400);
        }
        
        $reminderDays = $input['reminder_days_before'] ?? 3;
        $result = $this->reminderModel->createFromService($input['service_id'], $reminderDays);
        
        if ($result) {
            Response::json(['success' => true, 'message' => 'Reminder created from service successfully']);
        } else {
            Response::json(['success' => false, 'message' => 'Failed to create reminder or already exists'], 400);
        }
    }
    
    public function sendNow($id) {
        AuthMiddleware::requireRole(['admin', 'manager']);
        
        $reminder = $this->reminderModel->findById($id);
        
        if (!$reminder) {
            Response::json(['success' => false, 'message' => 'Reminder not found'], 404);
        }
        
        $results = [];
        $sent = false;
        
        // Send email
        if ($reminder['reminder_type'] == 'email' || $reminder['reminder_type'] == 'both') {
            if (!empty($reminder['email'])) {
                $emailResult = $this->emailService->send(
                    $reminder['email'],
                    'Service Reminder - SAVANT MOTORS',
                    $reminder['message']
                );
                
                $this->historyModel->create([
                    'reminder_id' => $reminder['id'],
                    'reminder_type' => 'email',
                    'sent_to' => $reminder['email'],
                    'message' => $reminder['message'],
                    'sent_status' => $emailResult['success'] ? 'sent' : 'failed',
                    'provider' => 'smtp',
                    'response' => $emailResult['response'] ?? null,
                    'error_message' => $emailResult['error'] ?? null
                ]);
                
                if ($emailResult['success']) {
                    $sent = true;
                }
                $results['email'] = $emailResult;
            }
        }
        
        // Send SMS
        if ($reminder['reminder_type'] == 'sms' || $reminder['reminder_type'] == 'both') {
            if (!empty($reminder['telephone'])) {
                $smsResult = $this->smsService->send(
                    $reminder['telephone'],
                    $reminder['message']
                );
                
                $this->historyModel->create([
                    'reminder_id' => $reminder['id'],
                    'reminder_type' => 'sms',
                    'sent_to' => $reminder['telephone'],
                    'message' => $reminder['message'],
                    'sent_status' => $smsResult['success'] ? 'sent' : 'failed',
                    'provider' => $smsResult['provider'] ?? 'local',
                    'response' => $smsResult['response'] ?? null,
                    'error_message' => $smsResult['error'] ?? null
                ]);
                
                if ($smsResult['success']) {
                    $sent = true;
                }
                $results['sms'] = $smsResult;
            }
        }
        
        // Mark as sent if at least one channel succeeded
        if ($sent) {
            $this->reminderModel->markAsSent($reminder['id']);
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
        $sentCount = 0;
        
        foreach ($reminders as $reminder) {
            $result = $this->sendReminderInternal($reminder);
            if ($result['sent']) {
                $sentCount++;
            }
            $results[] = [
                'reminder_id' => $reminder['id'],
                'customer' => $reminder['customer_name'],
                'sent' => $result['sent']
            ];
        }
        
        Response::json([
            'success' => true,
            'message' => count($reminders) . ' reminders processed, ' . $sentCount . ' sent',
            'results' => $results
        ]);
    }
    
    private function sendReminderInternal($reminder) {
        $sent = false;
        $emailResult = null;
        $smsResult = null;
        
        // Send email
        if ($reminder['reminder_type'] == 'email' || $reminder['reminder_type'] == 'both') {
            if (!empty($reminder['email'])) {
                $emailResult = $this->emailService->send(
                    $reminder['email'],
                    'Service Reminder - SAVANT MOTORS',
                    $reminder['message']
                );
                
                $this->historyModel->create([
                    'reminder_id' => $reminder['id'],
                    'reminder_type' => 'email',
                    'sent_to' => $reminder['email'],
                    'message' => $reminder['message'],
                    'sent_status' => $emailResult['success'] ? 'sent' : 'failed',
                    'provider' => 'smtp',
                    'response' => $emailResult['response'] ?? null,
                    'error_message' => $emailResult['error'] ?? null
                ]);
                
                if ($emailResult['success']) {
                    $sent = true;
                }
            }
        }
        
        // Send SMS
        if ($reminder['reminder_type'] == 'sms' || $reminder['reminder_type'] == 'both') {
            if (!empty($reminder['telephone'])) {
                $smsResult = $this->smsService->send(
                    $reminder['telephone'],
                    $reminder['message']
                );
                
                $this->historyModel->create([
                    'reminder_id' => $reminder['id'],
                    'reminder_type' => 'sms',
                    'sent_to' => $reminder['telephone'],
                    'message' => $reminder['message'],
                    'sent_status' => $smsResult['success'] ? 'sent' : 'failed',
                    'provider' => $smsResult['provider'] ?? 'local',
                    'response' => $smsResult['response'] ?? null,
                    'error_message' => $smsResult['error'] ?? null
                ]);
                
                if ($smsResult['success']) {
                    $sent = true;
                }
            }
        }
        
        if ($sent) {
            $this->reminderModel->markAsSent($reminder['id']);
        }
        
        return [
            'sent' => $sent,
            'email' => $emailResult,
            'sms' => $smsResult
        ];
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
        
        $reminderStats = $this->reminderModel->getStatistics();
        $historyStats = $this->historyModel->getStatistics();
        
        Response::json([
            'success' => true,
            'data' => [
                'reminders' => $reminderStats,
                'delivery' => $historyStats
            ]
        ]);
    }
}
?>