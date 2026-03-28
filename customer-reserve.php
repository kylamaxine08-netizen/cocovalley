<?php
// customer-reserve.php
declare(strict_types=1);
session_start();

require_once '../admin/handlers/db_connect.php';
require_once '../admin/handlers/notify.php';

// 🔒 CUSTOMER ONLY
if (!isset($_SESSION['customer_id']) || ($_SESSION['role'] ?? '') !== 'customer') {
    header("Location: customer-login.php");
    exit;
}

$customer_id    = (int) $_SESSION['customer_id'];
$customer_name  = $_SESSION['name']  ?? 'Customer';
$customer_email = $_SESSION['email'] ?? '';

// Helper
function safe(string $key, string $default = ''): string {
    return isset($_POST[$key]) ? trim((string)$_POST[$key]) : $default;
}

/* ============================================================
   GET FORM DATA
============================================================ */
$type           = safe('type');              // Cottage | Room | Event
$package        = safe('package');
$cottage_number = (int) ($_POST['cottage_number'] ?? 0);
$pax            = (int) ($_POST['pax'] ?? 1);
$time_slot      = safe('time_slot');
$start_date     = safe('start_date');
$end_date       = safe('end_date');          // may be blank for cottages
$total_price    = (float) ($_POST['total_price'] ?? 0);
$payment_percent= (float) ($_POST['payment_percent'] ?? 0);

if (!$type || !$package || !$start_date) {
    die("Missing required reservation data.");
}

/* ============================================================
   DOUBLE BOOKING RULE – 1 ACTIVE ONLY
============================================================ */
$check = $conn->prepare("
    SELECT COUNT(*) AS cnt
    FROM reservations
    WHERE customer_id = ?
      AND status IN ('pending','approved')
");
$check->bind_param("i", $customer_id);
$check->execute();
$resCount = $check->get_result()->fetch_assoc()['cnt'] ?? 0;
$check->close();

if ($resCount > 0) {
    echo "You already have an active reservation.";
    exit;
}

/* ============================================================
   GENERATE RESERVATION CODE
============================================================ */
$code = 'CVR-' . date('Ymd-His') . '-' . mt_rand(1000, 9999);

/* ============================================================
   INSERT RESERVATION
============================================================ */
$stmt = $conn->prepare("
    INSERT INTO reservations (
        customer_id,
        code,
        customer_name,
        type,
        package,
        cottage_number,
        pax,
        time_slot,
        total_price,
        payment_status,
        payment_percent,
        start_date,
        end_date,
        status
    ) VALUES (?,?,?,?,?,?,?,?,?,'pending',?,?,?, 'pending')
");

$paymentPercentStr = number_format($payment_percent, 2, '.', '');

$stmt->bind_param(
    "issssiisdsss",
    $customer_id,
    $code,
    $customer_name,
    $type,
    $package,
    $cottage_number,
    $pax,
    $time_slot,
    $total_price,
    $paymentPercentStr,
    $start_date,
    $end_date
);

$stmt->execute();
$reservation_id = (int)$stmt->insert_id;
$stmt->close();

/* ============================================================
   SEND NOTIFICATIONS
============================================================ */
// Admin
sendNotification(
    'admin',
    'New Reservation',
    "$customer_name submitted a reservation ($package – $type).",
    'reservation',
    'Customer',
    '../admin/admin-reservation-list.php'
);

// Staff
sendNotification(
    'all_staff',
    'New Reservation',
    "$customer_name submitted a reservation ($package – $type).",
    'reservation',
    'Customer',
    '../staff/staff-reservation-list.php'
);

// Customer
if ($customer_email) {
    sendNotification(
        $customer_email,
        'Reservation Submitted',
        "Your reservation has been created. Code: $code",
        'reservation',
        'System',
        'customer-dashboard.php'
    );
}

/* ============================================================
   REDIRECT TO PAYMENT PAGE
============================================================ */
$_SESSION['reservation_code'] = $code;
header("Location: customer-payment.php?code=" . urlencode($code));
exit;
