<?php
session_start();

// Database Configuration (XAMPP / Localhost)
$host = 'localhost';
$dbname = 'complete_wheel_db';
$username = 'root';
$password = '';

try {
    $conn = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // 1. Users Table
    $conn->exec("CREATE TABLE IF NOT EXISTS users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(50),
        email VARCHAR(100) UNIQUE,
        password VARCHAR(255),
        balance DECIMAL(10,2) DEFAULT 0.00,
        investment DECIMAL(10,2) DEFAULT 0.00,
        daily_profit DECIMAL(10,2) DEFAULT 0.00,
        is_approved INT DEFAULT 0,
        screenshot VARCHAR(255) DEFAULT '',
        referral_code VARCHAR(50),
        referred_by VARCHAR(50) DEFAULT '',
        referral_count INT DEFAULT 0,
        user_level INT DEFAULT 1,
        last_task_time TIMESTAMP NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    // 2. Admin Settings Table (For Cash Wheel Rigging)
    $conn->exec("CREATE TABLE IF NOT EXISTS admin_settings (
        id INT AUTO_INCREMENT PRIMARY KEY,
        setting_key VARCHAR(50),
        setting_value VARCHAR(255)
    )");

    // Default Forced Wheel Setting
    $chk = $conn->query("SELECT * FROM admin_settings WHERE setting_key = 'forced_win'")->fetch();
    if(!$chk) {
        $conn->exec("INSERT INTO admin_settings (setting_key, setting_value) VALUES ('forced_win', '')");
    }

    // 3. Live Chat Table
    $conn->exec("CREATE TABLE IF NOT EXISTS live_chat (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_email VARCHAR(100),
        sender VARCHAR(20),
        message TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

} catch(PDOException $e) {
    // DB connection error
}

// ----------------- AUTHENTICATION & ACTIONS -----------------
$msg = "";
$error = "";

// Register
if(isset($_POST['register'])) {
    $name = $_POST['name'];
    $email = $_POST['email'];
    $pass = $_POST['password'];
    $ref_code = 'REF' . rand(1000, 9999);
    $entered_ref = $_POST['entered_ref'] ?? '';

    // Check if email exists
    $stmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->execute([$email]);
    if($stmt->rowCount() > 0) {
        $error = "Email already registered!";
    } else {
        $stmt = $conn->prepare("INSERT INTO users (username, email, password, referral_code, referred_by) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$name, $email, $pass, $ref_code, $entered_ref]);

        // If referred by someone, increment their referral count & check level
        if(!empty($entered_ref)) {
            $ref_check = $conn->prepare("SELECT id, referral_count FROM users WHERE referral_code = ?");
            $ref_check->execute([$entered_ref]);
            $ref_user = $ref_check->fetch();
            if($ref_user) {
                $new_count = $ref_user['referral_count'] + 1;
                // Level calculation logic: 5refs=L2, 10refs=L3, 15refs=L4, 20refs=L5, 30refs=L6, 40+refs=L7
                $lvl = 1;
                if($new_count >= 40) $lvl = 7;
                elseif($new_count >= 30) $lvl = 6;
                elseif($new_count >= 20) $lvl = 5;
                elseif($new_count >= 15) $lvl = 4;
                elseif($new_count >= 10) $lvl = 3;
                elseif($new_count >= 5) $lvl = 2;

                $upd_ref = $conn->prepare("UPDATE users SET referral_count = ?, user_level = ? WHERE id = ?");
                $upd_ref->execute([$new_count, $lvl, $ref_user['id']]);
            }
        }
        $msg = "Registration successful! Please login.";
    }
}

// Login
if(isset($_POST['login'])) {
    $email = $_POST['email'];
    $pass = $_POST['password'];

    // Admin Hardcoded Check as requested
    if($email == 'admin@gmail.com' && $pass == 'admin1234') {
        $_SESSION['admin_logged'] = true;
        header("Location: ?admin=1");
        exit;
    }

    $stmt = $conn->prepare("SELECT * FROM users WHERE email = ? AND password = ?");
    $stmt->execute([$email, $pass]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if($user) {
        $_SESSION['user_email'] = $user['email'];
        header("Location: index.php");
        exit;
    } else {
        $error = "Invalid Email or Password!";
    }
}

// Logout
if(isset($_GET['logout'])) {
    session_destroy();
    header("Location: index.php");
    exit;
}

// Investment Upload
if(isset($_POST['upload_investment']) && isset($_SESSION['user_email'])) {
    $amount = $_POST['invest_amount'];
    // Auto assign daily profit based on rules: $10->0.35, $15->0.40, $40->0.50, $100->1.00
    $d_profit = 0.10;
    if($amount == 10) $d_profit = 0.35;
    elseif($amount == 15) $d_profit = 0.40;
    elseif($amount == 40) $d_profit = 0.50;
    elseif($amount >= 100) $d_profit = 1.00;

    // Handle Screenshot Upload
    $target_dir = "uploads/";
    if(!is_dir($target_dir)) mkdir($target_dir, 0777, true);
    $filename = time() . "_" . basename($_FILES["screenshot"]["name"]);
    $target_file = $target_dir . $filename;
    move_uploaded_file($_FILES["screenshot"]["tmp_name"], $target_file);

    $stmt = $conn->prepare("UPDATE users SET investment = ?, daily_profit = ?, screenshot = ?, is_approved = 0 WHERE email = ?");
    $stmt->execute([$amount, $d_profit, $target_file, $_SESSION['user_email']]);
    $msg = "Investment and screenshot submitted! Waiting for admin approval.";
}

// Admin Action: Approve User
if(isset($_GET['approve_id']) && isset($_SESSION['admin_logged'])) {
    $uid = $_GET['approve_id'];
    $conn->query("UPDATE users SET is_approved = 1 WHERE id = $uid");
    header("Location: index.php?admin=1");
    exit;
}

// Admin Action: Set Forced Wheel Prize
if(isset($_POST['set_wheel_prize']) && isset($_SESSION['admin_logged'])) {
    $val = $_POST['forced_prize'];
    $stmt = $conn->prepare("UPDATE admin_settings SET setting_value = ? WHERE setting_key = 'forced_win'");
    $stmt->execute([$val]);
    $msg = "Next spin prize successfully locked by admin!";
}

// Live Chat Send Message
if(isset($_POST['send_chat']) && (isset($_SESSION['user_email']) || isset($_SESSION['admin_logged']))) {
    $sender = isset($_SESSION['admin_logged']) ? 'admin' : $_SESSION['user_email'];
    $target_email = $_POST['chat_user_email'];
    $txt = $_POST['chat_msg'];
    $stmt = $conn->prepare("INSERT INTO live_chat (user_email, sender, message) VALUES (?, ?, ?)");
    $stmt->execute([$target_email, $sender, $txt]);
}

// AJAX Spin Processing Endpoint
if(isset($_GET['action']) && $_GET['action'] == 'spin') {
    header('Content-Type: application/json');
    if(!isset($_SESSION['user_email'])) {
        echo json_encode(['status'=>'error', 'message'=>'Please login first!']);
        exit;
    }
    
    $stmt = $conn->prepare("SELECT * FROM users WHERE email = ?");
    $stmt->execute([$_SESSION['user_email']]);
    $u = $stmt->fetch(PDO::FETCH_ASSOC);

    if($u['is_approved'] == 0) {
        echo json_encode(['status'=>'error', 'message'=>'Error: Your investment is not approved by admin yet!']);
        exit;
    }

    // 24 Hour Cooldown Check
    if(!empty($u['last_task_time'])) {
        $last = strtotime($u['last_task_time']);
        $diff = (time() - $last) / 3600;
        if($diff < 24) {
            $rem = round(24 - $diff, 1);
            echo json_encode(['status'=>'error', 'message'=>"Error: You can spin/task after 24 hours. Remaining: ~{$rem} hours."]);
            exit;
        }
    }

    // Allowed prizes as specified: $6, $10, $15, $20, $25, $35, $50, $85, $100
    $prizes = [6, 10, 15, 20, 25, 35, 50, 85, 100];
    $winning = 0;

    // Check Admin Rigging / Forced Win
    $setting = $conn->query("SELECT setting_value FROM admin_settings WHERE setting_key = 'forced_win'")->fetch(PDO::FETCH_ASSOC);
    if($setting && !empty($setting['setting_value']) && in_array((int)$setting['setting_value'], $prizes)) {
        $winning = (int)$setting['setting_value'];
        // Reset after one use
        $conn->query("UPDATE admin_settings SET setting_value = '' WHERE setting_key = 'forced_win'");
    } else {
        $winning = $prizes[array_rand($prizes)];
    }

    // Update User Balance & Last Task Time
    $upd = $conn->prepare("UPDATE users SET balance = balance + ?, last_task_time = NOW() WHERE email = ?");
    $upd->execute([$winning, $_SESSION['user_email']]);

    echo json_encode(['status'=>'success', 'prize'=>$winning]);
    exit;
}

// Fetch current logged-in user info if any
$current_user = null;
if(isset($_SESSION['user_email'])) {
    $stmt = $conn->prepare("SELECT * FROM users WHERE email = ?");
    $stmt->execute([$_SESSION['user_email']]);
    $current_user = $stmt->fetch(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Complete Admin-Controlled Spin & Investment Platform</title>
    <style>
        body { font-family: Arial, sans-serif; background: #0b0f19; color: #fff; margin: 0; padding: 20px; }
        .container { max-width: 950px; margin: auto; background: #111827; padding: 25px; border-radius: 12px; box-shadow: 0 10px 30px rgba(0,0,0,0.8); }
        h1, h2 { text-align: center; color: #f3f4f6; }
        .box { background: #1f2937; padding: 20px; border-radius: 8px; margin-bottom: 20px; }
        .error { background: #991b1b; color: #fca5a5; padding: 10px; border-radius: 5px; text-align: center; margin-bottom: 15px; }
        .success { background: #065f46; color: #6ee7b7; padding: 10px; border-radius: 5px; text-align: center; margin-bottom: 15px; }
        
        /* Wheel CSS */
        .wheel-wrap { text-align: center; position: relative; display: inline-block; }
        canvas { background: #374151; border-radius: 50%; box-shadow: 0 0 20px rgba(0,0,0,0.5); }
        .pointer {
            position: absolute; top: -5px; left: 50%; transform: translateX(-50%);
            width: 0; height: 0; border-left: 15px solid transparent;
            border-right: 15px solid transparent; border-bottom: 30px solid #f59e0b; z-index: 10;
        }
        .spin-btn { background: #f59e0b; color: #000; border: none; padding: 12px 35px; font-size: 18px; font-weight: bold; border-radius: 30px; cursor: pointer; margin-top: 15px; }
        .spin-btn:disabled { background: #4b5563; cursor: not-allowed; }

        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { border: 1px solid #374151; padding: 8px; text-align: center; font-size: 13px; }
        th { background: #111827; color: #9ca3af; }
        input, select { padding: 6px; background: #374151; color: #fff; border: 1px solid #4b5563; border-radius: 4px; width: 100%; box-sizing: border-box; margin-bottom: 8px; }
        button { background: #2563eb; color: white; border: none; padding: 8px 15px; border-radius: 4px; cursor: pointer; font-weight: bold; }
        .chat-box { height: 150px; overflow-y: scroll; background: #111827; padding: 10px; border: 1px solid #374151; border-radius: 4px; margin-bottom: 10px; text-align: left; }
    </style>
</head>
<body>

<div class="container">
    <h1>Lucky Cash Spin & Investment Platform</h1>

    <?php if(!empty($msg)): ?><div class="success"><?php echo $msg; ?></div><?php endif; ?>
    <?php if(!empty($error)): ?><div class="error"><?php echo $error; ?></div><?php endif; ?>

    <!-- ---------------- ADMIN PANEL VIEW ---------------- -->
    <?php if(isset($_SESSION['admin_logged'])): ?>
        <div class="box" style="border: 2px dashed #f59e0b;">
            <h2 style="color: #f59e0b;">👑 Admin Master Control Panel</h2>
            <p style="text-align: right;"><a href="?logout=1" style="color: #f87171;">Logout Admin</a></p>

            <!-- 1. Wheel Control / Rigging -->
            <div style="background: #111827; padding: 15px; border-radius: 6px; margin-bottom: 15px;">
                <h3>1. Force Next Spin Result (Cash Wheel Rigging)</h3>
                <form method="POST">
                    <label>Select Exact Dollar for Next User Spin:</label>
                    <select name="forced_prize">
                        <option value="">-- Random (Normal) --</option>
                        <?php 
                        $all_prizes = [6, 10, 15, 20, 25, 35, 50, 85, 100];
                        $curr_forced = $conn->query("SELECT setting_value FROM admin_settings WHERE setting_key = 'forced_win'")->fetch()['setting_value'] ?? '';
                        foreach($all_prizes as $p) {
                            $sel = ($curr_forced == $p) ? 'selected' : '';
                            echo "<option value='$p' $sel>\$$p</option>";
                        }
                        ?>
                    </select>
                    <button type="submit" name="set_wheel_prize" style="background: #d97706;">Lock Next Prize</button>
                </form>
                <p style="font-size: 12px; color: #9ca3af; margin-top: 5px;">Currently Forced: <b><?php echo $curr_forced ? "\$$curr_forced" : "None (Random)"; ?></b></p>
            </div>

            <!-- 2. Users Monitoring & Approvals -->
            <div style="background: #111827; padding: 15px; border-radius: 6px; margin-bottom: 15px;">
                <h3>2. Registered Users, Investments & Levels Monitor</h3>
                <table>
                    <tr>
                        <th>Username</th>
                        <th>Email</th>
                        <th>Investment</th>
                        <th>Daily Profit</th>
                        <th>Refs / Level</th>
                        <th>Balance</th>
                        <th>Screenshot</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                    <?php 
                    $users = $conn->query("SELECT * FROM users")->fetchAll(PDO::FETCH_ASSOC);
                    foreach($users as $u):
                    ?>
                    <tr>
                        <td><?php echo $u['username']; ?></td>
                        <td><?php echo $u['email']; ?></td>
                        <td>$<?php echo $u['investment']; ?></td>
                        <td>$<?php echo $u['daily_profit']; ?></td>
                        <td><b><?php echo $u['referral_count']; ?> refs</b> (Lvl <?php echo $u['user_level']; ?>)</td>
                        <td>$<?php echo $u['balance']; ?></td>
                        <td>
                            <?php if(!empty($u['screenshot'])): ?>
                                <a href="<?php echo $u['screenshot']; ?>" target="_blank" style="color: #60a5fa;">View Proof</a>
                            <?php else: ?> None <?php endif; ?>
                        </td>
                        <td><?php echo $u['is_approved'] == 1 ? '<span style="color:#34d399">Active</span>' : '<span style="color:#f87171">Pending</span>'; ?></td>
                        <td>
                            <?php if($u['is_approved'] == 0): ?>
                                <a href="?approve_id=<?php echo $u['id']; ?>&admin=1"><button style="background: #059669; padding: 4px 8px;">Approve</button></a>
                            <?php else: ?> Approved <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </table>
            </div>

            <!-- 3. Admin Live Chat Support Box -->
            <div style="background: #111827; padding: 15px; border-radius: 6px;">
                <h3>3. Live Chat Center (Admin View)</h3>
                <?php 
                $chat_users = $conn->query("SELECT DISTINCT email, username FROM users")->fetchAll(PDO::FETCH_ASSOC);
                foreach($chat_users as $cu):
                ?>
                    <div style="border: 1px solid #374151; padding: 10px; margin-bottom: 10px; border-radius: 4px;">
                        <b>Chat with: <?php echo $cu['username']; ?> (<?php echo $cu['email']; ?>)</b>
                        <div class="chat-box">
                            <?php 
                            $msgs = $conn->prepare("SELECT * FROM live_chat WHERE user_email = ? ORDER BY id ASC");
                            $msgs->execute([$cu['email']]);
                            while($m = $msgs->fetch(PDO::FETCH_ASSOC)) {
                                $col = ($m['sender'] == 'admin') ? '#f59e0b' : '#60a5fa';
                                echo "<p style='color:{$col}; margin: 4px 0;'><b>{$m['sender']}:</b> {$m['message']}</p>";
                            }
                            ?>
                        </div>
                        <form method="POST">
                            <input type="hidden" name="chat_user_email" value="<?php echo $cu['email']; ?>">
                            <input type="text" name="chat_msg" placeholder="Type reply as admin..." required style="width: 75%; display:inline-block;">
                            <button type="submit" name="send_chat" style="width: 20%; display:inline-block;">Send</button>
                        </form>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

    <!-- ---------------- USER / LOGIN / REGISTER VIEW ---------------- -->
    <?php elseif(!isset($_SESSION['user_email'])): ?>
        <div style="display: flex; gap: 20px;">
            <!-- Login Box -->
            <div class="box" style="flex: 1;">
                <h2>Login</h2>
                <form method="POST">
                    <label>Email (or admin@gmail.com):</label>
                    <input type="email" name="email" required>
                    <label>Password:</label>
                    <input type="password" name="password" required>
                    <button type="submit" name="login" style="width:100%; margin-top:10px;">Login</button>
                </form>
            </div>
            <!-- Register Box -->
            <div class="box" style="flex: 1;">
                <h2>Register (Gmail & Referral)</h2>
                <form method="POST">
                    <label>Full Name:</label>
                    <input type="text" name="name" required>
                    <label>Gmail / Email:</label>
                    <input type="email" name="email" required>
                    <label>Password:</label>
                    <input type="password" name="password" required>
                    <label>Referral Code (Optional):</label>
                    <input type="text" name="entered_ref" placeholder="e.g. REF1234">
                    <button type="submit" name="register" style="background:#059669; width:100%; margin-top:10px;">Register</button>
                </form>
            </div>
        </div>

    <!-- ---------------- USER DASHBOARD VIEW ---------------- -->
    <?php else: ?>
        <div class="box">
            <div style="display: flex; justify-content: space-between; align-items: center;">
                <h3>Welcome, <?php echo $current_user['username']; ?> | Balance: $<span id="user-bal"><?php echo $current_user['balance']; ?></span></h3>
                <a href="?logout=1" style="color: #f87171;">Logout</a>
            </div>
            <p><b>Your Referral Link/Code:</b> <span style="color:#f59e0b;"><?php echo $current_user['referral_code']; ?></span> | Total Referrals: <b><?php echo $current_user['referral_count']; ?></b> | <span style="color:#38bdf8;">Level: <?php echo $current_user['user_level']; ?></span></p>
            <p style="font-size: 12px; color: #9ca3af;">Level Rules: 5 Refs = L2, 10 = L3, 15 = L4, 20 = L5, 30 = L6, 40 = L7</p>

            <!-- Investment & Screenshot Upload Section -->
            <?php if($current_user['is_approved'] == 0): ?>
                <div style="background: #7f1d1d; padding: 15px; border-radius: 6px; margin-bottom: 15px;">
                    <h4>⚠️ Investment Locked! Please submit investment & screenshot.</h4>
                    <form method="POST" enctype="multipart/form-data">
                        <label>Select Investment Amount ($):</label>
                        <select name="invest_amount" required>
                            <option value="10">$10 (Daily Profit: $0.35)</option>
                            <option value="15">$15 (Daily Profit: $0.40)</option>
                            <option value="40">$40 (Daily Profit: $0.50)</option>
                            <option value="100">$100 (Daily Profit: $1.00)</option>
                        </select>
                        <label>Upload Payment Proof Screenshot:</label>
                        <input type="file" name="screenshot" accept="image/*" required>
                        <button type="submit" name="upload_investment" style="background: #d97706; margin-top: 10px;">Submit Investment Proof</button>
                    </form>
                </div>
            <?php else: ?>
                <div class="success">✅ Investment Approved! Your Daily Profit tier is active ($<?php echo $current_user['daily_profit']; ?>/day).</div>
            <?php endif; ?>

            <!-- CASH WHEEL SECTION -->
            <div style="text-align: center; margin-top: 20px;">
                <h3>Lucky Cash Spin Wheel</h3>
                <?php if($current_user['is_approved'] == 0): ?>
                    <div class="error">🔒 Wheel is locked until admin approves your investment screenshot!</div>
                    <div style="opacity: 0.3; pointer-events: none;">
                <?php else: ?>
                    <div>
                <?php endif; ?>

                <div class="wheel-wrap">
                    <div class="pointer"></div>
                    <canvas id="wheel" width="300" height="300"></canvas>
                </div>
                <br>
                <button class="spin-btn" id="spinBtn" onclick="spinWheel()">SPIN NOW (24h Cooldown)</button>
                <h3 id="spinResult" style="color: #f59e0b; margin-top: 10px;"></h3>

                <?php if($current_user['is_approved'] == 0): ?>
                    </div>
                <?php else: ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- LIVE CHAT WITH ADMIN -->
            <div style="margin-top: 30px; background: #111827; padding: 15px; border-radius: 6px;">
                <h3>Live Chat Support with Admin</h3>
                <div class="chat-box" id="userChatBox">
                    <?php 
                    $my_msgs = $conn->prepare("SELECT * FROM live_chat WHERE user_email = ? ORDER BY id ASC");
                    $my_msgs->execute([$current_user['email']]);
                    while($mm = $my_msgs->fetch(PDO::FETCH_ASSOC)) {
                        $col = ($mm['sender'] == 'admin') ? '#f59e0b' : '#60a5fa';
                        echo "<p style='color:{$col}; margin: 4px 0;'><b>{$mm['sender']}:</b> {$mm['message']}</p>";
                    }
                    ?>
                </div>
                <form method="POST">
                    <input type="hidden" name="chat_user_email" value="<?php echo $current_user['email']; ?>">
                    <input type="text" name="chat_msg" placeholder="Type message to admin..." required style="width: 80%; display:inline-block;">
                    <button type="submit" name="send_chat" style="width: 18%; display:inline-block;">Send</button>
                </form>
            </div>
        </div>
    <?php endif; ?>
</div>

<script>
    // Canvas Cash Wheel with exact values: $6, $10, $15, $20, $25, $35, $50, $85, $100
    const prizes = ['$6', '$10', '$15', '$20', '$25', '$35', '$50', '$85', '$100'];
    const colors = ['#ef4444', '#f97316', '#eab308', '#84cc16', '#10b981', '#06b6d4', '#3b82f6', '#8b5cf6', '#ec4899'];
    const canvas = document.getElementById('wheel');
    if(canvas) {
        const ctx = canvas.getContext('2d');
        const numSegments = prizes.length;
        const arcSize = (2 * Math.PI) / numSegments;
        let startAngle = 0;
        let isSpinning = false;

        function drawWheel() {
            ctx.clearRect(0, 0, 300, 300);
            for (let i = 0; i < numSegments; i++) {
                let angle = startAngle + i * arcSize;
                ctx.beginPath();
                ctx.fillStyle = colors[i];
                ctx.moveTo(150, 150);
                ctx.arc(150, 150, 140, angle, angle + arcSize, false);
                ctx.lineTo(150, 150);
                ctx.fill();
                ctx.save();

                ctx.fillStyle = "#ffffff";
                ctx.font = "bold 14px Arial";
                ctx.translate(150 + Math.cos(angle + arcSize / 2) * 90, 150 + Math.sin(angle + arcSize / 2) * 90);
                ctx.rotate(angle + arcSize / 2 + Math.PI / 2);
                ctx.fillText(prizes[i], -ctx.measureText(prizes[i]).width / 2, 0);
                ctx.restore();
            }
        }
        drawWheel();

        function spinWheel() {
            if (isSpinning) return;
            isSpinning = true;
            document.getElementById('spinBtn').disabled = true;
            document.getElementById('spinResult').innerText = "Spinning...";

            fetch('?action=spin')
            .then(res => res.json())
            .then(data => {
                if (data.status === 'error') {
                    alert(data.message);
                    isSpinning = false;
                    document.getElementById('spinBtn').disabled = false;
                    document.getElementById('spinResult').innerText = "";
                    return;
                }

                let winningAmount = data.prize;
                let winningIndex = prizes.indexOf('$' + winningAmount);
                let targetAngle = 2 * Math.PI - (winningIndex * arcSize + arcSize / 2);
                let totalRotations = 6 * 2 * Math.PI;
                let finalAngle = totalRotations + targetAngle;

                let currentRotation = 0;
                let interval = setInterval(() => {
                    currentRotation += 0.3;
                    startAngle += 0.3;
                    drawWheel();

                    if (currentRotation >= finalAngle) {
                        clearInterval(interval);
                        isSpinning = false;
                        document.getElementById('spinBtn').disabled = false;
                        document.getElementById('spinResult').innerText = "🎉 You Won: $" + winningAmount;
                        
                        let bal = document.getElementById('user-bal');
                        bal.innerText = (parseFloat(bal.innerText) + parseFloat(winningAmount)).toFixed(2);
                    }
                }, 20);
            }).catch(err => {
                isSpinning, isSpinning = false;
                document.getElementById('spinBtn').disabled = false;
            });
        }
    }
</script>

</body>
</html>
