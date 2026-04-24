<?php
namespace App\Models;

use Core\Model;

class UserActivity extends Model {
    protected $table = 'user_activities';
    protected $fillable = ['user_id', 'action', 'details', 'ip_address', 'user_agent'];
    
    public function log($userId, $action, $details = null) {
        return $this->create([
            'user_id' => $userId,
            'action' => $action,
            'details' => $details,
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null
        ]);
    }
    
    public function getUserActivities($userId, $limit = 50) {
        $stmt = $this->db->prepare("
            SELECT * FROM user_activities 
            WHERE user_id = ? 
            ORDER BY created_at DESC 
            LIMIT ?
        ");
        $stmt->execute([$userId, $limit]);
        return $stmt->fetchAll();
    }
    
    public function getRecentActivities($limit = 100) {
        $stmt = $this->db->prepare("
            SELECT a.*, u.full_name
            FROM user_activities a
            JOIN users u ON a.user_id = u.id
            ORDER BY a.created_at DESC
            LIMIT ?
        ");
        $stmt->execute([$limit]);
        return $stmt->fetchAll();
    }
    
    public function getActivityStats($days = 30) {
        $stmt = $this->db->prepare("
            SELECT 
                action,
                COUNT(*) as count,
                DATE(created_at) as date
            FROM user_activities
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
            GROUP BY action, DATE(created_at)
            ORDER BY date DESC
        ");
        $stmt->execute([$days]);
        return $stmt->fetchAll();
    }
}