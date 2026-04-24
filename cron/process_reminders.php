#!/usr/bin/env php
<?php
// process_reminders.php - Run via cron: 0 9 * * * php /path/to/process_reminders.php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../models/ServiceReminder.php';
require_once __DIR__ . '/../models/ReminderHistory.php';
require_once __DIR__ . '/../vendor/EmailService.php';
require_once __DIR__ . '/../vendor/SMSService.php';

error_log("=== Reminder Processing Started at " . date('Y-m-d H:i:s') . " ===");

try {
    $reminderModel = new ServiceReminder();
    $historyModel = new ReminderHistory();
    $emailService = new EmailService();
    $smsService = new SMSService();
    
    $reminders = $reminderModel->getTodayReminders();
    
    $sentCount = 0;
    $failedCount = 0;
    
    foreach ($reminders as $reminder) {
        error_log("Processing reminder ID: {$reminder['id']} for customer: {$reminder['customer_name']}");
        
        $sent = false;
        
        // Send email
        if ($reminder['reminder_type'] == 'email' || $reminder['reminder_type'] == 'both') {
            if (!empty($reminder['email'])) {
                $result = $emailService->send(
                    $reminder['email'],
                    'Service Reminder - SAVANT MOTORS',
                    $reminder['message']
                );
                
                $historyModel->create([
                    'reminder_id' => $reminder['id'],
                    'reminder_type' => 'email',
                    'sent_to' => $reminder['email'],
                    'message' => $reminder['message'],
                    'sent_status' => $result['success'] ? 'sent' : 'failed',
                    'provider' => 'smtp',
                    'response' => $result['response'] ?? null,
                    'error_message' => $result['error'] ?? null
                ]);
                
                if ($result['success']) {
                    $sent = true;
                    error_log("  ✓ Email sent to {$reminder['email']}");
                } else {
                    error_log("  ✗ Email failed: {$result['error']}");
                }
            }
        }
        
        // Send SMS
        if ($reminder['reminder_type'] == 'sms' || $reminder['reminder_type'] == 'both') {
            if (!empty($reminder['telephone'])) {
                $result = $smsService->send(
                    $reminder['telephone'],
                    $reminder['message']
                );
                
                $historyModel->create([
                    'reminder_id' => $reminder['id'],
                    'reminder_type' => 'sms',
                    'sent_to' => $reminder['telephone'],
                    'message' => $reminder['message'],
                    'sent_status' => $result['success'] ? 'sent' : 'failed',
                    'provider' => $result['provider'] ?? 'local',
                    'response' => $result['response'] ?? null,
                    'error_message' => $result['error'] ?? null
                ]);
                
                if ($result['success']) {
                    $sent = true;
                    error_log("  ✓ SMS sent to {$reminder['telephone']}");
                } else {
                    error_log("  ✗ SMS failed: {$result['error']}");
                }
            }
        }
        
        if ($sent) {
            $reminderModel->markAsSent($reminder['id']);
            $sentCount++;
        } else {
            $failedCount++;
        }
    }
    
    error_log("=== Reminder Processing Completed ===");
    error_log("Sent: {$sentCount}, Failed: {$failedCount}, Total: " . count($reminders));
    
    echo json_encode([
        'success' => true,
        'processed' => count($reminders),
        'sent' => $sentCount,
        'failed' => $failedCount,
        'timestamp' => date('Y-m-d H:i:s')
    ]);
    
} catch (Exception $e) {
    error_log("CRON ERROR: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>