<?php
/* ============================================================
   🔐 SECURE SESSION
============================================================ */
ob_start(); // buffer to avoid stray output breaking JSON
ini_set('display_errors', 1);
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    session_start();
}

require_once __DIR__ . '/handlers/db_connect.php';

/* 📧 EMAIL HELPERS */
require_once __DIR__ . '/email/send_payment_approved.php';   // function sendPaymentApproved(...)
require_once __DIR__ . '/email/send_payment_cancelled.php';  // function sendPaymentCancelled(...)

/* Only admin / staff for both GET + POST */
if (empty($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin','staff'], true)) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        header('Content-Type: application/json; charset=utf-8');
        ob_clean();
        echo json_encode(['success' => false, 'error' => 'unauthorized']);
        exit;
    }
    header("Location: admin-login.php");
    exit;
}

$adminId   = (int)($_SESSION['user_id'] ?? 0);
$adminName = $_SESSION['name'] ?? "Admin";

/* ============================================================
   📌 JSON API HANDLER (POST: approve / cancel)
============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // For JSON safety, huwag mag-print ng PHP warnings
    ini_set('display_errors', 0);
    header('Content-Type: application/json; charset=utf-8');
    ob_clean();

    $action         = $_POST['action'] ?? '';
    $payment_id     = (int)($_POST['payment_id'] ?? 0);
    $reservation_id = (int)($_POST['reservation_id'] ?? 0);
    $email          = trim($_POST['email'] ?? '');

    if ($payment_id <= 0 || $reservation_id <= 0 || !in_array($action, ['approve', 'cancel'], true)) {
        echo json_encode(['success' => false, 'error' => 'invalid_parameters']);
        exit;
    }

    // Fetch reservation + customer + payment info (para sa email + conflict)
    $stmt = $conn->prepare("
        SELECT 
            r.code,
            r.type,
            r.package,
            r.start_date,
            r.end_date,
            r.time_slot,
            r.pax,
            COALESCE(wc.full_name, CONCAT(u.first_name,' ',u.last_name), r.customer_name) AS full_name,
            COALESCE(wc.customer_email, u.email, '') AS email,
            p.amount,
            p.method,
            p.method_option
        FROM reservations r
        LEFT JOIN payments p 
            ON p.id = ? 
           AND p.reservation_id = r.id
        LEFT JOIN users u ON u.id = r.customer_id
        LEFT JOIN walkin_customers wc ON wc.reservation_id = r.id
        WHERE r.id = ?
        LIMIT 1
    ");
    $stmt->bind_param("ii", $payment_id, $reservation_id);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$res) {
        echo json_encode(['success' => false, 'error' => 'reservation_not_found']);
        exit;
    }

    // Fallback email from DB kung walang galing sa POST
    if ($email === '') {
        $email = trim($res['email'] ?? '');
    }
    $fullName = $res['full_name'] ?? 'Guest';

    try {
        $conn->begin_transaction();

        /* =====================================================
           ❌ CANCEL
        ===================================================== */
        if ($action === 'cancel') {

            // Update payments
            $stmt = $conn->prepare("
                UPDATE payments 
                SET payment_status='cancelled',
                    status='cancelled',
                    verified_by=?,
                    updated_at=NOW()
                WHERE id=?
            ");
            $stmt->bind_param("ii", $adminId, $payment_id);
            $stmt->execute();
            $stmt->close();

            // Update reservations
            $stmt = $conn->prepare("
                UPDATE reservations
                SET status='cancelled',
                    payment_status='cancelled',
                    updated_at=NOW()
                WHERE id=?
            ");
            $stmt->bind_param("i", $reservation_id);
            $stmt->execute();
            $stmt->close();

            // Notification
            if ($email !== '') {
                $itemName    = "Payment Cancelled ❌";
                $message     = "Hi {$fullName}, your payment for reservation {$res['code']} has been cancelled. "
                             . "Please contact admin if you have questions.";
                $imageUrl    = null;
                $redirectUrl = "../customer/customer-billing-proof.php?payment_id=" . $payment_id;
                $type        = "payment";
                $status      = "unread";
                $popup       = 1;

                $stmt = $conn->prepare("
                    INSERT INTO notifications 
                      (email, item_name, message, image_url, redirect_url, type, status, posted_by, created_at, popup)
                    VALUES
                      (?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)
                ");
                $stmt->bind_param(
                    "sssssssii",
                    $email,
                    $itemName,
                    $message,
                    $imageUrl,
                    $redirectUrl,
                    $type,
                    $status,
                    $adminName,
                    $popup
                );
                $stmt->execute();
                $stmt->close();
            }

            // Commit DB changes
            $conn->commit();

            // 📨 Send "payment cancelled" email (best-effort)
            if ($email !== '') {
                @sendPaymentCancelled($email, $fullName);
            }

            echo json_encode([
                'success'  => true,
                'status'   => 'cancelled',
                'redirect' => 'admin-reservation-list.php'
            ]);
            exit;
        }

        /* =====================================================
           ✅ APPROVE — CHECK DATE/TIME CONFLICT (EVENT ONLY)
           NOTE:
           - Cottages / rooms have multiple units (1–10 etc.)
           - So we ONLY block double-booking for unique EVENT type.
        ===================================================== */
        $typeLower = strtolower($res['type'] ?? '');
        $isUniqueType = ($typeLower === 'event'); // only Event is unique

        if ($isUniqueType) {
            $stmt = $conn->prepare("
                SELECT id 
                FROM reservations
                WHERE type      = ?
                  AND package   = ?
                  AND start_date= ?
                  AND time_slot = ?
                  AND status    = 'approved'
                  AND id        <> ?
                LIMIT 1
            ");
            $stmt->bind_param(
                "ssssi",
                $res['type'],
                $res['package'],
                $res['start_date'],
                $res['time_slot'],
                $reservation_id
            );
            $stmt->execute();
            $stmt->store_result();

            if ($stmt->num_rows > 0) {
                $stmt->close();
                $conn->rollback();
                echo json_encode(['success' => false, 'error' => 'conflict']);
                exit;
            }
            $stmt->close();
        }

        /* =====================================================
           🟢 APPROVE PAYMENT + RESERVATION
        ===================================================== */
        // payments: update payment_status + status + verified_by
        $stmt = $conn->prepare("
            UPDATE payments
            SET payment_status='approved',
                status='approved',
                verified_by=?,
                updated_at=NOW()
            WHERE id=?
        ");
        $stmt->bind_param("ii", $adminId, $payment_id);
        $stmt->execute();
        $stmt->close();

        // reservations: set status + payment_status + approved_* fields
        $stmt = $conn->prepare("
            UPDATE reservations
            SET status='approved',
                payment_status='approved',
                approved_at=NOW(),
                approved_by=?,
                approved_date=NOW(),
                updated_at=NOW()
            WHERE id=?
        ");
        $stmt->bind_param("ii", $adminId, $reservation_id);
        $stmt->execute();
        $stmt->close();

        // Notification for approve
        if ($email !== '') {
            $itemName    = "Payment Approved ✅";
            $message     = "Hi {$fullName}, your payment for reservation {$res['code']} has been approved. "
                         . "You can now view your official billing proof.";
            $imageUrl    = null;
            $redirectUrl = "../customer/customer-billing-proof.php?payment_id=" . $payment_id;
            $type        = "payment";
            $status      = "unread";
            $popup       = 1;

            $stmt = $conn->prepare("
                INSERT INTO notifications 
                  (email, item_name, message, image_url, redirect_url, type, status, posted_by, created_at, popup)
                VALUES
                  (?, ?, ?, ?, ?, ?, ?, ?, NOW(), ?)
            ");
            $stmt->bind_param(
                "sssssssii",
                $email,
                $itemName,
                $message,
                $imageUrl,
                $redirectUrl,
                $type,
                $status,
                $adminName,
                $popup
            );
            $stmt->execute();
            $stmt->close();
        }

        // Commit DB changes
        $conn->commit();

        // 📨 Send "payment approved" email (best-effort)
        if ($email !== '') {
            $code         = $res['code'];
            $amount       = (float)($res['amount'] ?? 0);
            $method       = trim($res['method'] ?? '') ?: 'GCash';
            $option       = (int)($res['method_option'] ?? 0);
            $dateReserved = $res['start_date'] ? date('M d, Y', strtotime($res['start_date'])) : '';
            $category     = $res['type'] ?? '';
            $package      = $res['package'] ?? '';
            $timeslot     = $res['time_slot'] ?? '';
            $pax          = (int)($res['pax'] ?? 0);

            @sendPaymentApproved(
                $email,
                $fullName,
                $code,
                $amount,
                $method,
                $option,
                $dateReserved,
                $category,
                $package,
                $timeslot,
                $pax
            );
        }

        echo json_encode([
            'success'  => true,
            'status'   => 'approved',
            'redirect' => 'admin-reservation-list.php'
        ]);
        exit;

    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode([
            'success' => false,
            'error'   => 'server_error',
            'message' => $e->getMessage()
        ]);
        exit;
    }
}

/* ============================================================
   📌 BELOW THIS POINT = PURE HTML (GET REQUEST)
============================================================ */

/* ============================================================
   📌 GET PAYMENT ID
============================================================ */
$payment_id = (int)($_GET['payment_id'] ?? 0);
if ($payment_id <= 0) {
    header("Location: admin-reservation-list.php?error=invalid_payment");
    exit;
}

/* ============================================================
   📌 FETCH PAYMENT + RESERVATION DATA (for UI)
============================================================ */
$stmt = $conn->prepare("
    SELECT 
        p.id          AS payment_id,
        p.amount,
        p.method,
        p.method_option,
        p.payment_status,
        p.proof_image,
        p.created_at,

        r.id          AS reservation_id,
        r.code,
        r.customer_id,
        r.type,
        r.package,
        r.time_slot,
        r.start_date,
        r.end_date,
        r.pax,
        r.total_price,
        r.status      AS reservation_status,

        COALESCE(wc.full_name, CONCAT(u.first_name,' ',u.last_name), r.customer_name) AS full_name,
        COALESCE(wc.customer_email, u.email, '') AS email

    FROM payments p
    LEFT JOIN reservations r ON p.reservation_id = r.id
    LEFT JOIN users u        ON u.id = r.customer_id
    LEFT JOIN walkin_customers wc ON wc.reservation_id = r.id
    WHERE p.id = ?
    LIMIT 1
");
$stmt->bind_param("i", $payment_id);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$row) {
    header("Location: admin-reservation-list.php?error=payment_not_found");
    exit;
}

$reservation_id = (int)$row['reservation_id'];

/* ============================================================
   PREP UI VALUES
============================================================ */
$paymentStatus  = strtolower($row['payment_status'] ?? 'pending');
$statusText     = ucfirst($paymentStatus);
$badgeClass     = ($paymentStatus === 'approved')
                    ? 'approved'
                    : (($paymentStatus === 'cancelled') ? 'cancelled' : 'pending');

$method = trim($row['method'] ?? '');
if ($method === '') {
    $method = 'GCash';
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Billing Proof | Cocovalley</title>
<style>
:root {
  --primary: #004d99;
  --accent: #0b72d1;
  --success: #10b981;
  --danger: #ef4444;
  --muted: #64748b;
  --bg: #eef3fb;
  --white: #ffffff;
  --border: #e2e8f0;
}

/* Main Layout */
body {
  background: radial-gradient(circle at top left, #dbeafe, #eef3fb 40%, #c7d2fe 100%);
  font-family: "Inter", "Segoe UI", sans-serif;
  display: flex;
  justify-content: center;
  align-items: flex-start;
  min-height: 100vh;
  margin: 0;
  padding: 32px 12px;
}

/* Card */
.card {
  background: var(--white);
  border-radius: 18px;
  box-shadow: 0 15px 45px rgba(15, 23, 42, 0.15);
  width: 100%;
  max-width: 780px;
  padding: 26px 32px 22px;
  animation: fadeIn .3s ease;
}

/* HEADER */
.header {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  border-bottom: 1px solid var(--border);
  padding-bottom: 14px;
  margin-bottom: 20px;
}

.header-left {
  display: flex;
  align-items: center;
  gap: 14px;
}

.header-left img {
  width: 58px;
  height: 58px;
  border-radius: 18px;
  box-shadow: 0 10px 24px rgba(15,23,42,0.35);
  object-fit: cover;
}

.header-title {
  display: flex;
  flex-direction: column;
  gap: 2px;
}

.header-title h2 {
  color: #0f172a;
  font-size: 1.1rem;
  font-weight: 800;
  letter-spacing: .03em;
}

.header-title small {
  font-size: 0.8rem;
  color: var(--muted);
}

.header-right {
  text-align: right;
  display: flex;
  flex-direction: column;
  align-items: flex-end;
  gap: 4px;
}

.status-tag {
  padding: 4px 10px;
  border-radius: 999px;
  font-size: 0.78rem;
  font-weight: 700;
  text-transform: uppercase;
  color: #fff;
}

.status-tag.pending {
  background: #f97316;
}
.status-tag.approved {
  background: var(--success);
}
.status-tag.cancelled {
  background: var(--danger);
}

.header-right .label {
  font-size: 0.74rem;
  text-transform: uppercase;
  letter-spacing: 0.14em;
  color: var(--muted);
}

.header-right .code-value {
  font-size: 0.86rem;
  font-weight: 700;
  color: #0f172a;
}

/* Info Section */
.section {
  border-radius: 14px;
  border: 1px solid var(--border);
  padding: 16px 18px 10px;
  margin-bottom: 14px;
  background: linear-gradient(to bottom, #f9fbff, #ffffff);
}

.section-title {
  font-size: 0.85rem;
  font-weight: 700;
  letter-spacing: 0.12em;
  color: var(--muted);
  text-transform: uppercase;
  margin-bottom: 10px;
}

.info-row {
  display: flex;
  justify-content: space-between;
  border-bottom: 1px dashed var(--border);
  padding: 5px 0;
  font-size: 0.9rem;
}
.info-row:last-child {
  border-bottom: none;
}
.label {
  color: #1e293b;
  font-weight: 500;
}
.value {
  color: #0f172a;
  text-align: right;
}

/* Total Section */
.total {
  margin-top: 10px;
  padding-top: 10px;
  border-top: 1px solid var(--border);
  text-align: right;
}
.total b {
  font-size: 0.9rem;
  color: #1e293b;
}
.amount {
  font-size: 1.4rem;
  font-weight: 800;
  color: var(--primary);
}

/* Buttons */
.actions {
  display: flex;
  justify-content: center;
  gap: 10px;
  margin-top: 20px;
}
button {
  border: none;
  border-radius: 999px;
  font-weight: 600;
  font-size: 0.9rem;
  cursor: pointer;
  transition: 0.25s;
  padding: 9px 24px;
}

/* Button Styles */
button.close {
  background: #cbd5e1;
  color: #111827;
}
button.approve {
  background: #16a34a;
  color: #fff;
}
button.cancel {
  background: #ef4444;
  color: #fff;
}

button:hover {
  transform: translateY(-1px);
  box-shadow: 0 10px 22px rgba(15,23,42,0.18);
  opacity: 0.96;
}

/* Footer */
.footer {
  text-align: center;
  color: var(--muted);
  font-size: 0.82rem;
  margin-top: 18px;
}
.footer strong {
  color: var(--primary);
}

/* Animations */
@keyframes fadeIn {
  from { opacity: 0; transform: translateY(10px); }
  to   { opacity: 1; transform: translateY(0); }
}

/* Responsive */
@media (max-width: 640px) {
  .card {
    padding: 20px 18px 18px;
  }
  .header {
    flex-direction: column;
    align-items: flex-start;
    gap: 10px;
  }
  .header-right {
    align-items: flex-start;
  }
}
</style>
</head>
<body>
<div class="card">

  <div class="header">
    <div class="header-left">
      <img src="logo.jpg" alt="Cocovalley Logo">
      <div class="header-title">
        <h2>Cocovalley Richnez Waterpark</h2>
        <small>Address: 315 Molino - Paliparan Rd, Dasmariñas, Cavite</small>
        <small>Phone: 0921 937 0908</small>
        <small>Billing Proof Verification</small>
      </div>
    </div>

    <div class="header-right">
      <div class="status-tag <?= htmlspecialchars($badgeClass) ?>">
        <?= htmlspecialchars(strtoupper($statusText)) ?>
      </div>
      <div class="label">Reservation Code</div>
      <div class="code-value"><?= htmlspecialchars($row['code']) ?></div>
    </div>
  </div>

  <!-- PAYMENT DETAILS -->
  <div class="section">
    <div class="section-title">Payment Details</div>

    <div class="info-row">
      <span class="label">Payment Method</span>
      <span class="value"><?= htmlspecialchars($method) ?></span>
    </div>

    <div class="info-row">
      <span class="label">Payment Option</span>
      <span class="value"><?= (int)$row['method_option'] ?>%</span>
    </div>

    <div class="info-row">
      <span class="label">Amount Paid</span>
      <span class="value">₱<?= number_format((float)$row['amount'], 2) ?></span>
    </div>

    <div class="info-row">
      <span class="label">Paid At</span>
      <span class="value">
        <?= date('M d, Y • h:i A', strtotime($row['created_at'])) ?>
      </span>
    </div>
  </div>

  <!-- RESERVATION INFO -->
  <div class="section">
    <div class="section-title">Reservation Information</div>

    <div class="info-row">
      <span class="label">Category</span>
      <span class="value"><?= htmlspecialchars($row['type'] ?? '—') ?></span>
    </div>

    <div class="info-row">
      <span class="label">Package</span>
      <span class="value"><?= htmlspecialchars($row['package'] ?? '—') ?></span>
    </div>

    <div class="info-row">
      <span class="label">Date Reserved</span>
      <span class="value">
        <?= date('M d, Y', strtotime($row['start_date'])) ?>
        <?php if (!empty($row['end_date']) && $row['end_date'] !== $row['start_date']): ?>
            – <?= date('M d, Y', strtotime($row['end_date'])) ?>
        <?php endif; ?>
      </span>
    </div>

    <div class="info-row">
      <span class="label">Time Slot</span>
      <span class="value"><?= htmlspecialchars($row['time_slot']) ?></span>
    </div>

    <div class="info-row">
      <span class="label">Pax</span>
      <span class="value"><?= (int)$row['pax'] ?></span>
    </div>

    <div class="info-row">
      <span class="label">Customer</span>
      <span class="value"><?= htmlspecialchars($row['full_name']) ?></span>
    </div>

    <div class="info-row">
      <span class="label">Email</span>
      <span class="value"><?= htmlspecialchars($row['email']) ?></span>
    </div>
  </div>

  <!-- TOTAL -->
  <div class="total">
    <b>Reservation Fee Paid</b>
    <div class="amount">₱<?= number_format((float)$row['amount'], 2) ?></div>
  </div>

  <!-- ACTION BUTTONS -->
  <form id="billingForm" class="actions">
    <input type="hidden" name="payment_id" value="<?= (int)$row['payment_id'] ?>">
    <input type="hidden" name="reservation_id" value="<?= (int)$row['reservation_id'] ?>">
    <input type="hidden" name="email" value="<?= htmlspecialchars($row['email']) ?>">

    <button type="button" class="close" onclick="window.history.back()">Close</button>
    <button type="submit" data-action="approve" class="approve">✔ Approve &amp; Send</button>
    <button type="submit" data-action="cancel" class="cancel">✖ Cancel</button>
  </form>

  <div class="footer">
    Thank you for choosing <strong>Cocovalley</strong>. Please present this receipt on the day of your visit.
  </div>
</div>

<script>
document.addEventListener("DOMContentLoaded", () => {
  const form = document.getElementById("billingForm");
  let clickedAction = null;

  form.querySelectorAll("button[type='submit']").forEach(btn => {
    btn.addEventListener("click", () => {
      clickedAction = btn.getAttribute("data-action");
    });
  });

  form.addEventListener("submit", async (e) => {
    e.preventDefault();

    if (!clickedAction) {
      alert("Please click Approve or Cancel");
      return;
    }

    const formData = new FormData(form);
    formData.set("action", clickedAction);

    form.querySelectorAll("button").forEach(btn => btn.disabled = true);

    try {
      const res = await fetch("admin-send-billing-proof.php", {
        method: "POST",
        body: formData
      });

      let data;
      try {
        data = await res.json();
      } catch (jsonErr) {
        throw new Error("Invalid server response (not JSON).");
      }

      if (!data.success) {
        if (data.error === "conflict") {
          alert("❌ Conflict: Another reservation is already approved for this date & time slot (Event only).");
        } else {
          alert("❌ " + (data.message || data.error || "Something went wrong."));
        }
        form.querySelectorAll("button").forEach(btn => btn.disabled = false);
        return;
      }

      const isApprove    = (clickedAction === "approve");
      const color        = isApprove ? "#10b981" : "#ef4444";
      const icon         = isApprove ? "✔" : "✖";
      const message      = isApprove ? "Billing Proof Sent!" : "Payment Cancelled!";
      const redirectText = isApprove
          ? "Redirecting to Reservation List..."
          : "Returning to Reservation List...";

      const overlay = document.createElement("div");
      overlay.style.cssText = `
        position:fixed; inset:0; background:rgba(255,255,255,0.96);
        display:flex; flex-direction:column; justify-content:center;
        align-items:center; z-index:9999; font-family:'Segoe UI',sans-serif;
      `;
      overlay.innerHTML = `
        <div style="
          width:120px;height:120px;border-radius:50%;
          border:6px solid ${color};display:flex;
          align-items:center;justify-content:center;
          animation:pop .4s ease-out;
        ">
          <div style="font-size:60px;color:${color};animation:fadeIn .6s ease;">
            ${icon}
          </div>
        </div>
        <h2 style="margin-top:20px;font-size:22px;color:#004d99;">${message}</h2>
        <p style="color:#64748b;font-size:15px;">${redirectText}</p>
        <style>
          @keyframes pop {0%{transform:scale(0);opacity:0;}100%{transform:scale(1);opacity:1;}}
          @keyframes fadeIn {0%{opacity:0;transform:scale(.6);}100%{opacity:1;transform:scale(1);}}
        </style>
      `;
      document.body.appendChild(overlay);

      const redirectUrl = data.redirect || "admin-reservation-list.php";

      setTimeout(() => {
        window.location.href = redirectUrl;
      }, 2000);

    } catch (err) {
      alert("❌ " + err.message);
      form.querySelectorAll("button").forEach(btn => btn.disabled = false);
    }
  });
});
</script>
</body>
</html>
