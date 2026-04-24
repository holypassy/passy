<?php
require_once __DIR__ . '/../core/Model.php';

class Service extends Model {
    protected $table = 'services';
    
    public function create($data) {
        $query = "INSERT INTO services (service_name, category, standard_price, 
                  estimated_duration, track_interval, service_interval, 
                  interval_unit, requires_parts, description) 
                  VALUES (:service_name, :category, :standard_price, 
                  :estimated_duration, :track_interval, :service_interval, 
                  :interval_unit, :requires_parts, :description)";
        
        $stmt = $this->db->prepare($query);
        
        $stmt->bindParam(':service_name', $data['service_name']);
        $stmt->bindParam(':category', $data['category']);
        $stmt->bindParam(':standard_price', $data['standard_price']);
        $stmt->bindParam(':estimated_duration', $data['estimated_duration']);
        $stmt->bindParam(':track_interval', $data['track_interval']);
        $stmt->bindParam(':service_interval', $data['service_interval']);
        $stmt->bindParam(':interval_unit', $data['interval_unit']);
        $stmt->bindParam(':requires_parts', $data['requires_parts']);
        $stmt->bindParam(':description', $data['description']);
        
        return $stmt->execute();
    }
    
    public function update($id, $data) {
        $query = "UPDATE services SET service_name = :service_name, 
                  category = :category, standard_price = :standard_price,
                  estimated_duration = :estimated_duration,
                  track_interval = :track_interval,
                  service_interval = :service_interval,
                  interval_unit = :interval_unit,
                  requires_parts = :requires_parts,
                  description = :description
                  WHERE id = :id";
        
        $stmt = $this->db->prepare($query);
        $data['id'] = $id;
        
        return $stmt->execute($data);
    }
    
    public function getByCategory($category) {
        $query = "SELECT * FROM services WHERE category = :category";
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':category', $category);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    public function getRevenueStats() {
        $query = "SELECT category, COUNT(*) as count, SUM(standard_price) as total 
                  FROM services GROUP BY category";
        $stmt = $this->db->prepare($query);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}