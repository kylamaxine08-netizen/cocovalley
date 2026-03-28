<?php
session_start();
require_once '../admin/handlers/db_connect.php';
if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'customer') {
    header("Location: customer-login.php");
    exit;
}

$customer_id = (int) $_SESSION['user_id'];
define('ALLOW_AUTO_CANCEL', true);
require_once '../admin/handlers/auto_cancel_unpaid.php';
$reservations = [];

$query = "
    SELECT 
        id,
        code,
        customer_id,
        customer_name,
        package,
        type AS category,
        start_date,
        end_date,
        time_slot,
        pax,
        status
    FROM reservations
    WHERE customer_id = ?
      AND status = 'approved'
    ORDER BY start_date ASC
";

$stmt = $conn->prepare($query);
$stmt->bind_param("i", $customer_id);
$stmt->execute();
$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $reservations[] = [
        "id"           => (int)$row['id'],
        "code"         => htmlspecialchars($row['code']),
        "customer_name"=> htmlspecialchars($row['customer_name']),
        "package"      => htmlspecialchars($row['package']),
        "category"     => htmlspecialchars($row['category']),
        "start_date"   => htmlspecialchars($row['start_date']),
        "end_date"     => htmlspecialchars($row['end_date']),
        "time_slot"    => htmlspecialchars($row['time_slot'] ?? ''),
        "pax"          => (int)$row['pax'],
        "status"       => htmlspecialchars($row['status'])
    ];
}


$stmt->close();
?>

<!-- ✅ Embed JSON data directly into JS -->
<script>
window.CUSTOMER_RESERVATIONS = <?php echo json_encode($reservations, JSON_UNESCAPED_UNICODE); ?>;
console.log("✅ CUSTOMER_RESERVATIONS from PHP:", window.CUSTOMER_RESERVATIONS);
</script>


<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <title>Coco Valley • Reservation Calendar</title>
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet"/>
  <link href="https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css" rel="stylesheet"/>


  <style>
    :root {
  --primary:#004d40;
  --secondary:#26a69a;
  --panel:#ffffff;
  --text:#1e293b;
  --muted:#64748b;
  --shadow:0 6px 18px rgba(0,0,0,0.08);

      --sidebar-w: 260px;
      --radius: 14px;

      /* TYPE COLORS */
      --type-cottage:#22c55e;
      --type-room:#ef4444;
      --type-event:#f59e0b;

      --cell-bg:#f7f9fc;
      --cell-border:#eaf0f7;
      --cell-hover:#ecfdf5;
      --cell-hover-border:#cdece0;

      --max-badges: 3;
    }
    @media (max-width: 1200px){ :root{ --max-badges: 2; } }
    @media (max-width: 768px){  :root{ --max-badges: 1; } }

    *{box-sizing:border-box}
    html,body{height:100%}
    body{
      margin:0;
      background:var(--bg);
      color:var(--text);
      font-family:'Segoe UI', system-ui, -apple-system, sans-serif;
      min-height:100vh;
    }

    /* ===== Sidebar (fixed) ===== */
 .sidebar {
  position: fixed;
  inset: 0 auto 0 0;
  width: 250px;
  background: linear-gradient(180deg, #004d40, #00332d);
  color: #fff;
  padding: 1rem;
  display: flex;
  flex-direction: column;
  box-shadow: 4px 0 20px rgba(0, 0, 0, 0.1);
  z-index: 200;
}

.brand {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: .8rem;
  margin-bottom: 1.5rem;
  background: rgba(255, 255, 255, 0.05);
  border-radius: 12px;
}

.brand img {
  width: 44px;
  height: 44px;
  border-radius: 12px;
  object-fit: cover;
}

.brand .name {
  font-weight: 800;
  font-size: 1.1rem;
  color: #fff;
}

.navlink {
  color: #d1fae5;
  text-decoration: none;
  padding: .75rem .9rem;
  border-radius: 10px;
  margin-bottom: .4rem;
  display: flex;
  align-items: center;
  gap: 10px;
  font-weight: 500;
  transition: .25s;
}

.navlink:hover {
  background: rgba(255, 255, 255, 0.15);
  color: #fff;
  transform: translateX(4px);
}

.navlink.active {
  background: linear-gradient(90deg, #26a69a, #004d40);
  color: #fff;
  font-weight: 600;
  border-left: 4px solid #26a69a;
}

.main {
  margin-left: 250px;
  padding: 24px 28px 28px;
}

    .topbar{
      display:flex;
      align-items:center;
      justify-content:space-between;
      padding:10px 14px;
      border-radius:12px;
      background:var(--white);
      border:1px solid var(--border);
      box-shadow:0 6px 16px rgba(0,0,0,.06);
    }
    .topbar h1{
      font-size:22px;
      font-weight:800;
      color:var(--primary);
      letter-spacing:.2px;
      display:flex;
      align-items:center;
      gap:8px;
    }
    .topbar h1 i{
      background:rgba(38,166,154,.12);
      color:var(--accent);
      width:30px;height:30px;
      border-radius:8px;
      display:grid;place-items:center;
    }

    /* ===== Calendar card & header ===== */
    .card{
      background:var(--white);
      border:1px solid var(--border);
      border-radius:16px;
      box-shadow:0 10px 24px rgba(0,0,0,.06);
    }
    .calendar-header{
      display:flex;
      flex-wrap:wrap;
      gap:12px;
      align-items:center;
      justify-content:space-between;
      padding:16px 18px 10px;
    }
    .left-controls{
      display:flex;
      align-items:center;
      gap:8px;
    }
    .btn{
      display:inline-flex;
      align-items:center;
      gap:8px;
      background:#fff;
      border:1px solid #e5e7eb;
      border-radius:10px;
      padding:8px 12px;
      color:#064e3b;
      font-weight:700;
      cursor:pointer;
      font-size:13px;
      transition:box-shadow .18s, transform .06s, border-color .18s, background .15s;
    }
    .btn:hover{
      box-shadow:0 4px 12px rgba(0,0,0,.08);
      border-color:#cbd5f0;
      background:#f9fafb;
    }
    .month-title{
      font-size:18px;
      font-weight:800;
      color:#064e3b;
      padding:0 8px;
    }

    /* Filters + search */
    .tools{
      display:flex;
      gap:10px;
      align-items:center;
      flex-wrap:wrap;
      padding:0 18px 14px;
    }
    .chip{
      border:1px solid #e5e7eb;
      border-radius:999px;
      padding:6px 10px;
      font-size:13px;
      font-weight:800;
      color:#35507a;
      background:#fff;
      cursor:pointer;
      display:inline-flex;
      align-items:center;
      gap:8px;
      transition:box-shadow .18s, border-color .18s, background .15s;
    }
    .chip .dot{width:10px;height:10px;border-radius:50%}
    .chip[data-type="cottage"] .dot{background:var(--type-cottage);}
    .chip[data-type="room"] .dot{background:var(--type-room);}
    .chip[data-type="event"] .dot{background:var(--type-event);}
    .chip.active{
      box-shadow:0 0 0 3px var(--ring);
      border-color:var(--accent);
      background:#ecfdf5;
    }
    .search{
      margin-left:auto;
      min-width:240px;
      padding:9px 12px;
      border:1px solid #d1d5db;
      border-radius:10px;
      background:#fff;
      font-size:14px;
    }
    .search:focus{
      outline:none;
      box-shadow:0 0 0 3px var(--ring);
      border-color:var(--accent);
    }

    /* Calendar grid */
    .calendar{padding:14px 18px 18px}
    .grid{display:grid;grid-template-columns:repeat(7, 1fr);gap:10px}
    .dow{
      font-size:12px;
      font-weight:800;
      text-transform:uppercase;
      letter-spacing:.8px;
      color:var(--muted);
      text-align:center;
      margin-bottom:6px;
    }

    .cell{
      background:var(--cell-bg);
      border:1px solid var(--cell-border);
      border-radius:12px;
      height:120px;
      padding:10px;
      position:relative;
      transition:background .15s, border-color .2s, transform .08s;
      overflow:hidden;
    }
    .cell:hover{
      background:var(--cell-hover);
      border-color:var(--cell-hover-border);
      transform:translateY(-1px);
    }
    .cell.out{opacity:.55}
    .cell.weekend{background:#f5f6fb}
    .cell.today{
      box-shadow:0 0 0 3px var(--ring);
      background:var(--cell-hover);
    }
    .cell .num{
      font-size:13px;
      font-weight:800;
      color:#0f172a;
    }

    .badges{
      margin-top:6px;
      display:flex;
      flex-direction:column;
      gap:6px;
    }
    .badge{
      display:flex;
      align-items:center;
      gap:6px;
      padding:5px 8px;
      border-radius:10px;
      font-size:11px;
      line-height:1.1;
      font-weight:800;
      color:#fff;
      max-width:100%;
      min-width:0;
      white-space:nowrap;
      overflow:hidden;
      text-overflow:ellipsis;
    }
    .badge.cottage{background:var(--type-cottage);}
    .badge.room{background:var(--type-room);}
    .badge.event{background:var(--type-event);}
    .what,.who{
      min-width:0;
      overflow:hidden;
      text-overflow:ellipsis;
      white-space:nowrap;
      flex:0 1 auto;
      max-width:40%;
    }
    .when{
      flex:0 0 auto;
      white-space:nowrap;
      margin-left:auto;
      opacity:.95;
    }
    .sep{opacity:.65}
    .more{
      align-self:flex-start;
      display:inline-block;
      font-size:11px;
      font-weight:800;
      color:#064e3b;
      background:#dcfce7;
      border:1px solid #bbf7d0;
      padding:4px 8px;
      border-radius:999px;
    }
    @media (max-width: 640px){
      .who,.sep{display:none}
      .what{max-width:60%}
    }

    /* Day modal (reservations per day) */
    .modal{
      position:fixed;
      inset:0;
      background:rgba(0,0,0,.45);
      display:none;
      align-items:center;
      justify-content:center;
      padding:16px;
      z-index:50;
    }
    .sheet{
      background:#fff;
      border-radius:16px;
      max-width:820px;
      width:100%;
      box-shadow:0 20px 48px rgba(0,0,0,.20);
      border:1px solid var(--border);
    }
    .sheet header{
      display:flex;
      justify-content:space-between;
      align-items:center;
      padding:16px 18px;
      border-bottom:1px solid #eef2f7;
    }
    .sheet h3{
      font-size:18px;
      font-weight:800;
      color:var(--primary);
    }
    .sheet .x{
      background:transparent;
      border:none;
      font-size:20px;
      cursor:pointer;
      color:#64748b;
    }
    .list{
      padding:8px 18px 18px;
      display:grid;
      gap:10px;
    }
    .item{
      display:grid;
      grid-template-columns:16px 1fr;
      gap:10px;
      align-items:flex-start;
      background:#f0fdf4;
      border:1px solid #bbf7d0;
      border-radius:10px;
      padding:10px 12px;
    }
    .dot{
      width:10px;
      height:10px;
      border-radius:50%;
      margin-top:6px;
    }
    .meta b{color:#064e3b}
    .meta small{color:var(--muted)}
    .footer{
      display:flex;
      justify-content:flex-end;
      gap:10px;
      padding:0 18px 16px;
    }
    .linklike{
      background:#fff;
      border:1px solid #e5e7eb;
      border-radius:10px;
      padding:8px 12px;
      cursor:pointer;
      font-size:13px;
    }
    .primary{
      background:linear-gradient(135deg,var(--primary),var(--accent));
      color:#fff;
      border:none;
      border-radius:10px;
      padding:8px 12px;
      cursor:pointer;
      font-size:13px;
      font-weight:600;
    }
    .primary:hover{filter:brightness(1.05)}

    /* Responsive */
    @media (max-width: 900px){
      .sidebar{display:none}
      .main{padding:16px; margin-left:0}
      .topbar{position:sticky; top:0; z-index:5}
      .search{flex:1;min-width:unset}
    }
  /* 🔴 Bell indicator (global) */
#notifLink i {
  position: relative;
}
#notifLink i::after {
  content: '';
  position: absolute;
  top: -3px;
  right: -3px;
  width: 10px;
  height: 10px;
  background: #e53935;
  border-radius: 50%;
  box-shadow: 0 0 5px rgba(229,57,53,0.7);
  display: none;
}
#notifLink.has-unread i::after {
  display: block;
}
  </style>
</head>
<body>

<?php include 'sidebar-customer.php'; ?>

 <!-- MAIN -->
<main class="main">
  <div class="topbar">
    <h1>
      <i class="fa-solid fa-calendar-days"></i>
      Reservation Calendar
    </h1>
    <span style="font-size:13px;color:var(--muted);">
      View your reservation schedule at a glance
    </span>
  </div>

  <section class="card">
    <!-- Header -->
    <div class="calendar-header">
      <div class="left-controls">
        <button class="btn" id="prevBtn">
          <i class="fa-solid fa-chevron-left"></i> Prev
        </button>
        <button class="btn" id="todayBtn">
          <i class="fa-regular fa-calendar"></i> Today
        </button>
        <button class="btn" id="nextBtn">
          Next <i class="fa-solid fa-chevron-right"></i>
        </button>
      </div>
      <div class="month-title" id="monthYear">—</div>
    </div>

    <!-- Filters + Search -->
    <div class="tools">
      <button class="chip active" data-type="cottage">
        <span class="dot" style="background:#4caf50;"></span> Cottage
      </button>
      <button class="chip active" data-type="room">
        <span class="dot" style="background:#2196f3;"></span> Room
      </button>
      <button class="chip active" data-type="event">
        <span class="dot" style="background:#ff9800;"></span> Event
      </button>
      <input class="search" id="searchInput" type="text"
       placeholder="Search by name or accommodation...">
    </div>

    <!-- Calendar -->
    <div class="calendar">
      <div class="grid" id="dowRow"></div>
      <div class="grid" id="calendarGrid"></div>
    </div>
  </section>
</main>

<!-- DAY MODAL -->
<div class="modal" id="reservationModal" role="dialog" aria-modal="true">
  <div class="sheet">
    <header>
      <h3>Reservations • <span id="modalDate">—</span></h3>
      <button class="x" onclick="closeModal()">
        <i class="fa-solid fa-xmark"></i>
      </button>
    </header>
    <div class="list" id="modalList">
      <!-- Filled dynamically by JS -->
    </div>
    <div class="footer">
      <button class="linklike" onclick="closeModal()">Close</button>
    </div>
  </div>
</div>

<script>
/* ========== DOM References ========== */
const monthYearEl   = document.getElementById('monthYear');
const dowRow        = document.getElementById('dowRow');
const grid          = document.getElementById('calendarGrid');
const searchInput   = document.getElementById('searchInput');
const chips         = Array.from(document.querySelectorAll('.chip'));

/* ========== Day names (header) ========== */
["Sun","Mon","Tue","Wed","Thu","Fri","Sat"].forEach(d => {
  const el = document.createElement('div');
  el.className = 'dow';
  el.textContent = d;
  dowRow.appendChild(el);
});

/* ========== Calendar State ========== */
let RESERVATIONS = [];
const today     = new Date();
let viewMonth   = today.getMonth();
let viewYear    = today.getFullYear();
let activeTypes = new Set(["cottage","room","event"]);
let searchQuery = "";

/* ========== Helpers ========== */
const pad   = n => String(n).padStart(2,'0');
const ymd   = (y,m,d) => `${y}-${pad(m+1)}-${pad(d)}`;
const sOD   = d => new Date(d.getFullYear(),d.getMonth(),d.getDate(),0,0,0,0);
const eOD   = d => new Date(d.getFullYear(),d.getMonth(),d.getDate(),23,59,59,999);
const parseISO = s => s.includes("T") ? new Date(s) : new Date(`${s}T00:00:00`);
const hasTime  = s => s.includes("T");
const typeClass= t => ({cottage:'cottage',room:'room',event:'event'}[t]||'event');

function setMonthTitle(y,m){
  const name = new Date(y, m).toLocaleString('default',{month:'long'});
  monthYearEl.textContent = `${name} ${y}`;
}

function getMonthMatrix(y,m){
  const first       = new Date(y,m,1);
  const firstDay    = first.getDay();
  const daysInMonth = new Date(y,m+1,0).getDate();
  const cells       = [];
  const prevDay     = new Date(y,m,0).getDate();

  for(let i=firstDay-1;i>=0;i--){
    cells.push({day:prevDay-i, out:true, date:new Date(y,m-1,prevDay-i)});
  }
  for(let d=1;d<=daysInMonth;d++){
    cells.push({day:d,out:false,date:new Date(y,m,d)});
  }
  while(cells.length < 42){
    const last = cells[cells.length-1].date;
    const next = new Date(last);
    next.setDate(last.getDate()+1);
    cells.push({day:next.getDate(),out:true,date:next});
  }
  return cells;
}

function overlapsDate(ev, dayDate){
  const start = parseISO(ev.start);
  const end   = parseISO(ev.end);
  return start <= eOD(dayDate) && end >= sOD(dayDate);
}

/* ===== Build segment object shown on calendar ===== */
function segmentForDay(ev, dayDate){
  const evStart = parseISO(ev.start);
  const evEnd   = parseISO(ev.end);

  const sod = sOD(dayDate);
  const eod = eOD(dayDate);

  const segStart = new Date(Math.max(evStart, sod));
  const segEnd   = new Date(Math.min(evEnd, eod));

  const allDayEvent =
     (!hasTime(ev.start) && !hasTime(ev.end)) ||
     (segStart.getTime()===sod.getTime() && segEnd.getTime()===eod.getTime());

  const fHM = d => `${pad(d.getHours())}:${pad(d.getMinutes())}`;
  const timeText = allDayEvent ? "All-day" : `${fHM(segStart)}–${fHM(segEnd)}`;

  return {
    customer : ev.customer,
    type     : ev.type,
    package  : ev.package,
    status   : ev.status,
    code     : ev.code || '',
    timeText,
    fullStart: evStart,
    fullEnd  : evEnd
  };
}

/* ===== Search Filter ===== */
function matchesFilters(seg){
  if(!activeTypes.has(seg.type)) return false;
  const q = searchQuery;
  if(!q) return true;

  const hay = [
    seg.customer || '',
    seg.package  || '',
    seg.type     || '',
    seg.status   || '',
    seg.code     || ''
  ].map(s=>s.toLowerCase());

  return hay.some(s => s.includes(q));
}

/* ===== UI Helpers ===== */
function rootMaxBadges(){
  const v = getComputedStyle(document.documentElement)
    .getPropertyValue('--max-badges').trim();
  const n = parseInt(v,10);
  return Number.isFinite(n) ? n : 3;
}

function fmtDateTime(d){
  return new Intl.DateTimeFormat(undefined,
    {month:'short',day:'2-digit',hour:'2-digit',minute:'2-digit'})
    .format(d);
}
function capitalize(s){ return s ? s.charAt(0).toUpperCase()+s.slice(1) : s; }
function shorten(s,n){ return (s && s.length>n) ? s.slice(0,n-1)+'…' : s; }
function shortType(t){ return {cottage:'Cottage',room:'Room',event:'Event'}[t]||'Event'; }

/* ===== Load from PHP JSON ===== */
async function loadReservations(){
  const data = window.CUSTOMER_RESERVATIONS || [];
  RESERVATIONS = [];

  if(Array.isArray(data) && data.length){
    RESERVATIONS = data.map(ev => ({
      customer : ev.customer_name || "You",
      type     : (ev.category || "event").toLowerCase(),
      package  : ev.package || "",
      status   : ev.status || "—",
      code     : ev.code || "",
      start    : ev.start_date + "T00:00:00",
      end      : ev.end_date ? ev.end_date+"T23:59:59" : ev.start_date+"T23:59:59"
    }));
  }

  render();
}

/* ===== Render Calendar ===== */
function render(){
  setMonthTitle(viewYear, viewMonth);
  grid.innerHTML = "";

  const cells      = getMonthMatrix(viewYear, viewMonth);
  const maxBadges  = rootMaxBadges();

  cells.forEach(c=>{
    const cell = document.createElement('div');
    cell.className = 'cell';
    if(c.out) cell.classList.add('out');
    if([0,6].includes(c.date.getDay())) cell.classList.add('weekend');
    if(c.date.toDateString()===new Date().toDateString()) cell.classList.add('today');

    const num = document.createElement('div');
    num.className = 'num';
    num.textContent = c.day;
    cell.appendChild(num);

    const segments = RESERVATIONS
      .filter(ev=>overlapsDate(ev,c.date))
      .map(ev=>segmentForDay(ev,c.date))
      .filter(matchesFilters);

    if(segments.length){
      const wrap = document.createElement('div');
      wrap.className = 'badges';

      segments.slice(0,maxBadges).forEach(seg=>{
        const b = document.createElement('div');
        b.className = `badge ${typeClass(seg.type)}`;
        b.title     = `${seg.customer} • ${capitalize(seg.type)} — ${seg.package}`;
        b.innerHTML = `
          <span class="what">${shortType(seg.type)} · ${shorten(seg.package,14)}</span>
          <span class="sep">•</span>
          <span class="who">${shorten(seg.customer,18)}</span>
        `;
        wrap.appendChild(b);
      });

      const rem = segments.length - maxBadges;
      if(rem>0){
        const more = document.createElement('span');
        more.className = 'more';
        more.textContent = `+${rem} more`;
        wrap.appendChild(more);
      }

      cell.appendChild(wrap);
      cell.style.cursor = 'pointer';
      cell.addEventListener('click',()=>{
        openModal(ymd(c.date.getFullYear(),c.date.getMonth(),c.date.getDate()),segments);
      });
    }

    grid.appendChild(cell);
  });
}

/* ===== Modal ===== */
function openModal(dateKey, segments){
  document.getElementById('reservationModal').style.display='flex';
  document.getElementById('modalDate').textContent = dateKey;

  const list = document.getElementById('modalList');
  list.innerHTML = "";

  segments.forEach(seg=>{
    const color = getComputedStyle(document.documentElement)
      .getPropertyValue('--type-'+seg.type).trim();

    const row = document.createElement('div');
    row.className = 'item';
    row.innerHTML = `
      <span class="dot" style="background:${color}"></span>
      <div class="meta">
        <b>${seg.customer}</b><br>
        <small>${capitalize(seg.type)} — ${seg.package}</small><br>
        <small>Status: <b>${seg.status}</b></small><br>
        <small>${fmtDateTime(seg.fullStart)}</small>
      </div>
    `;
    list.appendChild(row);
  });
}

function closeModal(){
  document.getElementById('reservationModal').style.display='none';
}
document.getElementById('reservationModal')
  .addEventListener('click',(e)=>{
    if(e.target.id==='reservationModal') closeModal();
});

/* ===== Navigation Buttons ===== */
document.getElementById('prevBtn').addEventListener('click',()=>{
  viewMonth--;
  if(viewMonth<0){viewMonth=11;viewYear--;}
  render();
});
document.getElementById('nextBtn').addEventListener('click',()=>{
  viewMonth++;
  if(viewMonth>11){viewMonth=0;viewYear++;}
  render();
});
document.getElementById('todayBtn').addEventListener('click',()=>{
  viewMonth = today.getMonth();
  viewYear  = today.getFullYear();
  render();
});

/* ===== Keyboard ===== */
document.addEventListener('keydown',(e)=>{
  if(e.key==='ArrowLeft')  document.getElementById('prevBtn').click();
  if(e.key==='ArrowRight') document.getElementById('nextBtn').click();
  if(e.key.toLowerCase()==='t') document.getElementById('todayBtn').click();
});

/* ===== Filters ===== */
chips.forEach(ch=>{
  ch.addEventListener('click',()=>{
    const type = ch.dataset.type;
    if(activeTypes.has(type)){
      activeTypes.delete(type);
      ch.classList.remove('active');
    } else {
      activeTypes.add(type);
      ch.classList.add('active');
    }
    render();
  });
});

/* ===== Search ===== */
searchInput.addEventListener('input',()=>{
  searchQuery = (searchInput.value||'').trim().toLowerCase();
  render();
});

/* ===== Init ===== */
(function init(){
  loadReservations();
})();
</script>
</body>
</html>
