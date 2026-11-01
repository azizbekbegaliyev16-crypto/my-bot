<?php
require_once __DIR__ . '/config.php';

$page = $_GET['page'] ?? 'home';
$error = '';

/* Birinchi adminni yaratish */
if ($page === 'setup') {
    $count = (int)$pdo->query(
        "SELECT COUNT(*) FROM users WHERE role='admin'"
    )->fetchColumn();

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        check_csrf();

        if ($count > 0) {
            $error = 'Admin allaqachon yaratilgan.';
        } elseif (!hash_equals(ADMIN_SETUP_KEY, $_POST['setup_key'] ?? '')) {
            $error = 'Maxsus kalit noto‘g‘ri.';
        } else {
            $name = trim($_POST['name'] ?? '');
            $email = filter_var($_POST['email'] ?? '', FILTER_VALIDATE_EMAIL);
            $password = $_POST['password'] ?? '';

            if (!$name || !$email || strlen($password) < 10) {
                $error = 'Ma’lumotlarni to‘g‘ri kiriting. Parol kamida 10 belgi bo‘lsin.';
            } else {
                $stmt = $pdo->prepare(
                    "INSERT INTO users(name,email,password,role)
                     VALUES(?,?,?,'admin')"
                );
                $stmt->execute([
                    $name,
                    $email,
                    password_hash($password, PASSWORD_DEFAULT)
                ]);
                notice('Admin yaratildi. Endi tizimga kiring.');
                header('Location: index.php?page=login');
                exit;
            }
        }
    }

    ?>
    <!doctype html>
    <html lang="uz">
    <head>
      <meta charset="utf-8">
      <meta name="viewport" content="width=device-width,initial-scale=1">
      <title>Admin sozlash</title>
      <link rel="stylesheet" href="assets/style.css">
    </head>
    <body>
    <main class="auth">
      <h1>Birinchi admin</h1>
      <?php if ($error): ?><div class="alert"><?=e($error)?></div><?php endif; ?>
      <?php if ($count === 0): ?>
      <form method="post">
        <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
        <input name="setup_key" placeholder="Maxsus kalit" required>
        <input name="name" placeholder="Ism" required>
        <input type="email" name="email" placeholder="Email" required>
        <input type="password" name="password" placeholder="Kuchli parol" minlength="10" required>
        <button>Admin yaratish</button>
      </form>
      <?php else: ?>
        <p>Admin allaqachon yaratilgan. <a href="index.php?page=login">Kirish</a></p>
      <?php endif; ?>
    </main>
    </body>
    </html>
    <?php
    exit;
}

/* POST amallari */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'register') {
        $name = trim($_POST['name'] ?? '');
        $email = filter_var($_POST['email'] ?? '', FILTER_VALIDATE_EMAIL);
        $password = $_POST['password'] ?? '';

        if (!$name || !$email || strlen($password) < 8) {
            $error = 'Ism, email va kamida 8 belgili parol kiriting.';
        } else {
            try {
                $stmt = $pdo->prepare(
                    "INSERT INTO users(name,email,password) VALUES(?,?,?)"
                );
                $stmt->execute([
                    $name,
                    $email,
                    password_hash($password, PASSWORD_DEFAULT)
                ]);
                notice('Ro‘yxatdan o‘tdingiz. Endi tizimga kiring.');
                header('Location: index.php?page=login');
                exit;
            } catch (PDOException $e) {
                $error = 'Bu email oldin ro‘yxatdan o‘tgan bo‘lishi mumkin.';
            }
        }
    }

    if ($action === 'login') {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE email=? LIMIT 1");
        $stmt->execute([trim($_POST['email'] ?? '')]);
        $user = $stmt->fetch();

        if ($user && password_verify($_POST['password'] ?? '', $user['password'])) {
            session_regenerate_id(true);
            $_SESSION['user_id'] = (int)$user['id'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['user_name'] = $user['name'];
            header('Location: index.php?page=dashboard');
            exit;
        }
        $error = 'Email yoki parol noto‘g‘ri.';
    }

    if ($action === 'logout') {
        $_SESSION = [];
        session_destroy();
        header('Location: index.php');
        exit;
    }

    if ($action === 'create_plan') {
        require_admin();

        $name = trim($_POST['name'] ?? '');
        $type = $_POST['type'] ?? 'web';
        $disk = (int)($_POST['disk_gb'] ?? 1);
        $uzs = (float)($_POST['price_uzs'] ?? 0);
        $rub = (float)($_POST['price_rub'] ?? 0);
        $days = (int)($_POST['period_days'] ?? 30);
        $description = trim($_POST['description'] ?? '');

        if (
            $name === '' ||
            !in_array($type, ['web','bot','both'], true) ||
            $disk < 1 || $disk > 10000 ||
            $uzs < 0 || $rub < 0 ||
            $days < 1 || $days > 3650
        ) {
            $error = 'Tarif ma’lumotlarini tekshiring.';
        } else {
            $stmt = $pdo->prepare(
                "INSERT INTO plans(name,type,disk_gb,price_uzs,price_rub,period_days,description)
                 VALUES(?,?,?,?,?,?,?)"
            );
            $stmt->execute([$name,$type,$disk,$uzs,$rub,$days,$description]);
            notice('Yangi tarif qo‘shildi.');
            header('Location: index.php?page=admin');
            exit;
        }
    }

    if ($action === 'toggle_plan') {
        require_admin();
        $id = (int)($_POST['plan_id'] ?? 0);
        $stmt = $pdo->prepare("UPDATE plans SET status=IF(status=1,0,1) WHERE id=?");
        $stmt->execute([$id]);
        header('Location: index.php?page=admin');
        exit;
    }

    if ($action === 'order') {
        require_login();
        $planId = (int)($_POST['plan_id'] ?? 0);
        $currency = $_POST['currency'] ?? 'UZS';

        $stmt = $pdo->prepare("SELECT * FROM plans WHERE id=? AND status=1");
        $stmt->execute([$planId]);
        $plan = $stmt->fetch();

        if (!$plan || !in_array($currency, ['UZS','RUB'], true)) {
            $error = 'Tarif topilmadi yoki valyuta noto‘g‘ri.';
        } else {
            $amount = $currency === 'UZS'
                ? (float)$plan['price_uzs']
                : (float)$plan['price_rub'];

            $stmt = $pdo->prepare(
                "INSERT INTO orders(user_id,plan_id,currency,amount)
                 VALUES(?,?,?,?)"
            );
            $stmt->execute([$_SESSION['user_id'],$planId,$currency,$amount]);
            $orderId = (int)$pdo->lastInsertId();

            telegram_send("🆕 Yangi buyurtma #$orderId\nTarif: {$plan['name']}\nSumma: $amount $currency");

            notice("Buyurtma #$orderId yaratildi. Endi to‘lov ma’lumotini yuboring.");
            header('Location: index.php?page=orders');
            exit;
        }
    }

    if ($action === 'payment') {
        require_login();

        $orderId = (int)($_POST['order_id'] ?? 0);
        $stmt = $pdo->prepare(
            "SELECT * FROM orders WHERE id=? AND user_id=? AND status='pending'"
        );
        $stmt->execute([$orderId,$_SESSION['user_id']]);
        $order = $stmt->fetch();

        if (!$order || empty($_FILES['proof']['tmp_name'])) {
            $error = 'Buyurtma yoki to‘lov cheki topilmadi.';
        } else {
            $file = $_FILES['proof'];

            if ($file['error'] !== UPLOAD_ERR_OK || $file['size'] > 3*1024*1024) {
                $error = 'Fayl xatosi. Maksimal hajm 3 MB.';
            } else {
                $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
                $allowed = [
                    'image/jpeg' => 'jpg',
                    'image/png' => 'png',
                    'application/pdf' => 'pdf'
                ];

                if (!isset($allowed[$mime])) {
                    $error = 'Faqat JPG, PNG yoki PDF chek yuborish mumkin.';
                } else {
                    $dir = STORAGE_PATH . '/payments';
                    if (!is_dir($dir)) {
                        mkdir($dir, 0750, true);
                    }

                    $filename = bin2hex(random_bytes(16)) . '.' . $allowed[$mime];
                    $target = $dir . '/' . $filename;

                    if (move_uploaded_file($file['tmp_name'], $target)) {
                        $relative = 'payments/' . $filename;

                        $stmt = $pdo->prepare(
                            "INSERT INTO payments(order_id,user_id,amount,currency,proof)
                             VALUES(?,?,?,?,?)"
                        );
                        $stmt->execute([
                            $orderId,
                            $_SESSION['user_id'],
                            $order['amount'],
                            $order['currency'],
                            $relative
                        ]);

                        $pdo->prepare(
                            "UPDATE orders SET status='payment_wait' WHERE id=?"
                        )->execute([$orderId]);

                        telegram_send("💳 To‘lov cheki yuborildi. Buyurtma #$orderId");

                        notice('To‘lov cheki yuborildi. Admin tekshiradi.');
                        header('Location: index.php?page=orders');
                        exit;
                    } else {
                        $error = 'Faylni saqlash amalga oshmadi.';
                    }
                }
            }
        }
    }

    if ($action === 'approve_payment' || $action === 'reject_payment') {
        require_admin();

        $paymentId = (int)($_POST['payment_id'] ?? 0);
        $pdo->beginTransaction();

        try {
            $stmt = $pdo->prepare(
                "SELECT * FROM payments WHERE id=? AND status='pending' FOR UPDATE"
            );
            $stmt->execute([$paymentId]);
            $payment = $stmt->fetch();

            if ($payment) {
                $newStatus = $action === 'approve_payment' ? 'approved' : 'rejected';
                $orderStatus = $action === 'approve_payment' ? 'paid' : 'rejected';

                $pdo->prepare("UPDATE payments SET status=? WHERE id=?")
                    ->execute([$newStatus,$paymentId]);

                $pdo->prepare("UPDATE orders SET status=? WHERE id=?")
                    ->execute([$orderStatus,$payment['order_id']]);

                $pdo->prepare(
                    "INSERT INTO notifications(user_id,message) VALUES(?,?)"
                )->execute([
                    $payment['user_id'],
                    $action === 'approve_payment'
                        ? "Buyurtma #{$payment['order_id']} to‘lovi tasdiqlandi."
                        : "Buyurtma #{$payment['order_id']} to‘lovi rad etildi."
                ]);
            }

            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            $error = 'Amal bajarilmadi.';
        }

        header('Location: index.php?page=admin');
        exit;
    }

    if ($action === 'activate_service' || $action === 'stop_service') {
        require_admin();

        $orderId = (int)($_POST['order_id'] ?? 0);
        $stmt = $pdo->prepare("SELECT * FROM orders WHERE id=?");
        $stmt->execute([$orderId]);
        $order = $stmt->fetch();

        if ($order && $order['status'] === 'paid' && $action === 'activate_service') {
            $stmt = $pdo->prepare(
                "INSERT INTO services(order_id,user_id,status,expires_at)
                 VALUES(?,?,'active',DATE_ADD(NOW(), INTERVAL
                 (SELECT period_days FROM plans WHERE id=?) DAY))"
            );
            $stmt->execute([$orderId,$order['user_id'],$order['plan_id']]);

            $pdo->prepare("UPDATE orders SET status='active' WHERE id=?")
                ->execute([$orderId]);

            $pdo->prepare(
                "INSERT INTO notifications(user_id,message) VALUES(?,?)"
            )->execute([$order['user_id'],"Buyurtma #$orderId xizmati faollashtirildi."]);
        }

        if ($order && $action === 'stop_service') {
            $pdo->prepare(
                "UPDATE services SET status='stopped' WHERE order_id=?"
            )->execute([$orderId]);
            $pdo->prepare(
                "UPDATE orders SET status='paid' WHERE id=? AND status='active'"
            )->execute([$orderId]);
        }

        header('Location: index.php?page=admin');
        exit;
    }
}

/* Sahifa ma’lumotlari */
$plans = $pdo->query("SELECT * FROM plans WHERE status=1 ORDER BY id DESC")->fetchAll();
$notice = $_SESSION['notice'] ?? '';
unset($_SESSION['notice']);

function header_html(string $title): void {
    global $notice, $error;
    ?>
    <!doctype html>
    <html lang="uz">
    <head>
      <meta charset="utf-8">
      <meta name="viewport" content="width=device-width,initial-scale=1">
      <title><?=e($title)?> | ULTRA X HOSTING</title>
      <link rel="stylesheet" href="assets/style.css">
    </head>
    <body>
    <header class="top">
      <a class="logo" href="index.php">ULTRA X <span>HOSTING</span></a>
      <nav>
        <a href="index.php">Bosh sahifa</a>
        <a href="index.php?page=plans">Tariflar</a>
        <?php if (logged_in()): ?>
          <a href="index.php?page=dashboard">Kabinet</a>
          <?php if (is_admin()): ?><a href="index.php?page=admin">Admin</a><?php endif; ?>
          <form method="post" class="inline">
            <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
            <input type="hidden" name="action" value="logout">
            <button class="small">Chiqish</button>
          </form>
        <?php else: ?>
          <a href="index.php?page=login">Kirish</a>
          <a href="index.php?page=register">Ro‘yxatdan o‘tish</a>
        <?php endif; ?>
      </nav>
    </header>
    <main class="container">
      <?php if ($notice): ?><div class="success"><?=e($notice)?></div><?php endif; ?>
      <?php if ($error): ?><div class="alert"><?=e($error)?></div><?php endif; ?>
    <?php
}

function footer_html(): void {
    ?>
    </main>
    <footer>© <?=date('Y')?> ULTRA X HOSTING — Barcha huquqlar himoyalangan.</footer>
    </body>
    </html>
    <?php
}

if ($page === 'login' || $page === 'register') {
    header_html($page === 'login' ? 'Kirish' : 'Ro‘yxatdan o‘tish');
    ?>
    <section class="auth card">
      <h1><?=$page === 'login' ? 'Hisobingizga kiring' : 'Yangi hisob yarating'?></h1>
      <form method="post">
        <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
        <input type="hidden" name="action" value="<?=$page === 'login' ? 'login' : 'register'?>">
        <?php if ($page === 'register'): ?>
          <label>Ismingiz</label>
          <input name="name" required maxlength="100">
        <?php endif; ?>
        <label>Email</label>
        <input type="email" name="email" required>
        <label>Parol</label>
        <input type="password" name="password" required>
        <button class="full"><?=$page === 'login' ? 'Kirish' : 'Ro‘yxatdan o‘tish'?></button>
      </form>
    </section>
    <?php
    footer_html();
    exit;
}

if ($page === 'plans' || $page === 'home') {
    header_html('Hosting xizmatlari');
    ?>
    <section class="hero">
      <div>
        <div class="tag">NEXT GENERATION HOSTING</div>
        <h1>LOYIHALARINGIZ UCHUN <span>ISHONCHLI HOSTING</span></h1>
        <p>Web-saytlar va Telegram botlaringiz uchun hosting tarifini tanlang.</p>
        <a class="button" href="index.php?page=plans">Tariflarni ko‘rish</a>
      </div>
    </section>

    <h2>Hosting tariflari</h2>
    <div class="plans">
      <?php foreach ($plans as $plan): ?>
        <article class="card plan">
          <div class="tag"><?=e(strtoupper($plan['type']))?></div>
          <h3><?=e($plan['name'])?></h3>
          <div class="price"><?=number_format((float)$plan['price_uzs'],0,'.',' ')?> <small>UZS / oy</small></div>
          <p><?=e($plan['description'] ?? '')?></p>
          <ul>
            <li><?= (int)$plan['disk_gb'] ?> GB xotira</li>
            <li><?= (int)$plan['period_days'] ?> kun</li>
            <li>Admin tasdiqlashi bilan faollashadi</li>
          </ul>
          <?php if (logged_in()): ?>
            <form method="post">
              <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
              <input type="hidden" name="action" value="order">
              <input type="hidden" name="plan_id" value="<?=(int)$plan['id']?>">
              <select name="currency">
                <option value="UZS">UZS</option>
                <option value="RUB">RUB</option>
              </select>
              <button class="full">Buyurtma berish</button>
            </form>
          <?php else: ?>
            <a class="button full" href="index.php?page=login">Buyurtma berish</a>
          <?php endif; ?>
        </article>
      <?php endforeach; ?>
      <?php if (!$plans): ?><p>Hozircha tariflar mavjud emas.</p><?php endif; ?>
    </div>
    <?php
    footer_html();
    exit;
}

if ($page === 'dashboard') {
    require_login();
    header_html('Mijoz kabineti');

    $stmt = $pdo->prepare("SELECT * FROM users WHERE id=?");
    $stmt->execute([$_SESSION['user_id']]);
    $me = $stmt->fetch();

    $stmt = $pdo->prepare(
        "SELECT o.*,p.name AS plan_name
         FROM orders o JOIN plans p ON p.id=o.plan_id
         WHERE o.user_id=? ORDER BY o.id DESC"
    );
    $stmt->execute([$_SESSION['user_id']]);
    $orders = $stmt->fetchAll();

    $stmt = $pdo->prepare(
        "SELECT * FROM notifications WHERE user_id=? ORDER BY id DESC LIMIT 10"
    );
    $stmt->execute([$_SESSION['user_id']]);
    $notifications = $stmt->fetchAll();
    ?>
    <h1>Mijoz kabineti</h1>
    <section class="grid">
      <div class="card">
        <h3>Salom, <?=e($me['name'])?></h3>
        <p><?=e($me['email'])?></p>
        <p>Balans: <?=number_format((float)$me['balance'],2)?> UZS</p>
      </div>
      <div class="card">
        <h3>Hosting xizmatlari</h3>
        <p>Buyurtmalaringiz va xizmat holatini shu yerdan kuzating.</p>
        <a class="button" href="index.php?page=plans">Tarif tanlash</a>
      </div>
    </section>
    <h2>Bildirishnomalar</h2>
    <div class="card">
      <?php foreach ($notifications as $n): ?>
        <p><?=e($n['message'])?> <small><?=e($n['created_at'])?></small></p>
      <?php endforeach; ?>
      <?php if (!$notifications): ?><p>Hozircha xabar yo‘q.</p><?php endif; ?>
    </div>
    <h2>Buyurtmalar</h2>
    <div class="card">
      <?php foreach ($orders as $o): ?>
        <div class="order">
          <b>#<?=(int)$o['id']?> — <?=e($o['plan_name'])?></b>
          <p><?=number_format((float)$o['amount'],2)?> <?=e($o['currency'])?> · <?=e($o['status'])?></p>
          <?php if ($o['status'] === 'pending'): ?>
            <form method="post" enctype="multipart/form-data">
              <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
              <input type="hidden" name="action" value="payment">
              <input type="hidden" name="order_id" value="<?=(int)$o['id']?>">
              <label>To‘lov chekini yuklash (JPG, PNG, PDF; 3 MB gacha)</label>
              <input type="file" name="proof" accept=".jpg,.jpeg,.png,.pdf" required>
              <button>Chekni yuborish</button>
            </form>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
      <?php if (!$orders): ?><p>Hali buyurtmalaringiz yo‘q.</p><?php endif; ?>
    </div>
    <?php
    footer_html();
    exit;
}

if ($page === 'orders') {
    require_login();
    header_html('Buyurtmalar');

    $stmt = $pdo->prepare(
        "SELECT o.*,p.name AS plan_name
         FROM orders o JOIN plans p ON p.id=o.plan_id
         WHERE o.user_id=? ORDER BY o.id DESC"
    );
    $stmt->execute([$_SESSION['user_id']]);
    $orders = $stmt->fetchAll();
    ?>
    <h1>Buyurtmalarim</h1>
    <?php foreach ($orders as $o): ?>
      <div class="card order">
        <b>#<?=(int)$o['id']?> — <?=e($o['plan_name'])?></b>
        <p><?=number_format((float)$o['amount'],2)?> <?=e($o['currency'])?></p>
        <p>Holati: <?=e($o['status'])?></p>
        <?php if ($o['status'] === 'pending'): ?>
          <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
            <input type="hidden" name="action" value="payment">
            <input type="hidden" name="order_id" value="<?=(int)$o['id']?>">
            <input type="file" name="proof" accept=".jpg,.jpeg,.png,.pdf" required>
            <button>To‘lov chekini yuborish</button>
          </form>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
    <?php
    footer_html();
    exit;
}

if ($page === 'admin') {
    require_admin();
    header_html('Admin panel');

    $allPlans = $pdo->query("SELECT * FROM plans ORDER BY id DESC")->fetchAll();

    $payments = $pdo->query(
        "SELECT py.*,o.id AS oid,u.name,u.email
         FROM payments py
         JOIN orders o ON o.id=py.order_id
         JOIN users u ON u.id=py.user_id
         ORDER BY py.id DESC"
    )->fetchAll();

    $orders = $pdo->query(
        "SELECT o.*,u.name,p.name AS plan_name
         FROM orders o
         JOIN users u ON u.id=o.user_id
         JOIN plans p ON p.id=o.plan_id
         ORDER BY o.id DESC"
    )->fetchAll();

    $userCount = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
    $orderCount = (int)$pdo->query("SELECT COUNT(*) FROM orders")->fetchColumn();
    ?>
    <h1>Admin boshqaruv paneli</h1>
    <section class="grid">
      <div class="card"><h3>Mijozlar</h3><strong><?=$userCount?></strong></div>
      <div class="card"><h3>Buyurtmalar</h3><strong><?=$orderCount?></strong></div>
      <div class="card"><h3>Tariflar</h3><strong><?=count($allPlans)?></strong></div>
    </section>

    <h2>Yangi tarif yaratish</h2>
    <form method="post" class="card">
      <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
      <input type="hidden" name="action" value="create_plan">
      <label>Tarif nomi</label>
      <input name="name" required maxlength="120" placeholder="Masalan: ULTRA PRO">
      <label>Hosting turi</label>
      <select name="type">
        <option value="web">Web hosting</option>
        <option value="bot">Telegram bot hosting</option>
        <option value="both">Web + bot hosting</option>
      </select>
      <label>Xotira (GB)</label>
      <input type="number" name="disk_gb" min="1" max="10000" value="5" required>
      <label>Narxi (UZS)</label>
      <input type="number" step="0.01" min="0" name="price_uzs" required>
      <label>Narxi (RUB)</label>
      <input type="number" step="0.01" min="0" name="price_rub" required>
      <label>Amal qilish muddati (kun)</label>
      <input type="number" name="period_days" min="1" max="3650" value="30" required>
      <label>Tarif haqida</label>
      <textarea name="description" rows="3"></textarea>
      <button>Tarifni saqlash</button>
    </form>

    <h2>Tariflarni boshqarish</h2>
    <?php foreach ($allPlans as $p): ?>
      <div class="card order">
        <b><?=e($p['name'])?></b>
        <p><?=e($p['type'])?> · <?=(int)$p['disk_gb']?> GB ·
        <?=number_format((float)$p['price_uzs'])?> UZS</p>
        <p>Holati: <?=$p['status'] ? 'Faol' : 'Yopilgan'?></p>
        <form method="post">
          <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
          <input type="hidden" name="action" value="toggle_plan">
          <input type="hidden" name="plan_id" value="<?=(int)$p['id']?>">
          <button><?=$p['status'] ? 'Tarifni yopish' : 'Tarifni faollashtirish'?></button>
        </form>
      </div>
    <?php endforeach; ?>

    <h2>To‘lovlarni tekshirish</h2>
    <?php foreach ($payments as $py): ?>
      <div class="card order">
        <b>To‘lov #<?=(int)$py['id']?> — Buyurtma #<?=(int)$py['oid']?></b>
        <p><?=e($py['name'])?> (<?=e($py['email'])?>)</p>
        <p><?=number_format((float)$py['amount'],2)?> <?=e($py['currency'])?></p>
        <p>Holati: <?=e($py['status'])?></p>
        <?php if ($py['status'] === 'pending'): ?>
          <form method="post" class="inline">
            <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
            <input type="hidden" name="payment_id" value="<?=(int)$py['id']?>">
            <button name="action" value="approve_payment">Tasdiqlash</button>
            <button class="danger" name="action" value="reject_payment">Rad etish</button>
          </form>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>

    <h2>Xizmatlarni faollashtirish</h2>
    <?php foreach ($orders as $o): ?>
      <div class="card order">
        <b>Buyurtma #<?=(int)$o['id']?> — <?=e($o['plan_name'])?></b>
        <p><?=e($o['name'])?> · Holati: <?=e($o['status'])?></p>
        <?php if ($o['status'] === 'paid'): ?>
          <form method="post">
            <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
            <input type="hidden" name="action" value="activate_service">
            <input type="hidden" name="order_id" value="<?=(int)$o['id']?>">
            <button>Xizmatni faollashtirish</button>
          </form>
        <?php elseif ($o['status'] === 'active'): ?>
          <form method="post">
            <input type="hidden" name="csrf" value="<?=e(csrf_token())?>">
            <input type="hidden" name="action" value="stop_service">
            <input type="hidden" name="order_id" value="<?=(int)$o['id']?>">
            <button class="danger">Xizmatni to‘xtatish</button>
          </form>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
    <?php
    footer_html();
    exit;
}

header('Location: index.php');
exit;
