<?php
session_start();

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: index.php');
    exit();
}

$type = $_GET['type'] ?? 'purchases';

try {
    $conn = new PDO("mysql:host=localhost;dbname=savant_motors_pos", "root", "");
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    header('Content-Type: application/vnd.ms-excel');
    
    switch($type) {
        case 'purchases':
            header('Content-Disposition: attachment; filename="purchases_' . date('Y-m-d') . '.xls"');
            $stmt = $conn->query("
                SELECT p.po_number, p.purchase_date, s.supplier_name, 
                       p.total_amount, p.status, p.created_at
                FROM purchases p
                LEFT JOIN suppliers s ON p.supplier_id = s.id
                ORDER BY p.created_at DESC
            ");
            $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo "PO Number\tDate\tSupplier\tTotal Amount\tStatus\tCreated At\n";
            foreach ($data as $row) {
                echo implode("\t", $row) . "\n";
            }
            break;
            
        case 'inventory':
            header('Content-Disposition: attachment; filename="inventory_' . date('Y-m-d') . '.xls"');
            $stmt = $conn->query("
                SELECT item_code, product_name, category, quantity, 
                       unit_cost, selling_price, reorder_level
                FROM inventory
                WHERE is_active = 1
                ORDER BY product_name
            ");
            $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo "Item Code\tProduct Name\tCategory\tQuantity\tUnit Cost\tSelling Price\tReorder Level\n";
            foreach ($data as $row) {
                echo implode("\t", $row) . "\n";
            }
            break;
            
        case 'suppliers':
            header('Content-Disposition: attachment; filename="suppliers_' . date('Y-m-d') . '.xls"');
            $stmt = $conn->query("
                SELECT supplier_name, telephone, email, address, 
                       payment_terms, contact_person, is_active
                FROM suppliers
                ORDER BY supplier_name
            ");
            $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo "Supplier Name\tTelephone\tEmail\tAddress\tPayment Terms\tContact Person\tStatus\n";
            foreach ($data as $row) {
                $row['is_active'] = $row['is_active'] ? 'Active' : 'Inactive';
                echo implode("\t", $row) . "\n";
            }
            break;
    }
} catch(PDOException $e) {
    die("Export failed: " . $e->getMessage());
}
?>