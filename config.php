<?php
// 设置时区为中国时区
date_default_timezone_set('Asia/Shanghai');

// 数据库路径
define('DB_PATH', __DIR__ . '/database/chat.db');

// 默认管理员账号（首次运行自动创建）
define('ADMIN_USERNAME', 'admin');
define('ADMIN_PASSWORD', 'admin123');
define('ADMIN_EMAIL', 'admin@example.com');

// 站点配置
define('SITE_NAME', '信Talk');
define('SITE_THEME_COLOR', '#4ECDC4');
define('MESSAGE_REFRESH_INTERVAL', 3000);
define('MAX_AVATAR_SIZE', 2 * 1024 * 1024);
define('ALLOWED_AVATAR_TYPES', ['image/jpeg', 'image/png', 'image/gif', 'image/webp']);

// 默认配置
define('DEFAULT_RATE_LIMIT', 10);
define('DEFAULT_RATE_LIMIT_WINDOW', 60);

// 记住我配置
define('REMEMBER_ME_EXPIRE', 30 * 24 * 3600);
define('REMEMBER_ME_COOKIE_NAME', 'chat_remember');

// 撤回消息时间限制（秒）
define('MESSAGE_RECALL_TIME', 120);
?>