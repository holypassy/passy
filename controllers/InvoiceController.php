<?php
// controllers/InvoiceController.php

require_once __DIR__ . '/../models/InvoiceModel.php';
require_once __DIR__ . '/../models/QuotationModel.php';
require_once __DIR__ . '/../utils/Response.php';
require_once __DIR__ . '/../utils/Database.php';

class InvoiceController {
    private $invoiceModel;
    private $quotationModel;

    public function __construct() {
        $db = Database::getConnection();
        $this->invoiceModel = new InvoiceModel($db);
        $this->quotationModel = new QuotationModel($db);
    }

    public function getAll() {
        $filters = [];
        if (isset($_GET['status'])) $filters['status'] = $_GET['status'];
        if (isset($_GET['from_date'])) $filters['from_date'] = $_GET['from_date'];
        if (isset($_GET['to_date'])) $filters['to_date'] = $_GET['to_date'];
        if (isset($_GET['search'])) $filters['search'] = $_GET['search'];

        try {
            $invoices = $this->invoiceModel->getAll($filters);
            $stats = $this->invoiceModel->getStatistics();
            Response::json(['success' => true, 'data' => $invoices, 'statistics' => $stats]);
        } catch (Exception $e) {
            Response::json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function getOne($id) {
        try {
            $invoice = $this->invoiceModel->getById($id);
            if (!$invoice) {
                Response::json(['error' => 'Invoice not found'], 404);
                return;
            }
            Response::json(['success' => true, 'data' => $invoice]);
        } catch (Exception $e) {
            Response::json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function create() {
        $input = json_decode(file_get_contents('php://input'), true);
        if (!$input) {
            Response::json(['error' => 'Invalid JSON'], 400);
            return;
        }

        if (empty($input['customer_id']) || empty($input['invoice_date']) || empty($input['due_date'])) {
            Response::json(['error' => 'Missing required fields'], 400);
            return;
        }

        if (empty($input['invoice_number'])) {
            $input['invoice_number'] = $this->invoiceModel->generateInvoiceNumber();
        }

        try {
            $id = $this->invoiceModel->create($input);
            Response::json(['success' => true, 'message' => 'Invoice created', 'data' => ['id' => $id]]);
        } catch (Exception $e) {
            Response::json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function update($id) {
        $input = json_decode(file_get_contents('php://input'), true);
        if (!$input) {
            Response::json(['error' => 'Invalid JSON'], 400);
            return;
        }

        try {
            $updated = $this->invoiceModel->update($id, $input);
            if (!$updated) {
                Response::json(['error' => 'Invoice not found or no changes'], 404);
                return;
            }
            Response::json(['success' => true, 'message' => 'Invoice updated']);
        } catch (Exception $e) {
            Response::json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function recordPayment($id) {
        $input = json_decode(file_get_contents('php://input'), true);
        if (empty($input['amount']) || empty($input['payment_method'])) {
            Response::json(['error' => 'Amount and payment method required'], 400);
            return;
        }

        try {
            $this->invoiceModel->recordPayment($id, $input['amount'], $input['payment_method']);
            Response::json(['success' => true, 'message' => 'Payment recorded']);
        } catch (Exception $e) {
            Response::json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function delete($id) {
        try {
            $deleted = $this->invoiceModel->delete($id);
            if (!$deleted) {
                Response::json(['error' => 'Invoice not found'], 404);
                return;
            }
            Response::json(['success' => true, 'message' => 'Invoice deleted']);
        } catch (Exception $e) {
            Response::json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function getStatistics() {
        try {
            $stats = $this->invoiceModel->getStatistics();
            Response::json(['success' => true, 'statistics' => $stats]);
        } catch (Exception $e) {
            Response::json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function generateNumber() {
        try {
            $number = $this->invoiceModel->generateInvoiceNumber();
            Response::json(['success' => true, 'data' => ['invoice_number' => $number]]);
        } catch (Exception $e) {
            Response::json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function getConvertibleQuotations() {
        try {
            $quotations = $this->quotationModel->getConvertible();
            Response::json(['success' => true, 'data' => $quotations]);
        } catch (Exception $e) {
            Response::json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function convertQuotation() {
        $input = json_decode(file_get_contents('php://input'), true);
        if (!isset($input['quotation_id'])) {
            Response::json(['error' => 'quotation_id required'], 400);
            return;
        }

        try {
            $invoiceId = $this->invoiceModel->convertFromQuotation($input['quotation_id']);
            Response::json(['success' => true, 'message' => 'Invoice created from quotation', 'invoice_id' => $invoiceId]);
        } catch (Exception $e) {
            Response::json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }
}