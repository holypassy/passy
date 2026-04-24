#!/usr/bin/env php
<?php
// Run this script via cron daily: 0 8 * * * php /path/to/cron/process_pickup_reminders.php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../models/PickupReminder.php';
require_once __DIR__ . '/../models/ReminderHistory.php';
require_once __DIR__ . '/../helpers/NotificationHelper.php';

error_log("=== Pickup Reminder Processing Started at " . date('Y-m-d H:i:s') . " ===");

try {
    $reminderModel = new PickupReminder();
    $historyModel = new ReminderHistory();
    $notificationHelper = new NotificationHelper();
    
    // Get today's reminders
    $reminders = $reminderModel->getTodayReminders();
    
    $sentCount = 0;
    $failedCount = 0;
    
    foreach ($reminders as $reminder) {
        error_log("Processing reminder ID: {$reminder['id']} for customer: {$reminder['full_name']}");
        
        $message = $notificationHelper->generatePickupMessage($reminder);
        $sent = false;
        
        // Send email
        if ($reminder['reminder_type'] == 'email' || $reminder['reminder_type'] == 'both') {
            if (!empty($reminder['email'])) {
                $result = $notificationHelper->sendEmail(
                    $reminder['email'],
                    'Vehicle Pickup Reminder - SAVANT MOTORS',
                    $message
                );
                
                $historyModel->create([
                    'reminder_id' => $reminder['id'],
                    'reminder_type' => 'email',
                    'sent_to' => $reminder['email'],
                    'message' => $message,
                    'sent_status' => $result['success'] ? 'sent' : 'failed',
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
                $result = $notificationHelper->sendSMS(
                    $reminder['telephone'],
                    $message
                );
                
                $historyModel->create([
                    'reminder_id' => $reminder['id'],
                    'reminder_type' => 'sms',
                    'sent_to' => $reminder['telephone'],
                    'message' => $message,
                    'sent_status' => $result['success'] ? 'sent' : 'failed',
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
            $reminderModel->markReminderSent($reminder['id']);
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