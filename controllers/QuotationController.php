<?php
// controllers/QuotationController.php

require_once __DIR__ . '/../models/QuotationModel.php';
require_once __DIR__ . '/../utils/Response.php';
require_once __DIR__ . '/../utils/Database.php';

class QuotationController {
    private $model;

    public function __construct() {
        $db = Database::getConnection();
        $this->model = new QuotationModel($db);
    }

    public function getAll() {
        $filters = [];
        if (isset($_GET['status'])) $filters['status'] = $_GET['status'];
        if (isset($_GET['from_date'])) $filters['from_date'] = $_GET['from_date'];
        if (isset($_GET['to_date'])) $filters['to_date'] = $_GET['to_date'];
        if (isset($_GET['search'])) $filters['search'] = $_GET['search'];

        try {
            $quotations = $this->model->getAll($filters);
            $stats = $this->model->getStatistics();
            Response::json(['success' => true, 'data' => $quotations, 'statistics' => $stats]);
        } catch (Exception $e) {
            Response::json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function getOne($id) {
        try {
            $quotation = $this->model->getById($id);
            if (!$quotation) {
                Response::json(['error' => 'Quotation not found'], 404);
                return;
            }
            Response::json(['success' => true, 'data' => $quotation]);
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

        // Basic validation
        if (empty($input['customer_id']) || empty($input['quotation_date']) || empty($input['valid_until'])) {
            Response::json(['error' => 'Missing required fields'], 400);
            return;
        }

        // Generate number if not provided
        if (empty($input['quotation_number'])) {
            $input['quotation_number'] = $this->model->generateQuotationNumber();
        }

        try {
            $id = $this->model->create($input);
            Response::json(['success' => true, 'message' => 'Quotation created', 'data' => ['id' => $id]]);
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
            $updated = $this->model->update($id, $input);
            if (!$updated) {
                Response::json(['error' => 'Quotation not found or no changes'], 404);
                return;
            }
            Response::json(['success' => true, 'message' => 'Quotation updated']);
        } catch (Exception $e) {
            Response::json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function updateStatus($id) {
        $input = json_decode(file_get_contents('php://input'), true);
        if (!isset($input['status'])) {
            Response::json(['error' => 'Status required'], 400);
            return;
        }

        try {
            $updated = $this->model->updateStatus($id, $input['status']);
            if (!$updated) {
                Response::json(['error' => 'Quotation not found'], 404);
                return;
            }
            Response::json(['success' => true, 'message' => 'Status updated']);
        } catch (Exception $e) {
            Response::json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function delete($id) {
        try {
            $deleted = $this->model->delete($id);
            if (!$deleted) {
                Response::json(['error' => 'Quotation not found'], 404);
                return;
            }
            Response::json(['success' => true, 'message' => 'Quotation deleted']);
        } catch (Exception $e) {
            Response::json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function getStatistics() {
        try {
            $stats = $this->model->getStatistics();
            Response::json(['success' => true, 'statistics' => $stats]);
        } catch (Exception $e) {
            Response::json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function generateNumber() {
        try {
            $number = $this->model->generateQuotationNumber();
            Response::json(['success' => true, 'data' => ['quotation_number' => $number]]);
        } catch (Exception $e) {
            Response::json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    public function getConvertible() {
        try {
            $quotations = $this->model->getConvertible();
            Response::json(['success' => true, 'data' => $quotations]);
        } catch (Exception $e) {
            Response::json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }
}