<?php
// staff-walkin-receipt.php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/handlers/db_connect.php';

// STAFF / ADMIN ONLY
if (!isset($_SESSION['user_id']) || !in_array($_SESSION['role'] ?? '', ['staff', 'admin'], true)) {
    header("Location: admin-login.php");
    exit;
}

// GET RESERVATION ID
$id = intval($_GET['id'] ?? 0);
if ($id <= 0) {
    header("Location: staff-walkin.php?error=invalid_id");
    exit;
}

// FETCH RESERVATION + POSSIBLE WALK-IN INFO
$sql = "
    SELECT 
        r.*,
        u.phone AS user_phone,
        u.email AS user_email,
        wc.phone AS walkin_phone,
        wc.customer_email AS walkin_email
    FROM reservations r
    LEFT JOIN users u ON u.id = r.customer_id
    LEFT JOIN walkin_customers wc ON wc.reservation_id = r.id
    WHERE r.id = ?
    LIMIT 1
";
$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $id);
$stmt->execute();
$res = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$res) {
    header("Location: staff-walkin.php?error=data_not_found");
    exit;
}

function peso($n) { return "₱" . number_format((float)$n, 2); }

// CONTACT / EMAIL (ONLINE OR WALK-IN)
$contact = $res['user_phone'] ?: ($res['walkin_phone'] ?: '');
$email   = $res['user_email'] ?: ($res['walkin_email'] ?: '');

// STATUS BADGE
$paymentStatus = strtolower($res['payment_status'] ?? '');
switch ($paymentStatus) {
    case 'paid':
        $badge = 'approved';
        $statusLabel = 'PAID';
        break;
    case 'pending':
        $badge = 'pending';
        $statusLabel = 'PENDING';
        break;
    default:
        $badge = 'cancelled';
        $statusLabel = strtoupper($paymentStatus ?: 'UNKNOWN');
        break;
}

// TIME SLOT
$slotText = $res['time_slot'] ?: '—';

// PAYMENT PERCENT LABEL
$percent = (float)($res['payment_percent'] ?? 0);
$percentLabel = ($percent >= 100) ? 'Full Payment' : "{$percent}% Downpayment";

// DATE RESERVED (created_at)
$createdRaw = $res['created_at'] ?? null;
if (!empty($createdRaw) && $createdRaw !== '0000-00-00 00:00:00') {
    $timestamp = strtotime($createdRaw);
    $dateReserved = $timestamp ? date("M d, Y", $timestamp) : "—";
} else {
    $dateReserved = "—";
}

// EMAIL SEND FLAG
$emailSent  = false;
$emailError = "";

// SEND EMAIL ONLY WHEN SEND EMAIL BUTTON CLICKED
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_email'])) {

    if (!$email) {
        $emailError = "No email address available.";
    } else {
        require_once __DIR__ . '/../includes/PHPMailer/src/Exception.php';
        require_once __DIR__ . '/../includes/PHPMailer/src/PHPMailer.php';
        require_once __DIR__ . '/../includes/PHPMailer/src/SMTP.php';

        $mail = new PHPMailer\PHPMailer\PHPMailer(true);

        try {
            $mail->isSMTP();
            $mail->Host       = 'smtp.gmail.com';
            $mail->SMTPAuth   = true;
            $mail->Username   = 'daynielsheerinahh@gmail.com';
            $mail->Password   = 'mmeegsvatkpizwhr'; // app password
            $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            $mail->Port       = 587;

            $mail->setFrom('daynielsheerinahh@gmail.com', 'Cocovalley Richnez Waterpark.');
            $mail->addAddress($email, $res['customer_name'] ?? '');

            $mail->isHTML(true);
            $mail->Subject = 'Cocovalley Walk-in Reservation Receipt';

            $dateStr = $res['start_date'] ? date("M d, Y", strtotime($res['start_date'])) : '—';

            $mail->Body = "
                <div style='font-family:Segoe UI,Arial,sans-serif;font-size:14px;color:#0f172a;'>
                    <h2 style='color:#004d99;margin-bottom:6px;'>Walk-in Reservation Receipt</h2>
                    <p>Hello <strong>{$res['customer_name']}</strong>,</p>
                    <p>Here are your reservation details:</p>
                    <ul>
                        <li><strong>Date Reserved:</strong> {$dateReserved}</li>
                        <li><strong>Reservation Code:</strong> {$res['code']}</li>
                        <li><strong>Category:</strong> {$res['type']}</li>
                        <li><strong>Package:</strong> {$res['package']}</li>
                        <li><strong>Date:</strong> {$dateStr}</li>
                        <li><strong>Time Slot:</strong> {$slotText}</li>
                        <li><strong>Pax:</strong> {$res['pax']}</li>
                        <li><strong>Payment:</strong> {$percentLabel}</li>
                        <li><strong>Total Fee:</strong> " . peso($res['total_price']) . "</li>
                    </ul>
                    <p>Thank you for choosing Cocovalley Richnez Waterpark!</p>
                </div>
            ";

            $mail->send();
            $emailSent = true;

        } catch (Exception $e) {
            $emailError = "Sending failed: " . $mail->ErrorInfo;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Walk-in Receipt | Cocovalley</title>

<style>
:root {
  --primary:#004d99;
  --accent:#0b72d1;
  --success:#16a34a;
  --pending:#f59e0b;
  --danger:#dc2626;
  --border:#e2e8f0;
  --bg:#f3f6fa;
  --white:#ffffff;
  --gray:#64748b;
}

body{
  margin:0;
  padding:40px 12px;
  background:var(--bg);
  font-family:"Inter","Segoe UI",sans-serif;
  display:flex;
  justify-content:center;
}

.card{
  width:100%;
  max-width:620px;
  background:var(--white);
  padding:32px 36px;
  border-radius:16px;
  box-shadow:0 6px 22px rgba(0,0,0,.08);
}

.header{
  text-align:center;
  padding-bottom:14px;
  border-bottom:1px solid var(--border);
  margin-bottom:22px;
  position:relative;
}
.header img{
  width:80px;
  height:80px;
  object-fit:cover;
  border-radius:12px;
}

.status{
  position:absolute;
  top:20px;right:20px;
  padding:6px 12px;
  border-radius:999px;
  color:#fff;
  font-size:.8rem;
  font-weight:700;
}
.status.approved{background:var(--success);}
.status.pending{background:var(--pending);}
.status.cancelled{background:var(--danger);}

.row{
  display:flex;
  justify-content:space-between;
  padding:7px 0;
  border-bottom:1px dashed var(--border);
  font-size:.95rem;
}

.total{
  margin-top:20px;
  padding-top:14px;
  border-top:2px solid var(--border);
  text-align:right;
}
.amount{
  font-size:1.7rem;
  font-weight:800;
  color:var(--primary);
}

.actions{
  text-align:center;
  margin-top:24px;
}
button{
  padding:10px 18px;
  border:none;
  border-radius:8px;
  font-weight:600;
  cursor:pointer;
}
.print-btn{background:var(--accent);color:#fff;}
.email-btn{background:var(--success);color:#fff;}

/* SIMPLE ALERT TOP (FOR ERRORS) */
.notice{
  max-width:620px;
  margin:0 auto 14px auto;
  padding:10px 14px;
  border-radius:8px;
  font-size:.9rem;
}
.notice.err{background:#fee2e2;color:#991b1b;border:1px solid #fecaca;}

/* ENHANCED SUCCESS POPUP (OPTION 1: ONLY AFTER SEND EMAIL SUCCESS) */
#successPopup {
  position: fixed;
  top: 50%;
  left: 50%;
  transform: translate(-50%, -50%) scale(0.6);
  background: rgba(255,255,255,0.98);
  padding: 40px 55px;
  border-radius: 20px;
  box-shadow: 0 15px 40px rgba(0,0,0,0.25);
  text-align: center;
  z-index: 9999;
  opacity: 0;
  pointer-events: none;
  display: none;
}

.popup-inner {
  animation: floatUp 1.2s ease-in-out infinite alternate;
}

.check-circle img {
  width: 96px;
  filter: drop-shadow(0 0 8px rgba(16,185,129,0.7));
  animation: scalePulse 1.3s ease-in-out infinite;
}

.popup-text {
  font-size: 1.3rem;
  font-weight: 800;
  color: #16a34a;
  margin-top: 10px;
}

/* POPUP FADE + SCALE ANIMATION */
@keyframes popupShow {
  0% { opacity: 0; transform: translate(-50%, -50%) scale(0.6); }
  70% { opacity: 1; transform: translate(-50%, -50%) scale(1.05); }
  100% { opacity: 1; transform: translate(-50%, -50%) scale(1); }
}

/* FLOATING UP-DOWN EFFECT */
@keyframes floatUp {
  0%   { transform: translateY(0px); }
  100% { transform: translateY(-8px); }
}

/* CHECK ICON PULSE */
@keyframes scalePulse {
  0%   { transform: scale(1); }
  100% { transform: scale(1.1); }
}

/* Toast message bottom */
#toast{
  position:fixed;
  bottom:25px;
  left:50%;
  transform:translateX(-50%);
  background:#dcfce7;
  color:#166534;
  padding:10px 18px;
  border-radius:8px;
  border:1px solid #86efac;
  font-size:.9rem;
  font-weight:700;
  display:none;
  z-index:9999;
}

/* FADE OUT ANIMATION */
.fade-out-popup{
  animation: fadeOutPopup 0.7s forwards;
}
@keyframes fadeOutPopup {
  from { opacity: 1; transform: translate(-50%, -50%) scale(1); }
  to   { opacity: 0; transform: translate(-50%, -50%) scale(0.8); }
}

.fade-out-toast{
  animation: fadeOutToast 0.7s forwards;
}
@keyframes fadeOutToast {
  from { opacity: 1; }
  to   { opacity: 0; transform: translateX(-50%) translateY(10px); }
}

/* PRINT MODE */
@media print {
  .actions, #toast, #successPopup, .notice {display:none;}
  body {background:#ffffff;}
  .card {box-shadow:none;border-radius:0;}
}
</style>
</head>
<body>

<?php if ($emailError): ?>
<div class="notice err">
  <?= htmlspecialchars($emailError, ENT_QUOTES, 'UTF-8'); ?>
</div>
<?php endif; ?>

<!-- SUCCESS POPUP (Option 1: only after Send Email success) -->
<div id="successPopup">
  <div class="popup-inner">
    <div class="check-circle">
      <img src="https://img.icons8.com/color/96/ok--v1.png" alt="ok">
    </div>
    <div class="popup-text">
      Email sent successfully!
    </div>
  </div>
</div>

<!-- TOAST (bottom) -->
<div id="toast">Email sent to <?= htmlspecialchars($email ?: 'customer', ENT_QUOTES, 'UTF-8'); ?></div>

<div class="card">

  <div class="header">
    <img src="logo.jpg" alt="Logo">
    <h2>Cocovalley Richnez Waterpark</h2>
    <small>315 Paliparan 3, Dasmariñas, Cavite</small><br>
    <small>📞 0921 937 0908</small>

    <div class="status <?= $badge ?>"><?= $statusLabel ?></div>
  </div>

  <div class="row"><strong>Date Reserved:</strong><span><?= $dateReserved ?></span></div>
  <div class="row"><strong>Reservation Code:</strong><span><?= $res['code'] ?></span></div>
  <div class="row"><strong>Category:</strong><span><?= $res['type'] ?></span></div>
  <div class="row"><strong>Package:</strong><span><?= $res['package'] ?></span></div>
  <div class="row">
    <strong>Date:</strong>
    <span><?= $res['start_date'] ? date("M d, Y", strtotime($res['start_date'])) : '—' ?></span>
  </div>
  <div class="row"><strong>Time Slot:</strong><span><?= $slotText ?></span></div>
  <div class="row"><strong>Pax:</strong><span><?= $res['pax'] ?></span></div>
  <div class="row"><strong>Customer:</strong><span><?= $res['customer_name'] ?></span></div>
  <div class="row"><strong>Contact:</strong><span><?= $contact ?: '—' ?></span></div>
  <div class="row"><strong>Email:</strong><span><?= $email ?: '—' ?></span></div>
  <div class="row"><strong>Payment Type:</strong><span><?= $percentLabel ?></span></div>
  <div class="row"><strong>Status:</strong><span><?= $statusLabel ?></span></div>

  <div class="total">
    <b>Total Fee</b>
    <div class="amount"><?= peso($res['total_price']) ?></div>
  </div>

  <div class="actions">
    <button type="button" class="print-btn" onclick="window.print()">🖨 Print</button>

    <form method="POST" style="display:inline;">
      <input type="hidden" name="send_email" value="1">
      <button type="submit" class="email-btn">📧 Send Email</button>
    </form>
  </div>

</div>

<script>
<?php if ($emailSent): ?>
// Only trigger when email was successfully sent (Option 1)
const popup = document.getElementById('successPopup');
const toast = document.getElementById('toast');

// Show popup + toast
popup.style.display = 'block';
popup.style.pointerEvents = 'auto';
popup.style.animation = 'popupShow 0.6s ease-out forwards';

toast.style.display = 'block';

// Fade out popup after 1.7s
setTimeout(() => {
    popup.classList.add('fade-out-popup');
}, 1700);

// Fade out toast after 2s
setTimeout(() => {
    toast.classList.add('fade-out-toast');
}, 2000);

// Redirect after 2.5s
setTimeout(() => {
    window.location.href = 'staff-dashboard.php';
}, 2500);
<?php endif; ?>
</script>

</body>
</html>
