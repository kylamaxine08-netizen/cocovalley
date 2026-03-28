<?php
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');

/* ============================================
   SECURE SESSION
============================================ */
session_set_cookie_params([
  'lifetime' => 0,
  'path'     => '/',
  'domain'   => '',
  'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
  'httponly' => true,
  'samesite' => 'Lax',
]);
session_start();

/* ============================================
   AUTH: STAFF ONLY
============================================ */
if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'staff') {
  header('Location: admin-login.php');
  exit;
}

$meName = trim((string)($_SESSION['name'] ?? 'Staff'));
$meRole = 'Staff';

/* ============================================
   CSRF TOKEN
============================================ */
if (empty($_SESSION['csrf'])) {
  $_SESSION['csrf'] = bin2hex(random_bytes(16));
}
$CSRF = $_SESSION['csrf'];

/* ============================================
   LOGOUT
============================================ */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'
    && ($_POST['action'] ?? '') === 'logout') {

  if (!hash_equals($CSRF, (string)($_POST['csrf'] ?? ''))) {
    http_response_code(400);
    exit('Bad request.');
  }

  $_SESSION = [];
  if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time() - 3600, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
  }
  session_destroy();

  header('Location: admin-login.php');
  exit;
}

/* ============================================
   SANITIZER
============================================ */
function e($s): string {
  if ($s === null || $s === '') return '—';
  return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

/* ============================================
   DB CONNECTION
============================================ */
require_once __DIR__ . '/handlers/db_connect.php';
$dbcOk = isset($conn) && $conn instanceof mysqli && !$conn->connect_error;

/* Default values */
$totalCount = $pendingCount = $approvedCount = $cancelledCount = 0;
$resList = [];

/* ============================================
   LOAD DASHBOARD DATA
============================================ */
if ($dbcOk) {

  /* ---- SUMMARY COUNTS ---- */
  $statsSql = "
    SELECT
      COUNT(*) AS total,
      SUM(LOWER(status) = 'pending')   AS pending,
      SUM(LOWER(status) = 'approved')  AS approved,
      SUM(LOWER(status) = 'cancelled') AS cancelled
    FROM reservations
  ";
  $stats = $conn->query($statsSql)?->fetch_assoc() ?? [];

  $totalCount     = (int)($stats['total'] ?? 0);
  $pendingCount   = (int)($stats['pending'] ?? 0);
  $approvedCount  = (int)($stats['approved'] ?? 0);
  $cancelledCount = (int)($stats['cancelled'] ?? 0);

  /* ============================================
     RECENT RESERVATIONS
     - Payment status galing sa reservations.payment_status + payment_percent
       (lalo na sa walk-in)
     - fallback sa payments.method_option kung kailangan
  ============================================= */
  $sql = "
    SELECT
      r.id,
      r.code,
      r.customer_name,
      r.package        AS package_name,
      r.pax,
      r.type           AS category,
      r.status,
      r.approved_by,
      r.start_date,
      r.created_at,
      r.payment_status    AS payment_status_res,
      r.payment_percent   AS payment_percent_res,
      COALESCE(p.method_option, '') AS method_option,
      u.role            AS approver_role
    FROM reservations r
    LEFT JOIN payments p 
      ON p.reservation_id = r.id
    LEFT JOIN users u 
      ON u.id = CAST(r.approved_by AS UNSIGNED)
    ORDER BY r.id DESC
    LIMIT 50
  ";

  if ($result = $conn->query($sql)) {

    while ($row = $result->fetch_assoc()) {

      /* ---- Clean & Normalize ---- */
      $row['customer_name'] = trim($row['customer_name'] ?? '—');
      $row['package_name']  = trim($row['package_name'] ?? '—');
      $row['pax']           = (int)($row['pax'] ?? 0);
      $row['category']      = ucfirst(trim($row['category'] ?? '—'));

      /* ---- Status ---- */
      $statusRaw = strtolower(trim((string)($row['status'] ?? '')));
      if (!in_array($statusRaw, ['pending','approved','cancelled'], true)) {
        $statusRaw = 'pending';
      }
      $row['status_raw']    = $statusRaw;
      $row['status_pretty'] = ucfirst($statusRaw);

      /* ============================================
         PAYMENT STATUS
         1) Unahin reservations.payment_status + payment_percent
         2) Fallback sa payments.method_option
      ============================================= */
      $paymentStatusDb = strtolower(trim((string)($row['payment_status_res'] ?? '')));
      $paymentPercent  = (float)($row['payment_percent_res'] ?? 0);
      $method          = strtolower(trim((string)$row['method_option']));

      if ($paymentStatusDb === 'paid') {
        // Ito yung mga walk-in mo (at pwede ring online na na-verify)
        if ($paymentPercent >= 100) {
          $row['payment_status'] = 'Fully Paid';
        } elseif ($paymentPercent >= 50) {
          $row['payment_status'] = '50% Paid';
        } elseif ($paymentPercent > 0) {
          $row['payment_status'] = 'Partial Payment';
        } else {
          // fallback kung sakaling wala kang percent pero status = paid
          $row['payment_status'] = 'Paid';
        }
      } else {
        // Fallback sa method_option (galing sa payments table)
        if ($method === 'full' || str_contains($method, '100')) {
          $row['payment_status'] = 'Fully Paid';
        } elseif (str_contains($method, '50')) {
          $row['payment_status'] = '50% Paid';
        } elseif ($method !== '') {
          $row['payment_status'] = 'Partial Payment';
        } elseif ($paymentStatusDb === 'unpaid' || $paymentStatusDb === '') {
          $row['payment_status'] = 'Unpaid';
        } else {
          // ibang value sa payment_status column (ex: 'refunded', etc.)
          $row['payment_status'] = ucfirst($paymentStatusDb);
        }
      }

      /* ============================================
         APPROVED BY (Coco Valley (Admin/Staff))
      ============================================= */
      $approver_raw = $row['approver_role'] ?? '';
      $approver = strtolower(trim((string)$approver_raw));

      if ($approver === 'admin') {
        $row['approved_by_display'] = "Coco Valley (Admin)";
      }
      elseif ($approver === 'staff') {
        $row['approved_by_display'] = "Coco Valley (Staff)";
      }
      else {
        // kung walk-in at approved_by = name lang (hindi user id),
        // wala siyang ma-jojoi­n sa users → default staff label
        $row['approved_by_display'] = "Coco Valley (Staff)";
      }

      /* ============================================
         DATE RESERVED
         - primary: reservations.created_at
         - fallback: start_date (kung sakaling walang created_at)
      ============================================= */
      $createdRaw = $row['created_at'] ?? null;
      $startRaw   = $row['start_date'] ?? null;

      if (!empty($createdRaw) && $createdRaw !== '0000-00-00 00:00:00') {
        $row['date_reserved_fmt'] = date('M d, Y', strtotime($createdRaw));
      } elseif (!empty($startRaw) && $startRaw !== '0000-00-00') {
        $row['date_reserved_fmt'] = date('M d, Y', strtotime($startRaw));
      } else {
        $row['date_reserved_fmt'] = '—';
      }

      $resList[] = $row;
    }

    $result->free();
  }

  $conn->close();
}
?>


<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Staff Dashboard - Cocovalley Richnez Waterpark</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

<style>
:root {
  --primary: #004d99;
  --accent: #0b72d1;
  --accent-soft: rgba(11,114,209,0.08);

  --bg: #f2f6fb;
  --bg-soft: #ffffff;

  --border: #e5e7eb;
  --border-soft: #f0f0f0;

  --text: #111827;
  --muted: #6b7280;

  --sidebar-w: 260px;

  --pending: #f59e0b;
  --approved: #22c55e;
  --cancelled: #ef4444;
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

/* ===================== SIDEBAR ===================== */
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
}

/* ===================== TOPBAR ===================== */
.topbar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  background: #ffffff;
  border-radius: 999px;
  padding: 8px 16px;
  box-shadow: 0 12px 30px rgba(0,0,0,0.06);
  border: 1px solid rgba(15,23,42,0.04);
  margin-bottom: 22px;
}
.topbar-left {
  display: flex;
  align-items: center;
  gap: 10px;
}
.topbar h1 {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 18px;
  font-weight: 700;
  color: var(--primary);
}
.topbar h1 i {
  background: var(--accent-soft);
  color: var(--accent);
  border-radius: 999px;
  padding: 7px;
  font-size: 14px;
}
.topbar-sub {
  font-size: 13px;
  color: var(--muted);
}

/* PROFILE CHIP */
.profile {
  position: relative;
  display: flex;
  align-items: center;
  gap: 10px;
  cursor: pointer;
  padding: 4px 8px;
  border-radius: 999px;
  transition: background 0.16s ease;
  font-size: 14px;
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
.dropdown button,
.dropdown a {
  border: none;
  background: transparent;
  padding: 10px 14px;
  font-size: 14px;
  text-align: left;
  cursor: pointer;
  color: #111827;
}
.dropdown button:hover,
.dropdown a:hover {
  background: #f3f4f6;
}

/* ===================== FILTER BAR ===================== */
.filters {
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
  align-items: center;
  margin-bottom: 14px;
}
.input-pill,
.select-pill,
.btn-pill {
  padding: 9px 13px;
  border-radius: 999px;
  border: 1px solid var(--border);
  background: #ffffff;
  font-size: 14px;
}
.input-pill {
  min-width: 260px;
}
.input-pill:focus,
.select-pill:focus {
  outline: none;
  border-color: var(--accent);
  box-shadow: 0 0 0 2px rgba(11,114,209,0.18);
}
.btn-pill {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  cursor: pointer;
  font-weight: 600;
}
.btn-pill i {
  font-size: 13px;
}
.btn-pill:hover {
  background: #eef4ff;
}

/* ===================== STATS CARDS ===================== */
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
  padding: 14px 14px;
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
  width: 38px;
  height: 38px;
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
.ico.all      { background: linear-gradient(135deg,#0ea5e9,#0b72d1); }
.ico.p        { background: var(--pending); }
.ico.a        { background: var(--approved); }
.ico.c        { background: var(--cancelled); }

/* ===================== TABLE CARD ===================== */
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
.table-wrapper {
  width: 100%;
  overflow-x: auto;
}
table {
  width: 100%;
  border-collapse: collapse;
  min-width: 820px;
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

/* STATUS TAGS */
.status-pill {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 3px 9px;
  border-radius: 999px;
  font-size: 11px;
  font-weight: 600;
  border: 1px solid transparent;
  text-transform: capitalize;
}
.status-pill.pending {
  background: #fef3c7;
  color: #92400e;
  border-color: #fde68a;
}
.status-pill.approved {
  background: #dcfce7;
  color: #166534;
  border-color: #bbf7d0;
}
.status-pill.cancelled {
  background: #fee2e2;
  color: #991b1b;
  border-color: #fecaca;
}

/* PAYMENT LABEL COLORS */
.pay-ok {
  color: #15803d;
  font-weight: 600;
}
.pay-half {
  color: #b45309;
  font-weight: 600;
}
.pay-unpaid {
  color: #6b7280;
  font-weight: 600;
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
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}
@media (max-width: 640px) {
  .stats {
    grid-template-columns: minmax(0,1fr);
  }
}
</style>
</head>
<body>

<!-- Hidden logout form -->
<form id="logoutForm" method="POST" style="display:none;">
  <input type="hidden" name="action" value="logout">
  <input type="hidden" name="csrf" value="<?php echo e($CSRF); ?>">
</form>

<!-- ===================== SIDEBAR ===================== -->
<aside class="sidebar">
  <div class="sb-head">
    <img src="logo.jpg" class="sb-logo" alt="Cocovalley Logo">
    <div>
      <div class="sb-title">Cocovalley</div>
      <div class="sb-tag">Staff Portal</div>
    </div>
  </div>

  <nav class="nav">
    <a href="staff-dashboard.php" class="nav-item active">
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
        <a href="staff-reservation-list.php">List View</a>
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
      <i class="fa-solid fa-bell"></i>Notification</a>
    <a href="staff-announcement.php" class="nav-item">
      <i class="fa-solid fa-bullhorn"></i>Announcements</a>
  </nav>
</aside>

<!-- ===================== MAIN ===================== -->
<main class="main">

  <!-- TOPBAR -->
  <div class="topbar">
    <div>
      <div class="topbar-left">
        <h1>
          <i class="fa-solid fa-chart-line"></i>
          Staff Dashboard
        </h1>
      </div>
      <div class="topbar-sub">
        Quick overview of reservations and payments for Cocovalley.
      </div>
    </div>

    <div class="profile" id="profileBtn">
      <div class="avatar">
        <?php echo strtoupper(substr($meName, 0, 1)); ?>
      </div>
      <span><?php echo e($meName); ?> • <?php echo e($meRole); ?></span>
      <i class="fa-solid fa-chevron-down"></i>

      <div class="dropdown" id="dropdown">
        <button type="button" onclick="openLogout()">Logout</button>
      </div>
    </div>
  </div>

  <!-- FILTER BAR -->
  <section class="filters">
    <input
      type="text"
      id="searchInput"
      class="input-pill"
      placeholder="Search customer, category or package..."
    >
    <select id="statusFilter" class="select-pill">
      <option value="all">All status</option>
      <option value="pending">Pending</option>
      <option value="approved">Approved</option>
      <option value="cancelled">Cancelled</option>
    </select>
    <button type="button" class="btn-pill" id="clearFilters">
      <i class="fa-solid fa-rotate"></i> Clear
    </button>
  </section>

  <!-- STATS CARDS -->
  <section class="stats">
    <div class="stat active" data-filter="all">
      <div class="ico all"><i class="fa-solid fa-layer-group"></i></div>
      <div class="meta">
        <div class="k">Total Reservations</div>
        <div class="v"><?php echo (int)$totalCount; ?></div>
      </div>
    </div>

    <div class="stat" data-filter="pending">
      <div class="ico p"><i class="fa-solid fa-hourglass-half"></i></div>
      <div class="meta">
        <div class="k">Pending</div>
        <div class="v"><?php echo (int)$pendingCount; ?></div>
      </div>
    </div>

    <div class="stat" data-filter="approved">
      <div class="ico a"><i class="fa-solid fa-circle-check"></i></div>
      <div class="meta">
        <div class="k">Approved</div>
        <div class="v"><?php echo (int)$approvedCount; ?></div>
      </div>
    </div>

    <div class="stat" data-filter="cancelled">
      <div class="ico c"><i class="fa-solid fa-circle-xmark"></i></div>
      <div class="meta">
        <div class="k">Cancelled</div>
        <div class="v"><?php echo (int)$cancelledCount; ?></div>
      </div>
    </div>
  </section>

  <!-- TABLE CARD -->
  <section class="card">
    <header>
      <span>Recent Reservations</span>
      <span class="sub">Latest bookings handled by Cocovalley.</span>
    </header>

    <div class="table-wrapper">
      <table>
        <thead>
          <tr>
            <th>Customer</th>
            <th>Status</th>
            <th>Approved By</th>
            <th>Date Reserved</th>
            <th>Category</th>
            <th>Package</th>
            <th>Pax</th>
            <th>Payment</th>
          </tr>
        </thead>
        <tbody id="tableBody">
        <?php if (!$dbcOk): ?>
          <tr>
            <td colspan="8" style="text-align:center;color:#b91c1c;padding:22px;">
              Database unavailable. Please check connection.
            </td>
          </tr>
        <?php elseif (empty($resList)): ?>
          <tr>
            <td colspan="8" style="text-align:center;color:#6b7280;padding:22px;">
              No reservations found.
            </td>
          </tr>
        <?php else: ?>
          <?php foreach ($resList as $r): ?>
            <tr
              data-status="<?php echo e($r['status_raw']); ?>"
              data-name="<?php echo strtolower($r['customer_name']); ?>"
              data-category="<?php echo strtolower($r['category']); ?>"
              data-package="<?php echo strtolower($r['package_name']); ?>"
            >
              <td><?php echo e($r['customer_name']); ?></td>
              <td>
                <span class="status-pill <?php echo e($r['status_raw']); ?>">
                  <?php echo e($r['status_pretty']); ?>
                </span>
              </td>
              <td><?php echo e($r['approved_by_display']); ?></td>
              <td><?php echo e($r['date_reserved_fmt']); ?></td>
              <td><?php echo e($r['category']); ?></td>
              <td><?php echo e($r['package_name']); ?></td>
              <td><?php echo e((string)$r['pax']); ?></td>
              <td>
                <?php
                  $ps  = $r['payment_status'] ?? 'Unpaid';
                  $cls =
                    ($ps === 'Fully Paid') ? 'pay-ok' :
                    (($ps === '50% Paid') ? 'pay-half' : 'pay-unpaid');
                ?>
                <span class="<?php echo $cls; ?>"><?php echo e($ps); ?></span>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
      </table>
    </div>
  </section>
</main>

<script>
// RESERVATIONS SUBMENU + FILTERS
document.addEventListener("DOMContentLoaded", () => {
  const resToggle = document.getElementById("resToggle");
  const resMenu   = document.getElementById("resMenu");
  const chev      = document.getElementById("chev");

  if (resToggle && resMenu && chev) {
    resToggle.addEventListener("click", () => {
      const open = resMenu.style.display === "flex";
      resMenu.style.display = open ? "none" : "flex";
      chev.classList.toggle("open", !open);
    });
    // default open on load
    resMenu.style.display = "flex";
    chev.classList.add("open");
  }

  // highlight walkin link if needed
  const path = window.location.pathname.split("/").pop();
  if (path === "staff-walkin.php") {
    document.getElementById("walkinLink")?.classList.add("active");
  }

  // PROFILE DROPDOWN
  const profileBtn = document.getElementById("profileBtn");
  const dropdown   = document.getElementById("dropdown");

  if (profileBtn && dropdown) {
    profileBtn.addEventListener("click", (e) => {
      e.stopPropagation();
      dropdown.style.display = (dropdown.style.display === "flex") ? "none" : "flex";
    });
    document.addEventListener("click", (e) => {
      if (!profileBtn.contains(e.target)) {
        dropdown.style.display = "none";
      }
    });
  }

  // LOGOUT HELPERS
  window.openLogout = function () {
    document.getElementById("logoutForm").submit();
  };

  // FILTERS: CARDS + SEARCH + STATUS
  const stats        = document.querySelectorAll(".stat");
  const rows         = document.querySelectorAll("#tableBody tr");
  const searchInput  = document.getElementById("searchInput");
  const statusFilter = document.getElementById("statusFilter");
  const clearBtn     = document.getElementById("clearFilters");

  function applyFilters(extraStatusFilter = null) {
    const q  = (searchInput?.value || "").toLowerCase();
    const st = (extraStatusFilter ?? (statusFilter?.value || "all")).toLowerCase();

    rows.forEach(row => {
      const rowStatus = (row.dataset.status || "").toLowerCase();
      const name  = (row.dataset.name || "");
      const cat   = (row.dataset.category || "");
      const pack  = (row.dataset.package || "");

      const matchesSearch =
        !q || name.includes(q) || cat.includes(q) || pack.includes(q);
      const matchesStatus =
        st === "all" || rowStatus === st;

      row.style.display = (matchesSearch && matchesStatus) ? "" : "none";
    });
  }

  // stats cards click -> filter by status
  if (stats.length > 0) {
    stats.forEach(stat => {
      stat.addEventListener("click", () => {
        const filter = (stat.dataset.filter || "all").toLowerCase();

        stats.forEach(s => s.classList.remove("active"));
        stat.classList.add("active");

        if (statusFilter) statusFilter.value = filter === "all" ? "all" : filter;
        applyFilters(filter);
      });
    });
  }

  if (searchInput)  searchInput.addEventListener("input", () => applyFilters());
  if (statusFilter) statusFilter.addEventListener("change", () => applyFilters());

  if (clearBtn) {
    clearBtn.addEventListener("click", () => {
      if (searchInput)  searchInput.value = "";
      if (statusFilter) statusFilter.value = "all";
      stats.forEach(s => s.classList.remove("active"));
      const allCard = document.querySelector('.stat[data-filter="all"]');
      if (allCard) allCard.classList.add("active");
      applyFilters("all");
    });
  }
});
</script>
</body>
</html>
