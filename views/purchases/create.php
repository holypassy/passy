<?php
session_start();
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: ../index.php');
    exit();
}

try {
    $conn = new PDO("mysql:host=localhost;dbname=savant_motors_pos", "root", "");
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Check suppliers table columns
    $supplierColumns = $conn->query("SHOW COLUMNS FROM suppliers")->fetchAll(PDO::FETCH_COLUMN);
    $activeCondition = in_array('is_active', $supplierColumns) ? "is_active = 1" : (in_array('status', $supplierColumns) ? "status = 'active'" : "1=1");
    
    // Get active suppliers
    $suppliers = $conn->query("SELECT id, supplier_name, payment_terms, telephone, email FROM suppliers WHERE $activeCondition ORDER BY supplier_name")->fetchAll(PDO::FETCH_ASSOC);
    
    // Check inventory columns
    $invColumns = $conn->query("SHOW COLUMNS FROM inventory")->fetchAll(PDO::FETCH_COLUMN);
    
    // Determine the correct column names for inventory
    $costColumn = 'unit_cost';
    if (!in_array('unit_cost', $invColumns)) {
        if (in_array('cost_price', $invColumns)) $costColumn = 'cost_price';
        else if (in_array('purchase_price', $invColumns)) $costColumn = 'purchase_price';
        else if (in_array('buying_price', $invColumns)) $costColumn = 'buying_price';
        else if (in_array('unit_price', $invColumns)) $costColumn = 'unit_price';
    }
    
    $stockColumn = 'quantity';
    if (!in_array('quantity', $invColumns)) {
        if (in_array('current_stock', $invColumns)) $stockColumn = 'current_stock';
        else if (in_array('stock', $invColumns)) $stockColumn = 'stock';
        else if (in_array('stock_quantity', $invColumns)) $stockColumn = 'stock_quantity';
    }
    
    // Get active products with correct column names
    $products = $conn->query("
        SELECT id, item_code, product_name, $costColumn as unit_cost, $stockColumn as quantity, unit_of_measure 
        FROM inventory 
        WHERE is_active = 1 
        ORDER BY product_name
    ")->fetchAll(PDO::FETCH_ASSOC);
    
    // Generate PO number
    $po_number = 'PO-' . date('Ymd') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
    
} catch(PDOException $e) {
    $error = $e->getMessage();
    $suppliers = [];
    $products = [];
    $po_number = 'PO-' . date('Ymd') . '-' . rand(1000, 9999);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Purchase Order | SAVANT MOTORS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #1e40af;
            --primary-dark: #1e3a8a;
        }
        body {
            background: #f0f2f5;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        .form-container {
            background: white;
            border-radius: 20px;
            padding: 30px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.05);
        }
        .section-title {
            font-size: 18px;
            font-weight: 600;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid var(--primary);
        }
        .item-row {
            background: #f8f9fa;
            padding: 15px;
            margin-bottom: 10px;
            border-radius: 10px;
        }
        .total-card {
            background: #f8f9fa;
            border-radius: 15px;
            padding: 20px;
        }
    </style>
</head>
<body>
    <div class="container mt-4 mb-5">
        <div class="form-container">
            <h2 class="mb-4">
                <i class="fas fa-plus-circle text-primary me-2"></i> 
                Create Purchase Order
            </h2>
            
            <?php if (isset($error)): ?>
            <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            
            <form id="purchaseForm" action="../../controllers/PurchaseController.php?action=store" method="POST">
                <!-- PO Information -->
                <div class="row mb-4">
                    <div class="col-md-6">
                        <label class="form-label fw-bold">PO Number</label>
                        <input type="text" name="po_number" class="form-control" value="<?php echo $po_number; ?>" readonly>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-bold">Purchase Date</label>
                        <input type="date" name="purchase_date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
                    </div>
                </div>
                
                <!-- Supplier Information -->
                <div class="section-title">
                    <i class="fas fa-truck me-2"></i> Supplier Information
                </div>
                <div class="row mb-4">
                    <div class="col-md-6">
                        <label class="form-label fw-bold">Select Supplier *</label>
                        <select name="supplier_id" id="supplierSelect" class="form-select" required>
                            <option value="">-- Select Supplier --</option>
                            <?php foreach ($suppliers as $supplier): ?>
                            <option value="<?php echo $supplier['id']; ?>" 
                                    data-terms="<?php echo htmlspecialchars($supplier['payment_terms'] ?? ''); ?>"
                                    data-phone="<?php echo htmlspecialchars($supplier['telephone'] ?? ''); ?>"
                                    data-email="<?php echo htmlspecialchars($supplier['email'] ?? ''); ?>">
                                <?php echo htmlspecialchars($supplier['supplier_name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-bold">Payment Terms</label>
                        <input type="text" name="payment_terms" id="paymentTerms" class="form-control" readonly>
                    </div>
                    <div class="col-md-6 mt-3">
                        <label class="form-label fw-bold">Expected Delivery</label>
                        <input type="date" name="expected_delivery" class="form-control" value="<?php echo date('Y-m-d', strtotime('+7 days')); ?>">
                    </div>
                    <div class="col-md-6 mt-3">
                        <label class="form-label fw-bold">Supplier Invoice #</label>
                        <input type="text" name="supplier_invoice" class="form-control" placeholder="Optional">
                    </div>
                </div>
                
                <!-- Purchase Items -->
                <div class="section-title">
                    <i class="fas fa-list me-2"></i> Purchase Items
                </div>
                
                <div class="mb-3">
                    <label class="form-label fw-bold">Add Product</label>
                    <select id="productSelect" class="form-select">
                        <option value="">-- Select Product --</option>
                        <?php foreach ($products as $product): ?>
                        <option value="<?php echo $product['id']; ?>"
                                data-name="<?php echo htmlspecialchars($product['product_name']); ?>"
                                data-code="<?php echo htmlspecialchars($product['item_code']); ?>"
                                data-price="<?php echo $product['unit_cost']; ?>"
                                data-stock="<?php echo $product['quantity']; ?>"
                                data-unit="<?php echo htmlspecialchars($product['unit_of_measure'] ?? 'piece'); ?>">
                            <?php echo htmlspecialchars($product['item_code']); ?> - <?php echo htmlspecialchars($product['product_name']); ?> 
                            (UGX <?php echo number_format($product['unit_cost']); ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div id="itemsContainer"></div>
                
                <!-- Totals -->
                <div class="row mt-4">
                    <div class="col-md-6 offset-md-6">
                        <div class="total-card">
                            <div class="d-flex justify-content-between mb-2">
                                <span>Subtotal:</span>
                                <strong id="subtotal">UGX 0</strong>
                            </div>
                            <div class="d-flex justify-content-between mb-2">
                                <span>Discount:</span>
                                <strong id="discountTotal" class="text-danger">UGX 0</strong>
                            </div>
                            <div class="d-flex justify-content-between mb-2">
                                <span>Tax (18%):</span>
                                <strong id="taxTotal">UGX 0</strong>
                            </div>
                            <div class="d-flex justify-content-between mb-2">
                                <span>Shipping:</span>
                                <input type="number" id="shipping" name="shipping_cost" value="0" 
                                       class="form-control form-control-sm" style="width: 120px;" step="1000">
                            </div>
                            <hr>
                            <div class="d-flex justify-content-between">
                                <span class="fw-bold">Grand Total:</span>
                                <strong class="text-primary fs-5" id="grandTotal">UGX 0</strong>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Notes -->
                <div class="mb-3 mt-4">
                    <label class="form-label fw-bold">Notes</label>
                    <textarea name="notes" class="form-control" rows="3" placeholder="Additional notes..."></textarea>
                </div>
                
                <input type="hidden" name="items" id="itemsJson">
                <input type="hidden" name="subtotal" id="hiddenSubtotal">
                <input type="hidden" name="discount_total" id="hiddenDiscount">
                <input type="hidden" name="tax_total" id="hiddenTax">
                <input type="hidden" name="total_amount" id="hiddenTotal">
                
                <div class="d-flex justify-content-end gap-3 mt-4">
                    <a href="index.php" class="btn btn-secondary">Cancel</a>
                    <button type="button" class="btn btn-info" onclick="previewOrder()">Preview</button>
                    <button type="submit" class="btn btn-primary">Create Purchase Order</button>
                </div>
            </form>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        let items = [];
        let itemCounter = 0;
        
        $(document).ready(function() {
            $('#supplierSelect').change(function() {
                const selected = $(this).find(':selected');
                $('#paymentTerms').val(selected.data('terms') || '');
            });
            
            $('#productSelect').change(function() {
                const selected = $(this).find(':selected');
                if (!selected.val()) return;
                
                if (items.find(item => item.product_id == selected.val())) {
                    alert('Product already added!');
                    $(this).val('');
                    return;
                }
                
                const product = {
                    id: itemCounter++,
                    product_id: selected.val(),
                    code: selected.data('code'),
                    name: selected.data('name'),
                    price: parseFloat(selected.data('price')) || 0,
                    unit: selected.data('unit'),
                    quantity: 1,
                    discount: 0
                };
                
                items.push(product);
                renderItems();
                $(this).val('');
            });
            
            $('#shipping').on('change', calculateTotals);
        });
        
        function renderItems() {
            const container = $('#itemsContainer');
            
            if (items.length === 0) {
                container.html(`
                    <div class="text-center py-5 text-muted">
                        <i class="fas fa-box-open fa-3x mb-3 d-block"></i>
                        <p>No items added yet. Select a product from the dropdown above.</p>
                    </div>
                `);
                calculateTotals();
                return;
            }
            
            let html = '';
            items.forEach((item, idx) => {
                const total = (item.price * item.quantity) - item.discount;
                html += `
                    <div class="item-row">
                        <div class="row">
                            <div class="col-md-4">
                                <strong>${escapeHtml(item.code)}</strong><br>
                                <small>${escapeHtml(item.name)}</small>
                                <input type="hidden" name="items[${idx}][product_id]" value="${item.product_id}">
                                <input type="hidden" name="items[${idx}][code]" value="${escapeHtml(item.code)}">
                                <input type="hidden" name="items[${idx}][name]" value="${escapeHtml(item.name)}">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label small">Quantity</label>
                                <input type="number" class="form-control" value="${item.quantity}" 
                                       min="1" onchange="updateItem(${idx}, 'quantity', this.value)">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label small">Unit Price</label>
                                <input type="number" class="form-control" value="${item.price}" 
                                       step="100" onchange="updateItem(${idx}, 'price', this.value)">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label small">Discount</label>
                                <input type="number" class="form-control" value="${item.discount}" 
                                       step="500" onchange="updateItem(${idx}, 'discount', this.value)">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label small">Total</label>
                                <div class="fw-bold text-primary">UGX ${total.toLocaleString()}</div>
                            </div>
                            <div class="col-md-12 mt-2">
                                <button type="button" class="btn btn-sm btn-danger" onclick="removeItem(${idx})">
                                    <i class="fas fa-trash"></i> Remove
                                </button>
                            </div>
                        </div>
                    </div>
                `;
            });
            
            container.html(html);
            calculateTotals();
        }
        
        function updateItem(index, field, value) {
            if (field === 'quantity') items[index][field] = parseInt(value) || 1;
            else if (field === 'price') items[index][field] = parseFloat(value) || 0;
            else if (field === 'discount') items[index][field] = parseFloat(value) || 0;
            renderItems();
        }
        
        function removeItem(index) {
            if (confirm('Remove this item?')) {
                items.splice(index, 1);
                renderItems();
            }
        }
        
        function calculateTotals() {
            let subtotal = 0, totalDiscount = 0;
            items.forEach(item => {
                subtotal += item.price * item.quantity;
                totalDiscount += item.discount;
            });
            
            const tax = subtotal * 0.18;
            const shipping = parseFloat($('#shipping').val()) || 0;
            const grandTotal = subtotal - totalDiscount + tax + shipping;
            
            $('#subtotal').text('UGX ' + subtotal.toLocaleString());
            $('#discountTotal').text('UGX ' + totalDiscount.toLocaleString());
            $('#taxTotal').text('UGX ' + tax.toLocaleString());
            $('#grandTotal').text('UGX ' + grandTotal.toLocaleString());
            
            $('#hiddenSubtotal').val(subtotal);
            $('#hiddenDiscount').val(totalDiscount);
            $('#hiddenTax').val(tax);
            $('#hiddenTotal').val(grandTotal);
        }
        
        function previewOrder() {
            if (items.length === 0) {
                alert('Please add at least one item!');
                return;
            }
            
            const supplierName = $('#supplierSelect option:selected').text();
            const poNumber = $('input[name="po_number"]').val();
            const purchaseDate = $('input[name="purchase_date"]').val();
            
            let itemsHtml = '';
            items.forEach((item, i) => {
                const total = (item.price * item.quantity) - item.discount;
                itemsHtml += `
                    <tr>
                        <td>${i+1}</td>
                        <td>${escapeHtml(item.code)}</td>
                        <td>${escapeHtml(item.name)}</td>
                        <td>${item.quantity} ${item.unit}</td>
                        <td>UGX ${item.price.toLocaleString()}</td>
                        <td>UGX ${item.discount.toLocaleString()}</td>
                        <td>UGX ${total.toLocaleString()}</td>
                    </tr>
                `;
            });
            
            const subtotal = $('#hiddenSubtotal').val();
            const discount = $('#hiddenDiscount').val();
            const tax = $('#hiddenTax').val();
            const shipping = $('#shipping').val();
            const grandTotal = $('#hiddenTotal').val();
            
            const win = window.open('', '_blank');
            win.document.write(`
                <html>
                <head>
                    <title>Purchase Order Preview - ${poNumber}</title>
                    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
                    <style>
                        body { padding: 40px; font-family: Arial, sans-serif; }
                        .header { text-align: center; margin-bottom: 40px; }
                        .po-header { background: #f8f9fa; padding: 20px; border-radius: 10px; margin-bottom: 30px; }
                        table { width: 100%; margin: 20px 0; }
                        th { background: #1e3a6f; color: white; padding: 10px; }
                        td { padding: 10px; border-bottom: 1px solid #dee2e6; }
                        .totals { text-align: right; margin-top: 30px; }
                    </style>
                </head>
                <body>
                    <div class="container">
                        <div class="header">
                            <h1>SAVANT MOTORS UGANDA</h1>
                            <h3>PURCHASE ORDER</h3>
                        </div>
                        
                        <div class="po-header">
                            <div class="row">
                                <div class="col-md-6">
                                    <p><strong>PO Number:</strong> ${poNumber}</p>
                                    <p><strong>Date:</strong> ${purchaseDate}</p>
                                </div>
                                <div class="col-md-6">
                                    <p><strong>Supplier:</strong> ${supplierName}</p>
                                    <p><strong>Payment Terms:</strong> ${$('#paymentTerms').val() || 'Standard'}</p>
                                </div>
                            </div>
                        </div>
                        
                        <table class="table">
                            <thead>
                                <tr><th>#</th><th>Code</th><th>Product</th><th>Qty</th><th>Unit Price</th><th>Discount</th><th>Total</th></tr>
                            </thead>
                            <tbody>${itemsHtml}</tbody>
                          </table>
                        
                        <div class="totals">
                            <p>Subtotal: UGX ${parseFloat(subtotal).toLocaleString()}</p>
                            <p>Discount: UGX ${parseFloat(discount).toLocaleString()}</p>
                            <p>Tax (18%): UGX ${parseFloat(tax).toLocaleString()}</p>
                            <p>Shipping: UGX ${parseFloat(shipping).toLocaleString()}</p>
                            <h4>Grand Total: UGX ${parseFloat(grandTotal).toLocaleString()}</h4>
                        </div>
                        
                        <div class="text-center mt-5 text-muted">
                            <p>Generated on: ${new Date().toLocaleString()}</p>
                        </div>
                    </div>
                </body>
                </html>
            `);
            win.document.close();
        }
        
        function escapeHtml(str) {
            if (!str) return '';
            return String(str).replace(/[&<>]/g, function(m) {
                if (m === '&') return '&amp;';
                if (m === '<') return '&lt;';
                if (m === '>') return '&gt;';
                return m;
            });
        }
        
        $('form').on('submit', function(e) {
            if (items.length === 0) {
                e.preventDefault();
                alert('Please add at least one item!');
                return false;
            }
            if (!$('#supplierSelect').val()) {
                e.preventDefault();
                alert('Please select a supplier!');
                return false;
            }
            $('#itemsJson').val(JSON.stringify(items));
            return true;
        });
    </script>
</body>
</html>