<?php
session_start();
require_once __DIR__ . "/config.php";

function h($str){
  return htmlspecialchars((string)$str, ENT_QUOTES, "UTF-8");
}

// ถ้ามี session อยู่แล้ว ให้ redirect ตาม role
if (!empty($_SESSION['user']) && is_array($_SESSION['user'])) {
  $role   = $_SESSION['user']['role'] ?? '';
  $shopId = (int)($_SESSION['user']['shop_id'] ?? 0);

  if ($role === 'admin') {
    header("Location: /queue/admin/admin-dashboard.php");
    exit;
  }

  if ($role === 'owner' && $shopId > 0) {
    header("Location: /queue/owner/owner-dashboard.php?shop_id=" . $shopId);
    exit;
  }
}

$error = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
  $username = trim((string)($_POST["username"] ?? ""));
  $password = (string)($_POST["password"] ?? "");

  if ($username === "" || $password === "") {
    $error = "กรุณากรอกชื่อผู้ใช้และรหัสผ่าน";
  } else {
    $stmt = $pdo->prepare("
      SELECT user_id, full_name, username, password_hash, role, shop_id, is_active
      FROM users
      WHERE username = ?
      LIMIT 1
    ");
    $stmt->execute([$username]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
      $error = "ไม่พบชื่อผู้ใช้นี้ในระบบ";
    } elseif ((int)$user["is_active"] !== 1) {
      $error = "บัญชีนี้ถูกปิดการใช้งาน";
    } elseif (!password_verify($password, $user["password_hash"])) {
      $error = "รหัสผ่านไม่ถูกต้อง";
    } else {
      session_regenerate_id(true);

      $_SESSION["user"] = [
        "user_id"   => (int)$user["user_id"],
        "full_name" => $user["full_name"],
        "username"  => $user["username"],
        "role"      => $user["role"],
        "shop_id"   => $user["shop_id"] !== null ? (int)$user["shop_id"] : null
      ];

      if ($user["role"] === "admin") {
        header("Location: /queue/admin/admin-dashboard.php");
        exit;
      }

      if ($user["role"] === "owner") {
        $shopId = (int)($user["shop_id"] ?? 0);

        if ($shopId <= 0) {
          session_unset();
          session_destroy();
          $error = "บัญชีร้านค้านี้ยังไม่ได้ผูกกับร้าน";
        } else {
          header("Location: /queue/owner/owner-dashboard.php?shop_id=" . $shopId);
          exit;
        }
      }

      session_unset();
      session_destroy();
      $error = "ไม่พบสิทธิ์การใช้งานของบัญชีนี้";
    }
  }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>เข้าสู่ระบบ | ตลาดน้อย</title>
  <style>
    :root{
      --yellow:#FFCD22;
      --yellow-dark:#f4b400;
      --gray:#7F7F7F;
      --bg:#f5f6f8;
      --white:#ffffff;
      --text:#1f2937;
      --border:#e5e7eb;
      --shadow:0 12px 30px rgba(15, 23, 42, .08);
      --danger:#dc2626;
      --danger-bg:#fef2f2;
      --danger-border:#fecaca;
    }

    *{ box-sizing:border-box; }

    html,body{
      margin:0;
      padding:0;
      min-height:100%;
      font-family:system-ui,-apple-system,"Segoe UI",Roboto,"Noto Sans Thai",sans-serif;
      background:linear-gradient(180deg,#fffdf4 0%, #f5f6f8 100%);
      color:var(--text);
    }

    body{
      display:flex;
      align-items:center;
      justify-content:center;
      padding:20px;
    }

    .login-wrap{
      width:100%;
      max-width:430px;
    }

    .login-card{
      background:var(--white);
      border:1px solid var(--border);
      border-radius:22px;
      box-shadow:var(--shadow);
      overflow:hidden;
    }

    .login-top{
      background:linear-gradient(135deg, #FFCD22 0%, #ffe27a 100%);
      padding:22px 20px 18px;
    }

    .brand{
      display:flex;
      align-items:center;
      gap:12px;
    }

    .brand-logo{
      width:52px;
      height:52px;
      border-radius:16px;
      background:rgba(255,255,255,.45);
      display:flex;
      align-items:center;
      justify-content:center;
      font-size:24px;
      font-weight:800;
      color:#111827;
      flex-shrink:0;
    }

    .brand-text h1{
      margin:0;
      font-size:22px;
      line-height:1.2;
      color:#111827;
    }

    .brand-text p{
      margin:4px 0 0;
      font-size:13px;
      color:#374151;
    }

    .login-body{
      padding:20px;
    }

    .hint-box{
      background:#fffdf2;
      border:1px solid #fde68a;
      color:#6b7280;
      border-radius:14px;
      padding:12px 14px;
      font-size:13px;
      line-height:1.6;
      margin-bottom:16px;
    }

    .error-box{
      background:var(--danger-bg);
      border:1px solid var(--danger-border);
      color:var(--danger);
      border-radius:14px;
      padding:12px 14px;
      font-size:14px;
      margin-bottom:16px;
      line-height:1.5;
    }

    .field{
      margin-bottom:14px;
    }

    .field label{
      display:block;
      font-size:14px;
      font-weight:600;
      margin-bottom:7px;
      color:#374151;
    }

    .input{
      width:100%;
      height:46px;
      border:1px solid var(--border);
      border-radius:14px;
      padding:0 14px;
      font-size:15px;
      outline:none;
      background:#fff;
      transition:.18s ease;
    }

    .input:focus{
      border-color:var(--yellow-dark);
      box-shadow:0 0 0 4px rgba(255,205,34,.22);
    }

    .password-wrap{
      position:relative;
    }

    .password-wrap .input{
      padding-right:92px;
    }

    .toggle-password{
      position:absolute;
      top:50%;
      right:8px;
      transform:translateY(-50%);
      border:none;
      background:transparent;
      color:#4b5563;
      font-size:13px;
      font-weight:700;
      cursor:pointer;
      padding:8px 10px;
      border-radius:10px;
    }

    .toggle-password:hover{
      background:#f3f4f6;
    }

    .btn-login{
      width:100%;
      height:48px;
      border:none;
      border-radius:14px;
      background:var(--yellow);
      color:#111827;
      font-size:15px;
      font-weight:800;
      cursor:pointer;
      transition:.18s ease;
      margin-top:6px;
    }

    .btn-login:hover{
      filter:brightness(.98);
      transform:translateY(-1px);
    }

    .footer-note{
      margin-top:14px;
      text-align:center;
      font-size:12px;
      color:var(--gray);
      line-height:1.5;
    }

    @media (max-width: 480px){
      body{
        padding:14px;
      }

      .login-top{
        padding:18px 16px 16px;
      }

      .login-body{
        padding:16px;
      }

      .brand-text h1{
        font-size:20px;
      }

      .input,
      .btn-login{
        height:44px;
      }
    }
  </style>
</head>
<body>
  <div class="login-wrap">
    <div class="login-card">
      <div class="login-top">
        <div class="brand">
          <div class="brand-logo">Q</div>
          <div class="brand-text">
            <h1>เข้าสู่ระบบ</h1>
            <p>ระบบจัดการคิวตลาดน้อย</p>
          </div>
        </div>
      </div>

      <div class="login-body">
        <div class="hint-box">
          ใช้หน้านี้เข้าสู่ระบบได้ทั้ง <strong>ผู้ดูแลระบบ</strong> และ <strong>ร้านค้า</strong>
        </div>

        <?php if ($error !== ""): ?>
          <div class="error-box"><?= h($error) ?></div>
        <?php endif; ?>

        <form method="post" action="">
          <div class="field">
            <label for="username">ชื่อผู้ใช้</label>
            <input
              class="input"
              type="text"
              id="username"
              name="username"
              placeholder="กรอกชื่อผู้ใช้"
              value="<?= h($_POST["username"] ?? "") ?>"
              autocomplete="username"
              required
            >
          </div>

          <div class="field">
            <label for="password">รหัสผ่าน</label>
            <div class="password-wrap">
              <input
                class="input"
                type="password"
                id="password"
                name="password"
                placeholder="กรอกรหัสผ่าน"
                autocomplete="current-password"
                required
              >
              <button type="button" class="toggle-password" id="togglePassword">แสดง</button>
            </div>
          </div>

          <button type="submit" class="btn-login">เข้าสู่ระบบ</button>
        </form>

        <div class="footer-note">
          Market Queue Management System
        </div>
      </div>
    </div>
  </div>

  <script>
    const passwordInput = document.getElementById("password");
    const toggleBtn = document.getElementById("togglePassword");

    toggleBtn.addEventListener("click", function () {
      const isPassword = passwordInput.type === "password";
      passwordInput.type = isPassword ? "text" : "password";
      toggleBtn.textContent = isPassword ? "ซ่อน" : "แสดง";
    });
  </script>
</body>
</html>