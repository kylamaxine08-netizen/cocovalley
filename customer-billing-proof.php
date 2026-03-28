<?php
session_start();
require_once '../admin/handlers/db_connect.php';

// ✅ Only logged-in customers can view
if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'customer') {
  header('Location: ../login.php');
  exit;
}

$payment_id = intval($_GET['payment_id'] ?? 0);
if ($payment_id <= 0) die('Invalid Payment ID.');

// ✅ Fetch payment and reservation details
$stmt = $conn->prepare("
  SELECT 
    p.id AS payment_id, p.amount, p.method_option, p.status, p.proof_image, p.created_at,
    r.id AS reservation_id, r.package, r.type, r.start_date, r.time_slot, r.pax, r.total_price,
    u.id AS user_id, CONCAT(u.first_name, ' ', u.last_name) AS full_name, u.email
  FROM payments p
  LEFT JOIN reservations r ON p.reservation_id = r.id
  LEFT JOIN users u ON u.id = r.customer_id
  WHERE p.id = ? AND u.id = ?
");
$stmt->bind_param('ii', $payment_id, $_SESSION['user_id']);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$row) die('Receipt not found or not authorized.');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Billing Proof - Cocovalley</title>
<style>
body {
  font-family: "Segoe UI", sans-serif;
  background: linear-gradient(135deg,#f2f6fb,#e8f5f3);
  padding: 40px;
  color: #0f172a;
}
.receipt {
  max-width: 700px;
  background: #fff;
  margin: auto;
  border-radius: 14px;
  box-shadow: 0 6px 20px rgba(0,0,0,0.08);
  padding: 30px 40px;
  border-top: 6px solid #004d40;
}
.header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  border-bottom: 2px dashed #e0e7ef;
  padding-bottom: 10px;
  margin-bottom: 15px;
}
.header img {
  width: 70px;
  height: 70px;
  border-radius: 10px;
  object-fit: cover;
}
.header .title {
  flex: 1;
  margin-left: 15px;
}
.header .title h2 {
  color: #004d40;
  margin: 0;
  font-size: 1.4rem;
}
.header .title small {
  color: #64748b;
}
.badge {
  background: #10b981;
  color: white;
  font-weight: 700;
  padding: 5px 10px;
  border-radius: 8px;
  text-transform: uppercase;
}
.badge.cancelled {
  background: #ef4444;
}
.info {
  margin-top: 20px;
}
.info .row {
  display: flex;
  justify-content: space-between;
  padding: 6px 0;
  border-bottom: 1px solid #e5e7eb;
}
.info .label {
  font-weight: 600;
  color: #004d40;
}
.info .value {
  font-weight: 500;
  color: #1e293b;
}
.amount-box {
  text-align: right;
  margin-top: 25px;
}
.amount-box h3 {
  color: #004d40;
  margin: 0;
}
.amount-box .total {
  font-size: 1.4rem;
  font-weight: 700;
  color: #004d40;
}

/* ✅ Close button styles */
.actions {
  display: flex;
  justify-content: right;
  margin-top: 15px;
}
button.close {
  background: #d30000ff;
  color: #ffffffff;
  border: none;
  border-radius: 8px;
  font-weight: 600;
  font-size: 0.9rem;
  cursor: pointer;
  padding: 5px 20px;
  transition: 0.25s;
}
button.close:hover {
  transform: translateY(-2px);
  opacity: 0.9;
}

.footer {
  text-align: center;
  margin-top: 25px;
  color: #64748b;
  font-size: .9rem;
}
.proof img {
  margin-top: 20px;
  border-radius: 10px;
  max-width: 320px;
  box-shadow: 0 4px 12px rgba(0,0,0,0.1);
}
</style>
</head>
<body>
  <div class="receipt">
    <div class="header">
      <img src="logo.jpg" alt="Cocovalley Logo">
      <div class="title">
        <h2>Cocovalley Richnez Waterpark</h2>
        <small>315 Molino - Paliparan Rd, Dasmariñas, Cavite</small><br>
        <small>Phone: 0921 937 0908</small>
      </div>
      <div class="badge <?= ($row['status'] === 'cancelled') ? 'cancelled' : '' ?>">
        <?= strtoupper($row['status']) ?>
      </div>
    </div>

    <div class="info">
      <div class="row"><span class="label">Payment Method:</span><span class="value"><?= htmlspecialchars($row['method'] ?? 'GCash') ?></span></div>
      <div class="row"><span class="label">Amount:</span><span class="value"><?= htmlspecialchars($row['method_option'] ?? '100%') ?></span></div>
      <div class="row"><span class="label">Paid At:</span>
        <span class="value">
          <?= date('M d, Y', strtotime($row['created_at'])) ?>
          <?= ($row['time_slot'] === 'Day') ? ' Day 08:00–17:00' : (($row['time_slot'] === 'Night') ? ' Night 18:00–06:00' : '') ?>
        </span>
      </div>
      <div class="row"><span class="label">Category:</span><span class="value"><?= htmlspecialchars($row['type'] ?? '—') ?></span></div>
      <div class="row"><span class="label">Room Type:</span><span class="value"><?= htmlspecialchars($row['package'] ?? '—') ?></span></div>
      <div class="row"><span class="label">Date Reserved:</span><span class="value"><?= date('M d, Y', strtotime($row['start_date'])) ?></span></div>
      <div class="row"><span class="label">Time Slot:</span><span class="value"><?= htmlspecialchars($row['time_slot'] ?? '—') ?></span></div>
      <div class="row"><span class="label">Pax:</span><span class="value"><?= htmlspecialchars($row['pax'] ?? '—') ?></span></div>
      <div class="row"><span class="label">Full Name:</span><span class="value"><?= htmlspecialchars($row['full_name'] ?? '—') ?></span></div>
      <div class="row"><span class="label">Email:</span><span class="value"><?= htmlspecialchars($row['email'] ?? '—') ?></span></div>
    </div>

    <div class="amount-box">
      <h3>Total Amount Paid</h3>
      <div class="total">₱<?= number_format($row['amount'], 2) ?></div>
    </div>

    <div class="footer">
      <p>Thank you for choosing <b>Cocovalley Richnez Waterpark</b>!</p>
      <p>We look forward to seeing you soon.</p>
    </div>
<!-- ✅ Close button -->
    <div class="actions">
      <button type="button" class="close" onclick="window.location.href='customer-notification.php'">
        ✖ Close
      </button>
    </div>
  </div>
</body>
</html>
