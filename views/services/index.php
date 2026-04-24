<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Services Management - Savant Motors</title>
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
            width: 500px;
            margin: 50px auto;
            border-radius: 12px;
            padding: 20px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1><i class="fas fa-cogs"></i> Services Management</h1>
            <p>Manage all automotive services offered by Savant Motors</p>
            <button class="btn btn-primary" onclick="openCreateModal()">
                <i class="fas fa-plus"></i> Add New Service
            </button>
        </div>
        
        <table id="servicesTable">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Service Name</th>
                    <th>Category</th>
                    <th>Price (UGX)</th>
                    <th>Duration</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody id="servicesTableBody">
                <tr><td colspan="6" style="text-align: center;">Loading...</td></tr>
            </tbody>
        </table>
    </div>
    
    <div id="serviceModal" class="modal">
        <div class="modal-content">
            <h2 id="modalTitle">Add New Service</h2>
            <form id="serviceForm">
                <input type="hidden" id="serviceId">
                <div style="margin-bottom: 15px;">
                    <label>Service Name:</label>
                    <input type="text" id="serviceName" class="form-control" required style="width: 100%; padding: 8px; margin-top: 5px;">
                </div>
                <div style="margin-bottom: 15px;">
                    <label>Category:</label>
                    <select id="category" class="form-control" required style="width: 100%; padding: 8px; margin-top: 5px;">
                        <option value="Minor">Minor Service</option>
                        <option value="Major">Major Service</option>
                    </select>
                </div>
                <div style="margin-bottom: 15px;">
                    <label>Price (UGX):</label>
                    <input type="number" id="price" class="form-control" required style="width: 100%; padding: 8px; margin-top: 5px;">
                </div>
                <div style="margin-bottom: 15px;">
                    <label>Duration:</label>
                    <input type="text" id="duration" class="form-control" placeholder="e.g., 2 hours" style="width: 100%; padding: 8px; margin-top: 5px;">
                </div>
                <div style="margin-bottom: 15px;">
                    <label>Description:</label>
                    <textarea id="description" rows="3" class="form-control" style="width: 100%; padding: 8px; margin-top: 5px;"></textarea>
                </div>
                <div>
                    <button type="submit" class="btn btn-primary">Save</button>
                    <button type="button" class="btn" onclick="closeModal()">Cancel</button>
                </div>
            </form>
        </div>
    </div>
    
    <script>
        let services = [];
        
        document.addEventListener('DOMContentLoaded', loadServices);
        
        async function loadServices() {
            try {
                const response = await fetch('/api/services');
                services = await response.json();
                renderTable();
            } catch (error) {
                console.error('Error loading services:', error);
            }
        }
        
        function renderTable() {
            const tbody = document.getElementById('servicesTableBody');
            if (services.length === 0) {
                tbody.innerHTML = '<tr><td colspan="6" style="text-align: center;">No services found</td></tr>';
                return;
            }
            
            tbody.innerHTML = services.map(service => `
                <tr>
                    <td>${service.id}</td>
                    <td><strong>${escapeHtml(service.service_name)}</strong></td>
                    <td><span class="badge">${service.category}</span></td>
                    <td>UGX ${formatNumber(service.standard_price)}</td>
                    <td>${service.estimated_duration || 'N/A'}</td>
                    <td>
                        <button class="btn" onclick="editService(${service.id})" style="background: #f59e0b; color: white; padding: 5px 10px;">Edit</button>
                        <button class="btn" onclick="deleteService(${service.id})" style="background: #ef4444; color: white; padding: 5px 10px;">Delete</button>
                    </td>
                </tr>
            `).join('');
        }
        
        function openCreateModal() {
            document.getElementById('modalTitle').innerText = 'Add New Service';
            document.getElementById('serviceForm').reset();
            document.getElementById('serviceId').value = '';
            document.getElementById('serviceModal').style.display = 'block';
        }
        
        async function editService(id) {
            const service = services.find(s => s.id == id);
            if (service) {
                document.getElementById('modalTitle').innerText = 'Edit Service';
                document.getElementById('serviceId').value = service.id;
                document.getElementById('serviceName').value = service.service_name;
                document.getElementById('category').value = service.category;
                document.getElementById('price').value = service.standard_price;
                document.getElementById('duration').value = service.estimated_duration || '';
                document.getElementById('description').value = service.description || '';
                document.getElementById('serviceModal').style.display = 'block';
            }
        }
        
        document.getElementById('serviceForm').addEventListener('submit', async (e) => {
            e.preventDefault();
            
            const id = document.getElementById('serviceId').value;
            const data = {
                service_name: document.getElementById('serviceName').value,
                category: document.getElementById('category').value,
                standard_price: parseFloat(document.getElementById('price').value),
                estimated_duration: document.getElementById('duration').value,
                description: document.getElementById('description').value
            };
            
            const url = id ? `/api/services/${id}` : '/api/services';
            const method = id ? 'PUT' : 'POST';
            
            try {
                const response = await fetch(url, {
                    method: method,
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(data)
                });
                
                if (response.ok) {
                    alert(id ? 'Service updated successfully' : 'Service created successfully');
                    closeModal();
                    loadServices();
                } else {
                    alert('Failed to save service');
                }
            } catch (error) {
                console.error('Error saving service:', error);
                alert('Network error');
            }
        });
        
        async function deleteService(id) {
            if (confirm('Are you sure you want to delete this service?')) {
                try {
                    const response = await fetch(`/api/services/${id}`, { method: 'DELETE' });
                    if (response.ok) {
                        alert('Service deleted successfully');
                        loadServices();
                    } else {
                        alert('Failed to delete service');
                    }
                } catch (error) {
                    console.error('Error deleting service:', error);
                    alert('Network error');
                }
            }
        }
        
        function closeModal() {
            document.getElementById('serviceModal').style.display = 'none';
        }
        
        function formatNumber(num) {
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
            if (event.target === document.getElementById('serviceModal')) {
                closeModal();
            }
        }
    </script>
</body>
</html>