<?php
// =========================================================
//  Cocovalley STAFF Notification System (Bubble UI)
//  – Same structure & data as Admin Notification
// =========================================================
session_start();
require_once '../admin/handlers/db_connect.php';

/* ============================================
    UNIVERSAL NOTIFICATION FUNCTION (GLOBAL)
============================================ */
function sendNotification($email, $item_name, $message, $type = 'system', $posted_by = 'System', $redirect_url = '#') {
    global $conn;

    $stmt = $conn->prepare("
        INSERT INTO notifications (email, item_name, message, type, status, posted_by, redirect_url)
        VALUES (?, ?, ?, ?, 'unread', ?, ?)
    ");

    $stmt->bind_param(
        "ssssss",
        $email,
        $item_name,
        $message,
        $type,        // reservation / payment / accommodation / announcement
        $posted_by,   // Admin / Staff / Customer
        $redirect_url
    );

    $stmt->execute();
    $stmt->close();
}

/* ============================================
    ACCESS CONTROL (STAFF ONLY)
============================================ */
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'staff') {
    header("Location: ../admin-login.php");
    exit;
}

$role   = $_SESSION['role'];
$meName = $_SESSION['name'] ?? "Staff";

/* ============================================
    MARK AS READ (Single)
============================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_read_id'])) {
    $id = (int) $_POST['mark_read_id'];

    $stmt = $conn->prepare("
        UPDATE notifications 
        SET status = 'read' 
        WHERE id = ?
    ");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $stmt->close();

    echo json_encode(["success" => true]);
    exit;
}

/* ============================================
    MARK ALL AS READ
============================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_all'])) {
    $stmt = $conn->prepare("
        UPDATE notifications 
        SET status = 'read' 
        WHERE status = 'unread'
    ");
    $stmt->execute();
    $stmt->close();

    echo json_encode(["success" => true]);
    exit;
}

/* ============================================
    FETCH ALL NOTIFICATIONS (Admin + Staff + Customer)
============================================ */
$notifications = [];

$sql = "
    SELECT 
        id,
        email,
        item_name,
        message,
        image_url,
        redirect_url,
        type,
        status,
        posted_by,
        created_at
    FROM notifications
    ORDER BY created_at DESC
";

$result = $conn->query($sql);

while ($row = $result->fetch_assoc()) {
    $notifications[] = [
        "id"           => (int)$row['id'],
        "email"        => $row['email'] ?? '',
        "item_name"    => $row['item_name'] ?? '',
        "message"      => $row['message'] ?? '',
        "image_url"    => $row['image_url'] ?? '',
        "redirect_url" => $row['redirect_url'] ?? '',
        "type"         => $row['type'] ?? 'system',
        "status"       => $row['status'] ?? 'unread',
        "posted_by"    => $row['posted_by'] ?? 'System',
        "created_at"   => date("M d, Y • h:i A", strtotime($row['created_at'])),
    ];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Notifications | Cocovalley Staff</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<link rel="stylesheet"
      href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

<style>
:root {
  --primary: #222222;
  --accent: #0b72d1; /* BLUE ACCENT like dashboard */
  --accent-soft: rgba(11, 114, 209, 0.08);
  --bg: #f7f7f7;
  --bg-soft: #ffffff;
  --border: #e5e7eb;
  --border-soft: #f0f0f0;
  --text: #111827;
  --muted: #6b7280;
  --shadow-soft: 0 14px 30px rgba(0,0,0,0.08);
  --sidebar-w: 260px;

  --notif-reservation:#0ea5e9;
  --notif-payment:#22c55e;
  --notif-cancel:#ef4444;
  --notif-system:#6366f1;
}

*{
  margin:0;
  padding:0;
  box-sizing:border-box;
  font-family:system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
}
body{
  background:var(--bg);
  color:var(--text);
  overflow-x:hidden;
}
a{text-decoration:none;color:inherit;}

/* =========================
   SIDEBAR (same structure as admin,
   BUT STAFF LINKS)
========================= */
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
  transition: background 0.16s ease, transform 0.12s ease, box-shadow 0.16s ease;
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

/* =========================
   MOBILE OVERLAY
========================= */
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

/* =========================
   MAIN & TOPBAR (dashboard style)
========================= */
.main{
  margin-left:var(--sidebar-w);
  padding:26px 34px 40px;
  min-height:100vh;
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

/* PROFILE */
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
.dropdown a,
.dropdown button {
  padding: 10px 14px;
  font-size: 14px;
  color: #111827;
  border: none;
  background: transparent;
  text-align: left;
  cursor: pointer;
  display: flex;
  align-items: center;
  gap: 8px;
}
.dropdown a:hover,
.dropdown button:hover {
  background:#f3f4f6;
}

/* =========================
   NOTIFICATION PANEL CARD
========================= */
.card {
  background: #ffffff;
  border-radius: 22px;
  border: 1px solid #e5e7eb;
  box-shadow: 0 18px 40px rgba(0,0,0,0.08);
  overflow: hidden;
}

.notif-panel{
  padding:18px 20px 22px;
}

.panel-header{
  display:flex;
  align-items:center;
  justify-content:space-between;
  margin-bottom:10px;
}
.panel-header h2{
  font-size:17px;
  font-weight:700;
  color:var(--primary);
  display:flex;
  align-items:center;
  gap:8px;
}
.panel-header h2 i{
  color:var(--accent);
  background:var(--accent-soft);
  border-radius:999px;
  padding:6px;
}

.filter-row{
  display:flex;
  align-items:center;
  gap:10px;
  flex-wrap:wrap;
}
.filter-buttons{
  display:flex;
  gap:6px;
}
.filter-buttons button{
  padding:6px 12px;
  border-radius:999px;
  border:1px solid var(--border);
  background:#eef2ff;
  color:#334155;
  font-size:12px;
  cursor:pointer;
  transition:all .15s;
}
.filter-buttons button.active,
.filter-buttons button:hover{
  background:var(--accent);
  color:#fff;
  border-color:var(--accent);
}
.btn-mark-all{
  padding:6px 10px;
  border-radius:999px;
  border:1px solid var(--border);
  background:#f9fafb;
  font-size:12px;
  cursor:pointer;
}
.btn-mark-all:hover{
  background:#e5e7eb;
}

/* CHAT CONTAINER */
.chat-container{
  max-width:780px;
  margin:0 auto;
  display:flex;
  flex-direction:column;
  gap:12px;
  padding-top:8px;
}

/* ROW */
.notif-row{
  display:flex;
  gap:10px;
  align-items:flex-start;
}

/* ICON */
.notif-avatar{
  width:40px;height:40px;
  border-radius:50%;
  display:flex;
  align-items:center;
  justify-content:center;
  color:white;
  flex-shrink:0;
  font-size:18px;
  box-shadow:0 4px 10px rgba(0,0,0,.18);
}
.notif-avatar.reservation{background:var(--notif-reservation);}
.notif-avatar.payment{background:var(--notif-payment);}
.notif-avatar.cancel{background:var(--notif-cancel);}
.notif-avatar.system{background:var(--notif-system);}

/* BUBBLE MIX STYLE */
/* Base = READ bubble (light) */
.notif-bubble{
  background:#ffffff;
  border-radius:14px 14px 14px 4px;
  padding:10px 12px 8px;
  position:relative;
  max-width:600px;
  border:1px solid #e5e7eb;
  cursor:pointer;
  transition:transform .15s, box-shadow .15s, background .15s, border-color .15s;
}

/* UNREAD → BLUE SOFT background */
.notif-row.unread .notif-bubble{
  background:var(--accent-soft);
  border-color:rgba(11,114,209,0.35);
  box-shadow:0 10px 24px rgba(11,114,209,0.20);
}
.notif-row.read .notif-bubble{
  background:#f9fafb;
}

.notif-bubble:hover{
  transform:translateY(-1px);
  box-shadow:0 6px 18px rgba(148,163,184,.35);
}

/* Unread dot */
.unread-dot{
  position:absolute;
  top:6px;
  right:10px;
  width:9px;height:9px;
  border-radius:50%;
  background:#ef4444;
  box-shadow:0 0 0 4px rgba(248,113,113,.35);
}

.bubble-title{
  font-size:14px;
  font-weight:700;
  color:#0f172a;
  margin-bottom:3px;
}
.bubble-meta{
  font-size:12px;
  color:#6b7280;
  margin-bottom:4px;
}
.bubble-meta span+span::before{
  content:"•";
  margin:0 4px;
}
.bubble-message{
  font-size:13px;
  color:#374151;
  line-height:1.4;
}
.bubble-message small{
  color:#6b7280;
}

/* Actions */
.bubble-actions{
  margin-top:6px;
  display:flex;
  gap:6px;
  align-items:center;
  font-size:11px;
  color:#9ca3af;
}
.badge-status{
  padding:2px 8px;
  border-radius:999px;
  font-size:11px;
  background:#e0f2fe;
  color:#0369a1;
}
.badge-status.read{
  background:#e5e7eb;
  color:#374151;
}
.btn-mini{
  padding:3px 8px;
  border-radius:999px;
  border:none;
  font-size:11px;
  cursor:pointer;
  background:transparent;
  color:#0b72d1;
}
.btn-mini:hover{
  text-decoration:underline;
}

/* Empty state */
.empty-state{
  text-align:center;
  color:#9ca3af;
  padding:40px 10px 25px;
  font-size:14px;
}
.empty-state i{
  font-size:32px;
  margin-bottom:10px;
  color:#cbd5f5;
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
  .topbar {
    border-radius: 18px;
  }
}
</style>
</head>
<body>

<!-- SIDEBAR (STAFF NAV) -->
<aside class="sidebar" id="sidebar">
  <div class="sb-head">
    <img src="logo.jpg" alt="Cocovalley" class="sb-logo">
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
        <a href="staff-walkin.php">Walk-in</a>
      </div>
    </div>

    <a href="staff-payment.php" class="nav-item">
      <i class="fa-solid fa-receipt"></i> Payment Proofs
    </a>

    <a href="staff-customer-list.php" class="nav-item">
      <i class="fa-solid fa-users"></i> Customer List
    </a>

    <a href="staff-notification.php" class="nav-item active">
      <i class="fa-solid fa-bell"></i> Notifications
    </a>

    <a href="staff-announcement.php" class="nav-item">
      <i class="fa-solid fa-bullhorn"></i> Announcements
    </a>
  </nav>
</aside>

<div class="mobile-overlay" id="mobileOverlay"></div>

<!-- MAIN -->
<main class="main">
  <!-- Topbar (Dashboard style) -->
  <div class="topbar">
    <div class="topbar-left">
      <button class="menu-btn" id="menuBtn" aria-label="Open sidebar">
        <i class="fa-solid fa-bars"></i>
      </button>
      <h1>
        <i class="fa-solid fa-bell"></i>
        Notifications
      </h1>
    </div>

    <div class="admin" id="adminBtn">
      <div class="avatar">
        <?php
          $initial = strtoupper(substr(trim($meName), 0, 1));
          echo htmlspecialchars($initial, ENT_QUOTES, 'UTF-8');
        ?>
      </div>
      <span><?= htmlspecialchars($meName) ?> (<?= htmlspecialchars($role) ?>) ▾</span>

      <div class="dropdown" id="profileDropdown">
        <a href="#">
          <i class="fa-regular fa-id-badge"></i> Profile
        </a>
        <a href="#">
          <i class="fa-solid fa-sliders"></i> Preferences
        </a>
        <a href="staff-logout.php">
          <i class="fa-solid fa-arrow-right-from-bracket"></i> Logout
        </a>
      </div>
    </div>
  </div>

  <!-- Notification panel inside card -->
  <section class="card">
    <div class="notif-panel">
      <div class="panel-header">
        <h2><i class="fa-solid fa-message"></i> Recent Activity</h2>
        <div class="filter-row">
          <div class="filter-buttons">
            <button id="filter-all"     class="active">All</button>
            <button id="filter-unread">Unread</button>
            <button id="filter-read">Read</button>
          </div>
          <button id="btnMarkAll" class="btn-mark-all">
            <i class="fa-solid fa-check-double"></i> Mark all as read
          </button>
        </div>
      </div>

      <div class="chat-container" id="notifList"></div>
    </div>
  </section>
</main>

<!-- 🔔 Notification Sound -->
<audio id="notifSound" preload="auto">
    <source src="sounds/notif.wav" type="audio/wav">
</audio>


<script>
// ===== SIDEBAR DROPDOWN =====
const resToggle = document.getElementById('resToggle');
const resMenu   = document.getElementById('resMenu');
const chev      = document.getElementById('chev');

if (resToggle && resMenu && chev) {
  resToggle.addEventListener('click', () => {
    const isOpen = resMenu.style.display === 'flex';
    resMenu.style.display = isOpen ? 'none' : 'flex';
    chev.classList.toggle('open', !isOpen);
  });
  // default open on desktop
  resMenu.style.display = 'flex';
  chev.classList.add('open');
}

// ===== MOBILE SIDEBAR =====
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
  if (window.innerWidth > 900) {
    closeSidebar();
  }
});

// ===== PROFILE DROPDOWN =====
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

// ===== Notifications Data from PHP =====
window.notifications = <?= json_encode($notifications, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

let currentFilter = 'all'; // all | unread | read

const listEl     = document.getElementById('notifList');
const btnAll     = document.getElementById('filter-all');
const btnUnread  = document.getElementById('filter-unread');
const btnRead    = document.getElementById('filter-read');
const btnMarkAll = document.getElementById('btnMarkAll');

// Utility: choose icon & color based on type or content
function getIconInfo(n) {
  const t = (n.type || '').toLowerCase();

  if (t === 'payment') {
    return {cls:'payment', icon:'fa-receipt'};
  }
  if (t === 'reservation') {
    return {cls:'reservation', icon:'fa-calendar-check'};
  }
  if (t === 'cancel' || t === 'cancelled') {
    return {cls:'cancel', icon:'fa-circle-xmark'};
  }

  const msg = (n.message || '').toLowerCase();
  if (msg.includes('payment') || msg.includes('gcash')) {
    return {cls:'payment', icon:'fa-receipt'};
  }
  if (msg.includes('reservation') || msg.includes('book')) {
    return {cls:'reservation', icon:'fa-calendar-check'};
  }
  if (msg.includes('cancel')) {
    return {cls:'cancel', icon:'fa-circle-xmark'};
  }

  return {cls:'system', icon:'fa-bell'};
}

function renderNotifications() {
  listEl.innerHTML = '';

  const filtered = window.notifications.filter(n => {
    if (currentFilter === 'unread') return (n.status || 'unread') === 'unread';
    if (currentFilter === 'read')   return (n.status || 'unread') === 'read';
    return true;
  });

  if (!filtered.length) {
    listEl.innerHTML = `
      <div class="empty-state">
        <i class="fa-regular fa-bell-slash"></i>
        <div>No notifications found for this filter.</div>
      </div>
    `;
    return;
  }

  filtered.forEach(n => {
    const row = document.createElement('div');
    row.className = 'notif-row ' + ((n.status === 'read') ? 'read' : 'unread');

    const iconInfo = getIconInfo(n);

    const avatar = document.createElement('div');
    avatar.className = 'notif-avatar ' + iconInfo.cls;
    avatar.innerHTML = `<i class="fa-solid ${iconInfo.icon}"></i>`;

    const bubble = document.createElement('div');
    bubble.className = 'notif-bubble';

    if ((n.status || 'unread') === 'unread') {
      const dot = document.createElement('div');
      dot.className = 'unread-dot';
      bubble.appendChild(dot);
    }

    const title = document.createElement('div');
    title.className = 'bubble-title';
    title.textContent = n.item_name || 'Notification';

    const meta = document.createElement('div');
    meta.className = 'bubble-meta';

    const fromWho = document.createElement('span');
    fromWho.textContent = (n.posted_by ? n.posted_by : 'System');

    const when = document.createElement('span');
    when.textContent = n.created_at || '';

    meta.appendChild(fromWho);
    meta.appendChild(when);

    const msg = document.createElement('div');
    msg.className = 'bubble-message';
    msg.innerHTML = n.message || '<small>No details provided.</small>';

    const actions = document.createElement('div');
    actions.className = 'bubble-actions';

    const badge = document.createElement('span');
    badge.className = 'badge-status ' + ((n.status === 'read') ? 'read' : '');
    badge.textContent = (n.status === 'read') ? 'Read' : 'Unread';

    actions.appendChild(badge);

    if (n.status !== 'read') {
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'btn-mini';
      btn.textContent = 'Mark as read';
      btn.addEventListener('click', (ev) => {
        ev.stopPropagation();
        markAsRead(n.id);
      });
      actions.appendChild(btn);
    }

    if (n.redirect_url) {
      const openBtn = document.createElement('button');
      openBtn.type = 'button';
      openBtn.className = 'btn-mini';
      openBtn.textContent = 'Open page';
      openBtn.addEventListener('click', (ev) => {
        ev.stopPropagation();
        window.location.href = n.redirect_url;
      });
      actions.appendChild(openBtn);
    }

    bubble.appendChild(title);
    bubble.appendChild(meta);
    bubble.appendChild(msg);
    bubble.appendChild(actions);

    bubble.addEventListener('click', () => {
      if (n.redirect_url) {
        window.location.href = n.redirect_url;
      }
    });

    row.appendChild(avatar);
    row.appendChild(bubble);
    listEl.appendChild(row);
  });
}

// ===== Mark as read (single) – STAFF ENDPOINT =====
async function markAsRead(id) {
  try {
    const form = new URLSearchParams();
    form.append('mark_read_id', String(id));

    const res = await fetch('staff-notification.php', {
      method: 'POST',
      headers: {'X-Requested-With':'XMLHttpRequest'},
      body: form
    });

    const data = await res.json().catch(() => ({}));
    if (!res.ok || !data.success) {
      alert('Failed to mark as read.');
      return;
    }

    window.notifications = window.notifications.map(n =>
      n.id === id ? {...n, status:'read'} : n
    );
    renderNotifications();
  } catch (e) {
    console.error(e);
    alert('Error communicating with server.');
  }
}

// ===== Mark all as read – STAFF ENDPOINT =====
async function markAllAsRead() {
  if (!confirm('Mark all notifications as read?')) return;

  try {
    const form = new URLSearchParams();
    form.append('mark_all', '1');

    const res = await fetch('staff-notification.php', {
      method: 'POST',
      headers: {'X-Requested-With':'XMLHttpRequest'},
      body: form
    });

    const data = await res.json().catch(() => ({}));
    if (!res.ok || !data.success) {
      alert('Failed to mark all as read.');
      return;
    }

    window.notifications = window.notifications.map(n => ({...n, status:'read'}));
    renderNotifications();
  } catch (e) {
    console.error(e);
    alert('Error communicating with server.');
  }
}

// ===== Filter buttons =====
btnAll.addEventListener('click', () => {
  currentFilter = 'all';
  btnAll.classList.add('active');
  btnUnread.classList.remove('active');
  btnRead.classList.remove('active');
  renderNotifications();
});

btnUnread.addEventListener('click', () => {
  currentFilter = 'unread';
  btnAll.classList.remove('active');
  btnUnread.classList.add('active');
  btnRead.classList.remove('active');
  renderNotifications();
});

btnRead.addEventListener('click', () => {
  currentFilter = 'read';
  btnAll.classList.remove('active');
  btnUnread.classList.remove('active');
  btnRead.classList.add('active');
  renderNotifications();
});

btnMarkAll.addEventListener('click', markAllAsRead);

// Initial render
renderNotifications();

<!-- 🔔 AUTO PLAY SOUND WHEN NEW STAFF NOTIFICATION ARRIVES -->
setInterval(() => {
    fetch("staff-notification.php?json=1")
        .then(res => res.json())
        .then(data => {
            if (!data || data.length === 0) return;

            let latest = data[0]?.created_at ?? null;
            let last = localStorage.getItem("cv_last_notif_time_staff");

            // kung may bagong notification → play sound
            if (latest && latest !== last) {
                document.getElementById("notifSound").play().catch(()=>{});
                localStorage.setItem("cv_last_notif_time_staff", latest);
            }
        });
}, 7000); // check every 7 seconds
</script>

</body>
</html>
