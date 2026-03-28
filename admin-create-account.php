<?php
session_start();
require_once __DIR__ . '/handlers/db_connect.php';

$success = "";
$error   = "";

// Simple helper para safe output
function e($str) {
    return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
}

/* --- Handle Create Account --- */
if ($_SERVER["REQUEST_METHOD"] === "POST") {

    $first  = trim($_POST['first_name'] ?? '');
    $last   = trim($_POST['last_name'] ?? '');
    $email  = strtolower(trim($_POST['email'] ?? ''));
    $pass   = $_POST['password'] ?? '';
    $cpass  = $_POST['confirm_password'] ?? '';
    $role   = $_POST['role'] ?? 'staff';

    // Force role to admin/staff only
    if (!in_array($role, ['admin', 'staff'], true)) {
        $role = 'staff';
    }

    // Basic validations
    if ($first === "" || $last === "" || $email === "" || $pass === "" || $cpass === "") {
        $error = "Please fill in all required fields.";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = "Please enter a valid email address.";
    } elseif (strlen($pass) < 6) {
        $error = "Password must be at least 6 characters.";
    } elseif ($pass !== $cpass) {
        $error = "Passwords do not match.";
    } else {
        // Check if email already exists
        $stmt = $conn->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
        $stmt->bind_param("s", $email);
        $stmt->execute();
        $exists = $stmt->get_result()->num_rows > 0;
        $stmt->close();

        if ($exists) {
            $error = "An account with this email already exists.";
        } else {
            $hash = password_hash($pass, PASSWORD_DEFAULT);

            $insert = $conn->prepare("
                INSERT INTO users (first_name, last_name, email, password_hash, role, status)
                VALUES (?, ?, ?, ?, ?, 1)
            ");
            $insert->bind_param("sssss", $first, $last, $email, $hash, $role);

            if ($insert->execute()) {
                $success = "Account created successfully for {$role}!";
                // Clear form values
                $first = $last = $email = "";
            } else {
                $error = "Something went wrong while saving. Please try again.";
            }
            $insert->close();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Create Account • Cocovalley</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <style>
        :root {
            --bg-gradient: radial-gradient(circle at top left, #2d6bff 0, transparent 55%),
                           radial-gradient(circle at bottom right, #00c6ff 0, transparent 55%),
                           linear-gradient(135deg, #020923 0%, #071736 45%, #030817 100%);
            --card-bg: rgba(255, 255, 255, 0.11);
            --card-border: rgba(255, 255, 255, 0.45);
            --card-shadow: 0 30px 60px rgba(0, 0, 0, 0.45);
            --accent: #1d73ea;
            --accent-soft: rgba(29, 115, 234, 0.15);
            --text-main: #0b1220;
            --text-muted: #6b7280;
            --danger: #e11d48;
            --success: #16a34a;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Inter", sans-serif;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: var(--bg-gradient);
            color: #fff;
            padding: 24px;
        }

        .page-wrap {
            position: relative;
            width: 100%;
            max-width: 1120px;
            min-height: 560px;
            display: flex;
            align-items: stretch;
            border-radius: 32px;
            overflow: hidden;
            background: linear-gradient(135deg, rgba(15, 23, 42, 0.9), rgba(15, 23, 42, 0.9));
            box-shadow: 0 40px 90px rgba(0, 0, 0, 0.65);
        }

        .page-wrap::before,
        .page-wrap::after {
            content: "";
            position: absolute;
            border-radius: 999px;
            filter: blur(40px);
            opacity: 0.35;
            pointer-events: none;
        }

        .page-wrap::before {
            width: 260px;
            height: 260px;
            background: #22c55e;
            top: -120px;
            right: -40px;
        }

        .page-wrap::after {
            width: 280px;
            height: 280px;
            background: #3b82f6;
            bottom: -160px;
            left: -60px;
        }

        /* Left panel (branding) */
        .left-panel {
            flex: 0.9;
            padding: 40px 40px 40px 40px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            color: rgba(255, 255, 255, 0.9);
            position: relative;
            z-index: 1;
        }

        .brand-row {
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .brand-logo {
            width: 52px;
            height: 52px;
            border-radius: 18px;
            background: radial-gradient(circle at 20% 20%, #a5f3fc, #22c55e, #0ea5e9);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 800;
            font-size: 20px;
            color: #022c22;
            box-shadow: 0 14px 30px rgba(15, 118, 110, 0.65);
        }

        .brand-text-title {
            font-size: 22px;
            font-weight: 700;
            letter-spacing: 0.02em;
        }

        .brand-text-sub {
            font-size: 13px;
            opacity: 0.85;
        }

        .headline {
            margin-top: 40px;
            max-width: 360px;
        }

        .headline h1 {
            font-size: 30px;
            line-height: 1.2;
            font-weight: 700;
            margin-bottom: 14px;
        }

        .headline p {
            font-size: 14px;
            line-height: 1.6;
            opacity: 0.85;
        }

        .left-footer {
            font-size: 12px;
            opacity: 0.8;
        }

        .pill {
            display: inline-flex;
            align-items: center;
            padding: 4px 10px;
            border-radius: 999px;
            background: rgba(15, 23, 42, 0.6);
            backdrop-filter: blur(12px);
            font-size: 11px;
            gap: 6px;
            margin-bottom: 10px;
        }

        .pill-dot {
            width: 8px;
            height: 8px;
            border-radius: 999px;
            background: #22c55e;
            box-shadow: 0 0 0 4px rgba(34, 197, 94, 0.3);
        }

        /* Right panel (form card) */
        .right-panel {
            flex: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 30px;
            position: relative;
            z-index: 2;
        }

        .form-card {
            width: 100%;
            max-width: 420px;
            padding: 28px 26px 26px 26px;
            border-radius: 24px;
            background: linear-gradient(135deg, rgba(15, 23, 42, 0.78), rgba(15, 23, 42, 0.9));
            border: 1px solid rgba(148, 163, 184, 0.8);
            backdrop-filter: blur(26px);
            box-shadow: var(--card-shadow);
            color: #e5e7eb;
        }

        .form-header {
            margin-bottom: 20px;
        }

        .form-title {
            font-size: 22px;
            font-weight: 600;
            color: #f9fafb;
        }

        .form-subtitle {
            font-size: 13px;
            color: #9ca3af;
            margin-top: 4px;
        }

        .badge-mini {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 10px;
            border-radius: 999px;
            background: rgba(148, 163, 184, 0.18);
            font-size: 11px;
            color: #e5e7eb;
            margin-bottom: 10px;
        }

        .badge-mini-dot {
            width: 7px;
            height: 7px;
            border-radius: 999px;
            background: #22c55e;
        }

        .form-grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 14px;
        }

        .group {
            margin-bottom: 14px;
        }

        label {
            font-size: 12px;
            font-weight: 500;
            color: #cbd5f5;
            letter-spacing: 0.01em;
        }

        .field {
            margin-top: 6px;
        }

        input[type="text"],
        input[type="email"],
        input[type="password"] {
            width: 100%;
            padding: 10px 11px;
            border-radius: 10px;
            border: 1px solid rgba(148, 163, 184, 0.6);
            background: rgba(15, 23, 42, 0.85);
            color: #e5e7eb;
            font-size: 14px;
            outline: none;
            transition: border-color 0.18s ease, box-shadow 0.18s ease, background 0.18s ease, transform 0.1s ease;
        }

        input::placeholder {
            color: #6b7280;
        }

        input:focus {
            border-color: #60a5fa;
            box-shadow: 0 0 0 1px rgba(96, 165, 250, 0.5);
            background: rgba(15, 23, 42, 0.98);
            transform: translateY(-0.5px);
        }

        /* Role pills */
        .role-group {
            margin-bottom: 16px;
        }

        .role-label {
            font-size: 12px;
            font-weight: 500;
            color: #cbd5f5;
            margin-bottom: 6px;
            display: block;
        }

        .role-pills {
            display: flex;
            gap: 8px;
        }

        .role-pill {
            position: relative;
            cursor: pointer;
        }

        .role-pill input {
            display: none;
        }

        .role-pill span {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 7px 14px;
            font-size: 12px;
            border-radius: 999px;
            border: 1px solid rgba(148, 163, 184, 0.6);
            color: #e5e7eb;
            background: rgba(15, 23, 42, 0.9);
            transition: 0.16s ease;
            gap: 4px;
        }

        .role-pill span::before {
            content: "";
            width: 7px;
            height: 7px;
            border-radius: 999px;
            border: 1px solid rgba(148, 163, 184, 0.8);
            background: transparent;
        }

        .role-pill input:checked + span {
            background: radial-gradient(circle at 20% 0, #60a5fa, #1d4ed8);
            border-color: transparent;
            box-shadow: 0 10px 25px rgba(37, 99, 235, 0.6);
        }

        .role-pill input:checked + span::before {
            background: #bbf7d0;
            border-color: transparent;
        }

        /* Alerts */
        .msg {
            display: flex;
            align-items: flex-start;
            gap: 8px;
            padding: 9px 10px;
            border-radius: 11px;
            font-size: 12px;
            line-height: 1.5;
            margin-bottom: 12px;
        }

        .msg-icon {
            font-size: 14px;
            margin-top: 1px;
        }

        .msg-success {
            background: rgba(34, 197, 94, 0.12);
            border: 1px solid rgba(34, 197, 94, 0.5);
            color: #bbf7d0;
        }

        .msg-error {
            background: rgba(248, 113, 113, 0.12);
            border: 1px solid rgba(248, 113, 113, 0.6);
            color: #fecaca;
        }

        .password-hint {
            font-size: 11px;
            color: #9ca3af;
            margin-top: 4px;
        }

        button[type="submit"] {
            width: 100%;
            margin-top: 8px;
            border: none;
            outline: none;
            cursor: pointer;
            border-radius: 999px;
            padding: 11px 0;
            font-size: 14px;
            font-weight: 600;
            letter-spacing: 0.02em;
            background: linear-gradient(135deg, #2563eb, #1d4ed8);
            color: #f9fafb;
            box-shadow: 0 18px 40px rgba(37, 99, 235, 0.6);
            transition: transform 0.12s ease, box-shadow 0.12s ease, filter 0.1s ease;
        }

        button[type="submit"]:hover {
            transform: translateY(-1px);
            box-shadow: 0 22px 55px rgba(37, 99, 235, 0.7);
            filter: brightness(1.04);
        }

        button[type="submit"]:active {
            transform: translateY(0);
            box-shadow: 0 14px 35px rgba(37, 99, 235, 0.6);
            filter: brightness(0.98);
        }

        .footer-link {
            margin-top: 14px;
            font-size: 12px;
            color: #9ca3af;
            text-align: center;
        }

        .footer-link a {
            color: #bfdbfe;
            text-decoration: none;
            font-weight: 500;
        }

        .footer-link a:hover {
            text-decoration: underline;
        }

        @media (max-width: 880px) {
            .page-wrap {
                flex-direction: column;
                min-height: unset;
                padding: 18px;
                border-radius: 28px;
            }
            .left-panel {
                padding-bottom: 10px;
            }
            .headline {
                margin-top: 28px;
            }
            .left-footer {
                display: none;
            }
        }

        @media (max-width: 640px) {
            .page-wrap {
                padding: 16px;
            }
            .left-panel {
                display: none;
            }
            .right-panel {
                padding: 0;
            }
            .form-card {
                max-width: 100%;
            }
        }
    </style>
</head>
<body>

<div class="page-wrap">
    <!-- LEFT PANEL / BRANDING -->
    <div class="left-panel">
        <div>
            <div class="brand-row">
                <div class="brand-logo">
                    CV
                </div>
                <div>
                    <div class="brand-text-title">Cocovalley</div>
                    <div class="brand-text-sub">Operations Portal</div>
                </div>
            </div>

            <div class="headline">
                <div class="pill">
                    <span class="pill-dot"></span>
                    Admin • Staff Access
                </div>
                <h1>Give your team a secure way to run the park.</h1>
                <p>
                    Create admin and staff accounts to manage reservations, payments,
                    notifications, and day-to-day operations of Cocovalley Waterpark.
                </p>
            </div>
        </div>

        <div class="left-footer">
            “A seamless guest experience starts with a reliable back office.”
        </div>
    </div>

    <!-- RIGHT PANEL / FORM -->
    <div class="right-panel">
        <div class="form-card">
            <div class="form-header">
                <div class="badge-mini">
                    <span class="badge-mini-dot"></span>
                    New internal user
                </div>
                <div class="form-title">Create Cocovalley account</div>
                <div class="form-subtitle">
                    Restricted for Admin and Staff roles only.
                </div>
            </div>

            <?php if ($success): ?>
                <div class="msg msg-success">
                    <div class="msg-icon">✔</div>
                    <div><?php echo e($success); ?></div>
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="msg msg-error">
                    <div class="msg-icon">!</div>
                    <div><?php echo e($error); ?></div>
                </div>
            <?php endif; ?>

            <form method="POST" autocomplete="off">
                <div class="form-grid">
                    <div class="group">
                        <label for="first_name">First name</label>
                        <div class="field">
                            <input id="first_name" name="first_name" type="text"
                                   placeholder="Juan"
                                   value="<?php echo e($first ?? ''); ?>" required>
                        </div>
                    </div>
                    <div class="group">
                        <label for="last_name">Last name</label>
                        <div class="field">
                            <input id="last_name" name="last_name" type="text"
                                   placeholder="Dela Cruz"
                                   value="<?php echo e($last ?? ''); ?>" required>
                        </div>
                    </div>
                </div>

                <div class="group">
                    <label for="email">Work email</label>
                    <div class="field">
                        <input id="email" name="email" type="email"
                               placeholder="name@cocovalley.local"
                               value="<?php echo e($email ?? ''); ?>" required>
                    </div>
                </div>

                <div class="form-grid">
                    <div class="group">
                        <label for="password">Password</label>
                        <div class="field">
                            <input id="password" name="password" type="password"
                                   placeholder="Minimum 6 characters" required>
                        </div>
                    </div>
                    <div class="group">
                        <label for="confirm_password">Confirm password</label>
                        <div class="field">
                            <input id="confirm_password" name="confirm_password" type="password"
                                   placeholder="Re-type password" required>
                        </div>
                    </div>
                </div>
                <div class="password-hint">
                    Use a strong password you don’t reuse elsewhere.
                </div>

                <div class="role-group">
                    <span class="role-label">Role</span>
                    <div class="role-pills">
                        <label class="role-pill">
                            <input type="radio" name="role" value="admin"
                                <?php echo (isset($role) && $role === 'admin') ? 'checked' : (!isset($role) ? 'checked' : ''); ?>>
                            <span>Admin</span>
                        </label>
                        <label class="role-pill">
                            <input type="radio" name="role" value="staff"
                                <?php echo (isset($role) && $role === 'staff') ? 'checked' : ''; ?>>
                            <span>Staff</span>
                        </label>
                    </div>
                </div>

                <button type="submit">Create account</button>

                <div class="footer-link">
                    Already have access?
                    <a href="admin-login.php">Sign in to Cocovalley</a>
                </div>
            </form>
        </div>
    </div>
</div>

</body>
</html>
