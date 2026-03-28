<?php
session_start();

/* DB CONFIG */
$DB_HOST = "127.0.0.1";
$DB_NAME = "cocovalley_admin";
$DB_USER = "root";
$DB_PASS = "";

/* AJAX LOGIN HANDLER */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['ajax']) && $_POST['ajax'] === 'login') {

    header("Content-Type: application/json");

    try {
        $pdo = new PDO(
            "mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4",
            $DB_USER,
            $DB_PASS,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
            ]
        );
    } catch (Throwable $e) {
        echo json_encode(["ok" => false, "message" => "Database connection error"]);
        exit;
    }

    /* GET REQUEST */
    $email = strtolower(trim($_POST['email'] ?? ''));
    $password = trim($_POST['password'] ?? '');

    /* CHECK USER */
    $stmt = $pdo->prepare("SELECT id, password_hash FROM users WHERE email = :email LIMIT 1");
    $stmt->execute(['email' => $email]);
    $user = $stmt->fetch();

    if (!$user) {
        echo json_encode(["ok" => false, "message" => "Account not found"]);
        exit;
    }

    if (!password_verify($password, $user['password_hash'])) {
        echo json_encode(["ok" => false, "message" => "Incorrect password"]);
        exit;
    }

    /* SUCCESS */
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['role'] = "customer";

    echo json_encode([
        "ok" => true,
        "redirect" => "customer-dashboard.php"
    ]);
    exit;
}
?>


<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Customer Login • Coco Valley</title>
<link href="https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css" rel="stylesheet">

<style>
    :root{
      --primary:#2e7d32;
      --primary-dark:#1b5e20;
      --accent:#00b894;
      --glass: rgba(255,255,255,0.88);
      --glass-border: rgba(255,255,255,0.25);
      --error:#e53935;
      --success:#1b5e20;
      --text:#142329;
      --muted:#667085;
      --shadow-lg: 0 18px 50px rgba(0,0,0,.25);
      --radius: 18px;
    }

    *{ box-sizing:border-box; font-family:'Segoe UI',system-ui,Arial,sans-serif; margin:0; padding:0; }

    body{
      height:100vh;
      background:linear-gradient(120deg, rgba(46,125,50,.25), rgba(0,184,148,.25)), url('bg.jpg') center/cover no-repeat fixed;
      overflow:hidden;
    }

    body::before{
      content:""; position:fixed; inset:0;
      background:rgba(0,0,0,.35); backdrop-filter:blur(3px);
      z-index:0;
    }

    .modal{
      position:fixed; inset:0; display:flex; justify-content:center; align-items:center;
      padding:20px; z-index:1;
    }

    .card{
      width:430px; max-width:92vw;
      background:var(--glass);
      border:1px solid var(--glass-border);
      backdrop-filter:blur(14px);
      border-radius:var(--radius);
      box-shadow:var(--shadow-lg);
      padding:28px 26px;
      animation:pop .35s ease;
      position:relative;
    }

    @keyframes pop{from{opacity:0; transform:scale(.97);} to{opacity:1;}}

    .logo{
      width:85px;height:85px;object-fit:cover;border-radius:50%;
      display:block;margin:0 auto 10px;box-shadow:0 6px 14px rgba(0,0,0,.25);
    }

    .title{text-align:center;color:var(--primary-dark);font-size:24px;font-weight:800;margin-bottom:4px;}
    .sub{text-align:center;color:var(--muted);margin-bottom:20px;}

    .error-msg{
      background:#fdeaea;border:1px solid #e57373;color:#b71c1c;
      padding:12px 15px;border-radius:12px;margin-bottom:16px;font-weight:600;font-size:14px;
      display:none;
    }

    .group{margin-bottom:15px;}
    .group label{font-size:14px;font-weight:700;margin-bottom:6px;display:block;}

    .field{position:relative;}
    .field input{
      width:100%;padding:12px 42px;border-radius:12px;border:1px solid #dce2e6;background:#ffffffee;
      outline:none;transition:.2s;
    }

    .field input:focus{
      border-color:var(--accent);box-shadow:0 0 0 3px rgba(0,184,148,.22);
    }

    .field i{
      position:absolute;top:50%;left:14px;transform:translateY(-50%);
      font-size:18px;color:#86909c;
    }

    .toggle{ right:14px; left:auto!important; cursor:pointer; }

    .forgot-container{
        text-align:right;
        margin-top:-5px;
        margin-bottom:12px;
    }
    .forgot-link{
        font-size:13px;
        color:var(--primary-dark);
        font-weight:700;
        text-decoration:none;
        opacity:0.85;
        transition:0.2s;
    }
    .forgot-link:hover{
        opacity:1;
        text-decoration:underline;
    }

    .btn{
      width:100%;padding:13px;border:none;
      background:linear-gradient(135deg,var(--primary),var(--primary-dark));
      color:white;border-radius:12px;font-size:16px;font-weight:700;cursor:pointer;transition:.2s;
    }
    .btn:hover{filter:brightness(1.05);transform:translateY(-1px);}

    .extra{text-align:center;margin-top:12px;font-size:14px;}
    .extra a{color:var(--primary-dark);font-weight:700;text-decoration:none;}
    .extra a:hover{text-decoration:underline;}

    .success-overlay{
      position:absolute;inset:0;background:rgba(255,255,255,0.85);backdrop-filter:blur(4px);
      display:flex;justify-content:center;align-items:center;border-radius:var(--radius);z-index:10;display:none;
    }
    .success-overlay i{font-size:80px;color:var(--success);animation:pop .4s ease;}

    /* ⬇ TERMS STYLE */
    .terms-container{
        font-size:13px;
        margin:6px 0 14px;
        color:var(--text);
    }
    .terms-container input{
        margin-right:6px;
        transform:translateY(1px);
    }
    .terms-container a{
        color:var(--primary-dark);
        text-decoration:none;
        font-weight:700;
    }
    .terms-container a:hover{
        text-decoration:underline;
    }

    .terms-modal{
        position:fixed;
        inset:0;
        background:rgba(0,0,0,.45);
        backdrop-filter:blur(3px);
        display:flex;
        justify-content:center;
        align-items:center;
        z-index:9999;
        padding:20px;
    }

    .terms-content{
        background:white;
        padding:22px;
        border-radius:14px;
        width:450px;
        max-width:92vw;
        animation:pop .3s ease;
    }
    .terms-content h2{
        margin-bottom:10px;
        color:var(--primary-dark);
        font-size:22px;
    }
    .terms-content p{
        font-size:14px;
        line-height:1.45;
        margin-bottom:10px;
        color:#36454F;
    }
    .terms-close{
        float:right;
        cursor:pointer;
        font-size:22px;
        color:#666;
    }
</style>
</head>

<body>

<div class="modal">
<div class="card">

    <!-- SUCCESS OVERLAY (optional) -->
    <div class="success-overlay" id="successOverlay">
        <i class='bx bx-check-circle'></i>
    </div>

    <!-- LOGO -->
    <img src="logo.jpg" class="logo" onerror="this.style.display='none'">

    <!-- TITLE -->
    <div class="title">Welcome Back!</div>
    <div class="sub">Login to continue</div>

    <!-- ERROR -->
    <div class="error-msg" id="errorMsg"></div>

    <!-- FORM -->
    <form id="loginForm">
        <input type="hidden" name="ajax" value="login">

        <!-- EMAIL -->
        <div class="group">
            <label>Email</label>
            <div class="field">
                <i class='bx bxs-envelope'></i>
                <input type="email" name="email" placeholder="you@example.com" required>
            </div>
        </div>

        <!-- PASSWORD -->
        <div class="group">
            <label>Password</label>
            <div class="field">
                <i class='bx bxs-lock-alt'></i>
                <input type="password" name="password" id="password" placeholder="Enter password" required>
                <i class='bx bx-show toggle' id="togglePw"></i>
            </div>
        </div>

        <!-- FORGOT -->
        <div class="forgot-container">
            <a href="forgotpass.php" class="forgot-link">Forgot Password?</a>
        </div>

        <!-- TERMS -->
        <div class="terms-container">
            <input type="checkbox" id="agreeTerms">
            <label for="agreeTerms">
                I agree to the 
                <a href="terms-privacy.php" id="termsLink" target="_blank">
                    Terms & Agreement
                </a>
            </label>
        </div>

        <!-- LOGIN BUTTON -->
        <button class="btn" id="loginBtn" type="submit" disabled>
            Login
        </button>

    </form>

    <!-- SIGNUP -->
    <div class="extra">
        Don’t have an account?
        <a href="customer-signup.php">Create one</a>
    </div>

</div>
</div>

<script>
const form   = document.getElementById("loginForm");
const errBox = document.getElementById("errorMsg");
const btn    = document.getElementById("loginBtn");

/* SHOW/HIDE PASSWORD */
const togglePw = document.getElementById("togglePw");
togglePw.addEventListener("click", () => {
    const pw = document.getElementById("password");
    const isHidden = pw.type === "password";
    pw.type = isHidden ? "text" : "password";
});

/* ENABLE LOGIN WHEN CHECKED */
const agreeTerms = document.getElementById("agreeTerms");
agreeTerms.addEventListener("change", () => {
    btn.disabled = !agreeTerms.checked;
});

/* AJAX LOGIN */
form.addEventListener("submit", async (e) => {
    e.preventDefault();

    errBox.style.display = "none";
    btn.disabled = true;
    btn.textContent = "Checking...";

    try {
        const res = await fetch("customer-login.php", {
            method: "POST",
            body: new FormData(form)
        });

        const data = await res.json();

        if (!data.ok) {
            errBox.textContent = data.message;
            errBox.style.display = "block";
            btn.disabled = false;
            btn.textContent = "Login";
            return;
        }

        window.location.href = data.redirect;

    } catch (e) {
        errBox.textContent = "Server error. Please try again.";
        errBox.style.display = "block";
        btn.disabled = false;
        btn.textContent = "Login";
    }
});
</script>
</body>
</html>
