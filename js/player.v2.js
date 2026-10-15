/**************************************************
 * MKOnlinePlayer v2.41
 * 播放器主功能模块
 * 编写：mengkun(https://mkblog.cn)
 * 时间：2018-3-13
 *************************************************/
// 播放器功能配置
var mkPlayer = {
    api: "api.php", // api地址
    loadcount: 20,  // 搜索结果一次加载多少条
    method: "POST",     // 数据传输方式(POST/GET)
    defaultlist: 3,    // 默认要显示的播放列表编号
    autoplay: false,    // 是否自动播放(true/false) *此选项在移动端可能无效
    coverbg: true,      // 是否开启封面背景(true/false) *开启后会有些卡
    mcoverbg: true,     // 是否开启[移动端]封面背景(true/false)
    dotshine: true,    // 是否开启播放进度条的小点闪动效果[不支持IE](true/false) *开启后会有些卡
    mdotshine: false,   // 是否开启[移动端]播放进度条的小点闪动效果[不支持IE](true/false)
    volume: 0.6,        // 默认音量值(0~1之间)
    version: "v2.41",    // 播放器当前版本号(仅供调试)
    debug: false   // 是否开启调试模式(true/false)
};



/*******************************************************
 * 以下内容是播放器核心文件，不建议进行修改，否则可能导致播放器无法正常使用!
 * 
 * 哈哈，吓唬你的！想改就改呗！不过建议修改之前先【备份】,要不然改坏了弄不好了。
 ******************************************************/

// 存储全局变量
var rem = [];

// 音频错误处理函数
function audioErr() {
    // 没播放过，直接跳过
    if(rem.playlist === undefined) return true;
    
    if(rem.errCount > 10) { // 连续播放失败的歌曲过多
        layer.msg('似乎出了点问题~播放已停止');
        rem.errCount = 0;
    } else {
        rem.errCount++;     // 记录连续播放失败的歌曲数目
        layer.msg('当前歌曲播放失败，自动播放下一首');
        nextMusic();    // 切换下一首歌
    } 
}

// 点击暂停按钮的事件
function pause() {
    if(rem.paused === false) {  // 之前是播放状态
        rem.audio[0].pause();  // 暂停
    } else {
        // 第一次点播放
        if(rem.playlist === undefined) {
            rem.playlist = rem.dislist;
            
            musicList[1].item = musicList[rem.playlist].item; // 更新正在播放列表中音乐
            
            // 正在播放 列表项已发生变更，进行保存
            playerSavedata('playing', musicList[1].item);   // 保存正在播放列表
            
            listClick(0);
        }
        rem.audio[0].play();
    }
}

// 循环顺序
function orderChange() {
    var orderDiv = $(".btn-order");
    orderDiv.removeClass();
    switch(rem.order) {
        case 1:     // 单曲循环 -> 列表循环
            orderDiv.addClass("player-btn btn-order btn-order-list");
            orderDiv.attr("title", "列表循环");
            layer.msg("列表循环");
            rem.order = 2;
            break;
            
        case 3:     // 随机播放 -> 单曲循环
            orderDiv.addClass("player-btn btn-order btn-order-single");
            orderDiv.attr("title", "单曲循环");
            layer.msg("单曲循环");
            rem.order = 1;
            break;
            
        // case 2:
        default:    // 列表循环(其它) -> 随机播放
            orderDiv.addClass("player-btn btn-order btn-order-random");
            orderDiv.attr("title", "随机播放");
            layer.msg("随机播放");
            rem.order = 3;
    }
}

// 播放
function audioPlay() {
    rem.paused = false;     // 更新状态（未暂停）
    refreshList();      // 刷新状态，显示播放的波浪
    $(".btn-play").addClass("btn-state-paused");        // 恢复暂停
    
    if((mkPlayer.dotshine === true && !rem.isMobile) || (mkPlayer.mdotshine === true && rem.isMobile)) {
        $("#music-progress .mkpgb-dot").addClass("dot-move");   // 小点闪烁效果
    }
    
    var music = musicList[rem.playlist].item[rem.playid];   // 获取当前播放的歌曲信息
    var msg = " 正在播放: " + music.name + " - " + music.artist;  // 改变浏览器标题
    
    // 清除定时器
    if (rem.titflash !== undefined ) 
    {
        clearInterval(rem.titflash);
    }
    // 标题滚动
    titleFlash(msg);
}
// 标题滚动
function titleFlash(msg) {

    // 截取字符
    var tit = function() {
        msg = msg.substring(1,msg.length)+ msg.substring(0,1);
        document.title = msg;
    };
    // 设置定时间 300ms滚动
    rem.titflash = setInterval(function(){tit()}, 300);
}
// 暂停
function audioPause() {
    rem.paused = true;      // 更新状态（已暂停）
    
    $(".list-playing").removeClass("list-playing");        // 移除其它的正在播放
    
    $(".btn-play").removeClass("btn-state-paused");     // 取消暂停
    
    $("#music-progress .dot-move").removeClass("dot-move");   // 小点闪烁效果

     // 清除定时器
    if (rem.titflash !== undefined ) 
    {
        clearInterval(rem.titflash);
    }
    document.title = rem.webTitle;    // 改变浏览器标题
}

// 播放上一首歌
function prevMusic() {
    playList(rem.playid - 1);
}

// 播放下一首歌
function nextMusic() {
    switch (rem.order ? rem.order : 1) {
        case 1,2: 
            playList(rem.playid + 1);
        break;
        case 3: 
            if (musicList[1] && musicList[1].item.length) {
                var id = parseInt(Math.random() * musicList[1].item.length);
                playList(id);
            }
        break;
        default:
            playList(rem.playid + 1); 
        break;
    }
}
// 自动播放时的下一首歌
function autoNextMusic() {
    if(rem.order && rem.order === 1) {
        playList(rem.playid);
    } else {
        nextMusic();
    }
}

// 格式化时间函数 (秒 -> mm:ss)
function formatTime(sec) {
    if (isNaN(sec) || sec < 0) return "00:00";
    sec = Math.floor(sec);
    var m = Math.floor(sec / 60);
    var s = sec % 60;
    return (m < 10 ? "0" + m : m) + ":" + (s < 10 ? "0" + s : s);
}

// 歌曲时间变动回调函数
function updateProgress(){
    if(!rem.audio || !rem.audio[0]) return true;
    var cur = rem.audio[0].currentTime || 0;
    var dur = rem.audio[0].duration || 0;
    
    // 实时更新时间文本
    $("#music-cur-time").text(formatTime(cur));
    if (dur > 0) {
        $("#music-total-time").text(formatTime(dur));
        // 缓冲进度条
        try {
            if (rem.audio[0].buffered && rem.audio[0].buffered.length > 0) {
                var bufEnd = rem.audio[0].buffered.end(rem.audio[0].buffered.length - 1);
                var bufPct = Math.min(100, Math.max(0, (bufEnd / dur) * 100));
                $("#music-progress .mkpgb-buf").css("width", bufPct + "%");
            }
        } catch(e){}
    }
    
    // 暂停状态不管
    if(rem.paused !== false) return true;
    
    // 同步进度条
    if (dur > 0) {
        music_bar.goto(cur / dur);
    }
    // 同步歌词显示	
    scrollLyric(cur);
}

// 显示的列表中的某一项点击后的处理函数
// 参数：歌曲在列表中的编号
function listClick(no) {
    // 记录要播放的歌曲的id
    var tmpid = no;
    
    // 调试信息输出
    if(mkPlayer.debug) {
        console.log("点播了列表中的第 " + (no + 1) + " 首歌 " + musicList[rem.dislist].item[no].name);
    }
    
    // 搜索列表的歌曲要额外处理
    if(rem.dislist === 0) {
        
        // 没播放过
        if(rem.playlist === undefined) {
            rem.playlist = 1;   // 设置播放列表为 正在播放 列表
            rem.playid = musicList[1].item.length - 1;  // 临时设置正在播放的曲目为 正在播放 列表的最后一首
        }
        
        // 获取选定歌曲的信息
        var tmpMusic = musicList[0].item[no];
        
        
        // 查找当前的播放列表中是否已经存在这首歌
        for(var i=0; i<musicList[1].item.length; i++) {
            if(musicList[1].item[i].id == tmpMusic.id && musicList[1].item[i].source == tmpMusic.source) {
                tmpid = i;
                playList(tmpid);    // 找到了直接播放
                return true;    // 退出函数
            }
        }
        
        
        // 将点击的这项追加到正在播放的条目的下方
        musicList[1].item.splice(rem.playid + 1, 0, tmpMusic);
        tmpid = rem.playid + 1;
        
        // 正在播放 列表项已发生变更，进行保存
        playerSavedata('playing', musicList[1].item);   // 保存正在播放列表
    } else {    // 普通列表
        // 与之前不是同一个列表了（在播放别的列表的歌曲）或者是首次播放
        if((rem.dislist !== rem.playlist && rem.dislist !== 1) || rem.playlist === undefined) {
            rem.playlist = rem.dislist;     // 记录正在播放的列表
            musicList[1].item = musicList[rem.playlist].item; // 更新正在播放列表中音乐
            
            // 正在播放 列表项已发生变更，进行保存
            playerSavedata('playing', musicList[1].item);   // 保存正在播放列表
            
            // 刷新正在播放的列表的动画
            refreshSheet();     // 更改正在播放的列表的显示
        }
    }
    
    playList(tmpid);
    
    return true;
}

// 播放正在播放列表中的歌曲
// 参数：歌曲在列表中的ID
function playList(id) {
    // 第一次播放
    if(rem.playlist === undefined) {
        pause();
        return true;
    }
    
    // 没有歌曲，跳出
    if(musicList[1].item.length <= 0) return true;
    
    // ID 范围限定
    if(id >= musicList[1].item.length) id = 0;
    if(id < 0) id = musicList[1].item.length - 1;
    
    // 记录正在播放的歌曲在正在播放列表中的 id
    rem.playid = id;
    
    // 如果链接为空，则 ajax 获取数据后再播放
    if(musicList[1].item[id].url === null || musicList[1].item[id].url === "") {
        ajaxUrl(musicList[1].item[id], play);
    } else {
        play(musicList[1].item[id]);
    }
}

// 初始化 Audio
function initAudio() {
    rem.audio = $('<audio></audio>').appendTo('body');
    
    // 应用初始音量
    rem.audio[0].volume = volume_bar.percent;
    // 恢复保存的播放倍速
    var savedSpeed = playerReaddata('speed');
    if(savedSpeed) {
        setPlaybackSpeed(savedSpeed);
    }
    // 绑定歌曲进度变化事件
    rem.audio[0].addEventListener('timeupdate', updateProgress);   // 更新进度
    rem.audio[0].addEventListener('play', audioPlay); // 开始播放了
    rem.audio[0].addEventListener('pause', audioPause);   // 暂停
    $(rem.audio[0]).on('ended', autoNextMusic);   // 播放结束
    rem.audio[0].addEventListener('error', audioErr);   // 播放器错误处理
}


// 播放音乐
// 参数：要播放的音乐数组
function play(music) {
    // 调试信息输出
    if(mkPlayer.debug) {
        console.log('开始播放 - ' + music.name);
        
        console.info('id: "' + music.id + '",\n' + 
        'name: "' + music.name + '",\n' +
        'artist: "' + music.artist + '",\n' +
        'album: "' + music.album + '",\n' +
        'source: "' + music.source + '",\n' +
        'url_id: "' + music.url_id + '",\n' + 
        'pic_id: "' + music.pic_id + '",\n' + 
        'lyric_id: "' + music.lyric_id + '",\n' + 
        'pic: "' + music.pic + '",\n' +
        'url: "' + music.url + '"');
    }
    
    // 遇到错误播放下一首歌
    if(music.url == "err") {
        audioErr(); // 调用错误处理函数
        return false;
    }
    
    addHis(music);  // 添加到播放历史
    
    // 如果当前主界面显示的是播放历史，那么还需要刷新列表显示
    if(rem.dislist == 2 && rem.playlist !== 2) {
        loadList(2);
    } else {
        refreshList();  // 更新列表显示
    }
    
    try {
        rem.audio[0].pause();
        rem.audio.attr('src', music.url);
        if(rem.speed && rem.audio[0]) {
            rem.audio[0].playbackRate = rem.speed;
        }
        rem.audio[0].play();
    } catch(e) {
        audioErr(); // 调用错误处理函数
        return;
    }
    
    rem.errCount = 0;   // 连续播放失败的歌曲数归零
    music_bar.goto(0);  // 进度条强制归零
    $("#music-cur-time").text("00:00");
    syncFooterSong(music);  // 同步更新底栏歌曲信息与音质徽章
    changeCover(music);    // 更新封面展示
    ajaxLyric(music, lyricCallback);     // ajax加载歌词
    music_bar.lock(false);  // 取消进度条锁定
}


// 我的要求并不高，保留这一句版权信息可好？
// 保留了，你不会损失什么；而保留版权，是对作者最大的尊重。
console.info('欢迎使用 MKOnlinePlayer!\n当前版本：'+mkPlayer.version+' \n作者：mengkun(https://mkblog.cn)\n歌曲来源于各大音乐平台\nGithub：https://github.com/mengkunsoft/MKOnlineMusicPlayer');

// 音乐进度条拖动回调函数
function mBcallback(newVal) {
    var newTime = rem.audio[0].duration * newVal;
    // 应用新的进度
    rem.audio[0].currentTime = newTime;
    refreshLyric(newTime);  // 强制滚动歌词到当前进度
}

// 音量条变动回调函数
// 参数：新的值
function vBcallback(newVal) {
    if(rem.audio[0] !== undefined) {   // 音频对象已加载则立即改变音量
        rem.audio[0].volume = newVal;
    }
    
    if($(".btn-quiet").is('.btn-state-quiet')) {
        $(".btn-quiet").removeClass("btn-state-quiet");     // 取消静音
    }
    
    if(newVal === 0) $(".btn-quiet").addClass("btn-state-quiet");
    
    playerSavedata('volume', newVal); // 存储音量信息
}

// 下面是进度条处理
var initProgress = function(){  
    // 初始化播放进度条
    music_bar = new mkpgb("#music-progress", 0, mBcallback);
    music_bar.lock(true);   // 未播放时锁定不让拖动
    // 初始化音量设定
    var tmp_vol = playerReaddata('volume');
    tmp_vol = (tmp_vol != null)? tmp_vol: (rem.isMobile? 1: mkPlayer.volume);
    if(tmp_vol < 0) tmp_vol = 0;    // 范围限定
    if(tmp_vol > 1) tmp_vol = 1;
    if(tmp_vol == 0) $(".btn-quiet").addClass("btn-state-quiet"); // 添加静音样式
    volume_bar = new mkpgb("#volume-progress", tmp_vol, vBcallback);
};  

// mk进度条插件
// 进度条框 id，初始量，回调函数
mkpgb = function(bar, percent, callback){  
    this.bar = bar;
    this.percent = percent;
    this.callback = callback;
    this.locked = false;
    this.init();  
};

mkpgb.prototype = {
    // 进度条初始化
    init : function(){  
        var mk = this, mdown = false;
        // 加载进度条html元素（带缓冲层和拖拽小点）
        $(mk.bar).html('<div class="mkpgb-bar"><div class="mkpgb-buf"></div><div class="mkpgb-cur"></div><div class="mkpgb-dot"></div></div>');
        // 获取偏移量
        mk.minLength = $(mk.bar).offset().left; 
        mk.maxLength = $(mk.bar).width() + mk.minLength;
        
        // 窗口大小改变偏移量重置
        $(window).resize(function(){
            if($(mk.bar).length && $(mk.bar).is(':visible')) {
                mk.minLength = $(mk.bar).offset().left; 
                mk.maxLength = $(mk.bar).width() + mk.minLength;
            }
        });
        
        // 监听小点的鼠标按下事件
        $(mk.bar + " .mkpgb-dot").mousedown(function(e){
            e.preventDefault();
        });
        
        // 监听进度条整体的鼠标与触摸按下事件
        $(mk.bar).on('mousedown', function(e){
            if(!mk.locked) {
                mdown = true;
                barMove(e);
            }
        }).on('touchstart', function(e){
            if(!mk.locked && e.originalEvent.touches && e.originalEvent.touches[0]) {
                mdown = true;
                barMove(e.originalEvent.touches[0]);
            }
        });
        
        // 监听移动事件，用于拖动
        $("html").on('mousemove', function(e){
            if(mdown) barMove(e);
        }).on('touchmove', function(e){
            if(mdown && e.originalEvent.touches && e.originalEvent.touches[0]) {
                barMove(e.originalEvent.touches[0]);
                e.preventDefault();
            }
        });
        
        // 监听弹起事件，用于释放拖动
        $("html").on('mouseup touchend', function(e){
            mdown = false;
        });
        
        // 悬浮时间气泡预览 (类似网易云/B站交互)
        if(mk.bar === "#music-progress") {
            $(mk.bar).on('mousemove', function(e){
                if (!rem.audio || !rem.audio[0] || !rem.audio[0].duration) return;
                var offset = $(mk.bar).offset();
                var width = $(mk.bar).width();
                if(!offset || width <= 0) return;
                var pct = Math.min(1, Math.max(0, (e.clientX - offset.left) / width));
                var targetTime = rem.audio[0].duration * pct;
                $("#progress-tooltip").text(formatTime(targetTime)).css('left', (pct * 100) + '%').addClass('show');
            }).on('mouseleave', function(){
                $("#progress-tooltip").removeClass('show');
            });
        }
        
        function barMove(e) {
            if(!mdown) return;
            var offset = $(mk.bar).offset();
            if(!offset) return;
            mk.minLength = offset.left;
            mk.maxLength = $(mk.bar).width() + mk.minLength;
            var width = mk.maxLength - mk.minLength;
            if(width <= 0) return;
            var percent = 0;
            if(e.clientX < mk.minLength){ 
                percent = 0; 
            }else if(e.clientX > mk.maxLength){ 
                percent = 1;
            }else{  
                percent = (e.clientX - mk.minLength) / width;
            }
            mk.callback(percent);
            mk.goto(percent);
            return true;
        }
        
        mk.goto(mk.percent);
        return true;
    },
    // 跳转至某处
    goto : function(percent) {
        if(percent > 1) percent = 1;
        if(percent < 0) percent = 0;
        this.percent = percent;
        $(this.bar + " .mkpgb-dot").css("left", (percent*100) +"%"); 
        $(this.bar + " .mkpgb-cur").css("width", (percent*100)+"%");
        return true;
    },
    // 锁定进度条
    lock : function(islock) {
        if(islock) {
            this.locked = true;
            $(this.bar).addClass("mkpgb-locked");
        } else {
            this.locked = false;
            $(this.bar).removeClass("mkpgb-locked");
        }
        return true;
    }
};  

/* ==============================================================
 * 网易云音乐功能系统 × Claude 官方文学极简交互扩展
 * ============================================================== */

// 1. 浮动 Toast 提示组件
function showToast(msg) {
    var $t = $("#claude-toast");
    if (!$t.length) {
        $t = $('<div id="claude-toast" class="claude-toast"></div>').appendTo('body');
    }
    $t.text(msg).addClass('show');
    clearTimeout(rem.toastTimer);
    rem.toastTimer = setTimeout(function(){ $t.removeClass('show'); }, 1500);
}

// 2. 播放倍速控制 (0.5x ~ 2.0x 动态自适应与持久记忆)
function setPlaybackSpeed(speed) {
    speed = parseFloat(speed) || 1.0;
    rem.speed = speed;
    if (rem.audio && rem.audio[0]) {
        rem.audio[0].playbackRate = speed;
    }
    playerSavedata('speed', speed);
    $("#speed-label").text(speed === 1.0 ? '1.0x' : (speed + 'x'));
    $(".speed-item").removeClass("active");
    $(".speed-item[data-speed='" + speed + "']").addClass("active");
    if (speed !== 1.0) {
        $("#speed-capsule-btn").addClass("active-speed");
    } else {
        $("#speed-capsule-btn").removeClass("active-speed");
    }
    showToast('播放倍速: ' + speed + 'x');
}

// 3. 底栏歌曲信息微缩卡片同步
function syncFooterSong(music) {
    if (!music) return;
    $("#footer-cover").attr('src', music.pic || 'images/player_cover.png');
    $("#footer-song-name").text(music.name || '未知歌曲').attr('title', music.name || '');
    var artistText = (music.artist || '未知歌手') + (music.album ? (' · ' + music.album) : '');
    $("#footer-song-artist").text(artistText).attr('title', artistText);
    
    // 动态智能音质徽章
    var badgeText = "HQ 320K";
    var src = (music.source || '').toLowerCase();
    if (src === 'netease' || src === 'wy') {
        badgeText = "SQ 无损";
    } else if (src === 'tencent' || src === 'tx') {
        badgeText = "SQ 臻品";
    } else if (src === 'kugou' || src === 'kg' || src === 'kuwo' || src === 'kw') {
        badgeText = "Hi-Res";
    }
    $("#footer-quality-badge").text(badgeText);
    
    // 活跃音源徽章更新
    var activeSrc = (window.SourceHub && SourceHub.activeSourceId) ? SourceHub.activeSourceId.replace(/\.js$/, '') : '全豆要';
    $("#active-source-name").text(activeSrc);
    
    // 收藏状态更新
    updateLikeBtnStatus(music);
    
    // 播放列表歌曲总数角标
    if (musicList && musicList[1] && musicList[1].item) {
        $("#playlist-count-badge").text(musicList[1].item.length);
    }
}

// 4. 红心收藏系统 (存储至 localStorage 'mk_liked_songs')
function getLikedSongs() {
    try {
        return JSON.parse(localStorage.getItem('mk_liked_songs') || '[]');
    } catch(e) {
        return [];
    }
}

function isSongLiked(music) {
    if (!music || !music.id) return false;
    var liked = getLikedSongs();
    var key = music.id + '_' + (music.source || '');
    return liked.some(function(item){ return item.key === key; });
}

function updateLikeBtnStatus(music) {
    if (isSongLiked(music)) {
        $("#btn-like").addClass("liked").attr('title', '已收藏到我喜欢的音乐 (点击取消)');
    } else {
        $("#btn-like").removeClass("liked").attr('title', '点击收藏至我喜欢的音乐');
    }
}

function toggleLikeCurrentMusic() {
    if (rem.playid === undefined || !musicList[1] || !musicList[1].item[rem.playid]) {
        showToast('当前没有正在播放的歌曲');
        return;
    }
    var music = musicList[1].item[rem.playid];
    var liked = getLikedSongs();
    var key = music.id + '_' + (music.source || '');
    var idx = liked.findIndex(function(item){ return item.key === key; });
    if (idx >= 0) {
        liked.splice(idx, 1);
        localStorage.setItem('mk_liked_songs', JSON.stringify(liked));
        updateLikeBtnStatus(music);
        showToast('已从我喜欢的音乐中移除');
    } else {
        liked.unshift({
            key: key,
            id: music.id,
            name: music.name,
            artist: music.artist,
            album: music.album,
            source: music.source,
            pic: music.pic
        });
        localStorage.setItem('mk_liked_songs', JSON.stringify(liked));
        updateLikeBtnStatus(music);
        showToast('已收藏到我喜欢的音乐 ❤️');
    }
}

// 5. 事件绑定与现代化交互响应
$(function(){
    // 倍速控制按钮点击
    $(document).on('click', '#speed-capsule-btn', function(e){
        e.stopPropagation();
        $("#speed-menu-dropdown").toggleClass("show");
    });
    
    // 倍速项选择
    $(document).on('click', '.speed-item', function(e){
        e.stopPropagation();
        var speed = $(this).data('speed');
        setPlaybackSpeed(speed);
        $("#speed-menu-dropdown").removeClass("show");
    });
    
    // 全局点击自动收起下拉
    $(document).on('click', function(){
        $("#speed-menu-dropdown").removeClass("show");
    });
    
    // 音源胶囊快速呼出 4-Tab 音源控制中心
    $(document).on('click', '#source-capsule-btn', function(){
        if (window.SourceHub && typeof SourceHub.openModal === 'function') {
            SourceHub.openModal();
        } else if (typeof openSourceHubModal === 'function') {
            openSourceHubModal();
        } else {
            layer.msg('音源引擎就绪中...');
        }
    });
    
    // 收藏按钮点击
    $(document).on('click', '#btn-like', function(){
        toggleLikeCurrentMusic();
    });
    
    // 播放列表抽屉开关
    $(document).on('click', '#btn-playlist-toggle', function(){
        $(".btn[data-action='playing']").click();
    });
    
    // 歌词视图切换
    $(document).on('click', '#btn-lyric-toggle, .footer-cover-wrap', function(){
        if ($("#player").is(":visible") && rem.isMobile) {
            $(".btn[data-action='playing']").click();
        } else {
            $(".btn[data-action='player']").click();
        }
    });
    
    // 底栏滚轮直接滑动调整音量
    $(document).on('wheel', '.footer', function(e){
        e.preventDefault();
        var delta = e.originalEvent.deltaY;
        var step = delta > 0 ? -0.05 : 0.05;
        var newVol = Math.min(1, Math.max(0, volume_bar.percent + step));
        volume_bar.goto(newVol);
        vBcallback(newVol);
        showToast('音量: ' + Math.round(newVol * 100) + '%');
    });
    
    // 全局键盘快捷键 (网易云同款极客操控)
    $(document).on('keydown', function(e){
        if ($(e.target).is('input, textarea, select')) return;
        
        switch(e.keyCode) {
            case 32: // 空格键：播放/暂停
                e.preventDefault();
                if(rem.paused === true) {
                    play();
                    showToast('继续播放');
                } else {
                    pause();
                    showToast('已暂停');
                }
                break;
            case 37: // 左箭头：快退 5 秒
                e.preventDefault();
                if (rem.audio && rem.audio[0]) {
                    rem.audio[0].currentTime = Math.max(0, rem.audio[0].currentTime - 5);
                    showToast('快退 5s (' + formatTime(rem.audio[0].currentTime) + ')');
                }
                break;
            case 39: // 右箭头：快进 5 秒
                e.preventDefault();
                if (rem.audio && rem.audio[0]) {
                    rem.audio[0].currentTime = Math.min(rem.audio[0].duration || 9999, rem.audio[0].currentTime + 5);
                    showToast('快进 5s (' + formatTime(rem.audio[0].currentTime) + ')');
                }
                break;
            case 38: // 上箭头：音量加 5%
                e.preventDefault();
                var newVolUp = Math.min(1, volume_bar.percent + 0.05);
                volume_bar.goto(newVolUp);
                vBcallback(newVolUp);
                showToast('音量: ' + Math.round(newVolUp * 100) + '%');
                break;
            case 40: // 下箭头：音量减 5%
                e.preventDefault();
                var newVolDown = Math.max(0, volume_bar.percent - 0.05);
                volume_bar.goto(newVolDown);
                vBcallback(newVolDown);
                showToast('音量: ' + Math.round(newVolDown * 100) + '%');
                break;
            case 219: // [ 键：上一首
                prevMusic();
                showToast('上一首');
                break;
            case 221: // ] 键：下一首
                nextMusic();
                showToast('下一首');
                break;
            case 77: // M 键：静音切换
                $(".btn-quiet").click();
                break;
        }
    });
});  
