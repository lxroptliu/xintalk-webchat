<?php
// 极简兼容版 - 2010风格聊天室
require_once 'functions.php';
$login = isLoggedIn();
$user = $login ? currentUser() : null;
if ($login && $user) {
    updateLastActivity($user['id']);
    $onlineCount = getOnlineCount();
    $avatarUrl = getAvatarUrl($user['avatar'], $user['username']);
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>信Talk • 兼容版</title>
    <style>
        body{background:#0A0C14;color:#F0F4FA;font-family:system-ui,sans-serif;margin:0;padding:0;}
        body.light{background:#F5F7FA;color:#1A2A2A;}
        a{color:#4ECDC4;text-decoration:none;}
        .header{background:#0F1623;border-bottom:2px solid #4ECDC4;padding:6px 12px;display:flex;justify-content:space-between;}
        .logo{font-weight:bold;}
        .nav a,.nav button{background:transparent;border:1px solid #4ECDC4;color:#4ECDC4;padding:2px 8px;border-radius:12px;font-size:12px;margin-left:4px;cursor:pointer;}
        .user-card{background:#0F1623;margin:6px;padding:8px;border:1px solid #2E405B;display:flex;gap:10px;}
        .avatar{width:36px;height:36px;border-radius:50%;overflow:hidden;border:1px solid #4ECDC4;}
        .avatar img{width:100%;height:100%;}
        .user-name{color:#4ECDC4;font-weight:bold;}
        .friend-box{margin:4px 6px;border:1px solid #2E405B;background:#0F1623;}
        .friend-head{padding:6px 10px;cursor:pointer;background:#1E2A3A;display:flex;justify-content:space-between;}
        .friend-list{max-height:0;overflow:hidden;transition:max-height 0.2s;}
        .friend-list.open{max-height:150px;overflow-y:auto;}
        .friend-item{display:flex;gap:8px;padding:5px 10px;border-top:1px solid #2E405B;text-decoration:none;color:#F0F4FA;}
        .friend-item img{width:24px;height:24px;border-radius:50%;}
        .chat{flex:1;overflow-y:auto;padding:8px;display:flex;flex-direction:column;gap:6px;}
        .msg{max-width:80%;padding:5px 8px;border:1px solid #2E405B;background:#1E2A3A;align-self:flex-start;}
        .msg.own{align-self:flex-end;background:rgba(78,205,196,0.2);border-color:#4ECDC4;}
        .msg-header{font-size:11px;color:#9AAEC2;}
        .msg-author{color:#4ECDC4;font-weight:bold;}
        .msg-title{color:#2C7A6F;}
        .msg-content{font-size:13px;margin-top:2px;word-break:break-word;}
        .msg-content img{max-width:100%;max-height:120px;}
        .input-area{background:#0F1623;border-top:2px solid #4ECDC4;padding:8px;display:flex;gap:6px;}
        .input-area input{flex:1;padding:8px;border:1px solid #2E405B;background:#1E2A3A;color:#F0F4FA;}
        .input-area button{background:#4ECDC4;border:none;color:#000;padding:6px 12px;font-weight:bold;}
        .load-more{text-align:center;margin:6px;}
        .load-btn{background:transparent;border:1px solid #4ECDC4;color:#4ECDC4;padding:4px 10px;cursor:pointer;}
        .empty{text-align:center;padding:20px;color:#9AAEC2;}
        @media (max-width:600px){.msg{max-width:90%;}}
    </style>
</head>
<body>
<div style="display:flex;flex-direction:column;height:100vh;">
    <div class="header">
        <div class="logo">📡 信Talk • 兼容版</div>
        <div class="nav">
            <?php if($login): ?>
                <a href="profile.php">资料</a>
                <a href="about.php">关于</a>
                <a href="logout.php">退出</a>
            <?php else: ?>
                <a href="login.php">登录</a>
                <a href="register.php">注册</a>
            <?php endif; ?>
            <button id="themeBtn">☀️</button>
        </div>
    </div>
    <?php if($login): ?>
    <div class="user-card">
        <div class="avatar"><img src="<?= $avatarUrl ?>"></div>
        <div><div class="user-name"><?= htmlspecialchars($user['username']) ?></div>
        <?php if(!empty($user['title'])): ?><div style="font-size:12px;">「<?= htmlspecialchars($user['title']) ?>」</div><?php endif; ?>
        <div style="font-size:11px;">在线 <?= $onlineCount ?>人</div></div>
    </div>
    <?php endif; ?>
    <div class="friend-box">
        <div class="friend-head" id="friendHead">👥 好友 · <span id="friendCount">0</span> <span id="toggleArrow">▼</span></div>
        <div class="friend-list" id="friendList"><div class="empty">加载中...</div></div>
    </div>
    <div class="chat" id="chatBox">
        <div id="loadMoreDiv" class="load-more" style="display:none;"><button id="loadMoreBtn" class="load-btn">📜 加载更多</button></div>
    </div>
    <?php if($login): ?>
    <div class="input-area">
        <button id="picBtn">📷</button>
        <input type="file" id="picFile" accept="image/*" style="display:none">
        <input type="text" id="msgInput" placeholder="说点什么...">
        <button id="sendBtn">发送</button>
    </div>
    <?php else: ?>
    <div class="input-area" style="justify-content:center;"><a href="login.php" style="background:#4ECDC4;color:#000;padding:6px 12px;border-radius:20px;">登录后发言</a></div>
    <?php endif; ?>
</div>
<script>
var user=<?= json_encode($user ? $user['username'] : null) ?>;
var uid=<?= $user ? $user['id'] : 0 ?>;
var lastId=0,oldId=null,hasMore=1,loading=0,timer=null;
function esc(s){if(!s)return '';return s.replace(/[&<>]/g,function(m){return m==='&'?'&amp;':m==='<'?'&lt;':'&gt;';});}
function addMsg(m){
    var box=document.getElementById('chatBox');
    if(!box)return;
    var d=document.createElement('div');
    d.className='msg'+(m.username===user?' own':'');
    var title=m.title?'<span class="msg-title">【'+esc(m.title)+'】</span>':'';
    var c=m.content&&/^uploads\/images\/.+\.(jpg|jpeg|png|gif|webp)$/i.test(m.content)?'<img src="'+esc(m.content)+'" onclick="window.open(this.src)">':esc(m.content);
    d.innerHTML='<div class="msg-header"><span class="msg-author">'+esc(m.username)+'</span>'+title+'<span>'+esc(m.time)+'</span></div><div class="msg-content">'+c+'</div>';
    box.appendChild(d);
    box.scrollTop=box.scrollHeight;
}
function loadNew(){
    if(!user)return;
    var x=new XMLHttpRequest();
    x.open('GET','api/get_messages.php?after='+lastId,true);
    x.withCredentials=true;
    x.onreadystatechange=function(){
        if(x.readyState===4&&x.status===200){
            try{
                var d=JSON.parse(x.responseText);
                if(d.messages&&d.messages.length){
                    for(var i=0;i<d.messages.length;i++){var m=d.messages[i];if(m.id>lastId){addMsg(m);lastId=m.id;}}
                    if(oldId===null&&d.messages.length){oldId=d.messages[0].id;checkMore();}
                }else if(document.querySelectorAll('.msg').length===0){document.getElementById('chatBox').innerHTML='<div class="empty">✨ 暂无消息</div>';}
            }catch(e){}
        }else if(x.status===401)location.href='login.php';
    };
    x.send();
}
function checkMore(){
    if(!oldId)return;
    var x=new XMLHttpRequest();
    x.open('GET','api/get_messages.php?before='+oldId+'&limit=1',true);
    x.withCredentials=true;
    x.onreadystatechange=function(){
        if(x.readyState===4&&x.status===200){
            try{var d=JSON.parse(x.responseText);hasMore=d.messages&&d.messages.length>0;var c=document.getElementById('loadMoreDiv');if(c)c.style.display=hasMore?'block':'none';}catch(e){}
        }
    };
    x.send();
}
function loadMore(){
    if(loading||!hasMore||!oldId)return;
    loading=1;
    var btn=document.getElementById('loadMoreBtn');
    if(!btn)return;
    var txt=btn.textContent;
    btn.textContent='...';
    var x=new XMLHttpRequest();
    x.open('GET','api/get_messages.php?before='+oldId+'&limit=20',true);
    x.withCredentials=true;
    x.onreadystatechange=function(){
        if(x.readyState===4&&x.status===200){
            loading=0;
            btn.textContent=txt;
            try{
                var d=JSON.parse(x.responseText);
                if(d.messages&&d.messages.length){
                    var box=document.getElementById('chatBox'),con=document.getElementById('loadMoreDiv');
                    var oldH=box.scrollHeight,oldT=box.scrollTop;
                    var frag=document.createDocumentFragment();
                    for(var i=d.messages.length-1;i>=0;i--){
                        var m=d.messages[i];
                        var div=document.createElement('div');
                        div.className='msg'+(m.username===user?' own':'');
                        var title=m.title?'<span class="msg-title">【'+esc(m.title)+'】</span>':'';
                        var c=m.content&&/^uploads\/images\/.+\.(jpg|jpeg|png|gif|webp)$/i.test(m.content)?'<img src="'+esc(m.content)+'" onclick="window.open(this.src)">':esc(m.content);
                        div.innerHTML='<div class="msg-header"><span class="msg-author">'+esc(m.username)+'</span>'+title+'<span>'+esc(m.time)+'</span></div><div class="msg-content">'+c+'</div>';
                        frag.appendChild(div);
                    }
                    if(con&&con.nextSibling)box.insertBefore(frag,con.nextSibling);
                    else box.insertBefore(frag,box.firstChild);
                    box.scrollTop=oldT+(box.scrollHeight-oldH);
                    oldId=d.messages[0].id;
                    hasMore=d.messages.length===20;
                    if(con)con.style.display=hasMore?'block':'none';
                }else{hasMore=0;var c2=document.getElementById('loadMoreDiv');if(c2)c2.style.display='none';}
            }catch(e){}
        }
    };
    x.send();
}
function sendMsg(){
    if(!user)return;
    var inp=document.getElementById('msgInput'),c=inp.value.trim();
    if(!c)return;
    var x=new XMLHttpRequest();
    x.open('POST','api/send_message.php',true);
    x.withCredentials=true;
    x.setRequestHeader('Content-Type','application/x-www-form-urlencoded');
    x.onreadystatechange=function(){
        if(x.readyState===4&&x.status===200){
            try{var r=JSON.parse(x.responseText);if(r.success){inp.value='';loadNew();}else alert(r.error||'发送失败');}catch(e){alert('发送失败');}
        }
    };
    x.send('content='+encodeURIComponent(c));
}
function upload(f){
    if(!user)return;
    var fd=new FormData();fd.append('image',f);
    var x=new XMLHttpRequest();
    x.open('POST','api/upload_image.php',true);
    x.withCredentials=true;
    x.onreadystatechange=function(){
        if(x.readyState===4&&x.status===200){
            try{var r=JSON.parse(x.responseText);if(r.success)sendMsg(r.url);else alert(r.error||'上传失败');}catch(e){alert('上传失败');}
        }
    };
    x.send(fd);
}
function loadFriends(){
    if(!user)return;
    var x=new XMLHttpRequest();
    x.open('GET','api/get_friends.php?t='+Date.now(),true);
    x.withCredentials=true;
    x.onreadystatechange=function(){
        if(x.readyState===4&&x.status===200){
            try{
                var d=JSON.parse(x.responseText);
                var cont=document.getElementById('friendList'),cnt=document.getElementById('friendCount');
                if(cont&&d.friends&&d.friends.length){
                    var h='';
                    for(var i=0;i<d.friends.length;i++){
                        var f=d.friends[i];
                        h+='<a href="pm.php?user='+encodeURIComponent(f.username)+'" class="friend-item"><img src="'+(f.avatar_url||'avatar.php?name='+encodeURIComponent(f.username)+'&size=24&background=4ECDC4&color=fff')+'"><span>'+esc(f.username)+'</span>'+(f.title?'<span style="color:#2C7A6F;">【'+esc(f.title)+'】</span>':'')+'</a>';
                    }
                    cont.innerHTML=h;
                    if(cnt)cnt.textContent=d.friends.length;
                }else{cont.innerHTML='<div class="empty">暂无好友</div>';if(cnt)cnt.textContent='0';}
            }catch(e){}
        }
    };
    x.send();
}
function addFriend(name){
    if(!name||!name.trim()){alert('输入用户名');return;}
    var x=new XMLHttpRequest();
    x.open('POST','api/send_friend_request.php',true);
    x.withCredentials=true;
    x.setRequestHeader('Content-Type','application/x-www-form-urlencoded');
    x.onreadystatechange=function(){
        if(x.readyState===4&&x.status===200){
            try{var r=JSON.parse(x.responseText);if(r.success){alert('请求已发送');loadFriends();}else alert(r.error||'失败');}catch(e){alert('失败');}
        }
    };
    x.send('username='+encodeURIComponent(name.trim()));
}
function initTheme(){
    var s=localStorage.getItem('theme');
    var b=document.body,btn=document.getElementById('themeBtn');
    if(s==='light')b.classList.add('light');
    if(btn)btn.onclick=function(){b.classList.toggle('light');localStorage.setItem('theme',b.classList.contains('light')?'light':'dark');btn.textContent=b.classList.contains('light')?'🌙':'☀️';};
}
function initFold(){
    var head=document.getElementById('friendHead'),list=document.getElementById('friendList'),arrow=document.getElementById('toggleArrow');
    if(!head||!list)return;
    var open=0;
    head.onclick=function(){
        open=!open;
        if(open){list.classList.add('open');arrow.textContent='▲';}else{list.classList.remove('open');arrow.textContent='▼';}
    };
}
function init(){
    initTheme();
    initFold();
    if(!user)return;
    document.getElementById('sendBtn').onclick=sendMsg;
    document.getElementById('msgInput').onkeypress=function(e){if(e.keyCode===13){e.preventDefault();sendMsg();}};
    var picBtn=document.getElementById('picBtn'),picFile=document.getElementById('picFile');
    if(picBtn&&picFile){
        picBtn.onclick=function(){picFile.click();};
        picFile.onchange=function(e){
            if(e.target.files.length){
                var f=e.target.files[0];
                if(f.size>10*1024*1024){alert('图片超过10MB');this.value='';return;}
                upload(f);
                this.value='';
            }
        };
    }
    document.getElementById('refreshBtn')&&(document.getElementById('refreshBtn').onclick=function(){loadNew();loadFriends();});
    document.getElementById('loadMoreBtn')&&(document.getElementById('loadMoreBtn').onclick=loadMore);
    var ft=document.querySelector('.friend-head');
    if(ft&&!document.getElementById('addFriendBtn')){
        var ab=document.createElement('button');
        ab.id='addFriendBtn';
        ab.textContent='+';
        ab.style.cssText='background:#4ECDC4;color:#000;border:none;border-radius:12px;padding:0 6px;margin-left:6px;cursor:pointer;';
        ab.onclick=function(e){e.stopPropagation();var n=prompt('输入好友用户名');if(n)addFriend(n);};
        ft.appendChild(ab);
    }
    loadNew();
    loadFriends();
    timer=setInterval(loadNew,3000);
}
init();
</script>
</body>
</html>