<?php
session_start();
include '../admin/handlers/db_connect.php';
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'customer') {
    header("Location: customer-login.php");
    exit;
}

$customer_id    = (int)($_SESSION['user_id'] ?? 0);
$customer_email = $_SESSION['email'] ?? '';
$customer_name  = $_SESSION['name'] ?? 'Customer';

/* ========= PREPARE FETCH ========= */
$reservations = [];

$sql = "
  SELECT 
    r.id,
    r.code,
    r.type AS category,
    r.package,
    r.pax,
    r.time_slot,
    COALESCE(r.total_price, 0) AS total_price,
    COALESCE(p.method_option, '') AS method_option,
    COALESCE(r.status, 'pending') AS status,
    COALESCE(r.start_date, '') AS start_date,
    COALESCE(r.end_date, '') AS end_date,
    COALESCE(r.approved_date, '') AS approved_date,
    COALESCE(r.payment_status, 'unpaid') AS payment_status
  FROM reservations r
  LEFT JOIN payments p ON p.reservation_id = r.id
  WHERE r.customer_id = ?
  ORDER BY r.created_at DESC
";

/* ========= RUN QUERY ========= */
$stmt = $conn->prepare($sql);
if (!$stmt) {
    die('❌ SQL Error: ' . htmlspecialchars($conn->error));
}

$stmt->bind_param('i', $customer_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {

        /* ========= BASIC CLEANUP ========= */
        $rawCategory          = trim($row['category'] ?? '');
        $row['id']            = (int)$row['id'];
        $row['code']          = htmlspecialchars($row['code'] ?? '');
        $row['category']      = ucfirst(htmlspecialchars($rawCategory));
        $row['category_key']  = strtolower($rawCategory); // for logic only
        $row['package']       = htmlspecialchars($row['package'] ?? '');
        $row['pax']           = (int)($row['pax'] ?? 0);

        // time_slot raw (STRICT: we just normalize label, no guessing by price)
        $row['time_slot_raw'] = trim($row['time_slot'] ?? '');

        /* ========= DATES ========= */
        $row['start_date_raw'] = $row['start_date'] ?? '';
        $row['start_date']     = !empty($row['start_date'])
            ? date('M d, Y', strtotime($row['start_date']))
            : '—';

        $row['end_date_raw'] = $row['end_date'] ?? '';
        $row['end_date']     = !empty($row['end_date'])
            ? date('M d, Y', strtotime($row['end_date']))
            : '—';

        $row['approved_date_raw'] = $row['approved_date'] ?? '';
        $row['approved_date']     = (!empty($row['approved_date']) 
                                     && $row['approved_date'] !== '0000-00-00 00:00:00')
            ? date('M d, Y', strtotime($row['approved_date']))
            : '—';

        /* ========= PRICE ========= */
        $clean_price          = (float)str_replace(',', '', $row['total_price'] ?? 0);
        $row['total_price']   = number_format($clean_price, 2);
        $row['total_price_f'] = $clean_price;

        /* ========= PAYMENT / STATUS ========= */
        $row['method_option']  = htmlspecialchars($row['method_option'] ?? '');
        $row['status']         = strtolower(trim($row['status'] ?? 'pending'));
        $row['payment_status'] = ucfirst(htmlspecialchars($row['payment_status'] ?? 'Unpaid'));

        $reservations[] = $row;
    }
}

$stmt->close();
$conn->close();
?>


<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title>My Reservations • Coco Valley</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet"/>
<link href="https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css" rel="stylesheet"/>

<style>
:root {
  --cv-primary:#004d40;
  --cv-primary-dark:#00352c;
  --cv-secondary:#26a69a;
  --cv-accent:#1abc9c;

  --bg-main:#f5faf8;
  --bg-soft:#f9fafb;
  --panel:#ffffff;
  --text:#0f172a;
  --muted:#6b7280;
  --line:#e5e7eb;

  --shadow-soft:0 18px 50px rgba(15,23,42,0.12);
  --radius-card:22px;
  --radius-pill:999px;
}

/* GLOBAL */
body{
  margin:0;
  background:radial-gradient(circle at top,#d9f7f1 0,#f5faf8 45%,#ffffff 100%);
  font-family:"Inter","Segoe UI",sans-serif;
  color:var(--text);
  min-height:100vh;
}

/* MAIN LAYOUT (with sidebar) */
.main{
  margin-left:250px;
  padding:26px 32px 40px;
}
@media (max-width: 991.98px){
  .main{
    margin-left:0;
    padding:90px 16px 32px;
  }
}

/* WRAPPER */
.payment-wrapper{
  max-width:1200px;
  margin:0 auto;
}

/* PAGE HEADER */
.page-header{
  display:flex;
  justify-content:space-between;
  align-items:flex-start;
  gap:1rem;
  margin-bottom:18px;
}
.page-title-wrap{
  display:flex;
  flex-direction:column;
  gap:4px;
}
.page-title{
  font-size:1.9rem;
  font-weight:800;
  color:var(--cv-primary-dark);
  display:flex;
  align-items:center;
  gap:9px;
}
.page-title i{
  font-size:1.9rem;
}
.page-subtitle{
  font-size:.9rem;
  color:var(--muted);
}

/* STEP CHIPS */
.step-indicator{
  display:flex;
  flex-wrap:wrap;
  gap:6px;
  justify-content:flex-end;
}
.step-pill{
  padding:5px 12px;
  border-radius:var(--radius-pill);
  border:1px solid #d1d5db;
  background-color:#ffffffd9;
  font-size:.8rem;
  font-weight:600;
  color:#6b7280;
}
.step-pill.active{
  border-color:var(--cv-primary);
  color:#ffffff;
  background:linear-gradient(135deg,#26a69a,#0d9488);
}

/* FILTER TABS */
.filter-tabs .nav-link{
  color:var(--cv-primary);
  font-weight:600;
  border-radius:var(--radius-pill);
  border:1px solid var(--cv-primary);
  font-size:.85rem;
  padding:.4rem 1rem;
  background:rgba(255,255,255,0.9);
  backdrop-filter:blur(8px);
  transition:all .18s ease;
}
.filter-tabs .nav-link:hover{
  background:var(--cv-primary);
  color:#fff;
  transform:translateY(-1px);
}
.filter-tabs .nav-link.active{
  background:var(--cv-primary);
  color:#fff !important;
}

/* TABLE CARD */
.reservations-card{
  border-radius:var(--radius-card);
  background:rgba(255,255,255,0.98);
  box-shadow:var(--shadow-soft);
  border:1px solid rgba(148,163,184,0.25);
  overflow:hidden;
}
.card-header-bar{
  padding:12px 22px;
  border-bottom:1px solid var(--line);
  display:flex;
  align-items:center;
  justify-content:space-between;
}
.card-header-bar span{
  font-weight:600;
  color:#111827;
}
.card-header-bar small{
  color:var(--muted);
  font-size:.8rem;
}

/* TABLE STYLING */
.table thead{
  background:linear-gradient(90deg,#004d40,#00796b);
  color:#fff;
}
.table thead th{
  border-bottom:0;
}
.table th{
  font-weight:600;
  text-transform:uppercase;
  font-size:0.78rem;
  letter-spacing:.06em;
}
.table td{
  vertical-align:middle;
  font-size:0.92rem;
  padding-top:.7rem;
  padding-bottom:.7rem;
}
.table-hover tbody tr:hover{
  background:#e7fdf5;
}

/* STATUS BADGES */
.status-pill{
  padding:.25rem .8rem;
  border-radius:var(--radius-pill);
  font-size:.78rem;
  font-weight:700;
  text-transform:capitalize;
}
.status-pill.pending{
  background:#fff7ed;
  color:#c05621;
}
.status-pill.approved{
  background:#dcfce7;
  color:#166534;
}
.status-pill.cancelled{
  background:#fee2e2;
  color:#b91c1c;
}
.status-pill.default{
  background:#e5e7eb;
  color:#374151;
}

/* PAYMENT LABELS */
.payment-info{
  font-size:.8rem;
  display:block;
  margin-top:2px;
}
.payment-50{
  color:#c05621;
  font-weight:600;
}
.payment-100{
  color:#166534;
  font-weight:600;
}
.payment-partial{
  color:#0369a1;
  font-weight:600;
}

/* ACTION BUTTONS */
.btn-sm{
  border-radius:var(--radius-pill);
  font-size:.78rem;
  padding:.35rem .8rem;
  font-weight:600;
}
.btn-view{
  background:#f9fafb;
  border:1px solid #cbd5f5;
  color:#111827;
}
.btn-view:hover{
  background:#e0f2fe;
  border-color:#60a5fa;
}
.btn-cancel{
  background:#fee2e2;
  border:1px solid #fecaca;
  color:#b91c1c;
}
.btn-cancel:hover{
  background:#fecaca;
}

/* VIEW RECEIPT MODAL (Airbnb-style) */
.modal-content{
  border-radius:22px;
  border:0;
  box-shadow:var(--shadow-soft);
}
.receipt-modal-header{
  padding:18px 22px 12px;
  border-bottom:1px solid var(--line);
  display:flex;
  justify-content:space-between;
  align-items:center;
  gap:10px;
}
.receipt-brand{
  display:flex;
  align-items:center;
  gap:10px;
}
.receipt-brand-logo{
  width:44px;
  height:44px;
  border-radius:14px;
  background:#ecfdf5;
  display:flex;
  align-items:center;
  justify-content:center;
  overflow:hidden;
}
.receipt-brand-logo img{
  width:100%;
  height:100%;
  object-fit:cover;
}
.receipt-brand-text{
  display:flex;
  flex-direction:column;
  gap:2px;
}
.receipt-brand-text span:first-child{
  font-size:.8rem;
  text-transform:uppercase;
  letter-spacing:.12em;
  color:#9ca3af;
  font-weight:700;
}
.receipt-brand-text span:last-child{
  font-size:1.05rem;
  font-weight:800;
  color:#111827;
}
.receipt-badge{
  padding:4px 9px;
  border-radius:var(--radius-pill);
  background:#ecfdf5;
  color:#166534;
  font-size:.75rem;
  font-weight:700;
  display:inline-flex;
  align-items:center;
  gap:4px;
}
.receipt-badge i{
  font-size:0.9rem;
}
.receipt-close{
  border:none;
  background:transparent;
  font-size:1.4rem;
  color:#6b7280;
}
.receipt-close:hover{
  color:#111827;
}

/* BODY */
.receipt-modal-body{
  padding:18px 22px 22px;
}
.receipt-columns{
  display:grid;
  grid-template-columns: minmax(0,1.4fr) minmax(0,1fr);
  gap:16px;
}
@media (max-width: 767.98px){
  .receipt-columns{
    grid-template-columns:1fr;
  }
}
.receipt-section{
  background:#f9fafb;
  border-radius:16px;
  padding:14px 14px 12px;
  border:1px solid #e5e7eb;
}
.receipt-section-title{
  font-size:.82rem;
  text-transform:uppercase;
  letter-spacing:.12em;
  color:#9ca3af;
  font-weight:700;
  margin-bottom:6px;
}
.info-grid{
  display:grid;
  grid-template-columns:repeat(2,minmax(0,1fr));
  gap:8px;
  font-size:.84rem;
}
.info-item{
  background:#ffffff;
  border-radius:12px;
  padding:6px 9px;
  border:1px solid #e5e7eb;
}
.info-label{
  font-size:.72rem;
  text-transform:uppercase;
  letter-spacing:.09em;
  color:#9ca3af;
  font-weight:600;
}
.info-value{
  font-size:.9rem;
  font-weight:600;
  color:#111827;
}

/* PAYMENT SUMMARY RIGHT */
.pay-summary{
  display:flex;
  flex-direction:column;
  gap:10px;
}
.pay-row{
  display:flex;
  justify-content:space-between;
  font-size:.9rem;
}
.pay-row span:first-child{
  color:#4b5563;
}
.pay-total{
  font-size:1rem;
  font-weight:800;
  color:#047857;
}
.pay-tag{
  font-size:.78rem;
  color:#6b7280;
}
.receipt-footer-note{
  font-size:.78rem;
  color:#94a3b8;
  margin-top:8px;
}

/* CANCEL/PAY MODAL ICONS */
#cancelModal .icon,
#goPayModal .icon{
  font-size:3rem;
}
</style>
</head>
<body>

<?php include 'sidebar-customer.php'; ?>

<main class="main">
  <div class="payment-wrapper">

    <!-- HEADER -->
    <div class="page-header">
      <div class="page-title-wrap">
        <div class="page-title">
          <i class='bx bx-calendar-check'></i>
          My Reservations
        </div>
        <div class="page-subtitle">
          Track your bookings, review your details, and manage your Coco Valley stays in one place.
        </div>
      </div>
      <div class="step-indicator">
        <span class="step-pill">Step 1: Choose Accommodation</span>
        <span class="step-pill">Step 2: Reservation</span>
        <span class="step-pill active">Step 3: Payment &amp; History</span>
      </div>
    </div>

    <!-- FILTER TABS -->
    <ul class="nav nav-pills filter-tabs mb-3 flex-wrap gap-2">
      <li class="nav-item"><button class="nav-link active" data-filter="all">All</button></li>
      <li class="nav-item"><button class="nav-link" data-filter="pending">Pending</button></li>
      <li class="nav-item"><button class="nav-link" data-filter="approved">Approved</button></li>
      <li class="nav-item"><button class="nav-link" data-filter="cancelled">Cancelled</button></li>
    </ul>

    <!-- TABLE CARD -->
    <div class="reservations-card">
      <div class="card-header-bar">
        <span>Reservation History</span>
        <small><?= htmlspecialchars($customer_email ?: '') ?></small>
      </div>

      <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
          <thead>
            <tr>
              <th>Code</th>
              <th>Category</th>
              <th>Package</th>
              <th>Pax</th>
              <th>Time Slot</th>
              <th>Date</th>
              <th>Payment</th>
              <th>Status</th>
              <th class="text-end">Action</th>
            </tr>
          </thead>
          <tbody>
          <?php if (empty($reservations)): ?>
            <tr>
              <td colspan="9" class="text-center py-4 text-muted">
                <i class="bx bx-info-circle me-1"></i>
                No reservations found yet. Start by booking your first stay!
              </td>
            </tr>
          <?php else: foreach ($reservations as $r): ?>
            <?php
              $reservationId = (int)$r['id'];

              $status        = strtolower(trim($r['status'] ?? 'pending'));
              $cancelAllowed = false;

              if ($status === 'pending') {
                  $cancelAllowed = true;
              } elseif ($status === 'approved' && !empty($r['approved_date_raw']) && $r['approved_date_raw'] !== '0000-00-00 00:00:00') {
                  $approvedTime = strtotime($r['approved_date_raw']);
                  if ($approvedTime && time() <= strtotime('+3 days', $approvedTime)) {
                      $cancelAllowed = true;
                  }
              }

              $clean_price      = (float)$r['total_price_f'];
              $formatted_price  = number_format($clean_price, 2);
              $method_option    = $r['method_option'] ?? '';
              $payment_status   = $r['payment_status'] ?? 'Unpaid';

              // Payment label
              $paymentLabel   = $payment_status;
              $paymentClass   = 'payment-partial';
              if (!empty($method_option)) {
                  $opt = strtolower($method_option);
                  if (strpos($opt, '50') !== false) {
                      $paymentLabel = '50% Downpayment';
                      $paymentClass = 'payment-50';
                  } elseif (strpos($opt, '100') !== false) {
                      $paymentLabel = 'Full Payment';
                      $paymentClass = 'payment-100';
                  }
              } elseif (strtolower($payment_status) === 'unpaid') {
                  $paymentLabel = 'Unpaid';
              }

              // status if totally unpaid (no downpayment yet)
              $isUnpaid = (strtolower($payment_status) === 'unpaid');

              // ========= STRICT TIME SLOT LABEL =========
              $catKey      = strtolower($r['category_key'] ?? '');
              $rawSlot     = trim($r['time_slot_raw'] ?? '');
              $slotLabelRaw = '—';

              if ($rawSlot !== '') {
                  $slotLower = strtolower($rawSlot);

                  if ($catKey === 'cottage') {
                      // Expect Day / Night
                      if ($slotLower === 'day' || $slotLower === 'night') {
                          $slotLabelRaw = ucfirst($slotLower);
                      } else {
                          $slotLabelRaw = $rawSlot;
                      }
                  } elseif ($catKey === 'room') {
                      // Normalize 10 / 22 hours variants
                      if (strpos($slotLower, '10') !== false) {
                          $slotLabelRaw = '10 Hours';
                      } elseif (strpos($slotLower, '22') !== false) {
                          $slotLabelRaw = '22 Hours';
                      } else {
                          $slotLabelRaw = $rawSlot;
                      }
                  } elseif ($catKey === 'event') {
                      // Events often 22 Hours but keep raw
                      $slotLabelRaw = $rawSlot;
                  } else {
                      $slotLabelRaw = $rawSlot;
                  }
              } else {
                  // Only for very old data:
                  if ($catKey === 'event') {
                      $slotLabelRaw = '22 Hours';
                  }
              }

              $slotLabel      = htmlspecialchars($slotLabelRaw);
              $categoryLabel  = $r['category'] ?? '—';
            ?>
            <tr data-status="<?= htmlspecialchars($status) ?>">
              <td><strong><?= htmlspecialchars($r['code'] ?? '—') ?></strong></td>
              <td><?= htmlspecialchars($categoryLabel ?: '—') ?></td>
              <td><?= htmlspecialchars($r['package'] ?? '—') ?></td>
              <td><?= (int)($r['pax'] ?? 0) ?></td>
              <td><?= $slotLabel ?></td>
              <td><?= htmlspecialchars($r['start_date'] ?? '—') ?></td>
              <td>
                <strong>₱<?= $formatted_price ?></strong><br>
                <span class="payment-info <?= $paymentClass ?>">
                  <?= htmlspecialchars($paymentLabel) ?>
                </span>
              </td>
              <td>
                <?php
                  $badgeClass = 'default';
                  if ($status === 'approved')   $badgeClass = 'approved';
                  elseif ($status === 'pending')  $badgeClass = 'pending';
                  elseif ($status === 'cancelled')$badgeClass = 'cancelled';
                ?>
                <span class="status-pill <?= $badgeClass ?>">
                  <?= ucfirst($status ?: '—') ?>
                </span>
              </td>
              <td class="text-end">
                <div class="d-flex justify-content-end gap-1">
                  <!-- VIEW BUTTON (opens receipt OR pay-redirect modal if unpaid) -->
                  <button
                    type="button"
                    class="btn btn-sm btn-view view-btn"
                    data-bs-toggle="modal"
                    data-bs-target="#viewModal"
                    data-id="<?= $reservationId ?>"
                    data-code="<?= htmlspecialchars($r['code']) ?>"
                    data-category="<?= htmlspecialchars($categoryLabel) ?>"
                    data-package="<?= htmlspecialchars($r['package']) ?>"
                    data-pax="<?= (int)$r['pax'] ?>"
                    data-timeslot="<?= $slotLabel ?>"
                    data-date="<?= htmlspecialchars($r['start_date']) ?>"
                    data-enddate="<?= htmlspecialchars($r['end_date']) ?>"
                    data-total="₱<?= $formatted_price ?>"
                    data-paymentlabel="<?= htmlspecialchars($paymentLabel) ?>"
                    data-paymentstatus="<?= htmlspecialchars($payment_status) ?>"
                    data-status="<?= htmlspecialchars($status) ?>"
                  >
                    <i class="bx bx-receipt"></i> View
                  </button>

                  <!-- CANCEL BUTTON -->
                  <?php if ($cancelAllowed): ?>
                    <button type="button"
                      class="btn btn-sm btn-cancel cancel-btn"
                      data-id="<?= $reservationId ?>"
                      data-code="<?= htmlspecialchars($r['code'] ?? '') ?>">
                      <i class='bx bx-x-circle'></i>
                    </button>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</main>

<!-- ===========================
      VIEW RECEIPT MODAL
=========================== -->
<div class="modal fade" id="viewModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content receipt-modal">

      <div class="receipt-modal-header">
        <div class="receipt-brand">
          <div class="receipt-brand-logo">
            <img src="images/logo.jpg" alt="Coco Valley Logo">
          </div>
          <div class="receipt-brand-text">
            <span>Reservation Receipt</span>
            <span>Coco Valley Richnez Waterpark</span>
          </div>
        </div>
        <div class="d-flex align-items-center gap-2">
          <span id="vmStatusBadge" class="receipt-badge">
            <i class="bx bxs-badge-check"></i>
            <span id="vmStatusText">Status</span>
          </span>
          <button type="button" class="receipt-close" data-bs-dismiss="modal" aria-label="Close">
            <i class="bx bx-x"></i>
          </button>
        </div>
      </div>

      <div class="receipt-modal-body">
        <div class="receipt-columns">

          <!-- LEFT: ACCOMMODATION INFO -->
          <div class="receipt-section">
            <div class="receipt-section-title">Accommodation Details</div>
            <div class="info-grid">
              <div class="info-item">
                <div class="info-label">Accommodation</div>
                <div class="info-value" id="vmPackage">–</div>
              </div>
              <div class="info-item">
                <div class="info-label">Category</div>
                <div class="info-value" id="vmCategory">–</div>
              </div>
              <div class="info-item">
                <div class="info-label">Pax</div>
                <div class="info-value" id="vmPax">–</div>
              </div>
              <div class="info-item">
                <div class="info-label">Time Slot</div>
                <div class="info-value" id="vmSlot">–</div>
              </div>
              <div class="info-item">
                <div class="info-label">Date</div>
                <div class="info-value" id="vmDate">–</div>
              </div>
              <div class="info-item">
                <div class="info-label">Reservation Code</div>
                <div class="info-value" id="vmCode">–</div>
              </div>
            </div>
          </div>

          <!-- RIGHT: PAYMENT SUMMARY -->
          <div class="receipt-section">
            <div class="receipt-section-title">Payment Summary</div>
            <div class="pay-summary">
              <div class="pay-row">
                <span>Payment Option</span>
                <span id="vmPaymentLabel">–</span>
              </div>
              <div class="pay-row">
                <span>Payment Status</span>
                <span id="vmPaymentStatus">–</span>
              </div>
              <div class="pay-row">
                <span>Total Package Price</span>
                <span id="vmTotal" class="pay-total">–</span>
              </div>
              <p class="receipt-footer-note mb-0">
                This receipt is for review only. Final confirmation will be based on admin verification
                and resort policies.
              </p>
            </div>
          </div>

        </div>
      </div>

    </div>
  </div>
</div>

<!-- ===========================
      CONTINUE-TO-PAYMENT MODAL
      (for UNPAID reservations)
=========================== -->
<div class="modal fade" id="goPayModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content text-center p-3">
      <i class="bx bx-wallet icon text-success mt-2"></i>
      <h5 class="fw-bold mb-2">Continue to Payment?</h5>
      <p class="mb-1">You haven't submitted a downpayment yet for</p>
      <p><strong id="payModalCode"></strong>.</p>
      <p class="text-muted small mb-0">
        You'll be redirected to the payment page with this reservation's details already filled in.
      </p>
      <div class="d-flex justify-content-center gap-3 mt-3 mb-2">
        <button type="button" class="btn btn-outline-secondary px-3" data-bs-dismiss="modal">Not now</button>
        <button type="button" class="btn btn-success px-3" id="goPayNowBtn">
          Go to Payment
        </button>
      </div>
    </div>
  </div>
</div>

<!-- ===========================
      CANCEL MODAL
=========================== -->
<div class="modal fade" id="cancelModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content text-center p-3">
      <i class="bx bx-error-circle icon text-danger mt-2"></i>
      <h5 class="fw-bold mb-2">Cancel Reservation?</h5>
      <p class="mb-1">Are you sure you want to cancel reservation</p>
      <p><strong id="cancelCode"></strong>?</p>
      <div class="d-flex justify-content-center gap-3 mt-3 mb-2">
        <button type="button" class="btn btn-outline-secondary px-3" data-bs-dismiss="modal">No</button>
        <button type="button" class="btn btn-danger px-3" id="confirmCancelBtn">Yes, Cancel</button>
      </div>
    </div>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
// ============================
//  FILTER TABS
// ============================
document.querySelectorAll('.filter-tabs .nav-link').forEach(tab => {
  tab.addEventListener('click', () => {
    document.querySelectorAll('.filter-tabs .nav-link').forEach(t => t.classList.remove('active'));
    tab.classList.add('active');

    const filter = tab.dataset.filter;
    document.querySelectorAll('tbody tr[data-status]').forEach(row => {
      const match = (filter === 'all') || (row.dataset.status === filter);
      row.style.display = match ? '' : 'none';
    });
  });
});

// ============================
//  VIEW RECEIPT / PAY MODAL
// ============================
const viewButtons      = document.querySelectorAll('.view-btn');
const vmCode           = document.getElementById('vmCode');
const vmCategory       = document.getElementById('vmCategory');
const vmPackage        = document.getElementById('vmPackage');
const vmPax            = document.getElementById('vmPax');
const vmSlot           = document.getElementById('vmSlot');
const vmDate           = document.getElementById('vmDate');
const vmTotal          = document.getElementById('vmTotal');
const vmPaymentLabel   = document.getElementById('vmPaymentLabel');
const vmPaymentStatus  = document.getElementById('vmPaymentStatus');
const vmStatusBadge    = document.getElementById('vmStatusBadge');
const vmStatusText     = document.getElementById('vmStatusText');

let pendingPayUrl = '';
const goPayModalEl = document.getElementById('goPayModal');
const payModal     = goPayModalEl ? new bootstrap.Modal(goPayModalEl) : null;
const payModalCode = document.getElementById('payModalCode');
const goPayBtn     = document.getElementById('goPayNowBtn');

if (goPayBtn) {
  goPayBtn.addEventListener('click', () => {
    if (!pendingPayUrl) return;
    window.location.href = pendingPayUrl;
  });
}

viewButtons.forEach(btn => {
  btn.addEventListener('click', (e) => {
    const paymentStatus = (btn.dataset.paymentstatus || '').toLowerCase();
    const reservationId = btn.dataset.id || '';
    const code          = btn.dataset.code || '—';

    // If UNPAID → redirect flow instead of receipt modal
    if (paymentStatus === 'unpaid' && reservationId) {
      e.preventDefault();
      pendingPayUrl = 'customer-payment.php?reservation_id=' + encodeURIComponent(reservationId);
      if (payModalCode) payModalCode.textContent = code;
      if (payModal)     payModal.show();
      return;
    }

    // Otherwise, fill receipt modal normally
    vmCode.textContent          = code;
    vmCategory.textContent      = btn.dataset.category || '—';
    vmPackage.textContent       = btn.dataset.package || '—';
    vmPax.textContent           = btn.dataset.pax || '—';
    vmSlot.textContent          = btn.dataset.timeslot || '—';
    vmDate.textContent          = btn.dataset.date || '—';
    vmTotal.textContent         = btn.dataset.total || '—';
    vmPaymentLabel.textContent  = btn.dataset.paymentlabel || '—';
    vmPaymentStatus.textContent = btn.dataset.paymentstatus || '—';

    const status = (btn.dataset.status || 'pending').toLowerCase();
    vmStatusText.textContent = status.charAt(0).toUpperCase() + status.slice(1);

    // badge color tweak
    if (status === 'approved') {
      vmStatusBadge.style.background = '#dcfce7';
      vmStatusBadge.style.color = '#166534';
    } else if (status === 'pending') {
      vmStatusBadge.style.background = '#fef3c7';
      vmStatusBadge.style.color = '#92400e';
    } else if (status === 'cancelled') {
      vmStatusBadge.style.background = '#fee2e2';
      vmStatusBadge.style.color = '#b91c1c';
    } else {
      vmStatusBadge.style.background = '#e5e7eb';
      vmStatusBadge.style.color = '#374151';
    }
  });
});

// ============================
//  CANCEL MODAL LOGIC
// ============================
let selectedID = null;
const cancelModalEl = document.getElementById('cancelModal');
const cancelModal   = cancelModalEl ? new bootstrap.Modal(cancelModalEl) : null;

document.querySelectorAll('.cancel-btn').forEach(btn => {
  btn.addEventListener('click', () => {
    selectedID = btn.dataset.id;
    document.getElementById('cancelCode').textContent = btn.dataset.code || 'N/A';
    if (cancelModal) cancelModal.show();
  });
});

document.getElementById('confirmCancelBtn')?.addEventListener('click', () => {
  if (!selectedID) return;
  const btn = document.getElementById('confirmCancelBtn');
  btn.disabled = true;
  btn.textContent = 'Cancelling...';

  fetch('../admin/handlers/cancel_reservation.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: 'reservation_id=' + encodeURIComponent(selectedID)
  })
  .then(res => res.json())
  .then(data => {
    if (data.ok || data.success) {
      showToast(data.message || 'Reservation cancelled successfully!', 'success');
      setTimeout(() => location.reload(), 1200);
    } else {
      showToast(data.message || 'Failed to cancel reservation.', 'warning');
    }
  })
  .catch(() => showToast('Network error. Please try again.', 'danger'))
  .finally(() => {
    btn.disabled = false;
    btn.textContent = 'Yes, Cancel';
    if (cancelModal) cancelModal.hide();
  });
});

// ============================
//  TOAST UTILITY
// ============================
function showToast(message, type = 'success') {
  const bgMap = {
    success: 'bg-success text-white',
    warning: 'bg-warning text-dark',
    danger:  'bg-danger text-white'
  };
  const toast = document.createElement('div');
  toast.className = `toast align-items-center ${bgMap[type] || ''}`;
  toast.role = 'alert';
  toast.style.position = 'fixed';
  toast.style.top = '1rem';
  toast.style.right = '1rem';
  toast.style.zIndex = '1055';
  toast.innerHTML = `
    <div class="d-flex">
      <div class="toast-body fw-semibold">${message}</div>
      <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
    </div>
  `;
  document.body.appendChild(toast);
  const bsToast = new bootstrap.Toast(toast, { delay: 2500 });
  bsToast.show();
  toast.addEventListener('hidden.bs.toast', () => toast.remove());
}
</script>
</body>
</html>
