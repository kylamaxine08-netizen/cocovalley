<?php
session_start();
include '../admin/handlers/db_connect.php';

/* ============================================================
   🔒 CUSTOMER ACCESS ONLY
============================================================ */
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'customer') {
    header("Location: customer-login.php");
    exit;
}

$customer_id = (int)$_SESSION['user_id'];

/* ============================================================
   LOAD RESERVATION BY CODE
============================================================ */
if (!empty($_GET['code'])) {
    $_SESSION['reservation_code'] = $_GET['code'];
}

$reservationCode = $_SESSION['reservation_code'] ?? '';

if ($reservationCode === '') {
    echo "<script>
            alert('Reservation code missing. Please book again.');
            window.location.href='customer-accommodation.php';
          </script>";
    exit;
}

$stmt = $conn->prepare("
    SELECT 
        id,
        type,
        package,
        cottage_number,
        pax,
        time_slot,
        total_price,
        start_date
    FROM reservations
    WHERE code = ?
      AND customer_id = ?
    LIMIT 1
");
$stmt->bind_param("si", $reservationCode, $customer_id);
$stmt->execute();
$res = $stmt->get_result();
$reservation = $res->fetch_assoc();
$stmt->close();

if (!$reservation) {
    echo "<script>
            alert('Reservation not found or does not belong to your account.');
            window.location.href='customer-accommodation.php';
          </script>";
    exit;
}

/* ============================================================
   MAP DB → PHP VARIABLES
============================================================ */
$reservation_id = (int)$reservation['id'];
$category       = $reservation['type'];
$package        = $reservation['package'];
$cottageNumber  = (int)($reservation['cottage_number'] ?? 0);
$pax            = (int)($reservation['pax'] ?? 0);
$slot           = $reservation['time_slot'];
$price          = (float)($reservation['total_price'] ?? 0);
$date           = $reservation['start_date'];

/* ============================================================
   PAYMENT SUBMISSION HANDLER
============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $payment_option = $_POST['paymentOption'] ?? '';
    $amount         = (float)($_POST['amount'] ?? 0);
    $method         = 'GCash';
    $payment_status = 'pending';
    $status         = 'pending';

    // paymentOption uses "50%" or "100%" — extract numeric part
    $payment_percent = (float)filter_var($payment_option, FILTER_SANITIZE_NUMBER_FLOAT, FILTER_FLAG_ALLOW_FRACTION);

    if ($payment_option === '' || $amount <= 0 || $payment_percent <= 0) {
        echo "<script>alert('Please select a valid payment option and amount.');</script>";
    }
    elseif (!isset($_FILES['proof']) || $_FILES['proof']['error'] !== UPLOAD_ERR_OK) {
        echo "<script>alert('Please upload a valid GCash receipt image.');</script>";
    }
    else {

        /* ---------------------------------------------------------
           FILE UPLOAD
        --------------------------------------------------------- */
        $uploadDir = "../admin/uploads/";
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }

        $original  = basename($_FILES["proof"]["name"]);
        $ext       = pathinfo($original, PATHINFO_EXTENSION);
        $fileName  = uniqid("proof_") . "." . $ext;
        $filePath  = $uploadDir . $fileName;

        if (move_uploaded_file($_FILES["proof"]["tmp_name"], $filePath)) {

            /* ---------------------------------------------------------
               INSERT PAYMENT RECORD — FIXED (Correct bind types)
            --------------------------------------------------------- */
            $stmt2 = $conn->prepare("
                INSERT INTO payments
                  (reservation_id, amount, method, method_option, proof_image,
                   payment_status, payment_percent, status, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");

            $stmt2->bind_param(
                "idsssdss",
                $reservation_id,
                $amount,
                $method,
                $payment_option,     // “50%” or “100%”
                $fileName,
                $payment_status,     // pending
                $payment_percent,    // 50 or 100
                $status
            );
            $stmt2->execute();
            $stmt2->close();

            /* ---------------------------------------------------------
               UPDATE RESERVATION
            --------------------------------------------------------- */
            $stmt3 = $conn->prepare("
                UPDATE reservations
                SET 
                    status = 'pending',
                    payment_status = 'pending',
                    payment_percent = ?,
                    last_payment_date = NOW()
                WHERE id = ?
            ");

            $stmt3->bind_param(
                "di",
                $payment_percent,
                $reservation_id
            );
            $stmt3->execute();
            $stmt3->close();

            /* ---------------------------------------------------------
               AUTO SEND NOTIFICATIONS
            --------------------------------------------------------- */
            include_once "../admin/handlers/notify.php";

            $customer_name  = $_SESSION['name'] ?? 'Customer';
            $customer_email = $_SESSION['email'] ?? '';

            $message_admin     = "$customer_name uploaded a GCash payment proof for reservation code $reservationCode.";
            $message_customer  = "Your payment proof for reservation code $reservationCode has been submitted and is pending review.";

            // Notify Admin
            sendNotification(
                "admin",
                "New Payment Proof",
                $message_admin,
                "payment",
                $customer_name,
                "../admin/payment-proof-view.php?code=$reservationCode"
            );

            // Notify All Staff
            sendNotification(
                "all_staff",
                "New Payment Proof",
                $message_admin,
                "payment",
                $customer_name,
                "../staff/staff-payment.php"
            );

            // Notify Customer
            sendNotification(
                $customer_email,
                "Payment Submitted",
                $message_customer,
                "payment",
                "System",
                "customer-myreservation.php"
            );

            /* ---------------------------------------------------------
               SUCCESS REDIRECT
            --------------------------------------------------------- */
            echo "<script>
                    alert('✅ Payment uploaded successfully! Waiting for admin verification.');
                    window.location.href='customer-myreservation.php';
                  </script>";
            exit;

        } else {
            echo "<script>alert('Failed to upload proof of payment. Please try again.');</script>";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Coco Valley • Payment</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css" rel="stylesheet">

<style>
:root {
  --primary:#004d40;
  --primary-dark:#00352d;
  --accent:#26a69a;
  --bg:#f3f4f6;
  --panel:#ffffff;
  --border:#e5e7eb;
  --shadow-soft:0 18px 40px rgba(15,23,42,0.08);
  --text:#0f172a;
  --muted:#6b7280;
}

*{box-sizing:border-box;}

body{
  margin:0;
  background:var(--bg);
  font-family:"Inter","Segoe UI",sans-serif;
  color:var(--text);
}

/* MAIN LAYOUT – matches Coco Valley customer pages */
.main{
  margin-left:250px;
  padding:24px 32px 40px;
}
@media (max-width: 991.98px){
  .main{
    margin-left:0;
    padding:80px 16px 32px;
  }
}

/* PAGE HEADER */
.page-header{
  display:flex;
  align-items:center;
  justify-content:space-between;
  margin-bottom:16px;
  flex-wrap:wrap;
  gap:8px;
}
.page-title{
  font-size:1.6rem;
  font-weight:800;
  color:var(--primary-dark);
  display:flex;
  align-items:center;
  gap:8px;
}
.page-subtitle{
  font-size:.85rem;
  color:var(--muted);
}

/* STEP INDICATOR */
.step-indicator{
  display:flex;
  align-items:center;
  gap:8px;
  font-size:.8rem;
  color:var(--muted);
  flex-wrap:wrap;
  justify-content:flex-end;
}
.step-pill{
  padding:4px 10px;
  border-radius:999px;
  border:1px solid #d1d5db;
  background:#fff;
  font-weight:600;
}
.step-pill.active{
  border-color:var(--primary);
  color:var(--primary);
}

/* WRAPPER */
.payment-wrapper{
  max-width:1180px;
  margin:0 auto;
}

/* PANEL (CARD) */
.panel{
  background:var(--panel);
  border-radius:18px;
  padding:22px 24px;
  box-shadow:var(--shadow-soft);
  border:1px solid var(--border);
}

/* LEFT: QR CARD */
.qr-title{
  font-weight:700;
  margin-bottom:10px;
}
.qr-box{
  background:#f9fafb;
  border-radius:16px;
  padding:20px 16px;
  display:flex;
  flex-direction:column;
  align-items:center;
  justify-content:center;
  border:1px solid #e5e7eb;
}
.qr-box img{
  max-width:260px;
  width:100%;
  border-radius:16px;
}
.qr-name{
  margin-top:8px;
  font-size:.85rem;
  color:var(--muted);
}
.qr-note{
  margin-top:18px;
  font-size:.82rem;
  background:#fffbeb;
  border-radius:12px;
  border:1px dashed #facc15;
  padding:10px 12px;
  color:#92400e;
}

/* SUMMARY + FORM */
.summary-card{
  border-radius:14px;
  border:1px solid #d1d5db;
  padding:14px 16px;
  background:#f9fafb;
  margin-bottom:18px;
}
.summary-header{
  display:flex;
  justify-content:space-between;
  align-items:center;
  margin-bottom:6px;
}
.summary-header span:first-child{
  font-weight:700;
}
.badge-recommended{
  display:inline-flex;
  align-items:center;
  gap:4px;
  font-size:.75rem;
  padding:3px 8px;
  border-radius:999px;
  background:#e0f2fe;
  color:#0369a1;
}
.badge-recommended i{
  font-size:0.9rem;
}
.summary-list{
  font-size:.85rem;
  color:var(--muted);
}
.summary-list p{
  margin:0 0 2px;
}
.summary-total{
  margin-top:8px;
  display:flex;
  justify-content:space-between;
  align-items:center;
  font-size:.9rem;
}
.summary-total span:last-child{
  font-weight:800;
  color:#047857;
}

/* FORM */
.section-title{
  font-weight:700;
  font-size:1rem;
  margin-bottom:10px;
}
.form-label{
  font-size:.86rem;
  font-weight:600;
  color:#374151;
}
.form-control,
.form-select{
  border-radius:12px;
  padding:.7rem .85rem;
  border:1px solid #d1d5db;
  font-size:.9rem;
}
.form-control:focus,
.form-select:focus{
  border-color:var(--primary);
  box-shadow:0 0 0 1px rgba(0,77,64,.15);
}
.form-hint{
  font-size:.78rem;
  color:#9ca3af;
  margin-top:3px;
}

/* BUTTONS */
.btn-pay{
  width:100%;
  background:var(--primary);
  border:none;
  color:#fff;
  font-weight:700;
  border-radius:12px;
  padding:.85rem;
  font-size:.95rem;
  display:flex;
  align-items:center;
  justify-content:center;
  gap:8px;
  transition:.2s ease;
}
.btn-pay:hover{
  background:var(--primary-dark);
}
.helper-text{
  font-size:.78rem;
  color:#9ca3af;
  margin-top:8px;
}

/* REVIEW MODAL */
.modal-content{
  border-radius:18px;
}
.modal-header{
  border-bottom:0;
  background:linear-gradient(90deg,var(--primary),var(--accent));
  color:#fff;
}
.modal-title{
  font-weight:700;
}
.review-label{
  font-size:.8rem;
  text-transform:uppercase;
  color:#9ca3af;
  letter-spacing:.04em;
  margin-bottom:2px;
}
.review-value{
  font-size:.9rem;
  font-weight:600;
  color:#111827;
}
.review-box{
  background:#f9fafb;
  border-radius:14px;
  padding:10px 12px;
  border:1px solid #e5e7eb;
}
.review-image{
  width:100%;
  max-height:220px;
  object-fit:contain;
  border-radius:12px;
  border:1px solid #e5e7eb;
  background:#f3f4f6;
}
</style>
</head>
<body>

<?php include 'sidebar-customer.php'; ?>

<main class="main">
  <div class="payment-wrapper">

    <!-- HEADER -->
    <div class="page-header">
      <div>
        <div class="page-title">
          <i class="bx bx-credit-card"></i>
          Secure Payment
        </div>
        <div class="page-subtitle">
          Review the details below and upload your GCash receipt to complete your booking.
        </div>
      </div>
      <div class="step-indicator">
        <span class="step-pill">Step 1: Choose Accommodation</span>
        <span class="step-pill">Step 2: Reservation</span>
        <span class="step-pill active">Step 3: Payment</span>
      </div>
    </div>

    <div class="row g-4">

      <!-- LEFT: QR AREA -->
      <div class="col-lg-5">
        <div class="panel h-100">
          <div class="qr-title">Scan to Pay (GCash)</div>
          <div class="qr-box">
            <img src="qrcode1.jpg" alt="GCash QR Code">
            <div class="qr-name">Account Name: <strong>COCO VALLEY RICHNEZ</strong></div>
          </div>
          <div class="qr-note">
            ⚠ <strong>Important:</strong> Please complete the payment within
            <strong>24 hours</strong> or your reservation may be automatically cancelled.
          </div>
        </div>
      </div>

      <!-- RIGHT: PAYMENT SUMMARY + FORM -->
      <div class="col-lg-7">
        <div class="panel">

          <!-- BACK BUTTON -->
          <a href="reservation-form.php?code=<?= urlencode($reservationCode) ?>" 
   class="btn btn-outline-secondary mb-3" 
   style="border-radius:12px; font-weight:600;">
  <i class="bx bx-arrow-back"></i> Back to Reservation Form
</a>


          <!-- SUMMARY -->
          <div class="summary-card">
            <div class="summary-header">
              <span><?= htmlspecialchars($package) ?></span>
              <span class="badge-recommended">
                <i class="bx bxs-star"></i> Highly Recommended
              </span>
            </div>
            <div class="summary-list">
              <p>Category: <?= htmlspecialchars(ucfirst($category)) ?></p>
              <?php if (strtolower($category) === 'cottage' && $cottageNumber > 0): ?>
                <p>Cottage #: <?= htmlspecialchars((string)$cottageNumber) ?></p>
              <?php endif; ?>
              <p>Pax: <?= htmlspecialchars((string)$pax) ?></p>
              <p>Date: <?= htmlspecialchars($date) ?></p>
              <p>Time Slot: <?= htmlspecialchars($slot) ?></p>
              <p>Reservation Code: <strong><?= htmlspecialchars($reservationCode) ?></strong></p>
            </div>
            <div class="summary-total">
              <span>Total Price</span>
              <span>₱<?= number_format($price, 2) ?></span>
            </div>
          </div>

          <!-- FORM -->
          <h6 class="section-title">Upload Payment Proof</h6>
          <form id="paymentForm" method="POST" enctype="multipart/form-data">
            <div class="row g-3">
              <div class="col-md-6">
                <label class="form-label">Payment Option</label>
                <select name="paymentOption" id="paymentOption" class="form-select" required>
                  <option value="" disabled selected>Select option</option>
                  <option value="50%">50% Downpayment</option>
                  <option value="100%">Full Payment</option>
                </select>
                <div class="form-hint">50% downpayment is required to secure your booking.</div>
              </div>

              <div class="col-md-6">
                <label class="form-label">Amount to Pay (₱)</label>
                <input type="number" id="amount" name="amount" class="form-control" readonly required>
                <div class="form-hint">Automatically calculated based on your selection.</div>
              </div>

              <div class="col-md-12">
                <label class="form-label">Upload GCash Receipt</label>
                <input type="file" id="proof" name="proof" class="form-control" accept="image/*" required>
                <div class="form-hint">Accepted: clear screenshot or photo of the GCash confirmation.</div>
              </div>
            </div>

            <!-- instead of direct submit, open review modal -->
            <button type="button" id="btnOpenReview" class="btn-pay mt-4">
              <i class="bx bx-upload"></i>
              Review & Submit Payment
            </button>
            <div class="helper-text">
              Once submitted, your payment will be reviewed by the admin. You can track the status in
              <strong>My Reservation</strong>.
            </div>
          </form>

        </div>
      </div>

    </div>
  </div>
</main>

<!-- REVIEW MODAL -->
<div class="modal fade" id="reviewModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Review Payment Details</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div class="row g-3">
          <div class="col-md-7">
            <div class="review-box mb-3">
              <div class="review-label">Accommodation</div>
              <div class="review-value"><?= htmlspecialchars($package) ?></div>
            </div>

            <div class="row g-2">
              <div class="col-6">
                <div class="review-box mb-2">
                  <div class="review-label">Category</div>
                  <div class="review-value"><?= htmlspecialchars(ucfirst($category)) ?></div>
                </div>
              </div>
              <div class="col-6">
                <div class="review-box mb-2">
                  <div class="review-label">Pax</div>
                  <div class="review-value"><?= htmlspecialchars((string)$pax) ?></div>
                </div>
              </div>
              <div class="col-6">
                <div class="review-box mb-2">
                  <div class="review-label">Date</div>
                  <div class="review-value"><?= htmlspecialchars($date) ?></div>
                </div>
              </div>
              <div class="col-6">
                <div class="review-box mb-2">
                  <div class="review-label">Time Slot</div>
                  <div class="review-value"><?= htmlspecialchars($slot) ?></div>
                </div>
              </div>
              <div class="col-12">
                <div class="review-box mb-2">
                  <div class="review-label">Reservation Code</div>
                  <div class="review-value"><?= htmlspecialchars($reservationCode) ?></div>
                </div>
              </div>
            </div>

            <div class="review-box mt-2">
              <div class="review-label">Total Package Price</div>
              <div class="review-value">₱<?= number_format($price, 2) ?></div>
            </div>
          </div>

          <div class="col-md-5">
            <div class="review-box mb-3">
              <div class="review-label">Payment Option</div>
              <div class="review-value" id="reviewOption">—</div>
            </div>
            <div class="review-box mb-3">
              <div class="review-label">Amount to Pay</div>
              <div class="review-value" id="reviewAmount">₱0.00</div>
            </div>
            <div class="review-box">
              <div class="review-label mb-1">GCash Receipt Preview</div>
              <img id="reviewImage" class="review-image" alt="Receipt preview">
            </div>
          </div>
        </div>
      </div>
      <div class="modal-footer justify-content-between">
        <span class="text-muted small">If everything looks correct, click <strong>Confirm & Submit</strong>.</span>
        <div>
          <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Go Back</button>
          <button type="button" id="btnConfirmSubmit" class="btn btn-success btn-sm">
            Confirm & Submit
          </button>
        </div>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener("DOMContentLoaded", () => {
  const price       = <?= json_encode((float)$price) ?>;
  const selectOpt   = document.getElementById("paymentOption");
  const amountInput = document.getElementById("amount");
  const proofInput  = document.getElementById("proof");
  const btnReview   = document.getElementById("btnOpenReview");
  const form        = document.getElementById("paymentForm");

  const reviewModal  = new bootstrap.Modal(document.getElementById("reviewModal"));
  const reviewOption = document.getElementById("reviewOption");
  const reviewAmount = document.getElementById("reviewAmount");
  const reviewImage  = document.getElementById("reviewImage");
  const btnConfirm   = document.getElementById("btnConfirmSubmit");

  // Auto compute amount
  if (selectOpt && amountInput) {
    selectOpt.addEventListener("change", () => {
      if (selectOpt.value === "50%") {
        amountInput.value = (price * 0.5).toFixed(2);
      } else if (selectOpt.value === "100%") {
        amountInput.value = price.toFixed(2);
      } else {
        amountInput.value = "";
      }
    });
  }

  // Open review modal
  if (btnReview) {
    btnReview.addEventListener("click", () => {
      const optVal = selectOpt.value;
      const amtVal = amountInput.value;

      if (!optVal || !amtVal) {
        alert("Please select payment option first.");
        return;
      }
      if (!proofInput.files || proofInput.files.length === 0) {
        alert("Please upload your GCash receipt first.");
        return;
      }

      // Set text values
      reviewOption.textContent = optVal === "50%" ? "50% Downpayment" : "100% Full Payment";
      reviewAmount.textContent = "₱" + parseFloat(amtVal).toFixed(2);

      // Image preview
      const file = proofInput.files[0];
      if (file) {
        const reader = new FileReader();
        reader.onload = e => {
          reviewImage.src = e.target.result;
        };
        reader.readAsDataURL(file);
      } else {
        reviewImage.removeAttribute("src");
      }

      reviewModal.show();
    });
  }

  // Confirm & auto-submit
  if (btnConfirm) {
    btnConfirm.addEventListener("click", () => {
      reviewModal.hide();
      form.submit();
    });
  }
});
</script>

</body>
</html>
