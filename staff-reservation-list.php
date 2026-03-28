<?php
// ============================================================
// 🔐 SECURE SESSION + STAFF ROLE ONLY
// ============================================================
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

if (
    empty($_SESSION['user_id']) ||
    (($_SESSION['role'] ?? '') !== 'staff')
) {
    header('Location: admin-login.php');
    exit;
}

$meName = $_SESSION['name'] ?? 'Staff';

// DB
require_once '../admin/handlers/db_connect.php';

// ============================================================
// 🔐 CSRF TOKEN (reserve if you want POST later)
// ============================================================
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}
$CSRF = $_SESSION['csrf'];

// ============================================================
// 📌 FETCH RESERVATIONS (SAME LOGIC AS ADMIN LIST)
// ============================================================
$reservations = [];
$total = $pending = $approved = $cancelled = 0;

$sql = "
    SELECT 
        r.id,
        r.code,

        p.id AS payment_id,

        COALESCE(
            wc.full_name,
            CONCAT(u.first_name, ' ', u.last_name),
            r.customer_name
        ) AS customer_name,

        COALESCE(wc.customer_email, u.email, '') AS customer_email,
        COALESCE(wc.phone, u.phone, '')          AS contact,

        r.status,
        r.type AS category,
        r.package,
        r.time_slot,
        r.pax,
        r.total_price,

        r.start_date,
        r.end_date,

        r.payment_status  AS res_payment_status,
        r.payment_percent AS res_payment_percent,

        COALESCE(p.method, 'GCash') AS method,
        COALESCE(p.method_option, 0) AS method_option,
        COALESCE(p.payment_percent, r.payment_percent) AS payment_percent,
        COALESCE(p.amount, 0) AS amount,
        COALESCE(p.proof_image, '') AS proof_image,
        COALESCE(p.payment_status, r.payment_status) AS payment_status

    FROM reservations r
    LEFT JOIN users u 
        ON r.customer_id = u.id
    LEFT JOIN walkin_customers wc 
        ON wc.reservation_id = r.id

    LEFT JOIN (
        SELECT 
            id,
            reservation_id,
            method,
            method_option,
            payment_percent,
            amount,
            proof_image,
            payment_status
        FROM payments
        WHERE id IN (
            SELECT MAX(id)
            FROM payments
            GROUP BY reservation_id
        )
    ) p ON p.reservation_id = r.id

    ORDER BY r.id DESC
";

$result = $conn->query($sql);

// ============================================================
// 📌 PROCESS RESULTS
// ============================================================
if ($result && $result->num_rows > 0) {
    while ($r = $result->fetch_assoc()) {

        $status = strtolower(trim($r['status'] ?? 'pending'));
        if (!in_array($status, ['pending','approved','cancelled'], true)) {
            $status = 'pending';
        }
        $r['status'] = ucfirst($status);

        $r['customer_name'] = trim($r['customer_name'] ?? 'Walk-in Customer');
        $r['category']      = ucfirst(trim($r['category'] ?? '—'));
        $r['package']       = trim($r['package'] ?? '—');
        $r['time_slot']     = trim($r['time_slot'] ?? '—');
        $r['pax']           = (int)($r['pax'] ?? 0);

        if (empty($r['end_date'])) {
            $r['end_date'] = $r['start_date'];
        }

        $rawAmount       = (float)($r['amount'] ?? 0);
        $r['amount_raw'] = $rawAmount;
        $r['amount']     = number_format($rawAmount, 2);

        $opt = (int)$r['method_option'];
        $r['payment_label'] =
            ($opt === 100) ? '100% Fully Paid' :
            (($opt === 50) ? '50% Down Payment' : 'Unpaid');

        $reservations[] = $r;

        if ($status === 'pending')   $pending++;
        if ($status === 'approved')  $approved++;
        if ($status === 'cancelled') $cancelled++;
        $total++;
    }
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Reservation List | Cocovalley Staff Portal</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <style>
    :root {
      --primary: #222222;
      --accent: #0b72d1;
      --accent-soft: rgba(11, 114, 209, 0.08);
      --bg: #f7f7f7;
      --bg-soft: #ffffff;
      --border: #e5e7eb;
      --border-soft: #f0f0f0;
      --text: #111827;
      --muted: #6b7280;
      --shadow-soft: 0 14px 30px rgba(0,0,0,0.08);
      --sidebar-w: 260px;

      --pending: #f59e0b;
      --approved: #22c55e;
      --cancelled: #ef4444;
    }

    * {
      margin: 0;
      padding: 0;
      box-sizing: border-box;
      font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
    }

    body {
      background: var(--bg);
      color: var(--text);
      overflow-x: hidden;
    }

    a {
      text-decoration: none;
      color: inherit;
    }

    /* SIDEBAR */
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
    }

    .nav-group {
      display: flex;
      flex-direction: column;
      gap: 4px;
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
    }

    /* MAIN */
    .main {
      margin-left: var(--sidebar-w);
      padding: 26px 34px 40px;
      min-height: 100vh;
    }

    /* TOPBAR */
    .topbar {
      display: flex;
      align-items: center;
      justify-content: space-between;
      background: #ffffff;
      border-radius: 999px;
      padding: 10px 18px;
      box-shadow: 0 12px 30px rgba(0,0,0,0.06);
      border: 1px solid rgba(15,23,42,0.04);
      margin-bottom: 22px;
    }

    .topbar h1 {
      display: flex;
      align-items: center;
      gap: 10px;
      font-size: 18px;
      font-weight: 700;
      color: var(--primary);
    }

    .topbar h1 i {
      background: var(--accent-soft);
      color: var(--accent);
      border-radius: 999px;
      padding: 8px;
      font-size: 14px;
    }

    .admin {
      display: flex;
      align-items: center;
      gap: 10px;
      cursor: pointer;
      position: relative;
      font-size: 14px;
      color: var(--primary);
      font-weight: 500;
      padding: 4px 8px;
      border-radius: 999px;
      transition: background 0.16s ease;
    }

    .admin:hover {
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

    .dropdown a {
      padding: 10px 14px;
      font-size: 14px;
      color: #111827;
    }

    .dropdown a:hover {
      background: #f3f4f6;
    }

    /* STATS */
    .stats {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
      gap: 14px;
      margin-bottom: 16px;
    }

    .stat {
      display: flex;
      gap: 10px;
      align-items: center;
      padding: 12px 14px;
      border-radius: 18px;
      background: #ffffff;
      border: 1px solid #e5e7eb;
      box-shadow: 0 10px 22px rgba(0,0,0,0.04);
      cursor: pointer;
      transition: box-shadow 0.18s ease, transform 0.12s ease, border 0.18s ease;
    }

    .stat:hover {
      transform: translateY(-2px);
      box-shadow: 0 16px 35px rgba(0,0,0,0.06);
    }

    .stat.active {
      border-color: rgba(11, 114, 209, 0.5);
      box-shadow: 0 18px 40px rgba(11, 114, 209, 0.25);
    }

    .stat .ico {
      width: 40px;
      height: 40px;
      border-radius: 999px;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 16px;
      color: #ffffff;
    }

    .stat .meta {
      display: flex;
      flex-direction: column;
      gap: 2px;
    }

    .stat .meta .k {
      font-size: 11px;
      text-transform: uppercase;
      letter-spacing: 0.08em;
      color: var(--muted);
      font-weight: 700;
    }

    .stat .meta .v {
      font-size: 20px;
      font-weight: 800;
      color: var(--primary);
    }

    .ico.all      { background: linear-gradient(135deg,#0ea5e9,#6366f1); }
    .ico.p        { background: var(--pending); }
    .ico.a        { background: var(--approved); }
    .ico.c        { background: var(--cancelled); }

    /* CARD + TABLE */
    .card {
      background: #ffffff;
      border-radius: 22px;
      border: 1px solid #e5e7eb;
      box-shadow: 0 18px 40px rgba(0,0,0,0.08);
      overflow: hidden;
    }

    .card header {
      padding: 14px 18px;
      border-bottom: 1px solid #e5e7eb;
      font-weight: 700;
      color: #111827;
      display: flex;
      align-items: center;
      justify-content: space-between;
    }

    .card header span.sub {
      font-size: 13px;
      color: var(--muted);
      font-weight: 400;
    }

    .tablewrap {
      width: 100%;
      overflow-x: auto;
    }

    table {
      width: 100%;
      border-collapse: collapse;
      min-width: 980px;
    }

    thead {
      background: #f9fafb;
    }

    th, td {
      padding: 11px 14px;
      text-align: left;
      font-size: 13px;
      border-bottom: 1px solid #f1f5f9;
      white-space: nowrap;
    }

    th {
      font-size: 12px;
      text-transform: uppercase;
      letter-spacing: 0.08em;
      color: #6b7280;
      font-weight: 700;
    }

    tbody tr:hover {
      background: #f9fafb;
    }

    tbody tr:last-child td {
      border-bottom: none;
    }

    /* STATUS TAGS */
    .status {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 4px 10px;
      border-radius: 999px;
      font-size: 12px;
      font-weight: 600;
      text-transform: capitalize;
      border: 1px solid transparent;
    }

    .status.pending {
      background: #fef3c7;
      color: #92400e;
      border-color: #fde68a;
    }

    .status.approved {
      background: #dcfce7;
      color: #166534;
      border-color: #bbf7d0;
    }

    .status.cancelled {
      background: #fee2e2;
      color: #991b1b;
      border-color: #fecaca;
    }

    /* PAYMENT LABEL */
    .pay-label {
      font-size: 12px;
      font-weight: 600;
      color: #111827;
    }
    .pay-label + small {
      font-size: 11px;
      color: #6b7280;
    }

    /* VIEW BUTTON */
    .btn-view {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 7px 11px;
      border-radius: 999px;
      border: 1px solid #e5e7eb;
      background: #ffffff;
      font-size: 12px;
      cursor: pointer;
      transition: background 0.16s ease, box-shadow 0.16s ease, transform 0.1s ease;
    }

    .btn-view i {
      font-size: 12px;
      color: #4b5563;
    }

    .btn-view:hover {
      background: #f9fafb;
      box-shadow: 0 8px 16px rgba(0,0,0,0.06);
      transform: translateY(-1px);
    }

    /* MODAL BACKDROP */
    .modal-backdrop {
      position: fixed;
      inset: 0;
      background: rgba(0,0,0,0.45);
      display: none;
      justify-content: center;
      align-items: center;
      z-index: 9999;
      padding: 16px;
    }

    /* RECEIPT MODAL */
    .receipt-modal {
      width: 520px;
      max-width: 100%;
      background: #ffffff;
      border-radius: 24px;
      padding: 22px 24px 20px;
      box-shadow: 0 30px 80px rgba(0,0,0,0.36);
      position: relative;
      animation: fadeIn 0.22s ease-out;
    }

    .mini-modal {
      width: 420px;
    }

    .receipt-logo {
      text-align: center;
      margin-bottom: 16px;
    }

    .receipt-logo img {
      width: 76px;
      height: 76px;
      border-radius: 24px;
      object-fit: cover;
      margin-bottom: 6px;
      box-shadow: 0 12px 30px rgba(0,0,0,0.4);
    }

    .receipt-logo h2 {
      font-size: 18px;
      font-weight: 800;
      color: #111827;
      margin-bottom: 4px;
    }

    .receipt-logo p {
      font-size: 13px;
      color: #6b7280;
    }

    .receipt-section {
      margin-top: 14px;
    }

    .section-title {
      font-size: 14px;
      font-weight: 700;
      color: #111827;
      margin-bottom: 8px;
    }

    .info-row {
      display: flex;
      justify-content: space-between;
      font-size: 13px;
      padding: 4px 0;
      color: #4b5563;
    }

    .info-row span {
      color: #6b7280;
    }

    .info-row strong {
      color: #111827;
      font-weight: 600;
    }

    .receipt-divider {
      border: none;
      border-top: 1px dashed #d4d4d8;
      margin: 16px 0;
    }

    .receipt-footer {
      margin-top: 10px;
      display: flex;
      justify-content: space-between;
      align-items: center;
      gap: 10px;
    }

    .btn {
      border-radius: 999px;
      border: none;
      padding: 8px 14px;
      font-size: 13px;
      font-weight: 600;
      cursor: pointer;
      transition: background 0.16s ease, transform 0.12s ease, box-shadow 0.16s ease;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 6px;
    }

    .btn.gray {
      background: #f3f4f6;
      color: #111827;
    }

    .btn.gray:hover {
      background: #e5e7eb;
    }

    .btn.blue {
      background: #111827;
      color: #ffffff;
    }

    .btn.blue:hover {
      background: #020617;
      box-shadow: 0 10px 20px rgba(15,23,42,0.3);
      transform: translateY(-1px);
    }

    .btn.red {
      background:#ef4444;
      color:#fff;
    }
    .btn.red:hover {
      background:#b91c1c;
      box-shadow:0 10px 20px rgba(185,28,28,0.35);
      transform:translateY(-1px);
    }

    .btn:disabled {
      opacity: 0.4;
      cursor: default;
      box-shadow: none;
      transform: none;
    }

    .action-buttons {
      display: flex;
      gap: 8px;
    }

    .full-btn {
      margin-top: 12px;
      width: 100%;
    }

    @keyframes fadeIn {
      from { opacity: 0; transform: translateY(6px); }
      to   { opacity: 1; transform: translateY(0); }
    }

    /* RESPONSIVE */
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
      .stats {
        grid-template-columns: 1fr 1fr;
      }
    }

    @media (max-width: 640px) {
      .stats {
        grid-template-columns: 1fr;
      }
      .receipt-modal {
        padding: 18px 16px 16px;
        border-radius: 20px;
      }
    }
  </style>
</head>
<body>

<!-- ===== SIDEBAR (STAFF) ===== -->
<aside class="sidebar">
  <div class="sb-head">
    <img src="logo.jpg" class="sb-logo" alt="Cocovalley Logo">
    <div>
      <div class="sb-title">Cocovalley</div>
      <div class="sb-tag">Staff Portal</div>
    </div>
  </div>

  <nav class="nav">
    <a href="staff-dashboard.php" class="nav-item">
      <i class="fa-solid fa-house"></i>Dashboard
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
        <a href="staff-reservation-list.php" class="active">List View</a>
        <a href="staff-walkin.php" id="walkinLink">Walk-in</a>
      </div>
    </div>

    <a href="staff-payment.php" class="nav-item">
      <i class="fa-solid fa-receipt"></i>Payment Proofs
    </a>
    <a href="staff-customer-list.php" class="nav-item">
      <i class="fa-solid fa-users"></i>Customer List
    </a>
    <a href="staff-notification.php" class="nav-item">
      <i class="fa-solid fa-bell"></i>Notifications
    </a>
    <a href="staff-announcement.php" class="nav-item">
      <i class="fa-solid fa-bullhorn"></i>Announcements
    </a>
  </nav>
</aside>

<!-- ===== MAIN ===== -->
<main class="main">

  <!-- TOPBAR -->
  <div class="topbar">
    <h1>
      <i class="fa-solid fa-list"></i>
      Reservation List
    </h1>

    <div class="admin" onclick="toggleDropdown()">
      <div class="avatar">
        <?php
          $initial = strtoupper(substr(trim($meName), 0, 1));
          echo htmlspecialchars($initial);
        ?>
      </div>
      <span><?= htmlspecialchars($meName) ?> ▾</span>

      <div class="dropdown" id="dropdown">
        <a href="staff-logout.php">Logout</a>
      </div>
    </div>
  </div>

  <!-- STATS -->
  <section class="stats">
    <div class="stat active" data-status="all">
      <div class="ico all"><i class="fa-solid fa-layer-group"></i></div>
      <div class="meta">
        <div class="k">Total Reservations</div>
        <div class="v" id="cTotal"><?= $total ?></div>
      </div>
    </div>

    <div class="stat" data-status="pending">
      <div class="ico p"><i class="fa-solid fa-hourglass-half"></i></div>
      <div class="meta">
        <div class="k">Pending</div>
        <div class="v" id="cPending"><?= $pending ?></div>
      </div>
    </div>

    <div class="stat" data-status="approved">
      <div class="ico a"><i class="fa-solid fa-circle-check"></i></div>
      <div class="meta">
        <div class="k">Approved</div>
        <div class="v" id="cApproved"><?= $approved ?></div>
      </div>
    </div>

    <div class="stat" data-status="cancelled">
      <div class="ico c"><i class="fa-solid fa-circle-xmark"></i></div>
      <div class="meta">
        <div class="k">Cancelled</div>
        <div class="v" id="cCancelled"><?= $cancelled ?></div>
      </div>
    </div>
  </section>

  <!-- TABLE CARD -->
  <section class="card">
    <header>
      <span>Reservations</span>
      <span class="sub">Click “View” to open full reservation summary</span>
    </header>

    <div class="tablewrap">
      <table id="resTable">
        <thead>
          <tr>
            <th>No</th>
            <th>Customer</th>
            <th>Email</th>
            <th>Status</th>
            <th>Type</th>
            <th>Package</th>
            <th>Time Slot</th>
            <th>Pax</th>
            <th>Payment</th>
            <th>Action</th>
          </tr>
        </thead>

        <tbody>
        <?php if (!empty($reservations)): ?>
          <?php foreach ($reservations as $i => $r): ?>
            <?php  
              $status = strtolower($r['status'] ?? 'pending');
              if (!in_array($status, ['pending','approved','cancelled'])) {
                $status = 'pending';
              }
              $cleanAmount = (float)str_replace(',', '', ($r['amount'] ?? 0));
              $percentVal  = (float)($r['payment_percent'] ?? 0);
            ?>
            <tr
              data-id="<?= (int)$r['id'] ?>"
              data-code="<?= htmlspecialchars($r['code']) ?>"

              data-name="<?= htmlspecialchars($r['customer_name']) ?>"
              data-email="<?= htmlspecialchars($r['customer_email'] ?? '') ?>"
              data-status="<?= htmlspecialchars($status) ?>"

              data-category="<?= htmlspecialchars($r['category']) ?>"
              data-package="<?= htmlspecialchars($r['package']) ?>"
              data-timeslot="<?= htmlspecialchars($r['time_slot']) ?>"

              data-pax="<?= (int)$r['pax'] ?>"
              data-start="<?= htmlspecialchars($r['start_date']) ?>"
              data-end="<?= htmlspecialchars($r['end_date']) ?>"

              data-amount="<?= $cleanAmount ?>"
              data-paymentlabel="<?= htmlspecialchars($r['payment_label']) ?>"
              data-paymentstatus="<?= htmlspecialchars($r['payment_status']) ?>"
              data-percent="<?= $percentVal ?>"

              data-paymentid="<?= htmlspecialchars($r['payment_id'] ?? '') ?>"
            >
              <td><?= $i + 1 ?></td>
              <td><?= htmlspecialchars($r['customer_name']) ?></td>
              <td><?= htmlspecialchars($r['customer_email'] ?? '') ?></td>

              <td>
                <span class="status <?= $status ?>">
                  <?= ucfirst($status) ?>
                </span>
              </td>

              <td><?= ucfirst(htmlspecialchars($r['category'])) ?></td>
              <td><?= htmlspecialchars($r['package']) ?></td>
              <td><?= htmlspecialchars($r['time_slot']) ?></td>
              <td><?= (int)$r['pax'] ?></td>

              <td>
                <span class="pay-label"><?= htmlspecialchars($r['payment_label']) ?></span><br>
                <small>₱<?= htmlspecialchars($r['amount']) ?></small>
              </td>

              <td>
                <button class="btn-view">
                  <i class="fa-regular fa-eye"></i> View
                </button>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php else: ?>
          <tr>
            <td colspan="10" style="text-align:center; color:#64748b; padding:18px;">
              No reservations found.
            </td>
          </tr>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </section>

  <!-- ================================
       STAFF VIEW / APPROVE MODAL
  ================================ -->
  <div class="modal-backdrop" id="viewModal">
    <div class="receipt-modal">

      <div class="receipt-logo">
        <img src="logo.jpg" alt="Logo">
        <h2>Cocovalley Resort</h2>
        <p>Reservation Summary</p>
      </div>

      <!-- RESERVATION DETAILS -->
      <div class="receipt-section">
        <div class="section-title">Reservation Details</div>

        <div class="info-row">
          <span>Reservation Code:</span>
          <strong id="vCode">—</strong>
        </div>

        <div class="info-row">
          <span>Customer Name:</span>
          <strong id="vName">—</strong>
        </div>

        <div class="info-row">
          <span>Status:</span>
          <strong id="vStatus">—</strong>
        </div>

        <div class="info-row">
          <span>Category:</span>
          <strong id="vType">—</strong>
        </div>

        <div class="info-row">
          <span>Package:</span>
          <strong id="vPackage">—</strong>
        </div>

        <div class="info-row">
          <span>Date:</span>
          <strong id="vDate">—</strong>
        </div>

        <div class="info-row">
          <span>Time Slot:</span>
          <strong id="vTimeSlot">—</strong>
        </div>

        <div class="info-row">
          <span>Pax:</span>
          <strong id="vPax">—</strong>
        </div>
      </div>

      <!-- PAYMENT SECTION -->
      <div class="receipt-section">
        <div class="section-title">Payment Information</div>

        <div class="info-row">
          <span>Payment Option:</span>
          <strong id="vPaymentLabel">—</strong>
        </div>

        <div class="info-row">
          <span>Payment Status:</span>
          <strong id="vPaymentStatus">—</strong>
        </div>

        <div class="info-row">
          <span>Total Amount:</span>
          <strong id="vAmount">₱0.00</strong>
        </div>

        <!-- STAFF MARK AS FULLY PAID BUTTON -->
        <button class="btn blue full-btn" id="btnFull">
          Mark as Fully Paid (100%)
        </button>
      </div>

      <hr class="receipt-divider">

      <!-- FOOTER BUTTONS -->
      <div class="receipt-footer">
        <button class="btn gray" onclick="closeReceipt()">Close</button>

        <div class="action-buttons">
          <button class="btn red" id="btnCancel">Cancel</button>
          <button class="btn blue" id="btnApprove">Approve</button>
        </div>
      </div>

    </div>
  </div>

  <!-- ================================
       CONFIRM FULLY PAID POPUP MODAL
  ================================ -->
  <div class="modal-backdrop" id="fullModal">
    <div class="receipt-modal mini-modal">
      <div class="receipt-section">
        <div class="section-title">Mark as Fully Paid</div>
        <p style="font-size:13px;color:#4b5563;margin-bottom:16px;">
          Mark this reservation as <strong>FULLY PAID (100%)</strong>?<br>
          This will update the payment status to 100% and finalize the billing.
        </p>
        <div class="receipt-footer">
          <button class="btn gray" id="fullCancel">Cancel</button>
          <button class="btn blue" id="fullConfirm">
            Yes, mark as fully paid
          </button>
        </div>
      </div>
    </div>
  </div>

</main>

<script>
document.addEventListener("DOMContentLoaded", () => {

  /* ===== Sidebar Reservations Toggle ===== */
  const resToggle = document.getElementById('resToggle');
  const resMenu   = document.getElementById('resMenu');
  const chev      = document.getElementById('chev');

  if (resToggle && resMenu && chev) {
    resToggle.addEventListener('click', () => {
      const open = resMenu.style.display === 'flex';
      resMenu.style.display = open ? 'none' : 'flex';
      chev.classList.toggle('open', !open);
    });

    // Default open
    resMenu.style.display = 'flex';
    chev.classList.add('open');
  }

  /* ===== Profile dropdown ===== */
  window.toggleDropdown = function () {
    const d = document.getElementById('dropdown');
    if (!d) return;
    d.style.display = d.style.display === 'block' ? 'none' : 'block';
  };

  document.addEventListener('click', e => {
    const adminBtn = document.querySelector('.admin');
    const drop = document.getElementById('dropdown');

    if (drop && adminBtn && !adminBtn.contains(e.target)) {
      drop.style.display = 'none';
    }
  });

  /* ===== Stats filter ===== */
  const stats  = document.querySelectorAll('.stat');
  const rows   = document.querySelectorAll('#resTable tbody tr');

  stats.forEach(stat => {
    stat.addEventListener('click', () => {
      const filter = stat.dataset.status || 'all';

      rows.forEach(r => {
        const rowStatus = (r.dataset.status || 'pending').toLowerCase();

        if (filter === 'all' || rowStatus === filter) {
          r.style.display = 'table-row';
        } else {
          r.style.display = 'none';
        }
      });

      stats.forEach(s => s.classList.remove('active'));
      stat.classList.add('active');
    });
  });

  /* ===== Modal Logic (view + approve/cancel/full) ===== */
  const modal       = document.getElementById('viewModal');
  const btnApprove  = document.getElementById('btnApprove');
  const btnCancel   = document.getElementById('btnCancel');
  const btnFull     = document.getElementById('btnFull');

  // Full confirm modal
  const fullModal   = document.getElementById('fullModal');
  const fullCancel  = document.getElementById('fullCancel');
  const fullConfirm = document.getElementById('fullConfirm');

  let   activeRow   = null;

  function capitalize(txt) {
    if (!txt) return '';
    return txt.charAt(0).toUpperCase() + txt.slice(1);
  }

  function openReceipt(row) {
    activeRow = row;

    document.getElementById("vCode").textContent         = row.code;
    document.getElementById("vName").textContent         = row.name;
    document.getElementById("vStatus").textContent       = capitalize(row.status);
    document.getElementById("vType").textContent         = row.category;
    document.getElementById("vPackage").textContent      = row.package;
    document.getElementById("vTimeSlot").textContent     = row.timeslot;
    document.getElementById("vPax").textContent          = row.pax;
    document.getElementById("vDate").textContent         = row.start;

    document.getElementById("vPaymentLabel").textContent  = row.payment_label;
    document.getElementById("vPaymentStatus").textContent = row.payment_status || "—";
    document.getElementById("vAmount").textContent        =
      "₱" + Number(row.amount || 0).toLocaleString();

    const percent = parseFloat(row.percent || 0);

    // Enable/disable buttons
    if (row.status === "cancelled") {
      btnApprove.disabled = true;
      btnCancel.disabled  = true;
      btnFull.disabled    = true;
    } else {
      btnCancel.disabled  = false;
      btnApprove.disabled = (row.status === "approved");
      btnFull.disabled    = (!row.payment_id || percent >= 100);
    }

    modal.style.display = 'flex';
  }

  window.closeReceipt = function () {
    modal.style.display = 'none';
    activeRow = null;
  };

  modal.addEventListener('click', (e) => {
    if (e.target === modal) {
      closeReceipt();
    }
  });

  /* ===== Table click → open modal ===== */
  const table = document.getElementById('resTable');
  if (table) {
    table.addEventListener('click', (e) => {
      const btn = e.target.closest('.btn-view');
      if (!btn) return;

      const rowEl = btn.closest('tr');
      if (!rowEl) return;

      const data = {
        id:            rowEl.dataset.id,
        code:          rowEl.dataset.code,
        name:          rowEl.dataset.name,
        email:         rowEl.dataset.email || "",
        status:        (rowEl.dataset.status || "pending").toLowerCase(),
        category:      rowEl.dataset.category,
        package:       rowEl.dataset.package,
        timeslot:      rowEl.dataset.timeslot,
        pax:           rowEl.dataset.pax,
        start:         rowEl.dataset.start,
        end:           rowEl.dataset.end,
        amount:        rowEl.dataset.amount,
        payment_label: rowEl.dataset.paymentlabel,
        payment_status:rowEl.dataset.paymentstatus,
        percent:       rowEl.dataset.percent,
        payment_id:    rowEl.dataset.paymentid
      };

      openReceipt(data);
    });
  }

  /* ===== Shared function to call payment_action.php ===== */
  async function updatePayment(action, withConfirm = true) {
    if (!activeRow) return;

    const paymentId     = parseInt(activeRow.payment_id || 0, 10);
    const reservationId = parseInt(activeRow.id || 0, 10);

    if (!paymentId || !reservationId) {
      alert("No payment record found for this reservation.");
      return;
    }

    // For approve/cancel we still use browser confirm, for full we use custom modal
    if (withConfirm) {
      let msg = "";
      if (action === "approve") {
        msg = "Approve this reservation payment?";
      } else if (action === "cancel") {
        msg = "Cancel this reservation and payment?";
      }
      if (msg && !confirm(msg)) return;
    }

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
          email: activeRow.email || null,
        }),
      });

      const data = await res.json().catch(() => ({}));
      console.log("payment_action response (staff list):", data);

      if (!data.success) {
        alert(`❌ Error: ${data.message || "Update failed."}`);
        return;
      }

      alert(data.message || "✅ Payment updated!");
      window.location.reload();
    } catch (err) {
      console.error(err);
      alert("❌ Network error.");
    }
  }

  /* ===== Button handlers ===== */
  btnApprove.addEventListener('click', () => {
    updatePayment("approve", true);
  });

  btnCancel.addEventListener('click', () => {
    updatePayment("cancel", true);
  });

  // Show custom FULLY PAID modal
  btnFull.addEventListener('click', () => {
    if (!activeRow) return;
    if (btnFull.disabled) return;
    fullModal.style.display = 'flex';
  });

  // Close FULLY PAID modal
  fullCancel.addEventListener('click', () => {
    fullModal.style.display = 'none';
  });

  fullModal.addEventListener('click', (e) => {
    if (e.target === fullModal) {
      fullModal.style.display = 'none';
    }
  });

  // Confirm FULLY PAID (call handler without extra confirm())
  fullConfirm.addEventListener('click', () => {
    fullModal.style.display = 'none';
    updatePayment("full", false);
  });

});
</script>
</body>
</html>