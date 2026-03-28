<?php
// 设置时区
date_default_timezone_set('Asia/Shanghai');

// 会话路径设置（与 index.php 一致）
$sessionPath = __DIR__ . '/sessions';
if (!file_exists($sessionPath)) {
    mkdir($sessionPath, 0755, true);
}
session_save_path($sessionPath);
session_start();

require_once 'functions.php';

// 如果已登录，跳转
if (isLoggedIn()) {
    header('Location: index.php');
    exit;
}

$error = '';

// 初始化登录尝试次数
if (!isset($_SESSION['login_attempts'])) {
    $_SESSION['login_attempts'] = 0;
}
if (!isset($_SESSION['last_attempt_time'])) {
    $_SESSION['last_attempt_time'] = time();
}

// 检查是否被锁定
if ($_SESSION['login_attempts'] >= 5 && (time() - $_SESSION['last_attempt_time']) < 900) {
    $remaining = 900 - (time() - $_SESSION['last_attempt_time']);
    $minutes = ceil($remaining / 60);
    $error = '登录失败次数过多，请 ' . $minutes . ' 分钟后再试';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($error)) {
    $usernameOrId = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $remember = isset($_POST['remember']) && $_POST['remember'] == '1';
    
    if (empty($usernameOrId) || empty($password)) {
        $error = '用户名/ID和密码不能为空';
        $_SESSION['login_attempts']++;
        $_SESSION['last_attempt_time'] = time();
    } else {
        try {
            $pdo = Database::getConnection();
            
            $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ? OR id = ?");
            $stmt->execute([$usernameOrId, is_numeric($usernameOrId) ? (int)$usernameOrId : 0]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($user && password_verify($password, $user['password'])) {
                $banInfo = isBanned($user['id']);
                if ($banInfo) {
                    $banMsg = $banInfo['expires_at'] ? "账号被封至 " . date('Y-m-d H:i', $banInfo['expires_at']) : "账号已被永久封禁";
                    if ($banInfo['reason']) {
                        $banMsg .= "\n原因: " . $banInfo['reason'];
                    }
                    $error = $banMsg;
                } else {
                    $_SESSION['login_attempts'] = 0;
                    session_regenerate_id(true);
                    $_SESSION['user_id'] = $user['id'];
                    updateLastActivity($user['id']);
                    
                    // 记住我功能：设置 Cookie 30天
                    if ($remember) {
                        $deviceInfo = $_SERVER['HTTP_USER_AGENT'] ?? '未知设备';
                        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
                        $token = createRememberSession($user['id'], $deviceInfo, $ip);
                        if ($token) {
                            // 30天过期时间：30 * 24 * 3600 = 2592000 秒
                            $expire = time() + 2592000;
                            setcookie(REMEMBER_ME_COOKIE_NAME, $token, $expire, '/', '', false, true);
                        }
                    }
                    
                    header('Location: index.php');
                    exit;
                }
            } else {
                $error = '用户名/ID或密码错误';
                $_SESSION['login_attempts']++;
                $_SESSION['last_attempt_time'] = time();
            }
        } catch (Exception $e) {
            error_log("Login error: " . $e->getMessage());
            $error = '登录失败，请稍后重试';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <meta name="theme-color" content="#4ECDC4">
    <title>信Talk· 登录</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .attempts-warning {
            font-size: 0.8rem;
            color: #FF6B6B;
            text-align: center;
            margin-top: 0.5rem;
        }
        .error-message {
            white-space: pre-line;
        }
        .remember-me {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin: 1rem 0;
        }
        .remember-me label {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            cursor: pointer;
            color: var(--text-muted);
            font-size: 0.9rem;
        }
        .remember-me input {
            width: auto;
            margin: 0;
        }
        .auth-card {
            max-width: 400px;
            width: 90%;
            background: var(--bg-card);
            backdrop-filter: blur(20px);
            border-radius: 2rem;
            padding: 2.5rem;
            border: 2px solid #4ECDC4;
            box-shadow: 0 0 30px rgba(78, 205, 196, 0.2);
        }
        .auth-card h2 {
            color: var(--text-main);
            text-align: center;
            margin-bottom: 2rem;
            font-weight: 500;
            letter-spacing: 2px;
            text-transform: uppercase;
        }
        .auth-card input {
            width: 100%;
            padding: 1rem;
            margin-bottom: 1.2rem;
            background: rgba(255, 255, 255, 0.1);
            border: 1px solid var(--border-color);
            border-radius: 2rem;
            color: var(--text-main);
            outline: none;
            font-size: 1rem;
            transition: all 0.2s;
        }
        .auth-card input:focus {
            border-color: #4ECDC4;
            box-shadow: 0 0 0 2px rgba(78, 205, 196, 0.3);
        }
        .auth-card button {
            width: 100%;
            padding: 1rem;
            border-radius: 2rem;
            background: linear-gradient(145deg, #4ECDC4, #2C7A6F);
            border: none;
            color: white;
            font-weight: bold;
            cursor: pointer;
            border: 1px solid #4ECDC4;
            text-transform: uppercase;
            font-size: 1rem;
            transition: all 0.2s;
        }
        .auth-card button:hover {
            filter: brightness(1.1);
            transform: translateY(-2px);
        }
        .auth-card button:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            transform: none;
        }
        .auth-links {
            margin-top: 1.5rem;
            text-align: center;
            color: var(--text-muted);
            font-size: 0.9rem;
        }
        .auth-links a {
            color: #4ECDC4;
            text-decoration: none;
        }
        .auth-links a:hover {
            text-decoration: underline;
        }
        .flash-message {
            padding: 0.8rem;
            margin: 1rem 0;
            border-radius: 1rem;
            text-align: center;
        }
        .error-message {
            background: rgba(255, 107, 107, 0.15);
            color: #FF6B6B;
            border-left: 4px solid #FF6B6B;
        }
        .success-message {
            background: rgba(78, 205, 196, 0.15);
            color: #4ECDC4;
            border-left: 4px solid #4ECDC4;
        }
        @media (max-width: 768px) {
            .auth-card {
                max-width: 95%;
                padding: 1.8rem;
            }
            .auth-card h2 {
                font-size: 1.3rem;
            }
            .auth-card input {
                padding: 0.8rem;
                font-size: 16px;
            }
        }
    </style>
</head>
<body class="dark">
<div class="auth-card">
    <h2>✧ 信Talk ✧</h2>
    
    <?php if ($error): ?>
    <div class="flash-message error-message"><?= nl2br(htmlspecialchars($error)) ?></div>
    <?php endif; ?>
    
    <?php if (isset($_SESSION['flash'])): ?>
    <div class="flash-message success-message">
        <?= htmlspecialchars($_SESSION['flash']['message']) ?>
    </div>
    <?php unset($_SESSION['flash']); ?>
    <?php endif; ?>
    
    <form method="post" autocomplete="off">
        <input type="text" name="username" placeholder="用户名 / UID" required autofocus>
        <input type="password" name="password" placeholder="密码" required>
        
        <div class="remember-me">
            <label>
                <input type="checkbox" name="remember" value="1"> 
                <span>记住我（30天）</span>
            </label>
        </div>
        
        <button type="submit" <?= ($_SESSION['login_attempts'] >= 5 && (time() - $_SESSION['last_attempt_time']) < 900) ? 'disabled' : '' ?>>
            进去聊聊
        </button>
    </form>
    
    <?php if ($_SESSION['login_attempts'] > 0 && $_SESSION['login_attempts'] < 5): ?>
    <div class="attempts-warning">
        剩余尝试次数: <?= 5 - $_SESSION['login_attempts'] ?>
    </div>
    <?php endif; ?>
    
    <div class="auth-links">
        还没有账号？ <a href="register.php">注册一个</a>
    </div>
</div>
</body>
</html>