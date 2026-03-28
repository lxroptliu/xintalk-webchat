<?php
require_once 'functions.php';
if (!isLoggedIn()) {
    header('Location: login.php');
    exit;
}

$user = currentUser();
$pdo = Database::getConnection();
$message = '';
$error = '';

// 处理头像上传
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['avatar'])) {
    $result = handleAvatarUpload($_FILES['avatar'], $user['id']);
    if ($result['success']) {
        $stmt = $pdo->prepare("UPDATE users SET avatar = ? WHERE id = ?");
        $stmt->execute([$result['filename'], $user['id']]);
        $message = '头像已更新';
        $user = currentUser();
    } else {
        $error = $result['error'];
    }
}

// 处理称号修改
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_title'])) {
    $newTitle = trim($_POST['title'] ?? '');
    if (strlen($newTitle) > 30) {
        $error = '称号不能超过30个字符';
    } else {
        $stmt = $pdo->prepare("UPDATE users SET title = ? WHERE id = ?");
        if ($stmt->execute([$newTitle, $user['id']])) {
            $message = '称号已更新';
            $user = currentUser();
        } else {
            $error = '更新失败';
        }
    }
}

// ========== 修改用户名（新增：不能包含数字） ==========
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_username'])) {
    $current = $_POST['current_password'] ?? '';
    $new_username = trim($_POST['new_username'] ?? '');

    if (strlen($new_username) < 3) {
        $error = '用户名至少需要3个字符';
    } elseif (strlen($new_username) > 20) {
        $error = '用户名不能超过20个字符';
    } elseif (preg_match('/\d/', $new_username)) {
        $error = '用户名不能包含数字，只能使用字母、中文或下划线';
    } elseif (!preg_match('/^[a-zA-Z_\x7f-\xff]+$/', $new_username)) {
        $error = '用户名只能包含字母、中文和下划线，不能包含数字或特殊符号';
    } elseif (!password_verify($current, $user['password'])) {
        $error = '当前密码错误';
    } else {
        $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ? AND id != ?");
        $stmt->execute([$new_username, $user['id']]);
        if ($stmt->fetch()) {
            $error = '用户名已被使用';
        } else {
            $stmt = $pdo->prepare("UPDATE users SET username = ? WHERE id = ?");
            if ($stmt->execute([$new_username, $user['id']])) {
                $stmt = $pdo->prepare("UPDATE messages SET username = ? WHERE user_id = ?");
                $stmt->execute([$new_username, $user['id']]);
                $message = '用户名已更新';
                $user = currentUser();
            } else {
                $error = '更新失败';
            }
        }
    }
}

// 处理密码/邮箱修改
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    $current = $_POST['current_password'] ?? '';
    $new = $_POST['new_password'] ?? '';
    $email = $_POST['email'] ?? $user['email'];

    if (!password_verify($current, $user['password'])) {
        $error = '当前密码错误';
    } else {
        $stmt = $pdo->prepare("UPDATE users SET email = ? WHERE id = ?");
        $stmt->execute([$email, $user['id']]);

        if (!empty($new)) {
            $hash = password_hash($new, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE id = ?");
            $stmt->execute([$hash, $user['id']]);
        }
        $message = '资料已更新';
        $user = currentUser();
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>信Talk · 我的资料</title>
    <link rel="stylesheet" href="style.css">
    <style>
        /* 保持原有样式，只添加修改用户名表单的提示样式 */
        .username-hint {
            font-size: 0.7rem;
            color: var(--text-muted);
            margin-bottom: 0.5rem;
            margin-top: -0.3rem;
        }
        /* 其他样式保持不变 */
    </style>
</head>
<body class="dark">
<div class="auth-card" style="max-width:600px;">
    <h2>✧ 我的资料 ✧</h2>

    <?php if ($message): ?>
    <div class="flash-message success-message" style="background:rgba(78,205,196,0.15); color:#4ECDC4;"><?= $message ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
    <div class="flash-message error-message"><?= $error ?></div>
    <?php endif; ?>

    <!-- UID 显示 -->
    <div class="uid-display">
        <div class="uid-label">用户ID (UID)</div>
        <div class="uid-value">
            #<?= $user['id'] ?>
            <button class="copy-uid" onclick="copyUID(<?= $user['id'] ?>)">复制</button>
        </div>
    </div>

    <!-- 当前头像显示 -->
    <div class="avatar-upload-area">
        <div class="avatar-preview">
            <img src="<?= getAvatarUrl($user['avatar'], $user['username']) ?>" alt="avatar">
        </div>
        <div style="margin-top:0.5rem; color:#4ECDC4;"><?= htmlspecialchars($user['username']) ?></div>
        <?php if (!empty($user['title'])): ?>
        <div style="margin-top:0.3rem; font-size:0.9rem; color:var(--text-muted);">称号：<?= htmlspecialchars($user['title']) ?></div>
        <?php endif; ?>
    </div>

    <!-- 称号修改表单 -->
    <form method="post" style="margin-bottom:2rem; padding:1rem; background:rgba(78,205,196,0.1); border-radius:1rem;">
        <input type="hidden" name="update_title" value="1">
        <h3>✧ 我的称号 ✧</h3>
        <div style="margin-bottom:0.5rem; color:var(--text-muted);">当前称号：<?= htmlspecialchars($user['title'] ?: '无') ?></div>
        <input type="text" name="title" class="title-input" placeholder="输入称号（最多30字）" maxlength="30" value="<?= htmlspecialchars($user['title']) ?>">
        <button type="submit">更新称号</button>
    </form>

    <!-- 头像上传表单 -->
    <form method="post" enctype="multipart/form-data" class="avatar-upload-form">
        <h3>📸 换头像</h3>
        <input type="file" name="avatar" accept="image/jpeg,image/png,image/gif,image/webp" required>
        <div class="hint">支持 JPG、PNG、GIF、WEBP，最大2MB</div>
        <button type="submit" style="background:transparent; border:1px solid #4ECDC4; color:#4ECDC4; padding:0.6rem 1.5rem; width:auto;">上传</button>
    </form>

    <!-- 修改用户名表单（已添加验证） -->
    <form method="post" style="margin-bottom:2rem; padding:1rem; background:rgba(78,205,196,0.1); border-radius:1rem;">
        <input type="hidden" name="change_username" value="1">
        <h3>✧ 改用户名 ✧</h3>
        <div style="margin-bottom:0.5rem; color:var(--text-muted);">当前：<?= htmlspecialchars($user['username']) ?></div>
        <input type="text" 
               name="new_username" 
               placeholder="新用户名" 
               required 
               pattern="[a-zA-Z_\u4e00-\u9fa5]+" 
               title="只能包含字母、中文和下划线，不能包含数字"
               minlength="3"
               maxlength="20"
               value="">
        <div class="username-hint">📝 3-20个字符，只能包含字母、中文或下划线，不能包含数字</div>
        <input type="password" name="current_password" placeholder="当前密码" required>
        <button type="submit">修改</button>
    </form>

    <!-- 资料修改表单 -->
    <form method="post">
        <input type="hidden" name="update_profile" value="1">
        <input type="email" name="email" placeholder="邮箱" value="<?= htmlspecialchars($user['email'] ?? '') ?>">
        <input type="password" name="current_password" placeholder="当前密码" required>
        <input type="password" name="new_password" placeholder="新密码 (留空则不修改)">
        <button type="submit">保存</button>
    </form>

    <div class="auth-links">
        <a href="index.php">← 回去</a>
    </div>
</div>

<script>
function copyUID(uid) {
    navigator.clipboard.writeText(uid).then(function() {
        alert('UID已复制: ' + uid);
    }, function() {
        prompt('手动复制 UID:', uid);
    });
}
</script>
</body>
</html>