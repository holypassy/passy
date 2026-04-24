<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Products Management - Savant Motors</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Inter', sans-serif;
            background: #f5f7fa;
            padding: 20px;
        }
        
        .container {
            max-width: 1400px;
            margin: 0 auto;
        }
        
        .header {
            background: white;
            padding: 20px;
            border-radius: 12px;
            margin-bottom: 20px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        
        .stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 20px;
        }
        
        .stat-card {
            background: white;
            padding: 20px;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        
        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s;
        }
        
        .btn-primary {
            background: #3b82f6;
            color: white;
        }
        
        .btn-warning {
            background: #f59e0b;
            color: white;
        }
        
        .btn-danger {
            background: #ef4444;
            color: white;
        }
        
        table {
            width: 100%;
            background: white;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        
        th {
            background: #1e40af;
            color: white;
            padding: 15px;
            text-align: left;
        }
        
        td {
            padding: 12px 15px;
            border-bottom: 1px solid #e2e8f0;
        }
        
        .low-stock {
            background: #fee2e2;
            color: #991b1b;
            font-weight: bold;
        }
        
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 1000;
        }
        
        .modal-content {
            background: white;
            width: 600px;
            margin: 50px auto;
            border-radius: 12px;
            padding: 20px;
            max-height: 80vh;
            overflow-y: auto;
        }
        
        .form-group {
            margin-bottom: 15px;
        }
        
        .form-control {
            width: 100%;
            padding: 8px;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            margin-top: 5px;
        }
        
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
        }
        
        .badge {
            padding: 4px 8px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 600;
        }
        
        .badge-success {
            background: #dcfce7;
            color: #166534;
        }
        
        .badge-warning {
            background: #fed7aa;
            color: #92400e;
        }
        
        .badge-danger {
            background: #fee2e2;
            color: #991b1b;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1><i class="fas fa-cube"></i> Products Management</h1>
            <p>Manage inventory and product catalog</p>
            <button class="btn btn-primary" onclick="openCreateModal()">
                <i class="fas fa-plus"></i> Add New Product
            </button>
            <button class="btn btn-warning" onclick="exportToCSV()">
                <i class="fas fa-download"></i> Export
            </button>
        </div>
        
        <div class="stats" id="statsContainer">
            <!-- Stats will be loaded here -->
        </div>
        
        <div style="margin-bottom: 20px;">
            <input type="text" id="searchInput" placeholder="Search products..." 
                   style="width: 100%; padding: 10px; border: 1px solid #cbd5e1; border-radius: 8px;" 
                   onkeyup="filterProducts()">
        </div>
        
        <table>
            <thead>
                <tr>
                    <th>Code</th>
                    <th>Product Name</th>
                    <th>Category</th>
                    <th>Cost (UGX)</th>
                    <th>Selling (UGX)</th>
                    <th>Stock</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody id="productsTableBody">
                <tr><td colspan="8" style="text-align: center;">Loading...</td></tr>
            </tbody>
        </table>
    </div>
    
    <div id="productModal" class="modal">
        <div class="modal-content">
            <h2 id="modalTitle">Add New Product</h2>
            <form id="productForm">
                <input type="hidden" id="productId">
                <div class="form-row">
                    <div class="form-group">
                        <label>Product Code *</label>
                        <input type="text" id="itemCode" class="form-control" required>
                    </div>
                    <div class="form-group">
                        <label>Product Name *</label>
                        <input type="text" id="productName" class="form-control" required>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Category</label>
                        <input type="text" id="category" class="form-control" placeholder="General">
                    </div>
                    <div class="form-group">
                        <label>Unit of Measure</label>
                        <select id="unitOfMeasure" class="form-control">
                            <option value="piece">Piece</option>
                            <option value="liter">Liter</option>
                            <option value="kilogram">Kilogram</option>
                            <option value="box">Box</option>
                            <option value="set">Set</option>
                        </select>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Unit Cost (UGX)</label>
                        <input type="number" id="unitCost" class="form-control" value="0" step="100">
                    </div>
                    <div class="form-group">
                        <label>Selling Price (UGX) *</label>
                        <input type="number" id="sellingPrice" class="form-control" required step="100">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label>Opening Stock</label>
                        <input type="number" id="openingStock" class="form-control" value="0" min="0">
                    </div>
                    <div class="form-group">
                        <label>Reorder Level</label>
                        <input type="number" id="reorderLevel" class="form-control" value="5" min="0">
                    </div>
                </div>
                <div class="form-group">
                    <label>Description</label>
                    <textarea id="description" rows="3" class="form-control"></textarea>
                </div>
                <div style="margin-top: 20px;">
                    <button type="submit" class="btn btn-primary">Save Product</button>
                    <button type="button" class="btn" onclick="closeModal()">Cancel</button>
                </div>
            </form>
        </div>
    </div>
    
    <script>
        let products = [];
        let allProducts = [];
        
        document.addEventListener('DOMContentLoaded', () => {
            loadProducts();
            generateProductCode();
        });
        
        async function loadProducts() {
            try {
                const response = await fetch('/api/products');
                products = await response.json();
                allProducts = [...products];
                renderTable();
                loadStats();
            } catch (error) {
                console.error('Error loading products:', error);
            }
        }
        
        async function loadStats() {
            try {
                const response = await fetch('/api/products/stats');
                const stats = await response.json();
                
                document.getElementById('statsContainer').innerHTML = `
                    <div class="stat-card">
                        <h3>Total Products</h3>
                        <div style="font-size: 32px; font-weight: bold;">${stats.inventory.total_products || 0}</div>
                    </div>
                    <div class="stat-card">
                        <h3>Total Stock Value</h3>
                        <div style="font-size: 32px; font-weight: bold;">UGX ${formatNumber(stats.inventory.total_cost_value || 0)}</div>
                    </div>
                    <div class="stat-card">
                        <h3>Low Stock Items</h3>
                        <div style="font-size: 32px; font-weight: bold; color: #ef4444;">${stats.low_stock_count || 0}</div>
                    </div>
                `;
            } catch (error) {
                console.error('Error loading stats:', error);
            }
        }
        
        function renderTable() {
            const tbody = document.getElementById('productsTableBody');
            if (products.length === 0) {
                tbody.innerHTML = '<tr><td colspan="8" style="text-align: center;">No products found</td></tr>';
                return;
            }
            
            tbody.innerHTML = products.map(product => {
                const isLowStock = product.quantity <= product.reorder_level;
                const statusClass = isLowStock ? 'badge-danger' : 'badge-success';
                const statusText = isLowStock ? 'Low Stock' : 'In Stock';
                const rowClass = isLowStock ? 'low-stock' : '';
                
                return `
                    <tr class="${rowClass}">
                        <td><code>${escapeHtml(product.item_code)}</code></td>
                        <td><strong>${escapeHtml(product.product_name)}</strong></td>
                        <td>${escapeHtml(product.category || 'General')}</td>
                        <td>UGX ${formatNumber(product.unit_cost)}</td>
                        <td>UGX ${formatNumber(product.selling_price)}</td>
                        <td>${formatNumber(product.quantity)} ${product.unit_of_measure}</td>
                        <td><span class="badge ${statusClass}">${statusText}</span></td>
                        <td>
                            <button class="btn" onclick="editProduct(${product.id})" style="background: #f59e0b; color: white; padding: 5px 10px;">Edit</button>
                            <button class="btn" onclick="deleteProduct(${product.id})" style="background: #ef4444; color: white; padding: 5px 10px;">Delete</button>
                            <button class="btn" onclick="updateStock(${product.id})" style="background: #10b981; color: white; padding: 5px 10px;">Stock</button>
                        </td>
                    </tr>
                `;
            }).join('');
        }
        
        function filterProducts() {
            const searchTerm = document.getElementById('searchInput').value.toLowerCase();
            if (!searchTerm) {
                products = [...allProducts];
            } else {
                products = allProducts.filter(p => 
                    p.product_name.toLowerCase().includes(searchTerm) || 
                    p.item_code.toLowerCase().includes(searchTerm)
                );
            }
            renderTable();
        }
        
        function generateProductCode() {
            const code = 'PRD-' + new Date().getFullYear() + 
                        '-' + String(Math.floor(Math.random() * 1000)).padStart(3, '0');
            document.getElementById('itemCode').value = code;
        }
        
        function openCreateModal() {
            document.getElementById('modalTitle').innerText = 'Add New Product';
            document.getElementById('productForm').reset();
            document.getElementById('productId').value = '';
            generateProductCode();
            document.getElementById('productModal').style.display = 'block';
        }
        
        async function editProduct(id) {
            const product = products.find(p => p.id == id);
            if (product) {
                document.getElementById('modalTitle').innerText = 'Edit Product';
                document.getElementById('productId').value = product.id;
                document.getElementById('itemCode').value = product.item_code;
                document.getElementById('productName').value = product.product_name;
                document.getElementById('category').value = product.category || '';
                document.getElementById('unitOfMeasure').value = product.unit_of_measure || 'piece';
                document.getElementById('unitCost').value = product.unit_cost || 0;
                document.getElementById('sellingPrice').value = product.selling_price;
                document.getElementById('openingStock').value = product.quantity;
                document.getElementById('reorderLevel').value = product.reorder_level;
                document.getElementById('description').value = product.description || '';
                document.getElementById('productModal').style.display = 'block';
            }
        }
        
        document.getElementById('productForm').addEventListener('submit', async (e) => {
            e.preventDefault();
            
            const id = document.getElementById('productId').value;
            const data = {
                item_code: document.getElementById('itemCode').value,
                product_name: document.getElementById('productName').value,
                category: document.getElementById('category').value,
                unit_of_measure: document.getElementById('unitOfMeasure').value,
                unit_cost: parseFloat(document.getElementById('unitCost').value) || 0,
                selling_price: parseFloat(document.getElementById('sellingPrice').value),
                opening_stock: parseInt(document.getElementById('openingStock').value) || 0,
                reorder_level: parseInt(document.getElementById('reorderLevel').value) || 5,
                description: document.getElementById('description').value
            };
            
            const url = id ? `/api/products/${id}` : '/api/products';
            const method = id ? 'PUT' : 'POST';
            
            try {
                const response = await fetch(url, {
                    method: method,
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(data)
                });
                
                if (response.ok) {
                    alert(id ? 'Product updated successfully' : 'Product created successfully');
                    closeModal();
                    loadProducts();
                } else {
                    const error = await response.json();
                    alert('Error: ' + (error.message || 'Failed to save product'));
                }
            } catch (error) {
                console.error('Error saving product:', error);
                alert('Network error');
            }
        });
        
        async function deleteProduct(id) {
            if (confirm('Are you sure you want to delete this product?')) {
                try {
                    const response = await fetch(`/api/products/${id}`, { method: 'DELETE' });
                    if (response.ok) {
                        alert('Product deleted successfully');
                        loadProducts();
                    } else {
                        alert('Failed to delete product');
                    }
                } catch (error) {
                    console.error('Error deleting product:', error);
                    alert('Network error');
                }
            }
        }
        
        async function updateStock(id) {
            const quantity = prompt('Enter quantity to add (positive) or remove (negative):');
            if (quantity !== null && !isNaN(parseInt(quantity))) {
                try {
                    const response = await fetch(`/api/products/stock/${id}`, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ quantity: parseInt(quantity) })
                    });
                    
                    if (response.ok) {
                        alert('Stock updated successfully');
                        loadProducts();
                    } else {
                        alert('Failed to update stock');
                    }
                } catch (error) {
                    console.error('Error updating stock:', error);
                    alert('Network error');
                }
            }
        }
        
        function exportToCSV() {
            let csv = "Code,Name,Category,Cost,Selling Price,Stock,Status\n";
            products.forEach(p => {
                const status = p.quantity <= p.reorder_level ? 'Low Stock' : 'In Stock';
                csv += `"${p.item_code}","${p.product_name}","${p.category || 'General'}",${p.unit_cost},${p.selling_price},${p.quantity},${status}\n`;
            });
            
            const blob = new Blob([csv], { type: 'text/csv' });
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = `products_${new Date().toISOString().split('T')[0]}.csv`;
            a.click();
            URL.revokeObjectURL(url);
            alert('Products exported successfully');
        }
        
        function closeModal() {
            document.getElementById('productModal').style.display = 'none';
        }
        
        function formatNumber(num) {
            if (!num) return '0';
            return num.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ",");
        }
        
        function escapeHtml(str) {
            if (!str) return '';
            return str.replace(/[&<>]/g, function(m) {
                if (m === '&') return '&amp;';
                if (m === '<') return '&lt;';
                if (m === '>') return '&gt;';
                return m;
            });
        }
        
        window.onclick = function(event) {
            if (event.target === document.getElementById('productModal')) {
                closeModal();
            }
        }
    </script>
</body>
</html>