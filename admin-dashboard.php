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
   AUTH: ADMIN ONLY
============================================ */
if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
  header('Location: admin-login.php');
  exit;
}

$meName = trim($_SESSION['name'] ?? 'Admin');

/* ============================================
   CSRF TOKEN
============================================ */
if (empty($_SESSION['csrf'])) {
  $_SESSION['csrf'] = bin2hex(random_bytes(16));
}
$CSRF = $_SESSION['csrf'];

/* ============================================
   LOGOUT HANDLER
============================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'logout') {
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
   HELPER
============================================ */
function e(?string $s): string {
  return htmlspecialchars($s ?: '—', ENT_QUOTES, 'UTF-8');
}

/* ============================================
   DATABASE CONNECTION
============================================ */
require_once __DIR__ . '/handlers/db_connect.php';
$dbcOk = isset($conn) && $conn instanceof mysqli && !$conn->connect_error;

/* === Default Values === */
$totalCount = $pendingCount = $approvedCount = $cancelledCount = 0;
$resList = [];

/* ============================================
   DASHBOARD STATISTICS + RECENT RESERVATIONS
============================================ */
if ($dbcOk) {

  // High-level counts
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
      RECENT RESERVATIONS (Latest 50, walk-in ready)
  ============================================= */
  $sql = "
    SELECT 
      r.id,
      r.customer_name,
      r.package AS package_name,
      r.pax,
      r.type   AS category,
      r.status,
      r.approved_by,                         -- holds user_id or staff name

      -- from reservations (works for walk-in + online)
      COALESCE(r.payment_status, '')  AS payment_status_res,
      COALESCE(r.payment_percent, 0)  AS payment_percent_res,

      -- from payments (online proof)
      COALESCE(p.method_option, '')   AS method_option,

      -- approver full name + role
      COALESCE(CONCAT(a.first_name, ' ', a.last_name), '') AS approved_by_name,
      COALESCE(a.role, '') AS approved_by_role,

      -- DATES
      r.created_at,
      r.start_date

    FROM reservations r
    LEFT JOIN payments p ON p.reservation_id = r.id
    LEFT JOIN users a    ON a.id = r.approved_by
    ORDER BY r.id DESC
    LIMIT 50
  ";

  if ($result = $conn->query($sql)) {

    while ($r = $result->fetch_assoc()) {

      // Clean values
      $r['customer_name'] = trim($r['customer_name'] ?? '—');
      $r['package_name']  = trim($r['package_name'] ?? '—');
      $r['pax']           = (int)($r['pax'] ?? 0);
      $r['category']      = ucfirst(trim($r['category'] ?? '—'));

      // Normalize status
      $statusRaw = strtolower(trim($r['status'] ?? ''));
      if (!in_array($statusRaw, ['pending','approved','cancelled'], true)) {
        $statusRaw = 'pending';
      }
      $r['status_raw']    = $statusRaw;
      $r['status_pretty'] = ucfirst($statusRaw);

      /* ============================================
         PAYMENT STATUS (prioritize reservations table)
      ============================================= */
      $paymentStatusDb = strtolower(trim((string)$r['payment_status_res']));
      $paymentPercent  = (float)$r['payment_percent_res'];
      $method          = strtolower(trim((string)$r['method_option']));

      if ($paymentStatusDb === 'paid') {
        // Trusted flag from reservations (online + walk-in)
        if ($paymentPercent >= 100) {
          $r['payment_status'] = 'Fully Paid';
        } elseif ($paymentPercent >= 50) {
          $r['payment_status'] = '50% Paid';
        } elseif ($paymentPercent > 0) {
          $r['payment_status'] = 'Partial Payment';
        } else {
          $r['payment_status'] = 'Paid';
        }
      } else {
        // Fallback to payments.method_option (older records)
        if ($method === 'full' || str_contains($method, '100')) {
          $r['payment_status'] = 'Fully Paid';
        } elseif (str_contains($method, '50')) {
          $r['payment_status'] = '50% Paid';
        } elseif ($method !== '') {
          $r['payment_status'] = 'Partial Payment';
        } else {
          $r['payment_status'] = 'Unpaid';
        }
      }

      /* ============================================
         APPROVED BY (full name + role or fallback)
      ============================================= */
      $approvedName = trim($r['approved_by_name'] ?? '');
      $approvedRole = strtolower(trim($r['approved_by_role'] ?? ''));

      if ($approvedName !== '') {
        // ex: "Kyla Tripoli (Admin)"
        $r['approved_by_display'] = $approvedName;
        if ($approvedRole === 'admin' || $approvedRole === 'staff') {
          $r['approved_by_display'] .= ' (' . ucfirst($approvedRole) . ')';
        }
      } else {
        // No approver linked in users table → generic label
        $r['approved_by_display'] = 'Coco Valley (Staff)';
      }

      /* ============================================
         DATE RESERVED (created_at or start_date)
      ============================================= */
      $createdRaw = $r['created_at'] ?? null;

      if (!empty($createdRaw) && $createdRaw !== '0000-00-00 00:00:00') {
        $r['created_at_fmt'] = date('M d, Y', strtotime($createdRaw));
      } elseif (!empty($r['start_date']) && $r['start_date'] !== '0000-00-00') {
        $r['created_at_fmt'] = date('M d, Y', strtotime($r['start_date']));
      } else {
        $r['created_at_fmt'] = '—';
      }

      $resList[] = $r;
    }

    $result->free();
  }

  $conn->close();
}
?>


<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Admin Dashboard - Cocovalley Richnez Waterpark</title>

  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

  <style>
    :root {
      --primary: #222222;
      --accent: #0b72d1; /* BLUE ACCENT */
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
      z-index: 40;
      transition: transform 0.25s ease-out;
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
      box-shadow: 0 10px 20px rgba(11, 114, 209, 0.25); /* BLUE SHADOW */
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
      box-shadow: 0 8px 18px rgba(11, 114, 209, 0.2); /* BLUE SHADOW */
    }

    /* MOBILE OVERLAY */
    .mobile-overlay {
      position: fixed;
      inset: 0;
      background: rgba(0,0,0,0.4);
      opacity: 0;
      visibility: hidden;
      transition: opacity 0.2s ease;
      z-index: 30;
    }

    .mobile-overlay.show {
      opacity: 1;
      visibility: visible;
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
      padding: 8px 16px;
      box-shadow: 0 12px 30px rgba(0,0,0,0.06);
      border: 1px solid rgba(15,23,42,0.04);
      margin-bottom: 22px;
      position: sticky;
      top: 14px;
      z-index: 10;
    }

    .topbar-left {
      display: flex;
      align-items: center;
      gap: 10px;
    }

    .menu-btn {
      border: none;
      background: transparent;
      border-radius: 999px;
      width: 34px;
      height: 34px;
      display: none;
      align-items: center;
      justify-content: center;
      cursor: pointer;
    }

    .menu-btn i {
      font-size: 18px;
      color: var(--primary);
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
      box-shadow: 0 10px 20px rgba(37, 99, 235, 0.35); /* BLUE SHADOW */
    }

    .dropdown {
      position: absolute;
      top: 42px;
      right: 0;
      min-width: 180px;
      background: #ffffff;
      border-radius: 14px;
      box-shadow: 0 16px 40px rgba(0,0,0,0.12);
      border: 1px solid #e5e7eb;
      display: none;
      flex-direction: column;
      overflow: hidden;
      z-index: 30;
    }

    .dropdown form {
      margin: 0;
    }

    .dropdown button,
    .dropdown a {
      padding: 10px 14px;
      font-size: 14px;
      color: #111827;
      background: transparent;
      border: none;
      text-align: left;
      width: 100%;
      cursor: pointer;
      display: flex;
      align-items: center;
      gap: 8px;
    }

    .dropdown button:hover,
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
      box-shadow: 0 18px 40px rgba(11, 114, 209, 0.25); /* BLUE SHADOW */
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

    /* TABLE CARD */
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

    tbody tr {
      opacity: 0;
      transform: translateY(4px);
      animation: rowFade 0.32s ease-out forwards;
    }

    tbody tr:hover {
      background: #f9fafb;
    }

    tbody tr:last-child td {
      border-bottom: none;
    }

    @keyframes rowFade {
      from {
        opacity: 0;
        transform: translateY(4px);
      }
      to {
        opacity: 1;
        transform: translateY(0);
      }
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

    /* PAYMENT LABEL */
    .pay-label {
      font-size: 12px;
      font-weight: 600;
      color: #111827;
    }

    /* FILTER BADGE */
    .filter-badge {
      font-size: 12px;
      padding: 4px 10px;
      border-radius: 999px;
      background: #e0f2fe;
      color: #0369a1;
      font-weight: 600;
    }

    /* RESPONSIVE */
    @media (max-width: 900px) {
      .sidebar {
        transform: translateX(-100%);
      }
      .sidebar.open {
        transform: translateX(0);
      }
      .main {
        margin-left: 0;
        padding: 18px 16px 30px;
      }
      .menu-btn {
        display: inline-flex;
      }
      .stats {
        grid-template-columns: repeat(2, minmax(0, 1fr));
      }
    }

    @media (max-width: 640px) {
      .stats {
        grid-template-columns: minmax(0, 1fr);
      }
      .topbar {
        border-radius: 18px;
      }
    }
  </style>
</head>
<body>

<!-- Hidden logout form (fallback) -->
<form id="logoutForm" method="POST" style="display:none;">
  <input type="hidden" name="action" value="logout">
  <input type="hidden" name="csrf" value="<?php echo e($CSRF); ?>">
</form>

<!-- SIDEBAR -->
<aside class="sidebar" id="sidebar">
  <div class="sb-head">
    <img src="logo.jpg" class="sb-logo" alt="Cocovalley Logo">
    <div>
      <div class="sb-title">Cocovalley</div>
      <div class="sb-tag">Admin Portal</div>
    </div>
  </div>

  <nav class="nav">
    <a href="admin-dashboard.php" class="nav-item active">
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
        <a href="admin-calendar.php">Calendar View</a>
        <a href="admin-reservation-list.php">List View</a>
        <a href="admin-2dmap.php">2D Map</a>
      </div>
    </div>

    <a href="admin-payment.php" class="nav-item">
      <i class="fa-solid fa-receipt"></i>Payment Proofs
    </a>
    <a href="admin-customer-list.php" class="nav-item">
      <i class="fa-solid fa-users"></i>Customer List
    </a>
    <a href="admin-notification.php" class="nav-item">
      <i class="fa-solid fa-bell"></i>Notification</a>
    <a href="admin-announcement.php" class="nav-item">
      <i class="fa-solid fa-bullhorn"></i>Announcements</a>
    <a href="admin-accommodations.php" class="nav-item">
      <i class="fa-solid fa-bed"></i>Accommodations</a>
    <a href="admin-reports.php" class="nav-item">
      <i class="fa-solid fa-chart-column"></i>Reports</a>
    <a href="admin-archive.php" class="nav-item">
      <i class="fa-solid fa-box-archive"></i>Archive</a>
    <a href="admin-system-settings.php" class="nav-item">
      <i class="fa-solid fa-gear"></i>System Settings</a>
  </nav>
</aside>

<div class="mobile-overlay" id="mobileOverlay"></div>

<!-- MAIN -->
<main class="main">

  <!-- TOPBAR -->
  <div class="topbar">
    <div class="topbar-left">
      <button class="menu-btn" id="menuBtn" aria-label="Open sidebar">
        <i class="fa-solid fa-bars"></i>
      </button>
      <h1>
        <i class="fa-solid fa-chart-line"></i>
        Admin Dashboard
      </h1>
    </div>

    <div class="admin" id="adminBtn">
      <div class="avatar">
        <?php
          $initial = strtoupper(substr(trim($meName), 0, 1));
          echo e($initial);
        ?>
      </div>
      <span><?= e($meName); ?> ▾</span>

      <div class="dropdown" id="profileDropdown">
        <a href="#">
          <i class="fa-regular fa-id-badge"></i>
          Profile
        </a>
        <a href="#">
          <i class="fa-solid fa-sliders"></i>
          Preferences
        </a>
        <form method="POST">
          <input type="hidden" name="csrf" value="<?= e($CSRF) ?>">
          <input type="hidden" name="action" value="logout">
          <button type="submit">
            <i class="fa-solid fa-arrow-right-from-bracket"></i> Logout
          </button>
        </form>
      </div>
    </div>
  </div>

  <!-- STATS CARDS -->
  <section class="stats">
    <div class="stat active" data-filter="all">
      <div class="ico all"><i class="fa-solid fa-layer-group"></i></div>
      <div class="meta">
        <div class="k">Total Reservations</div>
        <div class="v" id="totalCount"><?= (int)$totalCount; ?></div>
      </div>
    </div>

    <div class="stat" data-filter="pending">
      <div class="ico p"><i class="fa-solid fa-hourglass-half"></i></div>
      <div class="meta">
        <div class="k">Pending</div>
        <div class="v" id="pendingCount"><?= (int)$pendingCount; ?></div>
      </div>
    </div>

    <div class="stat" data-filter="approved">
      <div class="ico a"><i class="fa-solid fa-circle-check"></i></div>
      <div class="meta">
        <div class="k">Approved</div>
        <div class="v" id="approvedCount"><?= (int)$approvedCount; ?></div>
      </div>
    </div>

    <div class="stat" data-filter="cancelled">
      <div class="ico c"><i class="fa-solid fa-circle-xmark"></i></div>
      <div class="meta">
        <div class="k">Cancelled</div>
        <div class="v" id="cancelledCount"><?= (int)$cancelledCount; ?></div>
      </div>
    </div>
  </section>

  <!-- TABLE CARD -->
  <section class="card">
    <header>
      <span>Recent Reservations</span>
      <span class="sub">
        <span class="filter-badge" id="filterBadge">Showing: All Reservations</span>
      </span>
    </header>

    <div class="tablewrap">
      <table id="reservationTable">
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
          <tr style="animation:none; opacity:1;">
            <td colspan="8" style="text-align:center;color:#b91c1c;padding:22px;">
              Database unavailable. Please check connection.
            </td>
          </tr>
        <?php elseif (empty($resList)): ?>
          <tr style="animation:none; opacity:1;">
            <td colspan="8" style="text-align:center;color:#6b7280;padding:22px;">
              No reservations found.
            </td>
          </tr>
        <?php else: ?>
          <?php foreach ($resList as $index => $r): ?>
            <tr
              data-status="<?= e($r['status_raw']) ?>"
              style="animation-delay: <?= number_format($index * 0.03, 2) ?>s;"
            >
              <td><?= e($r['customer_name']) ?></td>
              <td>
                <span class="status-pill <?= e($r['status_raw']) ?>">
                  <?= e($r['status_pretty']) ?>
                </span>
              </td>
              <td><?= e($r['approved_by_display']) ?></td>
              <td><?= e($r['created_at_fmt']) ?></td>
              <td><?= e($r['category']) ?></td>
              <td><?= e($r['package_name']) ?></td>
              <td><?= e((string)$r['pax']) ?></td>
              <td>
                <span class="pay-label"><?= e($r['payment_status']) ?></span>
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
document.addEventListener("DOMContentLoaded", () => {
  /* ===========================
     SIDEBAR DROPDOWN
  ============================ */
  const resToggle = document.getElementById("resToggle");
  const resMenu   = document.getElementById("resMenu");
  const chev      = document.getElementById("chev");

  if (resToggle && resMenu && chev) {
    resToggle.addEventListener("click", () => {
      const open = resMenu.style.display === "flex";
      resMenu.style.display = open ? "none" : "flex";
      chev.classList.toggle("open", !open);
    });
    // Default open on desktop
    resMenu.style.display = "flex";
    chev.classList.add("open");
  }

  /* ===========================
     MOBILE SIDEBAR TOGGLE
  ============================ */
  const menuBtn       = document.getElementById("menuBtn");
  const sidebar       = document.getElementById("sidebar");
  const mobileOverlay = document.getElementById("mobileOverlay");

  function openSidebar() {
    if (sidebar) sidebar.classList.add("open");
    if (mobileOverlay) mobileOverlay.classList.add("show");
  }
  function closeSidebar() {
    if (sidebar) sidebar.classList.remove("open");
    if (mobileOverlay) mobileOverlay.classList.remove("show");
  }

  if (menuBtn) {
    menuBtn.addEventListener("click", (e) => {
      e.stopPropagation();
      openSidebar();
    });
  }
  if (mobileOverlay) {
    mobileOverlay.addEventListener("click", () => {
      closeSidebar();
    });
  }
  // Close sidebar when resizing up
  window.addEventListener("resize", () => {
    if (window.innerWidth > 900) {
      closeSidebar();
    }
  });

  /* ===========================
     PROFILE DROPDOWN
  ============================ */
  const adminBtn = document.getElementById("adminBtn");
  const profileDropdown = document.getElementById("profileDropdown");

  if (adminBtn && profileDropdown) {
    adminBtn.addEventListener("click", (e) => {
      e.stopPropagation();
      const isOpen = profileDropdown.style.display === "flex";
      profileDropdown.style.display = isOpen ? "none" : "flex";
    });

    document.addEventListener("click", (e) => {
      if (!adminBtn.contains(e.target) && !profileDropdown.contains(e.target)) {
        profileDropdown.style.display = "none";
      }
    });
  }

  /* ===========================
     TABLE FILTER VIA STATS
  ============================ */
  const stats  = document.querySelectorAll(".stat");
  const rows   = document.querySelectorAll("#tableBody tr");
  const badge  = document.getElementById("filterBadge");

  const labelMap = {
    all: "All Reservations",
    pending: "Pending Reservations",
    approved: "Approved Reservations",
    cancelled: "Cancelled Reservations"
  };

  stats.forEach(stat => {
    stat.addEventListener("click", () => {
      const filter = stat.dataset.filter || "all";

      stats.forEach(s => s.classList.remove("active"));
      stat.classList.add("active");

      if (badge) {
        badge.textContent = "Showing: " + (labelMap[filter] || "All Reservations");
      }

      rows.forEach((row, index) => {
        const status = (row.dataset.status || "").toLowerCase();
        const show = (filter === "all" || status === filter);

        row.style.display = show ? "table-row" : "none";

        // Re-apply fade-in animation for visible rows
        if (show) {
          row.style.animation = "none";
          // Force reflow
          void row.offsetWidth;
          row.style.animation = "";
          row.style.animationDelay = (index * 0.03) + "s";
        }
      });
    });
  });
});
</script>
</body>
</html>
