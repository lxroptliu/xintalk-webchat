<?php
// 设置时区
date_default_timezone_set('Asia/Shanghai');

// 设置会话保存路径（仅在未启动时设置）
if (session_status() === PHP_SESSION_NONE) {
    $sessionPath = __DIR__ . '/sessions';
    if (!file_exists($sessionPath)) {
        mkdir($sessionPath, 0755, true);
    }
    session_save_path($sessionPath);
    session_start();
}

require_once 'config.php';
require_once 'db.php';

// ============================================
// 基础功能函数
// ============================================

function isLoggedIn() {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

function currentUser() {
    if (!isLoggedIn()) return null;
    try {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return null;
    }
}

function updateLastActivity($userId) {
    try {
        $pdo = Database::getConnection();
        $timestamp = time();
        $stmt = $pdo->prepare("UPDATE users SET last_activity = ? WHERE id = ?");
        $stmt->execute([$timestamp, $userId]);
    } catch (Exception $e) {}
}

function isAdmin() {
    $user = currentUser();
    return $user && $user['role'] === 'admin';
}

function isSuperAdmin() {
    $user = currentUser();
    return $user && $user['id'] == 1;
}

function canBan() {
    $user = currentUser();
    if (!$user) return false;
    return $user['id'] == 1 || $user['id'] == 2;
}

function canMaintain() {
    $user = currentUser();
    if (!$user) return false;
    return $user['id'] == 1;
}

function isProtectedUser($userId) {
    return $userId == 1 || $userId == 2;
}

function canModifyUser($targetUserId) {
    if (isProtectedUser($targetUserId)) return false;
    if ($targetUserId == $_SESSION['user_id']) return false;
    return true;
}

function redirect($url, $message = null, $type = 'error') {
    if ($message) {
        $_SESSION['flash'] = ['message' => $message, 'type' => $type];
    }
    header('Location: ' . $url);
    exit;
}

// ============================================
// 头像相关函数
// ============================================

function getAvatarUrl($avatar, $username = '') {
    if (!empty($avatar) && $avatar !== 'default.jpg' && $avatar !== null) {
        $avatarPath = __DIR__ . '/uploads/' . $avatar;
        if (file_exists($avatarPath)) {
            return 'uploads/' . $avatar;
        }
    }
    return 'avatar.php?name=' . urlencode($username) . '&size=64&background=FF8A3C&color=fff';
}

function handleAvatarUpload($file, $userId) {
    $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
    $maxSize = 2 * 1024 * 1024;
    
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['success' => false, 'error' => '上传失败'];
    }
    if (!in_array($file['type'], $allowedTypes)) {
        return ['success' => false, 'error' => '仅支持 JPG, PNG, GIF, WEBP 格式'];
    }
    if ($file['size'] > $maxSize) {
        return ['success' => false, 'error' => '文件大小不能超过2MB'];
    }
    
    $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
    $filename = 'avatar_' . $userId . '_' . time() . '.' . $extension;
    $uploadPath = __DIR__ . '/uploads/' . $filename;
    
    if (move_uploaded_file($file['tmp_name'], $uploadPath)) {
        return ['success' => true, 'filename' => $filename];
    }
    return ['success' => false, 'error' => '保存失败'];
}

function compressImage($source, $destination, $quality) {
    $info = getimagesize($source);
    if ($info['mime'] == 'image/jpeg') {
        $image = imagecreatefromjpeg($source);
        imagejpeg($image, $destination, $quality);
        imagedestroy($image);
    } elseif ($info['mime'] == 'image/png') {
        $image = imagecreatefrompng($source);
        imagepng($image, $destination, floor($quality / 10));
        imagedestroy($image);
    }
}

function getRandomDefaultAvatar() {
    return null;
}

// ============================================
// 在线状态相关函数
// ============================================

function getUserOnlineStatus($userId) {
    try {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("SELECT last_activity FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $lastActivity = $stmt->fetchColumn();
        if (!$lastActivity) return false;
        return (time() - $lastActivity) < 300;
    } catch (Exception $e) {
        return false;
    }
}

function getUserTypingStatus($userId) {
    try {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("SELECT last_typing FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $lastTyping = $stmt->fetchColumn();
        if (!$lastTyping) return false;
        return (time() - $lastTyping) < 10;
    } catch (Exception $e) {
        return false;
    }
}

function updateUserTyping($userId) {
    try {
        $pdo = Database::getConnection();
        $timestamp = time();
        $stmt = $pdo->prepare("UPDATE users SET last_typing = ? WHERE id = ?");
        $stmt->execute([$timestamp, $userId]);
    } catch (Exception $e) {}
}

function getOnlineCount() {
    try {
        $pdo = Database::getConnection();
        $fiveMinutesAgo = time() - 300;
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM users WHERE last_activity > ?");
        $stmt->execute([$fiveMinutesAgo]);
        return (int)$stmt->fetchColumn();
    } catch (Exception $e) {
        return 0;
    }
}

// ============================================
// 好友功能函数
// ============================================

function areFriends($userId, $friendId) {
    try {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("SELECT id FROM friends WHERE ((user_id = ? AND friend_id = ?) OR (user_id = ? AND friend_id = ?)) AND status = 'accepted'");
        $stmt->execute([$userId, $friendId, $friendId, $userId]);
        return (bool)$stmt->fetch();
    } catch (Exception $e) {
        return false;
    }
}

function getFriends($userId) {
    try {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("
            SELECT u.id, u.username, u.avatar, u.title, u.last_activity, u.last_typing 
            FROM friends f 
            JOIN users u ON (f.friend_id = u.id OR f.user_id = u.id) 
            WHERE (f.user_id = ? OR f.friend_id = ?) 
              AND f.status = 'accepted' 
              AND u.id != ?
        ");
        $stmt->execute([$userId, $userId, $userId]);
        $friends = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // 去重
        $unique = [];
        foreach ($friends as $f) {
            $unique[$f['id']] = $f;
        }
        
        // 添加额外字段
        foreach ($unique as &$friend) {
            $friend['avatar_url'] = getAvatarUrl($friend['avatar'], $friend['username']);
            $friend['is_online'] = getUserOnlineStatus($friend['id']);
            $friend['unread'] = getUnreadCount($userId, $friend['id']);
        }
        
        return array_values($unique);
    } catch (Exception $e) {
        return [];
    }
}

function getPendingRequests($userId) {
    try {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("
            SELECT f.id, u.id as user_id, u.username, u.avatar, u.title, f.created_at 
            FROM friends f 
            JOIN users u ON f.user_id = u.id 
            WHERE f.friend_id = ? AND f.status = 'pending'
        ");
        $stmt->execute([$userId]);
        $requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($requests as &$req) {
            $req['avatar_url'] = getAvatarUrl($req['avatar'], $req['username']);
        }
        return $requests;
    } catch (Exception $e) {
        return [];
    }
}

function getUnreadCount($userId, $friendId) {
    try {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM private_messages WHERE from_user_id = ? AND to_user_id = ? AND is_read = 0");
        $stmt->execute([$friendId, $userId]);
        return (int)$stmt->fetchColumn();
    } catch (Exception $e) {
        return 0;
    }
}

function getUsernameById($id) {
    static $cache = [];
    if (isset($cache[$id])) return $cache[$id];
    try {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("SELECT username FROM users WHERE id = ?");
        $stmt->execute([$id]);
        $cache[$id] = $stmt->fetchColumn();
        return $cache[$id];
    } catch (Exception $e) {
        return '未知用户';
    }
}

// ============================================
// ========== index.php 需要的函数 ==========
// ============================================

/**
 * 获取在线用户列表（含称号）
 */
function getOnlineUsers() {
    try {
        $pdo = Database::getConnection();
        $fiveMinutesAgo = time() - 300;
        $stmt = $pdo->prepare("
            SELECT id, username, avatar, title, last_activity, last_typing 
            FROM users 
            WHERE last_activity > ?
            ORDER BY username
        ");
        $stmt->execute([$fiveMinutesAgo]);
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($users as &$user) {
            $user['avatar_url'] = getAvatarUrl($user['avatar'], $user['username']);
            $user['is_online'] = true;
            $user['is_typing'] = (time() - ($user['last_typing'] ?? 0)) < 10;
        }
        return $users;
    } catch (Exception $e) {
        return [];
    }
}

/**
 * 获取好友请求列表（别名，兼容 index.php）
 */
function getFriendRequests($userId) {
    return getPendingRequests($userId);
}

/**
 * 获取所有好友的未读私聊消息数
 */
function getUnreadPrivateCounts($userId) {
    try {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("
            SELECT from_user_id, COUNT(*) as unread 
            FROM private_messages 
            WHERE to_user_id = ? AND is_read = 0
            GROUP BY from_user_id
        ");
        $stmt->execute([$userId]);
        $result = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $result[$row['from_user_id']] = $row['unread'];
        }
        return $result;
    } catch (Exception $e) {
        return [];
    }
}

/**
 * 判断用户是否在线
 */
function isOnline($userId) {
    return getUserOnlineStatus($userId);
}

// ============================================
// 封禁相关函数
// ============================================

function isBanned($userId) {
    try {
        $pdo = Database::getConnection();
        $now = time();
        $stmt = $pdo->prepare("SELECT id, reason, expires_at FROM banned_users WHERE user_id = ? AND (expires_at IS NULL OR expires_at > ?)");
        $stmt->execute([$userId, $now]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return false;
    }
}

function getBanInfo($userId) {
    try {
        $pdo = Database::getConnection();
        $now = time();
        $stmt = $pdo->prepare("SELECT reason, expires_at FROM banned_users WHERE user_id = ? AND (expires_at IS NULL OR expires_at > ?)");
        $stmt->execute([$userId, $now]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return null;
    }
}

function banUser($userId, $bannedBy, $reason = '', $hours = 0) {
    try {
        $pdo = Database::getConnection();
        $expires = $hours > 0 ? time() + ($hours * 3600) : null;
        $pdo->prepare("DELETE FROM banned_users WHERE user_id = ?")->execute([$userId]);
        $stmt = $pdo->prepare("INSERT INTO banned_users (user_id, reason, expires_at, banned_by) VALUES (?, ?, ?, ?)");
        return $stmt->execute([$userId, $reason, $expires, $bannedBy]);
    } catch (Exception $e) {
        return false;
    }
}

function unbanUser($userId) {
    try {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("DELETE FROM banned_users WHERE user_id = ?");
        return $stmt->execute([$userId]);
    } catch (Exception $e) {
        return false;
    }
}

// ============================================
// 日志相关函数
// ============================================

function logAdminAction($action, $targetId = null, $details = null) {
    if (!isLoggedIn()) return;
    try {
        $pdo = Database::getConnection();
        $timestamp = time();
        $stmt = $pdo->prepare("INSERT INTO admin_logs (admin_id, action, target_id, details, ip, created_at) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$_SESSION['user_id'], $action, $targetId, $details, $_SERVER['REMOTE_ADDR'], $timestamp]);
    } catch (Exception $e) {}
}

// ============================================
// 服务器状态函数
// ============================================

function getServerStatus() {
    try {
        $pdo = Database::getConnection();
        $onlineCount = getOnlineCount();
        $totalUsers = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
        $messageCount = $pdo->query("SELECT COUNT(*) FROM messages")->fetchColumn();
        $privateCount = $pdo->query("SELECT COUNT(*) FROM private_messages")->fetchColumn();
        $dbSize = file_exists(DB_PATH) ? filesize(DB_PATH) : 0;
        $uploadSize = 0;
        if (is_dir(__DIR__ . '/uploads')) {
            $files = scandir(__DIR__ . '/uploads');
            foreach ($files as $file) {
                if ($file != '.' && $file != '..' && $file != 'defaults' && !is_dir(__DIR__ . '/uploads/' . $file)) {
                    $uploadSize += filesize(__DIR__ . '/uploads/' . $file);
                }
            }
        }
        return [
            'online' => $onlineCount,
            'total_users' => $totalUsers,
            'total_messages' => $messageCount,
            'total_private' => $privateCount,
            'db_size' => $dbSize,
            'upload_size' => $uploadSize,
            'php_memory' => memory_get_usage(true),
            'php_limit' => ini_get('memory_limit')
        ];
    } catch (Exception $e) {
        return [
            'online' => 0,
            'total_users' => 0,
            'total_messages' => 0,
            'total_private' => 0,
            'db_size' => 0,
            'upload_size' => 0,
            'php_memory' => 0,
            'php_limit' => 'unknown'
        ];
    }
}

// ============================================
// 敏感词过滤函数
// ============================================

function filterSensitiveWords($text) {
    try {
        $pdo = Database::getConnection();
        $stmt = $pdo->query("SELECT word, replacement FROM sensitive_words");
        $words = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($words as $w) {
            $text = str_ireplace($w['word'], $w['replacement'], $text);
        }
        return $text;
    } catch (Exception $e) {
        return $text;
    }
}

// ============================================
// 频率限制函数
// ============================================

function checkRateLimit($userId) {
    if ($userId == 1 || $userId == 2) return true;
    try {
        $pdo = Database::getConnection();
        $limit = $pdo->query("SELECT value FROM system_config WHERE key = 'rate_limit'")->fetchColumn();
        $limit = $limit ?: 10;
        $window = $pdo->query("SELECT value FROM system_config WHERE key = 'rate_limit_window'")->fetchColumn();
        $window = $window ?: 60;
        $windowAgo = time() - $window;
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM messages WHERE user_id = ? AND created_at > ?");
        $stmt->execute([$userId, $windowAgo]);
        return $stmt->fetchColumn() < $limit;
    } catch (Exception $e) {
        return true;
    }
}

// ============================================
// 公告相关函数
// ============================================

function getCurrentAnnouncement() {
    try {
        $pdo = Database::getConnection();
        $now = time();
        $stmt = $pdo->prepare("SELECT id, title, content, created_at FROM announcements WHERE expires_at IS NULL OR expires_at > ? ORDER BY id DESC LIMIT 1");
        $stmt->execute([$now]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return null;
    }
}

// ============================================
// 记住我/会话管理函数
// ============================================

function generateToken($length = 64) {
    return bin2hex(random_bytes($length));
}

function createRememberSession($userId, $deviceInfo = '', $ip = '') {
    try {
        $pdo = Database::getConnection();
        $token = generateToken();
        $expires = time() + REMEMBER_ME_EXPIRE;
        $stmt = $pdo->prepare("INSERT INTO login_sessions (user_id, session_token, device_info, ip, expires_at, last_used) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$userId, $token, $deviceInfo, $ip, $expires, time()]);
        return $token;
    } catch (Exception $e) {
        return null;
    }
}

function validateRememberToken($token) {
    try {
        $pdo = Database::getConnection();
        $now = time();
        $stmt = $pdo->prepare("SELECT user_id FROM login_sessions WHERE session_token = ? AND expires_at > ?");
        $stmt->execute([$token, $now]);
        $session = $stmt->fetch();
        if ($session) {
            $stmt = $pdo->prepare("UPDATE login_sessions SET last_used = ? WHERE session_token = ?");
            $stmt->execute([$now, $token]);
            return $session['user_id'];
        }
        return null;
    } catch (Exception $e) {
        return null;
    }
}

function deleteRememberSession($token) {
    try {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("DELETE FROM login_sessions WHERE session_token = ?");
        $stmt->execute([$token]);
    } catch (Exception $e) {}
}

function deleteAllUserSessions($userId) {
    try {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("DELETE FROM login_sessions WHERE user_id = ?");
        $stmt->execute([$userId]);
    } catch (Exception $e) {}
}

function getUserSessions($userId) {
    try {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("SELECT id, device_info, ip, created_at, last_used, expires_at FROM login_sessions WHERE user_id = ? ORDER BY last_used DESC");
        $stmt->execute([$userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        return [];
    }
}
?>