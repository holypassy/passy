<?php
require_once __DIR__ . '/../vendor/EmailService.php';
require_once __DIR__ . '/../vendor/SMSService.php';

class NotificationHelper {
    private $emailService;
    private $smsService;
    
    public function __construct() {
        $this->emailService = new EmailService();
        $this->smsService = new SMSService();
    }
    
    public function generatePickupMessage($reminder) {
        $message = "Dear " . $reminder['full_name'] . ",\n\n";
        $message .= "Vehicle Pickup Details:\n";
        $message .= "Vehicle: " . $reminder['vehicle_reg'];
        
        if (!empty($reminder['vehicle_make']) || !empty($reminder['vehicle_model'])) {
            $message .= " (" . $reminder['vehicle_make'] . " " . $reminder['vehicle_model'] . ")";
        }
        $message .= "\n";
        
        switch ($reminder['pickup_type']) {
            case 'workshop':
                $message .= "Pickup Location: Our Workshop\n";
                $message .= "Address: Savant Motors Workshop, Kampala, Uganda\n";
                break;
            case 'home':
                $message .= "Pickup Type: Home Pickup\n";
                $message .= "Pickup Address: " . $reminder['pickup_address'] . "\n";
                break;
            case 'office':
                $message .= "Pickup Type: Office Pickup\n";
                $message .= "Pickup Address: " . $reminder['pickup_address'] . "\n";
                break;
        }
        
        if (!empty($reminder['pickup_location_details'])) {
            $message .= "Location Details: " . $reminder['pickup_location_details'] . "\n";
        }
        
        $message .= "\nPickup Date: " . date('l, F j, Y', strtotime($reminder['pickup_date']));
        
        if (!empty($reminder['pickup_time'])) {
            $message .= " at " . date('h:i A', strtotime($reminder['pickup_time']));
        }
        
        $message .= "\n\nPlease ensure vehicle is accessible at the specified location.\n";
        $message .= "For any changes, please contact us at +256 123 456 789.\n\n";
        $message .= "Thank you for choosing Savant Motors!\n";
        $message .= "SAVANT MOTORS UGANDA";
        
        return $message;
    }
    
    public function sendEmail($to, $subject, $message, $isHtml = false) {
        return $this->emailService->send($to, $subject, $message, $isHtml);
    }
    
    public function sendSMS($phone, $message) {
        return $this->smsService->send($phone, $message);
    }
    
    public function generateReminderSummary($reminders) {
        $summary = [];
        
        foreach ($reminders as $reminder) {
            $summary[] = [
                'id' => $reminder['id'],
                'customer' => $reminder['full_name'],
                'vehicle' => $reminder['vehicle_reg'],
                'pickup_date' => $reminder['pickup_date'],
                'pickup_time' => $reminder['pickup_time'],
                'type' => $reminder['pickup_type'],
                'status' => $reminder['status']
            ];
        }
        
        return $summary;
    }
}
?>
<?php
class NotificationHelper {
    
    public function sendNewRequestNotification($request) {
        // Send email to admins/managers
        $subject = "New Tool Request: {$request['request_number']}";
        $message = "A new tool request has been submitted.\n\n";
        $message .= "Request #: {$request['request_number']}\n";
        $message .= "Technician: {$request['technician_name']}\n";
        $message .= "Tool: " . ($request['tool_name'] ?? $request['tool_name_requested']) . "\n";
        $message .= "Urgency: " . strtoupper($request['urgency']) . "\n";
        $message .= "Reason: {$request['reason']}\n\n";
        $message .= "Please review and respond to this request.";
        
        // Get admin emails
        $admins = $this->getAdminEmails();
        
        foreach ($admins as $admin) {
            $this->sendEmail($admin['email'], $subject, $message);
        }
    }
    
    public function sendRequestApprovedNotification($request) {
        $subject = "Tool Request Approved: {$request['request_number']}";
        $message = "Your tool request has been approved!\n\n";
        $message .= "Request #: {$request['request_number']}\n";
        $message .= "Tool: " . ($request['tool_name'] ?? $request['tool_name_requested']) . "\n";
        $message .= "Quantity: {$request['quantity']}\n";
        $message .= "Expected Duration: {$request['expected_duration_days']} days\n\n";
        $message .= "You can now collect the tool from the tool store.\n";
        $message .= "Please return it by the due date to avoid penalties.";
        
        $this->sendEmail($request['technician_email'], $subject, $message);
    }
    
    public function sendRequestRejectedNotification($request) {
        $subject = "Tool Request Rejected: {$request['request_number']}";
        $message = "Your tool request has been rejected.\n\n";
        $message .= "Request #: {$request['request_number']}\n";
        $message .= "Tool: " . ($request['tool_name'] ?? $request['tool_name_requested']) . "\n\n";
        
        if (!empty($request['rejection_reason'])) {
            $message .= "Reason for rejection: {$request['rejection_reason']}\n\n";
        }
        
        $message .= "If you have questions, please contact your supervisor.";
        
        $this->sendEmail($request['technician_email'], $subject, $message);
    }
    
    public function sendReminderNotification($assignment) {
        $daysLeft = ceil((strtotime($assignment['expected_return_date']) - time()) / 86400);
        
        if ($daysLeft <= 2 && $daysLeft > 0) {
            $subject = "Tool Return Reminder";
            $message = "Reminder: Tool {$assignment['tool_name']} is due for return in {$daysLeft} days.\n\n";
            $message .= "Please return it by {$assignment['expected_return_date']} to avoid overdue charges.";
            
            $this->sendEmail($assignment['technician_email'], $subject, $message);
        } elseif ($daysLeft <= 0) {
            $subject = "Tool Return Overdue";
            $message = "URGENT: Tool {$assignment['tool_name']} is overdue for return.\n\n";
            $message .= "Original due date: {$assignment['expected_return_date']}\n";
            $message .= "Please return immediately to avoid further penalties.";
            
            $this->sendEmail($assignment['technician_email'], $subject, $message);
        }
    }
    
    private function getAdminEmails() {
        $database = Database::getInstance();
        $conn = $database->getConnection();
        
        $query = "SELECT email FROM users WHERE role IN ('admin', 'manager') AND email IS NOT NULL";
        $stmt = $conn->prepare($query);
        $stmt->execute();
        
        return $stmt->fetchAll();
    }
    
    private function sendEmail($to, $subject, $message) {
        if (empty($to)) return false;
        
        $headers = "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $headers .= "From: Savant Motors <noreply@savantmotors.com>\r\n";
        
        return mail($to, $subject, $message, $headers);
    }
}
?>