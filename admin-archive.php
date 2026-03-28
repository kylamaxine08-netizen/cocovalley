<?php
// ============================================================
// 🔐 SECURE SESSION + ACCESS CONTROL
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

require_once '../admin/handlers/db_connect.php';

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header("Location: admin-login.php");
    exit;
}

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}
$CSRF   = $_SESSION['csrf'];
$meName = $_SESSION['name'] ?? 'Admin';

// Auto-archive helper
include '../admin/handlers/auto-archive.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <title>Archive | Cocovalley Admin</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <style>
:root {
  --primary:#222222;
  --accent:#0b72d1;
  --accent-soft:rgba(11,114,209,0.08);
  --bg:#f7f7f7;
  --white:#ffffff;
  --border:#e5e7eb;
  --border-soft:#f2f2f2;
  --text:#111827;
  --muted:#6b7280;
  --shadow-soft:0 14px 30px rgba(0,0,0,0.08);
  --sidebar-w:260px;
  --green:#10b981;
  --red:#ef4444;
}

/* ========== GLOBAL ========== */
* {
  box-sizing:border-box;
  margin:0;
  padding:0;
  font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;
}
body{
  background:var(--bg);
  color:var(--text);
}
a{
  text-decoration:none;
  color:inherit;
}

/* ========== SIDEBAR (same as Reservation List) ========== */
.sidebar{
  position:fixed;
  inset:0 auto 0 0;
  width:var(--sidebar-w);
  background:var(--white);
  border-right:1px solid var(--border-soft);
  padding:20px 18px;
  display:flex;
  flex-direction:column;
  gap:12px;
  z-index:20;
}
.sb-head{
  display:flex;
  align-items:center;
  gap:12px;
  margin-bottom:10px;
}
.sb-logo{
  width:44px;
  height:44px;
  border-radius:14px;
  object-fit:cover;
  box-shadow:0 8px 20px rgba(0,0,0,0.23);
}
.sb-title{
  font-size:18px;
  font-weight:800;
  color:var(--primary);
}
.sb-tag{
  font-size:13px;
  color:var(--muted);
}

.nav{
  display:flex;
  flex-direction:column;
  gap:4px;
  margin-top:8px;
}
.nav-item,
.nav-toggle{
  display:flex;
  align-items:center;
  justify-content:flex-start;
  gap:10px;
  padding:9px 10px;
  border-radius:999px;
  font-size:14px;
  color:#374151;
  cursor:pointer;
  transition:background 0.16s ease, transform 0.12s ease;
}
.nav-item i,
.nav-toggle i.fa-calendar-days{
  width:16px;
  text-align:center;
}
.nav-item:hover,
.nav-toggle:hover{
  background:#f3f4f6;
  transform:translateY(-1px);
}
.nav-item.active{
  background:var(--accent-soft);
  color:var(--accent);
  font-weight:600;
}

.nav-toggle{
  justify-content:space-between;
}
.nav-toggle .label{
  display:flex;
  align-items:center;
  gap:10px;
}
.chev{
  font-size:12px;
  color:#9ca3af;
  transition:transform 0.2s ease;
}
.chev.open{ transform:rotate(180deg); }

.submenu{
  display:none;
  flex-direction:column;
  gap:4px;
  margin:4px 0 8px 26px;
}
.submenu a{
  padding:7px 10px;
  border-radius:999px;
  font-size:13px;
  color:#4b5563;
}
.submenu a:hover{
  background:#f3f4f6;
}
.submenu a.active{
  background:var(--accent-soft);
  color:var(--accent);
  font-weight:600;
}

/* ========== MAIN LAYOUT ========== */
.main{
  margin-left:var(--sidebar-w);
  padding:26px 34px 40px;
  min-height:100vh;
}

/* ========== TOPBAR (same vibe as Reservation List) ========== */
.topbar{
  display:flex;
  align-items:center;
  justify-content:space-between;
  background:var(--white);
  border-radius:999px;
  padding:10px 18px;
  box-shadow:0 12px 30px rgba(0,0,0,0.06);
  border:1px solid rgba(15,23,42,0.04);
  margin-bottom:22px;
}
.tb-left{
  display:flex;
  flex-direction:column;
  gap:2px;
}
.tb-left h1{
  display:flex;
  align-items:center;
  gap:10px;
  font-size:18px;
  font-weight:700;
  color:var(--primary);
}
.tb-left h1 i{
  background:var(--accent-soft);
  color:var(--accent);
  border-radius:999px;
  padding:8px;
  font-size:14px;
}
.tb-sub{
  font-size:13px;
  color:var(--muted);
}

/* admin profile */
.admin{
  display:flex;
  align-items:center;
  gap:10px;
  cursor:pointer;
  position:relative;
  font-size:14px;
  color:var(--primary);
  font-weight:500;
  padding:4px 8px;
  border-radius:999px;
  transition:background 0.16s ease;
}
.admin:hover{ background:#f3f4f6; }
.avatar{
  width:34px;
  height:34px;
  border-radius:999px;
  background:linear-gradient(135deg,#bfdbfe,#1d4ed8);
  display:flex;
  align-items:center;
  justify-content:center;
  font-size:14px;
  color:#eff6ff;
  font-weight:700;
  text-transform:uppercase;
  box-shadow:0 10px 20px rgba(37,99,235,0.35);
}
.dropdown{
  position:absolute;
  top:42px;
  right:0;
  min-width:160px;
  background:#ffffff;
  border-radius:14px;
  box-shadow:0 16px 40px rgba(0,0,0,0.12);
  border:1px solid #e5e7eb;
  display:none;
  flex-direction:column;
  overflow:hidden;
  z-index:30;
}
.dropdown a{
  padding:10px 14px;
  font-size:14px;
  color:#111827;
}
.dropdown a:hover{
  background:#f3f4f6;
}

/* ========== TABS ========== */
.tabs{
  display:flex;
  gap:8px;
  flex-wrap:wrap;
  margin-bottom:10px;
}
.tab{
  border:none;
  outline:none;
  background:#ffffff;
  color:#4b5563;
  font-size:13px;
  font-weight:600;
  padding:8px 16px;
  border-radius:999px;
  cursor:pointer;
  box-shadow:0 2px 6px rgba(0,0,0,0.04);
  border:1px solid #e5e7eb;
  display:flex;
  align-items:center;
  gap:6px;
}
.tab i{ font-size:12px; }
.tab.active{
  background:var(--accent-soft);
  color:var(--accent);
  border-color:rgba(59,130,246,0.35);
}

/* ========== STATS CARDS ========== */
.stats{
  display:grid;
  grid-template-columns:repeat(auto-fit,minmax(220px,1fr));
  gap:14px;
  margin-bottom:16px;
}
.stat-box{
  background:#ffffff;
  border-radius:20px;
  padding:14px 18px;
  border:1px solid #e5e7eb;
  box-shadow:0 10px 22px rgba(0,0,0,0.04);
}
.stat-label{
  font-size:12px;
  text-transform:uppercase;
  letter-spacing:0.08em;
  color:var(--muted);
  margin-bottom:6px;
}
.stat-value{
  font-size:24px;
  font-weight:800;
  color:var(--primary);
}

/* ========== TABLE CARD ========== */
.card{
  background:#ffffff;
  border-radius:24px;
  border:1px solid #e5e7eb;
  box-shadow:0 18px 40px rgba(0,0,0,0.08);
  overflow:hidden;
}
.card header{
  padding:14px 18px;
  border-bottom:1px solid #e5e7eb;
  display:flex;
  align-items:center;
  justify-content:space-between;
  font-weight:700;
  color:#111827;
}
.card header .sub{
  font-size:12px;
  color:var(--muted);
  font-weight:400;
}

.table-wrapper{
  overflow-x:auto;
}
table{
  width:100%;
  border-collapse:collapse;
  min-width:720px;
}
thead{
  background:#f9fafb;
}
th,td{
  padding:11px 14px;
  font-size:13px;
  text-align:left;
  border-bottom:1px solid #f1f5f9;
}
th{
  font-size:12px;
  text-transform:uppercase;
  letter-spacing:0.08em;
  color:#6b7280;
  font-weight:700;
}
tbody tr:hover{
  background:#f9fafb;
}
tbody tr:last-child td{
  border-bottom:none;
}

/* status pill */
.status{
  padding:6px 10px;
  border-radius:999px;
  font-size:12px;
  font-weight:700;
  color:#fff;
  display:inline-block;
}
.status.active{ background:var(--green); }
.status.not-active{ background:#9ca3af; }
.status.archived{ background:#4b5563; }

/* buttons */
.btn-action{
  padding:6px 12px;
  font-size:13px;
  border-radius:999px;
  border:none;
  cursor:pointer;
  color:#fff;
  background:var(--accent);
  transition:filter 0.16s ease, transform 0.1s ease;
}
.btn-action:hover{
  filter:brightness(1.05);
  transform:translateY(-1px);
}
.btn-delete{
  background:var(--red);
}

/* ========== RESPONSIVE ========== */
@media (max-width:1080px){
  .sidebar{ display:none; }
  .main{
    margin-left:0;
    padding:18px 16px 30px;
  }
  .topbar{ border-radius:18px; }
}
@media (max-width:640px){
  .card{ border-radius:20px; }
}

/* ========== PRINT ========== */
@media print{
  .sidebar,.topbar{ display:none!important; }
  .main{ margin:0!important; padding:0!important; }
  .card{ box-shadow:none; border:0; }
}
  </style>
</head>
<body>

<!-- ========== SIDEBAR ========== -->
<aside class="sidebar">
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
    <a href="admin-customer-list.php" class="nav-item">
      <i class="fa-solid fa-users"></i>Customer List
    </a>
    <a href="admin-notification.php" class="nav-item">
      <i class="fa-solid fa-bell"></i>Notification
    </a>
    <a href="admin-announcement.php" class="nav-item">
      <i class="fa-solid fa-bullhorn"></i>Announcements
    </a>
    <a href="admin-accommodations.php" class="nav-item">
      <i class="fa-solid fa-bed"></i>Accommodations
    </a>
    <a href="admin-reports.php" class="nav-item">
      <i class="fa-solid fa-chart-column"></i>Reports
    </a>
    <a href="admin-archive.php" class="nav-item active">
      <i class="fa-solid fa-box-archive"></i>Archive
    </a>
    <a href="admin-system-settings.php" class="nav-item">
      <i class="fa-solid fa-gear"></i>System Settings
    </a>
  </nav>
</aside>

<!-- ========== MAIN ========== -->
<main class="main">

  <!-- TOPBAR -->
  <div class="topbar">
    <div class="tb-left">
      <h1>
        <i class="fa-solid fa-users-gear"></i>
        Customer Activity Checker
      </h1>
      <p class="tb-sub">Archive, restore, and audit customer activity.</p>
    </div>

    <div class="admin" onclick="toggleDropdown()">
      <div class="avatar">
        <?php
          $initial = strtoupper(substr(trim($meName), 0, 1));
          echo htmlspecialchars($initial);
        ?>
      </div>
      <span><?= htmlspecialchars($meName) ?> ▾</span>

      <div class="dropdown" id="dropdown">
        <a href="admin-login.php">Logout</a>
      </div>
    </div>
  </div>

  <!-- TABS -->
  <div class="tabs">
    <button class="tab active" data-tab="active">
      <i class="fa-solid fa-circle-dot"></i> Active Customers
    </button>
    <button class="tab" data-tab="archived">
      <i class="fa-solid fa-box-archive"></i> Archived Customers
    </button>
  </div>

  <!-- STATS -->
  <div class="stats">
    <div class="stat-box">
      <div class="stat-label">Total (this tab)</div>
      <div class="stat-value" id="totalCount">0</div>
    </div>
    <div class="stat-box">
      <div class="stat-label">Active (≤ 1 year)</div>
      <div class="stat-value" id="activeCount">—</div>
    </div>
    <div class="stat-box">
      <div class="stat-label">Not Active (> 1 year)</div>
      <div class="stat-value" id="inactiveCount">—</div>
    </div>
  </div>

  <!-- TABLE CARD -->
  <section class="card">
    <header>
      <span>Customer List</span>
      <span class="sub">Auto-classified based on most recent login.</span>
    </header>

    <div class="table-wrapper">
      <table id="customerTable">
        <thead>
          <tr>
            <th>#</th>
            <th>Full Name</th>
            <th>Email</th>
            <th>Recent Login</th>
            <th>Status</th>
            <th>Action</th>
          </tr>
        </thead>
        <tbody id="tableBody">
          <!-- JS fills here -->
        </tbody>
      </table>
    </div>
  </section>

</main>

<!-- LOGOUT MODAL -->
<div class="modal" id="logoutModal" role="dialog" aria-modal="true"
  style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);
    align-items:center;justify-content:center;z-index:50;">

  <div class="sheet"
    style="background:#fff;border:1px solid var(--border);border-radius:12px;
    box-shadow:var(--shadow-soft);padding:16px;max-width:360px;width:90%;">

    <header
      style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">
      <h3>Sign out?</h3>
      <button class="x" onclick="closeLogout()" 
        style="background:transparent;border:none;font-size:20px;cursor:pointer;">
        <i class="fa-solid fa-xmark"></i>
      </button>
    </header>

    <div style="color:#374151;font-size:14px;margin-bottom:10px;">
      You can log back in anytime using your account.
    </div>

    <div class="actions" style="display:flex;justify-content:flex-end;gap:8px;">
      <button class="dd-item" style="background:#e5efff;border-radius:10px;padding:6px 12px;border:none;cursor:pointer;"
        onclick="closeLogout()">Cancel</button>
      <button class="dd-item" style="background:#ef4444;color:#fff;border-radius:10px;padding:6px 12px;border:none;cursor:pointer;"
        onclick="doLogout()">Logout</button>
    </div>

  </div>
</div>

<script>
/* ========== SIDEBAR SUBMENU (open by default) ========== */
const resToggle = document.getElementById('resToggle');
const resMenu   = document.getElementById('resMenu');
const chev      = document.getElementById('chev');

if (resToggle && resMenu && chev) {
  resToggle.addEventListener('click', () => {
    const open = resMenu.style.display === 'flex';
    resMenu.style.display = open ? 'none' : 'flex';
    chev.classList.toggle('open', !open);
  });
  // Default: open reservations menu
  resMenu.style.display = 'flex';
  chev.classList.add('open');
}

/* ========== PROFILE DROPDOWN + LOGOUT ========== */
const profileBtn = document.querySelector('.admin');
const dropdown   = document.getElementById('dropdown');

function toggleDropdown() {
  if (!dropdown) return;
  dropdown.style.display = dropdown.style.display === 'block' ? 'none' : 'block';
}
window.toggleDropdown = toggleDropdown;

document.addEventListener('click', (e) => {
  if (profileBtn && dropdown && !profileBtn.contains(e.target)) {
    dropdown.style.display = 'none';
  }
});

function openLogout(){ document.getElementById('logoutModal').style.display='flex'; }
function closeLogout(){ document.getElementById('logoutModal').style.display='none'; }
function doLogout(){ location.href='admin-login.php'; }

/* ========== ARCHIVE API HELPERS ========== */
const API  = 'archive-api.php';
const CSRF = <?php echo json_encode($CSRF); ?>;

async function apiList(tab='active', page=1, per=200){
  const qs = new URLSearchParams({action:'list', tab, page, per});
  const r = await fetch(`${API}?${qs}`);
  return r.json();
}
async function apiStats(){
  const qs = new URLSearchParams({action:'stats'});
  const r = await fetch(`${API}?${qs}`);
  return r.json();
}
async function apiArchive(id){
  const fd = new FormData();
  fd.append('action','archive');
  fd.append('id',id);
  fd.append('csrf',CSRF);
  const r = await fetch(API,{method:'POST',body:fd});
  return r.json();
}
async function apiRestore(id){
  const fd = new FormData();
  fd.append('action','restore');
  fd.append('id',id);
  fd.append('csrf',CSRF);
  const r = await fetch(API,{method:'POST',body:fd});
  return r.json();
}
async function apiDelete(id){
  const fd = new FormData();
  fd.append('action','delete');
  fd.append('id',id);
  fd.append('csrf',CSRF);
  const r = await fetch(API,{method:'POST',body:fd});
  return r.json();
}
async function apiSeedIfEmpty(){
  const qs = new URLSearchParams({action:'seed_if_empty'});
  const r  = await fetch(`${API}?${qs}`);
  return r.json();
}

/* ========== STATE & HELPERS ========== */
let currentTab = 'active';
let rows = [];

const fmtDate = iso => {
  if(!iso) return '—';
  const d = new Date(iso.replace(' ','T'));
  if (isNaN(d)) return iso;
  return (
    d.toLocaleDateString('en-PH') + ' ' +
    d.toLocaleTimeString([], {hour:'2-digit',minute:'2-digit'})
  );
};

const within1Year = iso => {
  if(!iso) return false;
  const d = new Date(iso.replace(' ','T'));
  if(isNaN(d)) return false;
  const diff = (Date.now() - d.getTime()) / (1000*60*60*24);
  return diff <= 365;
};

function refreshStatsCounters(stat){
  const totalThisTab =
    currentTab === 'active'
      ? (stat.active + stat.inactive)
      : stat.archived;

  document.getElementById('totalCount').textContent = totalThisTab;

  if (currentTab === 'active') {
    document.getElementById('activeCount').textContent   = stat.active;
    document.getElementById('inactiveCount').textContent = stat.inactive;
  } else {
    document.getElementById('activeCount').textContent   = '—';
    document.getElementById('inactiveCount').textContent = '—';
  }
}

function renderTable(){
  const tbody = document.getElementById('tableBody');
  tbody.innerHTML = '';

  if(!rows.length){
    tbody.innerHTML = `
      <tr>
        <td colspan="6" style="text-align:center;color:#6b7280;padding:20px">
          No records found.
        </td>
      </tr>`;
    return;
  }

  rows.forEach((c, i) => {
    let statusClass, statusText;

    if (currentTab === 'active') {
      const ok = within1Year(c.last_login);
      statusClass = ok ? 'active' : 'not-active';
      statusText  = ok ? 'Active' : 'Not Active';
    } else {
      statusClass = 'archived';
      statusText  = 'Archived';
    }

    let actions = '';
    if (currentTab === 'active') {
      actions = `<button class="btn-action" onclick="doArchive(${c.id})">Archive</button>`;
    } else {
      actions = `
        <button class="btn-action" onclick="doRestore(${c.id})">Restore</button>
        <button class="btn-action btn-delete" onclick="doDelete(${c.id})">Delete</button>
      `;
    }

    const tr = document.createElement('tr');
    tr.innerHTML = `
      <td>${i+1}</td>
      <td>${c.full_name}</td>
      <td>${c.email || '—'}</td>
      <td>${fmtDate(c.last_login)}</td>
      <td><span class="status ${statusClass}">${statusText}</span></td>
      <td>${actions}</td>
    `;
    tbody.appendChild(tr);
  });
}

/* ========== MAIN LOAD ========== */
async function loadAll(){
  try {
    await apiSeedIfEmpty();

    const data = await apiList(currentTab);
    rows = (data.rows || []).sort(
      (a,b) => (b.last_login || '') > (a.last_login || '') ? 1 : -1
    );

    const stats = await apiStats();
    refreshStatsCounters(stats);

    renderTable();
  } catch (e) {
    console.error(e);
    document.getElementById('tableBody').innerHTML =
      `<tr><td colspan="6" style="color:#ef4444;padding:16px">
        Failed to load records.
      </td></tr>`;
  }
}

/* ========== ACTIONS ========== */
async function doArchive(id){
  if (!confirm("Archive this customer?")) return;
  const res = await apiArchive(id);
  if (!res.ok) return alert(res.error || "Archive failed");
  loadAll();
}
async function doRestore(id){
  if (!confirm("Restore this customer?")) return;
  const res = await apiRestore(id);
  if (!res.ok) return alert(res.error || "Restore failed");
  loadAll();
}
async function doDelete(id){
  if (!confirm("Delete this archived customer permanently?")) return;
  const res = await apiDelete(id);
  if (!res.ok) return alert(res.error || "Delete failed");
  loadAll();
}
window.doArchive = doArchive;
window.doRestore = doRestore;
window.doDelete  = doDelete;

/* ========== TAB SWITCHING ========== */
document.querySelectorAll('.tab').forEach(tab => {
  tab.addEventListener('click', () => {
    document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
    tab.classList.add('active');
    currentTab = tab.dataset.tab;
    loadAll();
  });
});

/* ========== INIT ========== */
loadAll();
</script>
</body>
</html>
