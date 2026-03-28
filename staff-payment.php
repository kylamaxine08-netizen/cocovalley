  <?php
  // ===================================================
  // ✅ SECURE SESSION SETUP (STAFF ONLY)
  // ===================================================
  session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'domain' => '',
    'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'httponly' => true,
    'samesite' => 'Lax',
  ]);
  session_start();

  if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'staff') {
    header('Location: admin-login.php');
    exit;
  }

  $meName = $_SESSION['name'] ?? 'Coco Valley (Staff)';

  require_once '../admin/handlers/db_connect.php';

  // ===================================================
  // 🟦 OPTIONAL: DIRECT APPROVE / CANCEL (same as admin)
  // (JS still uses ../admin/handlers/payment_action.php,
  // but this block is kept for flexibility.)
  // ===================================================
  if (isset($_POST['action'])) {

    $action         = $_POST['action'];
    $payment_id     = intval($_POST['payment_id'] ?? 0);
    $reservation_id = intval($_POST['reservation_id'] ?? 0);

    if ($payment_id > 0 && $reservation_id > 0) {

      try {
        $conn->begin_transaction();

        // 🔍 FETCH RESERVATION DETAILS + CUSTOMER EMAIL
        $res = $conn->prepare("
          SELECT 
              r.total_price,
              r.customer_name,
              u.email
          FROM reservations r
          LEFT JOIN users u ON r.customer_id = u.id
          WHERE r.id = ?
        ");
        $res->bind_param("i", $reservation_id);
        $res->execute();
        $resData = $res->get_result()->fetch_assoc();
        $res->close();

        $total_price    = floatval($resData['total_price'] ?? 0);
        $customer_name  = $resData['customer_name'] ?? 'Customer';
        $email          = $resData['email'] ?? '';

        // 🔍 SUM ALL PAYMENTS
        $fetch = $conn->prepare("
          SELECT COALESCE(SUM(amount), 0) AS total_paid
          FROM payments
          WHERE reservation_id = ?
        ");
        $fetch->bind_param("i", $reservation_id);
        $fetch->execute();
        $paidRow = $fetch->get_result()->fetch_assoc();
        $fetch->close();

        $total_paid = floatval($paidRow['total_paid'] ?? 0);
        $payment_percent = ($total_price > 0)
          ? min(($total_paid / $total_price) * 100, 100)
          : 0;

        $payment_status = $payment_percent >= 50 ? 'approved' : 'pending';

        // 🔹 APPROVE PAYMENT
        if ($action === 'approve') {

          // UPDATE PAYMENT RECORD
          $stmt1 = $conn->prepare("
            UPDATE payments
            SET 
              status          = 'approved',
              payment_status  = ?,
              payment_percent = ?,
              verified_by     = ?,
              updated_at      = NOW()
            WHERE id = ?
          ");
          $verifiedBy = (int)($_SESSION['user_id'] ?? 0);
          $stmt1->bind_param("sdii", $payment_status, $payment_percent, $verifiedBy, $payment_id);
          $stmt1->execute();
          $stmt1->close();

          // UPDATE RESERVATION (STAFF CAN APPROVE)
          $stmt2 = $conn->prepare("
            UPDATE reservations
            SET 
              status        = 'approved',
              approved_by   = 'Coco Valley (Staff)',
              approved_date = NOW(),
              updated_at    = NOW()
            WHERE id = ?
          ");
          $stmt2->bind_param("i", $reservation_id);
          $stmt2->execute();
          $stmt2->close();

          // 📧 SEND PAYMENT APPROVED EMAIL
          if (!empty($email)) {
            require_once '../admin/email/send_payment_approved.php';
            @sendPaymentApproved($email, $customer_name);
          }

          // 🔔 NOTIFICATION SYSTEM
          if (!empty($email)) {

            $notifTitle = "Payment Approved ✅";
            $notifMsg = "
              <div style='font-family:Segoe UI, sans-serif;'>
                <span style='background:#10b981;color:#fff;padding:4px 8px;
                    border-radius:6px;font-size:12px;font-weight:bold;'>APPROVED</span>
                <h3 style='color:#004d99;'>Cocovalley Richnez Waterpark</h3>
                <p>Hello <b>{$customer_name}</b>, your payment has been approved.</p>
              </div>";

            $postedBy = "Coco Valley (Staff)";

            $stmtN = $conn->prepare("
              INSERT INTO notifications (email, item_name, message, type, status, created_at, posted_by)
              VALUES (?, ?, ?, 'payment', 'unread', NOW(), ?)
            ");
            $stmtN->bind_param("ssss", $email, $notifTitle, $notifMsg, $postedBy);
            $stmtN->execute();
            $stmtN->close();
          }

          $conn->commit();
          echo json_encode(["ok" => true, "message" => "Payment approved successfully."]);
          exit;
        }

        // 🔹 CANCEL PAYMENT
        if ($action === 'cancel') {

          // UPDATE PAYMENT
          $stmt3 = $conn->prepare("
            UPDATE payments
            SET 
              status         = 'cancelled',
              payment_status = 'cancelled',
              verified_by    = ?,
              updated_at     = NOW()
            WHERE id = ?
          ");
          $verifiedBy = (int)($_SESSION['user_id'] ?? 0);
          $stmt3->bind_param("ii", $verifiedBy, $payment_id);
          $stmt3->execute();
          $stmt3->close();

          // UPDATE RESERVATION
          $stmt4 = $conn->prepare("
            UPDATE reservations
            SET status = 'cancelled', updated_at = NOW()
            WHERE id = ?
          ");
          $stmt4->bind_param("i", $reservation_id);
          $stmt4->execute();
          $stmt4->close();

          // 📧 SEND PAYMENT CANCELLED EMAIL
          if (!empty($email)) {
            require_once '../admin/email/send_payment_cancelled.php';
            @sendPaymentCancelled($email, $customer_name);
          }

          // 🔔 NOTIFICATION
          if (!empty($email)) {

            $notifTitle = "Payment Cancelled ❌";
            $notifMsg = "
              <div style='font-family:Segoe UI, sans-serif;'>
                <span style='background:#ef4444;color:#fff;padding:4px 8px;
                    border-radius:6px;font-size:12px;font-weight:bold;'>CANCELLED</span>
                <h3 style='color:#004d99;'>Cocovalley Richnez Waterpark</h3>
                <p>Hello <b>{$customer_name}</b>, your payment has been cancelled.</p>
              </div>";

            $postedBy = "Coco Valley (Staff)";

            $stmtN = $conn->prepare("
              INSERT INTO notifications (email, item_name, message, type, status, created_at, posted_by)
              VALUES (?, ?, ?, 'payment', 'unread', NOW(), ?)
            ");
            $stmtN->bind_param("ssss", $email, $notifTitle, $notifMsg, $postedBy);
            $stmtN->execute();
            $stmtN->close();
          }

          $conn->commit();
          echo json_encode(["ok" => true, "message" => "Payment cancelled."]);
          exit;
        }

      } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(["ok" => false, "error" => $e->getMessage()]);
        exit;
      }

    } else {
      echo json_encode(["ok" => false, "error" => "Invalid payment or reservation ID."]);
      exit;
    }
  }

  // ===================================================
  // 📌 FETCH PAYMENT LIST (PENDING ONLY, SAME AS ADMIN)
  // ===================================================
  $query = "
    SELECT 
      r.id AS reservation_id,
      r.code AS reservation_code,
      COALESCE(r.customer_name, CONCAT(u.first_name, ' ', u.last_name), '—') AS customer_name,
      u.email,
      r.package,
      r.type AS category,
      r.pax,
      r.time_slot,
      r.start_date,
      r.total_price,

      COALESCE(p.id, 0) AS payment_id,
      COALESCE(p.amount, 0) AS amount,
      COALESCE(p.payment_percent, 0) AS payment_percent,
      COALESCE(p.method_option, 0) AS method_option,
      COALESCE(p.proof_image, '') AS proof_image,
      COALESCE(p.status, 'pending') AS payment_status,
      COALESCE(p.created_at, r.created_at) AS payment_date

    FROM reservations r
    LEFT JOIN payments p ON r.id = p.reservation_id
    LEFT JOIN users u ON r.customer_id = u.id
    WHERE p.status = 'pending'
    ORDER BY r.created_at DESC
  ";

  $result   = $conn->query($query);
  $payments = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];

  $conn->close();
  ?>
  <!DOCTYPE html>
  <html lang="en">
  <head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>GCash Billing Verification - Cocovalley Staff Portal</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

  <style>
  :root {
    --primary: #004d99;
    --accent: #0b72d1;
    --accent-soft: rgba(11,114,209,0.08);

    --bg: #f7f7f7;
    --bg-soft: #ffffff;

    --border: #e5e7eb;
    --border-soft: #f0f0f0;

    --text: #111827;
    --muted: #6b7280;

    --shadow-soft: 0 14px 30px rgba(0,0,0,0.08);
    --sidebar-w: 260px;

    --pending: #f59e0b;
    --approved: #10b981;
    --rejected: #ef4444;
  }

  /* RESET + GLOBAL */
  * {
    box-sizing: border-box;
    margin: 0;
    padding: 0;
    font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
  }
  html, body { height: 100%; }
  body {
    background: var(--bg);
    color: var(--text);
    overflow-x: hidden;
  }
  a { text-decoration: none; color: inherit; }

  /* ===================== SIDEBAR (STAFF) ===================== */
  .sidebar {
    position: fixed;
    inset: 0 auto 0 0;
    width: var(--sidebar-w);
    background: #ffffff;
    border-right: 1px solid var(--border-soft);
    padding: 20px 18px;
    display: flex;
    flex-direction: column;
    gap: 12px;
    z-index: 20;
  }
  .sb-head {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 10px;
  }
  .sb-logo {
    width: 44px;
    height: 44px;
    border-radius: 14px;
    object-fit: cover;
    box-shadow: 0 8px 20px rgba(0,0,0,0.2);
  }
  .sb-title {
    font-size: 18px;
    font-weight: 800;
    color: var(--primary);
  }
  .sb-tag {
    font-size: 13px;
    color: var(--muted);
  }
  .nav {
    display: flex;
    flex-direction: column;
    gap: 4px;
    margin-top: 8px;
  }
  .nav-item,
  .nav-toggle {
    display: flex;
    align-items: center;
    justify-content: flex-start;
    gap: 10px;
    padding: 9px 10px;
    border-radius: 999px;
    font-size: 14px;
    color: #374151;
    transition: background 0.16s ease, transform 0.12s ease;
    cursor: pointer;
  }
  .nav-item i,
  .nav-toggle i.fa-calendar-days {
    width: 16px;
    text-align: center;
  }
  .nav-item:hover,
  .nav-toggle:hover {
    background: #f3f4f6;
    transform: translateY(-1px);
  }
  .nav-item.active {
    background: var(--accent-soft);
    color: var(--accent);
    font-weight: 600;
    box-shadow: 0 10px 22px rgba(11,114,209,0.25);
  }
  .nav-toggle {
    justify-content: space-between;
  }
  .nav-toggle .label {
    display: flex;
    align-items: center;
    gap: 10px;
  }
  .chev {
    font-size: 12px;
    transition: transform 0.2s ease;
    color: #9ca3af;
  }
  .chev.open {
    transform: rotate(180deg);
  }
  .submenu {
    display: none;
    flex-direction: column;
    gap: 4px;
    margin: 4px 0 8px 26px;
  }
  .submenu a {
    padding: 7px 10px;
    border-radius: 999px;
    font-size: 13px;
    color: #4b5563;
  }
  .submenu a:hover {
    background: #f3f4f6;
  }
  .submenu a.active {
    background: var(--accent-soft);
    color: var(--accent);
    font-weight: 600;
    box-shadow: 0 10px 22px rgba(11,114,209,0.25);
  }

  /* ===================== MAIN LAYOUT ===================== */
  .main {
    margin-left: var(--sidebar-w);
    padding: 26px 34px 40px;
    min-height: 100vh;
    display: flex;
    flex-direction: column;
    gap: 18px;
  }

  /* ===================== TOPBAR ===================== */
  .topbar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    background: #ffffff;
    border-radius: 999px;
    padding: 10px 18px;
    box-shadow: 0 12px 30px rgba(0,0,0,0.06);
    border: 1px solid rgba(15,23,42,0.04);
  }
  .topbar h1 {
    display: flex;
    align-items: center;
    gap: 10px;
    font-size: 18px;
    font-weight: 700;
    color: var(--primary);
  }
  .topbar h1::before {
    content: "\f555";
    font-family: "Font Awesome 6 Free";
    font-weight: 900;
    background: var(--accent-soft);
    color: var(--accent);
    border-radius: 999px;
    padding: 8px;
    font-size: 14px;
  }
  .topbar .sub {
    font-size: 13px;
    color: var(--muted);
    margin-top: 2px;
  }

  /* PROFILE (same style as admin list) */
  .profile {
    position: relative;
    display: flex;
    align-items: center;
    gap: 10px;
    cursor: pointer;
    padding: 6px 10px;
    border-radius: 999px;
    transition: background 0.16s ease;
  }
  .profile:hover {
    background: #f3f4f6;
  }
  .avatar {
    width: 34px;
    height: 34px;
    border-radius: 999px;
    background: linear-gradient(135deg, #bfdbfe, #1d4ed8);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 14px;
    color: #eff6ff;
    font-weight: 700;
    text-transform: uppercase;
    box-shadow: 0 10px 20px rgba(37, 99, 235, 0.35);
  }
  .dropdown {
    position: absolute;
    top: 42px;
    right: 0;
    min-width: 160px;
    background: #ffffff;
    border-radius: 14px;
    box-shadow: 0 16px 40px rgba(0,0,0,0.12);
    border: 1px solid #e5e7eb;
    display: none;
    flex-direction: column;
    overflow: hidden;
    z-index: 30;
  }
  .dropdown button {
    border: none;
    background: transparent;
    padding: 10px 14px;
    font-size: 14px;
    text-align: left;
    cursor: pointer;
  }
  .dropdown button:hover {
    background: #f3f4f6;
  }

  /* ===================== FILTER BAR ===================== */
  .section {
    display: flex;
    justify-content: flex-start;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
  }
  .controls {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
  }
  .input,
  .select,
  .btn {
    padding: 9px 11px;
    border-radius: 999px;
    border: 1px solid var(--border);
    background: #ffffff;
    font-size: 14px;
  }
  .input:focus,
  .select:focus {
    outline: none;
    border-color: var(--accent);
    box-shadow: 0 0 0 2px rgba(11,114,209,0.15);
  }
  .btn {
    cursor: pointer;
    font-weight: 600;
    color: #111827;
    display: inline-flex;
    align-items: center;
    gap: 6px;
  }
  .btn:hover {
    background: #eef4ff;
  }

  /* ===================== CARD & TABLE ===================== */
  .card {
    background: #ffffff;
    border-radius: 22px;
    border: 1px solid var(--border);
    box-shadow: 0 18px 40px rgba(0,0,0,0.08);
    overflow: hidden;
  }
  .card header {
    padding: 14px 18px;
    border-bottom: 1px solid var(--border);
    font-weight: 700;
    color: #111827;
    display: flex;
    align-items: center;
    justify-content: space-between;
  }
  .card header h2 {
    font-size: 16px;
  }
  .card header span.sub {
    font-size: 13px;
    color: var(--muted);
    font-weight: 400;
  }

  .table-wrapper {
    width: 100%;
    overflow-x: auto;
  }
  table {
    width: 100%;
    border-collapse: collapse;
    min-width: 900px;
  }
  th, td {
    padding: 11px 14px;
    text-align: center;
    font-size: 13px;
    border-bottom: 1px solid #f1f5f9;
    white-space: nowrap;
  }
  th:first-child,
  td:first-child {
    text-align: left;
  }
  thead {
    background: #f9fafb;
  }
  th {
    font-size: 12px;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    color: #6b7280;
    font-weight: 700;
  }
  tbody tr:hover {
    background: #f9fbff;
  }
  tbody tr:last-child td {
    border-bottom: none;
  }

  /* ===================== STATUS TAGS ===================== */
  .status {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    height: 30px;
    min-width: 90px;
    padding: 0 12px;
    border-radius: 999px;
    font-size: 12px;
    font-weight: 600;
    text-transform: capitalize;
    border: 1px solid transparent;
    color: #ffffff;
  }
  .status.approved {
    background: #dcfce7;
    color: #166534;
    border-color: #bbf7d0;
  }
  .status.pending {
    background: #fef3c7;
    color: #92400e;
    border-color: #fde68a;
  }
  .status.cancelled,
  .status.rejected {
    background: #fee2e2;
    color: #991b1b;
    border-color: #fecaca;
  }

  /* Payment label inside table */
  .text-success { color: #15803d; }
  .text-warning { color: #b45309; }
  .text-muted   { color: #6b7280; }
  .small        { font-size: 11px; }

  /* ===================== ACTION BUTTONS ===================== */
  td.action-cell {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    flex-wrap: wrap;
  }
  .btn-pill {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 7px 13px;
    border-radius: 999px;
    font-size: 12px;
    font-weight: 600;
    color: #ffffff;
    border: 1px solid transparent;
    cursor: pointer;
    transition: background 0.16s ease, box-shadow 0.16s ease, transform 0.1s ease;
    box-shadow: 0 4px 10px rgba(0,0,0,0.08);
  }
  .btn-pill i {
    font-size: 13px;
  }

  /* VIEW */
  .btn-view-proof {
    background: var(--accent);
    box-shadow: 0 6px 16px rgba(11,114,209,0.25);
  }
  .btn-view-proof:hover {
    background: #005bbb;
    transform: translateY(-1px);
    box-shadow: 0 10px 22px rgba(11,114,209,0.3);
  }

  /* APPROVE */
  .btn-approve {
    background: var(--approved);
    box-shadow: 0 6px 16px rgba(16,185,129,0.25);
  }
  .btn-approve:hover {
    background: #0fae73;
    transform: translateY(-1px);
    box-shadow: 0 10px 22px rgba(16,185,129,0.3);
  }

  /* CANCEL */
  .btn-reject {
    background: var(--rejected);
    box-shadow: 0 6px 16px rgba(239,68,68,0.25);
  }
  .btn-reject:hover {
    background: #dc2626;
    transform: translateY(-1px);
    box-shadow: 0 10px 22px rgba(239,68,68,0.3);
  }

  /* Disabled */
  .btn-pill.disabled,
  .btn-pill:disabled {
    opacity: 0.55;
    cursor: not-allowed;
    transform: none;
    box-shadow: none;
  }

  /* ===================== PROOF THUMB + IMAGE VIEWER ===================== */
  .proof-thumb {
    width: 70px;
    height: 70px;
    object-fit: cover;
    border-radius: 8px;
    cursor: pointer;
    box-shadow: 0 6px 14px rgba(0,0,0,0.2);
    transition: transform 0.15s ease, box-shadow 0.15s ease, filter 0.15s ease;
  }
  .proof-thumb:hover {
    transform: translateY(-2px) scale(1.03);
    box-shadow: 0 10px 20px rgba(0,0,0,0.3);
    filter: brightness(1.02);
  }

  /* Fullscreen viewer */
  .img-viewer {
    position: fixed;
    inset: 0;
    display: none;
    align-items: center;
    justify-content: center;
    z-index: 6000;
  }
  .img-viewer.active {
    display: flex;
  }
  .img-viewer-backdrop {
    position: absolute;
    inset: 0;
    background: rgba(0,0,0,0.78);
  }
  .img-viewer-content {
    position: relative;
    max-width: 90vw;
    max-height: 90vh;
    border-radius: 18px;
    overflow: hidden;
    box-shadow: 0 24px 60px rgba(0,0,0,0.55);
    background: #000;
  }
  .img-viewer-content img {
    display: block;
    max-width: 100%;
    max-height: 90vh;
    object-fit: contain;
  }
  .img-viewer-close {
    position: absolute;
    top: 10px;
    right: 10px;
    border: none;
    border-radius: 999px;
    width: 34px;
    height: 34px;
    background: rgba(15,23,42,0.85);
    color: #f9fafb;
    font-size: 20px;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
  }
  .img-viewer-close:hover {
    background: rgba(248,250,252,0.95);
    color: #111827;
  }

  /* ===================== RESPONSIVE ===================== */
  @media (max-width: 900px) {
    .sidebar {
      display: none;
    }
    .main {
      margin-left: 0;
      padding: 18px 16px 30px;
    }
    .topbar {
      border-radius: 18px;
    }
  }
  @media (max-width: 640px) {
    .card {
      border-radius: 18px;
    }
    table {
      min-width: 760px;
    }
  }
  </style>
  </head>
  <body>

  <!-- ✅ SIDEBAR (STAFF) -->
  <aside class="sidebar" id="sidebar">
    <div class="sb-head">
      <img src="logo.jpg" class="sb-logo" alt="Cocovalley Logo">
      <div>
        <div class="sb-title">Cocovalley</div>
        <div class="sb-tag">Staff Portal</div>
      </div>
    </div>

    <nav class="nav">
      <a href="staff-dashboard.php" class="nav-item">
        <i class="fa-solid fa-house"></i> Dashboard
      </a>

      <div class="nav-group">
        <div class="nav-toggle" id="resToggle">
          <div class="label">
            <i class="fa-solid fa-calendar-days"></i>
            <span>Reservations</span>
          </div>
          <i class="fa-solid fa-chevron-down chev" id="chev"></i>
        </div>
        <div class="submenu" id="resMenu">
          <a href="staff-calendar.php">Calendar View</a>
          <a href="staff-reservation-list.php">List View</a>
          <a href="staff-walkin.php" id="walkinLink">Walk-in</a>
        </div>
      </div>

      <a href="staff-payment.php" class="nav-item active">
        <i class="fa-solid fa-receipt"></i> Payment Proofs
      </a>
      <a href="staff-customer-list.php" class="nav-item">
        <i class="fa-solid fa-users"></i> Customer List
      </a>
      <a href="staff-notification.php" class="nav-item">
        <i class="fa-solid fa-bell"></i> Notifications
      </a>
      <a href="staff-announcement.php" class="nav-item">
        <i class="fa-solid fa-bullhorn"></i> Announcements
      </a>
    </nav>
  </aside>

  <!-- ===================== MAIN ===================== -->
  <main class="main">

    <!-- TOPBAR -->
    <div class="topbar">
      <div>
        <h1>GCash Billing Verification</h1>
        <div class="sub">Review and manage all GCash billing proofs.</div>
      </div>

      <div class="profile" id="profileBtn">
        <div class="avatar">
          <?php
            $initial = strtoupper(substr(trim($meName), 0, 1));
            echo htmlspecialchars($initial);
          ?>
        </div>
        <span><?= htmlspecialchars($meName ?? 'Coco Valley (Staff)') ?></span>
        <i class="fa-solid fa-chevron-down"></i>

        <div class="dropdown" id="dropdown">
          <button type="button" onclick="openLogout()">Logout</button>
        </div>
      </div>
    </div>

    <!-- FILTER BAR -->
    <section class="section">
      <div class="controls">
        <input class="input" id="searchBilling" placeholder="Search customer or package...">
        <select class="select" id="statusFilter">
          <option value="all">All status</option>
          <option value="pending">Pending</option>
          <option value="approved">Approved</option>
          <option value="cancelled">Cancelled</option>
        </select>
        <button class="btn" id="clearFilters">
          <i class="fa-solid fa-rotate"></i> Clear
        </button>
      </div>
    </section>

    <!-- PAYMENT TABLE CARD -->
    <section class="card">
      <header>
        <h2>Payment Proof</h2>
        <span class="sub">Click "View" to open full billing summary & send receipt.</span>
      </header>

      <div class="table-wrapper">
        <table>
          <thead>
            <tr>
              <th>Customer</th>
              <th>Package</th>
              <th>Pax</th>
              <th>Time Slot</th>
              <th>Date Reserved</th>
              <th>Payment</th>
              <th>Proof</th>
              <th>Status</th>
              <th>Action</th>
            </tr>
          </thead>

          <tbody id="billingTbody">
          <?php if (!empty($payments)): ?>
            <?php foreach ($payments as $p):

              $status      = strtolower($p['payment_status'] ?? 'pending');
              $percent     = (float)($p['payment_percent'] ?? 0);
              $has_payment = !empty($p['payment_id']);

              if ($percent >= 100) {
                $label        = "Fully Paid";
                $label_class  = "text-success";
                $status_class = "approved";
              } elseif ($percent >= 50) {
                $label        = "50% Paid";
                $label_class  = "text-warning";
                $status_class = "pending";
              } else {
                $label        = "Unpaid";
                $label_class  = "text-muted";
                $status_class = "pending";
              }

              $dateReserved = !empty($p['start_date'])
                ? date("M d, Y", strtotime($p['start_date']))
                : '—';

            ?>
            <tr 
              data-id="<?= (int)$p['payment_id'] ?>" 
              data-res-id="<?= (int)$p['reservation_id'] ?>" 
              data-status="<?= htmlspecialchars($status) ?>"
            >
              <td><?= htmlspecialchars($p['customer_name'] ?? '—') ?></td>
              <td><?= htmlspecialchars(ucfirst($p['package'] ?? '—')) ?></td>
              <td><?= htmlspecialchars($p['pax'] ?? '—') ?></td>
              <td><?= htmlspecialchars(ucfirst($p['time_slot'] ?? '—')) ?></td>
              <td><?= $dateReserved ?></td>

              <td>
                <strong class="<?= $label_class ?>"><?= $label ?></strong><br>
                <span class="small text-muted">
                  ₱<?= number_format((float)$p['amount'], 2) ?>
                </span>
              </td>

              <td>
  <?php if (!empty($p['proof_image'])): ?>
    <?php
      // FIX: force file to load from admin/uploads/
      $filename = basename($p['proof_image']);
      $proofSrc = "../admin/uploads/" . $filename;
    ?>
    <img
      src="<?= htmlspecialchars($proofSrc) ?>"
      alt="GCash Proof"
      class="proof-thumb"
      data-full="<?= htmlspecialchars($proofSrc) ?>">
  <?php else: ?>
    <span class="text-muted small">No proof</span>
  <?php endif; ?>
</td>


              <td>
                <span class="status <?= $status_class ?>">
                  <?= $label ?>
                </span>
              </td>

              <td class="action-cell">
                <?php if ($has_payment): ?>
                  <button class="btn-pill btn-view-proof"
                          data-id="<?= $p['payment_id'] ?>"
                          data-res-id="<?= $p['reservation_id'] ?>">
                    <i class="fa-solid fa-eye"></i> View
                  </button>

                  <?php if ($status !== 'approved'): ?>
                    <button class="btn-pill btn-approve"
                            data-id="<?= $p['payment_id'] ?>"
                            data-res-id="<?= $p['reservation_id'] ?>">
                      <i class="fa-solid fa-check"></i> Approve
                    </button>

                    <button class="btn-pill btn-reject"
                            data-id="<?= $p['payment_id'] ?>"
                            data-res-id="<?= $p['reservation_id'] ?>">
                      <i class="fa-solid fa-xmark"></i> Cancel
                    </button>
                  <?php else: ?>
                    <span class="text-muted small">Approved</span>
                  <?php endif; ?>

                <?php else: ?>
                  <button class="btn-pill disabled" disabled>
                    <i class="fa-solid fa-ban"></i> No Payment
                  </button>
                <?php endif; ?>
              </td>
            </tr>
            <?php endforeach; ?>
          <?php else: ?>
            <tr>
              <td colspan="9" style="text-align:center;color:#9ca3af;padding:18px;">
                No payment records found.
              </td>
            </tr>
          <?php endif; ?>
          </tbody>
        </table>
      </div>
    </section>
  </main>

  <!-- 🔍 FULLSCREEN IMAGE VIEWER -->
  <div class="img-viewer" id="imgViewer">
    <div class="img-viewer-backdrop"></div>
    <div class="img-viewer-content">
      <button class="img-viewer-close" id="imgViewerClose">&times;</button>
      <img id="imgViewerImg" src="" alt="GCash Proof Preview">
    </div>
  </div>

  <script>
  document.addEventListener("DOMContentLoaded", () => {
    console.log("✅ Cocovalley staff-payment loaded");

    // Sidebar Reservations submenu open by default
    const resToggle = document.getElementById("resToggle");
    const resMenu   = document.getElementById("resMenu");
    const chev      = document.getElementById("chev");

    if (resToggle && resMenu && chev) {
      resToggle.addEventListener("click", () => {
        const open = resMenu.style.display === "flex";
        resMenu.style.display = open ? "none" : "flex";
        chev.classList.toggle("open", !open);
      });
      resMenu.style.display = "flex";
      chev.classList.add("open");
    }

    // Profile dropdown
    const profileBtn = document.getElementById("profileBtn");
    const dropdown   = document.getElementById("dropdown");

    if (profileBtn && dropdown) {
      profileBtn.addEventListener("click", (e) => {
        e.stopPropagation();
        dropdown.style.display = dropdown.style.display === "flex" ? "none" : "flex";
      });

      document.addEventListener("click", (e) => {
        if (!profileBtn.contains(e.target)) {
          dropdown.style.display = "none";
        }
      });
    }

    // Logout
    window.openLogout = function () {
      if (confirm("Logout from Cocovalley Staff?")) {
        window.location.href = "admin-login.php";
      }
    };

    // Inject PHP array → JS
    window.payments = <?php
      echo json_encode(
        $payments ?? [],
        JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
      );
    ?>;

    const tbody        = document.getElementById("billingTbody");
    const searchInput  = document.getElementById("searchBilling");
    const statusFilter = document.getElementById("statusFilter");
    const clearBtn     = document.getElementById("clearFilters");

    // Simple client-side filter
    function applyFilter() {
      const q       = (searchInput.value || "").toLowerCase();
      const statusF = (statusFilter.value || "all").toLowerCase();

      Array.from(tbody.querySelectorAll("tr")).forEach(row => {
        const cells = row.querySelectorAll("td");
        if (!cells.length) return;

        const name    = (cells[0].textContent || "").toLowerCase();
        const pkg     = (cells[1].textContent || "").toLowerCase();
        const rowStat = (row.dataset.status || "pending").toLowerCase();

        const matchesSearch = !q || name.includes(q) || pkg.includes(q);
        const matchesStatus = statusF === "all" || rowStat === statusF;

        row.style.display = (matchesSearch && matchesStatus) ? "" : "none";
      });
    }

    if (searchInput) {
      searchInput.addEventListener("input", applyFilter);
    }
    if (statusFilter) {
      statusFilter.addEventListener("change", applyFilter);
    }
    if (clearBtn) {
      clearBtn.addEventListener("click", () => {
        searchInput.value = "";
        statusFilter.value = "all";
        applyFilter();
      });
    }

    // ====================== IMAGE VIEWER LOGIC ======================
    const viewer      = document.getElementById("imgViewer");
    const viewerImg   = document.getElementById("imgViewerImg");
    const viewerClose = document.getElementById("imgViewerClose");

    function openViewer(src) {
      if (!src) return;
      viewerImg.src = src;
      viewer.classList.add("active");
    }

    function closeViewer() {
      viewer.classList.remove("active");
      viewerImg.src = "";
    }

    if (viewerClose) {
      viewerClose.addEventListener("click", closeViewer);
    }
    if (viewer) {
      viewer.addEventListener("click", (e) => {
        if (e.target === viewer || e.target.classList.contains("img-viewer-backdrop")) {
          closeViewer();
        }
      });
    }

    // Global click listener
    document.body.addEventListener("click", (e) => {
      const thumb = e.target.closest(".proof-thumb");
      if (thumb) {
        const full = thumb.dataset.full || thumb.src;
        openViewer(full);
        return;
      }

      const viewBtn    = e.target.closest(".btn-view-proof");
      const approveBtn = e.target.closest(".btn-approve");
      const cancelBtn  = e.target.closest(".btn-reject");

      // VIEW BILLING PAGE (same page as admin uses)
      if (viewBtn) {
        const id = viewBtn.dataset.id;
        window.location.href = `admin-send-billing-proof.php?payment_id=${encodeURIComponent(id)}`;
        return;
      }

      // APPROVE / CANCEL via shared handler
      if (approveBtn) {
        updateStatus(approveBtn.dataset.id, approveBtn.dataset.resId, "approve");
        return;
      }

      if (cancelBtn) {
        updateStatus(cancelBtn.dataset.id, cancelBtn.dataset.resId, "cancel");
        return;
      }
    });

    // Approve / Cancel via AJAX → shared admin handler
    async function updateStatus(paymentId, reservationId, action) {
      paymentId     = parseInt(paymentId, 10);
      reservationId = parseInt(reservationId, 10);

      if (!paymentId) {
        alert("❌ No payment uploaded yet.");
        return;
      }

      const payment = window.payments.find(
        (p) => parseInt(p.payment_id, 10) === paymentId
      );
      if (!payment) {
        alert("Payment not found.");
        return;
      }

      if (!confirm(`Are you sure you want to ${action} this payment?`)) return;

      try {
        const res = await fetch("../admin/handlers/payment_action.php", {
          method: "POST",
          headers: {
            "Content-Type": "application/json",
            "X-Requested-With": "XMLHttpRequest",
          },
          body: JSON.stringify({
            action,
            payment_id: paymentId,
            reservation_id: reservationId,
            email: payment.email,
          }),
        });

        const data = await res.json().catch(() => ({}));
        console.log("payment_action response (staff):", data);

        if (!data.success) {
          alert(`❌ Error: ${data.message || "Update failed."}`);
          return;
        }

        alert(data.message || "✅ Payment updated!");

        // After approve/cancel, balik sa staff-reservation-list for sync
        setTimeout(() => {
          window.location.href = "staff-reservation-list.php";
        }, 500);

      } catch (err) {
        console.error(err);
        alert("❌ Network error.");
      }
    }
  });
  </script>
  </body>
  </html>
