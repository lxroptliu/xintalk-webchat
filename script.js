// ============================================
// 清语聊天室 - 完整脚本（稳定版）
// ============================================

// 全局变量
var lastMessageId = 0;
var oldestMessageId = null;
var REFRESH_INTERVAL = 3000;
var pollTimer = null;
var isSending = false;
var currentReplyTo = null;
var isLoadingMore = false;
var hasMoreMessages = true;

// 发送中的消息记录
window.sendingMessages = {};

// 输入状态相关变量
var typingTimer = null;
var typingInterval = null;

// ============================================
// 工具函数
// ============================================

function escapeHtml(unsafe) {
    if (!unsafe) return '';
    return unsafe.replace(/[&<>]/g, function(m) {
        if (m === '&') return '&amp;';
        if (m === '<') return '&lt;';
        if (m === '>') return '&gt;';
        return m;
    });
}

function truncateText(text, maxLength) {
    if (!text) return '';
    if (text.length <= maxLength) return text;
    return text.substring(0, maxLength) + '...';
}

// ============================================
// 通知功能
// ============================================

function sendNotification(title, body) {
    if (!window.Notification) return;
    if (Notification.permission !== 'granted') return;
    if (document.hasFocus()) return;

    var notification = new Notification(title, { body: body, icon: '/favicon.ico' });
    notification.onclick = function() { window.focus(); notification.close(); };
    setTimeout(function() { notification.close(); }, 5000);
}

function initNotification() {
    if (window.Notification && Notification.permission === 'default') {
        setTimeout(function() { Notification.requestPermission(); }, 2000);
    }
}

// ============================================
// 移动端菜单
// ============================================

function initMobileMenu() {
    var toggle = document.getElementById('menuToggle');
    var sidebar = document.querySelector('.sidebar');
    var chatArea = document.querySelector('.chat-area');
    if (!toggle || !sidebar) return;
    toggle.addEventListener('click', function(e) {
        e.stopPropagation();
        sidebar.classList.toggle('open');
    });
    if (chatArea) {
        chatArea.addEventListener('click', function() {
            if (sidebar.classList.contains('open')) sidebar.classList.remove('open');
        });
    }
}

// ============================================
// 引用消息
// ============================================

window.setReplyTo = function(msgId, username, content) {
    currentReplyTo = { id: msgId, username: username, content: content };
    var preview = document.getElementById('replyPreview');
    var previewContent = preview.querySelector('.reply-preview-content');
    previewContent.innerHTML = '回复 <strong>' + escapeHtml(username) + '</strong>: ' + truncateText(escapeHtml(content), 50);
    preview.style.display = 'flex';
    document.getElementById('messageInput').focus();
};

window.cancelReply = function() {
    currentReplyTo = null;
    var preview = document.getElementById('replyPreview');
    if (preview) preview.style.display = 'none';
};

// ============================================
// 图片上传
// ============================================

function uploadImage(file) {
    var fd = new FormData();
    fd.append('image', file);
    var xhr = new XMLHttpRequest();
    xhr.open('POST', 'api/upload_image.php', true);
    xhr.withCredentials = true;
    xhr.onreadystatechange = function() {
        if (xhr.readyState === 4 && xhr.status === 200) {
            try {
                var res = JSON.parse(xhr.responseText);
                if (res.success) sendMessage(res.url);
                else alert(res.error || '上传失败');
            } catch(e) { alert('上传失败'); }
        }
    };
    xhr.send(fd);
}

function initImageUpload() {
    var btn = document.getElementById('imageBtn');
    var input = document.getElementById('imageInput');
    if (!btn || !input) return;
    btn.addEventListener('click', function() { input.click(); });
    input.addEventListener('change', function(e) {
        if (e.target.files.length > 0) {
            var file = e.target.files[0];
            if (file.size > 10 * 1024 * 1024) { alert('图片不能超过10MB'); input.value = ''; return; }
            uploadImage(file);
            input.value = '';
        }
    });
}

// ============================================
// 正在输入状态
// ============================================

function sendTypingStatus() {
    if (typingInterval) return;
    typingInterval = setTimeout(function() {
        var xhr = new XMLHttpRequest();
        xhr.open('POST', 'api/update_typing.php', true);
        xhr.withCredentials = true;
        xhr.send();
        typingInterval = null;
    }, 3000);
}

function initTyping() {
    var input = document.getElementById('messageInput');
    if (!input) return;
    input.addEventListener('input', function() {
        if (typingTimer) clearTimeout(typingTimer);
        typingTimer = setTimeout(sendTypingStatus, 500);
    });
}

// ============================================
// 加载更多消息
// ============================================

function showLoadMoreButton() {
    var container = document.getElementById('loadMoreContainer');
    if (container && hasMoreMessages && oldestMessageId !== null) {
        container.style.display = 'block';
    } else if (container) {
        container.style.display = 'none';
    }
}

function hideLoadMoreButton() {
    var container = document.getElementById('loadMoreContainer');
    if (container) container.style.display = 'none';
}

function checkHasMoreMessages() {
    if (!oldestMessageId) return;
    var xhr = new XMLHttpRequest();
    xhr.open('GET', 'api/get_messages.php?before=' + oldestMessageId + '&limit=1', true);
    xhr.withCredentials = true;
    xhr.onreadystatechange = function() {
        if (xhr.readyState === 4 && xhr.status === 200) {
            try {
                var data = JSON.parse(xhr.responseText);
                hasMoreMessages = data.messages && data.messages.length > 0;
                showLoadMoreButton();
            } catch(e) {}
        }
    };
    xhr.send();
}

function loadMoreMessages() {
    if (isLoadingMore || !hasMoreMessages || !oldestMessageId) return;

    isLoadingMore = true;
    var loadMoreBtn = document.getElementById('loadMoreBtn');
    var originalText = loadMoreBtn.textContent;
    loadMoreBtn.textContent = '加载中...';
    loadMoreBtn.disabled = true;

    var xhr = new XMLHttpRequest();
    xhr.open('GET', 'api/get_messages.php?before=' + oldestMessageId + '&limit=20', true);
    xhr.withCredentials = true;
    xhr.timeout = 10000;

    xhr.onreadystatechange = function() {
        if (xhr.readyState === 4) {
            isLoadingMore = false;
            loadMoreBtn.textContent = originalText;
            loadMoreBtn.disabled = false;

            if (xhr.status === 200) {
                try {
                    var data = JSON.parse(xhr.responseText);
                    if (data.messages && data.messages.length > 0) {
                        var box = document.getElementById('messagesBox');
                        var oldScrollHeight = box.scrollHeight;
                        var oldScrollTop = box.scrollTop;

                        prependMessages(data.messages);

                        var newScrollHeight = box.scrollHeight;
                        box.scrollTop = oldScrollTop + (newScrollHeight - oldScrollHeight);

                        oldestMessageId = data.messages[0].id;
                        hasMoreMessages = data.messages.length === 20;
                        if (hasMoreMessages) {
                            showLoadMoreButton();
                        } else {
                            hideLoadMoreButton();
                        }
                    } else {
                        hasMoreMessages = false;
                        hideLoadMoreButton();
                    }
                } catch(e) { console.error('加载更多失败', e); }
            }
        }
    };
    xhr.send();
}

function prependMessages(messages) {
    var box = document.getElementById('messagesBox');
    var loadMoreContainer = document.getElementById('loadMoreContainer');
    var fragment = document.createDocumentFragment();
    var currentUser = document.body.getAttribute('data-current-user') || '';

    for (var i = 0; i < messages.length; i++) {
        var div = createMessageElement(messages[i], currentUser);
        fragment.appendChild(div);
    }

    if (loadMoreContainer && loadMoreContainer.nextSibling) {
        box.insertBefore(fragment, loadMoreContainer.nextSibling);
    } else {
        box.insertBefore(fragment, box.firstChild);
    }
}

// ============================================
// 创建消息元素（包含称号 + 撤回按钮）
// ============================================

function createMessageElement(msg, currentUser) {
    var div = document.createElement('div');
    div.className = 'message';
    if (msg.username === currentUser) div.classList.add('own');
    if (msg.is_temp) div.classList.add('temp-message');

    div.setAttribute('data-message-id', msg.id);
    div.setAttribute('data-message-username', msg.username);
    div.setAttribute('data-message-content', msg.content);

    var avatarUrl = msg.avatar_url || 'avatar.php?name=' + encodeURIComponent(msg.username) + '&size=32&background=4ECDC4&color=fff';
    var contentHtml = '';

    // 处理撤回消息显示
    if (msg.is_recalled) {
        contentHtml = '<div class="message-content recalled">该消息已被撤回</div>';
    } else {
        var isImage = msg.content && /^uploads\/images\/.+\.(jpg|jpeg|png|gif|webp)$/i.test(msg.content);
        contentHtml = isImage ? '<img src="' + escapeHtml(msg.content) + '" style="max-width:100%; max-height:200px; border-radius:0.5rem; cursor:pointer;" onclick="window.open(this.src)">' : '<div class="message-content">' + escapeHtml(msg.content) + '</div>';
    }

    var quoteHtml = '';
    if (msg.reply_to) {
        quoteHtml = '<div class="quote-message"><div class="quote-author">↳ 回复 ' + escapeHtml(msg.reply_to.username) + '</div><div class="quote-content">' + truncateText(escapeHtml(msg.reply_to.content), 80) + '</div></div>';
    }

    // 用户名 + 称号
    var usernameHtml = '<span class="message-author clickable-username" data-username="' + escapeHtml(msg.username) + '">' + escapeHtml(msg.username) + '</span>';
    if (msg.title) {
        usernameHtml += ' <span class="message-title">【' + escapeHtml(msg.title) + '】</span>';
    }

    var timeHtml = '';
    if (!msg.is_temp && msg.time) {
        timeHtml = '<span class="message-time">' + escapeHtml(msg.time) + '</span>';
    }

    // 撤回按钮（仅自己的消息，未被撤回且非临时）
    var recallBtnHtml = '';
    if (msg.user_id == currentUser && !msg.is_recalled && !msg.is_temp) {
        recallBtnHtml = '<span class="recall-btn" data-mid="' + msg.id + '">✕</span>';
    }

    div.innerHTML = '<div class="message-header">' +
        '<div class="message-avatar"><img src="' + escapeHtml(avatarUrl) + '" alt="avatar"></div>' +
        '<div class="message-info">' +
            '<div class="message-name-time">' +
                usernameHtml +
                timeHtml +
            '</div>' +
        '</div>' +
        recallBtnHtml +
        '</div>' +
        quoteHtml +
        contentHtml;

    // 为撤回按钮绑定点击事件
    if (recallBtnHtml) {
        var recallBtn = div.querySelector('.recall-btn');
        if (recallBtn) {
            recallBtn.addEventListener('click', function(e) {
                e.stopPropagation();
                if (confirm('撤回这条消息？')) {
                    recallMessage(msg.id);
                }
            });
        }
    }

    return div;
}

// 撤回消息函数
function recallMessage(messageId) {
    var xhr = new XMLHttpRequest();
    xhr.open('POST', 'api/recall_message.php', true);
    xhr.withCredentials = true;
    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
    xhr.onreadystatechange = function() {
        if (xhr.readyState === 4 && xhr.status === 200) {
            try {
                var res = JSON.parse(xhr.responseText);
                if (res.success) {
                    loadMessages(); // 刷新消息列表
                } else {
                    alert(res.error || '撤回失败');
                }
            } catch(e) {
                alert('服务器错误');
            }
        }
    };
    xhr.send('message_id=' + messageId);
}

// ============================================
// 消息加载
// ============================================

function loadMessages() {
    var xhr = new XMLHttpRequest();
    xhr.open('GET', 'api/get_messages.php?after=' + lastMessageId + '&t=' + Date.now(), true);
    xhr.withCredentials = true;
    xhr.timeout = 5000;
    xhr.onreadystatechange = function() {
        if (xhr.readyState === 4) {
            if (xhr.status === 200) {
                try {
                    var data = JSON.parse(xhr.responseText);
                    if (data.messages && data.messages.length > 0) {
                        var currentUser = document.body.getAttribute('data-current-user') || '';
                        var newMsgs = [];
                        var existingIds = {};

                        var existingMessages = document.querySelectorAll('#messagesBox .message:not(.temp-message)');
                        for (var i = 0; i < existingMessages.length; i++) {
                            var mid = existingMessages[i].getAttribute('data-message-id');
                            if (mid) existingIds[mid] = true;
                        }

                        for (var i = 0; i < data.messages.length; i++) {
                            var msg = data.messages[i];
                            var isDuplicate = false;

                            if (existingIds[msg.id]) {
                                isDuplicate = true;
                            }

                            if (!isDuplicate) {
                                for (var tid in window.sendingMessages) {
                                    var sending = window.sendingMessages[tid];
                                    if (sending && sending.content === msg.content && 
                                        Math.abs(Date.now() - sending.time) < 5000) {
                                        isDuplicate = true;
                                        break;
                                    }
                                }
                            }

                            if (!isDuplicate) {
                                newMsgs.push(msg);
                                if (msg.username !== currentUser && !document.hasFocus()) {
                                    sendNotification(msg.username + ' 说', truncateText(msg.content, 30));
                                }
                            }
                        }

                        if (newMsgs.length > 0) {
                            appendMessages(newMsgs);
                            lastMessageId = newMsgs[newMsgs.length - 1].id;
                            if (oldestMessageId === null && newMsgs.length > 0) {
                                oldestMessageId = newMsgs[0].id;
                                checkHasMoreMessages();
                            }
                        }
                    }
                } catch(e) { console.error('加载失败', e); }
            } else if (xhr.status === 401) { 
                window.location.href = 'login.php';
            }
        }
    };
    xhr.send();
}

function appendMessages(messages) {
    var box = document.getElementById('messagesBox');
    if (!box) return;
    var currentUser = document.body.getAttribute('data-current-user') || '';
    var shouldScroll = (box.scrollHeight - box.scrollTop - box.clientHeight) < 100;
    var fragment = document.createDocumentFragment();

    for (var i = 0; i < messages.length; i++) {
        var div = createMessageElement(messages[i], currentUser);
        fragment.appendChild(div);
    }

    box.appendChild(fragment);
    if (shouldScroll) box.scrollTop = box.scrollHeight;
}

// ============================================
// 事件委托（统一处理所有动态交互）
// ============================================

function initEventDelegation() {
    var container = document.getElementById('messagesBox');
    if (!container) return;

    // 点击撤回按钮
    container.addEventListener('click', function(e) {
        var btn = e.target.closest('.recall-btn');
        if (btn) {
            e.stopPropagation();
            var msgId = btn.getAttribute('data-mid');
            if (msgId && confirm('撤回这条消息？')) {
                recallMessage(msgId);
            }
            return;
        }

        // 点击用户名 - 弹出用户信息弹窗
        var usernameSpan = e.target.closest('.clickable-username');
        if (usernameSpan) {
            e.stopPropagation();
            var username = usernameSpan.getAttribute('data-username');
            var currentUser = document.body.getAttribute('data-current-user') || '';
            if (username && username !== currentUser) {
                showUserInfo(username);
            } else if (username === currentUser) {
                alert('这是你自己');
            }
            return;
        }
    });

    // 双击消息引用
    container.addEventListener('dblclick', function(e) {
        var msgDiv = e.target.closest('.message');
        if (msgDiv && !msgDiv.classList.contains('temp-message')) {
            e.stopPropagation();
            var msgId = msgDiv.getAttribute('data-message-id');
            var username = msgDiv.getAttribute('data-message-username');
            var content = msgDiv.getAttribute('data-message-content');
            if (msgId && username) {
                window.setReplyTo(msgId, username, content);
            }
        }
    });
}

// ============================================
// 发送消息
// ============================================

function sendMessage(content) {
    if (isSending) return;
    if (content === undefined) {
        var input = document.getElementById('messageInput');
        content = input.value.trim();
        if (!content) return;
    }

    isSending = true;
    var tempId = 'temp_' + Date.now() + '_' + Math.random();

    var fd = new FormData();
    fd.append('content', content);
    if (currentReplyTo) {
        fd.append('reply_to', currentReplyTo.id);
    }

    var xhr = new XMLHttpRequest();
    xhr.open('POST', 'api/send_message.php', true);
    xhr.withCredentials = true;

    xhr.onreadystatechange = function() {
        if (xhr.readyState === 4) {
            isSending = false;
            if (xhr.status === 200) {
                try {
                    var res = JSON.parse(xhr.responseText);
                    if (res.success) {
                        document.getElementById('messageInput').value = '';
                        window.cancelReply();
                        loadMessages();
                    } else {
                        alert(res.error || '发送失败');
                    }
                } catch(e) { alert('发送失败'); }
            } else {
                alert('网络错误');
            }
            delete window.sendingMessages[tempId];
        }
    };
    xhr.send(fd);
}

// ============================================
// 在线用户与好友列表（含称号）
// ============================================

function loadOnlineUsers() {
    var xhr = new XMLHttpRequest();
    xhr.open('GET', 'api/get_online_users.php?t=' + Date.now(), true);
    xhr.withCredentials = true;
    xhr.onreadystatechange = function() {
        if (xhr.readyState === 4 && xhr.status === 200) {
            try {
                var data = JSON.parse(xhr.responseText);
                var container = document.getElementById('onlineUsersList');
                if (!container) return;
                if (data.users && data.users.length > 0) {
                    var html = '';
                    for (var i = 0; i < data.users.length; i++) {
                        var u = data.users[i];
                        if (u.username !== document.body.getAttribute('data-current-user')) {
                            html += '<div class="online-user-item" data-username="' + escapeHtml(u.username) + '">' +
                                '<div class="online-user-avatar"><img src="' + (u.avatar_url || 'avatar.php?name=' + encodeURIComponent(u.username) + '&size=28&background=4ECDC4&color=fff') + '"></div>' +
                                '<span class="online-user-name">' + escapeHtml(u.username) + '</span>' +
                                (u.title ? '<span class="online-user-title">【' + escapeHtml(u.title) + '】</span>' : '') +
                                '<span class="typing-indicator" style="display:none;">✍️</span>' +
                                '</div>';
                        }
                    }
                    container.innerHTML = html || '<div class="empty-tip">暂无其他在线用户</div>';
                } else {
                    container.innerHTML = '<div class="empty-tip">暂无其他在线用户</div>';
                }
                // 绑定点击跳转私聊
                var items = container.querySelectorAll('.online-user-item');
                for (var i = 0; i < items.length; i++) {
                    items[i].addEventListener('click', function() {
                        var username = this.getAttribute('data-username');
                        if (username) window.location.href = 'pm.php?user=' + encodeURIComponent(username);
                    });
                }
            } catch(e) { console.error(e); }
        }
    };
    xhr.send();
}

function loadFriends() {
    var xhr = new XMLHttpRequest();
    xhr.open('GET', 'api/get_friends.php?t=' + Date.now(), true);
    xhr.withCredentials = true;
    xhr.onreadystatechange = function() {
        if (xhr.readyState === 4 && xhr.status === 200) {
            try {
                var data = JSON.parse(xhr.responseText);
                var container = document.getElementById('friendsList');
                if (!container) return;
                if (data.friends && data.friends.length > 0) {
                    var html = '';
                    for (var i = 0; i < data.friends.length; i++) {
                        var f = data.friends[i];
                        html += '<a href="pm.php?user=' + encodeURIComponent(f.username) + '" class="friend-item">' +
                            '<div class="friend-avatar"><img src="' + (f.avatar_url || 'avatar.php?name=' + encodeURIComponent(f.username) + '&size=32&background=4ECDC4&color=fff') + '"></div>' +
                            '<span class="friend-name">' + escapeHtml(f.username) + '</span>' +
                            (f.title ? '<span class="friend-title">【' + escapeHtml(f.title) + '】</span>' : '') +
                            '<div class="friend-status">' + (f.is_online ? '<span class="online-dot"></span>' : '<span class="offline-dot"></span>') + '</div>' +
                            (f.unread > 0 ? '<span class="unread-badge">' + f.unread + '</span>' : '') +
                            '</a>';
                    }
                    container.innerHTML = html;
                } else {
                    container.innerHTML = '<div class="empty-tip">暂无好友</div>';
                }
            } catch(e) { console.error(e); }
        }
    };
    xhr.send();
}

function loadFriendRequests() {
    var xhr = new XMLHttpRequest();
    xhr.open('GET', 'api/get_friend_requests.php?t=' + Date.now(), true);
    xhr.withCredentials = true;
    xhr.onreadystatechange = function() {
        if (xhr.readyState === 4 && xhr.status === 200) {
            try {
                var data = JSON.parse(xhr.responseText);
                var container = document.getElementById('friendRequests');
                if (!container) return;
                if (data.requests && data.requests.length > 0) {
                    var html = '<div class="section-header">📨 好友请求</div>';
                    for (var i = 0; i < data.requests.length; i++) {
                        var req = data.requests[i];
                        html += '<div class="request-item" data-id="' + req.id + '">' +
                            '<div class="friend-avatar"><img src="' + (req.avatar_url || 'avatar.php?name=' + encodeURIComponent(req.username) + '&size=32&background=4ECDC4&color=fff') + '"></div>' +
                            '<span class="friend-name">' + escapeHtml(req.username) + '</span>' +
                            '<div class="request-buttons">' +
                            '<button class="accept-request" data-id="' + req.id + '">接受</button>' +
                            '<button class="reject-request" data-id="' + req.id + '">拒绝</button>' +
                            '</div></div>';
                    }
                    container.innerHTML = html;
                    // 绑定按钮事件
                    var acceptBtns = container.querySelectorAll('.accept-request');
                    for (var i = 0; i < acceptBtns.length; i++) {
                        acceptBtns[i].addEventListener('click', function(e) {
                            e.preventDefault();
                            var id = this.getAttribute('data-id');
                            handleFriendRequest(id, 'accept');
                        });
                    }
                    var rejectBtns = container.querySelectorAll('.reject-request');
                    for (var i = 0; i < rejectBtns.length; i++) {
                        rejectBtns[i].addEventListener('click', function(e) {
                            e.preventDefault();
                            var id = this.getAttribute('data-id');
                            handleFriendRequest(id, 'reject');
                        });
                    }
                } else {
                    container.innerHTML = '';
                }
            } catch(e) { console.error(e); }
        }
    };
    xhr.send();
}

function handleFriendRequest(requestId, action) {
    var xhr = new XMLHttpRequest();
    xhr.open('POST', 'api/handle_friend_request.php', true);
    xhr.withCredentials = true;
    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
    xhr.onreadystatechange = function() {
        if (xhr.readyState === 4 && xhr.status === 200) {
            loadFriends();
            loadFriendRequests();
        }
    };
    xhr.send('request_id=' + requestId + '&action=' + action);
}

// 加好友功能（支持 UID 或用户名）
function addFriend(input) {
    if (!input || !input.trim()) {
        alert('请输入用户名或 UID');
        return;
    }
    
    var xhr = new XMLHttpRequest();
    xhr.open('POST', 'api/send_friend_request.php', true);
    xhr.withCredentials = true;
    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
    xhr.onreadystatechange = function() {
        if (xhr.readyState === 4 && xhr.status === 200) {
            try {
                var res = JSON.parse(xhr.responseText);
                if (res.success) {
                    alert('好友请求已发送');
                    loadFriends();
                    loadFriendRequests();
                } else {
                    alert(res.error || '发送失败');
                }
            } catch(e) { 
                console.error('解析响应失败:', e);
                alert('发送失败'); 
            }
        } else if (xhr.readyState === 4) {
            alert('网络错误，状态码：' + xhr.status);
        }
    };
    xhr.send('username=' + encodeURIComponent(input.trim()));
}

// 显示用户信息弹窗（点击用户名触发）
function showUserInfo(username) {
    var xhr = new XMLHttpRequest();
    xhr.open('GET', 'api/get_user_info.php?username=' + encodeURIComponent(username) + '&t=' + Date.now(), true);
    xhr.withCredentials = true;
    xhr.onreadystatechange = function() {
        if (xhr.readyState === 4 && xhr.status === 200) {
            try {
                var data = JSON.parse(xhr.responseText);
                if (data.user) {
                    var content = document.getElementById('userInfoContent');
                    
                    // 构建状态显示
                    var statusHtml = data.user.is_online ? 
                        '<span style="color:#4ECDC4;">● 在线</span>' : 
                        '<span style="color:#9AAEC2;">● 离线</span>';
                    
                    // 构建按钮
                    var buttonHtml = '';
                    if (data.user.is_friend) {
                        buttonHtml = '<button class="add-friend-btn disabled" disabled>✓ 已是好友</button>';
                    } else if (data.user.has_pending) {
                        buttonHtml = '<button class="add-friend-btn disabled" disabled>⏳ 等待对方同意</button>';
                    } else {
                        buttonHtml = '<button id="modalAddFriendBtn" class="add-friend-btn">➕ 添加好友</button>';
                    }
                    
                    content.innerHTML = 
                        '<div class="user-info-avatar"><img src="' + escapeHtml(data.user.avatar_url) + '" onerror="this.src=\'avatar.php?name=' + encodeURIComponent(data.user.username) + '&size=80&background=4ECDC4&color=fff\'"></div>' +
                        '<div class="user-info-name">' + escapeHtml(data.user.username) + '</div>' +
                        '<div class="user-info-uid">UID: ' + data.user.id + '</div>' +
                        (data.user.title ? '<div style="color:var(--accent-secondary); margin:5px 0;">称号：『' + escapeHtml(data.user.title) + '』</div>' : '') +
                        '<div style="margin:5px 0;">' + statusHtml + '</div>' +
                        '<div class="user-info-role">' + (data.user.role === 'admin' ? '👑 管理员' : '👤 普通用户') + '</div>' +
                        (data.user.email ? '<div class="user-info-email">📧 ' + escapeHtml(data.user.email) + '</div>' : '') +
                        '<div style="margin-top:10px;">' + buttonHtml + '</div>' +
                        '<button class="close-info-btn" onclick="document.getElementById(\'userInfoModal\').style.display=\'none\'">关闭</button>';
                    
                    document.getElementById('userInfoModal').style.display = 'flex';
                    
                    // 绑定添加好友按钮事件
                    var addBtn = document.getElementById('modalAddFriendBtn');
                    if (addBtn && !data.user.is_friend && !data.user.has_pending) {
                        addBtn.onclick = function() {
                            addFriend(data.user.username);
                            document.getElementById('userInfoModal').style.display = 'none';
                        };
                    }
                } else {
                    alert(data.error || '获取用户信息失败');
                }
            } catch(e) { 
                console.error(e);
                alert('获取用户信息失败');
            }
        }
    };
    xhr.send();
}

// ============================================
// 轮询与初始化
// ============================================

function startPolling() {
    if (pollTimer) clearInterval(pollTimer);
    pollTimer = setInterval(function() {
        loadMessages();
        loadOnlineUsers();
        loadFriends();
        loadFriendRequests();
    }, REFRESH_INTERVAL);
}

function init() {
    initMobileMenu();
    initImageUpload();
    initTyping();
    initNotification();
    initEventDelegation();

    var sendBtn = document.getElementById('sendBtn');
    if (sendBtn) sendBtn.addEventListener('click', function() { 
        var input = document.getElementById('messageInput');
        sendMessage(input.value);
    });

    var input = document.getElementById('messageInput');
    if (input) input.addEventListener('keypress', function(e) {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            sendMessage(this.value);
        }
    });

    // 加好友按钮（支持 UID 或用户名）
    var addFriendBtn = document.getElementById('addFriendBtn');
    if (addFriendBtn) {
        addFriendBtn.onclick = null;
        addFriendBtn.addEventListener('click', function() {
            var input = prompt('添加好友\n\n请输入用户名或 UID（数字）');
            if (input && input.trim()) {
                addFriend(input.trim());
            } else if (input === '') {
                alert('请输入用户名或 UID');
            }
        });
    }

    var loadMoreBtn = document.getElementById('loadMoreBtn');
    if (loadMoreBtn) loadMoreBtn.addEventListener('click', loadMoreMessages);

    // 点击弹窗外关闭
    window.onclick = function(e) {
        var modal = document.getElementById('userInfoModal');
        if (e.target === modal) modal.style.display = 'none';
    };

    loadMessages();
    loadOnlineUsers();
    loadFriends();
    loadFriendRequests();
    startPolling();
}

// 启动
init();