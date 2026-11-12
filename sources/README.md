# 内置洛雪音源脚本库

脚本统一来自 [pdone/lx-music-source](https://github.com/pdone/lx-music-source)（各目录 `latest.js`，2026-11-13 同步），版权归原作者所有，本仓库仅作播放器内置分发，脚本内容未做修改。

- 20 个上游源经线上全量实测：13 个存活并标记 `verified: true` 随页面自动挂载
- 7 个已移除：flower/grass/lx/juhe/qingliang/yuningxi（上游失效或 IP 被拒）、sixyin（脚本裸 fetch 绕过 lx.request，浏览器 CORS 无法兼容）
- 兼容性说明：站点 shim 已内置 `lx.utils`（buffer/md5/AES[via vendored aes-js]/rsaEncrypt）/`lx.zlib`/`currentScriptInfo.rawScript`，代理对 JSON 响应返回「字符串+属性」混合体，兼容 `JSON.parse(body)` 与 `body.code` 两种写法
- huibq 上游为 Render 免费实例，冷启动首请求可能较慢（超时属正常，重试即可）
- 上游随时可能失效，以 [health-status](https://github.com/pdone/lx-music-source/tree/health-status) 与站点实测为准
