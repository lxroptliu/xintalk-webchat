<?php
require_once 'functions.php';

// 检查登录和管理员权限
if (!isLoggedIn() || !isAdmin()) {
    header('Location: index.php');
    exit;
}

$pdo = Database::getConnection();
$isSuper = isSuperAdmin();
$isDeputy = ($_SESSION['user_id'] == 2);
$canBan = canBan();
$canMaintain = canMaintain();
$canClean = ($_SESSION['user_id'] == 1 || $_SESSION['user_id'] == 2); // 只有UID=1或2可以清理图片
$flashMessage = null;

// 辅助函数：安全显示时间
function safeDate($timestamp, $format = 'Y-m-d H:i') {
    if (empty($timestamp)) return '-';
    if (is_numeric($timestamp)) {
        return date($format, $timestamp);
    }
    return htmlspecialchars($timestamp);
}

// 处理操作
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    switch ($_POST['action']) {
        case 'delete_user':
            $userId = (int)($_POST['user_id'] ?? 0);
            if (isProtectedUser($userId)) {
                $_SESSION['flash'] = ['message' => '无法删除受保护账户', 'type' => 'error'];
            } elseif ($userId != $_SESSION['user_id']) {
                $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
                $stmt->execute([$userId]);
                $_SESSION['flash'] = ['message' => '用户已删除', 'type' => 'success'];
                logAdminAction('delete_user', $userId);
            } else {
                $_SESSION['flash'] = ['message' => '不能删自己', 'type' => 'error'];
            }
            break;
            
        case 'set_role':
            $userId = (int)($_POST['user_id'] ?? 0);
            $role = $_POST['role'] ?? 'user';
            
            if (!$isSuper && !$isDeputy) {
                $_SESSION['flash'] = ['message' => '只有超级管理员或副号可以修改用户角色', 'type' => 'error'];
            }
            elseif (isProtectedUser($userId)) {
                $_SESSION['flash'] = ['message' => '无法修改受保护账户权限', 'type' => 'error'];
            }
            elseif ($userId == $_SESSION['user_id']) {
                $_SESSION['flash'] = ['message' => '不能改自己', 'type' => 'error'];
            }
            elseif (in_array($role, ['user', 'admin'])) {
                $stmt = $pdo->prepare("UPDATE users SET role = ? WHERE id = ?");
                $stmt->execute([$role, $userId]);
                $_SESSION['flash'] = ['message' => '用户角色已更新', 'type' => 'success'];
                logAdminAction('set_role', $userId, "新角色: $role");
            } else {
                $_SESSION['flash'] = ['message' => '无效角色', 'type' => 'error'];
            }
            break;
            
        case 'clear_messages':
            // 只有 UID=1 或 UID=2 可以清理（因为会删除图片文件）
            if (!$canClean) {
                $_SESSION['flash'] = ['message' => '只有超级管理员或副号可以清理聊天记录（会删除图片文件）', 'type' => 'error'];
            } else {
                // 获取所有要删除的图片文件路径
                $stmt = $pdo->query("SELECT content FROM messages WHERE content LIKE 'uploads/images/%'");
                $images = $stmt->fetchAll(PDO::FETCH_COLUMN);
                $deletedImages = 0;
                
                // 删除图片文件
                foreach ($images as $imagePath) {
                    $fullPath = __DIR__ . '/' . $imagePath;
                    if (file_exists($fullPath)) {
                        if (unlink($fullPath)) {
                            $deletedImages++;
                        }
                    }
                }
                
                // 清空消息记录
                $pdo->exec("DELETE FROM messages");
                
                $_SESSION['flash'] = [
                    'message' => "聊天记录已清空，同时删除了 $deletedImages 个图片文件", 
                    'type' => 'success'
                ];
                logAdminAction('clear_messages', null, "删除图片文件数: $deletedImages");
            }
            break;
            
        case 'clear_private_messages':
            if (!$canClean) {
                $_SESSION['flash'] = ['message' => '只有超级管理员或副号可以清理私聊记录（会删除图片文件）', 'type' => 'error'];
            } else {
                // 获取所有要删除的私聊图片文件
                $stmt = $pdo->query("SELECT content FROM private_messages WHERE content LIKE 'uploads/images/%'");
                $images = $stmt->fetchAll(PDO::FETCH_COLUMN);
                $deletedImages = 0;
                
                foreach ($images as $imagePath) {
                    $fullPath = __DIR__ . '/' . $imagePath;
                    if (file_exists($fullPath)) {
                        if (unlink($fullPath)) {
                            $deletedImages++;
                        }
                    }
                }
                
                $pdo->exec("DELETE FROM private_messages");
                $_SESSION['flash'] = [
                    'message' => "私聊记录已清空，同时删除了 $deletedImages 个图片文件", 
                    'type' => 'success'
                ];
                logAdminAction('clear_private_messages', null, "删除图片文件数: $deletedImages");
            }
            break;
            
        case 'clean_orphan_images':
            if (!$canClean) {
                $_SESSION['flash'] = ['message' => '只有超级管理员或副号可以清理孤儿图片', 'type' => 'error'];
            } else {
                // 获取所有在消息中使用的图片
                $stmt = $pdo->query("SELECT content FROM messages WHERE content LIKE 'uploads/images/%' 
                                      UNION SELECT content FROM private_messages WHERE content LIKE 'uploads/images/%'");
                $usedImages = $stmt->fetchAll(PDO::FETCH_COLUMN);
                $usedSet = array_flip($usedImages);
                
                // 扫描上传目录
                $uploadDir = __DIR__ . '/uploads/images/';
                $deleted = 0;
                $totalSize = 0;
                
                if (is_dir($uploadDir)) {
                    $files = scandir($uploadDir);
                    foreach ($files as $file) {
                        if ($file != '.' && $file != '..') {
                            $filePath = $uploadDir . $file;
                            $relativePath = 'uploads/images/' . $file;
                            if (!isset($usedSet[$relativePath])) {
                                $totalSize += filesize($filePath);
                                if (unlink($filePath)) {
                                    $deleted++;
                                }
                            }
                        }
                    }
                }
                
                $sizeStr = $totalSize > 1024 * 1024 ? round($totalSize / 1024 / 1024, 2) . 'MB' : round($totalSize / 1024, 2) . 'KB';
                $_SESSION['flash'] = [
                    'message' => "已清理 $deleted 个孤儿图片文件，释放空间约 $sizeStr", 
                    'type' => 'success'
                ];
                logAdminAction('clean_orphan_images', null, "清理文件数: $deleted, 释放空间: $sizeStr");
            }
            break;
            
        case 'backup_database':
            if (!$canMaintain) {
                $_SESSION['flash'] = ['message' => '只有超级管理员可以备份数据库', 'type' => 'error'];
            } else {
                $backupDir = __DIR__ . '/database/';
                $backupFile = $backupDir . 'backup_' . date('Y-m-d_H-i-s') . '.db';
                if (copy(DB_PATH, $backupFile)) {
                    $_SESSION['flash'] = ['message' => '数据库备份成功: ' . basename($backupFile), 'type' => 'success'];
                    logAdminAction('backup_database', null, "文件: " . basename($backupFile));
                } else {
                    $_SESSION['flash'] = ['message' => '备份失败', 'type' => 'error'];
                }
            }
            break;
            
        case 'clear_uploads':
            if (!$canClean) {
                $_SESSION['flash'] = ['message' => '只有超级管理员或副号可以清理上传文件', 'type' => 'error'];
            } else {
                $uploadDir = __DIR__ . '/uploads/';
                $deleted = 0;
                $totalSize = 0;
                if (is_dir($uploadDir)) {
                    $files = scandir($uploadDir);
                    foreach ($files as $file) {
                        if ($file != '.' && $file != '..' && $file != 'defaults' && !is_dir($uploadDir . $file)) {
                            $totalSize += filesize($uploadDir . $file);
                            if (unlink($uploadDir . $file)) {
                                $deleted++;
                            }
                        }
                    }
                }
                $sizeStr = $totalSize > 1024 * 1024 ? round($totalSize / 1024 / 1024, 2) . 'MB' : round($totalSize / 1024, 2) . 'KB';
                $_SESSION['flash'] = ['message' => "已清理 $deleted 个上传文件，释放空间约 $sizeStr", 'type' => 'success'];
                logAdminAction('clear_uploads', null, "删除文件数: $deleted, 释放空间: $sizeStr");
            }
            break;
            
        case 'optimize_db':
            if (!$canMaintain) {
                $_SESSION['flash'] = ['message' => '只有超级管理员可以优化数据库', 'type' => 'error'];
            } else {
                $pdo->exec("VACUUM");
                $_SESSION['flash'] = ['message' => '数据库优化完成', 'type' => 'success'];
                logAdminAction('optimize_db');
            }
            break;
            
        case 'ban_user':
            if (!$canBan) {
                $_SESSION['flash'] = ['message' => '无权限封禁用户', 'type' => 'error'];
            } else {
                $userId = (int)($_POST['user_id'] ?? 0);
                $hours = (int)($_POST['hours'] ?? 0);
                $reason = trim($_POST['reason'] ?? '');
                
                if (isProtectedUser($userId)) {
                    $_SESSION['flash'] = ['message' => '不能封禁受保护账户', 'type' => 'error'];
                } elseif ($userId == $_SESSION['user_id']) {
                    $_SESSION['flash'] = ['message' => '不能封自己', 'type' => 'error'];
                } else {
                    $pdo->prepare("DELETE FROM banned_users WHERE user_id = ?")->execute([$userId]);
                    $expires = $hours > 0 ? time() + ($hours * 3600) : null;
                    $stmt = $pdo->prepare("INSERT INTO banned_users (user_id, reason, expires_at, banned_by) VALUES (?, ?, ?, ?)");
                    if ($stmt->execute([$userId, $reason, $expires, $_SESSION['user_id']])) {
                        $_SESSION['flash'] = ['message' => '用户已封禁', 'type' => 'success'];
                        logAdminAction('ban_user', $userId, "原因: $reason, 时长: {$hours}小时");
                    } else {
                        $_SESSION['flash'] = ['message' => '封禁失败', 'type' => 'error'];
                    }
                }
            }
            break;
            
        case 'unban_user':
            if (!$canBan) {
                $_SESSION['flash'] = ['message' => '无权限解封用户', 'type' => 'error'];
            } else {
                $userId = (int)($_POST['user_id'] ?? 0);
                $stmt = $pdo->prepare("DELETE FROM banned_users WHERE user_id = ?");
                if ($stmt->execute([$userId])) {
                    $_SESSION['flash'] = ['message' => '用户已解封', 'type' => 'success'];
                    logAdminAction('unban_user', $userId);
                } else {
                    $_SESSION['flash'] = ['message' => '解封失败', 'type' => 'error'];
                }
            }
            break;
            
        case 'add_sensitive_word':
            if (!$isSuper) {
                $_SESSION['flash'] = ['message' => '无权限', 'type' => 'error'];
            } else {
                $word = trim($_POST['word'] ?? '');
                $replacement = trim($_POST['replacement'] ?? '***');
                if (empty($word)) {
                    $_SESSION['flash'] = ['message' => '敏感词不能为空', 'type' => 'error'];
                } else {
                    $stmt = $pdo->prepare("INSERT INTO sensitive_words (word, replacement) VALUES (?, ?)");
                    if ($stmt->execute([$word, $replacement])) {
                        $_SESSION['flash'] = ['message' => '敏感词已添加', 'type' => 'success'];
                        logAdminAction('add_sensitive_word', null, "词: $word");
                    } else {
                        $_SESSION['flash'] = ['message' => '添加失败，可能已存在', 'type' => 'error'];
                    }
                }
            }
            break;
            
        case 'delete_sensitive_word':
            if (!$isSuper) {
                $_SESSION['flash'] = ['message' => '无权限', 'type' => 'error'];
            } else {
                $id = (int)($_POST['id'] ?? 0);
                $stmt = $pdo->prepare("DELETE FROM sensitive_words WHERE id = ?");
                if ($stmt->execute([$id])) {
                    $_SESSION['flash'] = ['message' => '敏感词已删除', 'type' => 'success'];
                    logAdminAction('delete_sensitive_word', $id);
                } else {
                    $_SESSION['flash'] = ['message' => '删除失败', 'type' => 'error'];
                }
            }
            break;
            
        case 'send_announcement':
            if (!$isSuper) {
                $_SESSION['flash'] = ['message' => '只有超级管理员可以发送公告', 'type' => 'error'];
            } else {
                $title = trim($_POST['title'] ?? '');
                $content = trim($_POST['content'] ?? '');
                $expires = $_POST['expires'] ? strtotime($_POST['expires']) : null;
                if (empty($title) || empty($content)) {
                    $_SESSION['flash'] = ['message' => '标题和内容不能为空', 'type' => 'error'];
                } else {
                    $stmt = $pdo->prepare("INSERT INTO announcements (title, content, created_by, expires_at) VALUES (?, ?, ?, ?)");
                    if ($stmt->execute([$title, $content, $_SESSION['user_id'], $expires])) {
                        $_SESSION['flash'] = ['message' => '公告已发送', 'type' => 'success'];
                        logAdminAction('send_announcement', null, "标题: $title");
                    } else {
                        $_SESSION['flash'] = ['message' => '发送失败', 'type' => 'error'];
                    }
                }
            }
            break;
            
        case 'set_rate_limit':
            if (!$isSuper) {
                $_SESSION['flash'] = ['message' => '只有超级管理员可以修改频率限制', 'type' => 'error'];
            } else {
                $limit = (int)($_POST['limit'] ?? 0);
                $window = (int)($_POST['window'] ?? 0);
                if ($limit > 0 && $window > 0) {
                    $stmt = $pdo->prepare("UPDATE system_config SET value = ? WHERE key = 'rate_limit'");
                    $stmt->execute([$limit]);
                    $stmt = $pdo->prepare("UPDATE system_config SET value = ? WHERE key = 'rate_limit_window'");
                    $stmt->execute([$window]);
                    $_SESSION['flash'] = ['message' => "频率限制已更新: {$limit}条/{$window}秒", 'type' => 'success'];
                    logAdminAction('set_rate_limit', null, "限制: {$limit}/{$window}");
                } else {
                    $_SESSION['flash'] = ['message' => '参数无效', 'type' => 'error'];
                }
            }
            break;
    }
    
    header('Location: admin.php');
    exit;
}

// 获取统计信息
try {
    $userCount = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
    $messageCount = $pdo->query("SELECT COUNT(*) FROM messages")->fetchColumn();
    $privateCount = $pdo->query("SELECT COUNT(*) FROM private_messages")->fetchColumn();
    $adminCount = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn();
    $now = time();
    $bannedCount = $pdo->query("SELECT COUNT(*) FROM banned_users WHERE expires_at IS NULL OR expires_at > $now")->fetchColumn();
    
    // 获取图片文件统计
    $imageDir = __DIR__ . '/uploads/images/';
    $imageCount = 0;
    $imageSize = 0;
    if (is_dir($imageDir)) {
        $files = scandir($imageDir);
        foreach ($files as $file) {
            if ($file != '.' && $file != '..') {
                $imageCount++;
                $imageSize += filesize($imageDir . $file);
            }
        }
    }
} catch (Exception $e) {
    $userCount = 0;
    $messageCount = 0;
    $privateCount = 0;
    $adminCount = 0;
    $bannedCount = 0;
    $imageCount = 0;
    $imageSize = 0;
}

// 获取所有用户
$users = $pdo->query("SELECT * FROM users ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);

// 获取服务器状态
$serverStatus = getServerStatus();

// 获取频率限制配置
$rateLimit = $pdo->query("SELECT value FROM system_config WHERE key = 'rate_limit'")->fetchColumn();
$rateLimitWindow = $pdo->query("SELECT value FROM system_config WHERE key = 'rate_limit_window'")->fetchColumn();
$rateLimit = $rateLimit ?: 10;
$rateLimitWindow = $rateLimitWindow ?: 60;

// 获取敏感词列表
$sensitiveWords = $pdo->query("SELECT id, word, replacement FROM sensitive_words ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);

// 获取封禁用户列表
$bannedUsers = $pdo->query("SELECT bu.*, u.username as user_name 
                            FROM banned_users bu 
                            JOIN users u ON bu.user_id = u.id 
                            WHERE bu.expires_at IS NULL OR bu.expires_at > $now
                            ORDER BY bu.id DESC")->fetchAll(PDO::FETCH_ASSOC);

// 获取操作日志
$adminLogs = $pdo->query("SELECT al.*, u.username as admin_name 
                          FROM admin_logs al 
                          JOIN users u ON al.admin_id = u.id 
                          ORDER BY al.id DESC LIMIT 50")->fetchAll(PDO::FETCH_ASSOC);

// 获取闪存消息
if (isset($_SESSION['flash'])) {
    $flashMessage = $_SESSION['flash'];
    unset($_SESSION['flash']);
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>信Talk· 管理</title>
    <link rel="stylesheet" href="style.css">
    <style>
        body { background: var(--bg-deep); padding: 1rem; margin: 0; font-family: 'Segoe UI', sans-serif; }
        .admin-container { max-width: 1400px; width: 100%; margin: 0 auto; background: var(--bg-card); backdrop-filter: blur(20px); border-radius: 2rem; border: 2px solid #4ECDC4; overflow: hidden; }
        .admin-header { padding: 1rem 2rem; background: rgba(0,0,0,0.2); border-bottom: 2px solid #4ECDC4; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 1rem; }
        .admin-header h1 { color: #4ECDC4; font-size: 1.5rem; margin: 0; display: flex; align-items: center; gap: 0.5rem; }
        .back-link { color: #4ECDC4; text-decoration: none; padding: 0.5rem 1rem; border: 1px solid #4ECDC4; border-radius: 2rem; }
        .back-link:hover { background: #4ECDC4; color: var(--bg-deep); }
        .flash-message { margin: 1rem 1.5rem; padding: 0.8rem 1rem; border-radius: 1rem; }
        .flash-message.success { background: rgba(78,205,196,0.15); color: #4ECDC4; border-left: 4px solid #4ECDC4; }
        .flash-message.error { background: rgba(255,107,107,0.15); color: #FF6B6B; border-left: 4px solid #FF6B6B; }
        .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 1rem; padding: 1.5rem; background: rgba(0,0,0,0.1); }
        .stat-card { background: rgba(78,205,196,0.1); border-radius: 1rem; padding: 1rem; text-align: center; border: 1px solid rgba(78,205,196,0.3); }
        .stat-number { font-size: 1.8rem; font-weight: bold; color: #4ECDC4; }
        .stat-label { font-size: 0.8rem; color: var(--text-muted); margin-top: 0.3rem; }
        .section-title { color: #4ECDC4; font-size: 1.2rem; margin-bottom: 1rem; border-left: 3px solid #4ECDC4; padding-left: 0.8rem; }
        .admin-card { background: rgba(0,0,0,0.2); border-radius: 1rem; padding: 1.5rem; margin-bottom: 1.5rem; }
        .form-group { margin-bottom: 1rem; }
        .form-group label { display: block; margin-bottom: 0.5rem; color: var(--text-muted); }
        .form-group input, .form-group select, .form-group textarea { width: 100%; padding: 0.8rem; border-radius: 1rem; border: 1px solid var(--border-color); background: var(--input-bg); color: var(--text-main); }
        .admin-btn { padding: 0.6rem 1.2rem; border-radius: 2rem; cursor: pointer; font-size: 0.9rem; border: none; display: inline-flex; align-items: center; gap: 0.5rem; }
        .admin-btn.primary { background: #4ECDC4; color: white; }
        .admin-btn.danger { background: #6b2e2e; color: white; }
        .admin-btn.secondary { background: transparent; border: 1px solid #4ECDC4; color: #4ECDC4; }
        .admin-btn.warning { background: #b86f2c; color: white; }
        .action-buttons { display: flex; gap: 1rem; flex-wrap: wrap; margin-bottom: 1rem; }
        .table-wrapper { overflow-x: auto; border-radius: 1rem; background: rgba(0,0,0,0.2); margin-top: 1rem; }
        .admin-table { width: 100%; border-collapse: collapse; min-width: 600px; }
        .admin-table th { background: rgba(78,205,196,0.2); padding: 1rem; border-bottom: 2px solid #4ECDC4; text-align: left; }
        .admin-table td { padding: 0.8rem 1rem; border-bottom: 1px solid var(--border-color); }
        .admin-table tr:hover { background: rgba(255,255,255,0.05); }
        .admin-table select { background: var(--bg-card); color: var(--text-main); border: 1px solid #4ECDC4; border-radius: 0.5rem; padding: 0.3rem; }
        .admin-table button { padding: 0.3rem 0.8rem; border-radius: 1.5rem; border: 1px solid #4ECDC4; background: transparent; color: #4ECDC4; cursor: pointer; margin-right: 0.3rem; font-size: 0.8rem; }
        .admin-table button:hover { background: #4ECDC4; color: var(--bg-deep); }
        .admin-table .delete-btn { border-color: #FF6B6B; color: #FF6B6B; }
        .protected-badge { background: #4ECDC4; color: white; padding: 0.2rem 0.5rem; border-radius: 1rem; font-size: 0.7rem; margin-left: 0.5rem; }
        .super-badge { background: gold; color: #1a1a2e; padding: 0.2rem 0.5rem; border-radius: 1rem; font-size: 0.7rem; margin-left: 0.5rem; }
        .deputy-badge { background: #4ECDC4; color: white; padding: 0.2rem 0.5rem; border-radius: 1rem; font-size: 0.7rem; margin-left: 0.5rem; }
        .banned-badge { background: #6b2e2e; color: white; padding: 0.2rem 0.5rem; border-radius: 1rem; font-size: 0.7rem; margin-left: 0.5rem; }
        .tabs { display: flex; gap: 0.5rem; border-bottom: 1px solid var(--border-color); margin-bottom: 1.5rem; flex-wrap: wrap; }
        .tab-btn { padding: 0.6rem 1.2rem; background: transparent; border: none; color: var(--text-muted); cursor: pointer; font-size: 1rem; border-bottom: 2px solid transparent; }
        .tab-btn.active { color: #4ECDC4; border-bottom-color: #4ECDC4; }
        .tab-pane { display: none; }
        .tab-pane.active { display: block; }
        .storage-info {
            background: rgba(78,205,196,0.05);
            border-radius: 0.5rem;
            padding: 0.5rem 1rem;
            margin-bottom: 1rem;
            font-size: 0.85rem;
            color: var(--text-muted);
        }
        .clean-note {
            font-size: 0.75rem;
            color: #FF8A3C;
            margin-top: 0.5rem;
        }
        @media (max-width: 768px) {
            body { padding: 0.5rem; }
            .admin-header { padding: 0.8rem 1rem; }
            .stats-grid { grid-template-columns: repeat(2, 1fr); gap: 0.8rem; padding: 1rem; }
            .stat-number { font-size: 1.3rem; }
            .action-buttons { flex-direction: column; }
            .admin-btn { width: 100%; justify-content: center; }
        }
    </style>
</head>
<body class="dark">
<div class="admin-container">
    <div class="admin-header">
        <h1>
            🛠️ 管理后台
            <?php if ($isSuper): ?>
            <span class="super-badge">超级管理员</span>
            <?php elseif ($isDeputy): ?>
            <span class="deputy-badge">副号</span>
            <?php endif; ?>
        </h1>
        <a href="index.php" class="back-link">← 回去</a>
    </div>
    
    <?php if ($flashMessage): ?>
    <div class="flash-message <?= $flashMessage['type'] ?>"><?= htmlspecialchars($flashMessage['message']) ?></div>
    <?php endif; ?>
    
    <!-- 统计卡片 -->
    <div class="stats-grid">
        <div class="stat-card"><div class="stat-number"><?= $userCount ?></div><div class="stat-label">总用户数</div></div>
        <div class="stat-card"><div class="stat-number"><?= $adminCount ?></div><div class="stat-label">管理员数</div></div>
        <div class="stat-card"><div class="stat-number"><?= $messageCount ?></div><div class="stat-label">公共消息</div></div>
        <div class="stat-card"><div class="stat-number"><?= $privateCount ?></div><div class="stat-label">私聊消息</div></div>
        <div class="stat-card"><div class="stat-number"><?= $serverStatus['online'] ?></div><div class="stat-label">在线人数</div></div>
        <div class="stat-card"><div class="stat-number"><?= $bannedCount ?></div><div class="stat-label">封禁人数</div></div>
        <div class="stat-card"><div class="stat-number"><?= $imageCount ?></div><div class="stat-label">图片文件</div></div>
        <div class="stat-card"><div class="stat-number"><?= round($imageSize / 1024) ?>KB</div><div class="stat-label">图片大小</div></div>
    </div>
    
    <!-- Tab 导航 -->
    <div class="tabs">
        <button class="tab-btn active" data-tab="users">👥 用户管理</button>
        <button class="tab-btn" data-tab="content">📝 内容管理</button>
        <?php if ($canClean): ?>
        <button class="tab-btn" data-tab="storage">💾 存储管理</button>
        <?php endif; ?>
        <?php if ($isSuper): ?>
        <button class="tab-btn" data-tab="system">⚙️ 系统维护</button>
        <button class="tab-btn" data-tab="logs">📋 操作日志</button>
        <?php endif; ?>
    </div>
    
    <!-- 用户管理 Tab -->
    <div id="tab-users" class="tab-pane active">
        <div class="admin-card">
            <div class="section-title">👥 用户列表</div>
            <div class="table-wrapper">
                <table class="admin-table">
                    <thead>
                         <tr><th>ID</th><th>用户名</th><th>邮箱</th><th>角色</th><th>注册时间</th><th>状态</th><th>操作</th> </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($users)): ?>
                         <tr><td colspan="7" style="text-align:center;">暂无用户数据</td></tr>
                        <?php else: ?>
                        <?php foreach ($users as $u): ?>
                        <?php $banInfo = isBanned($u['id']); ?>
                        <?php $isProtected = isProtectedUser($u['id']); ?>
                         <tr>
                              <td>
                                 <?= $u['id'] ?>
                                 <?php if ($u['id'] == 1): ?>
                                     <span class="protected-badge">超级</span>
                                 <?php elseif ($u['id'] == 2): ?>
                                     <span class="protected-badge">副号</span>
                                 <?php endif; ?>
                                 <?php if ($banInfo): ?>
                                     <span class="banned-badge">封禁中</span>
                                 <?php endif; ?>
                              </td>
                              <td><?= htmlspecialchars($u['username']) ?></td>
                              <td><?= htmlspecialchars($u['email'] ?? '未设置') ?></td>
                              <td><?= $u['role'] === 'admin' ? '<span style="color:#4ECDC4;">⚡ 管理员</span>' : '<span style="color:#9AAEC2;">✦ 用户</span>' ?></td>
                              <td><?= safeDate($u['created_at']) ?></td>
                              <td><?= $banInfo ? '<span style="color:#FF6B6B;">已封禁</span>' : '<span style="color:#4ECDC4;">正常</span>' ?></td>
                              <td>
                                 <?php if ($isProtected): ?>
                                     <span class="current-user-badge">🔒 受保护账户</span>
                                 <?php elseif ($u['id'] != $_SESSION['user_id']): ?>
                                     <?php if ($isSuper): ?>
                                     <form method="post" style="display:inline;">
                                         <input type="hidden" name="action" value="set_role">
                                         <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                         <select name="role">
                                             <option value="user" <?= $u['role'] == 'user' ? 'selected' : '' ?>>普通</option>
                                             <option value="admin" <?= $u['role'] == 'admin' ? 'selected' : '' ?>>管理员</option>
                                         </select>
                                         <button type="submit">更改</button>
                                     </form>
                                     <?php endif; ?>
                                     <form method="post" style="display:inline;" onsubmit="return confirm('确认删除用户「<?= htmlspecialchars($u['username']) ?>」？');">
                                         <input type="hidden" name="action" value="delete_user">
                                         <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                         <button type="submit" class="delete-btn">删除</button>
                                     </form>
                                     <?php if ($canBan && !$banInfo): ?>
                                     <button class="delete-btn" onclick="banUser(<?= $u['id'] ?>, '<?= htmlspecialchars($u['username']) ?>')">封禁</button>
                                     <?php elseif ($canBan && $banInfo): ?>
                                     <form method="post" style="display:inline;">
                                         <input type="hidden" name="action" value="unban_user">
                                         <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                         <button type="submit" style="border-color:#4ECDC4; color:#4ECDC4;">解封</button>
                                     </form>
                                     <?php endif; ?>
                                 <?php else: ?>
                                     <span class="current-user-badge">当前账户</span>
                                 <?php endif; ?>
                              </td>
                         </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
        <?php if ($canBan && !empty($bannedUsers)): ?>
        <div class="admin-card">
            <div class="section-title">🚫 封禁用户列表</div>
            <div class="table-wrapper">
                <table class="admin-table">
                    <thead>
                         <tr><th>用户ID</th><th>用户名</th><th>原因</th><th>封禁时间</th><th>过期时间</th><th>操作</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($bannedUsers as $b): ?>
                         <tr>
                             <td><?= $b['user_id'] ?></td>
                             <td><?= htmlspecialchars($b['user_name']) ?></td>
                             <td><?= htmlspecialchars($b['reason'] ?? '无') ?></td>
                             <td><?= safeDate($b['banned_at']) ?></td>
                             <td><?= $b['expires_at'] ? safeDate($b['expires_at']) : '永久' ?></td>
                             <td>
                                 <form method="post" style="display:inline;">
                                     <input type="hidden" name="action" value="unban_user">
                                     <input type="hidden" name="user_id" value="<?= $b['user_id'] ?>">
                                     <button type="submit" class="admin-btn secondary">解封</button>
                                 </form>
                              </tr>
                        <?php endforeach; ?>
                    </tbody>
                 </table>
            </div>
        </div>
        <?php endif; ?>
    </div>
    
    <!-- 内容管理 Tab -->
    <div id="tab-content" class="tab-pane">
        <div class="admin-card">
            <div class="section-title">🌐 全局操作</div>
            <div class="action-buttons">
                <?php if ($canClean): ?>
                <form method="post" onsubmit="return confirm('确定清空所有公共聊天记录？同时会删除所有图片文件。');">
                    <input type="hidden" name="action" value="clear_messages">
                    <button type="submit" class="admin-btn danger">🗑️ 清空公共聊天</button>
                </form>
                <?php if ($isSuper): ?>
                <form method="post" onsubmit="return confirm('确定清空所有私聊记录？同时会删除所有私聊图片文件。');">
                    <input type="hidden" name="action" value="clear_private_messages">
                    <button type="submit" class="admin-btn warning">💬 清空私聊记录</button>
                </form>
                <?php endif; ?>
                <?php else: ?>
                <div class="storage-info" style="background:rgba(255,107,107,0.1); color:#FF6B6B;">
                    ⚠️ 清理聊天记录功能仅限超级管理员(UID=1)和副号(UID=2)使用
                </div>
                <?php endif; ?>
            </div>
        </div>
        
        <?php if ($isSuper): ?>
        <div class="admin-card">
            <div class="section-title">📢 发送公告</div>
            <form method="post">
                <input type="hidden" name="action" value="send_announcement">
                <div class="form-group"><label>标题</label><input type="text" name="title" placeholder="公告标题" required></div>
                <div class="form-group"><label>内容</label><textarea name="content" placeholder="公告内容" required></textarea></div>
                <div class="form-group"><label>过期时间（留空则永久）</label><input type="datetime-local" name="expires"></div>
                <button type="submit" class="admin-btn primary">📢 发布公告</button>
            </form>
        </div>
        
        <div class="admin-card">
            <div class="section-title">🔤 敏感词管理</div>
            <div class="table-wrapper">
                <table class="admin-table">
                    <thead>   <tr><th>ID</th><th>敏感词</th><th>替换为</th><th>操作</th></tr> </thead>
                    <tbody>
                        <?php foreach ($sensitiveWords as $w): ?>
                        <tr><td><?= $w['id'] ?></td><td><?= htmlspecialchars($w['word']) ?></td><td><?= htmlspecialchars($w['replacement']) ?></td><td><button class="delete-btn" onclick="deleteSensitiveWord(<?= $w['id'] ?>)">删除</button></td></tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <form method="post" style="margin-top: 1rem;">
                <input type="hidden" name="action" value="add_sensitive_word">
                <div style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
                    <input type="text" name="word" placeholder="敏感词" style="flex:1; padding:0.6rem; border-radius:1rem; border:1px solid #4ECDC4;">
                    <input type="text" name="replacement" placeholder="替换为" style="width:100px; padding:0.6rem; border-radius:1rem; border:1px solid #4ECDC4;">
                    <button type="submit" class="admin-btn primary">添加</button>
                </div>
            </form>
        </div>
        <?php endif; ?>
    </div>
    
    <!-- 存储管理 Tab（仅超级管理员或副号） -->
    <?php if ($canClean): ?>
    <div id="tab-storage" class="tab-pane">
        <div class="admin-card">
            <div class="section-title">💾 存储空间管理</div>
            <div class="storage-info">
                📁 图片存储路径: uploads/images/<br>
                📊 当前图片文件数: <?= $imageCount ?> 个<br>
                💾 图片占用空间: <?= $imageSize > 1024 * 1024 ? round($imageSize / 1024 / 1024, 2) . ' MB' : round($imageSize / 1024, 2) . ' KB' ?><br>
                🗑️ 清理孤儿图片可以释放未使用的空间
            </div>
            <div class="action-buttons">
                <form method="post" onsubmit="return confirm('确定清理孤儿图片文件？(不在任何消息中的图片)');">
                    <input type="hidden" name="action" value="clean_orphan_images">
                    <button type="submit" class="admin-btn secondary">🧹 清理孤儿图片</button>
                </form>
                <form method="post" onsubmit="return confirm('确定清理所有上传文件（包括头像）？此操作不可撤销。');">
                    <input type="hidden" name="action" value="clear_uploads">
                    <button type="submit" class="admin-btn danger">🗑️ 清理所有上传文件</button>
                </form>
            </div>
            <div class="clean-note">
                💡 提示：清理孤儿图片会删除没有被任何消息引用的图片文件，可以释放服务器空间。
            </div>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- 系统维护 Tab（仅超级管理员） -->
    <?php if ($isSuper): ?>
    <div id="tab-system" class="tab-pane">
        <div class="admin-card">
            <div class="section-title">💾 数据库维护</div>
            <div class="action-buttons">
                <form method="post"><input type="hidden" name="action" value="backup_database"><button type="submit" class="admin-btn primary">💾 备份数据库</button></form>
                <form method="post"><input type="hidden" name="action" value="optimize_db"><button type="submit" class="admin-btn secondary">⚡ 优化数据库</button></form>
            </div>
        </div>
        
        <div class="admin-card">
            <div class="section-title">⚡ 频率限制</div>
            <form method="post">
                <input type="hidden" name="action" value="set_rate_limit">
                <div style="display: flex; gap: 1rem; flex-wrap: wrap;">
                    <div class="form-group" style="flex:1;"><label>每分钟最大消息数</label><input type="number" name="limit" value="<?= $rateLimit ?>" min="1" max="100" required></div>
                    <div class="form-group" style="flex:1;"><label>时间窗口（秒）</label><input type="number" name="window" value="<?= $rateLimitWindow ?>" min="10" max="300" required></div>
                    <button type="submit" class="admin-btn primary" style="align-self: flex-end;">保存设置</button>
                </div>
            </form>
        </div>
        
        <div class="admin-card">
            <div class="section-title">📊 服务器状态</div>
            <div class="stats-grid" style="grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));">
                <div class="stat-card"><div class="stat-number"><?= $serverStatus['online'] ?></div><div class="stat-label">在线人数</div></div>
                <div class="stat-card"><div class="stat-number"><?= $serverStatus['total_messages'] ?></div><div class="stat-label">总消息数</div></div>
                <div class="stat-card"><div class="stat-number"><?= $serverStatus['total_private'] ?></div><div class="stat-label">私聊消息数</div></div>
                <div class="stat-card"><div class="stat-number"><?= round($serverStatus['db_size'] / 1024) ?> KB</div><div class="stat-label">数据库大小</div></div>
                <div class="stat-card"><div class="stat-number"><?= round($serverStatus['upload_size'] / 1024) ?> KB</div><div class="stat-label">上传文件大小</div></div>
            </div>
        </div>
    </div>
    
    <!-- 操作日志 Tab -->
    <div id="tab-logs" class="tab-pane">
        <div class="admin-card">
            <div class="section-title">📋 管理员操作日志</div>
            <div class="table-wrapper">
                <table class="admin-table">
                    <thead>   <tr><th>时间</th><th>管理员</th><th>操作</th><th>目标ID</th><th>详情</th><th>IP</th></tr> </thead>
                    <tbody>
                        <?php foreach ($adminLogs as $log): ?>
                        <tr>
                            <td><?= safeDate($log['created_at'], 'm-d H:i') ?></td>
                            <td><?= htmlspecialchars($log['admin_name']) ?></td>
                            <td><?= htmlspecialchars($log['action']) ?></td>
                            <td><?= $log['target_id'] ?: '-' ?></td>
                            <td><?= htmlspecialchars($log['details'] ?? '-') ?></td>
                            <td><?= htmlspecialchars($log['ip'] ?? '-') ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div style="margin-top: 1rem;"><a href="api/export_logs.php?type=admin_logs" class="admin-btn secondary" target="_blank">📥 导出日志</a></div>
        </div>
    </div>
    <?php endif; ?>
</div>

<script>
function banUser(userId, username) {
    var hours = prompt('封禁时长（小时），0永久封禁：', '24');
    if (hours === null) return;
    var reason = prompt('封禁原因：', '');
    if (reason === null) reason = '';
    var form = document.createElement('form');
    form.method = 'POST';
    form.action = 'admin.php';
    form.innerHTML = '<input type="hidden" name="action" value="ban_user">' +
                     '<input type="hidden" name="user_id" value="' + userId + '">' +
                     '<input type="hidden" name="hours" value="' + hours + '">' +
                     '<input type="hidden" name="reason" value="' + reason + '">';
    document.body.appendChild(form);
    form.submit();
}
function deleteSensitiveWord(id) {
    if (confirm('确定删除这个敏感词吗？')) {
        var form = document.createElement('form');
        form.method = 'POST';
        form.action = 'admin.php';
        form.innerHTML = '<input type="hidden" name="action" value="delete_sensitive_word">' +
                         '<input type="hidden" name="id" value="' + id + '">';
        document.body.appendChild(form);
        form.submit();
    }
}
document.querySelectorAll('.tab-btn').forEach(btn => {
    btn.addEventListener('click', () => {
        document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
        document.querySelectorAll('.tab-pane').forEach(p => p.classList.remove('active'));
        btn.classList.add('active');
        document.getElementById('tab-' + btn.dataset.tab).classList.add('active');
    });
});
</script>
</body>
</html>