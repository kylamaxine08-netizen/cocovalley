<?php
// customer-payment-upload.php
declare(strict_types=1);
session_start();

require_once '../admin/handlers/db_connect.php';
require_once '../admin/handlers/notify.php';

// ----------------------------------------
// 🔐 CUSTOMER ONLY
// ----------------------------------------
if (!isset($_SESSION['customer_id']) || ($_SESSION['role'] ?? '') !== 'customer') {
    header("Location: customer-login.php");
    exit;
}

$customer_id    = (int) $_SESSION['customer_id'];
$customer_name  = $_SESSION['name']  ?? 'Customer';
$customer_email = $_SESSION['email'] ?? '';

// ----------------------------------------
// 📝 FORM DATA
// ----------------------------------------
$reservation_id = (int) ($_POST['reservation_id'] ?? 0);
$amount         = (float) ($_POST['amount'] ?? 0);

if ($reservation_id <= 0 || $amount <= 0) {
    die("Invalid payment data.");
}

// ----------------------------------------
// 📌 VERIFY RESERVATION
// ----------------------------------------
$stmt = $conn->prepare("
    SELECT id, code, status, total_paid, payment_status
    FROM reservations
    WHERE id = ? AND customer_id = ?
    LIMIT 1
");
$stmt->bind_param("ii", $reservation_id, $customer_id);
$stmt->execute();
$res = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$res) {
    die("Reservation not found.");
}

$code          = $res['code'];
$current_paid  = (float)$res['total_paid'];
$status        = $res['status'];

// ----------------------------------------
// ❌ Prevent payment upload if already cancelled or approved
// ----------------------------------------
if ($status === 'cancelled') {
    die("This reservation is already cancelled.");
}

if ($res['payment_status'] === 'approved') {
    die("Payment already approved. You cannot upload another proof.");
}

// ----------------------------------------
// 📸 PROOF IMAGE UPLOAD
// ----------------------------------------
if (!isset($_FILES['proof_image']) || $_FILES['proof_image']['error'] !== UPLOAD_ERR_OK) {
    die("Please upload a valid proof image.");
}

$allowed_ext = ['jpg','jpeg','png'];
$ext = strtolower(pathinfo($_FILES['proof_image']['name'], PATHINFO_EXTENSION));

if (!in_array($ext, $allowed_ext)) {
    die("Invalid file type. Allowed: JPG, JPEG, PNG.");
}

$uploadDir = '../admin/uploads/';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0777, true);
}

$fileName = 'PAY-' . $code . '-' . time() . '.' . $ext;
$targetPath = $uploadDir . $fileName;

if (!move_uploaded_file($_FILES['proof_image']['tmp_name'], $targetPath)) {
    die("Failed to save uploaded file.");
}

// ----------------------------------------
// 💾 INSERT PAYMENT RECORD
// ----------------------------------------
$method          = "GCash";
$method_option   = 0;
$payment_status  = "pending";
$payment_percent = 0;
$status_label    = "pending";

$pay = $conn->prepare("
    INSERT INTO payments (
        reservation_id,
        amount,
        method,
        method_option,
        proof_image,
        payment_status,
        payment_percent,
        status
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
");

$pay->bind_param(
    "idsssdss",
    $reservation_id,
    $amount,
    $method,
    $method_option,
    $fileName,
    $payment_status,
    $payment_percent,
    $status_label
);

$pay->execute();
$pay_id = (int)$pay->insert_id;
$pay->close();

// ----------------------------------------
// 🔄 UPDATE RESERVATION PAYMENT TOTAL
// ----------------------------------------
$new_total_paid = $current_paid + $amount;
$now = date('Y-m-d H:i:s');

$up = $conn->prepare("
    UPDATE reservations
    SET total_paid = ?, last_payment_date = ?, payment_status = 'pending'
    WHERE id = ?
");
$up->bind_param("dsi", $new_total_paid, $now, $reservation_id);
$up->execute();
$up->close();

// ----------------------------------------
// 🔔 NOTIFICATIONS
// ----------------------------------------
sendNotification(
    'admin',
    'Payment Proof Submitted',
    "$customer_name uploaded a GCash proof for reservation $code.",
    'payment',
    'Customer',
    'admin-payment-verification.php'
);

sendNotification(
    'all_staff',
    'Payment Proof Submitted',
    "$customer_name uploaded a GCash proof for reservation $code.",
    'payment',
    'Customer',
    'staff-payment-verification.php'
);

if ($customer_email) {
    sendNotification(
        $customer_email,
        'Payment Submitted',
        "We received your payment proof for reservation $code. It is now pending verification.",
        'payment',
        'System',
        'customer-dashboard.php'
    );
}

// ----------------------------------------
// REDIRECT
// ----------------------------------------
header("Location: customer-dashboard.php?payment=1");
exit;
