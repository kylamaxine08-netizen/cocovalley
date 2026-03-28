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
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <title>System Settings | Cocovalley Admin</title>
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

/* ========== SIDEBAR (same structure as Archive) ========== */
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

/* ========== TOPBAR (same vibe as Archive) ========== */
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
.dropdown a,
.dropdown button{
  padding:10px 14px;
  font-size:14px;
  color:#111827;
  background:transparent;
  border:none;
  text-align:left;
  width:100%;
  cursor:pointer;
}
.dropdown a:hover,
.dropdown button:hover{
  background:#f3f4f6;
}

/* ========== CARDS ========== */
.card{
  background:#ffffff;
  border-radius:24px;
  border:1px solid #e5e7eb;
  box-shadow:0 18px 40px rgba(0,0,0,0.08);
  overflow:hidden;
  margin-bottom:18px;
}
.card header{
  padding:14px 18px;
  border-bottom:1px solid #e5e7eb;
  display:flex;
  align-items:flex-start;
  justify-content:space-between;
  gap:10px;
}
.card header .title{
  font-weight:700;
  color:#111827;
  font-size:15px;
}
.card header .sub{
  font-size:12px;
  color:var(--muted);
  font-weight:400;
}
.card-body{
  padding:16px 18px 18px;
}

/* ========== FORM LAYOUT (Add Account) ========== */
.form-grid{
  display:grid;
  gap:14px;
  grid-template-columns:repeat(auto-fit,minmax(240px,1fr));
}
.form-group label{
  display:block;
  font-size:12px;
  text-transform:uppercase;
  letter-spacing:0.08em;
  font-weight:700;
  color:#4b5563;
  margin-bottom:6px;
}
.form-group input,
.form-group select{
  width:100%;
  padding:9px 11px;
  border-radius:10px;
  border:1px solid #d1d5db;
  font-size:14px;
  background:#ffffff;
}
.form-group input:focus,
.form-group select:focus{
  outline:none;
  border-color:var(--accent);
  box-shadow:0 0 0 3px rgba(59,130,246,0.25);
}
.hint{
  font-size:12px;
  color:var(--muted);
  margin-top:4px;
}
.actions{
  display:flex;
  flex-wrap:wrap;
  gap:8px;
  align-items:center;
  margin-top:14px;
}

/* Password controls */
.pw-row{
  display:flex;
  gap:8px;
  align-items:center;
}
.input-eye{
  position:relative;
  flex:1;
}
.input-eye input{
  padding-right:40px;
}
.eye-btn{
  position:absolute;
  right:6px;
  top:50%;
  transform:translateY(-50%);
  border:none;
  background:transparent;
  border-radius:999px;
  padding:4px;
  cursor:pointer;
  color:#6b7280;
}
.eye-btn:hover{
  background:#f3f4f6;
}
.eye-btn svg{
  width:18px;
  height:18px;
}

.pw-meter{
  display:flex;
  gap:4px;
  margin-top:8px;
}
.pw-meter .bar{
  flex:1;
  height:5px;
  border-radius:999px;
  background:#e5e7eb;
}
.pw-meter .bar.on.w1{ background:#ef4444; }
.pw-meter .bar.on.w2,
.pw-meter .bar.on.w3{ background:#f59e0b; }
.pw-meter .bar.on.w4{ background:#22c55e; }

.pw-checks{
  display:flex;
  flex-wrap:wrap;
  gap:8px;
  font-size:11px;
  margin-top:4px;
}
.pw-checks .ok{ color:#16a34a; font-weight:600; }
.pw-checks .bad{ color:#b91c1c; font-weight:600; }

/* buttons */
.btn{
  display:inline-flex;
  align-items:center;
  gap:7px;
  padding:8px 14px;
  border-radius:999px;
  border:1px solid #d1d5db;
  background:#ffffff;
  font-size:13px;
  font-weight:600;
  cursor:pointer;
  transition:background 0.16s ease, transform 0.08s ease, box-shadow 0.16s ease;
}
.btn i{ font-size:13px; }
.btn:hover{
  background:#f3f4f6;
  transform:translateY(-1px);
  box-shadow:0 6px 18px rgba(0,0,0,0.04);
}
.btn.primary{
  background:var(--accent);
  border-color:var(--accent);
  color:#ffffff;
}
.btn.primary:hover{
  filter:brightness(1.05);
}
.btn.ghost{
  background:#ffffff;
}
.btn.xs{
  padding:6px 10px;
  font-size:12px;
  box-shadow:none;
}

/* ========== TABLE AREA ========== */
.toolbar{
  display:flex;
  flex-wrap:wrap;
  gap:8px;
  align-items:center;
}
.toolbar input{
  max-width:260px;
  padding:8px 11px;
  border-radius:999px;
  border:1px solid #d1d5db;
  font-size:13px;
  background:#f9fafb;
}
.toolbar input:focus{
  outline:none;
  border-color:var(--accent);
  box-shadow:0 0 0 2px rgba(59,130,246,0.25);
}
.toolbar select{
  max-width:160px;
  padding:8px 11px;
  border-radius:999px;
  border:1px solid #d1d5db;
  font-size:13px;
  background:#ffffff;
}
.table-wrapper{
  margin-top:12px;
  overflow-x:auto;
}
table{
  width:100%;
  border-collapse:collapse;
  min-width:900px;
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
  font-size:11px;
  text-transform:uppercase;
  letter-spacing:0.08em;
  color:#6b7280;
  font-weight:700;
  cursor:pointer;
  user-select:none;
}
tbody tr:hover{
  background:#f9fafb;
}
tbody tr:last-child td{
  border-bottom:none;
}
.empty{
  text-align:center;
  color:#6b7280;
  padding:24px 12px;
}
.sort-ind{
  margin-left:6px;
  font-size:11px;
  opacity:0.7;
}

/* pills / badges */
.badge{
  display:inline-block;
  padding:5px 10px;
  border-radius:999px;
  font-size:11px;
  font-weight:700;
}
.badge.admin{
  background:#dbeafe;
  color:#1d4ed8;
}
.badge.staff{
  background:#dcfce7;
  color:#15803d;
}
.badge.off{
  background:#fee2e2;
  color:#b91c1c;
}

/* ========== MODALS ========== */
.modal{
  position:fixed;
  inset:0;
  background:rgba(0,0,0,.45);
  display:none;
  align-items:center;
  justify-content:center;
  z-index:50;
}
.modal.show{
  display:flex;
}
.modal-card{
  background:#fff;
  border-radius:18px;
  border:1px solid var(--border);
  box-shadow:var(--shadow-soft);
  padding:16px 18px 18px;
  width:min(720px,94%);
}
.modal-card header{
  display:flex;
  align-items:center;
  justify-content:space-between;
  margin-bottom:8px;
}
.modal-card h3{
  font-size:16px;
  font-weight:700;
  color:#111827;
}
.modal-card .close-btn{
  background:transparent;
  border:none;
  font-size:18px;
  cursor:pointer;
  color:#6b7280;
}
.modal-card .close-btn:hover{
  color:#111827;
}
.modal-row{
  display:grid;
  grid-template-columns:repeat(auto-fit,minmax(220px,1fr));
  gap:14px;
  margin-top:8px;
}
.modal-row .form-group label{
  text-transform:none;
  letter-spacing:normal;
  font-weight:600;
  font-size:13px;
}

/* logout modal reuse simple style */
#logoutModal .sheet{
  background:#fff;
  border-radius:14px;
  border:1px solid var(--border);
  box-shadow:var(--shadow-soft);
  padding:14px 16px 16px;
  max-width:360px;
  width:90%;
}

/* ========== TOAST ========== */
.toast{
  position:fixed;
  right:18px;
  bottom:18px;
  padding:10px 14px;
  border-radius:999px;
  background:#111827;
  color:#ffffff;
  font-size:13px;
  box-shadow:0 14px 30px rgba(0,0,0,0.2);
  opacity:0;
  transform:translateY(8px);
  transition:opacity 0.18s ease, transform 0.18s ease;
  z-index:60;
}
.toast.show{
  opacity:1;
  transform:translateY(0);
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
  .sidebar,.topbar,.btn,.admin,#toast,#logoutModal,#editModal{ display:none!important; }
  .main{ margin:0!important; padding:0!important; }
  .card{ box-shadow:none; border:0; border-radius:0; }
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
    <a href="admin-archive.php" class="nav-item">
      <i class="fa-solid fa-box-archive"></i>Archive
    </a>
    <a href="admin-system-settings.php" class="nav-item active">
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
        User &amp; Staff Management
      </h1>
      <p class="tb-sub">Add, edit, disable or manage system accounts.</p>
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
        <button type="button" onclick="openLogout()">
          <i class="fa-solid fa-arrow-right-from-bracket"></i> Logout
        </button>
      </div>
    </div>
  </div>

  <!-- ADD ACCOUNT CARD -->
  <section class="card">
    <header>
      <div>
        <div class="title">Add New Account</div>
        <div class="sub">Create a staff or admin account with a secure temporary password.</div>
      </div>
    </header>
    <div class="card-body">

      <div class="form-grid">
        <div class="form-group">
          <label for="fullName">Full Name</label>
          <input id="fullName" type="text" placeholder="Juan Dela Cruz">
        </div>

        <div class="form-group">
          <label for="email">Email</label>
          <input id="email" type="email" placeholder="juan@example.com">
        </div>

        <div class="form-group">
          <label for="username">Username</label>
          <input id="username" type="text" placeholder="juandelacruz">
        </div>

        <div class="form-group">
          <label for="phone">Phone (optional)</label>
          <input id="phone" type="tel" placeholder="+63 9xx xxx xxxx">
        </div>

        <div class="form-group">
          <label for="role">Role</label>
          <select id="role">
            <option value="staff">Staff</option>
            <option value="admin">Admin</option>
          </select>
        </div>

        <div class="form-group">
          <label for="tempPass">Temporary Password</label>
          <div class="pw-row">
            <div class="input-eye">
              <input id="tempPass" type="password" placeholder="Click Generate or type manually">
              <button class="eye-btn" id="eyeBtn" type="button" aria-label="Toggle password visibility">
                <svg viewBox="0 0 24 24">
                  <path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7S1 12 1 12Z"/>
                  <circle cx="12" cy="12" r="3"/>
                </svg>
              </button>
            </div>
            <button class="btn ghost xs" id="genPassBtn" type="button">
              <i class="fa-solid fa-wand-magic-sparkles"></i> Generate
            </button>
          </div>

          <div class="pw-meter">
            <div class="bar" id="bar1"></div>
            <div class="bar" id="bar2"></div>
            <div class="bar" id="bar3"></div>
            <div class="bar" id="bar4"></div>
          </div>
          <div class="pw-checks" id="pwChecks"></div>
          <p class="hint">Temporary only — required to change on first login.</p>
        </div>
      </div>

      <div class="actions">
        <button class="btn primary" id="addBtn" type="button">
          <i class="fa-solid fa-user-plus"></i> Add Account
        </button>
        <button class="btn ghost" id="resetFormBtn" type="button">
          <i class="fa-solid fa-rotate"></i> Reset
        </button>
        <button class="btn ghost" id="seedBtn" type="button" title="Insert demo accounts from server">
          <i class="fa-solid fa-seedling"></i> Seed (server)
        </button>
        <span class="hint" id="modeHint">Mode: Server/DB (live)</span>
      </div>

    </div>
  </section>

  <!-- ACCOUNTS TABLE CARD -->
  <section class="card">
    <header>
      <div>
        <div class="title">Accounts</div>
        <div class="sub">All staff and admin accounts with last login and status.</div>
      </div>
      <div class="toolbar">
        <input id="searchBox" type="text" placeholder="Search name, email, username, phone…">
        <select id="roleFilter">
          <option value="">All Roles</option>
          <option value="admin">Admin</option>
          <option value="staff">Staff</option>
        </select>
        <button class="btn ghost" id="exportBtn" type="button">
          <i class="fa-solid fa-file-export"></i> Export CSV
        </button>
      </div>
    </header>

    <div class="card-body">
      <div class="table-wrapper">
        <table id="accountsTable">
          <thead>
            <tr>
              <th data-key="name">Name <span class="sort-ind"></span></th>
              <th data-key="role">Role <span class="sort-ind"></span></th>
              <th data-key="email">Email <span class="sort-ind"></span></th>
              <th data-key="username">Username <span class="sort-ind"></span></th>
              <th data-key="phone">Phone <span class="sort-ind"></span></th>
              <th data-key="previous_login_at">Previous Login <span class="sort-ind"></span></th>
              <th data-key="status">Status <span class="sort-ind"></span></th>
              <th data-key="created_at">Created <span class="sort-ind"></span></th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody id="accountsBody">
            <tr><td colspan="9" class="empty">Loading…</td></tr>
          </tbody>
        </table>
      </div>
    </div>
  </section>

</main>

<!-- LOGOUT MODAL -->
<div class="modal" id="logoutModal" role="dialog" aria-modal="true">
  <div class="sheet" style="background:#fff;border-radius:14px;border:1px solid var(--border);box-shadow:var(--shadow-soft);padding:14px 16px 16px;max-width:360px;width:90%;">
    <header style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">
      <h3 style="font-size:16px;font-weight:700;color:#111827;">Sign out?</h3>
      <button class="x" onclick="closeLogout()" 
        style="background:transparent;border:none;font-size:20px;cursor:pointer;color:#6b7280;">
        <i class="fa-solid fa-xmark"></i>
      </button>
    </header>

    <div style="color:#374151;font-size:14px;margin-bottom:10px;">
      You can log back in anytime using your account.
    </div>

    <div class="actions" style="display:flex;justify-content:flex-end;gap:8px;margin-top:0;">
      <button class="btn ghost" style="border-radius:10px;padding-inline:12px;" onclick="closeLogout()">Cancel</button>
      <button class="btn primary" style="border-radius:10px;padding-inline:12px;" onclick="doLogout()">
        <i class="fa-solid fa-arrow-right-from-bracket"></i> Logout
      </button>
    </div>

  </div>
</div>

<!-- EDIT MODAL -->
<div class="modal" id="editModal" role="dialog" aria-modal="true">
  <div class="modal-card">
    <header>
      <h3>Edit Account</h3>
      <button type="button" class="close-btn" id="closeModalBtn" aria-label="Close">
        <i class="fa-solid fa-xmark"></i>
      </button>
    </header>

    <input type="hidden" id="editId">

    <div class="modal-row">
      <div class="form-group">
        <label for="editFullName">Full Name</label>
        <input id="editFullName" type="text">
      </div>
      <div class="form-group">
        <label for="editEmail">Email</label>
        <input id="editEmail" type="email">
      </div>
      <div class="form-group">
        <label for="editUsername">Username</label>
        <input id="editUsername" type="text">
      </div>
      <div class="form-group">
        <label for="editPhone">Phone</label>
        <input id="editPhone" type="tel">
      </div>
      <div class="form-group">
        <label for="editRole">Role</label>
        <select id="editRole">
          <option value="staff">Staff</option>
          <option value="admin">Admin</option>
        </select>
      </div>
      <div class="form-group">
        <label>Status</label>
        <button class="btn xs" id="editActiveSwitch" data-on="true" type="button">Active</button>
      </div>
    </div>

    <div style="margin-top:14px;">
      <div class="form-group">
        <label for="editPrevLogin">Previous Login</label>
        <input id="editPrevLogin" type="text" disabled>
        <p class="hint">Auto-updated by backend when user logs in.</p>
      </div>
    </div>

    <div class="actions" style="margin-top:16px;justify-content:flex-end;">
      <button class="btn primary" id="saveEditBtn" type="button">
        <i class="fa-solid fa-floppy-disk"></i> Save
      </button>
    </div>
  </div>
</div>

<!-- TOAST -->
<div id="toast" class="toast">Saved!</div>

<script>
// =========================
// GLOBAL HELPERS
// =========================
const API  = 'staff-api.php';
const CSRF = <?php echo json_encode($CSRF); ?>;

const $  = (s) => document.querySelector(s);
const $$ = (s) => Array.from(document.querySelectorAll(s));

const toast = $('#toast');
function showToast(msg='Saved!'){
  toast.textContent = msg;
  toast.classList.add('show');
  setTimeout(()=>toast.classList.remove('show'),1700);
}

// =========================
// SIDEBAR SUBMENU (same as Archive)
// =========================
const resToggle = document.getElementById('resToggle');
const resMenu   = document.getElementById('resMenu');
const chev      = document.getElementById('chev');

if (resToggle && resMenu && chev) {
  resToggle.addEventListener('click', () => {
    const open = resMenu.style.display === 'flex';
    resMenu.style.display = open ? 'none' : 'flex';
    chev.classList.toggle('open', !open);
  });
  // default open
  resMenu.style.display = 'flex';
  chev.classList.add('open');
}

// =========================
// PROFILE DROPDOWN + LOGOUT
// =========================
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

window.openLogout  = openLogout;
window.closeLogout = closeLogout;
window.doLogout    = doLogout;

// =========================
// PASSWORD GENERATOR + METER
// =========================
const CH_UP = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
const CH_LO = 'abcdefghijkmnopqrstuvwxyz';
const CH_DI = '23456789';
const CH_SY = '!@#$%^&*';
const CH_ALL = CH_UP + CH_LO + CH_DI + CH_SY;

function crand(max){
  if(window.crypto && crypto.getRandomValues){
    const a = new Uint32Array(1);
    crypto.getRandomValues(a);
    return a[0] % max;
  }
  return Math.floor(Math.random()*max);
}
function pick(set){ return set[crand(set.length)]; }
function shuffle(arr){
  for(let i=arr.length-1;i>0;i--){
    const j = crand(i+1);
    [arr[i],arr[j]] = [arr[j],arr[i]];
  }
  return arr;
}
function strongTemp(len=12){
  const req=[pick(CH_UP),pick(CH_LO),pick(CH_DI),pick(CH_SY)];
  const rest=[];
  while(req.length+rest.length<len){ rest.push(pick(CH_ALL)); }
  const raw=shuffle(req.concat(rest));
  if(!/[A-Za-z]/.test(raw[0])){
    for(let i=1;i<raw.length;i++){
      if(/[A-Za-z]/.test(raw[i])){ [raw[0],raw[i]]=[raw[i],raw[0]]; break; }
    }
  }
  return raw.join('');
}

function scorePassword(pw){
  let s=0;
  if(pw.length>=12) s++;
  if(/[A-Z]/.test(pw) && /[a-z]/.test(pw)) s++;
  if(/\d/.test(pw)) s++;
  if(/[!@#$%^&*]/.test(pw)) s++;
  return Math.min(s,4);
}
function validateStrong(pw){
  return pw.length>=12 &&
         /[A-Z]/.test(pw) &&
         /[a-z]/.test(pw) &&
         /\d/.test(pw) &&
         /[!@#$%^&*]/.test(pw);
}
function renderPwMeter(pw){
  const s = scorePassword(pw);
  ['#bar1','#bar2','#bar3','#bar4'].forEach((sel,i)=>{
    const el = $(sel);
    el.className='bar';
    if(i<s) el.classList.add('on','w'+s);
  });
  const checks = [
    {ok: pw.length>=12,          label:'12+ chars'},
    {ok: /[A-Z]/.test(pw),       label:'Uppercase'},
    {ok: /[a-z]/.test(pw),       label:'Lowercase'},
    {ok: /\d/.test(pw),          label:'Number'},
    {ok: /[!@#$%^&*]/.test(pw),  label:'Symbol'},
  ];
  $('#pwChecks').innerHTML = checks
    .map(c=>`<span class="${c.ok?'ok':'bad'}">• ${c.label}</span>`)
    .join(' ');
}

// =========================
// FORM ELEMENTS
// =========================
const fullName = $('#fullName');
const email    = $('#email');
const username = $('#username');
const phone    = $('#phone');
const roleSel  = $('#role');
const tempPass = $('#tempPass');
const genPassBtn = $('#genPassBtn');
const eyeBtn   = $('#eyeBtn');

const eyeOnSvg  = '<svg viewBox="0 0 24 24"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7S1 12 1 12Z"/><circle cx="12" cy="12" r="3"/></svg>';
const eyeOffSvg = '<svg viewBox="0 0 24 24"><path d="M3 3l18 18"/><path d="M10.6 10.6a3 3 0 104.2 4.2"/><path d="M9.9 4.2A10.7 10.7 0 0121 12s-4 7-11 7c-2.1 0-4-.5-5.6-1.4"/></svg>';

function updateEyeBtn(){
  const showing = tempPass.type === 'text';
  eyeBtn.innerHTML = showing ? eyeOffSvg : eyeOnSvg;
  eyeBtn.title = showing ? 'Hide password' : 'Show password';
}
eyeBtn.addEventListener('click', ()=>{
  tempPass.type = tempPass.type === 'password' ? 'text' : 'password';
  updateEyeBtn();
});

let tempAutoGen = false;
function maybeGenerateTemp(){
  const hasInput = fullName.value.trim() || email.value.trim() || username.value.trim();
  if(!tempAutoGen && hasInput && tempPass.value.trim()===''){
    tempPass.value = strongTemp();
    tempAutoGen = true;
    renderPwMeter(tempPass.value);
  }
}
genPassBtn.addEventListener('click', ()=>{
  tempPass.value = strongTemp();
  tempAutoGen = true;
  renderPwMeter(tempPass.value);
});
tempPass.addEventListener('input', ()=>renderPwMeter(tempPass.value));

fullName.addEventListener('input', ()=>{
  if(username.dataset.touched !== '1'){
    const base = fullName.value.toLowerCase().trim()
      .replace(/[^a-z0-9 ]/g,'')
      .replace(/\s+/g,'.');
    username.value = base.slice(0,24);
  }
  maybeGenerateTemp();
});
username.addEventListener('input', ()=>{
  username.dataset.touched = '1';
  maybeGenerateTemp();
});
email.addEventListener('input', maybeGenerateTemp);

$('#resetFormBtn').addEventListener('click', ()=>{
  fullName.value=''; email.value=''; username.value=''; username.dataset.touched='';
  phone.value=''; roleSel.value='staff'; tempPass.value='';
  tempAutoGen=false; renderPwMeter('');
  tempPass.type='password'; updateEyeBtn();
});

function isEmail(t){ return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(t); }

// =========================
// API HELPERS
// =========================
async function apiGet(params){
  const url = API + '?' + new URLSearchParams(params).toString();
  const res = await fetch(url,{headers:{'Accept':'application/json'}});
  const j = await res.json();
  if(!j.ok) throw new Error(j.error || 'API error');
  return j;
}
async function apiPost(action, payload){
  const body = new FormData();
  Object.entries(payload).forEach(([k,v])=> body.append(k,v));
  body.append('csrf',CSRF);
  const url = API + '?action=' + encodeURIComponent(action);
  const res = await fetch(url,{method:'POST',body,headers:{'Accept':'application/json'}});
  const j = await res.json();
  if(!j.ok) throw new Error(j.error || 'API error');
  return j;
}

// =========================
// ADD ACCOUNT — SERVER ACTION
// =========================
$('#addBtn').addEventListener('click', async ()=>{
  const data = {
    name: fullName.value.trim(),
    email: email.value.trim(),
    username: username.value.trim(),
    phone: phone.value.trim(),
    role: roleSel.value,
    temp_password: tempPass.value.trim()
  };

  if(!data.name){ showToast('Please enter full name'); return; }
  if(!isEmail(data.email)){ showToast('Invalid email'); return; }
  if(!data.username){ showToast('Username required'); return; }
  if(!data.temp_password || !validateStrong(data.temp_password)){
    showToast('Temporary password is weak. Click Generate.'); 
    return;
  }

  try{
    await apiPost('create', data);
    showToast('Account added');
    $('#resetFormBtn').click();
    await loadAndRender();
  }catch(e){
    console.error(e);
    showToast('Server error while adding account');
  }
});

// Seed demo accounts from server
$('#seedBtn').addEventListener('click', async ()=>{
  try{
    await apiGet({action:'seed_if_empty'});
    await loadAndRender();
    showToast('Seeded demo accounts');
  }catch(e){
    console.error(e);
    showToast('Seed failed');
  }
});

// =========================
// TABLE / LIST RENDER
// =========================
const tbody      = $('#accountsBody');
const searchBox  = $('#searchBox');
const roleFilter = $('#roleFilter');
let sortKey = 'created_at';
let sortDir = 'desc';

function fmtDate(iso){
  if(!iso) return '—';
  const d = new Date(iso);
  if(isNaN(d)) return iso;
  return d.toLocaleDateString('en-PH',{year:'numeric',month:'short',day:'2-digit'});
}
function fmtDateTime(iso){
  if(!iso) return '—';
  const d = new Date(iso);
  if(isNaN(d)) return iso;
  return d.toLocaleString('en-PH',{year:'numeric',month:'short',day:'2-digit',hour:'2-digit',minute:'2-digit'});
}

function updateSortIndicators(){
  $$('#accountsTable thead th[data-key]').forEach(th=>{
    const span = th.querySelector('.sort-ind');
    if(!span) return;
    if(th.dataset.key === sortKey){
      span.textContent = sortDir === 'asc' ? '▲' : '▼';
    }else{
      span.textContent = '';
    }
  });
}

async function loadAndRender(){
  try{
    const params = {
      action:'list',
      q   : (searchBox.value || '').trim(),
      role: (roleFilter.value || ''),
      sort: sortKey,
      dir : sortDir
    };
    const j = await apiGet(params);
    const rows = j.rows || [];

    updateSortIndicators();

    if(!rows.length){
      tbody.innerHTML = `<tr><td colspan="9" class="empty">No accounts found.</td></tr>`;
      return;
    }

    tbody.innerHTML = '';
    rows.forEach(u=>{
      const tr = document.createElement('tr');
      tr.innerHTML = `
        <td><strong>${u.name}</strong></td>
        <td>${u.role==='admin'
              ? '<span class="badge admin">Admin</span>'
              : '<span class="badge staff">Staff</span>'}</td>
        <td>${u.email}</td>
        <td>${u.username}</td>
        <td>${u.phone || '—'}</td>
        <td>${fmtDateTime(u.previous_login_at)}</td>
        <td>${u.status==='active'
              ? '<span class="badge staff">Active</span>'
              : '<span class="badge off">Disabled</span>'}</td>
        <td>${fmtDate(u.created_at)}</td>
        <td>
          <button class="btn ghost xs" data-act="edit" data-id="${u.id}" type="button">
            <i class="fa-regular fa-pen-to-square"></i> Edit
          </button>
        </td>
      `;
      tbody.appendChild(tr);
    });
  }catch(e){
    console.error(e);
    tbody.innerHTML = `<tr><td colspan="9" class="empty" style="color:#b91c1c">
      Failed to load accounts.
    </td></tr>`;
  }
}

// Sorting
$$('#accountsTable thead th[data-key]').forEach(th=>{
  th.addEventListener('click', ()=>{
    const key = th.dataset.key;
    if(sortKey === key){
      sortDir = (sortDir === 'asc' ? 'desc' : 'asc');
    }else{
      sortKey = key;
      sortDir = (key === 'created_at' || key === 'previous_login_at') ? 'desc' : 'asc';
    }
    loadAndRender();
  });
});

// Search / filter
let searchTimer;
if(searchBox){
  searchBox.addEventListener('input', ()=>{
    clearTimeout(searchTimer);
    searchTimer = setTimeout(loadAndRender, 200);
  });
}
roleFilter?.addEventListener('change', loadAndRender);

// =========================
// EDIT MODAL
// =========================
const editModal       = $('#editModal');
const editId          = $('#editId');
const editFullName    = $('#editFullName');
const editEmail       = $('#editEmail');
const editUsername    = $('#editUsername');
const editPhone       = $('#editPhone');
const editRole        = $('#editRole');
const editActiveSwitch= $('#editActiveSwitch');
const editPrevLogin   = $('#editPrevLogin');

function setSwitch(el,on){
  el.dataset.on = on ? 'true':'false';
  el.textContent = on ? 'Active' : 'Disabled';
  el.style.background = on ? '#dcfce7' : '#fee2e2';
  el.style.color = on ? '#15803d' : '#b91c1c';
  el.style.borderColor = on ? '#bbf7d0' : '#fecaca';
}

editActiveSwitch.addEventListener('click', ()=>{
  setSwitch(editActiveSwitch, editActiveSwitch.dataset.on !== 'true');
});

function openEdit(u){
  editId.value        = u.id;
  editFullName.value  = u.name || '';
  editEmail.value     = u.email || '';
  editUsername.value  = u.username || '';
  editPhone.value     = u.phone || '';
  editRole.value      = u.role || 'staff';
  setSwitch(editActiveSwitch, (u.status || 'active') === 'active');
  editPrevLogin.value = fmtDateTime(u.previous_login_at);
  editModal.classList.add('show');
}

$('#closeModalBtn').addEventListener('click', ()=>{
  editModal.classList.remove('show');
});

// Open edit when clicking "Edit" button
tbody.addEventListener('click', async (e)=>{
  const btn = e.target.closest('button[data-act="edit"]');
  if(!btn) return;
  const id = btn.dataset.id;
  try{
    const j = await apiGet({action:'get', id});
    openEdit(j.row);
  }catch(err){
    console.error(err);
    showToast('Failed to fetch account details');
  }
});

// Save edit
$('#saveEditBtn').addEventListener('click', async ()=>{
  const updated = {
    id      : editId.value,
    name    : editFullName.value.trim(),
    email   : editEmail.value.trim(),
    username: editUsername.value.trim(),
    phone   : editPhone.value.trim(),
    role    : editRole.value,
    status  : (editActiveSwitch.dataset.on === 'true' ? 'active' : 'disabled')
  };
  if(!updated.name){ showToast('Name required'); return; }
  if(!isEmail(updated.email)){ showToast('Invalid email'); return; }
  if(!updated.username){ showToast('Username required'); return; }

  try{
    await apiPost('update', updated);
    showToast('Updated');
    editModal.classList.remove('show');
    await loadAndRender();
  }catch(e){
    console.error(e);
    showToast('Update failed');
  }
});

// Close modal if clicking backdrop
editModal.addEventListener('click', (e)=>{
  if(e.target === editModal){
    editModal.classList.remove('show');
  }
});
logoutModal.addEventListener('click', (e)=>{
  if(e.target === logoutModal){
    logoutModal.style.display='none';
  }
});

// =========================
// EXPORT CSV
// =========================
$('#exportBtn').addEventListener('click', ()=>{
  const url = API + '?action=export_csv'
    + '&q='    + encodeURIComponent(searchBox.value || '')
    + '&role=' + encodeURIComponent(roleFilter.value || '');
  window.open(url, '_blank');
});

// =========================
// INIT
// =========================
document.addEventListener('DOMContentLoaded', ()=>{
  renderPwMeter('');
  tempPass.type='password';
  updateEyeBtn();
  setSwitch(editActiveSwitch, true);
  loadAndRender();
});
</script>
</body>
</html>
