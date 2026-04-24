<?php
namespace Utils;

use Core\Database;

class ReminderScheduler {
    private $db;
    
    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }
    
    public function scheduleReminder($reminderId, $reminderDate, $reminderTime = null) {
        // This would typically integrate with a job queue system like cron or Redis
        // For now, we'll just log the schedule
        $scheduleTime = $reminderDate . ' ' . ($reminderTime ?? '09:00:00');
        
        $stmt = $this->db->prepare("
            INSERT INTO scheduled_jobs (job_type, job_data, scheduled_at, status)
            VALUES ('send_reminder', ?, ?, 'pending')
        ");
        
        $jobData = json_encode(['reminder_id' => $reminderId]);
        $stmt->execute([$jobData, $scheduleTime]);
        
        return true;
    }
    
    public function processPendingReminders() {
        $stmt = $this->db->prepare("
            SELECT * FROM scheduled_jobs 
            WHERE job_type = 'send_reminder' 
            AND status = 'pending' 
            AND scheduled_at <= NOW()
        ");
        $stmt->execute();
        $jobs = $stmt->fetchAll();
        
        $results = [];
        foreach ($jobs as $job) {
            $data = json_decode($job['job_data'], true);
            $success = $this->processReminderJob($data['reminder_id']);
            
            $updateStmt = $this->db->prepare("
                UPDATE scheduled_jobs 
                SET status = ?, processed_at = NOW() 
                WHERE id = ?
            ");
            $updateStmt->execute([$success ? 'completed' : 'failed', $job['id']]);
            
            $results[] = ['id' => $job['id'], 'success' => $success];
        }
        
        return $results;
    }
    
    private function processReminderJob($reminderId) {
        // Logic to send the reminder
        // This would call the reminder sending service
        return true;
    }
}