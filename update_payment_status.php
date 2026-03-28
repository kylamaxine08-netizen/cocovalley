<?php
require_once __DIR__ . '/db_connect.php';
header('Content-Type: application/json');
session_start();

$data = json_decode(file_get_contents("php://input"), true);
$id      = $data['id'] ?? null;
$res_id  = $data['reservation_id'] ?? null;
$status  = strtolower(trim($data['status'] ?? ''));

// ============================================================
// 🧩 Validate Input
// ============================================================
if (!$id || !$res_id || !$status) {
  echo json_encode(['success' => false, 'message' => 'Missing or invalid data.']);
  exit;
}

// ✅ Determine current user role
$role = strtolower($_SESSION['role'] ?? 'admin');
$approvedBy = ($role === 'staff') ? 'staff' : 'admin';
$approvedRole = $approvedBy; // same value but stored separately for tracking
$verifiedBy = $_SESSION['user_id'] ?? 0;

try {
  $conn->begin_transaction();

  // ============================================================
  // ✅ 1. Fetch reservation total & total paid
  // ============================================================
  $fetch = $conn->prepare("
    SELECT 
      r.total_price,
      COALESCE(SUM(p.amount), 0) AS total_paid
    FROM reservations r
    LEFT JOIN payments p ON r.id = p.reservation_id
    WHERE r.id = ?
    GROUP BY r.id
  ");
  $fetch->bind_param("i", $res_id);
  $fetch->execute();
  $data = $fetch->get_result()->fetch_assoc();
  $fetch->close();

  $total_price = (float)($data['total_price'] ?? 0);
  $total_paid  = (float)($data['total_paid'] ?? 0);

  // ============================================================
  // ✅ 2. Compute payment percent
  // ============================================================
  $payment_percent = ($total_price > 0)
    ? min(($total_paid / $total_price) * 100, 100)
    : 0;

  // ============================================================
  // ✅ 3. Determine payment status
  // ============================================================
  if ($payment_percent >= 100) {
    $payment_status = 'Fully Paid';
  } elseif ($payment_percent >= 50) {
    $payment_status = 'Partially Paid';
  } else {
    $payment_status = 'Unpaid';
  }

  // ============================================================
  // ✅ 4. Update payments table
  // ============================================================
  if ($status === 'approved') {
    $stmt1 = $conn->prepare("
      UPDATE payments 
      SET 
        status = 'approved',
        payment_status = ?,
        payment_percent = ?,
        verified_by = ?,
        approved_by = ?,
        approved_role = ?,
        updated_at = NOW()
      WHERE id = ?
    ");
    $stmt1->bind_param("sdsssi", $payment_status, $payment_percent, $verifiedBy, $approvedBy, $approvedRole, $id);
  } else {
    $stmt1 = $conn->prepare("
      UPDATE payments 
      SET 
        status = 'cancelled',
        payment_status = 'Unpaid',
        payment_percent = 0,
        approved_by = NULL,
        approved_role = NULL,
        updated_at = NOW()
      WHERE id = ?
    ");
    $stmt1->bind_param("i", $id);
  }
  $stmt1->execute();

  // ============================================================
  // ✅ 5. Update reservation table
  // ============================================================
  if ($status === 'approved') {
    $stmt2 = $conn->prepare("
      UPDATE reservations
      SET 
        status = 'approved',
        approved_by = ?,
        approved_role = ?,
        payment_status = ?,
        payment_percent = ?,
        approved_date = NOW(),
        updated_at = NOW()
      WHERE id = ?
    ");
    $stmt2->bind_param("sssdi", $approvedBy, $approvedRole, $payment_status, $payment_percent, $res_id);
  } else {
    $stmt2 = $conn->prepare("
      UPDATE reservations
      SET 
        status = 'cancelled',
        payment_status = 'Unpaid',
        payment_percent = 0,
        approved_by = NULL,
        approved_role = NULL,
        updated_at = NOW()
      WHERE id = ?
    ");
    $stmt2->bind_param("i", $res_id);
  }
  $stmt2->execute();

  // ============================================================
  // ✅ 6. Commit Changes
  // ============================================================
  $conn->commit();

  echo json_encode([
    'success' => true,
    'message' => "Payment status updated successfully.",
    'percent' => $payment_percent,
    'payment_status' => $payment_status
  ]);
} catch (Exception $e) {
  $conn->rollback();
  echo json_encode(['success' => false, 'message' => 'Transaction failed: ' . $e->getMessage()]);
}

$conn->close();
?>
