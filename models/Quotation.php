<?php
// models/QuotationModel.php

class QuotationModel {
    private $db;

    public function __construct($db) {
        $this->db = $db;
    }

    public function getAll($filters = []) {
        $sql = "SELECT q.*, c.full_name as customer_name
                FROM quotations q
                LEFT JOIN customers c ON q.customer_id = c.id
                WHERE 1=1";
        $params = [];

        if (!empty($filters['status'])) {
            $sql .= " AND q.status = ?";
            $params[] = $filters['status'];
        }
        if (!empty($filters['from_date'])) {
            $sql .= " AND q.quotation_date >= ?";
            $params[] = $filters['from_date'];
        }
        if (!empty($filters['to_date'])) {
            $sql .= " AND q.quotation_date <= ?";
            $params[] = $filters['to_date'];
        }
        if (!empty($filters['search'])) {
            $sql .= " AND (q.quotation_number LIKE ? OR c.full_name LIKE ?)";
            $params[] = "%{$filters['search']}%";
            $params[] = "%{$filters['search']}%";
        }

        $sql .= " ORDER BY q.created_at DESC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getById($id) {
        $sql = "SELECT q.*, c.full_name as customer_name
                FROM quotations q
                LEFT JOIN customers c ON q.customer_id = c.id
                WHERE q.id = ?";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function create($data) {
        $sql = "INSERT INTO quotations (
                    quotation_number, customer_id, quotation_date, valid_until,
                    vehicle_reg, vehicle_model, odometer_reading,
                    items, discount, tax, subtotal, total_amount, notes,
                    status, created_at
                ) VALUES (
                    :quotation_number, :customer_id, :quotation_date, :valid_until,
                    :vehicle_reg, :vehicle_model, :odometer_reading,
                    :items, :discount, :tax, :subtotal, :total_amount, :notes,
                    'draft', NOW()
                )";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':quotation_number' => $data['quotation_number'],
            ':customer_id'      => $data['customer_id'],
            ':quotation_date'   => $data['quotation_date'],
            ':valid_until'      => $data['valid_until'],
            ':vehicle_reg'      => $data['vehicle_reg'] ?? null,
            ':vehicle_model'    => $data['vehicle_model'] ?? null,
            ':odometer_reading' => $data['odometer_reading'] ?? null,
            ':items'            => json_encode($data['items']),
            ':discount'         => $data['discount'] ?? 0,
            ':tax'              => $data['tax'] ?? 0,
            ':subtotal'         => $data['subtotal'],
            ':total_amount'     => $data['total_amount'],
            ':notes'            => $data['notes'] ?? null
        ]);
        return $this->db->lastInsertId();
    }

    public function update($id, $data) {
        $fields = [];
        $params = [];

        $allowed = ['quotation_number', 'customer_id', 'quotation_date', 'valid_until',
                    'vehicle_reg', 'vehicle_model', 'odometer_reading', 'items',
                    'discount', 'tax', 'subtotal', 'total_amount', 'notes', 'status'];

        foreach ($allowed as $field) {
            if (array_key_exists($field, $data)) {
                $fields[] = "$field = ?";
                $params[] = ($field === 'items') ? json_encode($data[$field]) : $data[$field];
            }
        }

        if (empty($fields)) {
            return false;
        }

        $params[] = $id;
        $sql = "UPDATE quotations SET " . implode(', ', $fields) . " WHERE id = ?";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute($params);
    }

    public function updateStatus($id, $status) {
        $sql = "UPDATE quotations SET status = ? WHERE id = ?";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([$status, $id]);
    }

    public function delete($id) {
        $sql = "DELETE FROM quotations WHERE id = ?";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([$id]);
    }

    public function getStatistics() {
        $sql = "SELECT
                    COUNT(*) as total_quotations,
                    SUM(CASE WHEN status = 'sent' THEN 1 ELSE 0 END) as sent_count,
                    SUM(CASE WHEN status = 'accepted' THEN 1 ELSE 0 END) as accepted_count,
                    COALESCE(SUM(total_amount), 0) as total_value
                FROM quotations";
        $stmt = $this->db->query($sql);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function generateQuotationNumber() {
        $year = date('Y');
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM quotations WHERE YEAR(created_at) = ?");
        $stmt->execute([$year]);
        $count = $stmt->fetchColumn() + 1;
        return "QT-{$year}-" . str_pad($count, 4, '0', STR_PAD_LEFT);
    }

    /**
     * Get quotations that are accepted and not yet converted to an invoice.
     */
    public function getConvertible() {
        $sql = "SELECT q.*, c.full_name as customer_name
                FROM quotations q
                LEFT JOIN customers c ON q.customer_id = c.id
                WHERE q.status = 'accepted'
                  AND q.id NOT IN (SELECT quotation_id FROM invoices WHERE quotation_id IS NOT NULL)
                ORDER BY q.created_at DESC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}