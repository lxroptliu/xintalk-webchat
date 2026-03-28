<?php
// 设置时区
date_default_timezone_set('Asia/Shanghai');

$sessionPath = __DIR__ . '/sessions';
if (!file_exists($sessionPath)) {
    mkdir($sessionPath, 0755, true);
}
session_save_path($sessionPath);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once 'functions.php';

// 如果已经登录，直接跳转到聊天室
if (isLoggedIn()) {
    header('Location: index.php');
    exit;
}

$error = '';
$success = '';

// 生成验证码（如果会话中没有）
if (!isset($_SESSION['captcha'])) {
    $_SESSION['captcha'] = [
        'num1' => rand(1, 20),
        'num2' => rand(1, 20),
        'operator' => ['+', '-', '*'][array_rand(['+', '-', '*'])]
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';
    $email = trim($_POST['email'] ?? '');
    $captcha_answer = trim($_POST['captcha_answer'] ?? '');
    
    // 验证验证码
    $captcha = $_SESSION['captcha'];
    $expected = 0;
    if ($captcha['operator'] == '+') {
        $expected = $captcha['num1'] + $captcha['num2'];
    } elseif ($captcha['operator'] == '-') {
        $expected = $captcha['num1'] - $captcha['num2'];
    } elseif ($captcha['operator'] == '*') {
        $expected = $captcha['num1'] * $captcha['num2'];
    }
    
    if ($captcha_answer != $expected) {
        $error = '验证码错误，请重新输入';
        $_SESSION['captcha'] = [
            'num1' => rand(1, 20),
            'num2' => rand(1, 20),
            'operator' => ['+', '-', '*'][array_rand(['+', '-', '*'])]
        ];
    }
    elseif (empty($username)) {
        $error = '用户名不能为空';
    } elseif (strlen($username) < 3) {
        $error = '用户名至少需要3个字符';
    } elseif (strlen($username) > 20) {
        $error = '用户名不能超过20个字符';
    } elseif (preg_match('/\d/', $username)) {
        $error = '用户名不能包含数字，只能使用字母、中文或下划线';
    } elseif (!preg_match('/^[a-zA-Z_\x7f-\xff]+$/', $username)) {
        $error = '用户名只能包含字母、中文和下划线，不能包含数字或特殊符号';
    }
    elseif (empty($password)) {
        $error = '密码不能为空';
    } elseif (strlen($password) < 4) {
        $error = '密码至少需要4个字符';
    } elseif ($password !== $confirm_password) {
        $error = '两次输入的密码不一致';
    } elseif (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = '邮箱格式不正确';
    } else {
        try {
            $pdo = Database::getConnection();
            
            $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
            $stmt->execute([$username]);
            if ($stmt->fetch()) {
                $error = '用户名已被使用';
            } else {
                if (!empty($email)) {
                    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
                    $stmt->execute([$email]);
                    if ($stmt->fetch()) {
                        $error = '邮箱已被使用';
                    }
                }
                
                if (empty($error)) {
                    $hash = password_hash($password, PASSWORD_DEFAULT);
                    $avatar = null;
                    
                    $stmt = $pdo->prepare("INSERT INTO users (username, password, email, avatar, role) VALUES (?, ?, ?, ?, 'user')");
                    if ($stmt->execute([$username, $hash, $email ?: null, $avatar])) {
                        unset($_SESSION['captcha']);
                        $_SESSION['flash'] = [
                            'message' => '🎉 注册成功！请登录',
                            'type' => 'success'
                        ];
                        header('Location: login.php');
                        exit;
                    } else {
                        $error = '注册失败，请稍后重试';
                    }
                }
            }
        } catch (PDOException $e) {
            error_log("Register error: " . $e->getMessage());
            $error = '数据库错误，请稍后重试';
        }
    }
    
    if ($error) {
        $_SESSION['captcha'] = [
            'num1' => rand(1, 20),
            'num2' => rand(1, 20),
            'operator' => ['+', '-', '*'][array_rand(['+', '-', '*'])]
        ];
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>信Talk· 注册</title>
    <link rel="stylesheet" href="style.css">
    <style>
        body {
            background: var(--bg-deep);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1rem;
            margin: 0;
        }
        
        .auth-card {
            max-width: 450px;
            width: 100%;
            margin: 0 auto;
            border: 2px solid #4ECDC4;
        }
        
        .password-hint {
            font-size: 0.75rem;
            color: var(--text-muted);
            margin-top: -0.8rem;
            margin-bottom: 1rem;
            text-align: left;
            padding-left: 0.5rem;
        }
        
        .terms {
            margin: 1.2rem 0;
            font-size: 0.9rem;
            text-align: center;
        }
        
        .terms label {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            cursor: pointer;
            color: var(--text-muted);
        }
        
        .terms input {
            width: auto;
            margin: 0;
            display: inline-block;
        }
        
        /* 验证码区域 - 强制修复 */
        .captcha-container {
            background: rgba(78, 205, 196, 0.1);
            border-radius: 1rem;
            padding: 1rem;
            margin-bottom: 1.2rem;
            border: 1px solid rgba(78, 205, 196, 0.3);
        }
        
        .captcha-question {
            font-size: 1.2rem;
            text-align: center;
            margin-bottom: 1rem;
            color: var(--text-main);
            font-weight: bold;
            background: rgba(0, 0, 0, 0.2);
            padding: 0.5rem;
            border-radius: 0.8rem;
        }
        
        .captcha-question span {
            color: #4ECDC4;
            font-size: 1.3rem;
            font-family: monospace;
        }
        
        .captcha-input {
            display: flex;
            gap: 0.8rem;
            align-items: center;
            width: 100%;
        }
        
        /* 强制修复输入框样式 */
        .captcha-input input {
            flex: 1;
            min-width: 0;
            margin: 0 !important;
            text-align: center;
            font-size: 1rem !important;
            padding: 0.8rem 1rem !important;
            background: var(--input-bg) !important;
            border: 1px solid var(--border-color) !important;
            border-radius: 2rem !important;
            color: var(--text-main) !important;
            outline: none !important;
            width: auto !important;
            box-sizing: border-box !important;
            display: block !important;
        }
        
        .captcha-input input:focus {
            border-color: #4ECDC4 !important;
            box-shadow: 0 0 0 2px rgba(78, 205, 196, 0.3) !important;
        }
        
        /* 强制修复输入框聚焦状态 */
        .captcha-input input:focus-visible {
            outline: none !important;
        }
        
        .refresh-captcha {
            width: 44px !important;
            height: 44px !important;
            min-width: 44px !important;
            background: transparent !important;
            border: 2px solid #4ECDC4 !important;
            color: #4ECDC4 !important;
            border-radius: 50% !important;
            cursor: pointer !important;
            font-size: 1.2rem !important;
            transition: all 0.2s !important;
            flex-shrink: 0 !important;
            display: flex !important;
            align-items: center !important;
            justify-content: center !important;
            background-color: rgba(78, 205, 196, 0.1) !important;
            padding: 0 !important;
            margin: 0 !important;
        }
        
        .refresh-captcha:hover {
            background: #4ECDC4 !important;
            color: white !important;
            transform: rotate(15deg);
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
            .captcha-question {
                font-size: 1rem;
                padding: 0.4rem;
            }
            .captcha-question span {
                font-size: 1.1rem;
            }
            .refresh-captcha {
                width: 40px !important;
                height: 40px !important;
                min-width: 40px !important;
                font-size: 1rem !important;
            }
            .captcha-input input {
                padding: 0.7rem 0.8rem !important;
                font-size: 0.95rem !important;
            }
        }
    </style>
</head>
<body class="dark">
<div class="auth-card">
    <h2>✧ 信Talk ✧</h2>
    
    <?php if ($error): ?>
    <div class="flash-message error-message"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    
    <?php if ($success): ?>
    <div class="flash-message success-message"><?= htmlspecialchars($success) ?></div>
    <?php endif; ?>
    
    <form method="post" autocomplete="off" id="registerForm">
        <input type="text" 
               name="username" 
               placeholder="用户名" 
               value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
               required 
               autofocus
               minlength="3"
               maxlength="20">
        <div class="password-hint">📝 3-20个字符，只能包含字母、中文或下划线，不能包含数字</div>
        
        <input type="email" 
               name="email" 
               placeholder="邮箱 (可选)" 
               value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
        <div class="password-hint">用于找回密码和头像服务</div>
        
        <input type="password" 
               name="password" 
               placeholder="密码" 
               required
               minlength="4">
        
        <input type="password" 
               name="confirm_password" 
               placeholder="确认密码" 
               required
               minlength="4">
        <div class="password-hint">🔒 至少4个字符</div>
        
        <div class="captcha-container">
            <div class="captcha-question">
                🔒 验证码：<span id="captchaQuestion"><?= $_SESSION['captcha']['num1'] ?> <?= $_SESSION['captcha']['operator'] ?> <?= $_SESSION['captcha']['num2'] ?> = ?</span>
            </div>
            <div class="captcha-input">
                <input type="text" 
                       name="captcha_answer" 
                       id="captcha_answer"
                       placeholder="输入计算结果" 
                       required 
                       autocomplete="off"
                       inputmode="numeric">
                <button type="button" class="refresh-captcha" id="refreshCaptchaBtn" aria-label="刷新验证码">⟳</button>
            </div>
        </div>
        
        <div class="terms">
            <label>
                <input type="checkbox" name="agree" required> 
                <span>我已阅读并同意服务条款</span>
            </label>
        </div>
        
        <button type="submit">注册</button>
    </form>
    
    <div class="auth-links">
        已有账号？ <a href="login.php">去登录</a>
    </div>
</div>

<script>
function refreshCaptcha() {
    var xhr = new XMLHttpRequest();
    xhr.open('GET', 'api/refresh_captcha.php', true);
    xhr.onreadystatechange = function() {
        if (xhr.readyState === 4 && xhr.status === 200) {
            try {
                var data = JSON.parse(xhr.responseText);
                if (data.success) {
                    var questionSpan = document.getElementById('captchaQuestion');
                    if (questionSpan) {
                        questionSpan.innerHTML = data.num1 + ' ' + data.operator + ' ' + data.num2 + ' = ?';
                    }
                    var captchaInput = document.getElementById('captcha_answer');
                    if (captchaInput) {
                        captchaInput.value = '';
                        captchaInput.focus();
                    }
                }
            } catch(e) {}
        }
    };
    xhr.send();
}

document.getElementById('registerForm')?.addEventListener('submit', function(e) {
    var username = document.querySelector('input[name="username"]').value;
    var password = document.querySelector('input[name="password"]').value;
    var confirm = document.querySelector('input[name="confirm_password"]').value;
    var agree = document.querySelector('input[name="agree"]').checked;
    var captcha = document.querySelector('input[name="captcha_answer"]').value;
    
    if (!agree) {
        e.preventDefault();
        alert('请同意服务条款');
        return false;
    }
    
    if (/\d/.test(username)) {
        e.preventDefault();
        alert('用户名不能包含数字，只能使用字母、中文或下划线');
        return false;
    }
    
    if (!/^[a-zA-Z_\u4e00-\u9fa5]+$/.test(username)) {
        e.preventDefault();
        alert('用户名只能包含字母、中文和下划线，不能包含数字或特殊符号');
        return false;
    }
    
    if (username.length < 3) {
        e.preventDefault();
        alert('用户名至少需要3个字符');
        return false;
    }
    
    if (username.length > 20) {
        e.preventDefault();
        alert('用户名不能超过20个字符');
        return false;
    }
    
    if (password.length < 4) {
        e.preventDefault();
        alert('密码至少需要4个字符');
        return false;
    }
    
    if (password !== confirm) {
        e.preventDefault();
        alert('两次输入的密码不一致');
        return false;
    }
    
    if (!captcha) {
        e.preventDefault();
        alert('请输入验证码');
        return false;
    }
    
    return true;
});

document.addEventListener('DOMContentLoaded', function() {
    var refreshBtn = document.getElementById('refreshCaptchaBtn');
    if (refreshBtn) {
        refreshBtn.addEventListener('click', function(e) {
            e.preventDefault();
            refreshCaptcha();
        });
    }
});
</script>
</body>
</html>