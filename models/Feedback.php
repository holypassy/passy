<?php
namespace App\Models;

use Core\Model;

class Feedback extends Model {
    protected $table = 'customer_feedback';
    protected $fillable = [
        'customer_id', 'feedback_date', 'rating', 'feedback_text', 'category'
    ];
    
    public function getByCustomer($customerId) {
        $stmt = $this->db->prepare("
            SELECT * FROM customer_feedback 
            WHERE customer_id = ? 
            ORDER BY feedback_date DESC
        ");
        $stmt->execute([$customerId]);
        return $stmt->fetchAll();
    }
    
    public function getAverageRating() {
        $stmt = $this->db->query("
            SELECT 
                AVG(rating) as avg_rating,
                COUNT(*) as total_reviews
            FROM customer_feedback
        ");
        return $stmt->fetch();
    }
    
    public function getRatingDistribution() {
        $stmt = $this->db->query("
            SELECT 
                rating,
                COUNT(*) as count
            FROM customer_feedback
            GROUP BY rating
            ORDER BY rating DESC
        ");
        return $stmt->fetchAll();
    }
    
    public function getFeedbackByCategory() {
        $stmt = $this->db->query("
            SELECT 
                category,
                COUNT(*) as total,
                AVG(rating) as avg_rating
            FROM customer_feedback
            WHERE category IS NOT NULL
            GROUP BY category
            ORDER BY total DESC
        ");
        return $stmt->fetchAll();
    }
    
    public function getRecentFeedback($limit = 20) {
        $stmt = $this->db->prepare("
            SELECT f.*, c.full_name as customer_name, c.telephone
            FROM customer_feedback f
            JOIN customers c ON f.customer_id = c.id
            ORDER BY f.feedback_date DESC
            LIMIT ?
        ");
        $stmt->execute([$limit]);
        return $stmt->fetchAll();
    }
    
    public function getLowRatingFeedback($rating = 3) {
        $stmt = $this->db->prepare("
            SELECT f.*, c.full_name as customer_name, c.telephone, c.email
            FROM customer_feedback f
            JOIN customers c ON f.customer_id = c.id
            WHERE f.rating <= ?
            ORDER BY f.feedback_date DESC
        ");
        $stmt->execute([$rating]);
        return $stmt->fetchAll();
    }
}