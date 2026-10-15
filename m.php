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
    
    <title>小白在线音乐播放器</title>
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
    
    <!-- 样式文件 -->
    <link rel="stylesheet" type="text/css" href="static/css/leeleo.css">
    <link rel="stylesheet" type="text/css" href="static/css/jquery.mcustomscrollbar.min.css">
    <link rel="stylesheet" href="static/css/layer.css" id="layuicss-skinlayercss">
    <style>
    /* 移动端顶栏与弹性自适应系统 */
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
        background-color: #0b1120;
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
        background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
        border-bottom: 1px solid rgba(255, 255, 255, 0.08);
        box-shadow: 0 2px 10px rgba(0, 0, 0, 0.35);
        z-index: 99997;
        box-sizing: border-box;
    }
    .mnav-brand {
        display: flex;
        align-items: center;
        gap: 6px;
        color: #fff;
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
        gap: 3px;
        padding: 4px 10px;
        background: rgba(255, 255, 255, 0.1);
        border: 1px solid rgba(255, 255, 255, 0.18);
        border-radius: 999px;
        color: #e2e8f0;
        text-decoration: none;
        font-size: 11.5px;
        line-height: 1.2;
        transition: all 0.2s;
    }
    .mnav-pill:active {
        background: rgba(255, 255, 255, 0.25);
        transform: scale(0.96);
    }
    .center {
        position: absolute !important;
        top: calc(44px + var(--safe-top)) !important;
        bottom: calc(60px + var(--safe-bottom)) !important;
        width: 100% !important;
        left: 0 !important;
        right: 0 !important;
        background-color: #f8fafc;
        overflow: hidden;
    }
    .footer {
        position: absolute !important;
        bottom: 0 !important;
        height: calc(60px + var(--safe-bottom)) !important;
        padding-bottom: var(--safe-bottom) !important;
        background-color: #fff;
        box-shadow: 0 -2px 10px rgba(0, 0, 0, 0.08);
        width: 100%;
        z-index: 99998;
        box-sizing: border-box;
    }
    .btn-bar {
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        height: 42px;
        z-index: 999;
    }
    .btn-box {
        display: flex !important;
        width: 100% !important;
        background: rgba(255, 255, 255, 0.98);
        box-shadow: 0 1px 4px rgba(0, 0, 0, 0.06);
    }
    .btn-box .btn {
        flex: 1 1 25% !important;
        width: 25% !important;
        float: none !important;
        height: 42px !important;
        line-height: 42px !important;
        font-size: 13px !important;
        cursor: pointer;
        text-align: center;
    }
    .data-area {
        position: absolute;
        top: 42px;
        bottom: 0;
        left: 0;
        right: 0;
        overflow-y: auto;
        -webkit-overflow-scrolling: touch;
    }
    .player {
        position: absolute;
        top: 42px;
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
            <!-- tab按钮区 -->
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

<!-- 播放器底部区域 -->
<div class="footer">
    <div class="container">
        <div class="con-btn">
            <a href="javascript:;" class="player-btn btn-prev" title="上一首"></a>
            <a href="javascript:;" class="player-btn btn-play" title="暂停/继续"></a>
            <a href="javascript:;" class="player-btn btn-next" title="下一首"></a>
			<a href="javascript:;" class="player-btn btn-order" title="循环控制"></a>
        </div>  <!--class="con-btn"-->
        
        <div class="vol">
            <div class="quiet">
                <a href="javascript:;" class="player-btn btn-quiet" title="静音"></a>
            </div>
            <div class="volume">
                <div class="volume-box">  
                    <div id="volume-progress" class="mkpgb-area"></div>
                </div>
            </div>
        </div>  <!--class="footer"-->
        
        <div class="progress">
            <div class="progress-box">  
                <div id="music-progress" class="mkpgb-area"></div>
            </div>
        </div>  <!--class="progress"-->
    </div>  <!--class="container"-->
</div>  <!--class="footer"-->

<!-- layer弹窗插件 -->
<script src="plugns/layer/layer.js"></script>

<!-- 播放器数据加载模块 -->
<script src="static/js/ajax.v2.js"></script>

<!-- 音源后端中心：切换 / 添加接口（链接或导入 .js 文件） -->
<script src="static/js/sourcehub.v2.js"></script>

<!-- 播放器歌词解析模块 -->
<script src="static/js/lyric.js"></script>

<!-- 音乐列表配置 -->
<script src="static/js/musicList.js"></script>

<!-- 封装函数及ui交互模块 -->
<script src="static/js/functions.v2.js"></script>

<!-- 播放器主体功能模块 -->
<script src="static/js/player.v2.js"></script>

<!-- 滚动条美化插件 -->
<script src="static/js/jquery.mCustomScrollbar.concat.min.js"></script>

<!-- 背景模糊化插件 -->
<script src="static/js/background-blur.min.js"></script>

</body>
</html>