<?php
namespace App\Models;

use Core\Model;

class ReminderHistory extends Model {
    protected $table = 'reminder_history';
    protected $fillable = [
        'reminder_id', 'reminder_type', 'sent_to', 'message', 'sent_status', 'error_message'
    ];
    
    public function getByReminder($reminderId) {
        $stmt = $this->db->prepare("
            SELECT * FROM reminder_history 
            WHERE reminder_id = ? 
            ORDER BY sent_at DESC
        ");
        $stmt->execute([$reminderId]);
        return $stmt->fetchAll();
    }
    
    public function getFailedReminders() {
        $stmt = $this->db->prepare("
            SELECT h.*, r.reminder_number, c.full_name
            FROM reminder_history h
            JOIN pickup_reminders r ON h.reminder_id = r.id
            JOIN customers c ON r.customer_id = c.id
            WHERE h.sent_status = 'failed'
            ORDER BY h.sent_at DESC
        ");
        $stmt->execute();
        return $stmt->fetchAll();
    }
    
    public function getStatistics($startDate = null, $endDate = null) {
        $sql = "SELECT 
                    reminder_type,
                    COUNT(*) as total,
                    SUM(CASE WHEN sent_status = 'sent' THEN 1 ELSE 0 END) as successful,
                    SUM(CASE WHEN sent_status = 'failed' THEN 1 ELSE 0 END) as failed
                FROM reminder_history";
        $params = [];
        
        if ($startDate) {
            $sql .= " WHERE sent_at >= ?";
            $params[] = $startDate;
        }
        if ($endDate) {
            $sql .= (strpos($sql, 'WHERE') ? ' AND' : ' WHERE') . " sent_at <= ?";
            $params[] = $endDate;
        }
        
        $sql .= " GROUP BY reminder_type";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
}