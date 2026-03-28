<?php
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '1');

/* ============================================
   SESSION + AUTH (STAFF ONLY)
============================================ */
session_start();

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'staff') {
    header('Location: admin-login.php');
    exit;
}

$meName = trim($_SESSION['name'] ?? 'Staff');
$meRole = 'staff';

/* ============================================
   HELPER
============================================ */
function e(?string $s): string {
    return htmlspecialchars($s ?: '—', ENT_QUOTES, 'UTF-8');
}

/* ============================================
   DATABASE CONNECTION
============================================ */
$promos  = [];
$current = basename($_SERVER['PHP_SELF']);

require_once __DIR__ . '/handlers/db_connect.php';

if (isset($conn) && $conn instanceof mysqli) {
    $sql = "
        SELECT 
            id,
            title,
            message,
            image_url,
            start_date,
            end_date,
            status,
            audience_type,
            created_by,
            created_at,
            updated_at
        FROM announcements
        ORDER BY created_at DESC
    ";
    if ($result = $conn->query($sql)) {
        while ($row = $result->fetch_assoc()) {
            $row['created_by']    = $row['created_by']    ?? 'Admin';
            $row['status']        = $row['status']        ?? 'active';
            $row['audience_type'] = $row['audience_type'] ?? 'all';
            $promos[] = $row;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Staff • Announcements</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <link rel="stylesheet"
        href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
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

    /* ============ SIDEBAR ============ */
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

    /* ============ MAIN LAYOUT ============ */
    .main {
      margin-left: var(--sidebar-w);
      padding: 26px 34px 40px;
      min-height: 100vh;
    }

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
      gap: 8px;
      flex-wrap: wrap;
    }

    .card header span.sub {
      font-size: 13px;
      color: var(--muted);
      font-weight: 400;
    }

    .btn-primary {
      background: linear-gradient(135deg,#0b72d1,#0a5eb0);
      border: none;
      padding: 8px 14px;
      border-radius: 999px;
      color: #fff;
      font-weight: 600;
      display: inline-flex;
      align-items: center;
      gap: 6px;
      cursor: pointer;
      box-shadow: 0 10px 18px rgba(11,114,209,.25);
      transition: .15s;
      font-size: 13px;
      white-space: nowrap;
    }
    .btn-primary:hover {
      filter: brightness(1.05);
      transform: translateY(-1px);
    }

    .feed-wrap {
      padding: 16px 18px 20px;
    }

    .composer {
      background: #f9fafb;
      border: 1px dashed #d1d5db;
      border-radius: 16px;
      padding: 12px 14px;
      display: flex;
      gap: 10px;
      align-items: center;
      margin-bottom: 14px;
    }

    .composer-avatar {
      width: 40px;
      height: 40px;
      border-radius: 999px;
      background: #e5edff;
      display: grid;
      place-items: center;
      color: #1d4ed8;
      font-weight: 700;
      font-size: 15px;
      flex-shrink: 0;
    }

    .composer-input {
      flex: 1;
      border-radius: 999px;
      border: 1px solid #e5e7eb;
      background: #ffffff;
      padding: 8px 14px;
      font-size: 13px;
      color: #6b7280;
      text-align: left;
      cursor: pointer;
    }

    .feed-list {
      display: flex;
      flex-direction: column;
      gap: 12px;
      margin-top: 4px;
    }

    .ann-card {
      background: #ffffff;
      border-radius: 18px;
      border: 1px solid #e5e7eb;
      padding: 14px 14px 10px;
      display: flex;
      gap: 14px;
      align-items: flex-start;
      transition: all .18s ease;
      cursor: pointer;
    }

    .ann-card:hover {
      transform: translateY(-2px);
      box-shadow: 0 10px 25px rgba(15,23,42,.08);
      border-color: rgba(11,114,209,.4);
    }

    .ann-img {
      width: 200px;
      min-width: 200px;
      height: 140px;
      border-radius: 14px;
      overflow: hidden;
      background: #e5e7eb;
      display: flex;
      align-items: center;
      justify-content: center;
    }

    .ann-img img {
      width: 100%;
      height: 100%;
      object-fit: cover;
      display: block;
    }

    .ann-body {
      flex: 1;
      display: flex;
      flex-direction: column;
      gap: 6px;
    }

    .ann-title-row {
      display: flex;
      flex-wrap: wrap;
      align-items: center;
      gap: 8px;
    }

    .ann-title {
      font-size: 15px;
      font-weight: 700;
      color: #111827;
    }

    .badge {
      font-size: 11px;
      padding: 3px 8px;
      border-radius: 999px;
      background: #e0f2fe;
      color: #0369a1;
      text-transform: uppercase;
      letter-spacing: 0.06em;
      font-weight: 700;
    }

    .badge.aud {
      background: #fef3c7;
      color: #92400e;
    }

    .ann-meta {
      font-size: 12px;
      color: #6b7280;
      display: flex;
      flex-wrap: wrap;
      gap: 10px;
    }

    .ann-text {
      font-size: 13.5px;
      color: #1f2937;
      line-height: 1.55;
      white-space: pre-line;
    }

    .ann-footer {
      display: flex;
      justify-content: space-between;
      align-items: center;
      gap: 8px;
      margin-top: 6px;
      padding-top: 6px;
      border-top: 1px solid #e5e7eb;
      flex-wrap: wrap;
    }

    .posted-by {
      font-size: 12.5px;
      color: #4b5563;
      display: flex;
      align-items: center;
      gap: 6px;
    }

    .posted-by i {
      color: #0b72d1;
    }

    .btn-row {
      display: flex;
      gap: 8px;
      flex-wrap: wrap;
    }

    .mini-btn {
      border-radius: 999px;
      border: none;
      background: #eff6ff;
      color: #0b72d1;
      padding: 5px 10px;
      font-size: 12px;
      display: inline-flex;
      align-items: center;
      gap: 5px;
      cursor: pointer;
      font-weight: 600;
      transition: background .15s ease, transform .1s ease;
    }
    .mini-btn:hover {
      background: #dbeafe;
      transform: translateY(-1px);
    }
    .mini-btn.del {
      background: #fee2e2;
      color: #b91c1c;
    }
    .mini-btn.del:hover {
      background: #fecaca;
    }

    .empty-state {
      text-align: center;
      padding: 30px 10px 16px;
      color: #9ca3af;
      font-size: 14px;
    }

    .empty-state i {
      font-size: 32px;
      margin-bottom: 8px;
      color: #cbd5f5;
    }

    .modal {
      position: fixed;
      inset: 0;
      background: rgba(15,23,42,.35);
      backdrop-filter: blur(5px);
      display: none;
      align-items: center;
      justify-content: center;
      z-index: 200;
      padding: 20px;
    }

    .sheet {
      background:#fff;
      border-radius:16px;
      width:min(520px,100%);
      max-height:90vh;
      overflow-y:auto;
      box-shadow:0 20px 50px rgba(0,0,0,.22);
      border:1px solid #e5e7eb;
      padding:18px 18px 20px;
    }

    .sheet header {
      display:flex;
      justify-content:space-between;
      align-items:center;
      margin-bottom:10px;
    }

    .sheet h3 {
      font-size:18px;
      color:var(--primary);
    }

    .x {
      background:transparent;
      border:none;
      font-size:20px;
      cursor:pointer;
    }

    .sheet label {
      display:block;
      margin-top:8px;
      margin-bottom:3px;
      font-weight:600;
      font-size:13.5px;
    }

    .sheet input,
    .sheet textarea,
    .sheet select {
      width:100%;
      padding:9px 10px;
      border:1px solid #d1d9e6;
      border-radius:10px;
      font-size:14px;
    }

    .sheet textarea {
      min-height:90px;
      resize:vertical;
    }

    .actions {
      display:flex;
      gap:10px;
      justify-content:flex-end;
      margin-top:14px;
    }

    .btn {
      padding:8px 14px;
      border-radius:10px;
      border:1px solid #d1d9e6;
      background:#fff;
      cursor:pointer;
      font-size:13px;
    }

    .btn.primary {
      background:linear-gradient(135deg,#0b72d1,#0a5eb0);
      color:#fff;
      border:none;
    }

    .preview-img {
      max-width:120px;
      margin-top:8px;
      display:none;
      border-radius:10px;
    }

    #viewModal .sheet { width:min(620px,100%); }

    .view-img {
      max-width:100%;
      border-radius:12px;
      margin-bottom:10px;
    }

    .view-meta {
      font-size:13px;
      color:#6b7280;
      margin-bottom:6px;
      display:flex;
      gap:10px;
      flex-wrap:wrap;
    }

    .view-posted {
      font-size:12.5px;
      color:#475569;
      margin-top:6px;
      display:flex;
      gap:6px;
      align-items:center;
    }

    #lightbox { justify-content:center;align-items:center; }
    #lightbox img {
      max-width:92vw;
      max-height:90vh;
      border-radius:14px;
      box-shadow:0 12px 28px rgba(0,0,0,.4);
    }

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
      .ann-card {
        flex-direction: column;
      }
      .ann-img {
        width: 100%;
        min-width: 0;
        height: auto;
      }
    }

    @media (max-width: 640px) {
      .topbar {
        border-radius: 18px;
      }
    }
  </style>
</head>
<body>

<!-- SIDEBAR (STAFF PORTAL, SAME STRUCTURE AS ADMIN) -->
<aside class="sidebar" id="sidebar">
  <div class="sb-head">
    <img src="logo.jpg" class="sb-logo" alt="Cocovalley Logo">
    <div>
      <div class="sb-title">Cocovalley</div>
      <div class="sb-tag">Staff Portal</div>
    </div>
  </div>

  <nav class="nav">
    <a href="staff-dashboard.php" class="nav-item <?= $current==='staff-dashboard.php'?'active':'' ?>">
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
        <a href="staff-calendar.php" class="<?= $current==='staff-calendar.php'?'active':'' ?>">Calendar View</a>
        <a href="staff-reservation-list.php" class="<?= $current==='staff-reservation-list.php'?'active':'' ?>">List View</a>
        <a href="staff-walkin.php" class="<?= $current==='staff-walkin.php'?'active':'' ?>">Walk-in</a>
      </div>
    </div>

    <a href="staff-payment.php" class="nav-item <?= $current==='staff-payment.php'?'active':'' ?>">
      <i class="fa-solid fa-receipt"></i>Payment Proofs
    </a>

    <a href="staff-customer-list.php" class="nav-item <?= $current==='staff-customer-list.php'?'active':'' ?>">
      <i class="fa-solid fa-users"></i>Customer List
    </a>

    <a href="staff-notification.php" class="nav-item <?= $current==='staff-notification.php'?'active':'' ?>">
      <i class="fa-solid fa-bell"></i>Notification</a>

    <a href="staff-announcement.php" class="nav-item active">
      <i class="fa-solid fa-bullhorn"></i>Announcements</a>
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
        <i class="fa-solid fa-bullhorn"></i>
        Announcements
      </h1>
    </div>

    <div class="admin" id="adminBtn">
      <div class="avatar">
        <?php
          $initial = strtoupper(substr($meName, 0, 1));
          echo e($initial);
        ?>
      </div>
      <span><?= e($meName); ?> (Staff) ▾</span>

      <div class="dropdown" id="profileDropdown">
        <a href="#">
          <i class="fa-regular fa-id-badge"></i>
          Profile
        </a>
        <a href="#">
          <i class="fa-solid fa-sliders"></i>
          Preferences
        </a>
        <form method="POST" action="staff-logout.php">
          <button type="submit">
            <i class="fa-solid fa-arrow-right-from-bracket"></i> Logout
          </button>
        </form>
      </div>
    </div>
  </div>

  <!-- ANNOUNCEMENT CARD -->
  <section class="card">
    <header>
      <span>Announcements Feed</span>
      <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
        <span class="sub"><?= count($promos) ?> announcement(s)</span>
        <button class="btn-primary" type="button" onclick="openAddModal()">
          <i class="fa-solid fa-plus"></i> New Announcement
        </button>
      </div>
    </header>

    <div class="feed-wrap">
      <!-- Composer -->
      <div class="composer">
        <div class="composer-avatar">
          <?= e(strtoupper(substr($meName,0,1))); ?>
        </div>
        <button class="composer-input" type="button" onclick="openAddModal()">
          Post a new update for staff/customers…
        </button>
        <button class="btn-primary" type="button" onclick="openAddModal()">
          <i class="fa-solid fa-pen"></i> Create
        </button>
      </div>

      <div class="feed-list">
        <?php if (empty($promos)): ?>
          <div class="empty-state">
            <i class="fa-regular fa-bell-slash"></i>
            <div>No announcements yet. Create one to get started.</div>
          </div>
        <?php else: ?>
          <?php foreach ($promos as $pr):
            $img     = $pr['image_url'] ?? '';
            $title   = $pr['title'] ?? 'No title';
            $msg     = $pr['message'] ?? '';
            $start   = $pr['start_date'] ?? '—';
            $end     = $pr['end_date'] ?? '—';
            $created = $pr['created_at'] ?? '';
            $poster  = $pr['created_by'] ?? 'Admin';
            $aud     = $pr['audience_type'] ?? 'all';
            $status  = $pr['status'] ?? 'active';

            $badgeText = ($status === 'expired') ? 'Expired' : 'Announcement';
            $audBadge  = ucfirst($aud);
          ?>
          <article class="ann-card <?= $status === 'expired' ? 'expired' : '' ?>"
                   data-id="<?= (int)$pr['id'] ?>">
            <?php if ($img): ?>
              <div class="ann-img">
                <img src="<?= e($img) ?>" alt="<?= e($title) ?>">
              </div>
            <?php endif; ?>

            <div class="ann-body">
              <div class="ann-title-row">
                <div class="ann-title"><?= e($title) ?></div>
                <span class="badge"><?= e($badgeText) ?></span>
                <span class="badge aud"><?= e($audBadge) ?></span>
              </div>

              <div class="ann-meta">
                <span>
                  <i class="fa-regular fa-calendar"></i>
                  <?= e($start) ?> → <?= e($end) ?>
                </span>
                <?php if ($created): ?>
                <span>
                  <i class="fa-regular fa-clock"></i>
                  <?= e(date('M d, Y H:i', strtotime($created))) ?>
                </span>
                <?php endif; ?>
              </div>

              <div class="ann-text"><?= nl2br(e($msg)) ?></div>

              <div class="ann-footer">
                <div class="posted-by">
                  <i class="fa-solid fa-user-shield"></i>
                  <?php
                    $displayPoster = $poster;
                    if (is_numeric($displayPoster)) $displayPoster = 'Admin';
                  ?>
                  <span>Posted by <strong><?= e($displayPoster) ?></strong></span>
                </div>

                <div class="btn-row">
                  <button class="mini-btn mini-edit" type="button">
                    <i class="fa-solid fa-pen-to-square"></i> Edit
                  </button>
                  <button class="mini-btn del mini-delete" type="button">
                    <i class="fa-solid fa-trash"></i> Delete
                  </button>
                </div>
              </div>
            </div>
          </article>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>
  </section>
</main>

<!-- ==== ADD MODAL ==== -->
<div class="modal" id="addModal">
  <div class="sheet">
    <header>
      <h3><i class="fa-solid fa-bullhorn"></i> New Announcement</h3>
      <button class="x" onclick="closeModal('addModal')"><i class="fa-solid fa-xmark"></i></button>
    </header>

    <form id="addForm" enctype="multipart/form-data">
      <label>Title</label>
      <input type="text" name="title" required>

      <label>Message / Caption</label>
      <textarea name="message" rows="3" required></textarea>

      <label>Image (optional)</label>
      <input type="file" name="image_url" accept="image/*" onchange="previewImage(event,'addPreview')">
      <img id="addPreview" class="preview-img">

      <label>Start Date</label>
      <input type="date" name="start_date" required>

      <label>End Date</label>
      <input type="date" name="end_date" required>

      <label>Audience</label>
      <select name="audience_type" required>
        <option value="all">All</option>
        <option value="staff">Staff</option>
        <option value="customer">Customer</option>
      </select>

      <!-- mark as staff creator (optional, depende sa promo-handler mo) -->
      <input type="hidden" name="posted_by" value="staff">

      <div class="actions">
        <button type="button" class="btn" onclick="closeModal('addModal')">Cancel</button>
        <button type="submit" class="btn primary">Post</button>
      </div>
    </form>
  </div>
</div>

<!-- ==== EDIT MODAL ==== -->
<div class="modal" id="editModal">
  <div class="sheet">
    <header>
      <h3><i class="fa-solid fa-pen-to-square"></i> Edit Announcement</h3>
      <button class="x" onclick="closeModal('editModal')"><i class="fa-solid fa-xmark"></i></button>
    </header>

    <form id="editForm" enctype="multipart/form-data">
      <input type="hidden" name="id" id="editId">

      <label>Title</label>
      <input type="text" name="title" id="editTitle" required>

      <label>Message / Caption</label>
      <textarea name="message" id="editMessage" rows="3" required></textarea>

      <label>Replace Image (optional)</label>
      <input type="file" name="image_url" accept="image/*" onchange="previewImage(event,'editPreview')">
      <img id="editPreview" class="preview-img">

      <label>Start Date</label>
      <input type="date" name="start_date" id="editStart" required>

      <label>End Date</label>
      <input type="date" name="end_date" id="editEnd" required>

      <label>Audience</label>
      <select name="audience_type" id="editAudience" required>
        <option value="all">All</option>
        <option value="staff">Staff</option>
        <option value="customer">Customer</option>
      </select>

      <input type="hidden" name="posted_by" id="editPostedBy" value="staff">

      <div class="actions">
        <button type="button" class="btn" onclick="closeModal('editModal')">Close</button>
        <button type="submit" class="btn primary">Save changes</button>
      </div>
    </form>
  </div>
</div>

<!-- ==== VIEW MODAL ==== -->
<div class="modal" id="viewModal">
  <div class="sheet">
    <header>
      <h3 id="viewTitle"><i class="fa-solid fa-bullhorn"></i> Announcement</h3>
      <button class="x" onclick="closeModal('viewModal')"><i class="fa-solid fa-xmark"></i></button>
    </header>

    <img id="viewImage" class="view-img" style="display:none;">
    <div class="view-meta" id="viewDates"></div>
    <div id="viewMessage" style="white-space:pre-line;font-size:14.5px;line-height:1.55;margin-bottom:8px;"></div>
    <div class="view-posted" id="viewPosted"></div>
  </div>
</div>

<!-- LIGHTBOX -->
<div class="modal" id="lightbox">
  <img src="" alt="">
</div>

<script>
// PHP → JS
const promos = <?php echo json_encode($promos, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;

// Helpers
function closeModal(id){
  const el = document.getElementById(id);
  if (el) el.style.display = 'none';
}

function openAddModal(){
  const f = document.getElementById('addForm');
  if (f) f.reset();
  const prev = document.getElementById('addPreview');
  if (prev) { prev.style.display = 'none'; prev.src = ''; }
  const modal = document.getElementById('addModal');
  if (modal) modal.style.display = 'flex';
}

function previewImage(e, id){
  const file = e.target.files && e.target.files[0];
  if (!file) return;
  const r = new FileReader();
  r.onload = function(){
    const img = document.getElementById(id);
    if (!img) return;
    img.src = r.result;
    img.style.display = 'block';
  };
  r.readAsDataURL(file);
}

function showLightbox(src){
  const lb  = document.getElementById('lightbox');
  if (!lb) return;
  const img = lb.querySelector('img');
  if (img) img.src = src;
  lb.style.display = 'flex';
}

// View modal
function openViewModal(id){
  const p = promos.find(x => String(x.id) === String(id));
  if (!p) return;

  const titleEl = document.getElementById('viewTitle');
  if (titleEl) {
    titleEl.innerHTML =
      '<i class="fa-solid fa-bullhorn"></i> ' + (p.title || 'Announcement');
  }

  const vImg = document.getElementById('viewImage');
  if (vImg) {
    if (p.image_url) {
      vImg.src = p.image_url;
      vImg.style.display = 'block';
    } else {
      vImg.style.display = 'none';
    }
  }

  const metaParts = [];
  metaParts.push('<i class="fa-regular fa-calendar"></i> ' +
                 (p.start_date || '—') + ' → ' + (p.end_date || '—'));
  if (p.created_at) {
    metaParts.push('<i class="fa-regular fa-clock"></i> ' + p.created_at);
  }
  if (p.status) {
    metaParts.push('<span class="badge">' + p.status + '</span>');
  }
  if (p.audience_type) {
    metaParts.push('<span class="badge" style="background:#0b72d1;color:#fff;">' +
                   p.audience_type + '</span>');
  }

  const datesEl = document.getElementById('viewDates');
  if (datesEl) datesEl.innerHTML = metaParts.join(' · ');

  const msgEl = document.getElementById('viewMessage');
  if (msgEl) msgEl.textContent = p.message || '';

  let who = p.created_by || 'Admin';
  if (!isNaN(who)) who = 'Admin';

  const postedEl = document.getElementById('viewPosted');
  if (postedEl) {
    postedEl.innerHTML =
      '<i class="fa-solid fa-user-shield"></i> Posted by <strong>' +
      who + '</strong>';
  }

  const modal = document.getElementById('viewModal');
  if (modal) modal.style.display = 'flex';
}

// Edit modal
function editPromo(id){
  const p = promos.find(x => String(x.id) === String(id));
  if (!p) return;

  document.getElementById('editId').value       = p.id;
  document.getElementById('editTitle').value    = p.title || '';
  document.getElementById('editMessage').value  = p.message || '';
  document.getElementById('editStart').value    = p.start_date || '';
  document.getElementById('editEnd').value      = p.end_date || '';

  document.getElementById('editAudience').value = p.audience_type || 'all';
  document.getElementById('editPostedBy').value = p.created_by || 'staff';

  const prev = document.getElementById('editPreview');
  if (prev) {
    if (p.image_url) {
      prev.src = p.image_url;
      prev.style.display = 'block';
    } else {
      prev.style.display = 'none';
    }
  }

  const modal = document.getElementById('editModal');
  if (modal) modal.style.display = 'flex';
}

// Delete
async function deletePromo(id){
  if (!confirm('Delete this announcement?')) return;
  const fd = new FormData();
  fd.append('action', 'delete');
  fd.append('id', id);
  const res  = await fetch('handlers/promo-handler.php', { method:'POST', body: fd });
  let data;
  try { data = await res.json(); } catch(e) { alert('Server error.'); return; }
  alert(data.message || 'Deleted');
  if (data.status === 'success') location.reload();
}

// Init bindings
(function init() {
  // Sidebar dropdown
  const resToggle = document.getElementById('resToggle');
  const resMenu   = document.getElementById('resMenu');
  const chev      = document.getElementById('chev');
  if (resToggle && resMenu && chev) {
    resToggle.addEventListener('click', () => {
      const open = resMenu.style.display === 'flex';
      resMenu.style.display = open ? 'none' : 'flex';
      chev.classList.toggle('open', !open);
    });
    resMenu.style.display = 'flex';
    chev.classList.add('open');
  }

  // Mobile sidebar
  const menuBtn       = document.getElementById('menuBtn');
  const sidebar       = document.getElementById('sidebar');
  const mobileOverlay = document.getElementById('mobileOverlay');
  function openSidebar() {
    if (sidebar) sidebar.classList.add('open');
    if (mobileOverlay) mobileOverlay.classList.add('show');
  }
  function closeSidebar() {
    if (sidebar) sidebar.classList.remove('open');
    if (mobileOverlay) mobileOverlay.classList.remove('show');
  }
  if (menuBtn) menuBtn.addEventListener('click', e => { e.stopPropagation(); openSidebar(); });
  if (mobileOverlay) mobileOverlay.addEventListener('click', closeSidebar);
  window.addEventListener('resize', () => { if (window.innerWidth > 900) closeSidebar(); });

  // Profile dropdown
  const adminBtn        = document.getElementById('adminBtn');
  const profileDropdown = document.getElementById('profileDropdown');
  if (adminBtn && profileDropdown) {
    adminBtn.addEventListener('click', e => {
      e.stopPropagation();
      const isOpen = profileDropdown.style.display === 'flex';
      profileDropdown.style.display = isOpen ? 'none' : 'flex';
    });
    document.addEventListener('click', e => {
      if (!adminBtn.contains(e.target) && !profileDropdown.contains(e.target)) {
        profileDropdown.style.display = 'none';
      }
    });
  }

  // Card bindings
  document.querySelectorAll('.ann-card').forEach(card => {
    const id = card.dataset.id;

    card.addEventListener('click', () => {
      openViewModal(id);
    });

    const img = card.querySelector('.ann-img img');
    if (img) {
      img.addEventListener('click', e => {
        e.stopPropagation();
        showLightbox(img.src);
      });
    }

    const editBtn = card.querySelector('.mini-edit');
    if (editBtn) {
      editBtn.addEventListener('click', e => {
        e.stopPropagation();
        editPromo(id);
      });
    }

    const delBtn = card.querySelector('.mini-delete');
    if (delBtn) {
      delBtn.addEventListener('click', e => {
        e.stopPropagation();
        deletePromo(id);
      });
    }
  });

  // ADD (AJAX)
  const addForm = document.getElementById('addForm');
  if (addForm) {
    addForm.addEventListener('submit', async e => {
      e.preventDefault();
      const fd = new FormData(addForm);
      fd.append('action', 'add');
      const res  = await fetch('handlers/promo-handler.php', { method:'POST', body: fd });
      let data;
      try { data = await res.json(); } catch(e) { alert('Server error.'); return; }
      alert(data.message || 'Saved!');
      if (data.status === 'success') location.reload();
    });
  }

  // EDIT (AJAX)
  const editForm = document.getElementById('editForm');
  if (editForm) {
    editForm.addEventListener('submit', async e => {
      e.preventDefault();
      const fd = new FormData(editForm);
      fd.append('action', 'edit');
      const res  = await fetch('handlers/promo-handler.php', { method:'POST', body: fd });
      let data;
      try { data = await res.json(); } catch(e) { alert('Server error.'); return; }
      alert(data.message || 'Updated!');
      if (data.status === 'success') location.reload();
    });
  }
})();
</script>
</body>
</html>
