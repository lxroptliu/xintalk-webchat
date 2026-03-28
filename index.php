<?php
require_once 'functions.php';

// 检查登录状态
if (!isLoggedIn()) {
    header('Location: login.php');
    exit;
}

$user = currentUser();
if (!$user) {
    session_destroy();
    header('Location: login.php');
    exit;
}

// 检查是否被封禁
if (isBanned($user['id'])) {
    $banInfo = getBanInfo($user['id']);
    session_destroy();
    $msg = $banInfo['expires_at'] ? "账号被封至 " . date('Y-m-d H:i', $banInfo['expires_at']) : "账号已被永久封禁";
    if ($banInfo['reason']) {
        $msg .= "\n原因: " . $banInfo['reason'];
    }
    die('<div style="background:#0A0C14; color:#FF6B6B; display:flex; align-items:center; justify-content:center; height:100vh; font-family:sans-serif; text-align:center;"><div><h1>🚫 被封了</h1><p>' . nl2br(htmlspecialchars($msg)) . '</p><a href="login.php" style="color:#4ECDC4;">回去登录</a></div></div>');
}

updateLastActivity($user['id']);
$onlineCount = getOnlineCount();
$avatarUrl = getAvatarUrl($user['avatar'], $user['username']);

// 获取在线用户（带称号）
$onlineUsers = getOnlineUsers();
// 获取好友列表（带称号）
$friends = getFriends($user['id']);
// 获取好友请求
$friendRequests = getFriendRequests($user['id']);
// 获取私聊未读数
$unreadCounts = getUnreadPrivateCounts($user['id']);
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <meta name="theme-color" content="#4ECDC4">
    <title>信Talk · 聊天室</title>
    
    <!-- 浏览器兼容性检测 - 仅跳转真正无法运行的旧浏览器 -->
    <script>
    (function() {
        // 检测 IE 浏览器
        var isIE = /*@cc_on!@*/false || !!document.documentMode;
        
        // 检测安卓 WebView 版本
        var ua = navigator.userAgent;
        var isAndroid = /Android/i.test(ua);
        var androidVersion = 0;
        if (isAndroid) {
            var match = ua.match(/Android\s([0-9\.]+)/);
            if (match) androidVersion = parseFloat(match[1]);
        }
        
        // 检测 Chrome 版本
        var chromeVersion = 0;
        var chromeMatch = ua.match(/Chrome\/([0-9]+)/);
        if (chromeMatch) chromeVersion = parseInt(chromeMatch[1]);
        
        // 检测 Safari 版本
        var safariVersion = 0;
        var safariMatch = ua.match(/Version\/([0-9]+).*Safari/);
        if (safariMatch) safariVersion = parseInt(safariMatch[1]);
        
        // 检测 Firefox 版本
        var firefoxVersion = 0;
        var firefoxMatch = ua.match(/Firefox\/([0-9]+)/);
        if (firefoxMatch) firefoxVersion = parseInt(firefoxMatch[1]);
        
        // 判断是否需要跳转到兼容版（仅当真正无法运行时）
        var needCompatible = false;
        
        // IE 浏览器全部跳转
        if (isIE) needCompatible = true;
        
        // 安卓系统：仅当 Android 版本 < 5.0 或 Chrome < 60 时才跳转
        // 安卓6 + Chrome 106 可以正常使用完整版
        if (isAndroid && (androidVersion < 5 || (chromeVersion > 0 && chromeVersion < 60))) {
            needCompatible = true;
        }
        
        // 桌面浏览器：Chrome < 60
        if (!isAndroid && chromeVersion > 0 && chromeVersion < 60) needCompatible = true;
        
        // Firefox < 60
        if (firefoxVersion > 0 && firefoxVersion < 60) needCompatible = true;
        
        // Safari < 12
        if (safariVersion > 0 && safariVersion < 12) needCompatible = true;
        
        // 如果需要跳转
        if (needCompatible) {
            window.location.href = 'index_two.php';
        }
    })();
    </script>
    
    <link rel="stylesheet" href="style.css">
    <style>
        /* ========== 深色主题变量（默认） ========== */
        :root {
            --bg-deep: #0A0C14;
            --bg-card: rgba(15, 22, 35, 0.95);
            --bg-sidebar: rgba(8, 14, 24, 0.98);
            --text-main: #F0F4FA;
            --text-muted: #9AAEC2;
            --border-color: #2E405B;
            --message-bg: rgba(30, 42, 60, 0.8);
            --message-own-bg: rgba(78, 205, 196, 0.2);
            --input-bg: rgba(10, 20, 30, 0.8);
            --accent-primary: #4ECDC4;
            --accent-secondary: #2C7A6F;
        }

        /* ========== 浅色主题变量 ========== */
        body.light {
            --bg-deep: #F5F7FA;
            --bg-card: rgba(255, 255, 255, 0.95);
            --bg-sidebar: rgba(248, 250, 252, 0.98);
            --text-main: #1A2A2A;
            --text-muted: #5A6E6E;
            --border-color: #C8D8D8;
            --message-bg: rgba(230, 245, 245, 0.9);
            --message-own-bg: rgba(78, 205, 196, 0.15);
            --input-bg: rgba(255, 255, 255, 0.9);
            --accent-primary: #4ECDC4;
            --accent-secondary: #2C7A6F;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            background: var(--bg-deep);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1rem;
            font-family: 'Segoe UI', 'PingFang SC', 'Microsoft YaHei', sans-serif;
            transition: background 0.2s ease;
        }

        /* 聊天容器 */
        .chat-container {
            max-width: 1400px;
            width: 100%;
            height: 95vh;
            background: var(--bg-card);
            backdrop-filter: blur(20px);
            border-radius: 2rem;
            border: 2px solid var(--accent-primary);
            box-shadow: 0 30px 60px -15px rgba(0,0,0,0.5);
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        /* 头部 */
        .chat-header {
            padding: 1rem 2rem;
            background: rgba(0,0,0,0.2);
            border-bottom: 2px solid var(--accent-primary);
            display: flex;
            align-items: center;
            justify-content: space-between;
        }

        .logo {
            display: flex;
            align-items: center;
            gap: 0.8rem;
            color: var(--text-main);
            font-weight: 600;
            font-size: 1.3rem;
        }

        .logo span:first-child {
            color: var(--accent-primary);
            font-size: 1.5rem;
        }

        .logo span:last-child {
            font-size: 0.9rem;
            opacity: 0.7;
        }

        .menu-toggle {
            display: none;
            background: transparent;
            border: 1px solid var(--accent-primary);
            color: var(--accent-primary);
            font-size: 1.8rem;
            padding: 0.2rem 0.8rem;
            border-radius: 0.8rem;
            cursor: pointer;
        }

        .theme-toggle button {
            background: transparent;
            border: 1px solid var(--border-color);
            color: var(--text-muted);
            font-size: 1.5rem;
            padding: 0.4rem 1rem;
            border-radius: 2rem;
            cursor: pointer;
        }

        /* 公告栏 */
        .announcement-bar {
            background: linear-gradient(135deg, rgba(78,205,196,0.2), rgba(44,122,111,0.2));
            border-bottom: 1px solid var(--accent-primary);
            padding: 0.8rem 2rem;
            animation: slideDown 0.3s ease;
        }
        .announcement-content {
            display: flex;
            align-items: center;
            gap: 1rem;
            max-width: 1200px;
            margin: 0 auto;
        }
        .announcement-icon { font-size: 1.2rem; }
        .announcement-content span:not(.announcement-icon) { flex: 1; color: var(--text-main); }
        .close-announcement { background: none; border: none; color: var(--text-muted); font-size: 1.2rem; cursor: pointer; }
        .close-announcement:hover { color: #FF6B6B; }
        @keyframes slideDown { from { opacity: 0; transform: translateY(-100%); } to { opacity: 1; transform: translateY(0); } }

        /* 主体 */
        .chat-main {
            display: flex;
            flex: 1;
            overflow: hidden;
        }

        /* 侧边栏 */
        .sidebar {
            width: 280px;
            background: var(--bg-sidebar);
            border-right: 2px solid var(--accent-primary);
            padding: 1.5rem 1rem;
            display: flex;
            flex-direction: column;
            gap: 1.5rem;
            overflow-y: auto;
            transition: transform 0.3s ease;
        }

        .user-card {
            background: rgba(255,255,255,0.05);
            border-radius: 1.5rem;
            padding: 1.5rem 1rem;
            text-align: center;
            border: 1px solid var(--border-color);
        }

        .user-card .avatar {
            width: 80px;
            height: 80px;
            margin: 0 auto 1rem;
            border-radius: 50%;
            overflow: hidden;
            border: 2px solid var(--accent-primary);
        }

        .user-card .avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .user-card .name {
            color: var(--text-main);
            font-size: 1.4rem;
            font-weight: 600;
        }

        .user-card .title {
            color: var(--accent-secondary);
            font-size: 0.9rem;
            margin-top: 0.2rem;
        }

        .user-card .uid {
            color: var(--text-muted);
            font-size: 0.8rem;
            margin: 0.2rem 0;
            font-family: monospace;
        }

        .user-card .role {
            color: var(--accent-primary);
            font-size: 1rem;
        }

        .online-count {
            font-size: 0.75rem;
            color: var(--accent-secondary);
            margin-top: 0.5rem;
            padding-top: 0.3rem;
            border-top: 1px solid rgba(78,205,196,0.3);
        }

        .sidebar-menu {
            display: flex;
            flex-direction: column;
            gap: 0.8rem;
        }

        .sidebar-menu a {
            color: var(--text-muted);
            text-decoration: none;
            padding: 0.8rem 1.2rem;
            border-radius: 2rem;
            background: rgba(255,255,255,0.02);
            border: 1px solid var(--border-color);
            display: flex;
            align-items: center;
            gap: 1rem;
            transition: 0.2s;
        }

        .sidebar-menu a:hover {
            background: rgba(78,205,196,0.1);
            border-color: var(--accent-primary);
            color: var(--accent-primary);
        }

        /* 好友区域 */
        .friends-section {
            margin-top: 1rem;
            border-top: 1px solid var(--border-color);
            padding-top: 1rem;
        }

        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 0.5rem;
            font-weight: bold;
            color: var(--text-muted);
        }

        .small-btn {
            background: transparent;
            border: 1px solid var(--accent-primary);
            color: var(--accent-primary);
            padding: 0.2rem 0.6rem;
            border-radius: 1rem;
            font-size: 0.8rem;
            cursor: pointer;
        }

        .friends-list, .friend-requests { margin-bottom: 0.8rem; }
        .friend-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem;
            border-radius: 1rem;
            text-decoration: none;
            color: var(--text-main);
            transition: 0.2s;
        }
        .friend-item:hover {
            background: rgba(78,205,196,0.1);
        }
        .friend-avatar {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            overflow: hidden;
        }
        .friend-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .friend-name { flex: 1; color: var(--text-main); }
        .friend-title {
            color: var(--accent-secondary);
            font-size: 0.75rem;
            margin-left: 0.3rem;
        }
        .friend-status { margin-left: 0.5rem; display: inline-flex; align-items: center; }
        .online-dot {
            display: inline-block;
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background-color: var(--accent-primary);
            box-shadow: 0 0 4px var(--accent-primary);
        }
        .offline-dot {
            display: inline-block;
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background-color: #666;
        }
        .unread-badge {
            background: var(--accent-primary);
            color: white;
            border-radius: 50%;
            min-width: 20px;
            height: 20px;
            padding: 0 4px;
            font-size: 0.7rem;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }
        .request-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem;
            background: rgba(78,205,196,0.1);
            border-radius: 1rem;
            margin-bottom: 0.3rem;
        }
        .request-buttons button {
            background: transparent;
            border: 1px solid var(--accent-primary);
            color: var(--accent-primary);
            border-radius: 1rem;
            padding: 0.2rem 0.5rem;
            margin-left: 0.3rem;
            cursor: pointer;
        }
        .empty-tip { color: var(--text-muted); font-size: 0.8rem; padding: 0.5rem; text-align: center; }

        /* 在线用户区域 */
        .online-users-section {
            margin-top: 1rem;
            border-top: 1px solid var(--border-color);
            padding-top: 1rem;
        }
        .online-users-list { max-height: 200px; overflow-y: auto; }
        .online-user-item {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.5rem;
            border-radius: 1rem;
            cursor: pointer;
            transition: 0.2s;
        }
        .online-user-item:hover { background: rgba(78,205,196,0.1); }
        .online-user-avatar {
            width: 28px;
            height: 28px;
            border-radius: 50%;
            overflow: hidden;
        }
        .online-user-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .online-user-name { flex: 1; }
        .online-user-title {
            color: var(--accent-secondary);
            font-size: 0.75rem;
            margin-left: 0.3rem;
        }
        .typing-indicator { color: var(--accent-primary); font-size: 0.7rem; margin-left: 0.5rem; animation: pulse 1s infinite; }
        @keyframes pulse { 0%, 100% { opacity: 0.5; } 50% { opacity: 1; } }

        /* 聊天区域 */
        .chat-area {
            flex: 1;
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        .messages-box {
            flex: 1;
            overflow-y: auto;
            padding: 1.5rem;
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }

        #loadMoreContainer {
            text-align: center;
            margin-bottom: 1rem;
        }
        .load-more-btn {
            background: transparent;
            border: 1px solid var(--accent-primary);
            color: var(--accent-primary);
            padding: 0.6rem 1.2rem;
            border-radius: 2rem;
            cursor: pointer;
            font-size: 0.85rem;
            transition: all 0.2s;
        }
        .load-more-btn:hover {
            background: rgba(78,205,196,0.1);
            transform: scale(1.02);
        }

        .message {
            max-width: 70%;
            padding: 0.8rem 1.2rem;
            border-radius: 1.2rem 1.2rem 1.2rem 0;
            background: var(--message-bg);
            border: 1px solid var(--border-color);
            align-self: flex-start;
            color: var(--text-main);
        }

        .message.own {
            align-self: flex-end;
            background: var(--message-own-bg);
            border-color: var(--accent-primary);
            border-radius: 1.2rem 1.2rem 0 1.2rem;
        }

        .message-header {
            display: flex;
            align-items: center;
            gap: 0.6rem;
            margin-bottom: 0.3rem;
            font-size: 0.85rem;
            color: var(--text-muted);
            position: relative;
        }

        .message-info {
            flex: 1;
        }

        .message-name-time {
            display: flex;
            align-items: baseline;
            gap: 0.5rem;
            flex-wrap: wrap;
        }

        .message-avatar {
            width: 28px;
            height: 28px;
            border-radius: 50%;
            overflow: hidden;
            border: 1px solid var(--accent-primary);
            flex-shrink: 0;
        }
        .message-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .message-author {
            color: var(--accent-primary);
            font-weight: 600;
            cursor: pointer;
        }
        .message.own .message-author { color: var(--accent-secondary); }
        .message-title {
            color: var(--accent-secondary);
            font-size: 0.8rem;
            margin-left: 0.2rem;
        }
        .message-time { font-size: 0.7rem; opacity: 0.7; }
        .message-content { word-wrap: break-word; line-height: 1.5; padding-left: 34px; }
        .message-content img { max-width: 100%; max-height: 200px; border-radius: 0.5rem; cursor: pointer; }

        /* 撤回按钮样式 */
        .recall-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 20px;
            height: 20px;
            background: rgba(255, 100, 100, 0.8);
            color: white;
            border-radius: 50%;
            font-size: 12px;
            font-weight: bold;
            cursor: pointer;
            transition: 0.2s;
            margin-left: 0.5rem;
            flex-shrink: 0;
        }
        .recall-btn:hover {
            background: #ff4444;
            transform: scale(1.05);
        }

        /* 撤回消息样式 */
        .message-content.recalled {
            color: var(--text-muted);
            font-style: italic;
            opacity: 0.7;
        }

        .quote-message {
            background: rgba(78,205,196,0.1);
            border-left: 3px solid var(--accent-primary);
            padding: 0.5rem;
            margin-bottom: 0.5rem;
            border-radius: 0.5rem;
            font-size: 0.85rem;
        }
        .quote-author { color: var(--accent-primary); font-weight: bold; }
        .quote-content { color: var(--text-muted); word-break: break-all; }

        .reply-preview {
            background: rgba(78,205,196,0.1);
            border-left: 3px solid var(--accent-primary);
            padding: 0.5rem;
            margin-bottom: 0.5rem;
            border-radius: 0.5rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .reply-preview-content { flex: 1; font-size: 0.85rem; color: var(--text-muted); }
        .cancel-reply { background: none; border: none; color: #FF6B6B; cursor: pointer; font-size: 1.2rem; padding: 0 0.5rem; }

        /* 输入区域 */
        .message-input-area {
            padding: 1rem 1.5rem;
            background: rgba(0,0,0,0.2);
            border-top: 2px solid var(--accent-primary);
            display: flex;
            gap: 1rem;
            align-items: center;
        }

        .message-input-area input {
            flex: 1;
            padding: 1rem 1.5rem;
            border-radius: 3rem;
            border: 1px solid var(--border-color);
            background: var(--input-bg);
            color: var(--text-main);
            font-size: 1rem;
            outline: none;
        }

        .message-input-area input:focus {
            border-color: var(--accent-primary);
            box-shadow: 0 0 0 2px rgba(78,205,196,0.3);
        }

        .message-input-area button {
            padding: 1rem 2rem;
            border-radius: 3rem;
            border: none;
            background: linear-gradient(145deg, var(--accent-primary), var(--accent-secondary));
            color: white;
            font-weight: bold;
            cursor: pointer;
            white-space: nowrap;
        }

        #imageBtn {
            background: transparent;
            border: 1px solid var(--accent-primary);
            color: var(--accent-primary);
            padding: 0.8rem 1rem;
            border-radius: 2rem;
            font-size: 1.2rem;
            cursor: pointer;
        }

        #imageInput {
            position: absolute;
            width: 1px;
            height: 1px;
            padding: 0;
            margin: -1px;
            overflow: hidden;
            clip: rect(0,0,0,0);
            border: 0;
        }

        /* 用户信息弹窗 */
        .user-info-modal {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.7);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 1000;
        }
        .modal-content {
            background: var(--bg-card);
            border-radius: 1.5rem;
            border: 2px solid var(--accent-primary);
            max-width: 320px;
            width: 90%;
            animation: fadeIn 0.2s;
        }
        .modal-header {
            padding: 1rem;
            border-bottom: 1px solid var(--border-color);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .close-info-modal {
            background: none;
            border: none;
            color: var(--text-muted);
            font-size: 1.5rem;
            cursor: pointer;
        }
        .user-info-content { text-align: center; padding: 1.5rem; }
        .user-info-avatar {
            width: 80px;
            height: 80px;
            margin: 0 auto 1rem;
            border-radius: 50%;
            overflow: hidden;
            border: 2px solid var(--accent-primary);
        }
        .user-info-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .user-info-name { font-size: 1.3rem; font-weight: bold; color: var(--text-main); }
        .user-info-uid { color: var(--text-muted); font-size: 0.9rem; margin: 0.3rem 0; }
        .user-info-role { color: var(--accent-primary); margin: 0.5rem 0; }
        .user-info-email { color: var(--text-muted); font-size: 0.9rem; word-break: break-all; }
        .add-friend-btn {
            background: var(--accent-primary);
            color: white;
            border: none;
            padding: 0.6rem 1.5rem;
            border-radius: 2rem;
            cursor: pointer;
            width: 100%;
            margin-top: 0.5rem;
        }
        .add-friend-btn.disabled { background: #666; cursor: not-allowed; }
        .close-info-btn {
            background: transparent;
            border: 1px solid var(--accent-primary);
            color: var(--accent-primary);
            padding: 0.5rem 1rem;
            border-radius: 2rem;
            cursor: pointer;
            margin-top: 0.5rem;
            width: 100%;
        }

        /* 移动端适配 */
        @media (max-width: 768px) {
            body { padding: 0; }
            .chat-container { height: 100vh; border-radius: 0; }
            .menu-toggle { display: block; }
            .sidebar { position: absolute; top: 0; left: 0; height: 100%; z-index: 100; transform: translateX(-100%); width: 280px; }
            .sidebar.open { transform: translateX(0); }
            .chat-area { width: 100%; }
            .message { max-width: 85%; }
            .message-input-area { padding: 0.8rem 1rem; }
            .message-input-area input { padding: 0.8rem; font-size: 16px; }
            .message-input-area button { padding: 0.8rem 1.2rem; }
            #imageBtn { padding: 0.6rem 0.8rem; }
            .load-more-btn { padding: 0.4rem 1rem; font-size: 0.75rem; }
            .announcement-bar { padding: 0.5rem 1rem; }
        }
    </style>
</head>
<body class="dark" data-current-user="<?= htmlspecialchars($user['username']) ?>" data-user-id="<?= $user['id'] ?>">
<div class="chat-container">
    <div id="announcementBar" class="announcement-bar" style="display:none;">
        <div class="announcement-content">
            <span class="announcement-icon">📢</span>
            <span id="announcementText"></span>
            <button class="close-announcement" onclick="closeAnnouncement()">✕</button>
        </div>
    </div>

    <div class="chat-header">
        <div class="logo">
            <span>信Talk</span>
            <span>XinTalk</span>
        </div>
        <button class="menu-toggle" id="menuToggle">☰</button>
        <div class="theme-toggle">
            <button id="themeBtn">☀️</button>
        </div>
    </div>

    <div class="chat-main">
        <div class="sidebar">
            <div class="user-card">
                <div class="avatar">
                    <img src="<?= $avatarUrl ?>" alt="avatar">
                </div>
                <div class="name"><?= htmlspecialchars($user['username']) ?></div>
                <?php if (!empty($user['title'])): ?>
                <div class="title">「<?= htmlspecialchars($user['title']) ?>」</div>
                <?php endif; ?>
                <div class="uid">UID: <?= $user['id'] ?></div>
                <div class="role"><?= $user['role'] === 'admin' ? '⚡管理' : '✦成员' ?></div>
                <div class="online-count">🌟 在线 <?= $onlineCount ?>人</div>
            </div>

            <div class="sidebar-menu">
                <a href="profile.php"><span>⚙️</span> 资料</a>
                <a href="about.php"><span>📖</span> 关于</a>
                <?php if ($user['role'] === 'admin'): ?>
                <a href="admin.php"><span>🛠️</span> 管理</a>
                <?php endif; ?>
                <a href="logout.php"><span>🚪</span> 退出</a>
            </div>

            <div class="friends-section">
                <div class="section-header">
                    <span>👥 好友</span>
                    <button id="addFriendBtn" class="small-btn">+ 加</button>
                </div>
                <div id="friendsList" class="friends-list">
                    <div class="empty-tip">加载中...</div>
                </div>
                <div id="friendRequests" class="friend-requests"></div>
            </div>

            <div class="online-users-section">
                <div class="section-header">
                    <span>🌟 在线</span>
                </div>
                <div id="onlineUsersList" class="online-users-list">
                    <div class="empty-tip">加载中...</div>
                </div>
            </div>
        </div>

        <div class="chat-area">
            <div class="messages-box" id="messagesBox">
                <div id="loadMoreContainer" style="text-align:center; display:none;">
                    <button id="loadMoreBtn" class="load-more-btn">📜 加载更多</button>
                </div>
            </div>
            <div class="message-input-area">
                <div id="replyPreview" class="reply-preview" style="display:none;">
                    <div class="reply-preview-content"></div>
                    <button class="cancel-reply" onclick="cancelReply()">✕</button>
                </div>
                <input type="text" id="messageInput" placeholder="说点什么..." autocomplete="off">
                <button type="button" id="imageBtn">📷</button>
                <input type="file" id="imageInput" accept="image/jpeg,image/png,image/gif,image/webp">
                <button id="sendBtn">→</button>
            </div>
        </div>
    </div>
</div>

<div id="userInfoModal" class="user-info-modal" style="display:none;">
    <div class="modal-content">
        <div class="modal-header">
            <span>用户信息</span>
            <button class="close-info-modal" onclick="document.getElementById('userInfoModal').style.display='none'">×</button>
        </div>
        <div class="user-info-content" id="userInfoContent"></div>
    </div>
</div>

<script>
// ========== 深色/浅色模式切换 ==========
(function() {
    var themeBtn = document.getElementById('themeBtn');
    if (!themeBtn) return;

    var savedTheme = localStorage.getItem('theme');
    if (savedTheme === 'light') {
        document.body.classList.add('light');
        themeBtn.textContent = '🌙';
    } else {
        document.body.classList.remove('light');
        themeBtn.textContent = '☀️';
    }

    themeBtn.addEventListener('click', function() {
        if (document.body.classList.contains('light')) {
            document.body.classList.remove('light');
            themeBtn.textContent = '☀️';
            localStorage.setItem('theme', 'dark');
        } else {
            document.body.classList.add('light');
            themeBtn.textContent = '🌙';
            localStorage.setItem('theme', 'light');
        }
    });
})();

// 关闭公告
function closeAnnouncement() {
    var bar = document.getElementById('announcementBar');
    if (bar) bar.style.display = 'none';
}

// 取消回复
function cancelReply() {
    var preview = document.getElementById('replyPreview');
    if (preview) preview.style.display = 'none';
    window.currentReplyTo = null;
}
</script>
<script src="script.js"></script>
</body>
</html>