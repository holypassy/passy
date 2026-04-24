<?php
require_once __DIR__ . '/../models/Voucher.php';
require_once __DIR__ . '/../models/VoucherItem.php';
require_once __DIR__ . '/../models/VoucherPaymentDetail.php';
require_once __DIR__ . '/../middleware/AuthMiddleware.php';
require_once __DIR__ . '/../middleware/CorsMiddleware.php';
require_once __DIR__ . '/../helpers/Validator.php';
require_once __DIR__ . '/../helpers/Response.php';

class VoucherController {
    private $voucherModel;
    private $itemModel;
    private $paymentDetailModel;
    
    public function __construct() {
        CorsMiddleware::handle();
        $this->voucherModel = new Voucher();
        $this->itemModel = new VoucherItem();
        $this->paymentDetailModel = new VoucherPaymentDetail();
    }
    
    // ==================== VOUCHER ENDPOINTS ====================
    
    public function getAll() {
        AuthMiddleware::authenticate();
        
        $filters = [
            'voucher_type' => $_GET['type'] ?? null,
            'status' => $_GET['status'] ?? null,
            'from_date' => $_GET['from_date'] ?? null,
            'to_date' => $_GET['to_date'] ?? null,
            'search' => $_GET['search'] ?? null,
            'limit' => $_GET['limit'] ?? 100,
            'offset' => $_GET['offset'] ?? 0
        ];
        
        $vouchers = $this->voucherModel->getAll($filters);
        $stats = $this->voucherModel->getStatistics();
        
        Response::json([
            'success' => true,
            'data' => $vouchers,
            'statistics' => $stats,
            'filters' => $filters
        ]);
    }
    
    public function getOne($id) {
        AuthMiddleware::authenticate();
        
        $voucher = $this->voucherModel->findById($id);
        
        if (!$voucher) {
            Response::json(['success' => false, 'message' => 'Voucher not found'], 404);
        }
        
        $items = $this->itemModel->getByVoucherId($id);
        $paymentDetails = $this->paymentDetailModel->getByVoucherId($id);
        
        $voucher['items'] = $items;
        $voucher['payment_details'] = $paymentDetails;
        
        Response::json(['success' => true, 'data' => $voucher]);
    }
    
    public function create() {
        AuthMiddleware::authenticate();
        
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            Response::json(['success' => false, 'message' => 'Method not allowed'], 405);
        }
        
        $input = json_decode(file_get_contents('php://input'), true);
        
        $validation = Validator::validate($input, [
            'voucher_type' => 'required|in:RECEIPT,PAYMENT,SALES,PURCHASE,CONTRA,JOURNAL',
            'voucher_date' => 'required|date',
            'amount' => 'required|numeric|min:0.01'
        ]);
        
        if ($validation !== true) {
            Response::json(['success' => false, 'errors' => $validation], 400);
        }
        
        $session = AuthMiddleware::getCurrentUser();
        
        // Get voucher type ID
        $typeId = $this->getVoucherTypeId($input['voucher_type']);
        
        $voucherData = [
            'voucher_number' => $this->voucherModel->generateVoucherNumber($input['voucher_type']),
            'voucher_type_id' => $typeId,
            'voucher_date' => $input['voucher_date'],
            'amount' => $input['amount'],
            'received_from' => $input['received_from'] ?? null,
            'paid_to' => $input['paid_to'] ?? null,
            'payment_mode' => $input['payment_mode'] ?? 'cash',
            'reference_no' => $input['reference_no'] ?? null,
            'narration' => $input['narration'] ?? null,
            'status' => 'draft',
            'created_by' => $session['id']
        ];
        
        $this->voucherModel->conn->beginTransaction();
        
        try {
            // Create voucher
            $voucherId = $this->voucherModel->create($voucherData);
            
            if (!$voucherId) {
                throw new Exception("Failed to create voucher");
            }
            
            // Create items
            if (!empty($input['items'])) {
                $result = $this->itemModel->createMultiple($voucherId, $input['items']);
                
                if (!$result) {
                    throw new Exception("Failed to create voucher items");
                }
            }
            
            // Create payment details
            if (!empty($input['payment_details'])) {
                $paymentData = $input['payment_details'];
                $paymentData['voucher_id'] = $voucherId;
                $this->paymentDetailModel->create($paymentData);
            }
            
            $this->voucherModel->conn->commit();
            
            $voucher = $this->voucherModel->findById($voucherId);
            $items = $this->itemModel->getByVoucherId($voucherId);
            $voucher['items'] = $items;
            
            Response::json([
                'success' => true,
                'message' => 'Voucher created successfully',
                'data' => $voucher
            ]);
            
        } catch (Exception $e) {
            $this->voucherModel->conn->rollback();
            Response::json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }
    
    public function update($id) {
        AuthMiddleware::requireRole(['admin', 'manager']);
        
        if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
            Response::json(['success' => false, 'message' => 'Method not allowed'], 405);
        }
        
        $voucher = $this->voucherModel->findById($id);
        if (!$voucher) {
            Response::json(['success' => false, 'message' => 'Voucher not found'], 404);
        }
        
        if ($voucher['status'] !== 'draft') {
            Response::json(['success' => false, 'message' => 'Only draft vouchers can be edited'], 400);
        }
        
        $input = json_decode(file_get_contents('php://input'), true);
        
        $this->voucherModel->conn->beginTransaction();
        
        try {
            // Update voucher
            $result = $this->voucherModel->update($id, $input);
            
            if (!$result) {
                throw new Exception("Failed to update voucher");
            }
            
            // Update items (delete and recreate)
            if (isset($input['items'])) {
                $this->itemModel->deleteByVoucherId($id);
                $this->itemModel->createMultiple($id, $input['items']);
            }
            
            // Update payment details
            if (isset($input['payment_details'])) {
                $this->paymentDetailModel->update($id, $input['payment_details']);
            }
            
            $this->voucherModel->conn->commit();
            
            Response::json(['success' => true, 'message' => 'Voucher updated successfully']);
            
        } catch (Exception $e) {
            $this->voucherModel->conn->rollback();
            Response::json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }
    
    public function post($id) {
        AuthMiddleware::requireRole(['admin', 'manager']);
        
        $voucher = $this->voucherModel->findById($id);
        if (!$voucher) {
            Response::json(['success' => false, 'message' => 'Voucher not found'], 404);
        }
        
        if ($voucher['status'] !== 'draft') {
            Response::json(['success' => false, 'message' => 'Only draft vouchers can be posted'], 400);
        }
        
        $session = AuthMiddleware::getCurrentUser();
        $result = $this->voucherModel->updateStatus($id, 'posted', $session['id']);
        
        if ($result) {
            Response::json(['success' => true, 'message' => 'Voucher posted successfully']);
        } else {
            Response::json(['success' => false, 'message' => 'Failed to post voucher'], 500);
        }
    }
    
    public function cancel($id) {
        AuthMiddleware::requireRole(['admin', 'manager']);
        
        $voucher = $this->voucherModel->findById($id);
        if (!$voucher) {
            Response::json(['success' => false, 'message' => 'Voucher not found'], 404);
        }
        
        if ($voucher['status'] === 'cancelled') {
            Response::json(['success' => false, 'message' => 'Voucher is already cancelled'], 400);
        }
        
        $result = $this->voucherModel->updateStatus($id, 'cancelled');
        
        if ($result) {
            Response::json(['success' => true, 'message' => 'Voucher cancelled successfully']);
        } else {
            Response::json(['success' => false, 'message' => 'Failed to cancel voucher'], 500);
        }
    }
    
    public function delete($id) {
        AuthMiddleware::requireRole(['admin']);
        
        $voucher = $this->voucherModel->findById($id);
        if (!$voucher) {
            Response::json(['success' => false, 'message' => 'Voucher not found'], 404);
        }
        
        if ($voucher['status'] === 'posted') {
            Response::json(['success' => false, 'message' => 'Cannot delete a posted voucher. Cancel it first.'], 400);
        }
        
        $result = $this->voucherModel->delete($id);
        
        if ($result) {
            Response::json(['success' => true, 'message' => 'Voucher deleted successfully']);
        } else {
            Response::json(['success' => false, 'message' => 'Failed to delete voucher'], 500);
        }
    }
    
    public function getStatistics() {
        AuthMiddleware::authenticate();
        
        $stats = $this->voucherModel->getStatistics();
        $receiptStats = $this->voucherModel->getStatistics('RECEIPT');
        $paymentStats = $this->voucherModel->getStatistics('PAYMENT');
        
        Response::json([
            'success' => true,
            'data' => [
                'overall' => $stats,
                'receipts' => $receiptStats,
                'payments' => $paymentStats
            ]
        ]);
    }
    
    public function generateNumber() {
        AuthMiddleware::authenticate();
        
        $type = $_GET['type'] ?? 'RECEIPT';
        $number = $this->voucherModel->generateVoucherNumber($type);
        
        Response::json(['success' => true, 'data' => ['voucher_number' => $number]]);
    }
    
    private function getVoucherTypeId($typeCode) {
        $query = "SELECT id FROM voucher_types WHERE type_code = :type_code";
        $stmt = $this->voucherModel->conn->prepare($query);
        $stmt->execute([':type_code' => $typeCode]);
        return $stmt->fetchColumn();
    }
}
?>