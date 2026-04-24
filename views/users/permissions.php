<?php
// views/users/permissions.php - Manage User Permissions
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Permissions | SAVANT MOTORS</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,300;14..32,400;14..32,500;14..32,600;14..32,700;14..32,800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Helvetica Neue', Helvetica, 'Inter', sans-serif;
            background: #f5f7fb;
        }

        :root {
            --primary: #2563eb;
            --success: #10b981;
            --danger: #ef4444;
            --border: #e2e8f0;
            --gray: #64748b;
            --dark: #0f172a;
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 2rem;
        }

        .page-header {
            margin-bottom: 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
        }

        .page-header h1 {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--dark);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .permissions-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 1.5rem;
        }

        .permission-card {
            background: white;
            border-radius: 1rem;
            border: 1px solid var(--border);
            overflow: hidden;
        }

        .card-header {
            background: #f8fafc;
            padding: 1rem 1.25rem;
            border-bottom: 1px solid var(--border);
            font-weight: 700;
            color: var(--dark);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .card-body {
            padding: 1rem 1.25rem;
        }

        .permission-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.5rem 0;
            border-bottom: 1px solid var(--border);
        }

        .permission-item:last-child {
            border-bottom: none;
        }

        .permission-name {
            font-weight: 500;
        }

        .permission-key {
            font-size: 0.7rem;
            color: var(--gray);
            font-family: monospace;
        }

        .btn {
            padding: 0.5rem 1rem;
            border-radius: 0.5rem;
            font-weight: 600;
            cursor: pointer;
            border: none;
        }

        .btn-primary {
            background: var(--primary);
            color: white;
        }

        .btn-danger {
            background: var(--danger);
            color: white;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="page-header">
            <h1><i class="fas fa-key" style="color: var(--primary);"></i> System Permissions</h1>
            <button class="btn btn-primary" onclick="openCreateModal()">
                <i class="fas fa-plus"></i> Create Permission
            </button>
        </div>

        <div class="permissions-grid" id="permissionsGrid">
            <!-- Permissions will be loaded here -->
        </div>
    </div>

    <script>
        function loadPermissions() {
            fetch('/api/v1/permissions.php')
                .then(response => response.json())
                .then(data => {
                    const grid = document.getElementById('permissionsGrid');
                    let html = '';
                    
                    for (const [category, perms] of Object.entries(data)) {
                        html += `
                            <div class="permission-card">
                                <div class="card-header">
                                    <span><i class="fas fa-folder"></i> ${escapeHtml(category)}</span>
                                    <span class="permission-key">${perms.length} permissions</span>
                                </div>
                                <div class="card-body">
                                    ${perms.map(perm => `
                                        <div class="permission-item">
                                            <div>
                                                <div class="permission-name">${escapeHtml(perm.permission_name)}</div>
                                                <div class="permission-key">${escapeHtml(perm.permission_key)}</div>
                                            </div>
                                            <button class="btn btn-danger btn-sm" onclick="deletePermission(${perm.id}, '${escapeHtml(perm.permission_name)}')">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </div>
                                    `).join('')}
                                </div>
                            </div>
                        `;
                    }
                    
                    grid.innerHTML = html;
                });
        }

        function deletePermission(id, name) {
            if (confirm(`Are you sure you want to delete permission "${name}"?`)) {
                fetch(`/api/v1/permissions.php?id=${id}`, { method: 'DELETE' })
                    .then(response => response.json())
                    .then(data => {
                        if (data.message) {
                            alert(data.message);
                            loadPermissions();
                        }
                    });
            }
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

        loadPermissions();
    </script>
</body>
</html>