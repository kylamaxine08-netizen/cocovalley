<?php
// ==========================================================
//  Cocovalley – CUSTOMER CANCEL RESERVATION
//  Fully updated + Notification integrated + Safe checks
// ==========================================================
declare(strict_types=1);
session_start();

require_once '../admin/handlers/db_connect.php';
require_once '../admin/handlers/notify.php';

/* ==========================================================
   ACCESS CHECK – CUSTOMER ONLY
========================================================== */
if (!isset($_SESSION['customer_id']) || ($_SESSION['role'] ?? '') !== 'customer') {
    header("Location: customer-login.php");
    exit;
}

$customer_id    = (int)$_SESSION['customer_id'];
$customer_name  = $_SESSION['first_name'] . " " . $_SESSION['last_name'];
$customer_email = $_SESSION['email'] ?? '';

/* ==========================================================
   INPUTS
========================================================== */
$reservation_id = isset($_POST['reservation_id']) ? (int)$_POST['reservation_id'] : 0;
$code           = trim($_POST['code'] ?? '');

if (!$reservation_id && $code === '') {
    die("Missing reservation identifier.");
}

/* ==========================================================
   LOAD RESERVATION OWNED BY THIS CUSTOMER
========================================================== */

if ($reservation_id) {
    $stmt = $conn->prepare("
        SELECT id, code, status, lock_cancel, cancellation_deadline
        FROM reservations
        WHERE id = ? AND customer_id = ?
        LIMIT 1
    ");
    $stmt->bind_param("ii", $reservation_id, $customer_id);
} else {
    $stmt = $conn->prepare("
        SELECT id, code, status, lock_cancel, cancellation_deadline
        FROM reservations
        WHERE code = ? AND customer_id = ?
        LIMIT 1
    ");
    $stmt->bind_param("si", $code, $customer_id);
}

$stmt->execute();
$res = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$res) {
    die("Reservation not found.");
}

if ($res['status'] === 'cancelled') {
    die("Reservation already cancelled.");
}

if ((int)$res['lock_cancel'] === 1) {
    die("This reservation can no longer be cancelled.");
}

if (!empty($res['cancellation_deadline']) && strtotime($res['cancellation_deadline']) < time()) {
    die("Cancellation deadline has passed.");
}

$resId = (int)$res['id'];
$code  = $res['code'];

/* ==========================================================
   UPDATE STATUS → CANCELLED
========================================================== */
$now = date('Y-m-d H:i:s');

$up = $conn->prepare("
    UPDATE reservations
    SET status = 'cancelled',
        cancelled_at = ?
    WHERE id = ?
");
$up->bind_param("si", $now, $resId);
$up->execute();
$up->close();

/* ==========================================================
   SEND NOTIFICATIONS
========================================================== */

// 🔔 ADMIN
sendNotification(
    'admin',
    'Reservation Cancelled',
    "$customer_name cancelled reservation $code.",
    'reservation',
    'Customer',
    'admin-reservation-list.php'
);

// 🔔 STAFF
sendNotification(
    'all_staff',
    'Reservation Cancelled',
    "$customer_name cancelled reservation $code.",
    'reservation',
    'Customer',
    'staff-reservation-list.php'
);

// 🔔 CUSTOMER (self copy)
if ($customer_email !== '') {
    sendNotification(
        $customer_email,
        'Reservation Cancelled',
        "Your reservation ($code) has been successfully cancelled.",
        'reservation',
        'System',
        'customer-dashboard.php'
    );
}

/* ==========================================================
   REDIRECT
========================================================== */
header("Location: customer-dashboard.php?cancel=1");
exit;
