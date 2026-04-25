<?php
session_start();

// If already logged in, go directly to the dashboard
if (isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true) {
    header('Location: dashboard_erp.php');
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SAVANT MOTORS | Home</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        }

        body {
            min-height: 100vh;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            position: relative;
            overflow: hidden;
        }

        /* Animated background orbs */
        .orb {
            position: absolute;
            border-radius: 50%;
            filter: blur(80px);
            opacity: 0.3;
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
        }
        .orb-3 {
            width: 300px;
            height: 300px;
            background: #8b5cf6;
            top: 40%;
            right: 20%;
            animation-duration: 14s;
        }
        @keyframes floatOrb {
            0% { transform: translate(0, 0) scale(1); }
            100% { transform: translate(40px, 30px) scale(1.1); }
        }

        .hero {
            position: relative;
            z-index: 10;
            text-align: center;
            max-width: 700px;
            padding: 2rem;
            animation: fadeSlideUp 0.7s cubic-bezier(0.2, 0.9, 0.4, 1.1);
        }
        @keyframes fadeSlideUp {
            from { opacity: 0; transform: translateY(30px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .logo-icon {
            width: 80px;
            height: 80px;
            background: linear-gradient(135deg, #ffffff20, #ffffff05);
            backdrop-filter: blur(8px);
            border-radius: 28px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1.5rem;
            box-shadow: 0 20px 35px -10px rgba(0,0,0,0.3);
            border: 1px solid rgba(255,255,255,0.2);
        }
        .logo-icon i {
            font-size: 40px;
            color: white;
            text-shadow: 0 2px 5px rgba(0,0,0,0.2);
        }

        h1 {
            font-size: 3.5rem;
            font-weight: 800;
            color: white;
            letter-spacing: -0.02em;
            margin-bottom: 1rem;
            text-shadow: 0 2px 10px rgba(0,0,0,0.2);
        }
        .tagline {
            font-size: 1.2rem;
            color: rgba(255,255,255,0.9);
            margin-bottom: 2rem;
            font-weight: 500;
        }

        .btn-group {
            display: flex;
            gap: 1rem;
            justify-content: center;
            flex-wrap: wrap;
        }
        .btn {
            padding: 0.9rem 2rem;
            border-radius: 2rem;
            font-weight: 600;
            font-size: 1rem;
            text-decoration: none;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 0.6rem;
            backdrop-filter: blur(4px);
        }
        .btn-primary {
            background: linear-gradient(95deg, #4f46e5, #7c3aed);
            color: white;
            box-shadow: 0 8px 20px -6px rgba(79,70,229,0.5);
            border: 1px solid rgba(255,255,255,0.2);
        }
        .btn-primary:hover {
            transform: translateY(-3px);
            box-shadow: 0 15px 30px -8px rgba(79,70,229,0.6);
        }
        .btn-outline {
            background: rgba(255,255,255,0.1);
            border: 1px solid rgba(255,255,255,0.3);
            color: white;
        }
        .btn-outline:hover {
            background: rgba(255,255,255,0.2);
            transform: translateY(-2px);
        }

        .features {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 1.5rem;
            margin-top: 3rem;
            padding-top: 2rem;
            border-top: 1px solid rgba(255,255,255,0.2);
        }
        .feature {
            background: rgba(255, 255, 255, 0.1);
            backdrop-filter: blur(8px);
            padding: 1.2rem;
            border-radius: 1.2rem;
            text-align: center;
            transition: all 0.3s;
        }
        .feature i {
            font-size: 2rem;
            color: #c4b5fd;
            margin-bottom: 0.5rem;
        }
        .feature h3 {
            color: white;
            font-size: 1rem;
            margin-bottom: 0.3rem;
        }
        .feature p {
            color: rgba(255,255,255,0.7);
            font-size: 0.8rem;
        }

        footer {
            position: absolute;
            bottom: 1rem;
            left: 0;
            right: 0;
            text-align: center;
            color: rgba(255,255,255,0.6);
            font-size: 0.8rem;
            z-index: 10;
        }

        @media (max-width: 600px) {
            h1 { font-size: 2.2rem; }
            .tagline { font-size: 1rem; }
            .btn-group { flex-direction: column; align-items: center; }
            .btn { width: 80%; justify-content: center; }
        }
    </style>
</head>
<body>
    <div class="orb orb-1"></div>
    <div class="orb orb-2"></div>
    <div class="orb orb-3"></div>

    <div class="hero">
        <div class="logo-icon">
            <i class="fas fa-chart-line"></i>
        </div>
        <h1>SAVANT MOTORS</h1>
        <div class="tagline">
            Enterprise Resource Planning & Point of Sale System
        </div>
        <div class="btn-group">
            <a href="login.php" class="btn btn-primary">
                <i class="fas fa-sign-in-alt"></i> Login to Dashboard
            </a>
            <a href="#" class="btn btn-outline" id="demoBtn">
                <i class="fas fa-play"></i> Watch Demo
            </a>
        </div>

        <div class="features">
            <div class="feature">
                <i class="fas fa-tools"></i>
                <h3>Tool Management</h3>
                <p>Track tools, assignments, and maintenance</p>
            </div>
            <div class="feature">
                <i class="fas fa-clipboard-list"></i>
                <h3>Job Cards</h3>
                <p>Manage repair orders and service history</p>
            </div>
            <div class="feature">
                <i class="fas fa-chart-simple"></i>
                <h3>Real‑time Analytics</h3>
                <p>Insights into performance and inventory</p>
            </div>
        </div>
    </div>

    <footer>
        &copy; <?php echo date('Y'); ?> Savant Motors Uganda. All rights reserved.
    </footer>

    <script>
        // Simple demo alert – can be replaced with a real demo video or tour
        document.getElementById('demoBtn').addEventListener('click', (e) => {
            e.preventDefault();
            alert('A system tour is coming soon! For now, please log in using the admin credentials: admin / admin123');
        });
    </script>
</body>
</html>