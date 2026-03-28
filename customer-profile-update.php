<?php
// customer-profile-update.php
declare(strict_types=1);
session_start();
require_once '../admin/handlers/db_connect.php';
require_once '../admin/handlers/notify.php';

if (!isset($_SESSION['customer_id']) || ($_SESSION['role'] ?? '') !== 'customer') {
    header("Location: customer-login.php");
    exit;
}

$customer_id    = (int) $_SESSION['customer_id'];
$customer_email = $_SESSION['email'] ?? '';
$customer_name  = $_SESSION['name']  ?? 'Customer';

function p(string $k): string {
    return isset($_POST[$k]) ? trim((string)$_POST[$k]) : '';
}

$first_name = p('first_name');
$last_name  = p('last_name');
$email      = p('email');
$phone      = p('phone');
$gender     = p('gender');
$birthdate  = p('birthdate');
$new_pass   = p('new_password');
$confirm    = p('confirm_password');

if (!$first_name || !$last_name || !$email) {
    die("Missing required fields.");
}

$conn->begin_transaction();

try {
    if ($new_pass !== '') {
        if ($new_pass !== $confirm) {
            throw new Exception("Passwords do not match.");
        }
        $hash = password_hash($new_pass, PASSWORD_DEFAULT);

        $stmt = $conn->prepare("
            UPDATE users
            SET first_name = ?, last_name = ?, email = ?, phone = ?, gender = ?, birthdate = ?, password_hash = ?
            WHERE id = ?
        ");
        $stmt->bind_param("sssssssi",
            $first_name, $last_name, $email, $phone, $gender, $birthdate, $hash, $customer_id
        );
    } else {
        $stmt = $conn->prepare("
            UPDATE users
            SET first_name = ?, last_name = ?, email = ?, phone = ?, gender = ?, birthdate = ?
            WHERE id = ?
        ");
        $stmt->bind_param("ssssssi",
            $first_name, $last_name, $email, $phone, $gender, $birthdate, $customer_id
        );
    }

    $stmt->execute();
    $stmt->close();

    // update session
    $_SESSION['email'] = $email;
    $_SESSION['name']  = $first_name . ' ' . $last_name;

    // notify
    if ($email) {
        sendNotification(
            $email,
            'Profile Updated',
            'Your profile details have been updated.',
            'system',
            'System',
            'customer-profile.php'
        );
    }

    $conn->commit();
    header("Location: customer-profile.php?updated=1");
    exit;

} catch (Throwable $e) {
    $conn->rollback();
    die("Error updating profile: " . $e->getMessage());
}
