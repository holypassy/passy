<?php
require_once __DIR__ . '/../config/database.php';

class Voucher {
    private $conn;
    private $table = "vouchers";
    
    public function __construct() {
        $database = Database::getInstance();
        $this->conn = $database->getConnection();
    }
    
    public function getAll($filters = []) {
        $query = "SELECT 
                    v.*,
                    vt.type_name,
                    vt.type_code,
                    u.full_name as created_by_name,
                    pu.full_name as posted_by_name
                  FROM {$this->table} v
                  LEFT JOIN voucher_types vt ON v.voucher_type_id = vt.id
                  LEFT JOIN users u ON v.created_by = u.id
                  LEFT JOIN users pu ON v.posted_by = pu.id
                  WHERE 1=1";
        
        $params = [];
        
        if (!empty($filters['voucher_type'])) {
            $query .= " AND vt.type_code = :type_code";
            $params[':type_code'] = $filters['voucher_type'];
        }
        
        if (!empty($filters['status'])) {
            $query .= " AND v.status = :status";
            $params[':status'] = $filters['status'];
        }
        
        if (!empty($filters['from_date'])) {
            $query .= " AND v.voucher_date >= :from_date";
            $params[':from_date'] = $filters['from_date'];
        }
        
        if (!empty($filters['to_date'])) {
            $query .= " AND v.voucher_date <= :to_date";
            $params[':to_date'] = $filters['to_date'];
        }
        
        if (!empty($filters['search'])) {
            $query .= " AND (v.voucher_number LIKE :search OR v.received_from LIKE :search OR v.paid_to LIKE :search)";
            $params[':search'] = "%{$filters['search']}%";
        }
        
        $query .= " ORDER BY v.voucher_date DESC, v.created_at DESC";
        
        if (!empty($filters['limit'])) {
            $query .= " LIMIT :limit";
            $params[':limit'] = (int)$filters['limit'];
        }
        
        if (!empty($filters['offset'])) {
            $query .= " OFFSET :offset";
            $params[':offset'] = (int)$filters['offset'];
        }
        
        $stmt = $this->conn->prepare($query);
        
        foreach ($params as $key => $value) {
            if ($key === ':limit' || $key === ':offset') {
                $stmt->bindValue($key, $value, PDO::PARAM_INT);
            } else {
                $stmt->bindValue($key, $value);
            }
        }
        
        $stmt->execute();
        return $stmt->fetchAll();
    }
    
    public function findById($id) {
        $query = "SELECT 
                    v.*,
                    vt.type_name,
                    vt.type_code,
                    u.full_name as created_by_name,
                    pu.full_name as posted_by_name
                  FROM {$this->table} v
                  LEFT JOIN voucher_types vt ON v.voucher_type_id = vt.id
                  LEFT JOIN users u ON v.created_by = u.id
                  LEFT JOIN users pu ON v.posted_by = pu.id
                  WHERE v.id = :id";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute([':id' => $id]);
        return $stmt->fetch();
    }
    
    public function findByNumber($number) {
        $query = "SELECT * FROM {$this->table} WHERE voucher_number = :number";
        $stmt = $this->conn->prepare($query);
        $stmt->execute([':number' => $number]);
        return $stmt->fetch();
    }
    
    public function create($data) {
        $query = "INSERT INTO {$this->table} 
                  (voucher_number, voucher_type_id, voucher_date, amount, 
                   received_from, paid_to, payment_mode, reference_no, 
                   narration, status, created_by) 
                  VALUES (:voucher_number, :voucher_type_id, :voucher_date, :amount,
                          :received_from, :paid_to, :payment_mode, :reference_no,
                          :narration, :status, :created_by)";
        
        $stmt = $this->conn->prepare($query);
        $result = $stmt->execute([
            ':voucher_number' => $data['voucher_number'],
            ':voucher_type_id' => $data['voucher_type_id'],
            ':voucher_date' => $data['voucher_date'],
            ':amount' => $data['amount'],
            ':received_from' => $data['received_from'] ?? null,
            ':paid_to' => $data['paid_to'] ?? null,
            ':payment_mode' => $data['payment_mode'] ?? 'cash',
            ':reference_no' => $data['reference_no'] ?? null,
            ':narration' => $data['narration'] ?? null,
            ':status' => $data['status'] ?? 'draft',
            ':created_by' => $data['created_by']
        ]);
        
        if ($result) {
            return $this->conn->lastInsertId();
        }
        
        return false;
    }
    
    public function update($id, $data) {
        $query = "UPDATE {$this->table} 
                  SET voucher_date = :voucher_date,
                      amount = :amount,
                      received_from = :received_from,
                      paid_to = :paid_to,
                      payment_mode = :payment_mode,
                      reference_no = :reference_no,
                      narration = :narration
                  WHERE id = :id";
        
        $stmt = $this->conn->prepare($query);
        return $stmt->execute([
            ':id' => $id,
            ':voucher_date' => $data['voucher_date'],
            ':amount' => $data['amount'],
            ':received_from' => $data['received_from'] ?? null,
            ':paid_to' => $data['paid_to'] ?? null,
            ':payment_mode' => $data['payment_mode'] ?? 'cash',
            ':reference_no' => $data['reference_no'] ?? null,
            ':narration' => $data['narration'] ?? null
        ]);
    }
    
    public function updateStatus($id, $status, $postedBy = null) {
        $query = "UPDATE {$this->table} 
                  SET status = :status";
        
        $params = [':id' => $id, ':status' => $status];
        
        if ($status === 'posted' && $postedBy) {
            $query .= ", posted_by = :posted_by, posted_at = NOW()";
            $params[':posted_by'] = $postedBy;
        }
        
        $query .= " WHERE id = :id";
        
        $stmt = $this->conn->prepare($query);
        return $stmt->execute($params);
    }
    
    public function delete($id) {
        $query = "DELETE FROM {$this->table} WHERE id = :id";
        $stmt = $this->conn->prepare($query);
        return $stmt->execute([':id' => $id]);
    }
    
    public function getStatistics($type = null) {
        $query = "SELECT 
                    COUNT(*) as total,
                    SUM(CASE WHEN status = 'draft' THEN 1 ELSE 0 END) as draft,
                    SUM(CASE WHEN status = 'posted' THEN 1 ELSE 0 END) as posted,
                    SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled,
                    COALESCE(SUM(amount), 0) as total_amount
                  FROM {$this->table}
                  WHERE 1=1";
        
        $params = [];
        
        if ($type) {
            $query .= " AND voucher_type_id = (SELECT id FROM voucher_types WHERE type_code = :type_code)";
            $params[':type_code'] = $type;
        }
        
        $stmt = $this->conn->prepare($query);
        
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        
        $stmt->execute();
        return $stmt->fetch();
    }
    
    public function generateVoucherNumber($typeCode) {
        $prefix = '';
        
        switch ($typeCode) {
            case 'RECEIPT':
                $prefix = 'RV';
                break;
            case 'PAYMENT':
                $prefix = 'PV';
                break;
            case 'SALES':
                $prefix = 'SV';
                break;
            case 'PURCHASE':
                $prefix = 'PV';
                break;
            case 'CONTRA':
                $prefix = 'CV';
                break;
            case 'JOURNAL':
                $prefix = 'JV';
                break;
            default:
                $prefix = 'V';
        }
        
        $year = date('Y');
        $month = date('m');
        
        $query = "SELECT voucher_number FROM {$this->table} 
                  WHERE voucher_number LIKE :prefix 
                  ORDER BY id DESC LIMIT 1";
        
        $stmt = $this->conn->prepare($query);
        $stmt->execute([':prefix' => "{$prefix}-{$year}{$month}%"]);
        $last = $stmt->fetch();
        
        if ($last) {
            $lastNum = (int)substr($last['voucher_number'], -4);
            $newNum = str_pad($lastNum + 1, 4, '0', STR_PAD_LEFT);
        } else {
            $newNum = '0001';
        }
        
        return "{$prefix}-{$year}{$month}-{$newNum}";
    }
}
?>