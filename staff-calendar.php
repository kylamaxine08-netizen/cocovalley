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
  'secure'   => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
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

$meName  = trim((string)($_SESSION['name'] ?? 'Staff'));
$initial = strtoupper(substr($meName, 0, 1));
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <title>Reservation Calendar - Staff | Cocovalley Richnez Waterpark</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

  <style>
    :root {
      /* Staff theme (blue) but same structure as admin */
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

      --type-cottage:#22c55e;
      --type-room:#ef4444;
      --type-event:#f59e0b;

      --cell-bg:#f9fafb;
      --cell-border:#e5e7eb;
      --cell-hover:#eef5ff;
      --cell-hover-border:#d4e4ff;
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

    /* ===================== SIDEBAR (same structure as admin) ===================== */
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

    .nav-group {
      display: flex;
      flex-direction: column;
      gap: 6px;
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

    /* Mobile overlay */
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

    /* ===================== MAIN LAYOUT ===================== */
    .main {
      margin-left: var(--sidebar-w);
      padding: 26px 34px 40px;
      min-height: 100vh;
    }

    /* ===================== TOPBAR (pill bar, same as admin) ===================== */
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

    /* ===================== CALENDAR CARD ===================== */
    .card {
      background: #ffffff;
      border-radius: 22px;
      border: 1px solid var(--border);
      box-shadow: 0 18px 40px rgba(0,0,0,0.08);
      overflow: hidden;
    }

    .calendar-header {
      padding: 16px 18px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 10px;
      flex-wrap: wrap;
    }

    .btn-nav {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      padding: 8px 14px;
      border-radius: 999px;
      border: 1px solid #d1d5db;
      background: #ffffff;
      font-size: 13px;
      font-weight: 600;
      color: #0f172a;
      cursor: pointer;
      transition: border-color 0.16s ease, box-shadow 0.16s ease, transform 0.12s ease;
    }

    .btn-nav:hover {
      border-color: var(--accent);
      box-shadow: 0 10px 22px rgba(11,114,209,0.18);
      transform: translateY(-1px);
    }

    .btn-nav i {
      font-size: 13px;
    }

    .month-title {
      font-size: 18px;
      font-weight: 800;
      color: var(--primary);
    }

    .tools {
      padding: 0 18px 16px;
      display: flex;
      gap: 10px;
      align-items: center;
      flex-wrap: wrap;
    }

    .chip {
      border-radius: 999px;
      border: 1px solid #d1d5db;
      padding: 6px 12px;
      font-size: 13px;
      display: inline-flex;
      align-items: center;
      gap: 8px;
      background: #ffffff;
      color: #111827;
      font-weight: 600;
      cursor: pointer;
      transition: border-color 0.16s ease, box-shadow 0.16s ease, background 0.16s ease;
    }

    .chip .dot {
      width: 10px;
      height: 10px;
      border-radius: 999px;
    }

    .chip.active {
      border-color: var(--accent);
      background: var(--accent-soft);
      box-shadow: 0 0 0 3px rgba(11,114,209,0.18);
      color: var(--accent);
    }

    .search {
      margin-left: auto;
      min-width: 230px;
      padding: 8px 12px;
      border-radius: 999px;
      border: 1px solid #d1d5db;
      font-size: 13px;
      outline: none;
      transition: border-color 0.16s ease, box-shadow 0.16s ease;
    }

    .search:focus {
      border-color: var(--accent);
      box-shadow: 0 0 0 3px rgba(11,114,209,0.18);
    }

    .calendar {
      padding: 0 18px 18px;
    }

    .grid {
      display: grid;
      grid-template-columns: repeat(7, minmax(0, 1fr));
      gap: 10px;
    }

    .dow {
      text-align: center;
      font-size: 12px;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.08em;
      color: #6b7280;
      padding-bottom: 4px;
    }

    .cell {
      background: var(--cell-bg);
      border: 1px solid var(--cell-border);
      border-radius: 16px;
      min-height: 120px;
      padding: 10px;
      position: relative;
      transition: background 0.16s ease, border-color 0.16s ease, box-shadow 0.16s ease, transform 0.12s ease;
      overflow: hidden;
    }

    .cell:hover {
      background: var(--cell-hover);
      border-color: var(--cell-hover-border);
      box-shadow: 0 10px 22px rgba(11,114,209,0.14);
      transform: translateY(-1px);
    }

    .cell.out {
      opacity: 0.45;
    }

    .cell.today {
      border-color: var(--accent);
      background: rgba(11,114,209,0.08);
      box-shadow: 0 0 0 3px rgba(11,114,209,0.24);
    }

    .cell .num {
      font-size: 13px;
      font-weight: 700;
      color: #111827;
    }

    .badges {
      margin-top: 6px;
      display: flex;
      flex-direction: column;
      gap: 6px;
    }

    .badge {
      display: flex;
      align-items: center;
      gap: 6px;
      padding: 5px 7px;
      border-radius: 10px;
      font-size: 11px;
      font-weight: 700;
      color: #ffffff;
      max-width: 100%;
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
    }

    .badge.cottage { background: var(--type-cottage); }
    .badge.room    { background: var(--type-room); }
    .badge.event   { background: var(--type-event); }

    .badge .what,
    .badge .who,
    .badge .when {
      min-width: 0;
      overflow: hidden;
      text-overflow: ellipsis;
    }

    .badge .what {
      max-width: 45%;
    }

    .badge .who {
      max-width: 30%;
    }

    .badge .when {
      margin-left: auto;
      opacity: 0.92;
    }

    .more {
      align-self: flex-start;
      background: #e0f2fe;
      color: #0369a1;
      border-radius: 999px;
      padding: 4px 8px;
      font-size: 11px;
      font-weight: 700;
    }

    /* ===================== MODAL ===================== */
    .modal {
      position: fixed;
      inset: 0;
      display: none;
      align-items: center;
      justify-content: center;
      background: rgba(0,0,0,0.45);
      padding: 16px;
      z-index: 50;
    }

    .sheet {
      background: #ffffff;
      border-radius: 18px;
      border: 1px solid var(--border);
      box-shadow: 0 22px 50px rgba(0,0,0,0.25);
      max-width: 820px;
      width: 100%;
    }

    .sheet header {
      padding: 16px 18px;
      border-bottom: 1px solid var(--border);
      display: flex;
      align-items: center;
      justify-content: space-between;
    }

    .sheet header h3 {
      font-size: 18px;
      font-weight: 700;
      color: var(--primary);
    }

    .sheet .x {
      border: none;
      background: transparent;
      font-size: 20px;
      cursor: pointer;
      color: #4b5563;
    }

    .list {
      padding: 12px 18px 16px;
      display: grid;
      gap: 10px;
    }

    .item {
      background: #f9fafb;
      border: 1px solid #e5e7eb;
      border-radius: 12px;
      padding: 10px 12px;
      display: grid;
      grid-template-columns: 16px 1fr;
      gap: 10px;
    }

    .dot {
      width: 10px;
      height: 10px;
      border-radius: 999px;
      margin-top: 6px;
    }

    .item b {
      color: #111827;
    }

    .item small {
      color: #6b7280;
      display: block;
      line-height: 1.35;
    }

    .footer {
      padding: 10px 18px 16px;
      display: flex;
      justify-content: flex-end;
      gap: 10px;
    }

    .linklike {
      padding: 8px 12px;
      border-radius: 999px;
      border: 1px solid #d1d5db;
      background: #ffffff;
      font-size: 13px;
      cursor: pointer;
    }

    .primary {
      padding: 8px 13px;
      border-radius: 999px;
      border: none;
      background: linear-gradient(135deg, #0b72d1, #2563eb);
      color: #ffffff;
      font-size: 13px;
      font-weight: 600;
      cursor: pointer;
      box-shadow: 0 10px 25px rgba(37,99,235,0.35);
    }

    .primary:hover {
      filter: brightness(1.05);
    }

    /* ===================== RESPONSIVE ===================== */
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
      .tools {
        flex-direction: column;
        align-items: flex-start;
      }
      .search {
        margin-left: 0;
        width: 100%;
      }
    }
  </style>
</head>
<body>

<!-- Mobile overlay -->
<div class="mobile-overlay" id="mobileOverlay"></div>

<!-- SIDEBAR (Staff Portal) -->
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
        <a href="staff-calendar.php" class="active">Calendar View</a>
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
      <i class="fa-solid fa-bell"></i>Notifications</a>
    <a href="staff-announcement.php" class="nav-item">
      <i class="fa-solid fa-bullhorn"></i>Announcements</a>
  </nav>
</aside>

<!-- MAIN -->
<main class="main">
  <!-- TOPBAR (same structure as admin, text for staff) -->
  <div class="topbar">
    <div class="topbar-left">
      <button class="menu-btn" id="menuBtn" aria-label="Open sidebar">
        <i class="fa-solid fa-bars"></i>
      </button>
      <h1>
        <i class="fa-solid fa-calendar-days"></i>
        Reservation Calendar
      </h1>
    </div>

    <div class="admin" id="adminBtn">
      <div class="avatar"><?php echo htmlspecialchars($initial, ENT_QUOTES, 'UTF-8'); ?></div>
      <span><?php echo htmlspecialchars($meName, ENT_QUOTES, 'UTF-8'); ?> ▾</span>

      <div class="dropdown" id="profileDropdown">
        <a href="#">
          <i class="fa-regular fa-id-badge"></i> Profile
        </a>
        <button type="button" onclick="location.href='admin-login.php'">
          <i class="fa-solid fa-arrow-right-from-bracket"></i> Logout
        </button>
      </div>
    </div>
  </div>

  <!-- CALENDAR CARD -->
  <section class="card">
    <div class="calendar-header">
      <div style="display:flex; gap:8px; flex-wrap:wrap;">
        <button class="btn-nav" id="prevBtn">
          <i class="fa-solid fa-chevron-left"></i> Prev
        </button>
        <button class="btn-nav" id="todayBtn">
          <i class="fa-regular fa-calendar"></i> Today
        </button>
        <button class="btn-nav" id="nextBtn">
          Next <i class="fa-solid fa-chevron-right"></i>
        </button>
      </div>

      <div class="month-title" id="monthYear">—</div>
    </div>

    <div class="tools">
      <button class="chip active" data-type="cottage">
        <span class="dot" style="background:var(--type-cottage);"></span> Cottage
      </button>
      <button class="chip active" data-type="room">
        <span class="dot" style="background:var(--type-room);"></span> Room
      </button>
      <button class="chip active" data-type="event">
        <span class="dot" style="background:var(--type-event);"></span> Event
      </button>

      <input
        class="search"
        id="searchInput"
        type="text"
        placeholder="Search customer / package / slot / cottage no..."
      >
    </div>

    <div class="calendar">
      <div class="grid" id="dowRow"></div>
      <div class="grid" id="calendarGrid"></div>
    </div>
  </section>
</main>

<!-- MODAL -->
<div class="modal" id="reservationModal">
  <div class="sheet">
    <header>
      <h3>Reservations • <span id="modalDate">—</span></h3>
      <button class="x" onclick="closeModal()">
        <i class="fa-solid fa-xmark"></i>
      </button>
    </header>
    <div class="list" id="modalList"></div>
    <div class="footer">
      <button class="linklike" type="button" onclick="closeModal()">Close</button>
      <button class="primary" type="button" onclick="alert('Day view page not yet implemented')">
        Open Day View
      </button>
    </div>
  </div>
</div>

<script>
/* =================== SIDEBAR + TOPBAR JS (same behavior as admin) =================== */
const sidebar         = document.getElementById("sidebar");
const mobileOverlay   = document.getElementById("mobileOverlay");
const menuBtn         = document.getElementById("menuBtn");
const resToggle       = document.getElementById("resToggle");
const resMenu         = document.getElementById("resMenu");
const chev            = document.getElementById("chev");
const adminBtn        = document.getElementById("adminBtn");
const profileDropdown = document.getElementById("profileDropdown");

if (resToggle && resMenu && chev) {
  resToggle.addEventListener("click", () => {
    const open = resMenu.style.display === "flex";
    resMenu.style.display = open ? "none" : "flex";
    chev.classList.toggle("open", !open);
  });
  resMenu.style.display = "flex";
  chev.classList.add("open");
}

if (menuBtn) {
  menuBtn.addEventListener("click", (e) => {
    e.stopPropagation();
    sidebar.classList.add("open");
    mobileOverlay.classList.add("show");
  });
}

if (mobileOverlay) {
  mobileOverlay.addEventListener("click", () => {
    sidebar.classList.remove("open");
    mobileOverlay.classList.remove("show");
  });
}

window.addEventListener("resize", () => {
  if (window.innerWidth > 900) {
    sidebar.classList.remove("open");
    mobileOverlay.classList.remove("show");
  }
});

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

/* =================== CALENDAR LOGIC (FULLY FIXED) =================== */
const monthYearEl = document.getElementById('monthYear');
const dowRow      = document.getElementById('dowRow');
const grid        = document.getElementById('calendarGrid');
const searchInput = document.getElementById('searchInput');
const chips       = [...document.querySelectorAll('.chip')];

const dayNames = ["Sun","Mon","Tue","Wed","Thu","Fri","Sat"];

dayNames.forEach(d => {
  const el = document.createElement('div');
  el.className = 'dow';
  el.textContent = d;
  dowRow.appendChild(el);
});

let today     = new Date();
let viewMonth = today.getMonth();
let viewYear  = today.getFullYear();

let activeTypes = new Set(["cottage","room","event"]);
let searchQuery = "";

// ---------------- Helpers ----------------
const pad = n => String(n).padStart(2,'0');

function formatLocalDate(d){
  return `${d.getFullYear()}-${pad(d.getMonth()+1)}-${pad(d.getDate())}`;
}

function parseServerDateTime(str){
  if (!str) return null;
  const normalized = str.replace(" ", "T");
  return new Date(normalized);
}

const startOfDay = d => new Date(d.getFullYear(), d.getMonth(), d.getDate(), 0,0,0);
const endOfDay   = d => new Date(d.getFullYear(), d.getMonth(), d.getDate(), 23,59,59,999);

function fmtDateTime(d){
  if (!d || isNaN(d)) return "";
  return new Intl.DateTimeFormat("en-PH", {
    month:'short', day:'2-digit', hour:'2-digit', minute:'2-digit'
  }).format(d);
}

function capitalize(s){ return s ? s[0].toUpperCase() + s.slice(1) : s; }

// ---------------- Load Events ----------------
async function loadMonthData(y,m){
  const first = new Date(y, m, 1);
  const last  = new Date(y, m+1, 0);

  const start = formatLocalDate(first);
  const end   = formatLocalDate(last);

  try {
    const res = await fetch(`../admin/handlers/calendar-data.php?start=${start}&end=${end}`);
    const data = await res.json();
    if (!data.ok || !Array.isArray(data.events)) return [];
    return data.events.map(ev => ({
      ...ev,
      type: (ev.type || 'event').toLowerCase()
    }));
  } catch (err) {
    console.error("Calendar load error (staff):", err);
    return [];
  }
}

// ---------------- Build Grid ----------------
function getMonthMatrix(y,m){
  const first       = new Date(y,m,1);
  const firstDay    = first.getDay();
  const daysInMonth = new Date(y,m+1,0).getDate();
  const prevDays    = new Date(y,m,0).getDate();

  const cells = [];

  // prev month filler
  for(let i = firstDay - 1; i >= 0; i--){
    const d = prevDays - i;
    cells.push({ day:d, out:true, date:new Date(y, m-1, d) });
  }

  // current month
  for(let d = 1; d <= daysInMonth; d++){
    cells.push({ day:d, out:false, date:new Date(y, m, d) });
  }

  // next month filler
  while (cells.length < 42){
    const last = cells[cells.length-1].date;
    const next = new Date(last.getFullYear(), last.getMonth(), last.getDate()+1);
    cells.push({ day:next.getDate(), out:true, date:next });
  }

  return cells;
}

// ---------------- Event overlap ----------------
function overlapsDate(ev, day){
  const s = parseServerDateTime(ev.start);
  const e = parseServerDateTime(ev.end);

  if (!s || !e) return false;

  return s <= endOfDay(day) && e >= startOfDay(day);
}

function segmentForDay(ev, day){
  return {
    customer: ev.customer,
    type: ev.type,
    package: ev.package,
    pax: ev.pax,
    time_slot: ev.time_slot,
    cottage_number: ev.cottage_number,
    fullStart: parseServerDateTime(ev.start),
    fullEnd: parseServerDateTime(ev.end)
  };
}

function matchesFilters(seg){
  if (!activeTypes.has(seg.type)) return false;
  if (!searchQuery) return true;
  return [
    seg.customer?.toLowerCase(),
    seg.package?.toLowerCase(),
    seg.time_slot?.toLowerCase(),
    seg.cottage_number ? String(seg.cottage_number) : ""
  ].some(v => v && v.includes(searchQuery));
}

// ---------------- Render Calendar ----------------
async function renderCalendar(){
  const events = await loadMonthData(viewYear, viewMonth);

  monthYearEl.textContent = new Date(viewYear, viewMonth)
      .toLocaleString("default", {month:"long", year:"numeric"});

  grid.innerHTML = "";
  const cells = getMonthMatrix(viewYear, viewMonth);

  cells.forEach(c => {
    const cell = document.createElement("div");
    cell.className = "cell";
    if (c.out) cell.classList.add("out");
    if (c.date.toDateString() === today.toDateString()) cell.classList.add("today");

    const num = document.createElement("div");
    num.className = "num";
    num.textContent = c.day;
    cell.appendChild(num);

    const dayEvents = events
      .filter(ev => overlapsDate(ev, c.date))
      .map(ev => segmentForDay(ev, c.date))
      .filter(seg => matchesFilters(seg));

    if (dayEvents.length){
      const wrap = document.createElement("div");
      wrap.className = "badges";

      dayEvents.slice(0,3).forEach(seg => {
        const b = document.createElement("div");
        b.className = `badge ${seg.type}`;

        let label = capitalize(seg.type);
        if (seg.type === "cottage" && seg.cottage_number){
          label += ` #${seg.cottage_number}`;
        }

        b.innerHTML = `
          <span class="what">${label}</span>
          <span class="who">${seg.customer}</span>
          <span class="when">${seg.time_slot}</span>
        `;
        wrap.appendChild(b);
      });

      if (dayEvents.length > 3){
        const more = document.createElement("span");
        more.className = "more";
        more.textContent = `+${dayEvents.length - 3} more`;
        wrap.appendChild(more);
      }

      cell.appendChild(wrap);
      cell.style.cursor = "pointer";
      cell.addEventListener("click", () => openModal(c.date, dayEvents));
    }

    grid.appendChild(cell);
  });
}

// ---------------- MODAL ----------------
function openModal(date, segments){
  const modal = document.getElementById("reservationModal");
  modal.style.display = "flex";

  document.getElementById("modalDate").textContent = formatLocalDate(date);

  const list = document.getElementById("modalList");
  list.innerHTML = "";

  segments.forEach(seg => {
    const color = getComputedStyle(document.documentElement)
      .getPropertyValue(`--type-${seg.type}`) || "#0b72d1";

    const row = document.createElement("div");
    row.className = "item";

    const cottageLine = seg.type === "cottage" && seg.cottage_number
      ? `<small>Cottage No.: #${seg.cottage_number}</small>`
      : "";

    let typeLabel = capitalize(seg.type);
    if (seg.type === "cottage" && seg.cottage_number){
      typeLabel += ` #${seg.cottage_number}`;
    }

    row.innerHTML = `
      <div class="dot" style="background:${color}"></div>
      <div>
        <b>${seg.customer}</b>
        <small>${typeLabel} — ${seg.package}</small>
        ${cottageLine}
        <small>Pax: ${seg.pax}</small>
        <small>${seg.time_slot}</small>
        <small>${fmtDateTime(seg.fullStart)} → ${fmtDateTime(seg.fullEnd)}</small>
      </div>
    `;
    list.appendChild(row);
  });
}

function closeModal(){
  document.getElementById("reservationModal").style.display = "none";
}

document.getElementById("reservationModal").addEventListener("click", e => {
  if (e.target.id === "reservationModal"){
    closeModal();
  }
});

// ---------------- Navigation ----------------
document.getElementById("prevBtn").addEventListener("click", () => {
  viewMonth--;
  if (viewMonth < 0){
    viewMonth = 11;
    viewYear--;
  }
  renderCalendar();
});

document.getElementById("nextBtn").addEventListener("click", () => {
  viewMonth++;
  if (viewMonth > 11){
    viewMonth = 0;
    viewYear++;
  }
  renderCalendar();
});

document.getElementById("todayBtn").addEventListener("click", () => {
  viewMonth = today.getMonth();
  viewYear  = today.getFullYear();
  renderCalendar();
});

// ---------------- Search + Chips ----------------
if (searchInput){
  searchInput.addEventListener("input", () => {
    searchQuery = searchInput.value.toLowerCase().trim();
    renderCalendar();
  });
}

chips.forEach(chip => {
  chip.addEventListener("click", () => {
    const t = chip.dataset.type;
    if (!t) return;
    if (activeTypes.has(t)){
      activeTypes.delete(t);
      chip.classList.remove("active");
    } else {
      activeTypes.add(t);
      chip.classList.add("active");
    }
    renderCalendar();
  });
});

// ---------------- INIT ----------------
renderCalendar();
</script>
</body>
</html>
