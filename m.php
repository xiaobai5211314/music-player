<?php
header("Cache-Control: no-cache, no-store, must-revalidate");
header("Pragma: no-cache");
header("Expires: 0");
?>
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no, viewport-fit=cover">
    <meta name="renderer" content="webkit">
    <meta name="author" content="mengkun">
    <meta name="generator" content="KodCloud">
    <meta http-equiv="Cache-Control" content="no-siteapp">
    
    <!-- 强制移动设备以app模式打开页面(即在移动设备下全屏，仅支持部分浏览器) -->
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-touch-fullscreen" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="full-screen" content="yes"><!--UC强制全屏-->
    <meta name="browsermode" content="application"><!--UC应用模式-->
    <meta name="x5-fullscreen" content="true"><!--QQ强制全屏-->
    <meta name="x5-page-mode" content="app"><!--QQ应用模式-->
    
    <title>小白在线音乐随身听</title>
    <meta name="description" content="小白在线音乐播放器，基于网易云音乐API的现代化在线音乐随身听。"/>
    <meta name="keywords" content="小白在线音乐,在线音乐播放器,MKOnlinePlayer,网易云音乐,全景空间"/>
    
    <!-- 不支持IE8及以下版本浏览器 -->
    <!--[if lte IE 8]>
        <script>window.location.href="plugns/killie/"</script>
    <![endif]--> 
  
    <!-- favicon图标 -->
    <link rel="shortcut icon" href="favicon.ico">
    
    <!-- jQuery文件 -->
    <script src="js/jquery.min.js"></script>
    
    <!-- 基础样式表文件 -->
    <link rel="stylesheet" type="text/css" href="css/player.css">
    <link rel="stylesheet" type="text/css" href="css/small.css">
    <link rel="stylesheet" type="text/css" href="css/jquery.mCustomScrollbar.min.css">
    <link rel="stylesheet" href="plugns/layer/skin/default/layer.css?v=3.0.2302" id="layuicss-skinlayercss">

    <!-- Anthropic Claude 沉静典雅设计系统与网易云功能扩展样式 -->
    <link rel="stylesheet" type="text/css" href="css/claude-player.css?v=2.2.2">
    <style>
    /* 移动端顶栏与沉浸式毛玻璃系统 */
    :root {
        --safe-top: env(safe-area-inset-top, 0px);
        --safe-bottom: env(safe-area-inset-bottom, 0px);
    }
    html, body {
        width: 100%;
        height: 100%;
        margin: 0;
        padding: 0;
        overflow: hidden;
        -webkit-text-size-adjust: 100%;
        background-color: #141413;
    }
    .leo-mnav-head {
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        height: calc(44px + var(--safe-top));
        padding-top: var(--safe-top);
        padding-left: 14px;
        padding-right: 14px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        background: rgba(20, 20, 19, 0.88);
        backdrop-filter: blur(20px);
        -webkit-backdrop-filter: blur(20px);
        border-bottom: 1px solid rgba(232, 230, 220, 0.1);
        box-shadow: 0 4px 16px rgba(0, 0, 0, 0.35);
        z-index: 99997;
        box-sizing: border-box;
    }
    .mnav-brand {
        display: flex;
        align-items: center;
        gap: 6px;
        color: #faf9f5;
        font-size: 14.5px;
        font-weight: 600;
        letter-spacing: 0.3px;
        user-select: none;
    }
    .mnav-actions {
        display: flex;
        align-items: center;
        gap: 6px;
    }
    .mnav-pill {
        display: inline-flex;
        align-items: center;
        gap: 4px;
        padding: 4px 10px;
        background: rgba(255, 255, 255, 0.08);
        border: 1px solid rgba(232, 230, 220, 0.15);
        border-radius: 999px;
        color: #b0aea5;
        text-decoration: none;
        font-size: 11.5px;
        line-height: 1.2;
        transition: all 0.2s;
    }
    .mnav-pill:active {
        background: rgba(255, 255, 255, 0.2);
        color: #fff;
        transform: scale(0.96);
    }
    .center {
        position: absolute !important;
        top: calc(44px + var(--safe-top)) !important;
        bottom: calc(74px + var(--safe-bottom)) !important;
        width: 100% !important;
        left: 0 !important;
        right: 0 !important;
        background-color: transparent !important;
        overflow: hidden;
    }
    .btn-bar {
        position: absolute;
        top: 8px;
        left: 12px;
        right: 12px;
        height: 38px;
        z-index: 999;
    }
    .btn-box {
        display: flex !important;
        width: 100% !important;
        padding: 3px !important;
        background: rgba(28, 27, 24, 0.85) !important;
        backdrop-filter: blur(16px) !important;
        -webkit-backdrop-filter: blur(16px) !important;
        border: 1px solid rgba(232, 230, 220, 0.12) !important;
        border-radius: 999px !important;
        box-shadow: 0 4px 16px rgba(0, 0, 0, 0.3) !important;
        box-sizing: border-box !important;
    }
    .btn-box .btn {
        flex: 1 1 25% !important;
        width: 25% !important;
        float: none !important;
        height: 30px !important;
        line-height: 30px !important;
        font-size: 12px !important;
        cursor: pointer;
        text-align: center;
        border-radius: 999px !important;
        color: #b0aea5 !important;
        border: none !important;
        margin: 0 !important;
        padding: 0 !important;
        transition: all 0.2s ease !important;
    }
    .btn-box .btn.active {
        background: #d97757 !important;
        color: #ffffff !important;
        font-weight: 600 !important;
        box-shadow: 0 2px 8px rgba(217, 119, 87, 0.4) !important;
    }
    .btn-box .active:after {
        display: none !important;
    }
    .data-area {
        position: absolute;
        top: 50px;
        bottom: 0;
        left: 0;
        right: 0;
        overflow-y: auto;
        -webkit-overflow-scrolling: touch;
    }
    .player {
        position: absolute;
        top: 50px;
        bottom: 0;
        left: 0;
        right: 0;
        overflow: hidden;
    }
    </style>
</head>
<body>
<header class="leo-mnav-head">
    <div class="mnav-brand">
        <span>🎵</span>
        <span>小白随身听</span>
    </div>
    <div class="mnav-actions">
        <a href="../" class="mnav-pill" title="返回全景空间">⌂ 全景空间</a>
        <a href="https://21212121.xyz" target="_blank" rel="noopener" class="mnav-pill" title="岁月静好博客">✎ 博客</a>
    </div>
</header>

<!-- 中间主体区域 -->
<div class="center">
    <div class="container">
        <div class="btn-bar"> 
            <!-- tab按钮区 (Claude 胶囊分段选择器) -->
            <div class="btn-box" id="btn-area">
                <span class="btn" data-action="player">播放器</span>
                <span class="btn" data-action="playing" title="正在播放列表">正在播放</span>
                <span class="btn" data-action="sheet" title="音乐播放列表">播放列表</span>
                <span class="btn" data-action="search" title="点击搜索音乐">歌曲搜索</span>
            </div>
        </div>  <!--class="btn-bar"-->
        
        <div class="data-area">
            <!--歌曲歌单-->
            <div id="sheet" class="data-box" hidden></div>
            
            <!--音乐播放列表-->
            <div id="main-list" class="music-list data-box"></div>
        </div>  <!--class="data-area"-->
        
        <!-- 右侧封面及歌词展示 -->
        <div class="player" id="player">
            <!--歌曲封面-->
            <div class="cover">
                <img src="images/player_cover.png" class="music-cover" id="music-cover">
            </div>
            <!--滚动歌词-->
            <div class="lyric">
                <ul id="lyric"></ul>
            </div>
            <div id="music-info" title="点击查看歌曲信息"></div>
        </div>
    </div>  <!--class="container"-->
</div>  <!--class="center"-->

<!-- 播放器底部区域 (Claude × 网易云音乐现代双层触控底栏) -->
<div class="footer claude-footer" id="player-footer">
    <div class="container footer-container">
        <!-- 1. 左侧：当前播放歌曲微缩信息卡片 -->
        <div class="footer-left">
            <div class="footer-cover-wrap" title="点击切换歌词/播放器视图">
                <img src="images/player_cover.png" class="footer-cover" id="footer-cover" alt="封面">
                <div class="footer-cover-overlay">
                    <svg viewBox="0 0 24 24" width="16" height="16" fill="currentColor"><path d="M12 4.5C7 4.5 2.73 7.61 1 12c1.73 4.39 6 7.5 11 7.5s9.27-3.11 11-7.5c-1.73-4.39-6-7.5-11-7.5zM12 17c-2.76 0-5-2.24-5-5s2.24-5 5-5 5 2.24 5 5-2.24 5-5 5zm0-8c-1.66 0-3 1.34-3 3s1.34 3 3 3 3-1.34 3-3-1.34-3-3-3z"/></svg>
                </div>
            </div>
            <div class="footer-song-meta">
                <div class="footer-song-title-row">
                    <span class="footer-song-name" id="footer-song-name" title="等待播放">小白音乐</span>
                    <span class="footer-quality-badge" id="footer-quality-badge" title="当前音质">HQ 320K</span>
                </div>
                <div class="footer-song-artist" id="footer-song-artist" title="歌手">Enjoy The Music</div>
            </div>
            <button type="button" class="footer-icon-btn btn-like" id="btn-like" title="收藏至我喜欢的音乐" aria-label="收藏">
                <svg class="heart-icon" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>
            </button>
        </div>

        <!-- 2. 中间：控制按钮区 + 时间进度条 (网易云核心控制) -->
        <div class="footer-center">
            <!-- 顶行：时间与交互进度条 -->
            <div class="player-progress-bar-wrap">
                <span class="time-display current-time" id="music-cur-time">00:00</span>
                <div class="progress-box" id="main-progress-box">
                    <div id="music-progress" class="mkpgb-area"></div>
                    <div class="progress-tooltip" id="progress-tooltip">00:00</div>
                </div>
                <span class="time-display total-time" id="music-total-time">00:00</span>
            </div>

            <!-- 底行：控制按钮 -->
            <div class="player-controls">
                <a href="javascript:;" class="player-btn btn-order" id="btn-order" title="循环控制"></a>
                <a href="javascript:;" class="player-btn btn-prev" title="上一首"></a>
                <a href="javascript:;" class="player-btn btn-play" id="btn-play" title="播放/暂停"></a>
                <a href="javascript:;" class="player-btn btn-next" title="下一首"></a>
                <button type="button" class="footer-icon-btn btn-lyric-toggle" id="btn-lyric-toggle" title="纯享全屏歌词 / 返回列表" aria-label="歌词视图">
                    词
                </button>
            </div>
        </div>

        <!-- 3. 右侧：倍速胶囊 + 当前音源 + 音量 + 播放列表抽屉 -->
        <div class="footer-right">
            <!-- 倍速控制胶囊 -->
            <div class="speed-control-box">
                <button type="button" class="speed-capsule-btn" id="speed-capsule-btn" title="点击调节播放倍速">
                    <span id="speed-label">1.0x</span>
                </button>
                <div class="speed-menu-dropdown" id="speed-menu-dropdown">
                    <div class="speed-menu-title">播放倍速</div>
                    <div class="speed-item" data-speed="0.5">0.5x</div>
                    <div class="speed-item" data-speed="0.75">0.75x</div>
                    <div class="speed-item active" data-speed="1.0">1.0x (正常)</div>
                    <div class="speed-item" data-speed="1.25">1.25x</div>
                    <div class="speed-item" data-speed="1.5">1.5x</div>
                    <div class="speed-item" data-speed="2.0">2.0x</div>
                </div>
            </div>

            <!-- 当前音源快捷胶囊 -->
            <button type="button" class="source-capsule-btn" id="source-capsule-btn" title="当前音源：点击切换音源">
                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
                <span id="active-source-name">全豆要</span>
            </button>

            <!-- 音量调节 -->
            <div class="footer-vol-wrap">
                <a href="javascript:;" class="player-btn btn-quiet" title="静音/恢复"></a>
                <div class="volume-slider-box">
                    <div id="volume-progress" class="mkpgb-area"></div>
                </div>
            </div>

            <!-- 当前播放队列按钮 -->
            <button type="button" class="footer-icon-btn btn-playlist-toggle" id="btn-playlist-toggle" title="正在播放列表">
                <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg>
                <span class="playlist-count-badge" id="playlist-count-badge">0</span>
            </button>
        </div>
    </div>
</div>

<!-- layer弹窗插件 -->
<script src="plugns/layer/layer.js"></script>

<!-- 播放器数据加载模块 -->
<script src="js/ajax.v2.js"></script>

<!-- 音源后端中心：切换 / 添加接口（链接或导入 .js 文件） -->
<script src="js/sourcehub.v2.js"></script>

<!-- 播放器歌词解析模块 -->
<script src="js/lyric.js"></script>

<!-- 音乐列表配置 -->
<script src="js/musicList.js"></script>

<!-- 封装函数及ui交互模块 -->
<script src="js/functions.v2.js?v=2.2.2"></script>

<!-- 播放器主体功能模块 (包含倍速持久化/时间进度条/红心收藏/快捷键) -->
<script src="js/player.v2.js?v=2.2.2"></script>

<!-- 滚动条美化插件 -->
<script src="js/jquery.mCustomScrollbar.concat.min.js"></script>

<!-- 背景模糊化插件 -->
<script src="js/background-blur.min.js"></script>

</body>
</html>