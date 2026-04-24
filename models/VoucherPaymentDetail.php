<?php
require_once __DIR__ . '/../config/database.php';

class VoucherPaymentDetail {
    private $conn;
    private $table = "voucher_payment_details";
    
    public function __construct() {
        $database = Database::getInstance();
        $this->conn = $database->getConnection();
    }
    
    public function getByVoucherId($voucherId) {
        $query = "SELECT * FROM {$this->table} WHERE voucher_id = :voucher_id";
        $stmt = $this->conn->prepare($query);
        $stmt->execute([':voucher_id' => $voucherId]);
        return $stmt->fetch();
    }
    
    public function create($data) {
        $query = "INSERT INTO {$this->table} 
                  (voucher_id, bank_name, cheque_number, mobile_number, transaction_id, card_number) 
                  VALUES (:voucher_id, :bank_name, :cheque_number, :mobile_number, :transaction_id, :card_number)";
        
        $stmt = $this->conn->prepare($query);
        return $stmt->execute([
            ':voucher_id' => $data['voucher_id'],
            ':bank_name' => $data['bank_name'] ?? null,
            ':cheque_number' => $data['cheque_number'] ?? null,
            ':mobile_number' => $data['mobile_number'] ?? null,
            ':transaction_id' => $data['transaction_id'] ?? null,
            ':card_number' => $data['card_number'] ?? null
        ]);
    }
    
    public function update($voucherId, $data) {
        // Delete existing and recreate
        $this->deleteByVoucherId($voucherId);
        return $this->create(array_merge(['voucher_id' => $voucherId], $data));
    }
    
    public function deleteByVoucherId($voucherId) {
        $query = "DELETE FROM {$this->table} WHERE voucher_id = :voucher_id";
        $stmt = $this->conn->prepare($query);
        return $stmt->execute([':voucher_id' => $voucherId]);
    }
}
?>