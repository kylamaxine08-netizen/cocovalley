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
   AUTH: ADMIN or STAFF
============================================ */
if (empty($_SESSION['user_id']) || !in_array(($_SESSION['role'] ?? ''), ['admin','staff'], true)) {
  header('Location: admin-login.php');
  exit;
}

/* ============================================
   HELPER
============================================ */
function e(?string $s): string {
  return htmlspecialchars($s ?: '—', ENT_QUOTES, 'UTF-8');
}

$meName = trim($_SESSION['name'] ?? 'Admin/Staff');

/* ============================================
   CSRF TOKEN (for logout)
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
   DATABASE CONNECTION
============================================ */
require_once __DIR__ . '/handlers/db_connect.php';

$customers = [];
if (isset($conn) && $conn instanceof mysqli && !$conn->connect_error) {
  $sql = "
    SELECT 
      id,
      first_name,
      last_name,
      email,
      phone,
      status,
      created_at
    FROM users
    WHERE role = 'customer'
    ORDER BY created_at DESC
  ";

  if ($res = $conn->query($sql)) {
    while ($row = $res->fetch_assoc()) {
      $customers[] = $row;
    }
    $res->free();
  }

  $conn->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Customer List - Cocovalley Admin</title>

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

      --active: #22c55e;
      --inactive: #ef4444;
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

    /* SIDEBAR (same as dashboard) */
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

    /* TOPBAR (same structure as dashboard) */
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

    /* SEARCH BAR AREA */
    .search-row {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 10px;
      margin-bottom: 14px;
      flex-wrap: wrap;
    }

    .search-row-left {
      display: flex;
      align-items: center;
      gap: 8px;
      flex-wrap: wrap;
    }

    .search-input-wrap {
      position: relative;
      display: inline-flex;
      align-items: center;
    }

    .search-input-wrap i {
      position: absolute;
      left: 10px;
      font-size: 13px;
      color: var(--muted);
    }

    #searchInput {
      padding: 8px 12px 8px 28px;
      border-radius: 999px;
      border: 1px solid var(--border);
      background: #ffffff;
      font-size: 13px;
      min-width: 220px;
      outline: none;
      transition: 0.15s;
    }

    #searchInput:focus {
      border-color: var(--accent);
      box-shadow: 0 0 0 2px var(--accent-soft);
    }

    .filter-badge {
      font-size: 12px;
      padding: 4px 10px;
      border-radius: 999px;
      background: #e0f2fe;
      color: #0369a1;
      font-weight: 600;
    }

    /* CARD + TABLE (same style as dashboard) */
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

    /* STATUS PILL */
    .status-pill {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 4px 10px;
      border-radius: 999px;
      font-size: 11px;
      font-weight: 600;
      border: 1px solid transparent;
      text-transform: capitalize;
      color: #ffffff;
    }

    .status-pill.active {
      background: #dcfce7;
      border-color: #bbf7d0;
      color: #166534;
    }

    .status-pill.inactive {
      background: #fee2e2;
      border-color: #fecaca;
      color: #991b1b;
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
    }

    @media (max-width: 640px) {
      .topbar {
        border-radius: 18px;
      }
      #searchInput {
        width: 100%;
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
    <a href="admin-dashboard.php" class="nav-item">
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
    <a href="admin-customer-list.php" class="nav-item active">
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
        <i class="fa-solid fa-users"></i>
        Customer List
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

  <!-- SEARCH ROW (no stats, just search + label) -->
  <section class="search-row">
    <div class="search-row-left">
      <div class="search-input-wrap">
        <i class="fa-solid fa-magnifying-glass"></i>
        <input
          type="text"
          id="searchInput"
          placeholder="Search customer name or email…">
      </div>
      <span class="filter-badge" id="customerBadge">
        <?= count($customers) ?> customers
      </span>
    </div>
  </section>

  <!-- TABLE CARD -->
  <section class="card">
    <header>
      <span>Registered Customers</span>
      <span class="sub">All customers who registered through the system.</span>
    </header>

    <div class="tablewrap">
      <table id="customerTable">
        <thead>
          <tr>
            <th>#</th>
            <th>Full Name</th>
            <th>Email</th>
            <th>Contact</th>
            <th>Status</th>
            <th>Date Registered</th>
          </tr>
        </thead>
        <tbody id="tableBody">
        <?php if (empty($customers)): ?>
          <tr style="animation:none; opacity:1;">
            <td colspan="6" style="text-align:center;color:#6b7280;padding:22px;">
              No customers found.
            </td>
          </tr>
        <?php else: ?>
          <?php foreach ($customers as $idx => $c): 
            $fullName = trim(($c['first_name'] ?? '') . ' ' . ($c['last_name'] ?? ''));
            $fullName = $fullName !== '' ? $fullName : '—';

            // status: might be tinyint or string
            $rawStatus = strtolower((string)($c['status'] ?? ''));
            $isActive  = in_array($rawStatus, ['1','active','enabled'], true);
            $statusClass = $isActive ? 'active' : 'inactive';
            $statusText  = $isActive ? 'Active' : 'Inactive';

            $created = $c['created_at'] ?? '';
            $datePretty = $created
              ? date('M d, Y', strtotime($created))
              : '—';
          ?>
            <tr
              data-name="<?= e(strtolower($fullName)) ?>"
              data-email="<?= e(strtolower($c['email'] ?? '')) ?>"
              style="animation-delay: <?= number_format($idx * 0.03, 2) ?>s;"
            >
              <td><?= $idx + 1 ?></td>
              <td><?= e($fullName) ?></td>
              <td><?= e($c['email'] ?? '') ?></td>
              <td><?= e($c['phone'] ?? '') ?></td>
              <td>
                <span class="status-pill <?= e($statusClass) ?>">
                  <?= e($statusText) ?>
                </span>
              </td>
              <td><?= e($datePretty) ?></td>
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

    // default open on desktop
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
  window.addEventListener("resize", () => {
    if (window.innerWidth > 900) closeSidebar();
  });

  /* ===========================
     PROFILE DROPDOWN
  ============================ */
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

  /* ===========================
     CLIENT-SIDE SEARCH
  ============================ */
  const searchInput = document.getElementById("searchInput");
  const rows        = document.querySelectorAll("#tableBody tr");
  const badge       = document.getElementById("customerBadge");

  function applySearch() {
    const q = (searchInput.value || "").trim().toLowerCase();
    let visible = 0;

    rows.forEach((row) => {
      const name  = (row.dataset.name  || "").toLowerCase();
      const email = (row.dataset.email || "").toLowerCase();

      const match = !q || name.includes(q) || email.includes(q);
      row.style.display = match ? "table-row" : "none";
      if (match) visible++;
    });

    if (badge) {
      badge.textContent = `${visible} customer${visible === 1 ? "" : "s"}`;
    }
  }

  if (searchInput) {
    searchInput.addEventListener("input", () => {
      applySearch();
    });
  }

  // initial badge update (in case of 0 rows)
  applySearch();
});
</script>
</body>
</html>
