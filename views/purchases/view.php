<?php
session_start();
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: ../index.php');
    exit();
}

$id = $_GET['id'] ?? 0;

try {
    $conn = new PDO("mysql:host=localhost;dbname=savant_motors_pos", "root", "");
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Get purchase details
    $stmt = $conn->prepare("
        SELECT p.*, s.supplier_name, s.telephone, s.email, s.address,
               u1.full_name as created_by_name,
               u2.full_name as received_by_name
        FROM purchases p
        LEFT JOIN suppliers s ON p.supplier_id = s.id
        LEFT JOIN users u1 ON p.created_by = u1.id
        LEFT JOIN users u2 ON p.received_by = u2.id
        WHERE p.id = ?
    ");
    $stmt->execute([$id]);
    $purchase = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$purchase) {
        header('Location: index.php');
        exit();
    }
    
    // Get purchase items
    $stmt = $conn->prepare("
        SELECT pi.*, i.item_code, i.product_name, i.unit_of_measure
        FROM purchase_items pi
        LEFT JOIN inventory i ON pi.product_id = i.id
        WHERE pi.purchase_id = ?
    ");
    $stmt->execute([$id]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch(PDOException $e) {
    $error = $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Purchase Order #<?php echo htmlspecialchars($purchase['po_number']); ?> | SAVANT MOTORS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * {
            font-family: 'Calibri', 'Segoe UI', 'Tahoma', sans-serif;
        }
        
        :root {
            --primary: #1e40af;
            --success: #10b981;
            --danger: #ef4444;
            --warning: #f59e0b;
        }
        
        body {
            background: #f0f2f5;
            font-family: 'Calibri', 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        .invoice-container {
            background: white;
            border-radius: 20px;
            padding: 40px;
            margin: 20px auto;
            max-width: 1200px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.1);
        }
        
        .status-badge {
            padding: 8px 20px;
            border-radius: 30px;
            font-weight: 600;
            font-family: 'Calibri', 'Segoe UI', sans-serif;
        }
        
        .status-ordered { background: #fff3e0; color: #f59e0b; }
        .status-received { background: #dcfce7; color: #10b981; }
        .status-cancelled { background: #fee2e2; color: #ef4444; }
        
        .btn-print {
            background: #4a5568;
            color: white;
        }
        
        .btn-print:hover {
            background: #2d3748;
            color: white;
        }
        
        h1, h2, h3, h4, h5, h6 {
            font-family: 'Calibri', 'Segoe UI', sans-serif;
            font-weight: 700;
        }
        
        .card-body p, .card-body strong, .card-body span {
            font-family: 'Calibri', 'Segoe UI', sans-serif;
        }
        
        table th, table td {
            font-family: 'Calibri', 'Segoe UI', sans-serif;
        }
        
        table th {
            font-weight: 600;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="invoice-container">
            <!-- Header -->
            <div class="text-center mb-4">
                <h1 class="fw-bold" style="color: var(--primary);">SAVANT MOTORS UGANDA</h1>
                <p class="text-muted" style="font-family: 'Calibri', sans-serif;">Kampala, Uganda | Tel: +256 774 537 017 | Email: info@savantmotors.com</p>
                <h3 class="mt-3">PURCHASE ORDER</h3>
            </div>
            
            <!-- Actions -->
            <div class="d-flex justify-content-between mb-4">
                <a href="index.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left me-2"></i> Back
                </a>
                <div>
                    <button onclick="window.print()" class="btn btn-print me-2">
                        <i class="fas fa-print me-2"></i> Print
                    </button>
                    <?php if ($purchase['status'] == 'ordered'): ?>
                    <a href="receive.php?id=<?php echo $purchase['id']; ?>" class="btn btn-success">
                        <i class="fas fa-check-circle me-2"></i> Mark as Received
                    </a>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- PO Info -->
            <div class="row mb-4">
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-body">
                            <h6 class="fw-bold">PO Information</h6>
                            <hr>
                            <p><strong>PO Number:</strong> <?php echo htmlspecialchars($purchase['po_number']); ?></p>
                            <p><strong>Date:</strong> <?php echo date('d M Y', strtotime($purchase['purchase_date'])); ?></p>
                            <p><strong>Status:</strong> 
                                <span class="status-badge status-<?php echo $purchase['status']; ?>">
                                    <?php echo strtoupper($purchase['status']); ?>
                                </span>
                            </p>
                            <?php if ($purchase['received_date']): ?>
                            <p><strong>Received Date:</strong> <?php echo date('d M Y', strtotime($purchase['received_date'])); ?></p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-body">
                            <h6 class="fw-bold">Supplier Information</h6>
                            <hr>
                            <p><strong><?php echo htmlspecialchars($purchase['supplier_name']); ?></strong></p>
                            <p><i class="fas fa-phone me-2"></i> <?php echo htmlspecialchars($purchase['telephone']); ?></p>
                            <p><i class="fas fa-envelope me-2"></i> <?php echo htmlspecialchars($purchase['email']); ?></p>
                            <p><i class="fas fa-map-marker-alt me-2"></i> <?php echo nl2br(htmlspecialchars($purchase['address'])); ?></p>
                            <p><strong>Payment Terms:</strong> <?php echo htmlspecialchars($purchase['payment_terms'] ?? 'Standard'); ?></p>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Items Table -->
            <div class="card mb-4">
                <div class="card-body">
                    <h6 class="fw-bold mb-3">Purchase Items</h6>
                    <div class="table-responsive">
                        <table class="table table-bordered">
                            <thead class="table-light">
                                <tr>
                                    <th>#</th>
                                    <th>Item Code</th>
                                    <th>Product Name</th>
                                    <th class="text-center">Quantity</th>
                                    <th class="text-end">Unit Price</th>
                                    <th class="text-end">Discount</th>
                                    <th class="text-end">Total</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php $counter = 1; ?>
                                <?php foreach ($items as $item): ?>
                                <tr>
                                    <td><?php echo $counter++; ?></td>
                                    <td><?php echo htmlspecialchars($item['item_code']); ?></td>
                                    <td><?php echo htmlspecialchars($item['product_name']); ?></td>
                                    <td class="text-center"><?php echo $item['quantity']; ?> <?php echo htmlspecialchars($item['unit_of_measure']); ?></td>
                                    <td class="text-end">UGX <?php echo number_format($item['unit_price']); ?></td>
                                    <td class="text-end">UGX <?php echo number_format($item['discount']); ?></td>
                                    <td class="text-end fw-bold">UGX <?php echo number_format($item['total']); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot>
                                <tr class="table-light">
                                    <td colspan="6" class="text-end fw-bold">Subtotal:</td>
                                    <td class="text-end">UGX <?php echo number_format($purchase['subtotal']); ?></td>
                                </tr>
                                <tr class="table-light">
                                    <td colspan="6" class="text-end fw-bold">Discount:</td>
                                    <td class="text-end text-danger">UGX <?php echo number_format($purchase['discount_total']); ?></td>
                                </tr>
                                <tr class="table-light">
                                    <td colspan="6" class="text-end fw-bold">Tax (18%):</td>
                                    <td class="text-end">UGX <?php echo number_format($purchase['tax_total']); ?></td>
                                </tr>
                                <tr class="table-light">
                                    <td colspan="6" class="text-end fw-bold">Shipping:</td>
                                    <td class="text-end">UGX <?php echo number_format($purchase['shipping_cost']); ?></td>
                                </tr>
                                <tr class="table-primary">
                                    <td colspan="6" class="text-end fw-bold fs-5">Grand Total:</td>
                                    <td class="text-end fw-bold fs-5">UGX <?php echo number_format($purchase['total_amount']); ?></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>
            
            <!-- Notes -->
            <?php if ($purchase['notes']): ?>
            <div class="card">
                <div class="card-body">
                    <h6 class="fw-bold">Notes</h6>
                    <hr>
                    <p class="text-muted"><?php echo nl2br(htmlspecialchars($purchase['notes'])); ?></p>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- Footer -->
            <div class="text-center mt-4 pt-3 border-top">
                <p class="text-muted small" style="font-family: 'Calibri', sans-serif;">
                    This is a computer-generated document. No signature is required.<br>
                    Generated on: <?php echo date('Y-m-d H:i:s'); ?>
                </p>
            </div>
        </div>
    </div>
</body>
</html>