<?php
/******************** CUSTOMER SIGNUP WITH FULL FIELDS ********************/
declare(strict_types=1);
session_start();

/* DB Config */
$DB_HOST = '127.0.0.1';
$DB_NAME = 'cocovalley_admin';
$DB_USER = 'root';
$DB_PASS = '';

/* JSON Response Helper */
function respond_json(bool $ok, string $msg, array $extra = []): void {
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode(array_merge(['ok'=>$ok,'message'=>$msg], $extra));
  exit;
}

/* Process Signup */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

  /* Connect DB */
  try {
    $pdo = new PDO(
      "mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4",
      $DB_USER, $DB_PASS,
      [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
      ]
    );
  } catch (Throwable $e) {
    respond_json(false, "Database connection failed.");
  }

  /* Collect inputs */
  $email      = strtolower(trim($_POST['email'] ?? ''));
  $firstName  = trim($_POST['firstName'] ?? '');
  $lastName   = trim($_POST['lastName'] ?? '');
  $password   = $_POST['password'] ?? '';
  $confirm    = $_POST['confirmPassword'] ?? '';
  $phone      = preg_replace('/\D+/', '', $_POST['phone'] ?? '');
  $gender     = trim($_POST['gender'] ?? '');
  $birthdate  = trim($_POST['birthdate'] ?? '');

  /* Validation */
  if (!filter_var($email, FILTER_VALIDATE_EMAIL))
    respond_json(false, "Please enter a valid email.");

  if ($firstName === '' || $lastName === '')
    respond_json(false, "Please complete your name.");

  if (strlen($phone) < 10)
    respond_json(false, "Please enter a valid phone number.");

  if (!in_array($gender, ['Male','Female','Prefer not to say'], true))
    respond_json(false, "Invalid gender selected.");

  if (!$birthdate)
    respond_json(false, "Please select your birthdate.");

  if (strlen($password) < 8 || !preg_match('/[A-Z]/', $password) || !preg_match('/\d/', $password))
    respond_json(false, "Password must have at least 8 chars, 1 uppercase letter, and 1 number.");

  if ($password !== $confirm)
    respond_json(false, "Passwords do not match.");

  /* Check duplicate email */
  $stmt = $pdo->prepare("SELECT id FROM users WHERE email = :email LIMIT 1");
  $stmt->execute([':email' => $email]);
  if ($stmt->fetch()) respond_json(false, "That email is already registered.");

  /* Insert NEW CUSTOMER */
  try {
    $hash = password_hash($password, PASSWORD_DEFAULT);

    $ins = $pdo->prepare("
      INSERT INTO users 
      (email, first_name, last_name, birthdate, gender, phone, password_hash, role, status)
      VALUES 
      (:email, :fn, :ln, :bd, :gd, :ph, :pw, 'customer', 1)
    ");

    $ins->execute([
      ':email' => $email,
      ':fn'    => $firstName,
      ':ln'    => $lastName,
      ':bd'    => $birthdate,
      ':gd'    => $gender,
      ':ph'    => $phone,
      ':pw'    => $hash
    ]);

    // Clear old session
    session_unset();
    session_destroy();

    /* ===========================================================
         SEND EMAIL CONFIRMATION TO CUSTOMER (NO PASSWORD)
    =========================================================== */
    require __DIR__ . '/../includes/PHPMailer/src/PHPMailer.php';
    require __DIR__ . '/../includes/PHPMailer/src/SMTP.php';
    require __DIR__ . '/../includes/PHPMailer/src/Exception.php';

    $mail = new PHPMailer\PHPMailer\PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host       = "smtp.gmail.com";
        $mail->SMTPAuth   = true;
        $mail->Username   = "daynielsheerinahh@gmail.com";
        $mail->Password   = "mmeegsvatkpizwhr";
        $mail->SMTPSecure = "tls";
        $mail->Port       = 587;

        $mail->setFrom("daynielsheerinahh@gmail.com", "Coco Valley Richnez Waterpark");
        $mail->addAddress($email, $firstName . " " . $lastName);

        $mail->isHTML(true);
        $mail->Subject = "Your Coco Valley Account Registration is Successful";

        // UPDATED EMAIL BODY – NO PASSWORD, SIMPLE CONFIRMATION
        $mail->Body = "
            <div style='font-family:Arial, sans-serif; background:#f5f5f5; padding:20px;'>
              <div style='max-width:600px; margin:auto; background:#ffffff; border-radius:10px; overflow:hidden; box-shadow:0 4px 15px rgba(0,0,0,0.1);'>
                <div style='background:#004d40; color:#ffffff; padding:20px; text-align:center;'>
                    <h2 style='margin:0;'>Welcome to Coco Valley!</h2>
                </div>
                <div style='padding:20px; color:#333333;'>
                    <p>Hello <strong>$firstName</strong>,</p>
                    <p>Your customer account for <strong>Coco Valley Richnez Waterpark</strong> has been successfully created.</p>
                    <p>You are already registered, you can now login using your email address and the password you set on our website.</p>
                    <br>
                    <p>If you did not request this account, you may safely ignore this email.</p>
                    <br>
                    <p>Thank you,<br><strong>Coco Valley Richnez Waterpark</strong></p>
                </div>
                <div style='background:#e0f2f1; color:#004d40; padding:12px; text-align:center; font-size:14px;'>
                    This is an automated message. Please do not reply.
                </div>
              </div>
            </div>
        ";

        $mail->send();

    } catch (Exception $e) {
        // Email failed silently (signup will still succeed)
    }

    // SUCCESS RESPONSE TO FRONTEND (REDIRECT WITH FLAG)
    respond_json(true, "You are already registered, you can now login.", [
      "redirect" => "customer-login.php?registered=1"
    ]);

  } catch (Throwable $ex) {
    respond_json(false, "Registration failed. Please try again.");
  }
}
?>


<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Sign Up • Coco Valley</title>
  <link href="https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css" rel="stylesheet">
  <style>
    :root{
      --primary:#2e7d32;
      --primary-dark:#1b5e20;
      --accent:#00b894;
      --white:#ffffff;
      --glass: rgba(255,255,255,0.86);
      --glass-border: rgba(0,0,0,0.06);
      --error:#e53935;
      --warning:#f59e0b;
      --success:#1b5e20;
      --text:#142329;
      --muted:#667085;
      --shadow-lg: 0 18px 50px rgba(0,0,0,.25);
      --radius: 18px;
    }
    *{box-sizing:border-box;margin:0;padding:0;font-family:'Segoe UI',system-ui,-apple-system,Arial,sans-serif}
    html,body{height:100%;overflow:hidden}
    body{
      color:var(--text);
      background:
        linear-gradient(120deg, rgba(46,125,50,.22), rgba(0,184,148,.18)),
        url('bg.jpg') center/cover no-repeat fixed;
      -webkit-font-smoothing:antialiased; -moz-osx-font-smoothing:grayscale;
    }
    body::before{
      content:""; position:fixed; inset:0; z-index:0;
      background: rgba(0,0,0,.35); backdrop-filter: blur(2px);
    }

    .modal{position:fixed; inset:0; z-index:1; display:grid; place-items:center; padding:24px}
    .card{
      width:460px; max-width:92vw;
      max-height:min(88vh, 720px);
      display:flex; flex-direction:column; overflow:hidden;
      background:var(--glass); border:1px solid var(--glass-border);
      backdrop-filter: blur(14px); border-radius:var(--radius); box-shadow:var(--shadow-lg);
      animation:pop .35s ease both;
    }
    @keyframes pop{from{opacity:0;transform:scale(.985)}to{opacity:1;transform:none}}

    .progress{height:3px; background:#e9eef2; position:relative}
    .progress > span{position:absolute; inset:0 0 0 0; width:0%; background:linear-gradient(90deg,var(--primary),var(--primary-dark)); transition:width .25s}

    .header{
      display:flex; align-items:center; justify-content:center;
      padding:14px 16px; border-bottom:1px solid #e6e9ec;
      background:linear-gradient(180deg,#ffffffb5,#ffffff90);
      position:sticky; top:0; z-index:2; backdrop-filter:blur(8px);
    }
    .title{font-size:18px;font-weight:800;color:#0f172a}

    .content{padding:16px; overflow:auto; scrollbar-width:thin}
    .logo{width:72px;height:72px;border-radius:50%;object-fit:cover;display:block;margin:6px auto 10px;box-shadow:0 4px 14px rgba(0,0,0,.18)}
    .sub{color:var(--muted);text-align:center;margin-bottom:16px}

    .alert{display:none;padding:10px 12px;border-radius:12px;font-size:14px;margin-bottom:12px}
    .alert.err{background:#fdeaea;color:#8a1c1c;border:1px solid #f1b3b3}
    .alert.ok{background:#ecfdf5;color:#065f46;border:1px solid #bbead9}

    form{display:flex;flex-direction:column;gap:12px}
    .row{display:flex;gap:10px}
    .row>*{flex:1;min-width:0}
    .group{display:flex;flex-direction:column;gap:6px}
    label{font-weight:700;font-size:13.5px;color:#0f172a}

    .field{position:relative}
    .field input,.field select{
      width:100%; padding:12px 14px 12px 42px; font-size:15px; border-radius:12px;
      border:1px solid #dfe3e6; background:#ffffffee; outline:none; transition:border .2s, box-shadow .2s;
    }
    .field select{padding-left:12px}
    .field input:focus,.field select:focus{border-color:var(--accent);box-shadow:0 0 0 3px rgba(0,184,148,.18)}
    .icon{position:absolute;left:12px;top:50%;transform:translateY(-50%);color:#94a3b8;font-size:18px}
    .hint{font-size:12px;color:var(--muted)}

    .meter{height:8px;background:#e9eef2;border-radius:999px;overflow:hidden;margin-top:6px}
    .meter>div{height:100%;width:0;background:var(--error);transition:width .25s,background .2s}
    .checklist{display:grid;grid-template-columns:1fr 1fr;gap:6px 12px;margin-top:6px}
    .req{display:flex;align-items:center;gap:6px;font-size:12px;color:#9aa5b1}
    .req.ok{color:var(--success)}
    .caps{display:none;color:var(--warning);font-size:12px;margin-top:6px}

    .terms{display:flex;align-items:flex-start;gap:8px;font-size:13px;color:var(--muted)}
    .terms input{margin-top:2px}

    .btn{
      width:100%; padding:12px 14px; border:none; border-radius:12px; cursor:pointer;
      background:linear-gradient(135deg,var(--primary),var(--primary-dark)); color:#fff; font-weight:800; letter-spacing:.2px;
      transition:filter .2s, transform .12s;
    }
    .btn:hover{filter:brightness(1.05);transform:translateY(-1px)}
    .btn:disabled{opacity:.7;cursor:not-allowed;transform:none}

    .login-link{margin-top:6px;font-size:.95rem;text-align:center; position:relative; z-index:5;}
    .login-link a{color:var(--primary);text-decoration:none;font-weight:800}
    .login-link a:hover{text-decoration:underline}

    /* Overlays: don’t block clicks when hidden */
    .loading,.success{
      display:none; position:absolute; inset:0; border-radius:var(--radius);
      background:rgba(255,255,255,.88); backdrop-filter:blur(4px);
      align-items:center; justify-content:center; z-index:3;
      pointer-events:none;
    }
    .spinner{width:46px;height:46px;border:5px solid var(--primary);border-top-color:transparent;border-radius:50%;animation:spin 1s linear infinite}
    @keyframes spin{to{transform:rotate(360deg)}}
    .success i{font-size:72px;color:var(--success);filter:drop-shadow(0 6px 18px rgba(0,0,0,.15));animation:pop .35s ease}

    .invalid input,.invalid select{border-color:var(--error)}
    .invalid .hint{color:var(--error)}
  </style>
</head>
<body>
  <div class="modal">
    <div class="card" id="card">
      <div class="progress"><span id="progressBar"></span></div>
      <div class="header"><div class="title">Create Account</div></div>

      <div class="content">
        <img src="logo.jpg" alt="Coco Valley logo" class="logo" onerror="this.style.display='none'">
        <div class="sub">Welcome! Fill in your details to get started.</div>

        <div id="alertErr" class="alert err"></div>
        <div id="alertOk" class="alert ok">All set! Submitting…</div>

        <form id="signupForm" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>" method="POST" novalidate>

          <!-- HIDDEN BIRTHDATE FIELD (FINAL VALUE SENT TO PHP) -->
          <input type="hidden" name="birthdate" id="birthdate">

          <div class="row">
            <div class="group">
              <label for="firstName">First Name</label>
              <div class="field">
                <i class='bx bxs-user icon'></i>
                <input type="text" name="firstName" id="firstName" placeholder="Juan" required />
              </div>
            </div>

            <div class="group">
              <label for="lastName">Last Name</label>
              <div class="field">
                <i class='bx bxs-id-card icon'></i>
                <input type="text" name="lastName" id="lastName" placeholder="Dela Cruz" required />
              </div>
            </div>
          </div>

          <div class="group">
            <label for="email">Email</label>
            <div class="field">
              <i class='bx bxs-envelope icon'></i>
              <input type="email" name="email" id="email" placeholder="you@example.com" required />
            </div>
            <div class="hint">We’ll send confirmations here.</div>
          </div>

          <div class="group">
            <label for="phone">Mobile Number</label>
            <div class="field">
              <i class='bx bxs-phone icon'></i>
              <input type="tel" name="phone" id="phone" pattern="09[0-9]{9}" maxlength="11" placeholder="09XXXXXXXXX" required />
            </div>
          </div>

          <div class="group">
            <label for="password">Password</label>
            <div class="field">
              <i class='bx bxs-lock-alt icon'></i>
              <input type="password" name="password" id="password" placeholder="At least 8 characters" required />
              <i class='bx bx-show' id="togglePw"></i>
            </div>

            <div class="meter"><div id="pwBar"></div></div>

            <div class="checklist">
              <div class="req" id="reqLen"><i class='bx bx-checkbox'></i> 8+ characters</div>
              <div class="req" id="reqNum"><i class='bx bx-checkbox'></i> Number</div>
              <div class="req" id="reqLower"><i class='bx bx-checkbox'></i> Lowercase</div>
              <div class="req" id="reqUpper"><i class='bx bx-checkbox'></i> Uppercase</div>
            </div>
            <div class="caps" id="capsWarn">Caps Lock is ON</div>
          </div>

          <div class="group">
            <label for="confirm">Confirm Password</label>
            <div class="field">
              <i class='bx bxs-lock icon'></i>
              <input type="password" id="confirm" name="confirmPassword" placeholder="Re-type password" required />
              <i class='bx bx-show' id="togglePw2"></i>
            </div>
            <div class="hint" id="matchHint">Must match your password.</div>
          </div>

          <div class="group">
            <label>Birthdate</label>
            <div class="row">
              <div class="field">
                <select id="month" required>
                  <option value="">Month</option>
                  <option value="01">Jan</option><option value="02">Feb</option><option value="03">Mar</option>
                  <option value="04">Apr</option><option value="05">May</option><option value="06">Jun</option>
                  <option value="07">Jul</option><option value="08">Aug</option><option value="09">Sep</option>
                  <option value="10">Oct</option><option value="11">Nov</option><option value="12">Dec</option>
                </select>
              </div>

              <div class="field">
                <select id="day" required>
                  <option value="">Day</option>
                </select>
              </div>

              <div class="field">
                <select id="year" required>
                  <option value="">Year</option>
                </select>
              </div>
            </div>
          </div>

          <div class="group">
            <label for="gender">Gender</label>
            <div class="field">
              <select name="gender" id="gender" required>
                <option value="">Select</option>
                <option value="Male">Male</option>
                <option value="Female">Female</option>
                <option value="Prefer not to say">Prefer not to say</option>
              </select>
            </div>
          </div>

          <div class="group">
            <label class="terms">
              <input type="checkbox" id="terms" required>
              <span>I agree to the <a href="terms-privacy.php" target="_blank">Terms and Privacy</a>.</span>
            </label>
          </div>

          <button type="submit" class="btn" id="submitBtn">Create Account</button>
        </form>

        <div class="login-link">
          Already have an account?  
          <a href="/cocovalley/customer/customer-login.php">Login here</a>
        </div>
      </div>

      <div class="loading" id="loading"><div class="spinner"></div></div>
      <div class="success" id="success"><i class='bx bx-check-circle'></i></div>
    </div>
  </div>
</body>


<script>
/* ------------ GET ELEMENTS ------------ */
const monthSel = document.getElementById('month');
const daySel   = document.getElementById('day');
const yearSel  = document.getElementById('year');
const birthdateHidden = document.getElementById('birthdate');

const pw       = document.getElementById('password');
const pw2      = document.getElementById('confirm');
const togglePw = document.getElementById('togglePw');
const togglePw2= document.getElementById('togglePw2');

const pwBar    = document.getElementById('pwBar');
const reqLen   = document.getElementById('reqLen');
const reqNum   = document.getElementById('reqNum');
const reqLower = document.getElementById('reqLower');
const reqUpper = document.getElementById('reqUpper');
const capsWarn = document.getElementById('capsWarn');
const matchHint= document.getElementById('matchHint');

const form     = document.getElementById('signupForm');
const alertErr = document.getElementById('alertErr');
const alertOk  = document.getElementById('alertOk');
const loading  = document.getElementById('loading');
const success  = document.getElementById('success');
const progress = document.getElementById('progressBar');


/* ------------------------------------------
   BIRTHDATE SELECT (month/day/year)
------------------------------------------ */
function fillYears(){
  const now = new Date().getFullYear();
  yearSel.innerHTML = '<option value="">Year</option>';

  for(let y = now - 10; y >= 1900; y--){
    yearSel.innerHTML += `<option value="${y}">${y}</option>`;
  }
}
fillYears();

const isLeap = y => (y%4===0 && y%100!==0) || (y%400===0);

function fillDays(){
  const m = monthSel.value;
  const y = parseInt(yearSel.value, 10);

  const feb = (!isNaN(y) && isLeap(y)) ? 29 : 28;
  const days = {
    "01":31,"02":feb,"03":31,"04":30,"05":31,"06":30,
    "07":31,"08":31,"09":30,"10":31,"11":30,"12":31
  };

  const max = days[m] || 31;
  const prev = daySel.value;

  daySel.innerHTML = '<option value="">Day</option>';
  for(let d=1; d<=max; d++){
    const val = String(d).padStart(2,'0');
    daySel.innerHTML += `<option value="${val}">${d}</option>`;
  }

  if(prev && prev <= max) daySel.value = prev;

  updateBirthdate();
  updateProgress();
}
fillDays();

monthSel.addEventListener('change', fillDays);
yearSel.addEventListener('change', fillDays);
daySel.addEventListener('change', ()=>{ updateBirthdate(); updateProgress(); });


/* ------------------------------------------
   BIRTHDATE → hidden input (YYYY-MM-DD)
------------------------------------------ */
function updateBirthdate(){
  const m = monthSel.value;
  const d = daySel.value;
  const y = yearSel.value;

  if(m && d && y){
    birthdateHidden.value = `${y}-${m}-${d}`;
  } else {
    birthdateHidden.value = "";
  }
}


/* ------------------------------------------
   PASSWORD STRENGTH METER
------------------------------------------ */
function scorePassword(v){
  let s=0;
  if(v.length>=8) s++;
  if(/\d/.test(v)) s++;
  if(/[a-z]/.test(v)) s++;
  if(/[A-Z]/.test(v)) s++;
  return s;
}

function setReq(el, ok){ el.classList.toggle('ok', ok); }

function renderMeter(){
  const v = pw.value;

  setReq(reqLen, v.length>=8);
  setReq(reqNum, /\d/.test(v));
  setReq(reqLower, /[a-z]/.test(v));
  setReq(reqUpper, /[A-Z]/.test(v));

  const s = scorePassword(v);
  pwBar.style.width = (s/4)*100 + '%';
  pwBar.style.background =
    s <= 1 ? '#e53935' :
    s === 2 ? '#f59e0b' : '#1b5e20';

  updateProgress();
}
pw.addEventListener('input', renderMeter);
renderMeter();


/* ------------------------------------------
   CAPS LOCK WARNING
------------------------------------------ */
function capsCheck(e){
  capsWarn.style.display =
    e.getModifierState('CapsLock') ? 'block' : 'none';
}
pw.addEventListener('keyup', capsCheck);
pw2.addEventListener('keyup', capsCheck);


/* ------------------------------------------
   SHOW / HIDE PASSWORD
------------------------------------------ */
function toggleVis(input, icon){
  const isPwd = input.type === 'password';
  input.type = isPwd ? 'text' : 'password';
  icon.classList.toggle('bx-show', !isPwd);
  icon.classList.toggle('bx-hide', isPwd);
}
togglePw.addEventListener('click', ()=> toggleVis(pw, togglePw));
togglePw2.addEventListener('click', ()=> toggleVis(pw2, togglePw2));


/* ------------------------------------------
   PASSWORD MATCH
------------------------------------------ */
function checkMatch(){
  if(!pw2.value){
    matchHint.textContent = "Must match your password.";
    matchHint.style.color = "var(--muted)";
    return false;
  }

  const ok = pw.value === pw2.value;
  matchHint.textContent = ok ? "Passwords match." : "Passwords do not match.";
  matchHint.style.color = ok ? "var(--success)" : "var(--error)";
  return ok;
}
pw2.addEventListener('input', ()=>{ checkMatch(); updateProgress(); });
pw.addEventListener('input', ()=> checkMatch());


/* ------------------------------------------
   PROGRESS BAR
------------------------------------------ */
function requiredFilled(){
  const ph = document.getElementById('phone').value.trim();
  const fn = document.getElementById('firstName').value.trim();
  const ln = document.getElementById('lastName').value.trim();
  const em = document.getElementById('email').value.trim();
  const gd = document.getElementById('gender').value;
  const tm = document.getElementById('terms').checked;

  const pwOK = pw.value.length >= 8;
  const matchOK = pw.value === pw2.value && pw2.value.length > 0;

  const bdOK = birthdateHidden.value !== "";

  return [ph,fn,ln,em,gd,tm,pwOK,matchOK,bdOK].filter(Boolean).length;
}

function updateProgress(){
  const total = 9; 
  const done = requiredFilled();
  progress.style.width = Math.round((done/total)*100) + '%';
}
document.querySelectorAll('input,select').forEach(el=>{
  el.addEventListener('input', updateProgress);
  el.addEventListener('change', updateProgress);
});


/* ------------------------------------------
   INVALID HANDLING
------------------------------------------ */
function invalidate(el, msg){
  const group = el.closest('.group');
  if(group) group.classList.add('invalid');

  alertErr.textContent = msg;
  alertErr.style.display = 'block';

  el.setAttribute('aria-invalid','true');
  el.focus();
}

function clearInvalid(){
  alertErr.style.display = 'none';
  document.querySelectorAll('.group.invalid').forEach(g=>g.classList.remove('invalid'));
  document.querySelectorAll('[aria-invalid="true"]').forEach(x=>x.removeAttribute('aria-invalid'));
}


/* ------------------------------------------
   MAIN SUBMIT
------------------------------------------ */
form.addEventListener('submit', async (e)=>{
  e.preventDefault();
  clearInvalid();

  const phone     = document.getElementById('phone');
  const firstName = document.getElementById('firstName');
  const lastName  = document.getElementById('lastName');
  const email     = document.getElementById('email');
  const gender    = document.getElementById('gender');
  const terms     = document.getElementById('terms');

  if(!/^\d{10,}$/.test(phone.value))
    return invalidate(phone,'Enter a valid phone (digits only, min 10).');

  if(!firstName.value.trim())
    return invalidate(firstName,'Please enter your first name.');

  if(!lastName.value.trim())
    return invalidate(lastName,'Please enter your last name.');

  if(!/[^@\s]+@[^@\s]+\.[^@\s]+/.test(email.value))
    return invalidate(email,'Please enter a valid email.');

  if(pw.value.length < 8)
    return invalidate(pw,'Password must be at least 8 characters.');

  if(!/\d/.test(pw.value))
    return invalidate(pw,'Password must contain a number.');

  if(!/[A-Z]/.test(pw.value))
    return invalidate(pw,'Password must contain an uppercase letter.');

  if(pw.value !== pw2.value)
    return invalidate(pw2,'Passwords do not match.');

  if(birthdateHidden.value === "")
    return invalidate(monthSel,'Please select your full birthdate.');

  if(!gender.value)
    return invalidate(gender,'Please select your gender.');

  if(!terms.checked)
    return invalidate(terms,'You must agree to the Terms and Privacy.');

  /* SEND */
  alertOk.style.display = 'block';
  loading.style.display = 'flex';
  document.getElementById('submitBtn').disabled = true;

  try{
    const res = await fetch(form.action, {
      method:'POST',
      body:new FormData(form)
    });

    const data = await res.json();
    loading.style.display = 'none';

    if(data.ok){
      success.style.display = 'flex';
      setTimeout(()=>{ window.location.href = data.redirect; },700);
    } else {
      alertErr.textContent = data.message;
      alertErr.style.display = 'block';
      document.getElementById('submitBtn').disabled = false;
    }

  } catch(err){
    loading.style.display = 'none';
    alertErr.textContent = 'Network error. Please try again.';
    alertErr.style.display = 'block';
    document.getElementById('submitBtn').disabled = false;
  }
});
</script>
</body>
</html>
