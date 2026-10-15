# 全网解析在线音乐播放器 (music.21212121.xyz)

[![Deploy](https://github.com/xiaobai5211314/music-player/actions/workflows/deploy.yml/badge.svg)](https://github.com/xiaobai5211314/music-player/actions/workflows/deploy.yml)
[![Website](https://img.shields.io/badge/online-music.21212121.xyz-blue)](https://music.21212121.xyz)

> 基于 MKOnline 与全网音源解析的轻量级在线音乐播放器，支持歌词同步高亮、高斯模糊唱片背景、个性歌单及移动端自适应。

---

## 🌐 访问地址
- 官方独立域名：[https://music.21212121.xyz](https://music.21212121.xyz)
- 生产服务器根目录：`/www/wwwroot/music.21212121.xyz/`

## 🛠️ 技术栈与特性
- **播放核心**：HTML5 Audio / JavaScript / Meting 音频网关
- **又拍云 CDN 加速**：
  - 核心静态依赖（jQuery、Layer、mCustomScrollbar、background-blur）全量托管于又拍云存储：`https://tc1.21212121.xyz/asite/assets/vendor/music/`，极大减少源站流量开销与并发压力。
- **站群互联**：PC 端与移动端顶栏均深度集成高质感品牌胶囊导航（`xb-brand-pill-container`），直达岁月静好博客主站（`https://21212121.xyz`）与全景导航站（`https://a.21212121.xyz`）。

## 🚀 自动部署与 CI/CD
本项目已配置 GitHub Actions 自动化持续集成：
- 当向 `main` 分支提交（Push）或合并代码时，GitHub Actions 会自动通过安全 SSH 密钥同步最新代码至生产服务器 `/www/wwwroot/music.21212121.xyz`。

## 📄 授权与许可
详细规范请参考 [AGENTS.md](./AGENTS.md)。
Personal Open Source Project · All Rights Reserved.
