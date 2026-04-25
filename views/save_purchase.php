<?php
session_start();
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: index.php');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: purchases.php');
    exit();
}

try {
    $conn = new PDO("mysql:host=localhost;dbname=savant_motors_pos", "root", "");
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Start transaction
    $conn->beginTransaction();
    
    // Get form data
    $po_number = $_POST['po_number'];
    $supplier_id = (int)$_POST['supplier_id'];
    $purchase_date = $_POST['purchase_date'];
    $expected_delivery = !empty($_POST['expected_delivery']) ? $_POST['expected_delivery'] : null;
    $payment_terms = !empty($_POST['payment_terms']) ? $_POST['payment_terms'] : null;
    $supplier_invoice = !empty($_POST['supplier_invoice']) ? $_POST['supplier_invoice'] : null;
    $notes = !empty($_POST['notes']) ? $_POST['notes'] : null;
    $shipping_cost = (float)$_POST['shipping_cost'];
    $subtotal = (float)$_POST['subtotal'];
    $discount_total = (float)$_POST['discount_total'];
    $tax_total = (float)$_POST['tax_total'];
    $total_amount = (float)$_POST['total_amount'];
    
    // Insert purchase header
    $stmt = $conn->prepare("
        INSERT INTO purchases 
        (po_number, supplier_id, purchase_date, expected_delivery, payment_terms, 
         supplier_invoice, notes, shipping_cost, subtotal, discount_total, 
         tax_total, total_amount, status, created_at)
        VALUES 
        (:po_number, :supplier_id, :purchase_date, :expected_delivery, :payment_terms,
         :supplier_invoice, :notes, :shipping_cost, :subtotal, :discount_total,
         :tax_total, :total_amount, 'ordered', NOW())
    ");
    $stmt->execute([
        ':po_number' => $po_number,
        ':supplier_id' => $supplier_id,
        ':purchase_date' => $purchase_date,
        ':expected_delivery' => $expected_delivery,
        ':payment_terms' => $payment_terms,
        ':supplier_invoice' => $supplier_invoice,
        ':notes' => $notes,
        ':shipping_cost' => $shipping_cost,
        ':subtotal' => $subtotal,
        ':discount_total' => $discount_total,
        ':tax_total' => $tax_total,
        ':total_amount' => $total_amount
    ]);
    
    $purchase_id = $conn->lastInsertId();
    
    // Insert items
    if (isset($_POST['items']) && is_array($_POST['items'])) {
        $itemStmt = $conn->prepare("
            INSERT INTO purchase_items 
            (purchase_id, product_id, description, quantity, unit_price, discount, total)
            VALUES 
            (:purchase_id, :product_id, :description, :quantity, :unit_price, :discount, :total)
        ");
        
        foreach ($_POST['items'] as $item) {
            $product_id = !empty($item['product_id']) ? (int)$item['product_id'] : null;
            $description = !empty($item['description']) ? $item['description'] : null;
            $quantity = (float)$item['quantity'];
            $unit_price = (float)$item['unit_price'];
            $discount = (float)$item['discount'];
            $total = (float)$item['total'];
            
            $itemStmt->execute([
                ':purchase_id' => $purchase_id,
                ':product_id' => $product_id,
                ':description' => $description,
                ':quantity' => $quantity,
                ':unit_price' => $unit_price,
                ':discount' => $discount,
                ':total' => $total
            ]);
        }
    } else {
        // No items – rollback
        $conn->rollBack();
        throw new Exception("No items added to purchase order.");
    }
    
    $conn->commit();
    
    $_SESSION['success_message'] = "Purchase order $po_number created successfully!";
    header('Location: purchases.php');
    exit();
    
} catch (PDOException $e) {
    if (isset($conn)) $conn->rollBack();
    $_SESSION['error_message'] = "Database error: " . $e->getMessage();
    header('Location: purchases.php');
    exit();
} catch (Exception $e) {
    if (isset($conn)) $conn->rollBack();
    $_SESSION['error_message'] = $e->getMessage();
    header('Location: purchases.php');
    exit();
}
?>