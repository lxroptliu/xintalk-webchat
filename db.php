<?php
require_once 'config.php';

class Database {
    private static $pdo = null;

    public static function getConnection() {
        if (self::$pdo === null) {
            try {
                self::$pdo = new PDO('sqlite:' . DB_PATH);
                self::$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                self::$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
                self::$pdo->exec('PRAGMA foreign_keys = ON;');
                self::initTables();
            } catch (PDOException $e) {
                error_log("Database connection error: " . $e->getMessage());
                throw new Exception("数据库连接失败: " . $e->getMessage());
            }
        }
        return self::$pdo;
    }

    private static function initTables() {
        $pdo = self::$pdo;
        
        // 创建 users 表（增加 title 字段）
        $pdo->exec("CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT UNIQUE NOT NULL,
            password TEXT NOT NULL,
            email TEXT,
            avatar TEXT DEFAULT NULL,
            title TEXT DEFAULT '',
            role TEXT DEFAULT 'user',
            created_at INTEGER DEFAULT (strftime('%s', 'now') + 28800),
            last_activity INTEGER,
            last_typing INTEGER
        )");
        
        // 检查并添加 title 列（兼容旧表）
        $columns = $pdo->query("PRAGMA table_info(users)")->fetchAll(PDO::FETCH_ASSOC);
        $hasTitle = false;
        foreach ($columns as $col) {
            if ($col['name'] === 'title') $hasTitle = true;
        }
        if (!$hasTitle) {
            $pdo->exec("ALTER TABLE users ADD COLUMN title TEXT DEFAULT ''");
        }
        
        // 创建 messages 表（增加 is_recalled 字段）
        $pdo->exec("CREATE TABLE IF NOT EXISTS messages (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            username TEXT NOT NULL,
            avatar TEXT,
            content TEXT,
            image TEXT,
            reply_to INTEGER DEFAULT NULL,
            is_recalled INTEGER DEFAULT 0,
            created_at INTEGER DEFAULT (strftime('%s', 'now') + 28800),
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (reply_to) REFERENCES messages(id) ON DELETE SET NULL
        )");
        
        // 检查并添加缺失的列（兼容旧表）
        $columns = $pdo->query("PRAGMA table_info(messages)")->fetchAll(PDO::FETCH_ASSOC);
        $hasImage = false;
        $hasReplyTo = false;
        $hasRecalled = false;
        foreach ($columns as $col) {
            if ($col['name'] === 'image') $hasImage = true;
            if ($col['name'] === 'reply_to') $hasReplyTo = true;
            if ($col['name'] === 'is_recalled') $hasRecalled = true;
        }
        if (!$hasImage) {
            $pdo->exec("ALTER TABLE messages ADD COLUMN image TEXT");
        }
        if (!$hasReplyTo) {
            $pdo->exec("ALTER TABLE messages ADD COLUMN reply_to INTEGER DEFAULT NULL");
        }
        if (!$hasRecalled) {
            $pdo->exec("ALTER TABLE messages ADD COLUMN is_recalled INTEGER DEFAULT 0");
        }
        
        // 好友关系表
        $pdo->exec("CREATE TABLE IF NOT EXISTS friends (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            friend_id INTEGER NOT NULL,
            status TEXT DEFAULT 'pending',
            created_at INTEGER DEFAULT (strftime('%s', 'now') + 28800),
            updated_at INTEGER DEFAULT (strftime('%s', 'now') + 28800),
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (friend_id) REFERENCES users(id) ON DELETE CASCADE,
            UNIQUE(user_id, friend_id)
        )");
        
        // 私聊消息表
        $pdo->exec("CREATE TABLE IF NOT EXISTS private_messages (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            from_user_id INTEGER NOT NULL,
            to_user_id INTEGER NOT NULL,
            content TEXT,
            image TEXT,
            is_read INTEGER DEFAULT 0,
            created_at INTEGER DEFAULT (strftime('%s', 'now') + 28800),
            FOREIGN KEY (from_user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (to_user_id) REFERENCES users(id) ON DELETE CASCADE
        )");
        
        // 检查私聊表是否有 image 列
        $columns = $pdo->query("PRAGMA table_info(private_messages)")->fetchAll(PDO::FETCH_ASSOC);
        $hasPrivateImage = false;
        foreach ($columns as $col) {
            if ($col['name'] === 'image') $hasPrivateImage = true;
        }
        if (!$hasPrivateImage) {
            $pdo->exec("ALTER TABLE private_messages ADD COLUMN image TEXT");
        }
        
        // 封禁用户表
        $pdo->exec("CREATE TABLE IF NOT EXISTS banned_users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            reason TEXT,
            banned_at INTEGER DEFAULT (strftime('%s', 'now') + 28800),
            expires_at INTEGER,
            banned_by INTEGER,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (banned_by) REFERENCES users(id)
        )");
        
        // 操作日志表
        $pdo->exec("CREATE TABLE IF NOT EXISTS admin_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            admin_id INTEGER NOT NULL,
            action TEXT NOT NULL,
            target_id INTEGER,
            details TEXT,
            ip TEXT,
            created_at INTEGER DEFAULT (strftime('%s', 'now') + 28800),
            FOREIGN KEY (admin_id) REFERENCES users(id)
        )");
        
        // 敏感词表
        $pdo->exec("CREATE TABLE IF NOT EXISTS sensitive_words (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            word TEXT UNIQUE NOT NULL,
            replacement TEXT DEFAULT '***',
            created_at INTEGER DEFAULT (strftime('%s', 'now') + 28800)
        )");
        
        // 系统配置表
        $pdo->exec("CREATE TABLE IF NOT EXISTS system_config (
            key TEXT PRIMARY KEY,
            value TEXT,
            updated_at INTEGER DEFAULT (strftime('%s', 'now') + 28800)
        )");
        
        // 公告表
        $pdo->exec("CREATE TABLE IF NOT EXISTS announcements (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            title TEXT NOT NULL,
            content TEXT NOT NULL,
            created_by INTEGER,
            created_at INTEGER DEFAULT (strftime('%s', 'now') + 28800),
            expires_at INTEGER,
            FOREIGN KEY (created_by) REFERENCES users(id)
        )");
        
        // 登录会话表
        $pdo->exec("CREATE TABLE IF NOT EXISTS login_sessions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            session_token TEXT UNIQUE NOT NULL,
            device_info TEXT,
            ip TEXT,
            created_at INTEGER DEFAULT (strftime('%s', 'now') + 28800),
            expires_at INTEGER,
            last_used INTEGER,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        )");
        
        // 初始化默认敏感词
        $stmt = $pdo->query("SELECT COUNT(*) FROM sensitive_words");
        if ($stmt->fetchColumn() == 0) {
            $defaultWords = [
                ['fuck', '***'], ['shit', '***'], ['damn', '***'],
                ['操', '***'], ['妈', '***'], ['逼', '***'],
                ['垃圾', '***'], ['傻逼', '***'], ['白痴', '***']
            ];
            $stmt = $pdo->prepare("INSERT INTO sensitive_words (word, replacement) VALUES (?, ?)");
            foreach ($defaultWords as $w) {
                $stmt->execute($w);
            }
        }
        
        // 初始化默认系统配置
        $stmt = $pdo->query("SELECT COUNT(*) FROM system_config");
        if ($stmt->fetchColumn() == 0) {
            $defaultConfigs = [
                ['rate_limit', DEFAULT_RATE_LIMIT],
                ['rate_limit_window', DEFAULT_RATE_LIMIT_WINDOW],
                ['site_theme', 'dark'],
                ['site_name', SITE_NAME]
            ];
            $stmt = $pdo->prepare("INSERT INTO system_config (key, value) VALUES (?, ?)");
            foreach ($defaultConfigs as $cfg) {
                $stmt->execute($cfg);
            }
        }
        
        // 检查是否存在管理员
        $stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin'");
        if ($stmt->fetchColumn() == 0 && defined('ADMIN_USERNAME') && ADMIN_USERNAME) {
            $hash = password_hash(ADMIN_PASSWORD, PASSWORD_DEFAULT);
            $timestamp = time();
            $stmt = $pdo->prepare("INSERT INTO users (username, password, email, avatar, role, created_at) VALUES (?, ?, ?, NULL, 'admin', ?)");
            $stmt->execute([ADMIN_USERNAME, $hash, ADMIN_EMAIL, $timestamp]);
        }
    }
}
?>