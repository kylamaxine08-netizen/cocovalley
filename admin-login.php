<?php
session_start();
require_once __DIR__ . '/handlers/db_connect.php';

$error = "";

/* CSRF Token */
if (empty($_SESSION['login_csrf'])) {
    $_SESSION['login_csrf'] = bin2hex(random_bytes(32));
}
$token = $_SESSION['login_csrf'];

/* Handle Login */
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    /* CSRF Validation */
    if (!isset($_POST['csrf']) || $_POST['csrf'] !== $_SESSION['login_csrf']) {
        $error = "Security verification failed. Please refresh.";
    } else {

        $email = strtolower(trim($_POST['email'] ?? ''));
        $pass  = $_POST['password'] ?? '';

        if ($email === "" || $pass === "") {
            $error = "Please enter both email and password.";
        } else {

            /* Fetch user */
            $stmt = $conn->prepare("
                SELECT id, first_name, last_name, email, password_hash, role, status
                FROM users
                WHERE email = ?
                LIMIT 1
            ");
            $stmt->bind_param("s", $email);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result->num_rows === 0) {
                $error = "Invalid login credentials.";
            } else {

                $user = $result->fetch_assoc();

                if ($user['status'] != 1) {
                    $error = "Your account is inactive.";
                } elseif (!password_verify($pass, $user['password_hash'])) {
                    $error = "Incorrect password.";
                } else {

                    /* CLEAR OLD SESSION VARIABLES */
                    session_unset();

                    /* UNIVERSAL SESSION ID (Fixes staff login) */
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['name']    = $user['first_name'] . " " . $user['last_name'];

                    /* ROLE-BASED LOGIN */
                    if ($user['role'] === "admin") {

                        $_SESSION['admin_id'] = $user['id'];
                        $_SESSION['role']     = 'admin';

                        header("Location: admin-dashboard.php");
                        exit;

                    } elseif ($user['role'] === "staff") {

                        $_SESSION['staff_id'] = $user['id'];
                        $_SESSION['role']     = 'staff';

                        header("Location: staff-dashboard.php");
                        exit;

                    } else {
                        $error = "Unauthorized role detected.";
                    }
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Cocovalley • Admin Login</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<style>
/* RESET */
* { margin:0; padding:0; box-sizing:border-box; }

/* BACKGROUND */
body {
    font-family: 'Inter', sans-serif;
    height: 100vh;
    padding: 24px;
    background: linear-gradient(135deg, #020617, #0a1220, #04070d);
    display: flex;
    align-items: center;
    justify-content: center;
    position: relative;
    overflow: hidden;
    color: #fff;
}

/* Glowing Effects */
.glow {
    position:absolute;
    width:480px;
    height:480px;
    border-radius:50%;
    filter: blur(120px);
    opacity:0.45;
    animation: float 6s ease-in-out infinite alternate;
}
.glow1 { background:#0ea5e9; top:-120px; left:-80px; }
.glow2 { background:#6366f1; bottom:-160px; right:-100px; }

@keyframes float {
    from { transform:translateY(0); }
    to   { transform:translateY(40px); }
}

/* LOGIN CARD */
.login-card {
    width:100%;
    max-width: 430px;
    padding: 50px 35px 40px;
    border-radius: 28px;
    background: rgba(255,255,255,0.10);
    border: 1px solid rgba(255,255,255,0.25);
    backdrop-filter: blur(24px);
    box-shadow: 0 25px 60px rgba(0,0,0,0.60);
    animation: fadeIn 0.45s ease;
    position: relative;
}

@keyframes fadeIn {
    from { opacity:0; transform: translateY(20px); }
    to   { opacity:1; transform: translateY(0); }
}

.logo-box {
    width: 90px;
    height: 90px;
    margin: 0 auto;
    border-radius: 22px;
    overflow:hidden;
    box-shadow:0 12px 30px rgba(0,0,0,0.4);
}
.logo-box img {
    width:100%;
    height:100%;
    object-fit:cover;
}

/* Titles */
.login-title {
    text-align:center;
    font-size:30px;
    font-weight:800;
    margin-top:18px;
    color:#f8fafc;
}
.login-sub {
    text-align:center;
    font-size:15px;
    margin-top:4px;
    color:#cbd5e1;
}

/* Error */
.error-box {
    margin-top:20px;
    padding:14px;
    background:rgba(239,68,68,0.20);
    border:1px solid rgba(239,68,68,0.45);
    border-radius:14px;
    color:#fecaca;
    font-size:14px;
}

/* Inputs */
.group { margin-top:22px; }
label { font-size:14px; color:#e2e8f0; }

input {
    width:100%;
    padding:14px 16px;
    margin-top:8px;
    border-radius:14px;
    border:1px solid rgba(148,163,184,0.45);
    background:rgba(255,255,255,0.12);
    color:#f0f9ff;
    font-size:15px;
    outline:none;
    transition:.2s;
}
input::placeholder { color:#94a3b8; }

input:focus {
    border-color:#3b82f6;
    box-shadow:0 0 0 3px rgba(59,130,246,0.40);
}

/* Button */
button {
    width:100%;
    margin-top:26px;
    padding:15px 0;
    background:linear-gradient(135deg,#2563eb,#1d4ed8);
    color:#fff;
    font-size:16px;
    font-weight:600;
    border:none;
    border-radius:999px;
    cursor:pointer;
    box-shadow:0 18px 40px rgba(37,99,235,0.55);
    transition:.18s;
}
button:hover {
    transform: translateY(-2px);
    box-shadow:0 25px 55px rgba(37,99,235,0.75);
}
button:active {
    transform: translateY(0);
}

/* Footer */
.footer-link {
    margin-top:22px;
    text-align:center;
    font-size:14px;
    color:#cbd5e1;
}
.footer-link a {
    color:#93c5fd;
    text-decoration:none;
}
.footer-link a:hover {
    text-decoration:underline;
}
</style>
</head>

<body>

<div class="glow glow1"></div>
<div class="glow glow2"></div>

<div class="login-card">

    <div class="logo-box">
        <img src="logo.jpg" alt="Logo">
    </div>

    <div class="login-title">Welcome Back</div>
    <div class="login-sub">Cocovalley Admin & Staff Portal</div>

    <?php if ($error): ?>
        <div class="error-box">
            <?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>

    <form method="POST" autocomplete="off">
        <input type="hidden" name="csrf" value="<?= $token ?>">

        <div class="group">
            <label>Email Address</label>
            <input type="email" name="email" placeholder="admin@cocovalley.com" required>
        </div>

        <div class="group">
            <label>Password</label>
            <input type="password" name="password" placeholder="••••••••••" required>
        </div>

        <button type="submit">Sign In</button>

        <div class="footer-link">
            Need an account? <a href="admin-create-account.php">Create one</a>
        </div>
    </form>

</div>

</body>
</html>
