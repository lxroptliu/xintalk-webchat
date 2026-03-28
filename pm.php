<?php
// ============================================
// 私聊页面 - 完整稳定版
// ============================================

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

// 获取私聊对象（支持用户名或UID）
$targetParam = isset($_GET['user']) ? trim($_GET['user']) : (isset($_GET['friend_id']) ? trim($_GET['friend_id']) : '');
if (empty($targetParam)) {
    die('未指定聊天对象');
}

$pdo = Database::getConnection();

// 查找用户（数字参数强制转为整数）
if (is_numeric($targetParam)) {
    $userId = (int)$targetParam;
    $stmt = $pdo->prepare("SELECT id, username, avatar, title FROM users WHERE id = ?");
    $stmt->execute([$userId]);
} else {
    $stmt = $pdo->prepare("SELECT id, username, avatar, title FROM users WHERE username = ?");
    $stmt->execute([$targetParam]);
}
$friend = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$friend) {
    die('用户不存在');
}

// 不能和自己私聊
if ($friend['id'] == $user['id']) {
    die('不能和自己聊天');
}

$avatarUrl = getAvatarUrl($friend['avatar'], $friend['username']);
$friendId = $friend['id'];

// 获取历史消息（最近50条）
$stmt = $pdo->prepare("
    SELECT m.id, m.from_user_id, m.to_user_id, m.content, m.image, m.created_at,
           u.username, u.title
    FROM private_messages m
    LEFT JOIN users u ON m.from_user_id = u.id
    WHERE (m.from_user_id = ? AND m.to_user_id = ?)
       OR (m.from_user_id = ? AND m.to_user_id = ?)
    ORDER BY m.created_at ASC
    LIMIT 50
");
$stmt->execute([$user['id'], $friendId, $friendId, $user['id']]);
$initialMessages = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 标记已读
$stmt = $pdo->prepare("
    UPDATE private_messages SET is_read = 1
    WHERE from_user_id = ? AND to_user_id = ? AND is_read = 0
");
$stmt->execute([$friendId, $user['id']]);

?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>信Talk · 与 <?= htmlspecialchars($friend['username']) ?> 私聊</title>
    <link rel="stylesheet" href="style.css">
    <style>
        /* ========== 深色主题变量（与 index.php 一致） ========== */
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
            font-family: 'Segoe UI', 'PingFang SC', 'Microsoft YaHei', sans-serif;
            transition: background 0.2s ease;
        }

        .private-container {
            width: 100%;
            height: 100vh;
            background: var(--bg-card);
            backdrop-filter: blur(20px);
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        .chat-header {
            padding: 1rem 2rem;
            background: rgba(0,0,0,0.2);
            border-bottom: 2px solid var(--accent-primary);
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 0.5rem;
        }

        .back-link {
            color: var(--accent-primary);
            text-decoration: none;
            font-size: 1rem;
            padding: 0.3rem 0.8rem;
            border: 1px solid var(--accent-primary);
            border-radius: 2rem;
            transition: 0.2s;
        }
        .back-link:hover {
            background: var(--accent-primary);
            color: var(--bg-deep);
        }

        .target-info {
            display: flex;
            align-items: center;
            gap: 0.8rem;
        }

        .target-avatar {
            width: 44px;
            height: 44px;
            border-radius: 50%;
            overflow: hidden;
            border: 2px solid var(--accent-primary);
        }
        .target-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .target-name {
            font-weight: bold;
            color: var(--accent-primary);
            font-size: 1.1rem;
        }

        .target-title {
            font-size: 0.8rem;
            color: var(--accent-secondary);
            margin-left: 0.3rem;
        }

        .theme-toggle button {
            background: transparent;
            border: 1px solid var(--border-color);
            color: var(--text-muted);
            font-size: 1.3rem;
            padding: 0.3rem 0.8rem;
            border-radius: 2rem;
            cursor: pointer;
        }

        /* 消息区域 */
        .messages-box {
            flex: 1;
            overflow-y: auto;
            padding: 1.5rem;
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }

        .message {
            max-width: 70%;
            padding: 0.8rem 1.2rem;
            border-radius: 1.2rem 1.2rem 1.2rem 0;
            background: var(--message-bg);
            border: 1px solid var(--border-color);
            align-self: flex-start;
            color: var(--text-main);
            position: relative;
        }

        .message.own {
            align-self: flex-end;
            background: var(--message-own-bg);
            border-color: var(--accent-primary);
            border-radius: 1.2rem 1.2rem 0 1.2rem;
        }

        .message.sending {
            opacity: 0.6;
        }
        .message.failed {
            border-color: #FF6B6B;
        }
        .message.failed::after {
            content: "⚠️ 发送失败";
            position: absolute;
            bottom: -20px;
            right: 0;
            font-size: 0.7rem;
            color: #FF6B6B;
        }

        .message-header {
            display: flex;
            align-items: baseline;
            gap: 0.6rem;
            margin-bottom: 0.3rem;
            font-size: 0.85rem;
            color: var(--text-muted);
            flex-wrap: wrap;
        }

        .message-author {
            color: var(--accent-primary);
            font-weight: 600;
        }
        .message.own .message-author { color: var(--accent-secondary); }
        .message-title {
            color: var(--accent-secondary);
            font-size: 0.75rem;
        }
        .message-time {
            font-size: 0.7rem;
            opacity: 0.7;
        }
        .message-content {
            word-wrap: break-word;
            line-height: 1.5;
        }
        .message-content img {
            max-width: 100%;
            max-height: 200px;
            border-radius: 0.5rem;
            cursor: pointer;
        }

        /* 输入区域 - 与 index.php 完全一致 */
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

        /* 移动端适配 */
        @media (max-width: 768px) {
            .chat-header {
                padding: 0.8rem 1rem;
            }
            .target-avatar {
                width: 36px;
                height: 36px;
            }
            .target-name {
                font-size: 1rem;
            }
            .messages-box {
                padding: 1rem;
            }
            .message {
                max-width: 85%;
            }
            .message-input-area {
                padding: 0.8rem 1rem;
            }
            .message-input-area input {
                padding: 0.8rem 1rem;
                font-size: 16px;
            }
            .message-input-area button {
                padding: 0.8rem 1.2rem;
            }
            #imageBtn {
                padding: 0.6rem 0.8rem;
            }
        }
    </style>
</head>
<body>
<div class="private-container">
    <div class="chat-header">
        <a href="index.php" class="back-link">← 返回大厅</a>
        <div class="target-info">
            <div class="target-avatar">
                <img src="<?= $avatarUrl ?>" alt="avatar">
            </div>
            <div>
                <span class="target-name"><?= htmlspecialchars($friend['username']) ?></span>
                <?php if (!empty($friend['title'])): ?>
                    <span class="target-title">「<?= htmlspecialchars($friend['title']) ?>」</span>
                <?php endif; ?>
            </div>
        </div>
        <div class="theme-toggle">
            <button id="themeBtn">☀️</button>
        </div>
    </div>

    <div class="messages-box" id="messagesBox">
        <?php foreach ($initialMessages as $msg): ?>
            <div class="message <?= $msg['from_user_id'] == $user['id'] ? 'own' : '' ?>" data-id="<?= $msg['id'] ?>">
                <div class="message-header">
                    <span class="message-author"><?= htmlspecialchars($msg['username']) ?></span>
                    <?php if (!empty($msg['title'])): ?>
                        <span class="message-title">【<?= htmlspecialchars($msg['title']) ?>】</span>
                    <?php endif; ?>
                    <span class="message-time"><?= date('H:i', $msg['created_at']) ?></span>
                </div>
                <div class="message-content">
                    <?php if ($msg['image']): ?>
                        <img src="<?= htmlspecialchars($msg['image']) ?>" onclick="window.open(this.src)">
                    <?php else: ?>
                        <?= nl2br(htmlspecialchars($msg['content'])) ?>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="message-input-area">
        <button id="imageBtn">📷</button>
        <input type="file" id="imageInput" accept="image/jpeg,image/png,image/gif,image/webp" style="display:none">
        <input type="text" id="messageInput" placeholder="说点什么..." autocomplete="off">
        <button id="sendBtn">发送</button>
    </div>
</div>

<script>
// ========== 深色模式 ==========
(function() {
    var themeBtn = document.getElementById('themeBtn');
    if (!themeBtn) return;
    var saved = localStorage.getItem('theme');
    if (saved === 'light') {
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

// ========== 私聊核心 ==========
var currentUserId = <?= $user['id'] ?>;
var targetUserId = <?= $friendId ?>;
var lastId = 0;
var pollTimer = null;

// 滚动到底部函数
function scrollToBottom() {
    var box = document.getElementById('messagesBox');
    if (box) {
        box.scrollTop = box.scrollHeight;
    }
}

function updateLastId() {
    var msgs = document.querySelectorAll('.message');
    for (var i = 0; i < msgs.length; i++) {
        var id = parseInt(msgs[i].getAttribute('data-id'));
        if (id > lastId) lastId = id;
    }
}

function escapeHtml(str) {
    if (!str) return '';
    return str.replace(/[&<>]/g, function(m) {
        if (m === '&') return '&amp;';
        if (m === '<') return '&lt;';
        if (m === '>') return '&gt;';
        return m;
    });
}

function formatTime(ts) {
    var d = new Date(ts * 1000);
    return d.getHours().toString().padStart(2,'0') + ':' + d.getMinutes().toString().padStart(2,'0');
}

function addMessage(msg) {
    var box = document.getElementById('messagesBox');
    var div = document.createElement('div');
    div.className = 'message' + (msg.from_user_id == currentUserId ? ' own' : '');
    div.setAttribute('data-id', msg.id);
    var titleHtml = msg.title ? ' <span class="message-title">【' + escapeHtml(msg.title) + '】</span>' : '';
    var contentHtml = msg.image ? 
        '<img src="' + escapeHtml(msg.image) + '" onclick="window.open(this.src)">' : 
        escapeHtml(msg.content);
    div.innerHTML = '<div class="message-header">' +
        '<span class="message-author">' + escapeHtml(msg.username) + '</span>' +
        titleHtml +
        '<span class="message-time">' + formatTime(msg.created_at) + '</span>' +
        '</div>' +
        '<div class="message-content">' + contentHtml + '</div>';
    box.appendChild(div);
    // 添加消息后滚动到底部
    scrollToBottom();
}

function loadMessages() {
    var xhr = new XMLHttpRequest();
    xhr.open('GET', 'api/get_private_messages.php?friend_id=' + targetUserId + '&after=' + lastId, true);
    xhr.withCredentials = true;
    xhr.onreadystatechange = function() {
        if (xhr.readyState === 4 && xhr.status === 200) {
            try {
                var data = JSON.parse(xhr.responseText);
                if (data.messages && data.messages.length) {
                    var newMsgAdded = false;
                    for (var i = 0; i < data.messages.length; i++) {
                        var msg = data.messages[i];
                        if (msg.id > lastId) {
                            addMessage(msg);
                            lastId = msg.id;
                            newMsgAdded = true;
                        }
                    }
                    // 如果有新消息，确保滚动到底部
                    if (newMsgAdded) {
                        scrollToBottom();
                    }
                }
            } catch(e) { console.error(e); }
        } else if (xhr.status === 401) {
            window.location.href = 'login.php';
        }
    };
    xhr.send();
}

function sendMessage() {
    var input = document.getElementById('messageInput');
    var content = input.value.trim();
    if (!content) return;

    var xhr = new XMLHttpRequest();
    xhr.open('POST', 'api/send_private_message.php', true);
    xhr.withCredentials = true;
    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
    xhr.onreadystatechange = function() {
        if (xhr.readyState === 4 && xhr.status === 200) {
            try {
                var res = JSON.parse(xhr.responseText);
                if (res.success) {
                    input.value = '';
                    loadMessages();
                    scrollToBottom();
                } else {
                    alert(res.error || '发送失败');
                }
            } catch(e) { alert('发送失败'); }
        }
    };
    xhr.send('friend_id=' + targetUserId + '&content=' + encodeURIComponent(content));
}

function sendImage(file) {
    var fd = new FormData();
    fd.append('image', file);
    fd.append('friend_id', targetUserId);
    
    var reader = new FileReader();
    reader.onload = function(e) {
        var box = document.getElementById('messagesBox');
        var tempDiv = document.createElement('div');
        tempDiv.className = 'message own sending';
        tempDiv.innerHTML = '<div class="message-header">' +
            '<span class="message-author"><?= htmlspecialchars($user['username']) ?></span>' +
            '<span class="message-time">发送中...</span>' +
            '</div>' +
            '<div class="message-content"><img src="' + e.target.result + '" style="max-width:100%; max-height:150px;"></div>';
        box.appendChild(tempDiv);
        scrollToBottom();
        
        var xhr = new XMLHttpRequest();
        xhr.open('POST', 'api/send_private_image.php', true);
        xhr.withCredentials = true;
        xhr.onreadystatechange = function() {
            if (xhr.readyState === 4 && xhr.status === 200) {
                try {
                    var res = JSON.parse(xhr.responseText);
                    if (res.success) {
                        if (tempDiv.parentNode) tempDiv.remove();
                        loadMessages();
                        scrollToBottom();
                    } else {
                        tempDiv.classList.remove('sending');
                        tempDiv.classList.add('failed');
                        tempDiv.querySelector('.message-time').textContent = '发送失败';
                        alert(res.error || '图片发送失败');
                    }
                } catch(e) {
                    tempDiv.classList.remove('sending');
                    tempDiv.classList.add('failed');
                    tempDiv.querySelector('.message-time').textContent = '发送失败';
                    alert('图片发送失败');
                }
            } else if (xhr.readyState === 4) {
                tempDiv.classList.remove('sending');
                tempDiv.classList.add('failed');
                tempDiv.querySelector('.message-time').textContent = '发送失败';
                alert('网络错误');
            }
        };
        xhr.send(fd);
    };
    reader.readAsDataURL(file);
}

function init() {
    updateLastId();
    
    // 先加载消息，然后滚动到底部
    loadMessages();
    // 延迟滚动确保消息渲染完成
    setTimeout(scrollToBottom, 200);
    
    document.getElementById('sendBtn').addEventListener('click', sendMessage);
    document.getElementById('messageInput').addEventListener('keypress', function(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            sendMessage();
        }
    });
    
    var imageBtn = document.getElementById('imageBtn');
    var imageInput = document.getElementById('imageInput');
    imageBtn.addEventListener('click', function() { imageInput.click(); });
    imageInput.addEventListener('change', function(e) {
        if (e.target.files.length) {
            var file = e.target.files[0];
            if (file.size > 10 * 1024 * 1024) {
                alert('图片不能超过10MB');
                imageInput.value = '';
                return;
            }
            sendImage(file);
            imageInput.value = '';
        }
    });
    
    // 轮询新消息
    pollTimer = setInterval(loadMessages, 3000);
}

init();
</script>
</body>
</html>