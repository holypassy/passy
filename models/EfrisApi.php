<?php
require_once __DIR__ . '/../config/database.php';

class EfrisApi {
    private $settings;
    private $conn;
    
    public function __construct() {
        $settingModel = new Setting();
        $this->settings = $settingModel->getGrouped()['efris'];
        $database = Database::getInstance();
        $this->conn = $database->getConnection();
    }
    
    public function testConnection() {
        if (!$this->settings['efris_enabled']) {
            return ['success' => false, 'message' => 'EFRIS is disabled'];
        }
        
        $url = $this->settings['efris_url'] . 'test';
        $data = [
            'deviceNo' => $this->settings['efris_device_no'],
            'tin' => $this->settings['efris_tin']
        ];
        
        try {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'API-Key: ' . $this->settings['efris_api_key']
            ]);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($httpCode == 200) {
                return ['success' => true, 'message' => 'Connection successful', 'response' => $response];
            } else {
                return ['success' => false, 'message' => 'Connection failed: HTTP ' . $httpCode];
            }
        } catch (Exception $e) {
            return ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
        }
    }
    
    public function sendInvoice($invoiceData) {
        if (!$this->settings['efris_enabled']) {
            return ['success' => false, 'message' => 'EFRIS is disabled'];
        }
        
        $url = $this->settings['efris_url'] . 'invoice';
        
        $data = [
            'deviceNo' => $this->settings['efris_device_no'],
            'tin' => $this->settings['efris_tin'],
            'invoiceNo' => $invoiceData['invoice_number'],
            'invoiceDate' => $invoiceData['invoice_date'],
            'customerTin' => $invoiceData['customer_tin'] ?? '',
            'customerName' => $invoiceData['customer_name'],
            'totalAmount' => $invoiceData['total_amount'],
            'taxAmount' => $invoiceData['tax_amount'] ?? 0,
            'items' => $invoiceData['items']
        ];
        
        try {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'API-Key: ' . $this->settings['efris_api_key'],
                'Client-ID: ' . $this->settings['efris_client_id']
            ]);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($httpCode == 200 || $httpCode == 201) {
                return ['success' => true, 'message' => 'Invoice sent to EFRIS', 'response' => json_decode($response, true)];
            } else {
                return ['success' => false, 'message' => 'Failed to send invoice', 'http_code' => $httpCode];
            }
        } catch (Exception $e) {
            return ['success' => false, 'message' => 'Error: ' . $e->getMessage()];
        }
    }
}
?>