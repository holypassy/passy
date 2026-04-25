<?php
session_start();

// If user is already logged in, redirect to dashboard
if (isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true) {
    header('Location: dashboard_erp.php');
    exit;
}

// --------------------------------------------------------------------
// LOGIN PROCESSING (AJAX and standard POST)
// --------------------------------------------------------------------
$response = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Ensure JSON response for AJAX requests
    $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && 
              strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest';
    
    try {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $remember = isset($_POST['remember']) && $_POST['remember'] == '1';

        if (empty($username) || empty($password)) {
            throw new Exception('Please enter both username/email and password');
        }

        // Database connection
        $conn = new PDO("mysql:host=localhost;dbname=savant_motors_pos", "root", "");
        $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Fetch user by username or email
        $stmt = $conn->prepare("SELECT id, username, password, full_name, role, is_active FROM users WHERE username = ? OR email = ?");
        $stmt->execute([$username, $username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            throw new Exception('Invalid credentials');
        }

        // Verify password (assumes passwords are hashed with password_hash)
        if (!password_verify($password, $user['password'])) {
            throw new Exception('Invalid credentials');
        }

        if ($user['is_active'] != 1) {
            throw new Exception('Account is disabled. Contact administrator.');
        }

        // Login successful – set session
        $_SESSION['logged_in'] = true;
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['full_name'] = $user['full_name'];
        $_SESSION['role'] = $user['role'];

        // Remember me: set a cookie with user ID and a token (simplified)
        if ($remember) {
            // Generate a random token
            $token = bin2hex(random_bytes(32));
            // Store token in database for verification (optional, but recommended)
            $stmt = $conn->prepare("UPDATE users SET remember_token = ? WHERE id = ?");
            $stmt->execute([$token, $user['id']]);
            setcookie('remember_token', $token, time() + 86400 * 30, '/', '', false, true);
            setcookie('user_id', $user['id'], time() + 86400 * 30, '/', '', false, true);
        }

        if ($isAjax) {
            // Return JSON success
            header('Content-Type: application/json');
            echo json_encode([
                'success' => true,
                'message' => 'Login successful! Redirecting...'
            ]);
            exit;
        } else {
            // Standard form submission: redirect to dashboard
            header('Location: dashboard_erp.php');
            exit;
        }
    } catch (Exception $e) {
        $errorMessage = $e->getMessage();

        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'message' => $errorMessage
            ]);
            exit;
        } else {
            // For non‑AJAX, store error in session or pass via URL
            $_SESSION['login_error'] = $errorMessage;
            header('Location: login.php?error=' . urlencode($errorMessage));
            exit;
        }
    }
}

// --------------------------------------------------------------------
// HTML / UI – unchanged except for capturing URL error parameter
// --------------------------------------------------------------------
$url_error = isset($_GET['error']) ? htmlspecialchars($_GET['error']) : '';
if (isset($_SESSION['login_error'])) {
    $url_error = htmlspecialchars($_SESSION['login_error']);
    unset($_SESSION['login_error']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">
    <title>SAVANT MOTORS UGANDA Login | Secure Access</title>
    <!-- Google Fonts + Font Awesome -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,300;14..32,400;14..32,500;14..32,600;14..32,700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        /* All CSS styles remain exactly as originally provided – unchanged */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        body {
            font-family: 'Inter', sans-serif;
            min-height: 100vh;
            background: linear-gradient(145deg, #0a0f1e 0%, #0c1222 50%, #0a0f1c 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
            overflow-x: hidden;
        }
        .orb {
            position: absolute;
            border-radius: 50%;
            filter: blur(80px);
            opacity: 0.4;
            z-index: 0;
            animation: floatOrb 18s infinite alternate ease-in-out;
        }
        .orb-1 {
            width: 400px;
            height: 400px;
            background: #4f46e5;
            top: -100px;
            left: -150px;
        }
        .orb-2 {
            width: 500px;
            height: 500px;
            background: #06b6d4;
            bottom: -150px;
            right: -100px;
            animation-duration: 22s;
            opacity: 0.3;
        }
        .orb-3 {
            width: 300px;
            height: 300px;
            background: #8b5cf6;
            top: 40%;
            right: 20%;
            animation-duration: 14s;
            opacity: 0.25;
        }
        @keyframes floatOrb {
            0% { transform: translate(0, 0) scale(1); }
            100% { transform: translate(40px, 30px) scale(1.1); }
        }
        .login-container {
            position: relative;
            z-index: 10;
            width: 100%;
            max-width: 460px;
            margin: 2rem;
            animation: fadeSlideUp 0.7s cubic-bezier(0.2, 0.9, 0.4, 1.1);
        }
        @keyframes fadeSlideUp {
            from { opacity: 0; transform: translateY(30px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .login-card {
            background: rgba(18, 24, 38, 0.75);
            backdrop-filter: blur(12px);
            border-radius: 2rem;
            padding: 2rem 2rem 2.2rem;
            box-shadow: 0 25px 45px -12px rgba(0, 0, 0, 0.5), 0 1px 2px rgba(255, 255, 255, 0.05) inset;
            border: 1px solid rgba(255, 255, 255, 0.08);
            transition: transform 0.2s ease, box-shadow 0.2s;
        }
        .login-card:hover {
            box-shadow: 0 30px 55px -15px rgba(0, 0, 0, 0.6);
            transform: translateY(-2px);
        }
        .brand {
            text-align: center;
            margin-bottom: 2rem;
        }
        .brand a {
            text-decoration: none;
            display: inline-block;
        }
        .brand-icon {
            width: 56px;
            height: 56px;
            background: linear-gradient(135deg, #4f46e5, #7c3aed);
            border-radius: 20px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 1rem;
            box-shadow: 0 12px 20px -8px rgba(79, 70, 229, 0.4);
            transition: transform 0.2s;
        }
        .brand-icon:hover {
            transform: scale(1.02);
        }
        .brand-icon i {
            font-size: 28px;
            color: white;
        }
        .brand h1 {
            font-size: 1.9rem;
            font-weight: 700;
            background: linear-gradient(120deg, #ffffff, #c4b5fd);
            background-clip: text;
            -webkit-background-clip: text;
            color: transparent;
            letter-spacing: -0.3px;
            transition: opacity 0.2s;
        }
        .brand h1:hover {
            opacity: 0.9;
        }
        .brand p {
            color: #9ca3af;
            font-size: 0.9rem;
            margin-top: 0.5rem;
            font-weight: 400;
        }
        /* Logo styling */
        .logo-circle {
            width: 100px;
            height: 100px;
            margin: 0 auto 1rem auto;
            background: linear-gradient(135deg, #ffffff, #ffffff);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.3);
            border: 2px solid rgba(255, 255, 255, 0.2);
        }
        .logo-circle img {
            width: 100px;
            height: 100px;
            object-fit: contain;
            border-radius: 50%;
        }
        .message-area {
            margin-bottom: 1.5rem;
            min-height: 52px;
        }
        .alert-message {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 0.8rem 1rem;
            border-radius: 1.2rem;
            font-size: 0.85rem;
            font-weight: 500;
            backdrop-filter: blur(4px);
            animation: shakeFade 0.3s ease;
        }
        .alert-message i {
            font-size: 1rem;
        }
        .alert-error {
            background: rgba(239, 68, 68, 0.15);
            border-left: 3px solid #ef4444;
            color: #fecaca;
        }
        .alert-success {
            background: rgba(16, 185, 129, 0.15);
            border-left: 3px solid #10b981;
            color: #a7f3d0;
        }
        @keyframes shakeFade {
            0% { opacity: 0; transform: translateX(-6px);}
            100% { opacity: 1; transform: translateX(0);}
        }
        .input-group {
            margin-bottom: 1.4rem;
            position: relative;
        }
        .input-icon {
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
            color: #6b7280;
            font-size: 1.1rem;
            transition: color 0.2s;
            pointer-events: none;
        }
        .input-field {
            width: 100%;
            background: rgba(30, 36, 54, 0.8);
            border: 1px solid rgba(255, 255, 255, 0.1);
            padding: 0.9rem 1rem 0.9rem 2.8rem;
            font-size: 0.95rem;
            font-weight: 500;
            border-radius: 1.2rem;
            color: #f1f5f9;
            transition: all 0.25s;
            font-family: 'Inter', sans-serif;
        }
        .input-field:focus {
            outline: none;
            border-color: #8b5cf6;
            background: rgba(20, 26, 44, 0.9);
            box-shadow: 0 0 0 3px rgba(139, 92, 246, 0.2);
        }
        .input-field::placeholder {
            color: #6b7280;
            font-weight: 400;
        }
        .password-toggle {
            position: absolute;
            right: 1rem;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: #8b5cf6;
            cursor: pointer;
            font-size: 1rem;
            padding: 0;
            display: flex;
            align-items: center;
            transition: color 0.2s;
        }
        .password-toggle:hover {
            color: #c4b5fd;
        }
        .options {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin: 1.2rem 0 1.8rem;
            font-size: 0.85rem;
        }
        .checkbox-wrapper {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            cursor: pointer;
            color: #cbd5e1;
        }
        .checkbox-wrapper input {
            width: 16px;
            height: 16px;
            accent-color: #7c3aed;
            cursor: pointer;
        }
        .forgot-link {
            color: #a78bfa;
            text-decoration: none;
            font-weight: 500;
            transition: color 0.2s;
        }
        .forgot-link:hover {
            color: #c4b5fd;
            text-decoration: underline;
        }
        .login-btn {
            width: 100%;
            background: linear-gradient(95deg, #4f46e5, #7c3aed);
            border: none;
            padding: 0.9rem;
            border-radius: 1.4rem;
            font-weight: 600;
            font-size: 1rem;
            font-family: 'Inter', sans-serif;
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            cursor: pointer;
            transition: all 0.25s ease;
            box-shadow: 0 8px 18px -6px rgba(79, 70, 229, 0.4);
            letter-spacing: 0.2px;
        }
        .login-btn i {
            font-size: 1.1rem;
            transition: transform 0.2s;
        }
        .login-btn:hover {
            transform: translateY(-2px);
            background: linear-gradient(95deg, #6366f1, #8b5cf6);
            box-shadow: 0 14px 26px -8px rgba(79, 70, 229, 0.5);
        }
        .login-btn:active {
            transform: translateY(1px);
        }
        .login-btn:disabled {
            opacity: 0.7;
            transform: none;
            cursor: not-allowed;
            filter: grayscale(0.1);
        }
        .spinner {
            width: 18px;
            height: 18px;
            border: 2px solid rgba(255,255,255,0.3);
            border-top: 2px solid white;
            border-radius: 50%;
            animation: spin 0.7s linear infinite;
            display: inline-block;
        }
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        .signup-link {
            text-align: center;
            margin-top: 2rem;
            font-size: 0.85rem;
            color: #9ca3af;
        }
        .signup-link a {
            color: #c4b5fd;
            text-decoration: none;
            font-weight: 600;
            margin-left: 5px;
        }
        .signup-link a:hover {
            text-decoration: underline;
        }
        .home-link {
            text-align: center;
            margin-top: 1.2rem;
            font-size: 0.8rem;
        }
        .home-link a {
            color: #9ca3af;
            text-decoration: none;
            transition: color 0.2s;
        }
        .home-link a:hover {
            color: #c4b5fd;
            text-decoration: underline;
        }
        @media (max-width: 500px) {
            .login-card {
                padding: 1.5rem;
            }
            .brand h1 {
                font-size: 1.6rem;
            }
            .options {
                flex-wrap: wrap;
                gap: 0.8rem;
            }
        }
    </style>
</head>
<body>
    <div class="orb orb-1"></div>
    <div class="orb orb-2"></div>
    <div class="orb orb-3"></div>

    <div class="login-container">
        <div class="login-card">
            <div class="brand">
                <!-- Logo Image Added -->
                <div class="logo-circle">
                    <img src="images/logo.jpeg" alt="Savant Motors Logo">
                </div>
                <a href="index.php">
                    <h1>SAVANT MOTORS</h1>
                </a>
                <p>ERP SYSTEM</p>
            </div>

            <div class="message-area" id="messageArea">
                <?php if (!empty($url_error)): ?>
                <div class="alert-message alert-error">
                    <i class="fas fa-circle-exclamation"></i>
                    <span><?php echo $url_error; ?></span>
                </div>
                <?php endif; ?>
            </div>

            <form id="loginForm" method="POST" action="login.php">
                <div class="input-group">
                    <i class="fas fa-user input-icon"></i>
                    <input type="text" class="input-field" id="username" name="username" placeholder="Username or Email" autocomplete="username" required>
                </div>

                <div class="input-group">
                    <i class="fas fa-lock input-icon"></i>
                    <input type="password" class="input-field" id="password" name="password" placeholder="Password" autocomplete="current-password" required>
                    <button type="button" class="password-toggle" id="togglePassword" aria-label="Show password">
                        <i class="far fa-eye-slash"></i>
                    </button>
                </div>

                <div class="options">
                    <label class="checkbox-wrapper">
                        <input type="checkbox" name="remember" id="rememberCheckbox">
                        <span>Remember me</span>
                    </label>
                    <a href="#" class="forgot-link">Forgot password?</a>
                </div>

                <button type="submit" class="login-btn" id="loginButton">
                    <i class="fas fa-arrow-right-to-bracket"></i>
                    <span>Sign in</span>
                </button>
            </form>

            <div class="signup-link">
                Don't have an account? <a href="#">Request access</a>
            </div>
            <div class="home-link">
                <a href="index.php"><i class="fas fa-home"></i> Back to Home</a>
            </div>
        </div>
    </div>

    <script>
        // DOM elements
        const loginForm = document.getElementById('loginForm');
        const loginBtn = document.getElementById('loginButton');
        const btnTextSpan = loginBtn.querySelector('span');
        const btnIcon = loginBtn.querySelector('i');
        const messageArea = document.getElementById('messageArea');
        const usernameInput = document.getElementById('username');
        const passwordInput = document.getElementById('password');
        const togglePasswordBtn = document.getElementById('togglePassword');
        const rememberCheck = document.getElementById('rememberCheckbox');

        function showMessage(type, text) {
            // Clear existing messages except possibly the static PHP error (if any)
            const existingErrors = messageArea.querySelectorAll('.alert-message');
            existingErrors.forEach(el => el.remove());
            
            const msgDiv = document.createElement('div');
            msgDiv.className = `alert-message alert-${type}`;
            const icon = type === 'error' ? '<i class="fas fa-circle-exclamation"></i>' : '<i class="fas fa-check-circle"></i>';
            msgDiv.innerHTML = `${icon}<span>${escapeHtml(text)}</span>`;
            messageArea.appendChild(msgDiv);
            
            if (type === 'success') {
                setTimeout(() => {
                    if (messageArea.contains(msgDiv)) {
                        msgDiv.style.opacity = '0';
                        setTimeout(() => {
                            if (messageArea.contains(msgDiv)) msgDiv.remove();
                        }, 300);
                    }
                }, 3000);
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

        function clearMessage() {
            const msgs = messageArea.querySelectorAll('.alert-message');
            msgs.forEach(el => el.remove());
        }

        function setLoading(isLoading) {
            if (isLoading) {
                loginBtn.disabled = true;
                btnTextSpan.innerHTML = 'Authenticating';
                btnIcon.innerHTML = '<span class="spinner"></span>';
            } else {
                loginBtn.disabled = false;
                btnTextSpan.innerHTML = 'Sign in';
                btnIcon.innerHTML = '<i class="fas fa-arrow-right-to-bracket"></i>';
            }
        }

        togglePasswordBtn.addEventListener('click', () => {
            const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
            passwordInput.setAttribute('type', type);
            const icon = togglePasswordBtn.querySelector('i');
            if (type === 'text') {
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            } else {
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            }
        });

        loginForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            
            const username = usernameInput.value.trim();
            const password = passwordInput.value;
            
            if (!username || !password) {
                showMessage('error', 'Please enter both username/email and password');
                return;
            }
            
            clearMessage();
            setLoading(true);
            
            const formData = new FormData();
            formData.append('username', username);
            formData.append('password', password);
            if (rememberCheck.checked) {
                formData.append('remember', '1');
            }
            
            try {
                const response = await fetch('login.php', {
                    method: 'POST',
                    body: formData,
                    credentials: 'same-origin',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                });
                
                let data;
                const contentType = response.headers.get('content-type');
                if (contentType && contentType.includes('application/json')) {
                    data = await response.json();
                } else {
                    throw new Error('Invalid server response');
                }
                
                if (data.success) {
                    showMessage('success', data.message || 'Login successful! Redirecting...');
                    setTimeout(() => {
                        window.location.href = 'dashboard_erp.php';
                    }, 1200);
                } else {
                    showMessage('error', data.message || 'Authentication failed. Please try again.');
                    setLoading(false);
                    const card = document.querySelector('.login-card');
                    card.style.transform = 'translateX(4px)';
                    setTimeout(() => { card.style.transform = ''; }, 200);
                }
            } catch (err) {
                console.error('Login error:', err);
                showMessage('error', 'Network error. Please check your connection and try again.');
                setLoading(false);
            }
        });

        usernameInput.addEventListener('blur', () => {
            usernameInput.value = usernameInput.value.trim();
        });
        
        window.addEventListener('load', () => {
            usernameInput.focus();
        });
        
        const inputs = document.querySelectorAll('.input-field');
        inputs.forEach(input => {
            input.addEventListener('focus', () => {
                input.parentElement.style.transform = 'scale(1.01)';
            });
            input.addEventListener('blur', () => {
                input.parentElement.style.transform = '';
            });
        });
        
        const forgotLink = document.querySelector('.forgot-link');
        if (forgotLink) {
            forgotLink.addEventListener('click', (e) => {
                e.preventDefault();
                showMessage('error', 'Password recovery is not implemented. Contact administrator.');
            });
        }
        
        const signupLink = document.querySelector('.signup-link a');
        if (signupLink) {
            signupLink.addEventListener('click', (e) => {
                e.preventDefault();
                showMessage('error', 'Account creation is disabled. Please contact system administrator.');
            });
        }
    </script>
</body>
</html>