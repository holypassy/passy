<?php
namespace App\Models;

use Core\Model;

class PickupLocation extends Model {
    protected $table = 'pickup_locations';
    protected $fillable = [
        'customer_id', 'location_name', 'address', 'latitude', 
        'longitude', 'landmark', 'instructions', 'is_default'
    ];
    
    public function getByCustomer($customerId) {
        $stmt = $this->db->prepare("
            SELECT * FROM pickup_locations 
            WHERE customer_id = ? 
            ORDER BY is_default DESC, location_name ASC
        ");
        $stmt->execute([$customerId]);
        return $stmt->fetchAll();
    }
    
    public function getDefaultLocation($customerId) {
        $stmt = $this->db->prepare("
            SELECT * FROM pickup_locations 
            WHERE customer_id = ? AND is_default = 1
            LIMIT 1
        ");
        $stmt->execute([$customerId]);
        return $stmt->fetch();
    }
    
    public function setDefault($id, $customerId) {
        // Remove default from other locations
        $this->db->prepare("UPDATE pickup_locations SET is_default = 0 WHERE customer_id = ?")
            ->execute([$customerId]);
        
        // Set this as default
        return $this->update($id, ['is_default' => 1]);
    }
}