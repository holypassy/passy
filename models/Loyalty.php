<?php
namespace App\Models;

use Core\Model;

class Loyalty extends Model {
    protected $table = 'customer_loyalty';
    protected $fillable = [
        'customer_id', 'loyalty_points', 'total_spent', 'total_visits', 'last_visit_date', 'joined_date'
    ];
    
    public function getByCustomer($customerId) {
        $stmt = $this->db->prepare("
            SELECT l.*, c.full_name, c.customer_tier
            FROM customer_loyalty l
            JOIN customers c ON l.customer_id = c.id
            WHERE l.customer_id = ?
        ");
        $stmt->execute([$customerId]);
        return $stmt->fetch();
    }
    
    public function addPoints($customerId, $points) {
        $stmt = $this->db->prepare("
            UPDATE customer_loyalty 
            SET loyalty_points = loyalty_points + ?
            WHERE customer_id = ?
        ");
        return $stmt->execute([$points, $customerId]);
    }
    
    public function redeemPoints($customerId, $points) {
        $stmt = $this->db->prepare("
            UPDATE customer_loyalty 
            SET loyalty_points = loyalty_points - ?
            WHERE customer_id = ? AND loyalty_points >= ?
        ");
        return $stmt->execute([$points, $customerId, $points]);
    }
    
    public function getTopLoyaltyCustomers($limit = 10) {
        $stmt = $this->db->prepare("
            SELECT l.*, c.full_name, c.telephone, c.email
            FROM customer_loyalty l
            JOIN customers c ON l.customer_id = c.id
            ORDER BY l.loyalty_points DESC
            LIMIT ?
        ");
        $stmt->execute([$limit]);
        return $stmt->fetchAll();
    }
    
    public function updateTier($customerId) {
        $loyalty = $this->getByCustomer($customerId);
        if (!$loyalty) return false;
        
        $totalSpent = $loyalty['total_spent'];
        $newTier = 'bronze';
        
        if ($totalSpent >= 10000000) {
            $newTier = 'platinum';
        } elseif ($totalSpent >= 5000000) {
            $newTier = 'gold';
        } elseif ($totalSpent >= 1000000) {
            $newTier = 'silver';
        }
        
        $stmt = $this->db->prepare("
            UPDATE customers 
            SET customer_tier = ? 
            WHERE id = ?
        ");
        return $stmt->execute([$newTier, $customerId]);
    }
}