<?php
// ---- SECURE SESSION ----
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    session_start();
}

require_once "../admin/handlers/db_connect.php";

// ---- ACCESS CONTROL ----
if (empty($_SESSION['user_id']) || !in_array($_SESSION['role'], ['admin', 'staff'])) {
    header("Location: admin-login.php");
    exit;
}

$meName   = $_SESSION['name'] ?? ($_SESSION['first_name'] ?? 'Admin');
$initial  = strtoupper(substr(trim($meName), 0, 1));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>2D Map Editor | Cocovalley Admin</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<!-- FONT AWESOME -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

<style>
/* ===========================
   GLOBAL VARIABLES
=========================== */
:root{
  --primary: #004d99;
  --accent: #0b72d1;
  --accent-soft: rgba(11,114,209,.08);

  --bg: #f7f7f7;
  --bg-soft: #ffffff;

  --border: #e5e7eb;
  --border-soft: #f0f0f0;

  --text: #111827;
  --muted: #6b7280;

  --shadow-soft: 0 14px 30px rgba(0,0,0,0.08);
  --sidebar-w: 260px;

  /* Pin Colors by Type */
  --pin-room:#e63946;
  --pin-cottage:#ffb703;
  --pin-event:#8e44ad;
  --pin-pool:#219ebc;
  --pin-parking:#606c38;
  --pin-grounds:#2a9d8f;
  --pin-other:#adb5bd;
}

/* ===========================
   RESET + BASICS
=========================== */
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

a{
  text-decoration:none;
  color:inherit;
}

/* ===========================
   SIDEBAR  (Airbnb-style white)
=========================== */
.sidebar{
  position:fixed;
  inset:0 auto 0 0;
  width:var(--sidebar-w);
  background:#ffffff;
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
  box-shadow:0 8px 20px rgba(0,0,0,0.2);
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
  transition:background 0.16s ease, transform 0.12s ease;
  cursor:pointer;
}

.nav-item i,
.nav-toggle i.fa-calendar-days,
.nav-toggle i.fa-calendar{
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
  box-shadow:0 10px 22px rgba(11,114,209,0.25);
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
  transition:transform 0.2s ease;
  color:#9ca3af;
}
.chev.open{
  transform:rotate(180deg);
}

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
  box-shadow:0 10px 22px rgba(11,114,209,0.25);
}

/* ===========================
   MAIN
=========================== */
.main{
  margin-left:var(--sidebar-w);
  padding:26px 34px 40px;
  min-height:100vh;
}

/* ===========================
   TOPBAR (same style as list)
=========================== */
.topbar{
  display:flex;
  align-items:center;
  justify-content:space-between;
  background:#ffffff;
  border-radius:999px;
  padding:10px 18px;
  box-shadow:0 12px 30px rgba(0,0,0,0.06);
  border:1px solid rgba(15,23,42,0.04);
  margin-bottom:22px;
}

.topbar h1{
  display:flex;
  align-items:center;
  gap:10px;
  font-size:18px;
  font-weight:700;
  color:var(--primary);
}

.topbar h1 i{
  background:var(--accent-soft);
  color:var(--accent);
  border-radius:999px;
  padding:8px;
  font-size:14px;
}

/* Admin profile */
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

.admin:hover{
  background:#f3f4f6;
}

.avatar{
  width:34px;
  height:34px;
  border-radius:999px;
  background:linear-gradient(135deg, #bfdbfe, #1d4ed8);
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

/* ===========================
   MAP LEGEND (BLUE)
=========================== */
.map-legend{
    background: linear-gradient(135deg, var(--accent), #2563eb);
    color:white;
    padding:12px 16px;
    border-radius:16px;
    margin-bottom:18px;
    display:flex;
    align-items:center;
    gap:18px;
    width:fit-content;
    box-shadow:0 8px 20px rgba(11,114,209,0.28);
}

.map-legend-title{
    font-weight:800;
    font-size:15px;
    margin-right:10px;
}

.legend-item{
    display:flex;
    align-items:center;
    gap:6px;
    font-size:13px;
}

.legend-dot{
    width:14px;
    height:14px;
    border-radius:50%;
    border:2px solid rgba(255,255,255,.9);
}

.legend-room    { background: var(--pin-room); }
.legend-cottage { background: var(--pin-cottage); }
.legend-event   { background: var(--pin-event); }
.legend-pool    { background: var(--pin-pool); }
.legend-parking { background: var(--pin-parking); }
.legend-grounds { background: var(--pin-grounds); }
.legend-other   { background: var(--pin-other); }

/* ===========================
   MAP AREA CARD
=========================== */
.btn-create{
  background:var(--accent);
  color:white;
  padding:9px 16px;
  border:none;
  border-radius:999px;
  cursor:pointer;
  font-weight:600;
  margin-bottom:14px;
  box-shadow:0 8px 16px rgba(11,114,209,0.35);
  font-size:14px;
  display:inline-flex;
  align-items:center;
  gap:6px;
}

.btn-create i{
  font-size:13px;
}

.btn-create:hover{
  background:#095eb5;
}

.save-msg{
  font-size:14px;
  margin-bottom:10px;
  display:none;
  color:#008000;
}

/* Map Card */
.map-box{
  background:var(--bg-soft);
  border:1px solid var(--border);
  padding:20px 22px 24px;
  border-radius:22px;
  box-shadow:0 18px 40px rgba(0,0,0,0.08);
}

/* Map wrapper */
.map2d-wrap{
  position:relative;
  border-radius:18px;
  overflow:hidden;
  background:#eaf4ee;
  box-shadow:0 4px 14px rgba(0,0,0,.08);
  user-select:none;
}

.map2d-wrap object{
    width:100%;
    height:auto;
    aspect-ratio:2.4 / 1;
    object-fit:contain;
    display:block;
}

/* ===========================
   PIN STYLES
=========================== */
.pin-layer{
  position:absolute;
  inset:0;
  z-index:20;
  pointer-events:none;
}

.pin{
  width:20px;
  height:20px;
  border-radius:50%;
  border:3px solid #ffffff;
  position:absolute;
  transform:translate(-50%,-100%);
  pointer-events:auto;
  cursor:pointer;
  box-shadow:0 3px 10px rgba(0,0,0,.25);
  transition:0.15s;
}

.pin:hover{
  transform:translate(-50%,-100%) scale(1.25);
  box-shadow:0 0 12px rgba(255,255,255,.7);
}

/* Pulse */
.pin::after{
  content:"";
  position:absolute;
  left:50%;top:50%;
  width:26px;height:26px;
  border-radius:50%;
  transform:translate(-50%,-50%);
  background:rgba(255,255,255,0.2);
  animation:pulse 1.5s infinite;
}

@keyframes pulse {
  0%{transform:translate(-50%,-50%) scale(.6);opacity:.9}
  100%{transform:translate(-50%,-50%) scale(1.4);opacity:0}
}

/* Colors */
.pin.room{ background:var(--pin-room); }
.pin.cottage{ background:var(--pin-cottage); }
.pin.event{ background:var(--pin-event); }
.pin.pool{ background:var(--pin-pool); }
.pin.parking{ background:var(--pin-parking); }
.pin.grounds{ background:var(--pin-grounds); }
.pin.other{ background:var(--pin-other); }

/* ===========================
   PIN MODAL (Airbnb-ish)
=========================== */
.pin-modal{
  position:fixed;
  inset:0;
  background:rgba(0,0,0,0.45);
  backdrop-filter:blur(6px);
  display:none;
  align-items:center;
  justify-content:center;
  z-index:500;
  padding:20px;
}

.pin-modal.open{
  display:flex;
}

.pin-card{
  width:min(95vw, 980px);
  background:#ffffff;
  border-radius:20px;
  overflow:hidden;
  box-shadow:0 20px 40px rgba(0,0,0,0.18);
  display:grid;
  grid-template-columns:1.6fr 1fr;
  animation:fadeSlide .25s ease-out;
}

@keyframes fadeSlide{
  from{opacity:0;transform:translateY(15px);}
  to{opacity:1;transform:translateY(0);}
}

/* GALLERY */
.pin-gallery{
  position:relative;
  background:#f7f7f7;
  display:flex;
  align-items:center;
  justify-content:center;
}

.pin-gallery img{
  width:100%;
  height:100%;
  max-height:520px;
  object-fit:cover;
  pointer-events:none !important; /* para clickable controls */
}

/* Image dots */
.pg-dots{
  display:flex;
  gap:8px;
  position:absolute;
  bottom:20px;
}

.pg-dot{
  width:10px;
  height:10px;
  border-radius:50%;
  background:rgba(255,255,255,0.6);
  transition:.2s;
  cursor:pointer;
}

.pg-dot.active{
  background:#fff;
  transform:scale(1.2);
}

/* Prev / Next buttons */
.pg-slide-nav{
  position:absolute;
  inset:0;
  display:flex;
  justify-content:space-between;
  align-items:center;
  padding:0 18px;
  z-index:50;
}

.pg-slide-btn{
  pointer-events:auto !important;
  border:none;
  background:rgba(255,255,255,0.85);
  padding:10px 16px;
  border-radius:8px;
  font-size:15px;
  cursor:pointer;
  font-weight:600;
  transition:.2s;
}

.pg-slide-btn:hover{
  background:#fff;
  box-shadow:0 4px 14px rgba(0,0,0,0.2);
}

/* Photo admin controls */
.admin-photo-controls{
  position:absolute;
  right:14px;
  bottom:70px;
  display:flex;
  flex-direction:column;
  gap:10px;
  z-index:9999 !important;
  pointer-events:auto !important;
}

.admin-btn{
  padding:7px 14px;
  border:none;
  border-radius:10px;
  font-size:13px;
  cursor:pointer;
  font-weight:600;
  color:#fff;
  box-shadow:0 3px 8px rgba(0,0,0,.25);
  background:#4b5563;
  transition:.18s;
}

.admin-btn.edit{ background:#0ea65b; }
.admin-btn.remove{ background:#e11d48; }
.admin-btn.save{
  background:#0b72d1;
  margin-top:14px;
  width:100%;
}

.admin-btn:hover{
  transform:translateY(-1px);
  box-shadow:0 6px 16px rgba(0,0,0,.25);
}

/* INFO PANEL */
.pin-info{
  padding:24px 22px 22px;
  display:flex;
  flex-direction:column;
  gap:8px;
}

.pin-info label{
  font-weight:600;
  font-size:14px;
  margin-top:4px;
  color:#111827;
}

.pin-info input,
.pin-info textarea,
.pin-info select{
  width:100%;
  padding:9px 11px;
  border-radius:12px;
  border:1px solid #d1d5db;
  background:#fafafa;
  font-size:14px;
  transition:.15s;
}

.pin-info input:focus,
.pin-info textarea:focus,
.pin-info select:focus{
  border-color:var(--accent);
  background:#fff;
  box-shadow:0 0 0 3px rgba(11,114,209,0.12);
}

.pin-info textarea{
  height:90px;
  resize:none;
}

/* Close button */
.pin-close{
  position:absolute;
  top:18px;
  right:18px;
  width:40px;
  height:40px;
  border-radius:50%;
  font-size:22px;
  color:#333;
  background:white;
  border:none;
  cursor:pointer;
  box-shadow:0 4px 12px rgba(0,0,0,0.15);
  transition:.2s;
  z-index:600;
}

.pin-close:hover{
  transform:scale(1.08);
}

/* ===========================
   RESPONSIVE
=========================== */
@media(max-width:900px){
  .sidebar{display:none;}
  .main{margin-left:0;padding:18px 16px 30px;}
  .topbar{border-radius:18px;}
}

@media(max-width:768px){
  .pin-card{
    grid-template-columns:1fr;
  }
}

@media(max-width:640px){
  .receipt-modal{
    padding:18px 16px 16px;
    border-radius:20px;
  }
}
</style>
</head>
<body>

<!-- ====================== SIDEBAR ====================== -->
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
        <a href="admin-2dmap.php" class="active">2D Map</a>
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
      <i class="fa-solid fa-bullhorn"></i>Announcements</a>
    <a href="admin-accommodations.php" class="nav-item">
      <i class="fa-solid fa-bed"></i>Accommodations
    </a>
    <a href="admin-reports.php" class="nav-item">
      <i class="fa-solid fa-chart-column"></i>Reports
    </a>
    <a href="admin-archive.php" class="nav-item">
      <i class="fa-solid fa-box-archive"></i>Archive
    </a>
    <a href="admin-system-settings.php" class="nav-item">
      <i class="fa-solid fa-gear"></i>System Settings
    </a>

  </nav>
</aside>


<!-- ====================== MAIN CONTENT ====================== -->
<main class="main">

  <!-- TOPBAR -->
  <div class="topbar">
    <h1>
      <i class="fa-solid fa-map-location-dot"></i>
      2D Resort Map Editor
    </h1>

    <div class="admin" onclick="toggleDropdown()">
      <div class="avatar"><?= htmlspecialchars($initial) ?></div>
      <span><?= htmlspecialchars($meName) ?> ▾</span>

      <div class="dropdown" id="dropdown">
        <a href="admin-login.php">Logout</a>
      </div>
    </div>
  </div>

  <!-- LEGEND -->
  <div class="map-legend">
      <div class="map-legend-title">Legend:</div>

      <div class="legend-item"><span class="legend-dot legend-room"></span> Room</div>
      <div class="legend-item"><span class="legend-dot legend-cottage"></span> Cottage</div>
      <div class="legend-item"><span class="legend-dot legend-event"></span> Event Hall</div>
      <div class="legend-item"><span class="legend-dot legend-pool"></span> Pool</div>
      <div class="legend-item"><span class="legend-dot legend-parking"></span> Parking</div>
      <div class="legend-item"><span class="legend-dot legend-grounds"></span> Grounds</div>
      <div class="legend-item"><span class="legend-dot legend-other"></span> Other</div>
  </div>

  <!-- ADD PIN + MAP -->
  <button id="addPinBtn" class="btn-create">
    <i class="fa-solid fa-plus"></i> Add New Pin
  </button>
  <div id="saveMsg" class="save-msg"></div>

  <div class="map-box">
    <div class="map2d-wrap" id="mapWrapper">
      <object id="resortMap" data="2d_map/2d/2d.svg" type="image/svg+xml"></object>
      <div class="pin-layer" id="pinLayer"></div>
    </div>
  </div>

</main>


<!-- ====================== PIN EDITOR MODAL ====================== -->
<div class="pin-modal" id="pinModal">

  <button class="pin-close" id="pinClose">×</button>

  <div class="pin-card">

    <!-- GALLERY -->
    <div class="pin-gallery">
      <img id="pgImg" alt="">
      <div class="pg-dots" id="pgDots"></div>

      <div class="pg-slide-nav">
        <button id="pgPrev" class="pg-slide-btn">‹ Prev</button>
        <button id="pgNext" class="pg-slide-btn">Next ›</button>
      </div>

      <div class="admin-photo-controls">
        <button id="editPhotoBtn" class="admin-btn edit">Upload Photo</button>
        <button id="removePhotoBtn" class="admin-btn remove">Delete Photo</button>
        <input type="file" id="uploadNewPhoto" accept="image/*" hidden>
      </div>
    </div>

    <!-- INFO -->
    <div class="pin-info">

      <input type="hidden" id="pinId">

      <label>Label</label>
      <input id="pinLabel">

      <label>Type</label>
      <select id="pinType">
        <option value="room">Room</option>
        <option value="cottage">Cottage</option>
        <option value="event">Event Hall</option>
        <option value="pool">Pool</option>
        <option value="parking">Parking</option>
        <option value="grounds">Grounds</option>
        <option value="other">Other</option>
      </select>

      <label>Capacity</label>
      <input id="pinCapacity">

      <label>Price</label>
      <input id="pinPrice">

      <label>Description</label>
      <textarea id="pinDesc"></textarea>

      <label style="margin-top:10px;">X Position</label>
      <input id="pinX" type="number" step="0.001">

      <label>Y Position</label>
      <input id="pinY" type="number" step="0.001">

      <label style="margin-top:12px;">
        <input type="checkbox" id="pinNoReserve"> Not Reservable
      </label>

      <button id="saveChangesBtn" class="admin-btn save">Save Pin</button>
      <button id="deletePinBtn" class="admin-btn remove">Delete Pin</button>
    </div>

  </div>
</div>

<script>
// ============================================================
// SIDEBAR + DROPDOWN
// ============================================================
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
    // default open
    resMenu.style.display = "flex";
    chev.classList.add("open");
  }

  window.toggleDropdown = function() {
    const d = document.getElementById("dropdown");
    if (!d) return;
    d.style.display = (d.style.display === "block") ? "none" : "block";
  };

  document.addEventListener("click", (e) => {
    const adminBtn = document.querySelector(".admin");
    const drop = document.getElementById("dropdown");
    if (drop && adminBtn && !adminBtn.contains(e.target)) {
      drop.style.display = "none";
    }
  });
});

// ============================================================
// CONFIG
// ============================================================
const GET_PINS      = "../map/get_pins.php";
const SAVE_PIN      = "../map/save_pin.php";
const DELETE_PIN    = "../map/delete_pin.php";
const UPLOAD_PHOTO  = "../map/upload_photo.php";
const DELETE_PHOTO  = "../map/delete_photo.php";

const IMG_BASE      = "../map/uploads/";

// Elements
const pinLayer      = document.getElementById("pinLayer");
const addPinBtn     = document.getElementById("addPinBtn");
const saveMsg       = document.getElementById("saveMsg");

const pinModal      = document.getElementById("pinModal");
const pinClose      = document.getElementById("pinClose");

const pinId         = document.getElementById("pinId");
const pinLabel      = document.getElementById("pinLabel");
const pinType       = document.getElementById("pinType");
const pinCapacity   = document.getElementById("pinCapacity");
const pinPrice      = document.getElementById("pinPrice");
const pinDesc       = document.getElementById("pinDesc");

const pinX          = document.getElementById("pinX");
const pinY          = document.getElementById("pinY");
const pinNoReserve  = document.getElementById("pinNoReserve");

const saveChangesBtn= document.getElementById("saveChangesBtn");
const deletePinBtn  = document.getElementById("deletePinBtn");

const pgImg         = document.getElementById("pgImg");
const pgDots        = document.getElementById("pgDots");
const pgPrev        = document.getElementById("pgPrev");
const pgNext        = document.getElementById("pgNext");

const uploadNewPhoto= document.getElementById("uploadNewPhoto");
const editPhotoBtn  = document.getElementById("editPhotoBtn");
const removePhotoBtn= document.getElementById("removePhotoBtn");

let currentPhotos = [];
let photoIndex    = 0;

// ============================================================
// FETCH PINS
// ============================================================
async function loadPins(){
    pinLayer.innerHTML = "";

    const res  = await fetch(GET_PINS);
    const pins = await res.json();

    pins.forEach(p => createPinElement(p));
}

loadPins();

// ============================================================
// CREATE PIN ELEMENT
// ============================================================
function createPinElement(p){
    const el = document.createElement("div");
    el.className = `pin ${p.type}`;
    el.dataset.id = p.id;

    el.style.left = (p.x * 100) + "%";
    el.style.top  = (p.y * 100) + "%";

    el.title = p.label;

    el.onclick = () => openPinModal(p);

    pinLayer.appendChild(el);
}

// ============================================================
// OPEN / CLOSE MODAL
// ============================================================
function openPinModal(p){
    pinModal.classList.add("open");

    pinId.value        = p.id;
    pinLabel.value     = p.label;
    pinType.value      = p.type;
    pinCapacity.value  = p.capacity;
    pinPrice.value     = p.price;
    pinDesc.value      = p.desc;

    pinX.value         = p.x;
    pinY.value         = p.y;

    pinNoReserve.checked = !!p.noReserve;

    currentPhotos = p.photos || [];
    photoIndex    = 0;

    updatePhoto();
}

pinClose.onclick = () => pinModal.classList.remove("open");

pinModal.onclick = e => {
    if (e.target === pinModal) pinModal.classList.remove("open");
};

// ============================================================
// GALLERY
// ============================================================
function updatePhoto(){
    if (!currentPhotos.length){
        pgImg.src = "";
        pgDots.innerHTML = "";
        return;
    }

    pgImg.src = IMG_BASE + currentPhotos[photoIndex];

    pgDots.innerHTML = "";
    currentPhotos.forEach((_, i) => {
        const dot = document.createElement("span");
        dot.className = "pg-dot" + (i === photoIndex ? " active" : "");
        dot.onclick = () => { photoIndex = i; updatePhoto(); };
        pgDots.appendChild(dot);
    });
}

pgPrev.onclick = () => {
    if (!currentPhotos.length) return;
    photoIndex = (photoIndex - 1 + currentPhotos.length) % currentPhotos.length;
    updatePhoto();
};

pgNext.onclick = () => {
    if (!currentPhotos.length) return;
    photoIndex = (photoIndex + 1) % currentPhotos.length;
    updatePhoto();
};

// ============================================================
// SAVE PIN
// ============================================================
saveChangesBtn.onclick = async () => {
    const data = new URLSearchParams({
        id       : pinId.value,
        label    : pinLabel.value,
        type     : pinType.value,
        capacity : pinCapacity.value,
        price    : pinPrice.value,
        desc     : pinDesc.value,
        x        : pinX.value,
        y        : pinY.value,
        noReserve: pinNoReserve.checked ? 1 : 0
    });

    await fetch(SAVE_PIN, { method:"POST", body:data });

    showSaveMessage("Pin updated!");
    pinModal.classList.remove("open");
    loadPins();
};

// ============================================================
// DELETE PIN
// ============================================================
deletePinBtn.onclick = async () => {
    if (!confirm("Delete this pin?")) return;

    await fetch(DELETE_PIN, {
        method:"POST",
        body:new URLSearchParams({ id: pinId.value })
    });

    showSaveMessage("Pin deleted!");
    pinModal.classList.remove("open");
    loadPins();
};

// ============================================================
// ADD NEW PIN
// ============================================================
addPinBtn.onclick = async () => {
    const x = 0.5, y = 0.5;

    await fetch(SAVE_PIN, {
        method:"POST",
        body:new URLSearchParams({
            id:0,
            label:"New Pin",
            type:"other",
            capacity:"",
            price:"",
            desc:"",
            x,y,
            noReserve:0
        })
    });

    showSaveMessage("New pin added!");
    loadPins();
};

// ============================================================
// UPLOAD PHOTO
// ============================================================
editPhotoBtn.onclick = () => uploadNewPhoto.click();

uploadNewPhoto.onchange = async () => {
    const file = uploadNewPhoto.files[0];
    if (!file) return;

    const form = new FormData();
    form.append("file", file);
    form.append("id", pinId.value);

    await fetch(UPLOAD_PHOTO, { method:"POST", body:form });

    showSaveMessage("Photo uploaded!");
    loadPins();
};

// ============================================================
// DELETE PHOTO
// ============================================================
removePhotoBtn.onclick = async () => {
    if (!currentPhotos.length) return;
    if (!confirm("Delete this photo?")) return;

    await fetch(DELETE_PHOTO, {
        method:"POST",
        body:new URLSearchParams({
            id   : pinId.value,
            photo: currentPhotos[photoIndex]
        })
    });

    showSaveMessage("Photo deleted!");
    loadPins();
};

// ============================================================
// SAVE MESSAGE
// ============================================================
function showSaveMessage(msg){
    saveMsg.textContent   = msg;
    saveMsg.style.display = "block";
    setTimeout(() => saveMsg.style.display = "none", 1800);
}
</script>
</body>
</html>
