<?php
namespace App\Models;

use Core\Model;
use PDO;

class PickupReminder extends Model {
    protected $table = 'pickup_reminders';
    protected $primaryKey = 'id';
    protected $fillable = [
        'reminder_number', 'customer_id', 'job_card_id', 'vehicle_reg',
        'vehicle_make', 'vehicle_model', 'vehicle_year', 'vehicle_color',
        'pickup_type', 'pickup_address', 'pickup_location_details',
        'pickup_latitude', 'pickup_longitude', 'pickup_date', 'pickup_time',
        'reminder_date', 'reminder_time', 'reminder_type', 'notes',
        'assigned_to', 'created_by'
    ];
    
    public function generateReminderNumber() {
        return 'PKP-' . date('Ymd') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
    }
    
    public function getWithDetails($id) {
        $stmt = $this->db->prepare("
            SELECT 
                r.*,
                c.full_name as customer_name,
                c.telephone as customer_phone,
                c.email as customer_email,
                c.address as customer_address,
                jc.job_number,
                u1.full_name as assigned_to_name,
                u2.full_name as created_by_name,
                (SELECT COUNT(*) FROM reminder_history WHERE reminder_id = r.id) as reminder_count,
                (SELECT COUNT(*) FROM pickup_assignments WHERE reminder_id = r.id) as assignment_count
            FROM pickup_reminders r
            LEFT JOIN customers c ON r.customer_id = c.id
            LEFT JOIN job_cards jc ON r.job_card_id = jc.id
            LEFT JOIN users u1 ON r.assigned_to = u1.id
            LEFT JOIN users u2 ON r.created_by = u2.id
            WHERE r.id = ?
        ");
        $stmt->execute([$id]);
        return $stmt->fetch();
    }
    
    public function getReminders($filters = [], $page = 1, $perPage = 15) {
        $sql = "SELECT 
                    r.*,
                    c.full_name as customer_name,
                    c.telephone as customer_phone,
                    u.full_name as assigned_to_name
                FROM pickup_reminders r
                LEFT JOIN customers c ON r.customer_id = c.id
                LEFT JOIN users u ON r.assigned_to = u.id
                WHERE 1=1";
        $params = [];
        
        if (!empty($filters['status'])) {
            $sql .= " AND r.status = ?";
            $params[] = $filters['status'];
        }
        
        if (!empty($filters['pickup_type'])) {
            $sql .= " AND r.pickup_type = ?";
            $params[] = $filters['pickup_type'];
        }
        
        if (!empty($filters['customer_id'])) {
            $sql .= " AND r.customer_id = ?";
            $params[] = $filters['customer_id'];
        }
        
        if (!empty($filters['date_from'])) {
            $sql .= " AND r.pickup_date >= ?";
            $params[] = $filters['date_from'];
        }
        
        if (!empty($filters['date_to'])) {
            $sql .= " AND r.pickup_date <= ?";
            $params[] = $filters['date_to'];
        }
        
        if (!empty($filters['search'])) {
            $sql .= " AND (c.full_name LIKE ? OR r.vehicle_reg LIKE ?)";
            $searchTerm = "%{$filters['search']}%";
            $params[] = $searchTerm;
            $params[] = $searchTerm;
        }
        
        $sql .= " ORDER BY r.pickup_date ASC, r.created_at DESC LIMIT ? OFFSET ?";
        $offset = ($page - 1) * $perPage;
        $params[] = $perPage;
        $params[] = $offset;
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $items = $stmt->fetchAll();
        
        // Get total count
        $countSql = "SELECT COUNT(*) as total FROM pickup_reminders r WHERE 1=1";
        $countParams = [];
        
        if (!empty($filters['status'])) {
            $countSql .= " AND r.status = ?";
            $countParams[] = $filters['status'];
        }
        
        if (!empty($filters['pickup_type'])) {
            $countSql .= " AND r.pickup_type = ?";
            $countParams[] = $filters['pickup_type'];
        }
        
        $countStmt = $this->db->prepare($countSql);
        $countStmt->execute($countParams);
        $total = $countStmt->fetch()['total'];
        
        return [
            'data' => $items,
            'current_page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'last_page' => ceil($total / $perPage)
        ];
    }
    
    public function getDueReminders() {
        $stmt = $this->db->prepare("
            SELECT r.*, c.full_name, c.telephone, c.email
            FROM pickup_reminders r
            JOIN customers c ON r.customer_id = c.id
            WHERE r.status IN ('pending', 'scheduled')
            AND r.reminder_date <= CURDATE()
            AND r.reminder_sent = 0
            ORDER BY r.reminder_date ASC
        ");
        $stmt->execute();
        return $stmt->fetchAll();
    }
    
    public function getStatistics() {
        $stmt = $this->db->query("
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN status = 'scheduled' THEN 1 ELSE 0 END) as scheduled,
                SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) as in_progress,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
                SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled,
                SUM(CASE WHEN pickup_type = 'workshop' THEN 1 ELSE 0 END) as workshop_pickups,
                SUM(CASE WHEN pickup_type = 'home' THEN 1 ELSE 0 END) as home_pickups,
                SUM(CASE WHEN pickup_type = 'office' THEN 1 ELSE 0 END) as office_pickups,
                SUM(CASE WHEN reminder_sent = 1 THEN 1 ELSE 0 END) as reminders_sent,
                SUM(CASE WHEN status IN ('pending', 'scheduled') AND pickup_date <= CURDATE() THEN 1 ELSE 0 END) as due_today
            FROM pickup_reminders
        ");
        return $stmt->fetch();
    }
    
    public function updateStatus($id, $status) {
        return $this->update($id, ['status' => $status]);
    }
    
    public function markReminderSent($id) {
        return $this->update($id, ['reminder_sent' => 1]);
    }
    
    public function getUpcomingPickups($days = 7) {
        $stmt = $this->db->prepare("
            SELECT r.*, c.full_name, c.telephone
            FROM pickup_reminders r
            JOIN customers c ON r.customer_id = c.id
            WHERE r.status IN ('pending', 'scheduled')
            AND r.pickup_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL ? DAY)
            ORDER BY r.pickup_date ASC
        ");
        $stmt->execute([$days]);
        return $stmt->fetchAll();
    }
    
    public function getPickupsByDate($date) {
        $stmt = $this->db->prepare("
            SELECT r.*, c.full_name, c.telephone, c.address
            FROM pickup_reminders r
            JOIN customers c ON r.customer_id = c.id
            WHERE r.pickup_date = ?
            ORDER BY r.pickup_time ASC
        ");
        $stmt->execute([$date]);
        return $stmt->fetchAll();
    }
}