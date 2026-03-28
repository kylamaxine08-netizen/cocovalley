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
   DB CONNECTION
============================================ */
require_once __DIR__ . '/handlers/db_connect.php';

if (!$conn || $conn->connect_errno) {
  die("Database connection failed: " . $conn->connect_error);
}

/* Small helper to fetch numeric value safely */
function fetchSingleValue(mysqli $conn, string $sql, string $key = 'total'): float {
  $query = $conn->query($sql);
  if ($query && $query->num_rows > 0) {
    $row = $query->fetch_assoc();
    return (float) ($row[$key] ?? 0);
  }
  return 0.0;
}

/* ============================================
   🧾 RESERVATION COUNTERS
============================================ */
$totalReservations = (int) fetchSingleValue(
  $conn,
  "SELECT COUNT(*) AS total FROM reservations",
  "total"
);

/* ============================================
   💰 TOTAL REVENUE
============================================ */
$totalRevenue = fetchSingleValue(
  $conn,
  "SELECT SUM(amount) AS total FROM payments WHERE payment_status='approved'",
  "total"
);

/* ============================================
   ⏳ CHECK PAID DATE COLUMN
============================================ */
$dateColumn = "created_at";
$col = $conn->query("SHOW COLUMNS FROM payments LIKE 'approved_date'");
if ($col && $col->num_rows > 0) {
  $dateColumn = "approved_date";
}

/* ============================================
   🔥 PEAK REVENUE MONTH
============================================ */
$peakMonthQ = $conn->query("
  SELECT  
      YEAR($dateColumn) AS y,
      MONTHNAME($dateColumn) AS mname,
      SUM(amount) AS total
  FROM payments
  WHERE payment_status='approved' AND $dateColumn IS NOT NULL
  GROUP BY YEAR($dateColumn), MONTH($dateColumn)
  ORDER BY total DESC
  LIMIT 1
");

$peakMonth = [
  'y'     => null,
  'mname' => null,
  'total' => 0.0
];

if ($peakMonthQ && $peakMonthQ->num_rows > 0) {
  $row = $peakMonthQ->fetch_assoc();
  $peakMonth = [
    'y'     => (int)$row['y'],
    'mname' => $row['mname'],
    'total' => (float)$row['total']
  ];
}

/* ============================================
   🔥 PEAK REVENUE YEAR
============================================ */
$peakYearQ = $conn->query("
  SELECT 
      YEAR($dateColumn) AS y,
      SUM(amount) AS total
  FROM payments
  WHERE payment_status='approved'
  GROUP BY YEAR($dateColumn)
  ORDER BY total DESC
  LIMIT 1
");

$peakYear = [
  'y'     => null,
  'total' => 0.0
];

if ($peakYearQ && $peakYearQ->num_rows > 0) {
  $row = $peakYearQ->fetch_assoc();
  $peakYear = [
    'y'     => (int)$row['y'],
    'total' => (float)$row['total']
  ];
}

/* ============================================
   🔥 PEAK RESERVATION MONTH
============================================ */
$peakResQ = $conn->query("
  SELECT 
      YEAR(created_at) AS y,
      MONTHNAME(created_at) AS mname,
      COUNT(*) AS count
  FROM reservations
  GROUP BY YEAR(created_at), MONTH(created_at)
  ORDER BY count DESC
  LIMIT 1
");

$peakRes = [
  'y'     => null,
  'mname' => null,
  'count' => 0
];

if ($peakResQ && $peakResQ->num_rows > 0) {
  $row = $peakResQ->fetch_assoc();
  $peakRes = [
    'y'     => (int)$row['y'],
    'mname' => $row['mname'],
    'count' => (int)$row['count']
  ];
}

/* ============================================
   📊 MONTHLY CHART DATA
============================================ */
$monthlyQ = $conn->query("
  SELECT 
      YEAR($dateColumn) AS year,
      MONTH($dateColumn) AS month,
      MONTHNAME($dateColumn) AS month_name,
      SUM(amount) AS total
  FROM payments
  WHERE payment_status='approved'
  GROUP BY YEAR($dateColumn), MONTH($dateColumn)
  ORDER BY YEAR($dateColumn), MONTH($dateColumn)
");

$chartData = [];

if ($monthlyQ && $monthlyQ->num_rows > 0) {
  while ($row = $monthlyQ->fetch_assoc()) {
    $chartData[] = [
      "year"       => (int)$row["year"],
      "month"      => (int)$row["month"],
      "month_name" => $row["month_name"],
      "total"      => (float)($row["total"] ?? 0)
    ];
  }
}

$chartJson = json_encode($chartData, JSON_UNESCAPED_UNICODE);
$current   = basename($_SERVER['PHP_SELF']);
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <title>Admin • Reports</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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

    /* SIDEBAR – same as dashboard */
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
      box-shadow: 0 10px 20px rgba(11, 114, 209, 0.25);
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
      box-shadow: 0 8px 18px rgba(11, 114, 209, 0.2);
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

    /* TOPBAR – same pattern as dashboard */
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

    .topbar-sub {
      font-size: 12px;
      color: var(--muted);
      margin-top: -2px;
    }

    /* ADMIN PROFILE DROPDOWN – same feel as dashboard */
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

    /* FILTER BAR */
    .filter {
      display: flex;
      align-items: center;
      gap: 10px;
      margin: 10px 0 20px;
      flex-wrap: wrap;
      font-size: 13px;
    }

    .select,
    .input {
      padding: 9px 10px;
      border: 1px solid var(--border);
      border-radius: 999px;
      background: #ffffff;
      font-size: 13px;
      min-width: 120px;
    }

    .select:focus,
    .input:focus {
      outline: none;
      border-color: var(--accent);
      box-shadow: 0 0 0 2px rgba(11,114,209,0.16);
    }

    .btn {
      padding: 9px 14px;
      border-radius: 999px;
      border: 1px solid #0b72d1;
      background: linear-gradient(135deg,#0b72d1,#0a5eb0);
      color: #ffffff;
      font-size: 13px;
      cursor: pointer;
      display: inline-flex;
      align-items: center;
      gap: 6px;
      font-weight: 600;
      box-shadow: 0 10px 20px rgba(11,114,209,0.25);
    }

    .btn:hover {
      filter: brightness(1.06);
      transform: translateY(-1px);
    }

    /* KPI CARDS */
    .kpis {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
      gap: 14px;
      margin-bottom: 16px;
    }

    .kpi {
      background: #ffffff;
      border-radius: 18px;
      border: 1px solid var(--border);
      box-shadow: 0 14px 30px rgba(0,0,0,0.06);
      padding: 14px 16px;
    }

    .kpi .label {
      font-size: 12px;
      text-transform: uppercase;
      letter-spacing: 0.07em;
      color: var(--muted);
      margin-bottom: 6px;
      font-weight: 600;
    }

    .kpi .value {
      font-size: 22px;
      font-weight: 800;
      color: var(--primary);
    }

    .kpi .sub {
      font-size: 12px;
      color: #6b7280;
      margin-top: 4px;
    }

    /* CHART CARD */
    .chart-card {
      background: #ffffff;
      border-radius: 20px;
      border: 1px solid var(--border);
      box-shadow: 0 18px 40px rgba(0,0,0,0.07);
      padding: 16px 18px 18px;
      margin-bottom: 16px;
      height: 260px;
    }

    /* TABS */
    .report-tabs {
      display: flex;
      gap: 8px;
      margin: 14px 0 6px;
      flex-wrap: wrap;
    }

    .report-tab {
      border: none;
      background: #e5e7eb;
      padding: 7px 12px;
      border-radius: 999px;
      font-size: 13px;
      cursor: pointer;
      color: #4b5563;
      display: inline-flex;
      align-items: center;
      gap: 6px;
    }

    .report-tab i {
      font-size: 12px;
    }

    .report-tab.active {
      background: #0b72d1;
      color: #ffffff;
      box-shadow: 0 10px 22px rgba(11,114,209,0.4);
      font-weight: 600;
    }

    /* TABLE WRAP */
    .table-wrap {
      background: #ffffff;
      margin-top: 6px;
      border-radius: 20px;
      box-shadow: 0 18px 40px rgba(0,0,0,0.08);
      overflow: hidden;
      border: 1px solid var(--border);
    }

    .table-header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: 14px 18px;
      gap: 10px;
      flex-wrap: wrap;
      border-bottom: 1px solid #e5e7eb;
    }

    .table-header h3 {
      font-size: 15px;
      font-weight: 700;
      color: #111827;
      display: flex;
      align-items: center;
      gap: 6px;
    }

    .table-header .actions {
      display: flex;
      gap: 8px;
      flex-wrap: wrap;
    }

    .table-header .actions .btn {
      box-shadow: none;
      border-radius: 999px;
    }

    .table-header .actions .btn:nth-child(1) {
      background: #ffffff;
      color: #0b72d1;
      border-color: #cbd5f5;
    }
    .table-header .actions .btn:nth-child(1):hover {
      background: #eff6ff;
    }

    table {
      width: 100%;
      border-collapse: collapse;
      font-size: 13px;
    }

    thead th {
      text-align: left;
      padding: 10px 18px;
      background: #f9fafb;
      color: #6b7280;
      font-size: 11px;
      text-transform: uppercase;
      letter-spacing: 0.08em;
      border-bottom: 1px solid #e5e7eb;
    }

    tbody td {
      padding: 11px 18px;
      border-bottom: 1px solid #f1f5f9;
      font-size: 13px;
    }

    tbody tr:last-child td {
      border-bottom: none;
    }

    tbody tr:nth-child(even) {
      background: #fcfdff;
    }

    /* STATUS / METHOD PILLS */
    .status-pill {
      display: inline-flex;
      align-items: center;
      padding: 3px 9px;
      border-radius: 999px;
      font-size: 11px;
      font-weight: 600;
    }

    .status-verified {
      background: #dcfce7;
      color: #166534;
    }

    .status-pending {
      background: #fef9c3;
      color: #854d0e;
    }

    .method-pill {
      display: inline-flex;
      align-items: center;
      padding: 3px 9px;
      border-radius: 999px;
      font-size: 11px;
      background: #eff6ff;
      color: #1d4ed8;
    }

    /* PRINT BRAND */
    .print-brand {
      display: none;
      align-items: center;
      gap: 12px;
      margin: 12px 0;
    }

    .print-brand img {
      width: 46px;
      height: 46px;
      border-radius: 10px;
      object-fit: cover;
    }

    .print-brand .t {
      font-weight: 800;
      color: #0e4a8a;
    }

    /* LOGOUT MODAL */
    .modal {
      position: fixed;
      inset: 0;
      background: rgba(15,23,42,0.45);
      display: none;
      align-items: center;
      justify-content: center;
      z-index: 60;
      padding: 20px;
    }

    .sheet {
      background: #ffffff;
      border-radius: 18px;
      border: 1px solid #e5e7eb;
      padding: 18px 18px 16px;
      width: min(380px, 100%);
      box-shadow: 0 20px 55px rgba(0,0,0,0.25);
    }

    .sheet header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      margin-bottom: 8px;
    }

    .sheet header h3 {
      font-size: 16px;
      color: #111827;
    }

    .sheet .x {
      border: none;
      background: transparent;
      font-size: 18px;
      cursor: pointer;
      color: #6b7280;
    }

    .sheet .x:hover {
      color: #111827;
    }

    .sheet .actions {
      display: flex;
      justify-content: flex-end;
      gap: 8px;
      margin-top: 14px;
    }

    .sheet .actions .btn {
      box-shadow: none;
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
      .kpis {
        grid-template-columns: repeat(2, minmax(0, 1fr));
      }
    }

    @media (max-width: 640px) {
      .kpis {
        grid-template-columns: minmax(0, 1fr);
      }
      .topbar {
        border-radius: 18px;
      }
    }

    /* PRINT MODE */
    @media print {
      .sidebar,
      .topbar,
      .filter,
      .report-tabs,
      .table-header .actions,
      .chart-card,
      .kpis {
        display: none !important;
      }
      .main {
        margin: 0 !important;
        padding: 0 !important;
      }
      .print-brand {
        display: flex !important;
      }
      .table-wrap {
        box-shadow: none !important;
        border-radius: 0 !important;
      }
    }
  </style>
</head>
<body>

<!-- Hidden logout form (same approach as dashboard) -->
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
    <a href="admin-dashboard.php" class="nav-item <?= $current==='admin-dashboard.php'?'active':'' ?>">
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
        <a href="admin-calendar.php" class="<?= $current==='admin-calendar.php'?'active':'' ?>">Calendar View</a>
        <a href="admin-reservation-list.php" class="<?= $current==='admin-reservation-list.php'?'active':'' ?>">List View</a>
        <a href="admin-2dmap.php" class="<?= $current==='admin-2dmap.php'?'active':'' ?>">2D Map</a>
      </div>
    </div>

    <a href="admin-payment.php" class="nav-item <?= $current==='admin-payment.php'?'active':'' ?>">
      <i class="fa-solid fa-receipt"></i>Payment Proofs
    </a>
    <a href="admin-customer-list.php" class="nav-item <?= $current==='admin-customer-list.php'?'active':'' ?>">
      <i class="fa-solid fa-users"></i>Customer List
    </a>
    <a href="admin-notification.php" class="nav-item <?= $current==='admin-notification.php'?'active':'' ?>">
      <i class="fa-solid fa-bell"></i>Notification</a>
    <a href="admin-announcement.php" class="nav-item <?= $current==='admin-announcement.php'?'active':'' ?>">
      <i class="fa-solid fa-bullhorn"></i>Announcements</a>
    <a href="admin-accommodations.php" class="nav-item <?= $current==='admin-accommodations.php'?'active':'' ?>">
      <i class="fa-solid fa-bed"></i>Accommodations</a>
    <a href="admin-reports.php" class="nav-item active">
      <i class="fa-solid fa-chart-line"></i>Reports</a>
    <a href="admin-archive.php" class="nav-item <?= $current==='admin-archive.php'?'active':'' ?>">
      <i class="fa-solid fa-box-archive"></i>Archive</a>
    <a href="admin-system-settings.php" class="nav-item <?= $current==='admin-system-settings.php'?'active':'' ?>">
      <i class="fa-solid fa-gear"></i>System Settings</a>
  </nav>
</aside>

<div class="mobile-overlay" id="mobileOverlay"></div>

<!-- MAIN -->
<main class="main">

  <!-- TOPBAR (same structure as dashboard) -->
  <div class="topbar">
    <div class="topbar-left">
      <button class="menu-btn" id="menuBtn" aria-label="Open sidebar">
        <i class="fa-solid fa-bars"></i>
      </button>
      <div>
        <h1>
          <i class="fa-solid fa-chart-line"></i>
          Reports & Analytics
        </h1>
        <div class="topbar-sub">Reservation revenue and peak performance overview.</div>
      </div>
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

  <!-- FILTERS -->
  <div class="filter">
    <span><strong>View by:</strong></span>

    <select id="reportType" class="select" onchange="toggleFilters()">
      <option value="monthly" selected>Monthly (year)</option>
      <option value="daily">Daily (date range)</option>
      <option value="yearly">Yearly (range)</option>
    </select>

    <!-- Monthly -->
    <span id="filterMonthly">
      <input type="number" id="yearInput" class="input"
        min="2016" max="2099" step="1" placeholder="Year" style="width:120px">
    </span>

    <!-- Daily -->
    <span id="filterDaily" style="display:none;">
      <input type="date" id="startDate" class="input">
      <input type="date" id="endDate" class="input">
    </span>

    <!-- Yearly -->
    <span id="filterYearly" style="display:none;">
      <input type="number" id="startYear" class="input" placeholder="Start Year" style="width:140px">
      <input type="number" id="endYear" class="input" placeholder="End Year" style="width:140px">
    </span>

    <button class="btn" id="applyBtn">
      <i class="fa-solid fa-arrows-rotate"></i> Apply
    </button>
  </div>

  <!-- KPI CARDS -->
  <section class="kpis">
    <div class="kpi">
      <div class="label">Total Revenue</div>
      <div id="kpiRevenue" class="value">₱0</div>
      <div class="sub" id="kpiRevenueSub">—</div>
    </div>

    <div class="kpi">
      <div class="label">Total Reservations</div>
      <div id="kpiCount" class="value">0</div>
      <div class="sub" id="kpiCountSub">—</div>
    </div>

    <div class="kpi">
      <div class="label">Average Ticket</div>
      <div id="kpiAvg" class="value">₱0</div>
      <div class="sub">Revenue ÷ Reservations</div>
    </div>
  </section>

  <!-- PEAK PERFORMANCE CARDS -->
  <section class="kpis" style="margin-top: 6px;">
    <div class="kpi" style="border-left:4px solid #0b72d1;">
      <div class="label">Peak Revenue Month</div>
      <div class="value">
        <?= $peakMonth['mname'] ? $peakMonth['mname'] . ' ' . $peakMonth['y'] : '—' ?>
      </div>
      <div class="sub">
        <?= $peakMonth['total'] ? '₱' . number_format($peakMonth['total']) : 'No data' ?>
      </div>
    </div>

    <div class="kpi" style="border-left:4px solid #15803d;">
      <div class="label">Peak Revenue Year</div>
      <div class="value">
        <?= $peakYear['y'] ?: '—' ?>
      </div>
      <div class="sub">
        <?= $peakYear['total'] ? '₱' . number_format($peakYear['total']) : 'No data' ?>
      </div>
    </div>

    <div class="kpi" style="border-left:4px solid #a16207;">
      <div class="label">Peak Reservation Month</div>
      <div class="value">
        <?= $peakRes['mname'] ? $peakRes['mname'] . ' ' . $peakRes['y'] : '—' ?>
      </div>
      <div class="sub">
        <?= $peakRes['count'] ? $peakRes['count'] . ' reservations' : 'No data' ?>
      </div>
    </div>
  </section>

  <!-- CHART -->
  <section class="chart-card">
    <canvas id="reportChart"></canvas>
  </section>

  <!-- TABS -->
  <div class="report-tabs">
    <button class="report-tab active" data-target="breakdownSection">
      <i class="fa-solid fa-table"></i> Breakdown Report
    </button>
    <button class="report-tab" data-target="gcashSection">
      <i class="fa-solid fa-wallet"></i> GCash Transactions
    </button>
  </div>

  <!-- PRINT BRAND (for print mode only) -->
  <div class="print-brand">
    <img src="logo.jpg" alt="Cocovalley Logo">
    <div>
      <div class="t">Cocovalley Richnez Waterpark</div>
      <div style="font-size:12px;color:#374151">Reservation Revenue Breakdown</div>
    </div>
  </div>

  <!-- BREAKDOWN TABLE -->
  <section id="breakdownSection" class="table-wrap">
    <div class="table-header">
      <h3><i class="fa-solid fa-list-ul"></i> Breakdown</h3>
      <div class="actions">
        <button class="btn" id="bdPrintBtn">
          <i class="fa-solid fa-print"></i> Print
        </button>
        <button class="btn" id="bdExportBtn">
          <i class="fa-solid fa-file-export"></i> Export CSV
        </button>
      </div>
    </div>

    <table id="breakdownTable">
      <thead>
        <tr>
          <th>Period</th>
          <th>Revenue</th>
          <th>Reservations</th>
        </tr>
      </thead>
      <tbody id="tableBody"></tbody>
    </table>
  </section>

  <!-- GCASH SECTION (sample static for now) -->
  <section id="gcashSection" class="table-wrap" style="display:none;">
    <div class="table-header">
      <h3><i class="fa-solid fa-wallet"></i> Approved GCash Transactions</h3>
      <div class="actions">
        <button class="btn" onclick="window.location.href='admin-payment.php'">
          <i class="fa-solid fa-link"></i> Go to Payment Proofs
        </button>
      </div>
    </div>

    <table>
      <thead>
        <tr>
          <th>Reference</th>
          <th>Reservation Code</th>
          <th>Customer</th>
          <th>Method</th>
          <th>Amount</th>
          <th>Status</th>
          <th>Date &amp; Time</th>
        </tr>
      </thead>
      <tbody>
        <tr>
          <td>PP-0001</td>
          <td>CVR-0001</td>
          <td>Maria Santos</td>
          <td><span class="method-pill">GCash (QR)</span></td>
          <td>₱1,500.00</td>
          <td><span class="status-pill status-verified">Approved</span></td>
          <td>2025-11-01 10:30 AM</td>
        </tr>
        <tr>
          <td>PP-0002</td>
          <td>CVC-0004</td>
          <td>John Dela Cruz</td>
          <td><span class="method-pill">GCash (QR)</span></td>
          <td>₱1,000.00</td>
          <td><span class="status-pill status-verified">Approved</span></td>
          <td>2025-11-02 02:15 PM</td>
        </tr>
        <tr>
          <td>PP-0003</td>
          <td>CVX-0001</td>
          <td>Sample Guest</td>
          <td><span class="method-pill">GCash (QR)</span></td>
          <td>₱2,800.00</td>
          <td><span class="status-pill status-verified">Approved</span></td>
          <td>2025-11-03 09:05 AM</td>
        </tr>
      </tbody>
    </table>
  </section>

  <!-- LOGOUT MODAL -->
  <div class="modal" id="logoutModal" role="dialog">
    <div class="sheet">
      <header>
        <h3>Sign out?</h3>
        <button class="x" onclick="closeLogout()">
          <i class="fa-solid fa-xmark"></i>
        </button>
      </header>

      <div style="color:#374151;font-size:14px;margin-bottom:10px;">
        You can log back in anytime using your account.
      </div>

      <div class="actions">
        <button class="btn" style="background:#e5e7eb;color:#111827;border-color:#d1d5db;" onclick="closeLogout()">Cancel</button>
        <button class="btn" style="background:#ef4444;border-color:#ef4444;box-shadow:none;" onclick="doLogout()">
          Logout
        </button>
      </div>
    </div>
  </div>
</main>

<script>
/* ==========================
   SIDEBAR DROPDOWN
========================== */
const resToggle = document.getElementById("resToggle");
const resMenu   = document.getElementById("resMenu");
const chev      = document.getElementById("chev");

if (resToggle && resMenu && chev) {
  resToggle.addEventListener("click", () => {
    const open = resMenu.style.display === "flex";
    resMenu.style.display = open ? "none" : "flex";
    chev.classList.toggle("open", !open);
  });
  // default open on desktop
  resMenu.style.display = "flex";
  chev.classList.add("open");
}

/* ==========================
   MOBILE SIDEBAR
========================== */
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
  mobileOverlay.addEventListener("click", closeSidebar);
}
window.addEventListener("resize", () => {
  if (window.innerWidth > 900) closeSidebar();
});

/* ==========================
   PROFILE DROPDOWN
========================== */
const adminBtn        = document.getElementById("adminBtn");
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

/* ==========================
   LOGOUT MODAL
========================== */
function openLogout() {
  document.getElementById("logoutModal").style.display = "flex";
}
function closeLogout() {
  document.getElementById("logoutModal").style.display = "none";
}
function doLogout() {
  document.getElementById("logoutForm").submit();
}

/* ==========================
   FILTER VISIBILITY
========================== */
function toggleFilters() {
  const type = document.getElementById("reportType").value;

  document.getElementById("filterMonthly").style.display =
    type === "monthly" ? "inline-block" : "none";

  document.getElementById("filterDaily").style.display =
    type === "daily" ? "inline-block" : "none";

  document.getElementById("filterYearly").style.display =
    type === "yearly" ? "inline-block" : "none";
}

/* ==========================
   CHART DATA FROM PHP
========================== */
let chartData = <?php echo $chartJson ?? '[]'; ?>;
let labels    = chartData.map(r => `${r.month_name} ${r.year}`);
let values    = chartData.map(r => parseFloat(r.total || 0));

/* ==========================
   CHART.JS
========================== */
const canvas = document.getElementById("reportChart");
const ctx    = canvas.getContext("2d");

const reportChart = new Chart(ctx, {
  type: "line",
  data: {
    labels,
    datasets: [{
      label: "Revenue (₱)",
      data: values,
      fill: true,
      borderColor: "#0b72d1",
      backgroundColor: "rgba(11,114,209,0.12)",
      tension: 0.35,
      pointRadius: 4,
      pointHoverRadius: 7,
    }],
  },
  options: {
    responsive: true,
    maintainAspectRatio: false,
    plugins: {
      tooltip: {
        callbacks: {
          label: (c) => "₱" + Number(c.parsed.y).toLocaleString(),
        },
      },
      legend: { display: true },
    },
    scales: {
      y: {
        beginAtZero: true,
        ticks: {
          callback: (v) => "₱" + v.toLocaleString(),
        },
      },
      x: {
        ticks: {
          autoSkip: false,
          minRotation: 45,
          maxRotation: 45,
        },
      },
    },
  },
});

/* ==========================
   KPI UPDATE
========================== */
function updateKPIs() {
  const totalRevenue       = values.reduce((a, b) => a + b, 0);
  const totalReservations  = <?php echo (int)($totalReservations ?? 0); ?>;
  const avg                = totalReservations ? (totalRevenue / totalReservations) : 0;

  document.getElementById("kpiRevenue").textContent =
    "₱" + totalRevenue.toLocaleString("en-PH");

  document.getElementById("kpiRevenueSub").textContent =
    chartData.length ? "Since " + chartData[0].year : "—";

  document.getElementById("kpiCount").textContent = totalReservations;
  document.getElementById("kpiCountSub").textContent = "All-time total";

  document.getElementById("kpiAvg").textContent =
    "₱" + Math.round(avg).toLocaleString("en-PH");
}
updateKPIs();

/* ==========================
   DEFAULT TABLE
========================== */
function refreshTable() {
  const tbody = document.getElementById("tableBody");
  tbody.innerHTML = "";

  chartData.forEach(row => {
    const tr = document.createElement("tr");
    tr.innerHTML = `
      <td>${row.month_name} ${row.year}</td>
      <td>₱${Number(row.total).toLocaleString()}</td>
      <td>1</td>
    `;
    tbody.appendChild(tr);
  });
}
refreshTable();

/* ==========================
   TABLE FOR FILTERED DATA
========================== */
function refreshTableFiltered(data, type) {
  const tbody = document.getElementById("tableBody");
  tbody.innerHTML = "";

  data.forEach(row => {
    let period = "";

    if (type === "monthly") period = row.month_name;
    if (type === "daily")   period = row.date;
    if (type === "yearly")  period = row.year;

    const tr = document.createElement("tr");
    tr.innerHTML = `
      <td>${period}</td>
      <td>₱${Number(row.revenue || 0).toLocaleString()}</td>
      <td>${row.reservations || 0}</td>
    `;
    tbody.appendChild(tr);
  });
}

/* ==========================
   APPLY FILTER
========================== */
document.getElementById("applyBtn").addEventListener("click", async () => {
  const type = document.getElementById("reportType").value;

  const form = new FormData();
  form.append("type", type);

  if (type === "monthly") {
    form.append("year",
      document.getElementById("yearInput").value || new Date().getFullYear()
    );
  }

  if (type === "daily") {
    form.append("start", document.getElementById("startDate").value);
    form.append("end",   document.getElementById("endDate").value);
  }

  if (type === "yearly") {
    form.append("startYear", document.getElementById("startYear").value);
    form.append("endYear",   document.getElementById("endYear").value);
  }

  const res  = await fetch("handlers/report-filter.php", { method: "POST", body: form });
  const json = await res.json();

  if (json.status !== "success") {
    alert("⚠️ " + (json.message || "Filter error."));
    return;
  }

  const newData = json.data;

  if (!Array.isArray(newData) || newData.length === 0) {
    alert("⚠ No data found for this filter.");
    return;
  }

  // Map for chart labels + values
  if (type === "monthly") {
    labels = newData.map(r => r.month_name);
    values = newData.map(r => parseFloat(r.revenue || 0));
  }
  if (type === "daily") {
    labels = newData.map(r => r.date);
    values = newData.map(r => parseFloat(r.revenue || 0));
  }
  if (type === "yearly") {
    labels = newData.map(r => r.year);
    values = newData.map(r => parseFloat(r.revenue || 0));
  }

  reportChart.data.labels = labels;
  reportChart.data.datasets[0].data = values;
  reportChart.update();

  updateKPIs();
  refreshTableFiltered(newData, type);
});

/* ==========================
   PRINT BREAKDOWN
========================== */
function printBreakdown() {
  const original = document.getElementById("breakdownTable");
  const clone    = original.cloneNode(true);
  const rows     = Array.from(clone.querySelectorAll("tbody tr"));

  if (rows.length === 0) {
    alert("⚠ No data to print.");
    return;
  }

  const totalRevenue = rows.reduce(
    (s, r) => s + Number(r.children[1].innerText.replace(/[₱,]/g, "")), 0
  );

  const totalRow = document.createElement("tr");
  totalRow.innerHTML = `
    <td><strong>Total</strong></td>
    <td><strong>₱${totalRevenue.toLocaleString()}</strong></td>
    <td><strong>${rows.length}</strong></td>
  `;
  clone.querySelector("tbody").appendChild(totalRow);

  const w = window.open("", "_blank");
  w.document.write(`
    <html><body>
      <h2>Cocovalley Richnez Waterpark</h2>
      <p>Generated: ${new Date().toLocaleString()}</p>
      ${clone.outerHTML}
    </body></html>
  `);

  w.document.close();
  w.print();
}

document.getElementById("bdPrintBtn").addEventListener("click", printBreakdown);

/* ==========================
   EXPORT CSV
========================== */
function exportBreakdownCSV() {
  const rows = Array.from(document.querySelectorAll("#breakdownTable tr"));

  const csv = rows.map(tr =>
    Array.from(tr.querySelectorAll("th,td"))
      .map(td => `"${td.innerText.replace(/₱/g,"").trim()}"`)
      .join(",")
  ).join("\r\n");

  const blob = new Blob([csv], { type: "text/csv" });
  const url  = URL.createObjectURL(blob);

  const a = document.createElement("a");
  a.href = url;
  a.download = "revenue_breakdown.csv";
  document.body.appendChild(a);
  a.click();
  a.remove();

  URL.revokeObjectURL(url);
}

document.getElementById("bdExportBtn").addEventListener("click", exportBreakdownCSV);

/* ==========================
   TABS
========================== */
document.querySelectorAll(".report-tab").forEach((tab) => {
  tab.addEventListener("click", () => {
    document.querySelectorAll(".report-tab").forEach(t =>
      t.classList.remove("active")
    );
    tab.classList.add("active");

    const target = tab.dataset.target;

    document.getElementById("breakdownSection").style.display =
      target === "breakdownSection" ? "block" : "none";

    document.getElementById("gcashSection").style.display =
      target === "gcashSection" ? "block" : "none";
  });
});
</script>
</body>
</html>
