<?php
// ==============================
// SEND TEST EMAIL PAGE
// ==============================
session_start();

// OPTIONAL: only admin can test
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin','staff'])) {
    header("Location: admin-login.php");
    exit;
}

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Correct PHPMailer paths
require_once __DIR__ . "/../includes/PHPMailer/src/Exception.php";
require_once __DIR__ . "/../includes/PHPMailer/src/PHPMailer.php";
require_once __DIR__ . "/../includes/PHPMailer/src/SMTP.php";

$sentMessage = "";

// ---------- SEND BUTTON PRESSED ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $emailTo = trim($_POST['email']);
    $templateType = trim($_POST['template']);

    if (!filter_var($emailTo, FILTER_VALIDATE_EMAIL)) {
        $sentMessage = "<p style='color:red;'>Invalid email address.</p>";
    } else {

        // Map template to file
        $templates = [
            "reservation_approved"   => "reservation_approved.html",
            "reservation_cancelled"  => "reservation_cancelled.html",
            "payment_approved"       => "payment_approved.html",
            "payment_cancelled"      => "payment_cancelled.html",
            "payment_rejected"       => "payment_rejected.html",
            "reset_password"         => "reset_password.html",
            "staff_welcome"          => "staff_welcome.html"
        ];

        $selectedFile = $templates[$templateType] ?? "";

        // CORRECT TEMPLATE FOLDER (very important)
        $templatePath = __DIR__ . "/../email_templates/" . $selectedFile;

        if ($selectedFile !== "" && file_exists($templatePath)) {

            // Load HTML template
            $html = file_get_contents($templatePath);

            // Replace placeholders with sample test data
            $html = str_replace(
                ["{{NAME}}", "{{REASON}}", "{{USERNAME}}", "{{TEMP_PASSWORD}}", "{{ROLE}}"],
                ["Test User", "Sample test reason", "test@example.com", "Temp12345", "Admin"],
                $html
            );

            // SEND EMAIL
            $mail = new PHPMailer(true);

            try {
                $mail->isSMTP();
                $mail->Host       = "smtp.gmail.com";
                $mail->SMTPAuth   = true;
                $mail->Username   = "daynielsheerinahh@gmail.com";
                $mail->Password   = "mmeegsvatkpizwhr"; // Gmail App Password
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
                $mail->Port       = 465;

                $mail->setFrom("daynielsheerinahh@gmail.com", "Cocovalley Waterpark");
                $mail->addAddress($emailTo);

                $mail->isHTML(true);
                $mail->Subject = "Test Email – " . ucfirst(str_replace("_"," ",$templateType));
                $mail->Body    = $html;

                $mail->send();
                $sentMessage = "<p style='color:green;'>✅ Test email sent to <strong>$emailTo</strong>.</p>";

            } catch (Exception $e) {
                $sentMessage = "<p style='color:red;'>❌ Failed to send: {$mail->ErrorInfo}</p>";
            }

        } else {
            $sentMessage = "<p style='color:red;'>❌ Template file not found in /email_templates/</p>";
        }
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Send Test Email</title>
    <style>
        body {
            background:#f4f4f4;
            font-family: Arial, sans-serif;
            padding:40px;
        }
        .container {
            max-width:600px;
            margin:0 auto;
            background:white;
            padding:30px;
            border-radius:10px;
            box-shadow:0 4px 20px rgba(0,0,0,0.1);
        }
        input, select {
            width:100%;
            padding:12px;
            margin:10px 0;
            border-radius:6px;
            border:1px solid #ccc;
            font-size:15px;
        }
        button {
            background:#111827;
            color:white;
            padding:12px 18px;
            width:100%;
            border:none;
            border-radius:6px;
            font-size:16px;
            cursor:pointer;
            margin-top:15px;
        }
        button:hover { opacity:0.85; }
    </style>
</head>
<body>

<div class="container">
    <h2>📨 Send Test Email</h2>
    <p>Choose a template and send it to yourself.</p>

    <?= $sentMessage ?>

    <form method="POST">
        <label><strong>Send To Email:</strong></label>
        <input type="email" name="email" placeholder="your-email@gmail.com" required>

        <label><strong>Select Template:</strong></label>
        <select name="template" required>
            <option value="">-- Select --</option>
            <option value="reservation_approved">Reservation Approved</option>
            <option value="reservation_cancelled">Reservation Cancelled</option>
            <option value="payment_approved">Payment Approved</option>
            <option value="payment_cancelled">Payment Cancelled</option>
            <option value="payment_rejected">Payment Rejected</option>
            <option value="reset_password">Reset Password</option>
            <option value="staff_welcome">Staff Welcome</option>
        </select>

        <button type="submit">Send Test Email</button>
    </form>
</div>

</body>
</html>
x