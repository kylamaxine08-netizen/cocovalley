<?php
// customer-payment-process.php
declare(strict_types=1);
session_start();

if (!isset($_SESSION['customer_id']) || ($_SESSION['role'] ?? '') !== 'customer') {
    header("Location: customer-login.php");
    exit;
}

$_SESSION['pay_reservation_id'] = $_POST['reservation_id'] ?? 0;
$_SESSION['pay_amount']        = $_POST['amount'] ?? 0;

header("Location: customer-payment-upload.php");
exit;
