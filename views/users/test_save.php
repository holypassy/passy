<?php
// test_save.php - Simple test page for saving users
session_start();

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: index.php');
    exit();
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Test Save User</title>
    <style>
        body { font-family: Arial, sans-serif; padding: 20px; }
        .form-group { margin-bottom: 15px; }
        label { display: block; margin-bottom: 5px; }
        input, select { width: 100%; padding: 8px; max-width: 300px; }
        button { padding: 10px 20px; background: blue; color: white; border: none; cursor: pointer; }
        .result { margin-top: 20px; padding: 10px; border: 1px solid #ccc; display: none; }
        .success { background: #d4edda; color: #155724; border-color: #c3e6cb; }
        .error { background: #f8d7da; color: #721c24; border-color: #f5c6cb; }
    </style>
</head>
<body>
    <h1>Test Save User</h1>
    <form id="testForm">
        <div class="form-group">
            <label>Full Name</label>
            <input type="text" name="full_name" required>
        </div>
        <div class="form-group">
            <label>Username</label>
            <input type="text" name="username" required>
        </div>
        <div class="form-group">
            <label>Email</label>
            <input type="email" name="email" required>
        </div>
        <div class="form-group">
            <label>Role</label>
            <select name="role">
                <option value="cashier">Cashier</option>
                <option value="manager">Manager</option>
                <option value="admin">Admin</option>
                <option value="technician">Technician</option>
                <option value="receptionist">Receptionist</option>
            </select>
        </div>
        <div class="form-group">
            <label>Password</label>
            <input type="password" name="password">
        </div>
        <div class="form-group">
            <label>Confirm Password</label>
            <input type="password" name="confirm_password">
        </div>
        <button type="submit">Save User</button>
    </form>
    
    <div id="result" class="result"></div>
    
    <script>
        document.getElementById('testForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            
            fetch('save_user.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                const resultDiv = document.getElementById('result');
                resultDiv.style.display = 'block';
                
                if (data.success) {
                    resultDiv.className = 'result success';
                    resultDiv.innerHTML = '<strong>Success!</strong> ' + data.message;
                    document.getElementById('testForm').reset();
                } else {
                    resultDiv.className = 'result error';
                    resultDiv.innerHTML = '<strong>Error!</strong> ' + data.message;
                }
            })
            .catch(error => {
                const resultDiv = document.getElementById('result');
                resultDiv.style.display = 'block';
                resultDiv.className = 'result error';
                resultDiv.innerHTML = '<strong>Error!</strong> ' + error;
            });
        });
    </script>
</body>
</html>