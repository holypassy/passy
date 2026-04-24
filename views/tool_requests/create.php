<?php
// views/tool_requests/create.php - Create New Tool Request (with Quantity & New Tool Option)
session_start();

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: index.php');
    exit();
}

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Database connection for dynamic data
try {
    $conn = new PDO("mysql:host=localhost;dbname=savant_motors_pos", "root", "");
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Get available tools (only available ones)
    $availableTools = $conn->query("
        SELECT id, tool_code, tool_name, status 
        FROM tools 
        WHERE status = 'available' AND is_active = 1 
        ORDER BY tool_name
    ")->fetchAll(PDO::FETCH_ASSOC);
    
    // Get technicians
    $technicians = $conn->query("
        SELECT id, full_name, technician_code 
        FROM technicians 
        WHERE status = 'active' AND is_blocked = 0 
        ORDER BY full_name
    ")->fetchAll(PDO::FETCH_ASSOC);
    
} catch(PDOException $e) {
    $availableTools = [];
    $technicians = [];
    $error = "Database error: " . $e->getMessage();
}

// Display any error messages from session
$displayError = isset($_SESSION['error']) ? $_SESSION['error'] : (isset($error) ? $error : null);
if (isset($_SESSION['error'])) unset($_SESSION['error']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>New Tool Request | SAVANT MOTORS</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,300;14..32,400;14..32,500;14..32,600;14..32,700;14..32,800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Inter', sans-serif;
            background: #f0f2f5;
            min-height: 100vh;
        }
        :root {
            --primary: #1e40af;
            --primary-light: #3b82f6;
            --success: #10b981;
            --danger: #ef4444;
            --warning: #f59e0b;
            --border: #e2e8f0;
            --gray: #64748b;
            --dark: #0f172a;
        }

        /* Sidebar */
        .sidebar {
            position: fixed;
            left: 0;
            top: 0;
            width: 260px;
            height: 100%;
            background: linear-gradient(180deg, #e0f2fe 0%, #bae6fd 100%);
            color: #0c4a6e;
            z-index: 1000;
            overflow-y: auto;
        }
        .sidebar-header { padding: 1.5rem; border-bottom: 1px solid rgba(0,0,0,0.08); }
        .sidebar-header h2 { font-size: 1.2rem; font-weight: 700; color: #0369a1; }
        .sidebar-header p { font-size: 0.7rem; opacity: 0.7; margin-top: 0.25rem; color: #0284c7; }
        .sidebar-menu { padding: 1rem 0; }
        .sidebar-title { padding: 0.5rem 1.5rem; font-size: 0.7rem; text-transform: uppercase; letter-spacing: 1px; color: #0369a1; font-weight: 600; }
        .menu-item {
            padding: 0.7rem 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
            color: #0c4a6e;
            text-decoration: none;
            transition: all 0.2s;
            border-left: 3px solid transparent;
            font-size: 0.85rem;
            font-weight: 500;
        }
        .menu-item i { width: 20px; }
        .menu-item:hover, .menu-item.active { background: rgba(14, 165, 233, 0.2); color: #0284c7; border-left-color: #0284c7; }

        /* Main Content */
        .main-content { margin-left: 260px; padding: 1.5rem; min-height: 100vh; }

        /* Top Bar */
        .top-bar {
            background: white;
            border-radius: 1rem;
            padding: 1rem 1.5rem;
            margin-bottom: 1.5rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            border: 1px solid var(--border);
        }
        .page-title h1 { font-size: 1.3rem; font-weight: 700; color: var(--dark); display: flex; align-items: center; gap: 0.5rem; }
        .page-title p { font-size: 0.75rem; color: var(--gray); margin-top: 0.25rem; }

        /* Form Card */
        .form-card {
            background: white;
            border-radius: 1rem;
            border: 1px solid var(--border);
            overflow: hidden;
        }
        .form-header {
            background: linear-gradient(135deg, var(--primary-light), var(--primary));
            padding: 1rem 1.5rem;
            color: white;
        }
        .form-header h2 { font-size: 1.1rem; font-weight: 600; }
        .form-body { padding: 1.5rem; }

        .form-section {
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid var(--border);
        }
        .form-section:last-child { border-bottom: none; }
        .section-title {
            font-size: 0.9rem;
            font-weight: 700;
            color: var(--dark);
            margin-bottom: 1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .form-row {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 1rem;
            margin-bottom: 1rem;
        }
        .form-group { margin-bottom: 1rem; }
        .form-group label {
            display: block;
            font-size: 0.7rem;
            font-weight: 700;
            color: var(--gray);
            margin-bottom: 0.25rem;
            text-transform: uppercase;
        }
        .required { color: var(--danger); margin-left: 0.25rem; }
        input, select, textarea {
            width: 100%;
            padding: 0.6rem 0.75rem;
            border: 1.5px solid var(--border);
            border-radius: 0.5rem;
            font-size: 0.85rem;
            font-family: inherit;
        }
        input:focus, select:focus, textarea:focus { outline: none; border-color: var(--primary-light); }

        /* Tool Row */
        .tool-row {
            background: #f8fafc;
            padding: 1rem;
            border-radius: 0.5rem;
            margin-bottom: 1rem;
            position: relative;
            border: 1px solid var(--border);
        }
        .remove-tool {
            position: absolute;
            top: 0.5rem;
            right: 0.5rem;
            background: none;
            border: none;
            color: var(--danger);
            cursor: pointer;
            font-size: 1rem;
        }
        .add-tool-btn {
            background: var(--primary-light);
            color: white;
            border: none;
            padding: 0.5rem 1rem;
            border-radius: 0.5rem;
            cursor: pointer;
            font-size: 0.8rem;
            margin-top: 0.5rem;
        }
        .tool-type-group {
            display: flex;
            gap: 1rem;
            margin-bottom: 0.8rem;
        }
        .radio-option {
            display: flex;
            align-items: center;
            gap: 0.3rem;
            cursor: pointer;
            font-size: 0.8rem;
        }
        .tool-existing, .tool-new {
            margin-top: 0.5rem;
        }
        .tool-quantity {
            margin-top: 0.8rem;
        }
        .tool-quantity input {
            width: 100px;
        }

        .form-actions {
            display: flex;
            gap: 1rem;
            justify-content: flex-end;
            margin-top: 1.5rem;
            padding-top: 1rem;
            border-top: 1px solid var(--border);
        }
        .btn {
            padding: 0.6rem 1.2rem;
            border-radius: 0.5rem;
            font-weight: 600;
            font-size: 0.85rem;
            cursor: pointer;
            border: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }
        .btn-primary { background: linear-gradient(135deg, var(--primary-light), var(--primary)); color: white; }
        .btn-secondary { background: #e2e8f0; color: var(--dark); }

        .alert {
            padding: 0.75rem 1rem;
            border-radius: 0.5rem;
            margin-bottom: 1rem;
        }
        .alert-error { background: #fee2e2; color: #991b1b; border-left: 3px solid var(--danger); }
        .alert-success { background: #dcfce7; color: #166534; border-left: 3px solid var(--success); }
        .alert-warning { background: #fed7aa; color: #9a3412; border-left: 3px solid var(--warning); }

        @media (max-width: 768px) {
            .sidebar { left: -260px; }
            .main-content { margin-left: 0; padding: 1rem; }
            .form-row { grid-template-columns: 1fr; }
            .tool-type-group { flex-direction: column; }
        }
    </style>
</head>
<body>
    <div class="sidebar">
        <div class="sidebar-header">
            <h2>🔧 SAVANT MOTORS</h2>
            <p>Tool Request System</p>
        </div>
        <div class="sidebar-menu">
            <div class="sidebar-title">MAIN</div>
            <a href="dashboard_erp.php" class="menu-item">📊 Dashboard</a>
            <a href="../tools/index.php" class="menu-item">🔧 Tools</a>
            <a href="../technicians.php" class="menu-item">👨‍🔧 Technicians</a>
            <a href="../tool_requests/index.php" class="menu-item active">📝 Tool Requests</a>
            <div style="margin-top: 2rem;">
                <a href="logout.php" class="menu-item">🚪 Logout</a>
            </div>
        </div>
    </div>

    <div class="main-content">
        <div class="top-bar">
            <div class="page-title">
                <h1><i class="fas fa-plus-circle"></i> New Tool Request</h1>
                <p>Request tools for a job - specify quantity or request new tools</p>
            </div>
            <a href="index.php" class="btn btn-secondary">← Back to Requests</a>
        </div>

        <div class="form-card">
            <div class="form-header">
                <h2>📝 Request Details</h2>
            </div>
            <div class="form-body">
                <?php if (isset($displayError)): ?>
                <div class="alert alert-error">❌ <?php echo htmlspecialchars($displayError); ?></div>
                <?php endif; ?>
                
                <?php if (isset($_SESSION['success'])): ?>
                <div class="alert alert-success">✅ <?php echo $_SESSION['success']; unset($_SESSION['success']); ?></div>
                <?php endif; ?>

                <?php if (empty($technicians)): ?>
                <div class="alert alert-warning">⚠️ No active technicians found. Please add technicians first.</div>
                <?php endif; ?>

                <form method="POST" action="store.php" id="requestForm">
                    <!-- Technician Selection -->
                    <div class="form-section">
                        <div class="section-title"><i class="fas fa-user-cog"></i> Technician Information</div>
                        
                        <div class="form-group">
                            <label>Technician <span class="required">*</span></label>
                            <select name="technician_id" id="technicianSelect" required <?php echo empty($technicians) ? 'disabled' : ''; ?>>
                                <option value="">-- Select Technician --</option>
                                <?php foreach ($technicians as $tech): ?>
                                <option value="<?php echo $tech['id']; ?>">
                                    <?php echo htmlspecialchars($tech['full_name']); ?> (<?php echo $tech['technician_code']; ?>)
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label>Vehicle Number Plate <span class="required">*</span></label>
                            <input type="text" name="number_plate" required placeholder="e.g., UBA 123A">
                        </div>
                    </div>

                    <!-- Tools Selection -->
                    <div class="form-section">
                        <div class="section-title"><i class="fas fa-tools"></i> Tools Required</div>
                        
                        <div id="toolsContainer"></div>
                        <button type="button" class="add-tool-btn" onclick="addToolRow()">
                            <i class="fas fa-plus"></i> Add Another Tool
                        </button>
                        <p style="font-size: 0.7rem; color: var(--gray); margin-top: 0.5rem;">
                            <i class="fas fa-info-circle"></i> Select "Existing Tool" for tools in inventory, or "New Tool" for tools not yet in inventory
                        </p>
                    </div>

                    <!-- Request Details -->
                    <div class="form-section">
                        <div class="section-title"><i class="fas fa-info-circle"></i> Request Details</div>
                        
                        <div class="form-row">
                            <div class="form-group">
                                <label>Expected Duration (Days) <span class="required">*</span></label>
                                <input type="number" name="expected_duration_days" value="1" min="1" required>
                            </div>
                            <div class="form-group">
                                <label>Urgency</label>
                                <select name="urgency">
                                    <option value="low">🟢 Low</option>
                                    <option value="medium" selected>🟡 Medium</option>
                                    <option value="high">🟠 High</option>
                                    <option value="emergency">🔴 Emergency</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label><i class="fas fa-pen-fancy"></i> Reason <span class="required">*</span></label>
                            <select class="form-control" name="reason_select" id="reasonSelect" onchange="toggleCustomReason()" required>
                                <option value="">-- Select Reason --</option>
                                <option value="Engine service">🔧 Engine service</option>
                                <option value="Brake service">✅ Brake service</option>
                                <option value="Engine overhauling">⚙️ Engine overhauling</option>
                                <option value="Engine replacement">🆕 Engine replacement</option>
                                <option value="Gearbox service">⚙️ Gearbox service</option>
                                <option value="Gearbox overhaul">⚙️ Gearbox overhaul</option>
                                <option value="Gearbox replacement">🆕 Gearbox replacement</option>
                                <option value="Suspension service">🚗 Suspension service</option>
                                <option value="Suspension overhaul">🚗 Suspension overhaul</option>
                                <option value="Suspension replacement">🚗 Suspension replacement</option>
                                <option value="Electrical work">🔌 Electrical work</option>
                                <option value="Paint work">🎨 Paint work</option>
                                <option value="Diagnosis">🔍 Diagnosis</option>
                                <option value="lamp service">💡 lamp service</option>
                                <option value="wiring">🔌 wiring</option>
                                <option value="AC service">❄️ AC service</option>
                                <option value="Fittings">🔩 Fittings</option>
                                <option value="Other">📝 Other (please specify)</option>
                            </select>
                            <div id="customReasonDiv" style="display: none; margin-top: 0.8rem;">
                                <textarea class="form-control" name="reason_custom" id="reasonCustom" rows="2" placeholder="Please describe the reason..."></textarea>
                            </div>
                            <input type="hidden" name="reason" id="finalReason">
                        </div>
                        
                        <div class="form-group">
                            <label>Special Instructions</label>
                            <textarea name="instructions" rows="2" placeholder="Any special instructions..."></textarea>
                        </div>
                    </div>

                    <input type="hidden" name="tools" id="toolsJson">

                    <div class="form-actions">
                        <a href="tool_requests.php" class="btn btn-secondary">Cancel</a>
                        <button type="submit" class="btn btn-primary" onclick="return prepareSubmit()">
                            <i class="fas fa-paper-plane"></i> Submit Request
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        let toolRowCount = 0;
        const availableTools = <?php echo json_encode($availableTools); ?>;
        
        // Toggle custom reason textarea
        function toggleCustomReason() {
            const reasonSelect = document.getElementById('reasonSelect');
            const customReasonDiv = document.getElementById('customReasonDiv');
            const finalReason = document.getElementById('finalReason');
            const reasonCustom = document.getElementById('reasonCustom');
            
            if (reasonSelect.value === 'Other') {
                customReasonDiv.style.display = 'block';
                if (reasonCustom) reasonCustom.required = true;
                if (finalReason) finalReason.value = '';
            } else {
                customReasonDiv.style.display = 'none';
                if (reasonCustom) reasonCustom.required = false;
                if (finalReason && reasonSelect.value) {
                    finalReason.value = reasonSelect.value;
                }
            }
        }
        
        function addToolRow(selectedType = 'existing', selectedToolId = null, quantity = 1, newToolDesc = '') {
            const container = document.getElementById('toolsContainer');
            if (!container) return;
            
            const rowId = 'tool-row-' + (++toolRowCount);
            
            const row = document.createElement('div');
            row.className = 'tool-row';
            row.id = rowId;
            
            let toolsOptions = '<option value="">-- Select Tool --</option>';
            if (availableTools && availableTools.length > 0) {
                toolsOptions += availableTools.map(tool => `
                    <option value="${tool.id}" ${selectedToolId == tool.id ? 'selected' : ''}>
                        ${escapeHtml(tool.tool_code)} - ${escapeHtml(tool.tool_name)} (${tool.status})
                    </option>
                `).join('');
            } else {
                toolsOptions = '<option value="">-- No tools available --</option>';
            }
            
            row.innerHTML = `
                <button type="button" class="remove-tool" onclick="removeToolRow('${rowId}')">
                    <i class="fas fa-trash-alt"></i>
                </button>
                <div class="tool-type-group">
                    <label class="radio-option">
                        <input type="radio" name="tool_type_${toolRowCount}" value="existing" ${selectedType === 'existing' ? 'checked' : ''} onchange="toggleToolType(${toolRowCount})">
                        <span>📦 Existing Tool</span>
                    </label>
                    <label class="radio-option">
                        <input type="radio" name="tool_type_${toolRowCount}" value="new" ${selectedType === 'new' ? 'checked' : ''} onchange="toggleToolType(${toolRowCount})">
                        <span>🆕 New Tool (Not in Inventory)</span>
                    </label>
                </div>
                <div class="tool-existing" id="existing-div-${toolRowCount}">
                    <div class="form-group">
                        <label>Select Tool</label>
                        <select name="tool_id[]" class="form-control tool-select">
                            ${toolsOptions}
                        </select>
                    </div>
                </div>
                <div class="tool-new" id="new-div-${toolRowCount}" style="display: ${selectedType === 'new' ? 'block' : 'none'};">
                    <div class="form-group">
                        <label>Tool Name/Description</label>
                        <input type="text" name="new_tool_description[]" class="form-control new-tool-desc" placeholder="e.g., Special Diagnostic Scanner" value="${escapeHtml(newToolDesc)}">
                        <small style="color: var(--gray);">Describe the tool you need that's not in inventory</small>
                    </div>
                </div>
                <div class="tool-quantity">
                    <div class="form-group">
                        <label>Quantity <span class="required">*</span></label>
                        <input type="number" name="quantity[]" class="form-control tool-quantity-input" value="${quantity}" min="1" required>
                    </div>
                </div>
            `;
            container.appendChild(row);
        }
        
        function toggleToolType(rowIndex) {
            const radios = document.getElementsByName(`tool_type_${rowIndex}`);
            let selected = 'existing';
            for (let radio of radios) {
                if (radio.checked) {
                    selected = radio.value;
                    break;
                }
            }
            
            const existingDiv = document.getElementById(`existing-div-${rowIndex}`);
            const newDiv = document.getElementById(`new-div-${rowIndex}`);
            const existingSelect = existingDiv?.querySelector('select');
            const newInput = newDiv?.querySelector('input');
            
            if (selected === 'existing') {
                if (existingDiv) existingDiv.style.display = 'block';
                if (newDiv) newDiv.style.display = 'none';
                if (existingSelect) existingSelect.required = true;
                if (newInput) newInput.required = false;
            } else {
                if (existingDiv) existingDiv.style.display = 'none';
                if (newDiv) newDiv.style.display = 'block';
                if (existingSelect) existingSelect.required = false;
                if (newInput) newInput.required = true;
            }
        }
        
        function removeToolRow(rowId) {
            const row = document.getElementById(rowId);
            if (row) row.remove();
        }
        
        function prepareSubmit() {
            // Validate technician selection
            const technicianSelect = document.getElementById('technicianSelect');
            if (!technicianSelect.value) {
                alert('Please select a technician');
                return false;
            }
            
            // Set the final reason before submit
            const reasonSelect = document.getElementById('reasonSelect');
            const reasonCustom = document.getElementById('reasonCustom');
            const finalReason = document.getElementById('finalReason');
            
            if (!reasonSelect.value) {
                alert('Please select a reason');
                return false;
            }
            
            if (reasonSelect.value === 'Other') {
                if (!reasonCustom.value.trim()) {
                    alert('Please specify the reason');
                    return false;
                }
                finalReason.value = reasonCustom.value.trim();
            } else {
                finalReason.value = reasonSelect.value;
            }
            
            // Validate tools
            const toolRows = document.querySelectorAll('.tool-row');
            if (toolRows.length === 0) {
                alert('Please add at least one tool');
                return false;
            }
            
            const tools = [];
            let isValid = true;
            
            toolRows.forEach((row, index) => {
                const rowIndex = index + 1;
                const radios = row.querySelectorAll('input[type="radio"]');
                let selectedType = null;
                
                for (let radio of radios) {
                    if (radio.checked) {
                        selectedType = radio.value;
                        break;
                    }
                }
                
                if (!selectedType) {
                    alert(`Please select tool type for row ${rowIndex}`);
                    isValid = false;
                    return;
                }
                
                const quantityInput = row.querySelector('.tool-quantity-input');
                const quantity = quantityInput ? quantityInput.value : 1;
                
                if (selectedType === 'existing') {
                    const select = row.querySelector('.tool-select');
                    if (!select.value) {
                        alert(`Please select a tool for row ${rowIndex}`);
                        isValid = false;
                        return;
                    }
                    tools.push({
                        type: 'existing',
                        tool_id: select.value,
                        quantity: parseInt(quantity)
                    });
                } else {
                    const newToolInput = row.querySelector('.new-tool-desc');
                    if (!newToolInput.value.trim()) {
                        alert(`Please describe the new tool for row ${rowIndex}`);
                        isValid = false;
                        return;
                    }
                    tools.push({
                        type: 'new',
                        tool_name: newToolInput.value.trim(),
                        quantity: parseInt(quantity)
                    });
                }
            });
            
            if (!isValid) return false;
            
            document.getElementById('toolsJson').value = JSON.stringify(tools);
            return true;
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
        
        // Add first tool row on page load
        document.addEventListener('DOMContentLoaded', function() {
            addToolRow();
        });
    </script>
</body>
</html>