<?php
namespace App\Models;

use Core\Model;
use PDO;

class Customer extends Model {
    protected $table = 'customers';
    protected $primaryKey = 'id';
    protected $fillable = [
        'full_name', 'telephone', 'email', 'address', 'tax_id',
        'credit_limit', 'balance', 'preferred_contact', 'preferred_language',
        'customer_source', 'notes', 'last_contact_date', 'next_follow_up_date',
        'assigned_sales_rep', 'customer_tier', 'status'
    ];
    
    public function getWithDetails($id) {
        $stmt = $this->db->prepare("
            SELECT 
                c.*,
                u.full_name as sales_rep_name,
                COALESCE(l.loyalty_points, 0) as loyalty_points,
                COALESCE(l.total_spent, 0) as total_spent,
                COALESCE(l.total_visits, 0) as total_visits,
                COALESCE(l.last_visit_date, '') as last_visit_date,
                (SELECT COUNT(*) FROM customer_interactions WHERE customer_id = c.id) as total_interactions,
                (SELECT COUNT(*) FROM customer_communications WHERE customer_id = c.id AND comm_status != 'closed') as open_issues,
                (SELECT AVG(rating) FROM customer_feedback WHERE customer_id = c.id) as avg_rating
            FROM customers c
            LEFT JOIN users u ON c.assigned_sales_rep = u.id
            LEFT JOIN customer_loyalty l ON c.id = l.customer_id
            WHERE c.id = ?
        ");
        $stmt->execute([$id]);
        return $stmt->fetch();
    }
    
    public function getRecentInteractions($customerId, $limit = 10) {
        $stmt = $this->db->prepare("
            SELECT * FROM customer_interactions 
            WHERE customer_id = ? 
            ORDER BY interaction_date DESC 
            LIMIT ?
        ");
        $stmt->execute([$customerId, $limit]);
        return $stmt->fetchAll();
    }
    
    public function getCommunications($customerId, $limit = 10) {
        $stmt = $this->db->prepare("
            SELECT * FROM customer_communications 
            WHERE customer_id = ? 
            ORDER BY created_at DESC 
            LIMIT ?
        ");
        $stmt->execute([$customerId, $limit]);
        return $stmt->fetchAll();
    }
    
    public function getFeedback($customerId, $limit = 10) {
        $stmt = $this->db->prepare("
            SELECT * FROM customer_feedback 
            WHERE customer_id = ? 
            ORDER BY feedback_date DESC 
            LIMIT ?
        ");
        $stmt->execute([$customerId, $limit]);
        return $stmt->fetchAll();
    }
    
    public function getStatistics() {
        $stmt = $this->db->query("
            SELECT 
                COUNT(*) as total_customers,
                SUM(CASE WHEN customer_tier = 'platinum' THEN 1 ELSE 0 END) as platinum,
                SUM(CASE WHEN customer_tier = 'gold' THEN 1 ELSE 0 END) as gold,
                SUM(CASE WHEN customer_tier = 'silver' THEN 1 ELSE 0 END) as silver,
                SUM(CASE WHEN customer_tier = 'bronze' THEN 1 ELSE 0 END) as bronze,
                SUM(CASE WHEN status = 1 THEN 1 ELSE 0 END) as active_customers,
                SUM(CASE WHEN next_follow_up_date <= CURDATE() AND next_follow_up_date IS NOT NULL THEN 1 ELSE 0 END) as pending_followups,
                (SELECT AVG(rating) FROM customer_feedback) as avg_rating
            FROM customers
            WHERE status = 1
        ");
        return $stmt->fetch();
    }
    
    public function getTopCustomers($limit = 10) {
        $stmt = $this->db->prepare("
            SELECT 
                c.*,
                COALESCE(l.total_spent, 0) as total_spent,
                COALESCE(l.loyalty_points, 0) as loyalty_points
            FROM customers c
            LEFT JOIN customer_loyalty l ON c.id = l.customer_id
            WHERE c.status = 1
            ORDER BY l.total_spent DESC
            LIMIT ?
        ");
        $stmt->execute([$limit]);
        return $stmt->fetchAll();
    }
    
    public function search($keyword, $limit = 20) {
        $stmt = $this->db->prepare("
            SELECT * FROM customers 
            WHERE status = 1 
            AND (full_name LIKE ? OR telephone LIKE ? OR email LIKE ?)
            ORDER BY full_name ASC
            LIMIT ?
        ");
        $searchTerm = "%{$keyword}%";
        $stmt->execute([$searchTerm, $searchTerm, $searchTerm, $limit]);
        return $stmt->fetchAll();
    }
    
    public function updateLoyalty($customerId, $amount) {
        $stmt = $this->db->prepare("
            UPDATE customer_loyalty 
            SET total_spent = total_spent + ?,
                total_visits = total_visits + 1,
                loyalty_points = loyalty_points + FLOOR(? / 1000),
                last_visit_date = CURDATE()
            WHERE customer_id = ?
        ");
        return $stmt->execute([$amount, $amount, $customerId]);
    }
    
    public function getCustomersByTier($tier) {
        $stmt = $this->db->prepare("
            SELECT * FROM customers 
            WHERE customer_tier = ? AND status = 1
            ORDER BY full_name
        ");
        $stmt->execute([$tier]);
        return $stmt->fetchAll();
    }
    
    public function getBirthdayCustomers() {
        $stmt = $this->db->prepare("
            SELECT * FROM customers 
            WHERE MONTH(birth_date) = MONTH(CURDATE()) 
            AND DAY(birth_date) = DAY(CURDATE())
            AND status = 1
        ");
        $stmt->execute();
        return $stmt->fetchAll();
    }
}