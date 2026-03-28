<?php
// ============================================================
// ✅ Secure Session Setup
// ============================================================
if (session_status() === PHP_SESSION_NONE) {
  session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'domain' => '',
    'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'httponly' => true,
    'samesite' => 'Lax',
  ]);
  session_start();
}

require_once '../admin/handlers/db_connect.php';
header('Content-Type: application/json');

// ============================================================
// ✅ Parse JSON Input
// ============================================================
$input = json_decode(file_get_contents("php://input"), true);
if (!$input || !isset($input['id'], $input['status'])) {
  echo json_encode(["success" => false, "message" => "Invalid request data."]);
  exit;
}

$reservation_id = intval($input['id']);
$new_status = strtolower(trim($input['status']));
$valid_statuses = ['approved', 'cancelled'];

if (!in_array($new_status, $valid_statuses)) {
  echo json_encode(["success" => false, "message" => "Invalid status provided."]);
  exit;
}

// ============================================================
// ✅ Fetch Reservation + Customer Details
// ============================================================
$resQuery = $conn->prepare("
  SELECT r.id, r.customer_id, r.total_price, r.code, r.customer_name,
         u.email, u.first_name, u.last_name
  FROM reservations r
  LEFT JOIN users u ON r.customer_id = u.id
  WHERE r.id = ?
");
$resQuery->bind_param("i", $reservation_id);
$resQuery->execute();
$res = $resQuery->get_result()->fetch_assoc();
$resQuery->close();

if (!$res) {
  echo json_encode(["success" => false, "message" => "Reservation not found."]);
  exit;
}

$customer_email = $res['email'] ?? '';
$customer_name = $res['customer_name'] ?? trim(($res['first_name'] ?? '') . ' ' . ($res['last_name'] ?? ''));
$total_price = (float)($res['total_price'] ?? 0);

// ✅ Determine who approved (admin/staff)
$role = ($_SESSION['role'] ?? 'admin') === 'staff' ? 'staff' : 'admin';
$verified_by = $_SESSION['user_id'] ?? 0;

// ============================================================
// ✅ Handle APPROVAL Logic
// ============================================================
if ($new_status === 'approved') {
  try {
    $conn->begin_transaction();

    // 🔹 Compute Total Paid
    $payQuery = $conn->prepare("
      SELECT COALESCE(SUM(amount), 0) AS total_paid
      FROM payments
      WHERE reservation_id = ?
    ");
    $payQuery->bind_param("i", $reservation_id);
    $payQuery->execute();
    $paidData = $payQuery->get_result()->fetch_assoc();
    $payQuery->close();

    $total_paid = (float)($paidData['total_paid'] ?? 0);
    $percent = ($total_price > 0) ? min(($total_paid / $total_price) * 100, 100) : 0;

    // 🔹 Determine Payment Label
    if ($percent >= 100) $payment_status = 'fully paid';
    elseif ($percent >= 50) $payment_status = 'partially paid';
    else $payment_status = 'unpaid';

    // 🔹 Update Reservation
    $stmt1 = $conn->prepare("
      UPDATE reservations
      SET 
        status = 'approved',
        approved_by = ?,
        approved_role = ?,
        approved_date = NOW(),
        payment_status = ?,
        payment_percent = ?,
        updated_at = NOW()
      WHERE id = ?
    ");
    $stmt1->bind_param("sssdi", $role, $role, $payment_status, $percent, $reservation_id);
    $stmt1->execute();

    // 🔹 Update Payments
    $stmt2 = $conn->prepare("
      UPDATE payments
      SET 
        status = 'approved',
        payment_status = ?,
        payment_percent = ?,
        verified_by = ?,
        approved_by = ?,
        updated_at = NOW()
      WHERE reservation_id = ?
    ");
    $stmt2->bind_param("sdssi", $payment_status, $percent, $verified_by, $role, $reservation_id);
    $stmt2->execute();

    // 🔹 Insert Notification
    if (!empty($customer_email)) {
      $notifTitle = "Reservation Approved 🎉";
      $notifMsg = "Hi $customer_name! Your reservation (Ref: {$res['code']}) has been approved by the $role. You can now view the details in your account.";
      $notifType = "reservation";

      $notif = $conn->prepare("
        INSERT INTO notifications (customer_email, title, message, type, created_at)
        VALUES (?, ?, ?, ?, NOW())
      ");
      $notif->bind_param("ssss", $customer_email, $notifTitle, $notifMsg, $notifType);
      $notif->execute();
    }

    $conn->commit();
    echo json_encode(["success" => true, "message" => "Reservation approved successfully."]);
  } catch (Exception $e) {
    $conn->rollback();
    echo json_encode(["success" => false, "message" => "Transaction failed: " . $e->getMessage()]);
  }
  exit;
}

// ============================================================
// ✅ Handle CANCELLATION Logic
// ============================================================
if ($new_status === 'cancelled') {
  try {
    $conn->begin_transaction();

    // Cancel reservation
    $stmt1 = $conn->prepare("
      UPDATE reservations
      SET 
        status = 'cancelled',
        approved_role = NULL,
        updated_at = NOW()
      WHERE id = ?
    ");
    $stmt1->bind_param("i", $reservation_id);
    $stmt1->execute();

    // Cancel payment
    $stmt2 = $conn->prepare("
      UPDATE payments
      SET 
        status = 'cancelled',
        approved_by = NULL,
        updated_at = NOW()
      WHERE reservation_id = ?
    ");
    $stmt2->bind_param("i", $reservation_id);
    $stmt2->execute();

    // Notification
    if (!empty($customer_email)) {
      $notifTitle = "Reservation Cancelled ❌";
      $notifMsg = "Hi $customer_name, your reservation (Ref: {$res['code']}) has been cancelled. Please contact the resort if you'd like to rebook.";
      $notifType = "reservation";

      $notif = $conn->prepare("
        INSERT INTO notifications (customer_email, title, message, type, created_at)
        VALUES (?, ?, ?, ?, NOW())
      ");
      $notif->bind_param("ssss", $customer_email, $notifTitle, $notifMsg, $notifType);
      $notif->execute();
    }

    $conn->commit();
    echo json_encode(["success" => true, "message" => "Reservation cancelled successfully."]);
  } catch (Exception $e) {
    $conn->rollback();
    echo json_encode(["success" => false, "message" => "Transaction failed: " . $e->getMessage()]);
  }
  exit;
}
?>
