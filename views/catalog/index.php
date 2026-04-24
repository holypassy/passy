<?php
// This is the main unified catalog view
session_start();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Unified Catalog - Savant Motors ERP</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        :root {
            --primary: #1e40af;
            --primary-light: #3b82f6;
            --primary-dark: #1e3a8a;
            --primary-gradient: linear-gradient(135deg, #3b82f6, #1e40af);
            --success: #10b981;
            --success-light: #34d399;
            --danger: #ef4444;
            --warning: #f59e0b;
            --info: #3b82f6;
            --dark: #0f172a;
            --gray-dark: #334155;
            --gray: #64748b;
            --gray-light: #94a3b8;
            --light: #f8fafc;
            --border: #e2e8f0;
            --shadow-sm: 0 1px 2px 0 rgb(0 0 0 / 0.05);
            --shadow: 0 1px 3px 0 rgb(0 0 0 / 0.1);
            --shadow-md: 0 4px 6px -1px rgb(0 0 0 / 0.1);
            --shadow-lg: 0 10px 15px -3px rgb(0 0 0 / 0.1);
            --shadow-xl: 0 20px 25px -5px rgb(0 0 0 / 0.1);
            --radius: 0.5rem;
            --radius-lg: 1rem;
            --radius-xl: 1.5rem;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #f0f9ff 0%, #e6f3ff 100%);
            color: var(--dark);
            line-height: 1.5;
        }

        /* Sidebar */
        .sidebar {
            position: fixed;
            left: 0;
            top: 0;
            width: 280px;
            height: 100%;
            background: linear-gradient(180deg, #0f172a 0%, #1e293b 100%);
            color: white;
            z-index: 1000;
            overflow-y: auto;
            transition: transform 0.3s ease;
            box-shadow: var(--shadow-xl);
        }

        .sidebar-header {
            padding: 25px 24px;
            text-align: center;
            border-bottom: 1px solid rgba(59, 130, 246, 0.2);
        }

        .logo-icon {
            width: 60px;
            height: 60px;
            background: var(--primary-gradient);
            border-radius: var(--radius-lg);
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 12px;
        }

        .logo-icon i {
            font-size: 28px;
            color: white;
        }

        .logo-text {
            font-size: 20px;
            font-weight: 800;
        }

        .sidebar-menu {
            padding: 20px 0;
        }

        .menu-item {
            padding: 12px 24px;
            display: flex;
            align-items: center;
            gap: 12px;
            color: rgba(255, 255, 255, 0.7);
            text-decoration: none;
            transition: all 0.3s ease;
            border-left: 3px solid transparent;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
        }

        .menu-item:hover,
        .menu-item.active {
            background: rgba(59, 130, 246, 0.2);
            color: white;
            border-left-color: var(--primary-light);
        }

        /* Main Content */
        .main-content {
            margin-left: 280px;
            padding: 25px 30px;
            min-height: 100vh;
        }

        /* Page Header */
        .page-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            flex-wrap: wrap;
            gap: 20px;
        }

        .page-title h1 {
            font-size: 28px;
            font-weight: 800;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        /* Buttons */
        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 12px;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-primary {
            background: var(--primary-gradient);
            color: white;
        }

        .btn-success {
            background: linear-gradient(135deg, var(--success-light), var(--success));
            color: white;
        }

        .btn-secondary {
            background: white;
            color: var(--gray);
            border: 1px solid var(--border);
        }

        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .stat-card {
            background: white;
            border-radius: var(--radius-xl);
            padding: 20px;
            display: flex;
            align-items: center;
            gap: 20px;
            transition: all 0.3s ease;
            cursor: pointer;
        }

        .stat-card:hover {
            transform: translateY(-4px);
            box-shadow: var(--shadow-lg);
        }

        .stat-icon {
            width: 60px;
            height: 60px;
            border-radius: var(--radius-lg);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 28px;
        }

        /* Table */
        .table-container {
            overflow-x: auto;
            background: white;
            border-radius: var(--radius-xl);
            box-shadow: var(--shadow-md);
        }

        .data-table {
            width: 100%;
            border-collapse: collapse;
        }

        .data-table thead {
            background: var(--primary-gradient);
            color: white;
        }

        .data-table th,
        .data-table td {
            padding: 16px 20px;
            text-align: left;
        }

        /* Badges */
        .badge {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }

        .badge-service {
            background: linear-gradient(135deg, #dbeafe, #bfdbfe);
            color: #1e40af;
        }

        .badge-product {
            background: linear-gradient(135deg, #dcfce7, #bbf7d0);
            color: #065f46;
        }

        /* Modal */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            backdrop-filter: blur(4px);
            z-index: 2000;
            align-items: center;
            justify-content: center;
        }

        .modal.active {
            display: flex;
        }

        .modal-content {
            background: white;
            border-radius: var(--radius-xl);
            width: 90%;
            max-width: 700px;
            max-height: 85vh;
            overflow-y: auto;
        }

        .modal-header {
            background: var(--primary-gradient);
            color: white;
            padding: 20px 25px;
            border-radius: var(--radius-xl) var(--radius-xl) 0 0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        /* Forms */
        .form-group {
            margin-bottom: 20px;
        }

        .form-control {
            width: 100%;
            padding: 12px 14px;
            border: 2px solid var(--border);
            border-radius: 8px;
            font-size: 14px;
            font-family: 'Inter', sans-serif;
        }

        .form-row {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 20px;
            margin-bottom: 20px;
        }

        @media (max-width: 768px) {
            .sidebar {
                transform: translateX(-100%);
            }
            
            .main-content {
                margin-left: 0;
                padding: 20px;
            }
            
            .form-row {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="sidebar">
        <div class="sidebar-header">
            <div class="logo-icon">
                <i class="fas fa-cube"></i>
            </div>
            <div class="logo-text">SAVANT MOTORS</div>
            <div class="logo-subtitle" style="font-size: 11px; color: var(--gray-light); margin-top: 4px;">ERP System</div>
        </div>
        <div class="sidebar-menu">
            <a href="../dashboard_erp.php" class="menu-item">
                <i class="fas fa-chart-line"></i> Dashboard
            </a>
            <a href="/catalog" class="menu-item active">
                <i class="fas fa-cubes"></i> Unified Catalog
            </a>
            <a href="/services" class="menu-item">
                <i class="fas fa-cogs"></i> Services
            </a>
            <a href="/products" class="menu-item">
                <i class="fas fa-cube"></i> Products
            </a>
        </div>
    </div>

    <div class="main-content">
        <div class="page-header">
            <div class="page-title">
                <h1>
                    <i class="fas fa-cubes"></i> 
                    Unified Catalog
                </h1>
                <p style="color: var(--gray); margin-top: 8px;">Manage all your services and products from a single dashboard</p>
            </div>
            <div>
                <button onclick="exportToCSV()" class="btn btn-primary">
                    <i class="fas fa-download"></i> Export
                </button>
                <button onclick="openServiceModal()" class="btn btn-success">
                    <i class="fas fa-plus"></i> New Service
                </button>
                <button onclick="openProductModal()" class="btn btn-primary">
                    <i class="fas fa-plus"></i> New Product
                </button>
            </div>
        </div>

        <div id="alertContainer"></div>

        <!-- Stats Grid -->
        <div class="stats-grid" id="statsGrid">
            <!-- Stats will be loaded dynamically -->
        </div>

        <!-- Unified Table -->
        <div class="table-container">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Type</th>
                        <th>Name / Code</th>
                        <th>Category</th>
                        <th>Price (UGX)</th>
                        <th>Status</th>
                        <th>Details</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody id="catalogTableBody">
                    <tr>
                        <td colspan="7" style="text-align: center; padding: 40px;">
                            <div class="loading-spinner"></div>
                            <p>Loading catalog data...</p>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <script>
        let catalogData = { services: [], products: [] };

        document.addEventListener('DOMContentLoaded', function() {
            loadData();
        });

        async function loadData() {
            try {
                const [servicesRes, productsRes] = await Promise.all([
                    fetch('/api/services'),
                    fetch('/api/products')
                ]);
                
                catalogData.services = await servicesRes.json();
                catalogData.products = await productsRes.json();
                
                renderTable();
                updateStats();
            } catch (error) {
                console.error('Error loading data:', error);
                showAlert('error', 'Failed to load catalog data');
            }
        }

        function renderTable() {
            const tbody = document.getElementById('catalogTableBody');
            let allItems = [
                ...catalogData.services.map(s => ({ ...s, type: 'service' })),
                ...catalogData.products.map(p => ({ ...p, type: 'product' }))
            ];
            
            if (allItems.length === 0) {
                tbody.innerHTML = `<tr><td colspan="7" style="text-align: center; padding: 60px;">
                    <i class="fas fa-inbox" style="font-size: 48px; color: var(--gray-light);"></i>
                    <p>No items found</p></td></tr>`;
                return;
            }
            
            tbody.innerHTML = allItems.map(item => {
                if (item.type === 'service') {
                    return `
                        <tr>
                            <td><span class="badge badge-service"><i class="fas fa-cogs"></i> Service</span></td>
                            <td><strong>${escapeHtml(item.service_name)}</strong><br><small>ID: #${item.id}</small></td>
                            <td><span class="badge">${escapeHtml(item.category)}</span></td>
                            <td><strong>UGX ${formatNumber(item.standard_price)}</strong></td>
                            <td><span class="badge">${item.track_interval ? 'Tracked' : 'Standard'}</span></td>
                            <td><small>${item.estimated_duration || 'N/A'}</small></td>
                            <td>
                                <button onclick="viewItem(${item.id}, 'service')" class="btn btn-secondary" style="padding: 4px 8px;">View</button>
                                <button onclick="deleteItem(${item.id}, 'service')" class="btn btn-secondary" style="padding: 4px 8px; background: var(--danger);">Delete</button>
                            </td>
                        </tr>
                    `;
                } else {
                    return `
                        <tr>
                            <td><span class="badge badge-product"><i class="fas fa-cube"></i> Product</span></td>
                            <td><strong>${escapeHtml(item.product_name)}</strong><br><small>Code: ${escapeHtml(item.item_code)}</small></td>
                            <td><span class="badge">${escapeHtml(item.category || 'General')}</span></td>
                            <td><strong>UGX ${formatNumber(item.selling_price)}</strong></td>
                            <td><span class="badge">${item.quantity <= item.reorder_level ? 'Low Stock' : 'In Stock'}</span></td>
                            <td><small>Stock: ${formatNumber(item.quantity)} ${item.unit_of_measure}</small></td>
                            <td>
                                <button onclick="viewItem(${item.id}, 'product')" class="btn btn-secondary" style="padding: 4px 8px;">View</button>
                                <button onclick="deleteItem(${item.id}, 'product')" class="btn btn-secondary" style="padding: 4px 8px; background: var(--danger);">Delete</button>
                            </td>
                        </tr>
                    `;
                }
            }).join('');
        }

        function updateStats() {
            const services = catalogData.services;
            const products = catalogData.products;
            const totalRevenue = services.reduce((sum, s) => sum + (s.standard_price || 0), 0);
            const inventoryValue = products.reduce((sum, p) => sum + ((p.unit_cost || 0) * (p.quantity || 0)), 0);
            
            document.getElementById('statsGrid').innerHTML = `
                <div class="stat-card">
                    <div class="stat-icon" style="background: linear-gradient(135deg, #3b82f6, #1e40af);">
                        <i class="fas fa-chart-line"></i>
                    </div>
                    <div>
                        <h3>Total Items</h3>
                        <div class="value" style="font-size: 28px; font-weight: 800;">${services.length + products.length}</div>
                        <small>${services.length} Services | ${products.length} Products</small>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon" style="background: linear-gradient(135deg, #10b981, #059669);">
                        <i class="fas fa-cogs"></i>
                    </div>
                    <div>
                        <h3>Services Revenue</h3>
                        <div class="value" style="font-size: 28px; font-weight: 800;">UGX ${formatNumber(totalRevenue)}</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon" style="background: linear-gradient(135deg, #f59e0b, #d97706);">
                        <i class="fas fa-boxes"></i>
                    </div>
                    <div>
                        <h3>Inventory Value</h3>
                        <div class="value" style="font-size: 28px; font-weight: 800;">UGX ${formatNumber(inventoryValue)}</div>
                    </div>
                </div>
            `;
        }

        async function deleteItem(id, type) {
            if (!confirm(`Delete this ${type}?`)) return;
            
            const endpoint = type === 'service' ? `/api/services/${id}` : `/api/products/${id}`;
            
            try {
                const response = await fetch(endpoint, { method: 'DELETE' });
                if (response.ok) {
                    showAlert('success', `${type} deleted successfully`);
                    loadData();
                } else {
                    showAlert('error', `Failed to delete ${type}`);
                }
            } catch (error) {
                showAlert('error', 'Network error');
            }
        }

        function viewItem(id, type) {
            const item = type === 'service' 
                ? catalogData.services.find(s => s.id == id)
                : catalogData.products.find(p => p.id == id);
            
            if (item) {
                const details = type === 'service' 
                    ? `Service: ${item.service_name}\nPrice: UGX ${formatNumber(item.standard_price)}`
                    : `Product: ${item.product_name}\nStock: ${item.quantity} units\nPrice: UGX ${formatNumber(item.selling_price)}`;
                alert(details);
            }
        }

        function openServiceModal() {
            alert('Service modal - Implement full form modal as needed');
        }

        function openProductModal() {
            alert('Product modal - Implement full form modal as needed');
        }

        function exportToCSV() {
            let csv = "Type,Name,Category,Price,Status\n";
            catalogData.services.forEach(s => {
                csv += `Service,${s.service_name},${s.category},${s.standard_price},Active\n`;
            });
            catalogData.products.forEach(p => {
                csv += `Product,${p.product_name},${p.category || 'General'},${p.selling_price},${p.quantity > 0 ? 'In Stock' : 'Out of Stock'}\n`;
            });
            
            const blob = new Blob([csv], { type: 'text/csv' });
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = `catalog_${new Date().toISOString().split('T')[0]}.csv`;
            a.click();
            URL.revokeObjectURL(url);
            showAlert('success', 'Catalog exported successfully');
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

        function showAlert(type, message) {
            const container = document.getElementById('alertContainer');
            const alert = document.createElement('div');
            alert.style.cssText = `padding: 16px; margin-bottom: 20px; border-radius: 8px; background: ${type === 'success' ? '#dcfce7' : '#fee2e2'}; color: ${type === 'success' ? '#065f46' : '#991b1b'};`;
            alert.innerHTML = `${message} <button onclick="this.parentElement.remove()" style="float: right; background: none; border: none; cursor: pointer;">&times;</button>`;
            container.appendChild(alert);
            setTimeout(() => alert.remove(), 5000);
        }
    </script>
</body>
</html>