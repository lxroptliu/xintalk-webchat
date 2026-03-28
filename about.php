<?php
// about.php - 关于页面
require_once 'functions.php';

// 检查登录状态
$isLoggedIn = isLoggedIn();
$user = $isLoggedIn ? currentUser() : null;

// 只有超级管理员（ID=1）和副号（ID=2）可以查看反馈记录
$canViewFeedback = $isLoggedIn && ($user['id'] == 1 || $user['id'] == 2);

// 处理反馈提交
$feedbackSuccess = '';
$feedbackError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_feedback'])) {
    $type = $_POST['type'] ?? 'suggestion';
    $content = trim($_POST['content'] ?? '');
    $contact = trim($_POST['contact'] ?? '');
    
    if (empty($content)) {
        $feedbackError = '请填写反馈内容';
    } elseif (strlen($content) < 5) {
        $feedbackError = '反馈内容至少5个字符';
    } else {
        // 保存反馈到文件
        $feedbackDir = __DIR__ . '/feedback';
        if (!file_exists($feedbackDir)) {
            mkdir($feedbackDir, 0755, true);
        }
        
        $filename = $feedbackDir . '/feedback_' . date('Y-m-d') . '.log';
        $logEntry = date('Y-m-d H:i:s') . ' | ' . 
                   ($user ? $user['username'] . '(' . $user['id'] . ')' : '游客') . ' | ' .
                   $type . ' | ' . 
                   str_replace(["\n", "\r"], ' ', $content) . ' | ' .
                   $contact . "\n";
        
        if (file_put_contents($filename, $logEntry, FILE_APPEND | LOCK_EX)) {
            $feedbackSuccess = '感谢您的反馈！我们会尽快处理。';
        } else {
            $feedbackError = '提交失败，请稍后重试';
        }
    }
}

// 获取反馈列表（仅超级管理员和副号可见）
$feedbackList = [];
if ($canViewFeedback) {
    $feedbackDir = __DIR__ . '/feedback';
    if (file_exists($feedbackDir)) {
        $files = glob($feedbackDir . '/feedback_*.log');
        rsort($files);
        foreach ($files as $file) {
            $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            $lines = array_reverse($lines);
            foreach ($lines as $line) {
                $feedbackList[] = $line;
            }
        }
        $feedbackList = array_slice($feedbackList, 0, 100);
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=yes">
    <title>关于 · 信Talk</title>
    <link rel="stylesheet" href="style.css">
    <style>
        /* 与 index.php 完全一致的配色 */
        :root {
            --bg-deep: #0A0C14;
            --bg-card: rgba(15, 22, 35, 0.95);
            --bg-sidebar: rgba(8, 14, 24, 0.98);
            --text-main: #F0F4FA;
            --text-muted: #9AAEC2;
            --border-color: #2E405B;
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
            flex-direction: column;
            font-family: 'Segoe UI', 'PingFang SC', 'Microsoft YaHei', sans-serif;
            transition: background 0.2s ease;
        }

        /* 头部导航 - 与 index.php 头部一致 */
        .nav-header {
            padding: 1rem 2rem;
            background: rgba(0,0,0,0.2);
            border-bottom: 2px solid var(--accent-primary);
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 1rem;
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

        .nav-links {
            display: flex;
            gap: 0.5rem;
            align-items: center;
            flex-wrap: wrap;
        }

        .nav-links a {
            color: var(--text-muted);
            text-decoration: none;
            padding: 0.5rem 1rem;
            border-radius: 2rem;
            transition: 0.2s;
        }

        .nav-links a:hover {
            background: rgba(78, 205, 196, 0.1);
            color: var(--accent-primary);
        }

        .nav-links a.active {
            color: var(--accent-primary);
            border: 1px solid var(--accent-primary);
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

        /* 主内容区 */
        .container {
            flex: 1;
            max-width: 1000px;
            margin: 0 auto;
            padding: 2rem;
            width: 100%;
        }

        /* 卡片样式 - 与 index.php 侧边栏风格一致 */
        .about-card {
            background: var(--bg-card);
            backdrop-filter: blur(20px);
            border-radius: 2rem;
            border: 2px solid var(--accent-primary);
            overflow: hidden;
            margin-bottom: 2rem;
        }

        .card-header {
            padding: 1.5rem 2rem;
            background: rgba(78, 205, 196, 0.1);
            border-bottom: 1px solid var(--border-color);
        }

        .card-header h1 {
            color: var(--accent-primary);
            font-size: 1.5rem;
            display: flex;
            align-items: center;
            gap: 0.8rem;
        }

        .card-body {
            padding: 2rem;
        }

        .intro-text {
            font-size: 1rem;
            line-height: 1.6;
            color: var(--text-main);
            margin-bottom: 1.5rem;
        }

        .section-title {
            color: var(--accent-primary);
            font-size: 1.2rem;
            margin-bottom: 1rem;
            padding-bottom: 0.5rem;
            border-bottom: 1px solid var(--border-color);
        }

        .feature-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 1rem;
            margin: 1.5rem 0;
        }

        .feature-item {
            background: rgba(78, 205, 196, 0.05);
            border-radius: 1rem;
            padding: 1rem;
            border: 1px solid rgba(78, 205, 196, 0.2);
            transition: 0.2s;
        }

        .feature-item:hover {
            border-color: var(--accent-primary);
            transform: translateY(-2px);
        }

        .feature-icon {
            font-size: 1.8rem;
            margin-bottom: 0.5rem;
        }

        .feature-title {
            font-weight: bold;
            color: var(--accent-primary);
            margin-bottom: 0.3rem;
            font-size: 1rem;
        }

        .feature-desc {
            color: var(--text-muted);
            font-size: 0.85rem;
            line-height: 1.4;
        }

        /* 反馈表单 */
        .feedback-form {
            margin-top: 1rem;
        }

        .form-group {
            margin-bottom: 1rem;
        }

        .form-group label {
            display: block;
            margin-bottom: 0.4rem;
            color: var(--text-main);
            font-weight: 500;
            font-size: 0.9rem;
        }

        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 0.8rem 1rem;
            border-radius: 2rem;
            border: 1px solid var(--border-color);
            background: var(--input-bg);
            color: var(--text-main);
            font-size: 0.9rem;
            outline: none;
            font-family: inherit;
        }

        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            border-color: var(--accent-primary);
            box-shadow: 0 0 0 2px rgba(78, 205, 196, 0.2);
        }

        .form-group textarea {
            border-radius: 1rem;
            resize: vertical;
            min-height: 100px;
        }

        .submit-btn {
            background: linear-gradient(145deg, var(--accent-primary), var(--accent-secondary));
            color: white;
            border: none;
            padding: 0.7rem 1.8rem;
            border-radius: 2rem;
            font-weight: bold;
            cursor: pointer;
            font-size: 0.9rem;
            transition: 0.2s;
        }

        .submit-btn:hover {
            opacity: 0.9;
            transform: scale(1.02);
        }

        .success-message {
            background: rgba(78, 205, 196, 0.15);
            color: #4ECDC4;
            padding: 0.8rem;
            border-radius: 1rem;
            margin-bottom: 1rem;
            text-align: center;
        }

        .error-message {
            background: rgba(255, 107, 107, 0.15);
            color: #FF6B6B;
            padding: 0.8rem;
            border-radius: 1rem;
            margin-bottom: 1rem;
            text-align: center;
        }

        /* 反馈列表表格（仅管理员可见） */
        .feedback-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 1rem;
            font-size: 0.85rem;
        }

        .feedback-table th,
        .feedback-table td {
            padding: 0.6rem;
            text-align: left;
            border-bottom: 1px solid var(--border-color);
        }

        .feedback-table th {
            color: var(--accent-primary);
            font-weight: 600;
        }

        .feedback-table tr:hover {
            background: rgba(78, 205, 196, 0.05);
        }

        .feedback-type-bug {
            color: #FF6B6B;
        }
        .feedback-type-suggestion {
            color: #4ECDC4;
        }
        .feedback-type-question {
            color: #FFB347;
        }

        /* 联系方式 */
        .contact-info {
            margin-top: 1.5rem;
            padding-top: 1rem;
            border-top: 1px solid var(--border-color);
            text-align: center;
            color: var(--text-muted);
            font-size: 0.85rem;
        }

        .contact-info a {
            color: var(--accent-primary);
            text-decoration: none;
        }

        .contact-info a:hover {
            text-decoration: underline;
        }

        /* 页脚 */
        .footer {
            text-align: center;
            padding: 1rem;
            color: var(--text-muted);
            font-size: 0.75rem;
            border-top: 1px solid var(--border-color);
            background: var(--bg-card);
        }

        @media (max-width: 768px) {
            .nav-header {
                padding: 0.8rem 1rem;
            }
            .container {
                padding: 1rem;
            }
            .card-header {
                padding: 1rem 1.5rem;
            }
            .card-body {
                padding: 1.5rem;
            }
            .feature-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="nav-header">
        <div class="logo">
            <span>💬</span>
            <span>信Talk</span>
        </div>
        <div class="nav-links">
            <a href="index.php">聊天室</a>
            <a href="about.php" class="active">关于</a>
            <?php if ($isLoggedIn): ?>
                <a href="profile.php"><?= htmlspecialchars($user['username']) ?></a>
                <a href="logout.php">退出</a>
            <?php else: ?>
                <a href="login.php">登录</a>
                <a href="register.php">注册</a>
            <?php endif; ?>
        </div>
        <div class="theme-toggle">
            <button id="themeBtn">☀️</button>
        </div>
    </div>

    <div class="container">
        <!-- 关于卡片 -->
        <div class="about-card">
            <div class="card-header">
                <h1>
                    <span>✨</span>
                    关于信Talk
                </h1>
            </div>
            <div class="card-body">
                <div class="intro-text">
                    <strong>信Talk</strong> 是一个简洁、安全、实时的在线聊天室。
                    在这里，你可以与朋友私聊、分享图片、自定义称号，享受纯净的聊天体验。
                </div>

                <div class="section-title">🎯 核心功能</div>
                <div class="feature-grid">
                    <div class="feature-item">
                        <div class="feature-icon">💬</div>
                        <div class="feature-title">公共聊天室</div>
                        <div class="feature-desc">实时消息，与所有在线用户畅聊</div>
                    </div>
                    <div class="feature-item">
                        <div class="feature-icon">🔒</div>
                        <div class="feature-title">私密私聊</div>
                        <div class="feature-desc">一对一私密对话，保护隐私</div>
                    </div>
                    <div class="feature-item">
                        <div class="feature-icon">📸</div>
                        <div class="feature-title">图片分享</div>
                        <div class="feature-desc">支持图片上传，即时预览</div>
                    </div>
                    <div class="feature-item">
                        <div class="feature-icon">🏷️</div>
                        <div class="feature-title">自定义称号</div>
                        <div class="feature-desc">个性化称号，彰显独特身份</div>
                    </div>
                    <div class="feature-item">
                        <div class="feature-icon">👥</div>
                        <div class="feature-title">好友系统</div>
                        <div class="feature-desc">添加好友，快速私聊</div>
                    </div>
                    <div class="feature-item">
                        <div class="feature-icon">🌙</div>
                        <div class="feature-title">深色模式</div>
                        <div class="feature-desc">护眼舒适，日夜皆宜</div>
                    </div>
                </div>

                <div class="section-title">📋 技术信息</div>
                <div class="feature-grid" style="grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));">
                    <div class="feature-item">
                        <div class="feature-icon">⚡</div>
                        <div class="feature-title">实时通信</div>
                        <div class="feature-desc">3秒轮询，消息及时送达</div>
                    </div>
                    <div class="feature-item">
                        <div class="feature-icon">🛡️</div>
                        <div class="feature-title">安全防护</div>
                        <div class="feature-desc">敏感词过滤 · 频率限制</div>
                    </div>
                    <div class="feature-item">
                        <div class="feature-icon">📱</div>
                        <div class="feature-title">全端适配</div>
                        <div class="feature-desc">手机/电脑，完美响应</div>
                    </div>
                </div>
            </div>
        </div>

        <!-- 反馈卡片 -->
        <div class="about-card" id="feedback">
            <div class="card-header">
                <h1>
                    <span>📮</span>
                    反馈与建议
                </h1>
            </div>
            <div class="card-body">
                <div class="intro-text">
                    在使用过程中遇到任何问题，或者有好的建议，欢迎填写以下表单反馈给我们。
                    我们会认真阅读每一条反馈，持续改进！
                </div>

                <?php if ($feedbackSuccess): ?>
                    <div class="success-message"><?= htmlspecialchars($feedbackSuccess) ?></div>
                <?php endif; ?>
                
                <?php if ($feedbackError): ?>
                    <div class="error-message"><?= htmlspecialchars($feedbackError) ?></div>
                <?php endif; ?>

                <form method="post" class="feedback-form">
                    <div class="form-group">
                        <label>反馈类型</label>
                        <select name="type">
                            <option value="bug">🐛 Bug报告</option>
                            <option value="suggestion">💡 功能建议</option>
                            <option value="question">❓ 使用问题</option>
                            <option value="other">📝 其他</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label>反馈内容 <span style="color: #FF6B6B;">*</span></label>
                        <textarea name="content" placeholder="请详细描述您的问题或建议..." required minlength="5"></textarea>
                    </div>
                    
                    <div class="form-group">
                        <label>联系方式（选填）</label>
                        <input type="text" name="contact" placeholder="邮箱 / 用户名 / 其他联系方式">
                        <div style="font-size: 0.7rem; color: var(--text-muted); margin-top: 0.2rem;">
                            方便我们回复您
                        </div>
                    </div>
                    
                    <button type="submit" name="submit_feedback" class="submit-btn">📮 提交反馈</button>
                </form>

                <div class="contact-info">
                    <p>📧 直接联系：<a href="mailto:ruansik@163.com">ruansik@163.com</a></p>
                    <p style="margin-top: 0.3rem;">💬 或直接在聊天室 @管理员 反馈</p>
                </div>
            </div>
        </div>

        <!-- 反馈记录（仅超级管理员和副号可见） -->
        <?php if ($canViewFeedback && !empty($feedbackList)): ?>
        <div class="about-card">
            <div class="card-header">
                <h1>
                    <span>📋</span>
                    用户反馈记录
                </h1>
            </div>
            <div class="card-body">
                <div style="overflow-x: auto;">
                    <table class="feedback-table">
                        <thead>
                            <tr>
                                <th>时间</th>
                                <th>用户</th>
                                <th>类型</th>
                                <th>内容</th>
                                <th>联系方式</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($feedbackList as $line): ?>
                            <?php 
                                $parts = explode(' | ', $line);
                                if (count($parts) >= 5):
                                    $typeClass = '';
                                    if (strpos($parts[2], 'bug') !== false) $typeClass = 'feedback-type-bug';
                                    elseif (strpos($parts[2], 'suggestion') !== false) $typeClass = 'feedback-type-suggestion';
                                    elseif (strpos($parts[2], 'question') !== false) $typeClass = 'feedback-type-question';
                            ?>
                            <tr>
                                <td style="white-space: nowrap;"><?= htmlspecialchars($parts[0]) ?></td>
                                <td><?= htmlspecialchars($parts[1]) ?></td>
                                <td class="<?= $typeClass ?>"><?= htmlspecialchars($parts[2]) ?></td>
                                <td><?= htmlspecialchars(mb_substr($parts[3], 0, 100)) ?></td>
                                <td><?= htmlspecialchars($parts[4] ?? '') ?></td>
                            </tr>
                            <?php endif; ?>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <div class="footer">
        <p>© 2026 信Talk ruansik 版权所有</p>
        <p style="font-size: 0.7rem; margin-top: 0.2rem;">让沟通更真诚 · 简洁 · 安全 · 实时</p>
    </div>

    <script>
    // 深色模式切换
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
    </script>
</body>
</html>