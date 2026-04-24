<?php
require_once __DIR__ . '/../config/database.php';

class Supplier {
    private $conn;
    private $table = "suppliers";
    
    public function __construct() {
        $database = Database::getInstance();
        $this->conn = $database->getConnection();
    }
    
    public function getAll() {
        $query = "SELECT * FROM {$this->table} ORDER BY supplier_name ASC";
        $stmt = $this->conn->prepare($query);
        $stmt->execute();
        return $stmt->fetchAll();
    }
    
    public function findById($id) {
        $query = "SELECT * FROM {$this->table} WHERE id = :id";
        $stmt = $this->conn->prepare($query);
        $stmt->execute([':id' => $id]);
        return $stmt->fetch();
    }
    
    public function create($data) {
        $query = "INSERT INTO {$this->table} 
                  (supplier_name, contact_person, phone, email, address, tax_id) 
                  VALUES (:supplier_name, :contact_person, :phone, :email, :address, :tax_id)";
        
        $stmt = $this->conn->prepare($query);
        return $stmt->execute([
            ':supplier_name' => $data['supplier_name'],
            ':contact_person' => $data['contact_person'] ?? null,
            ':phone' => $data['phone'] ?? null,
            ':email' => $data['email'] ?? null,
            ':address' => $data['address'] ?? null,
            ':tax_id' => $data['tax_id'] ?? null
        ]);
    }
    
    public function update($id, $data) {
        $query = "UPDATE {$this->table} 
                  SET supplier_name = :supplier_name,
                      contact_person = :contact_person,
                      phone = :phone,
                      email = :email,
                      address = :address,
                      tax_id = :tax_id
                  WHERE id = :id";
        
        $stmt = $this->conn->prepare($query);
        return $stmt->execute([
            ':id' => $id,
            ':supplier_name' => $data['supplier_name'],
            ':contact_person' => $data['contact_person'] ?? null,
            ':phone' => $data['phone'] ?? null,
            ':email' => $data['email'] ?? null,
            ':address' => $data['address'] ?? null,
            ':tax_id' => $data['tax_id'] ?? null
        ]);
    }
    
    public function delete($id) {
        $query = "DELETE FROM {$this->table} WHERE id = :id";
        $stmt = $this->conn->prepare($query);
        return $stmt->execute([':id' => $id]);
    }
    
    public function getProducts($supplierId) {
        $query = "SELECT * FROM inventory WHERE supplier_id = :supplier_id AND is_active = 1";
        $stmt = $this->conn->prepare($query);
        $stmt->execute([':supplier_id' => $supplierId]);
        return $stmt->fetchAll();
    }
}
?>