<?php
// ============================================
//  STAFF - CUSTOMER LIST (MATCH STAFF DASHBOARD UI)
// ============================================
declare(strict_types=1);

/*  Secure session (same pattern as other pages) */
session_set_cookie_params([
    'lifetime' => 0,
    'path'     => '/',
    'domain'   => '',
    'secure'   => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

/*  STAFF ONLY (same gate as staff-walkin, etc.)  */
if (empty($_SESSION['user_id']) || (($_SESSION['role'] ?? '') !== 'staff')) {
    header('Location: admin-login.php');
    exit;
}

/*  Helper  */
function e(?string $v): string {
    return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8');
}

$meName = trim((string)($_SESSION['name'] ?? 'Coco Staff • Staff'));
$initial = strtoupper(substr($meName, 0, 1) ?: 'S');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Customer List - Staff Portal</title>

  <link rel="stylesheet"
        href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>

  <style>
    :root {
      --primary: #111827;
      --accent: #0b72d1;
      --accent-soft: rgba(11, 114, 209, 0.10);
      --bg-main: #f3f6fb;
      --bg-soft: #ffffff;
      --border: #e5e7eb;
      --border-soft: #edf2fb;
      --muted: #6b7280;
      --sidebar-w: 260px;

      --green-soft: #dcfce7;
      --green-deep: #166534;
      --red-soft: #fee2e2;
      --red-deep: #991b1b;
    }

    * {
      margin: 0;
      padding: 0;
      box-sizing: border-box;
      font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
    }

    body {
      background: radial-gradient(circle at top left, #eef4ff 0, #f9fafb 40%, #f3f4f6 100%);
      color: var(--primary);
      overflow-x: hidden;
    }

    a { text-decoration: none; color: inherit; }

    /* ============== SIDEBAR (MATCH STAFF DASHBOARD) ============== */
    .sidebar {
      position: fixed;
      inset: 0 auto 0 0;
      width: var(--sidebar-w);
      background: #ffffff;
      border-right: 1px solid var(--border-soft);
      padding: 20px 18px 18px;
      display: flex;
      flex-direction: column;
      gap: 14px;
      z-index: 40;
    }

    .sb-head {
      display: flex;
      align-items: center;
      gap: 12px;
      margin-bottom: 4px;
    }

    .sb-logo {
      width: 46px;
      height: 46px;
      border-radius: 16px;
      object-fit: cover;
      box-shadow: 0 10px 24px rgba(0,0,0,0.25);
    }

    .sb-title {
      font-size: 18px;
      font-weight: 800;
      color: #0f172a;
    }

    .sb-tag {
      font-size: 13px;
      color: var(--muted);
    }

    .nav {
      display: flex;
      flex-direction: column;
      gap: 4px;
      margin-top: 6px;
    }

    .nav-item,
    .nav-toggle {
      display: flex;
      align-items: center;
      justify-content: flex-start;
      gap: 10px;
      padding: 10px 12px;
      border-radius: 999px;
      font-size: 14px;
      color: #4b5563;
      cursor: pointer;
      transition: background 0.16s ease, transform 0.12s ease;
    }

    .nav-item i,
    .nav-toggle i.fa-calendar-days {
      width: 18px;
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
      box-shadow: 0 10px 25px rgba(15,118,220,0.28);
      font-weight: 600;
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
      color: #9ca3af;
      transition: transform 0.2s ease;
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

    /* ============== MAIN SHELL ============== */
    .main {
      margin-left: var(--sidebar-w);
      padding: 22px 32px 32px;
      min-height: 100vh;
    }

    /* ============== TOP HEADER (LIKE STAFF DASHBOARD) ============== */
    .top-shell {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 16px;
      background: #ffffff;
      border-radius: 999px;
      padding: 10px 18px;
      box-shadow: 0 18px 40px rgba(15,23,42,0.08);
      border: 1px solid rgba(148,163,184,0.18);
      margin-bottom: 20px;
    }

    .page-title-wrap {
      display: flex;
      align-items: center;
      gap: 12px;
    }

    .page-icon {
      width: 42px;
      height: 42px;
      border-radius: 999px;
      display: flex;
      align-items: center;
      justify-content: center;
      background: radial-gradient(circle at 30% 0, #eff6ff 0, #bfdbfe 36%, #1d4ed8 100%);
      color: #ffffff;
      box-shadow: 0 15px 35px rgba(37,99,235,0.4);
      font-size: 18px;
    }

    .page-text h1 {
      font-size: 18px;
      font-weight: 700;
      color: #0f172a;
      display: flex;
      align-items: center;
      gap: 8px;
    }

    .page-text p {
      font-size: 13px;
      color: var(--muted);
      margin-top: 2px;
    }

    /* profile on top-right */
    .user-pill {
      display: inline-flex;
      align-items: center;
      gap: 10px;
      padding: 6px 10px;
      border-radius: 999px;
      background: radial-gradient(circle at top left, #eff6ff 0, #dbeafe 40%, #1d4ed8 110%);
      color: #0f172a;
      cursor: pointer;
      position: relative;
      box-shadow: 0 18px 40px rgba(15,23,42,0.3);
    }

    .avatar {
      width: 32px;
      height: 32px;
      border-radius: 999px;
      background: rgba(15,23,42,0.12);
      display: flex;
      align-items: center;
      justify-content: center;
      color: #eff6ff;
      font-weight: 700;
      text-transform: uppercase;
    }

    .user-pill span.label {
      font-size: 13px;
      font-weight: 500;
      color: #0b1120;
    }

    .user-pill i.fa-chevron-down {
      font-size: 11px;
      color: #0b1120;
    }

    .dropdown {
      position: absolute;
      top: 44px;
      right: 0;
      min-width: 160px;
      background: #ffffff;
      border-radius: 14px;
      border: 1px solid #e5e7eb;
      box-shadow: 0 18px 40px rgba(0,0,0,0.16);
      display: none;
      overflow: hidden;
      z-index: 50;
    }

    .dropdown a {
      display: flex;
      align-items: center;
      gap: 8px;
      padding: 10px 13px;
      font-size: 14px;
      color: #111827;
    }
    .dropdown a:hover {
      background: #f3f4f6;
    }

    /* ============== SEARCH ROW (MATCH STYLE) ============== */
    .search-strip {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
      background: rgba(255,255,255,0.9);
      border-radius: 999px;
      padding: 8px 14px;
      border: 1px solid rgba(226,232,240,0.9);
      box-shadow: 0 10px 30px rgba(15,23,42,0.05);
      margin-bottom: 18px;
      flex-wrap: wrap;
    }

    .search-left {
      display: flex;
      align-items: center;
      gap: 10px;
      flex: 1 1 260px;
    }

    .search-input-wrap {
      position: relative;
      flex: 1 1 auto;
      max-width: 380px;
    }

    .search-input-wrap i {
      position: absolute;
      left: 11px;
      top: 50%;
      transform: translateY(-50%);
      font-size: 13px;
      color: var(--muted);
    }

    #searchInput {
      width: 100%;
      padding: 8px 12px 8px 30px;
      border-radius: 999px;
      border: 1px solid #d1d5db;
      font-size: 13px;
      outline: none;
      background: #ffffff;
      transition: box-shadow 0.16s ease, border-color 0.16s ease;
    }

    #searchInput:focus {
      border-color: var(--accent);
      box-shadow: 0 0 0 2px var(--accent-soft);
    }

    .badge-count {
      font-size: 12px;
      padding: 5px 11px;
      border-radius: 999px;
      background: #e0f2fe;
      color: #0369a1;
      font-weight: 600;
      white-space: nowrap;
    }

    /* ============== CARD + TABLE ============== */
    .card {
      background: #ffffff;
      border-radius: 24px;
      border: 1px solid #e5e7eb;
      box-shadow: 0 22px 45px rgba(15,23,42,0.08);
      overflow: hidden;
    }

    .card header {
      padding: 14px 18px;
      border-bottom: 1px solid #e5e7eb;
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 10px;
      font-size: 14px;
      font-weight: 700;
      color: #0f172a;
    }

    .card header span.sub {
      font-size: 13px;
      font-weight: 400;
      color: var(--muted);
    }

    .tablewrap {
      width: 100%;
      overflow-x: auto;
    }

    table {
      width: 100%;
      border-collapse: collapse;
      min-width: 880px;
    }

    thead {
      background: #f9fafb;
    }

    th, td {
      padding: 11px 14px;
      font-size: 13px;
      text-align: left;
      border-bottom: 1px solid #f1f5f9;
      white-space: nowrap;
    }

    th {
      font-size: 11px;
      letter-spacing: 0.08em;
      text-transform: uppercase;
      color: #6b7280;
      font-weight: 700;
    }

    tbody tr {
      transition: background 0.12s ease;
    }

    tbody tr:hover {
      background: #f9fafb;
    }

    tbody tr:last-child td {
      border-bottom: none;
    }

    .status-pill {
      display: inline-flex;
      align-items: center;
      padding: 4px 10px;
      border-radius: 999px;
      font-size: 11px;
      font-weight: 700;
      text-transform: capitalize;
    }
    .status-pill.active {
      background: var(--green-soft);
      color: var(--green-deep);
    }
    .status-pill.inactive {
      background: var(--red-soft);
      color: var(--red-deep);
    }

    /* ============== RESPONSIVE ============== */
    @media (max-width: 900px) {
      .sidebar {
        transform: translateX(-100%);
      }
      .sidebar.open {
        transform: translateX(0);
      }
      .main {
        margin-left: 0;
        padding: 18px 16px 26px;
      }
      .top-shell {
        border-radius: 18px;
        padding-inline: 12px;
      }
    }

    @media (max-width: 640px) {
      .top-shell {
        flex-direction: column;
        align-items: flex-start;
      }
      .search-strip {
        border-radius: 18px;
      }
    }
  </style>
</head>
<body>

<!-- ============== SIDEBAR ============== -->
<aside class="sidebar" id="sidebar">
  <div class="sb-head">
    <img src="logo.jpg" alt="Cocovalley Logo" class="sb-logo">
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
        <a href="staff-reservation-list.php">List View</a>
        <a href="staff-walkin.php">Walk-in</a>
      </div>
    </div>

    <a href="staff-payment.php" class="nav-item">
      <i class="fa-solid fa-receipt"></i>Payment Proofs
    </a>

    <a href="staff-customer-list.php" class="nav-item active">
      <i class="fa-solid fa-users"></i>Customer List
    </a>

    <a href="staff-notification.php" class="nav-item">
      <i class="fa-solid fa-bell"></i>Notification
    </a>

    <a href="staff-announcement.php" class="nav-item">
      <i class="fa-solid fa-bullhorn"></i>Announcements
    </a>
  </nav>
</aside>

<!-- ============== MAIN ============== -->
<main class="main">

  <!-- TOP HEADER (same vibe as Staff Dashboard) -->
  <section class="top-shell">
    <div class="page-title-wrap">
      <div class="page-icon">
        <i class="fa-solid fa-users"></i>
      </div>
      <div class="page-text">
        <h1>Customer List</h1>
        <p>All registered Cocovalley customers handled by staff.</p>
      </div>
    </div>

    <div class="user-pill" id="userPill">
      <div class="avatar"><?= e($initial) ?></div>
      <span class="label"><?= e($meName) ?></span>
      <i class="fa-solid fa-chevron-down"></i>

      <div class="dropdown" id="userDropdown">
        <a href="staff-logout.php">
          <i class="fa-solid fa-arrow-right-from-bracket"></i>
          Logout
        </a>
      </div>
    </div>
  </section>

  <!-- SEARCH STRIP -->
  <section class="search-strip">
    <div class="search-left">
      <div class="search-input-wrap">
        <i class="fa-solid fa-magnifying-glass"></i>
        <input type="text"
               id="searchInput"
               placeholder="Search customer name or email…">
      </div>
    </div>

    <span class="badge-count" id="badgeCount">
      0 customers
    </span>
  </section>

  <!-- TABLE CARD -->
  <section class="card">
    <header>
      <span>Registered Customers</span>
      <span class="sub">All customers who registered in the system.</span>
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
        <!-- Filled by JS -->
        </tbody>
      </table>
    </div>
  </section>
</main>

<script>
document.addEventListener("DOMContentLoaded", () => {
  /* ===== Reservations submenu auto-open like dashboard ===== */
  const resToggle = document.getElementById("resToggle");
  const resMenu   = document.getElementById("resMenu");
  const chev      = document.getElementById("chev");

  if (resToggle && resMenu && chev) {
    resToggle.addEventListener("click", () => {
      const open = resMenu.style.display === "flex";
      resMenu.style.display = open ? "none" : "flex";
      chev.classList.toggle("open", !open);
    });

    // default open (same as screenshot)
    resMenu.style.display = "flex";
    chev.classList.add("open");
  }

  /* ===== Profile dropdown ===== */
  const userPill      = document.getElementById("userPill");
  const userDropdown  = document.getElementById("userDropdown");

  if (userPill && userDropdown) {
    userPill.addEventListener("click", (e) => {
      e.stopPropagation();
      const isOpen = userDropdown.style.display === "flex";
      userDropdown.style.display = isOpen ? "none" : "flex";
      userDropdown.style.flexDirection = "column";
    });

    document.addEventListener("click", (e) => {
      if (!userPill.contains(e.target)) {
        userDropdown.style.display = "none";
      }
    });
  }

  /* ===== Fetch customers from API (same endpoint you already use) ===== */
  const tbody      = document.getElementById("tableBody");
  const badgeCount = document.getElementById("badgeCount");
  const searchInput = document.getElementById("searchInput");

  async function loadCustomers(q = "") {
    try {
      const res = await fetch("customer-api.php?q=" + encodeURIComponent(q), {
        headers: { "Accept": "application/json" }
      });
      const data = await res.json();

      const list = Array.isArray(data.data) ? data.data : [];
      renderTable(list);
    } catch (err) {
      console.error("Customer fetch error:", err);
      renderTable([]);
    }
  }

  function renderTable(list) {
    tbody.innerHTML = "";

    if (!list.length) {
      tbody.innerHTML =
        `<tr><td colspan="6" style="text-align:center;padding:18px;color:#6b7280;">
           No customers found.
         </td></tr>`;
      if (badgeCount) badgeCount.textContent = "0 customers";
      return;
    }

    list.forEach((c, index) => {
      const tr = document.createElement("tr");
      const statusVal = (c.status || "").toString().toLowerCase();
      const statusClass = ["1","active","enabled"].includes(statusVal) ? "active" : "inactive";
      const statusLabel = statusClass === "active" ? "Active" : "Inactive";

      let prettyDate = "-";
      if (c.created_at) {
        const d = new Date(c.created_at);
        if (!isNaN(d)) {
          const options = { year: "numeric", month: "short", day: "2-digit" };
          prettyDate = d.toLocaleDateString(undefined, options);
        }
      }

      tr.innerHTML = `
        <td>${index + 1}</td>
        <td>${c.name || c.full_name || "-"}</td>
        <td>${c.email || "-"}</td>
        <td>${c.contact || c.phone || "-"}</td>
        <td>
          <span class="status-pill ${statusClass}">
            ${statusLabel}
          </span>
        </td>
        <td>${prettyDate}</td>
      `;

      tbody.appendChild(tr);
    });

    if (badgeCount) {
      const total = list.length;
      badgeCount.textContent = `${total} customer${total === 1 ? "" : "s"}`;
    }
  }

  if (searchInput) {
    searchInput.addEventListener("input", () => {
      const q = searchInput.value.trim();
      loadCustomers(q);
    });
  }

  // Initial load
  loadCustomers();
});
</script>
</body>
</html>
