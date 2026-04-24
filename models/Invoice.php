<?php
// models/InvoiceModel.php

class InvoiceModel {
    private $db;

    public function __construct($db) {
        $this->db = $db;
    }

    public function getAll($filters = []) {
        $sql = "SELECT i.*, c.full_name as customer_name
                FROM invoices i
                LEFT JOIN customers c ON i.customer_id = c.id
                WHERE 1=1";
        $params = [];

        if (!empty($filters['status'])) {
            $sql .= " AND i.payment_status = ?";
            $params[] = $filters['status'];
        }
        if (!empty($filters['from_date'])) {
            $sql .= " AND i.invoice_date >= ?";
            $params[] = $filters['from_date'];
        }
        if (!empty($filters['to_date'])) {
            $sql .= " AND i.invoice_date <= ?";
            $params[] = $filters['to_date'];
        }
        if (!empty($filters['search'])) {
            $sql .= " AND (i.invoice_number LIKE ? OR c.full_name LIKE ?)";
            $params[] = "%{$filters['search']}%";
            $params[] = "%{$filters['search']}%";
        }

        $sql .= " ORDER BY i.created_at DESC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getById($id) {
        $sql = "SELECT i.*, c.full_name as customer_name
                FROM invoices i
                LEFT JOIN customers c ON i.customer_id = c.id
                WHERE i.id = ?";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function create($data) {
        $sql = "INSERT INTO invoices (
                    invoice_number, customer_id, invoice_date, due_date,
                    vehicle_reg, vehicle_model, odometer_reading,
                    items, discount, tax, subtotal, total_amount, notes,
                    payment_status, created_at
                ) VALUES (
                    :invoice_number, :customer_id, :invoice_date, :due_date,
                    :vehicle_reg, :vehicle_model, :odometer_reading,
                    :items, :discount, :tax, :subtotal, :total_amount, :notes,
                    'unpaid', NOW()
                )";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':invoice_number' => $data['invoice_number'],
            ':customer_id'    => $data['customer_id'],
            ':invoice_date'   => $data['invoice_date'],
            ':due_date'       => $data['due_date'],
            ':vehicle_reg'    => $data['vehicle_reg'] ?? null,
            ':vehicle_model'  => $data['vehicle_model'] ?? null,
            ':odometer_reading' => $data['odometer_reading'] ?? null,
            ':items'          => json_encode($data['items']),
            ':discount'       => $data['discount'] ?? 0,
            ':tax'            => $data['tax'] ?? 0,
            ':subtotal'       => $data['subtotal'],
            ':total_amount'   => $data['total_amount'],
            ':notes'          => $data['notes'] ?? null
        ]);
        return $this->db->lastInsertId();
    }

    public function update($id, $data) {
        $fields = [];
        $params = [];

        $allowed = ['invoice_number', 'customer_id', 'invoice_date', 'due_date',
                    'vehicle_reg', 'vehicle_model', 'odometer_reading', 'items',
                    'discount', 'tax', 'subtotal', 'total_amount', 'notes', 'payment_status'];

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
        $sql = "UPDATE invoices SET " . implode(', ', $fields) . " WHERE id = ?";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute($params);
    }

    public function recordPayment($invoiceId, $amount, $paymentMethod) {
        $this->db->beginTransaction();
        try {
            $invoice = $this->getById($invoiceId);
            if (!$invoice) {
                throw new Exception("Invoice not found");
            }

            $newPaid = ($invoice['amount_paid'] ?? 0) + $amount;
            $total = $invoice['total_amount'];

            $status = ($newPaid >= $total) ? 'paid' : (($newPaid > 0) ? 'partial' : 'unpaid');

            $sql = "UPDATE invoices SET amount_paid = ?, payment_status = ? WHERE id = ?";
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$newPaid, $status, $invoiceId]);

            // Optional: record payment in payments table
            // $sql = "INSERT INTO payments (invoice_id, amount, payment_method, payment_date) VALUES (?, ?, ?, NOW())";
            // $stmt = $this->db->prepare($sql);
            // $stmt->execute([$invoiceId, $amount, $paymentMethod]);

            $this->db->commit();
            return true;
        } catch (Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function delete($id) {
        $sql = "DELETE FROM invoices WHERE id = ?";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([$id]);
    }

    public function getStatistics() {
        $sql = "SELECT
                    COUNT(*) as total_invoices,
                    SUM(CASE WHEN payment_status = 'paid' THEN 1 ELSE 0 END) as paid_count,
                    SUM(CASE WHEN payment_status = 'unpaid' THEN 1 ELSE 0 END) as unpaid_count,
                    SUM(CASE WHEN payment_status = 'partial' THEN 1 ELSE 0 END) as partial_count,
                    COALESCE(SUM(total_amount), 0) as total_amount,
                    COALESCE(SUM(CASE WHEN payment_status = 'paid' THEN total_amount ELSE 0 END), 0) as collected_amount
                FROM invoices";
        $stmt = $this->db->query($sql);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function generateInvoiceNumber() {
        $year = date('Y');
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM invoices WHERE YEAR(created_at) = ?");
        $stmt->execute([$year]);
        $count = $stmt->fetchColumn() + 1;
        return "INV-{$year}-" . str_pad($count, 4, '0', STR_PAD_LEFT);
    }

    public function convertFromQuotation($quotationId) {
        // Fetch the quotation
        $stmt = $this->db->prepare("SELECT * FROM quotations WHERE id = ?");
        $stmt->execute([$quotationId]);
        $quotation = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$quotation) {
            throw new Exception("Quotation not found");
        }
        if ($quotation['status'] !== 'accepted') {
            throw new Exception("Only accepted quotations can be converted");
        }

        // Check if already converted
        $stmt = $this->db->prepare("SELECT id FROM invoices WHERE quotation_id = ?");
        $stmt->execute([$quotationId]);
        if ($stmt->fetch()) {
            throw new Exception("This quotation has already been converted to an invoice");
        }

        $invoiceNumber = $this->generateInvoiceNumber();
        $invoiceDate = date('Y-m-d');
        $dueDate = date('Y-m-d', strtotime('+30 days'));

        $items = $quotation['items'];
        if (is_string($items)) {
            $items = json_decode($items, true);
        }

        $sql = "INSERT INTO invoices (
                    invoice_number, customer_id, invoice_date, due_date,
                    vehicle_reg, vehicle_model, odometer_reading,
                    items, discount, tax, subtotal, total_amount, notes,
                    quotation_id, payment_status, created_at
                ) VALUES (
                    :invoice_number, :customer_id, :invoice_date, :due_date,
                    :vehicle_reg, :vehicle_model, :odometer_reading,
                    :items, :discount, :tax, :subtotal, :total_amount, :notes,
                    :quotation_id, 'unpaid', NOW()
                )";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':invoice_number'   => $invoiceNumber,
            ':customer_id'      => $quotation['customer_id'],
            ':invoice_date'     => $invoiceDate,
            ':due_date'         => $dueDate,
            ':vehicle_reg'      => $quotation['vehicle_reg'],
            ':vehicle_model'    => $quotation['vehicle_model'],
            ':odometer_reading' => $quotation['odometer_reading'],
            ':items'            => json_encode($items),
            ':discount'         => $quotation['discount'],
            ':tax'              => $quotation['tax'],
            ':subtotal'         => $quotation['subtotal'],
            ':total_amount'     => $quotation['total_amount'],
            ':notes'            => $quotation['notes'],
            ':quotation_id'     => $quotationId
        ]);

        return $this->db->lastInsertId();
    }
}