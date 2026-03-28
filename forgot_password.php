<?php
// no session required here
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Forgot Password</title>
  <style>
    body { font-family: Arial; background:#f2f2f2; padding:40px; }
    .box { max-width:400px; margin:auto; background:white; padding:25px; border-radius:8px; box-shadow:0 3px 10px rgba(0,0,0,0.1); }
    input { width:100%; padding:12px; margin-top:10px; border:1px solid #ccc; border-radius:6px; }
    button { width:100%; background:#0b63c9; color:white; padding:12px; border:none; border-radius:6px; margin-top:15px; cursor:pointer; }
    .msg { margin-top:15px; padding:10px; border-radius:6px; display:none; }
    .success { background:#d4edda; color:#155724; }
    .error { background:#f8d7da; color:#721c24; }
  </style>
</head>
<body>

<div class="box">
  <h2>Forgot Password</h2>
  <p>Enter your email to receive a reset link.</p>

  <input type="email" id="email" placeholder="Enter your email" required>

  <button onclick="sendReset()">Send Reset Link</button>

  <div id="msg" class="msg"></div>
</div>

<script>
function sendReset() {
    const email = document.getElementById("email").value.trim();
    const msg = document.getElementById("msg");

    msg.style.display = "none";

    if (!email) {
        msg.className = "msg error";
        msg.innerHTML = "Email is required.";
        msg.style.display = "block";
        return;
    }

    fetch("forgot_password_process.php", {
        method: "POST",
        headers: { "Content-Type": "application/x-www-form-urlencoded" },
        body: "email=" + encodeURIComponent(email)
    })
    .then(res => res.json())
    .then(data => {
        msg.className = "msg " + (data.status === "success" ? "success" : "error");
        msg.innerHTML = data.message;

        msg.style.display = "block";

        if (data.reset_link) {
            console.log("DEV RESET LINK:", data.reset_link);
        }
    });
}
</script>

</body>
</html>
