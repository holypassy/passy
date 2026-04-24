<?php
namespace App\Models;

use Core\Model;

class Interaction extends Model {
    protected $table = 'customer_interactions';
    protected $fillable = [
        'customer_id', 'interaction_date', 'interaction_type',
        'summary', 'notes', 'follow_up_date', 'created_by'
    ];
    
    public function getByCustomer($customerId, $limit = 50) {
        $stmt = $this->db->prepare("
            SELECT i.*, u.full_name as created_by_name
            FROM customer_interactions i
            LEFT JOIN users u ON i.created_by = u.id
            WHERE i.customer_id = ?
            ORDER BY i.interaction_date DESC
            LIMIT ?
        ");
        $stmt->execute([$customerId, $limit]);
        return $stmt->fetchAll();
    }
    
    public function getPendingFollowups() {
        $stmt = $this->db->prepare("
            SELECT i.*, c.full_name as customer_name, c.telephone, c.email
            FROM customer_interactions i
            JOIN customers c ON i.customer_id = c.id
            WHERE i.follow_up_date <= CURDATE() 
            AND i.follow_up_date IS NOT NULL
            AND c.status = 1
            ORDER BY i.follow_up_date ASC
        ");
        $stmt->execute();
        return $stmt->fetchAll();
    }
    
    public function getInteractionsByType($type, $startDate = null, $endDate = null) {
        $sql = "SELECT i.*, c.full_name as customer_name 
                FROM customer_interactions i
                JOIN customers c ON i.customer_id = c.id
                WHERE i.interaction_type = ?";
        $params = [$type];
        
        if ($startDate) {
            $sql .= " AND i.interaction_date >= ?";
            $params[] = $startDate;
        }
        if ($endDate) {
            $sql .= " AND i.interaction_date <= ?";
            $params[] = $endDate;
        }
        
        $sql .= " ORDER BY i.interaction_date DESC";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
    
    public function getInteractionStats($startDate = null, $endDate = null) {
        $sql = "SELECT 
                    interaction_type,
                    COUNT(*) as total,
                    DATE(interaction_date) as date
                FROM customer_interactions";
        $params = [];
        
        if ($startDate) {
            $sql .= " WHERE interaction_date >= ?";
            $params[] = $startDate;
        }
        if ($endDate) {
            $sql .= (strpos($sql, 'WHERE') ? ' AND' : ' WHERE') . " interaction_date <= ?";
            $params[] = $endDate;
        }
        
        $sql .= " GROUP BY interaction_type, DATE(interaction_date)
                  ORDER BY interaction_date DESC";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
}