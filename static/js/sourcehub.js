/**************************************************
 * 音源后端中心 SourceHub 2.0 (全功能高可用音源引擎)
 *
 * 功能特性：
 *   1. 自动预加载：开箱即用自动挂载「全豆要[聚合音源]」、「念心音源」等优质音源
 *   2. 完整协议模拟：100% 兼容洛雪 globalThis.lx 规范 (lx.request, lx.on, lx.send, lx.env)
 *   3. 修复 body 预解析崩溃：原始响应文本透传，杜绝 "[object Object]" is not valid JSON
 *   4. CORS 穿透：第三方 API 自动走同源后端代理 backend.php?types=proxy
 *   5. HTTPS 混合内容防拦截：HTTP 音频直链自动转换为同源安全流 backend.php?types=stream
 *   6. 智能多源容灾：单源失效时自动尝试备用音源与官方流
 *   7. 搜索无缝增强：挂钩 ajaxSearch 击碎 CDN 缓存，支持多平台稳定检索
 *************************************************/
(function () {
  'use strict';

  var LS_KEY = 'yyjx_sourcehub_v2';
  var LX_VERSION = '1.2.0';

  // 环境补丁：补足浏览器/脚本可能缺失的全局函数
  if (!window.console) window.console = {};
  if (!console.group) console.group = function () {};
  if (!console.groupEnd) console.groupEnd = function () {};
  if (!console.info) console.info = console.log || function () {};
  if (!console.debug) console.debug = console.log || function () {};

  // MKOnlinePlayer 平台名 → 洛雪脚本代号别名映射
  var LX_ALIAS = {
    netease: ['wy', 'netease', '163'],
    qq:      ['tx', 'qq', 'tencent'],
    kuwo:    ['kw', 'kuwo'],
    kugou:   ['kg', 'kugou'],
    migu:    ['mg', 'migu']
  };

  /* ==========================================================
     状态管理
     ========================================================== */
  var state = {
    backends: [],
    searchId: 'builtin',
    urlId: 'preset_全豆要[聚合音源]'
  };

  var sessions = {};

  function uid(prefix) {
    return (prefix || 'b') + '_' + Date.now().toString(36) + Math.random().toString(36).slice(2, 6);
  }

  function save() {
    try {
      // 仅持久化用户自定义的后端（避免预置音源撑爆 localStorage）
      var customBackends = state.backends.filter(function (b) { return !b.isPreset; });
      localStorage.setItem(LS_KEY, JSON.stringify({
        customBackends: customBackends.map(function (b) {
          return { id: b.id, name: b.name, kind: b.kind, code: b.code, searchUrl: b.searchUrl, urlUrl: b.urlUrl };
        }),
        searchId: state.searchId,
        urlId: state.urlId
      }));
    } catch (e) {
      console.warn('[SourceHub] 保存本地配置失败', e);
    }
  }

  function load() {
    try {
      var raw = localStorage.getItem(LS_KEY);
      if (!raw) return;
      var d = JSON.parse(raw);
      if (d) {
        if (d.searchId) state.searchId = d.searchId;
        if (d.urlId) state.urlId = d.urlId;
        if (Array.isArray(d.customBackends)) {
          d.customBackends.forEach(function (cb) {
            if (cb.kind === 'lx' && cb.code) {
              try {
                var s = loadLxScript(cb.code, cb.id);
                state.backends.push({
                  id: cb.id,
                  name: cb.name || '自定义音源',
                  kind: 'lx',
                  code: cb.code,
                  session: s,
                  sources: Object.keys((s.inited && s.inited.sources) || {})
                });
              } catch (err) {
                console.warn('[SourceHub] 自定义音源加载失败：' + cb.name, err);
              }
            } else if (cb.kind === 'http') {
              state.backends.push(cb);
            }
          });
        }
      }
    } catch (e) {
      console.warn('[SourceHub] 读取本地配置失败', e);
    }
  }

  /* ==========================================================
     内置官方与预置音源定义
     ========================================================== */
  var BUILTIN_BACKENDS = [
    { id: 'builtin', name: '官方直连聚合 (酷我/QQ/网易)', kind: 'builtin', builtin: true }
  ];

  function allBackends() {
    return BUILTIN_BACKENDS.concat(state.backends);
  }

  function findBackend(id) {
    var list = allBackends();
    for (var i = 0; i < list.length; i++) {
      if (list[i].id === id) return list[i];
    }
    return null;
  }

  /* ==========================================================
     洛雪音源协议代理 (lx.request)
     ========================================================== */
  function hostRequest(url, options) {
    options = options || {};
    var method = (options.method || 'GET').toUpperCase();
    var headers = options.headers || {};
    var body = options.body;

    // 绝大多数洛雪音源向第三方请求，浏览器直接 fetch 会受 CORS 限制，统一走同源代理
    var proxyUrl = 'backend.php?types=proxy&_t=' + Date.now();
    var payload = {
      types: 'proxy',
      url: url,
      method: method,
      headers: headers,
      body: typeof body === 'object' ? JSON.stringify(body) : (body || '')
    };

    return fetch(proxyUrl, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    }).then(function (res) {
      return res.json();
    }).then(function (j) {
      if (!j || !j.ok) {
        throw new Error((j && j.error) || '代理请求失败 (HTTP ' + (j ? j.status : 0) + ')');
      }
      var rawBody = j.body == null ? '' : String(j.body);
      return {
        statusCode: j.status || 200,
        status: j.status || 200,
        headers: j.headers || {},
        body: rawBody // 必须为原始字符串，保证脚本内 JSON.parse(body) 正常工作
      };
    }).catch(function (err) {
      console.warn('[SourceHub] 代理请求异常:', url, err);
      throw err;
    });
  }

  function makeLxShim(sessionId) {
    var session = sessions[sessionId];
    return {
      version: LX_VERSION,
      env: 'web',
      currentScriptInfo: {
        name: (session && session.name) || 'SourceHub-Worker',
        version: '1.0.0'
      },
      EVENT_NAMES: {
        inited: 'inited',
        updateAlert: 'updateAlert',
        request: 'request',
        showConfigView: 'showConfigView',
        configViewReady: 'configViewReady'
      },
      on: function (event, handler) {
        if (session) session.handlers[event] = handler;
      },
      send: function (event, data) {
        if (session && event === 'inited') {
          session.inited = data || null;
        }
      },
      request: function (url, options, cb) {
        if (typeof options === 'function') {
          cb = options;
          options = {};
        }
        cb = cb || function () {};

        hostRequest(url, options).then(function (r) {
          cb(null, r, r.body);
        }).catch(function (err) {
          cb(err || new Error('网络请求失败'));
        });
      }
    };
  }

  // 绑定全局 globalThis.lx 支持
  function ensureGlobalLx(activeSessionId) {
    var shim = makeLxShim(activeSessionId || 'default');
    try {
      window.lx = shim;
      globalThis.lx = shim;
    } catch (e) {}
  }

  /**
   * 加载洛雪音源脚本
   */
  function loadLxScript(code, customId, name) {
    var sid = customId || uid('lx');
    var session = {
      id: sid,
      name: name || 'LX-Source',
      handlers: {},
      inited: null,
      code: code
    };
    sessions[sid] = session;

    ensureGlobalLx(sid);
    try {
      (0, eval)(code);
    } catch (e) {
      console.error('[SourceHub] 执行音源脚本出错 (' + name + '):', e);
      throw e;
    }

    if (typeof session.handlers.request !== 'function' && typeof session.handlers.musicUrl !== 'function') {
      throw new Error('脚本未注册 request/musicUrl 处理函数，可能不是洛雪自定义音源');
    }
    return session;
  }

  /**
   * 调用洛雪音源获取真实直链
   */
  function callLx(session, action, source, info) {
    if (!session) return Promise.reject(new Error('音源会话不存在'));
    var fn = session.handlers[action] || session.handlers.request;
    if (typeof fn !== 'function') {
      return Promise.reject(new Error('该音源未实现 ' + action));
    }

    ensureGlobalLx(session.id);
    return new Promise(function (resolve, reject) {
      try {
        var res = fn({ action: action, source: source, info: info });
        Promise.resolve(res).then(resolve, reject);
      } catch (err) {
        reject(err);
      }
    });
  }

  /* ==========================================================
     HTTPS 混合内容保护 (Mixed Content Stream Relay)
     ========================================================== */
  function ensureSecureStream(url) {
    if (!url || typeof url !== 'string') return '';
    url = url.trim();
    if (!/^https?:\/\//i.test(url)) return url;

    // 若当前页面是 HTTPS，而直链是 HTTP
    if (window.location.protocol === 'https:' && url.indexOf('http://') === 0) {
      // 避免某些无法 HTTPS 加载的 IP 或域名被浏览器直接阻断
      return 'backend.php?types=stream&url=' + encodeURIComponent(url);
    }
    return url;
  }

  /* ==========================================================
     取播放链接与多源自动故障转移 (Smart Fallback Engine)
     ========================================================== */
  function resolveUrl(music, quality) {
    quality = quality || '128k';

    // 候选音源链
    var candidates = [];

    // 1. 首选音源
    var primary = findBackend(state.urlId);
    if (primary && primary.kind === 'lx' && primary.session) {
      candidates.push(primary);
    }

    // 2. 备选已加载的洛雪音源
    state.backends.forEach(function (b) {
      if (b.kind === 'lx' && b.session && candidates.indexOf(b) === -1) {
        candidates.push(b);
      }
    });

    var idx = 0;
    function tryNextSource() {
      if (idx >= candidates.length) {
        // 所有洛雪音源尝试完毕，回退到官方直连
        return fallbackBuiltinUrl(music);
      }
      var cur = candidates[idx++];
      return resolveViaLx(cur, music, quality).then(function (u) {
        if (u && /^https?:\/\//i.test(u)) {
          return ensureSecureStream(u);
        }
        return tryNextSource();
      }).catch(function (err) {
        console.warn('[SourceHub] 音源 [' + cur.name + '] 解析失败，自动切换备选音源:', err && err.message);
        return tryNextSource();
      });
    }

    return tryNextSource();
  }

  function resolveViaLx(backend, music, quality) {
    var s = backend.session;
    if (!s) return Promise.reject(new Error('音源未就绪'));

    var aliases = LX_ALIAS[music.source] || [music.source];
    var i = 0;

    function tryPlatformAlias() {
      if (i >= aliases.length) {
        return Promise.reject(new Error('音源不支持来源：' + music.source));
      }
      var src = aliases[i++];
      var info = {
        type: quality || '128k',
        musicInfo: {
          id: String(music.id || ''),
          songmid: String(music.id || ''),
          songId: String(music.id || ''),
          musicId: String(music.id || ''),
          hash: String(music.id || ''),
          rid: String(music.id || ''),
          name: music.name || '',
          singer: Array.isArray(music.artist) ? music.artist.join(' / ') : (music.artist || ''),
          artist: Array.isArray(music.artist) ? music.artist.join(' / ') : (music.artist || ''),
          albumName: music.album || '',
          album: music.album || '',
          source: src // 核心：向脚本注入当前平台标识
        }
      };

      return callLx(s, 'musicUrl', src, info).then(function (res) {
        var u = typeof res === 'string' ? res : (res && (res.url || (res.data && res.data.url)));
        if (typeof u === 'string' && /^https?:\/\//i.test(u)) {
          return u;
        }
        throw new Error('未获取到有效播放直链');
      }).catch(function (err) {
        if (i < aliases.length) return tryPlatformAlias();
        throw err;
      });
    }

    return tryPlatformAlias();
  }

  function fallbackBuiltinUrl(music) {
    return fetch('backend.php?types=url&id=' + encodeURIComponent(music.id) +
                 '&source=' + encodeURIComponent(music.source) +
                 '&_t=' + Date.now())
      .then(function (r) { return r.json(); })
      .then(function (j) {
        var u = (j && j.url) || '';
        return ensureSecureStream(u);
      }).catch(function () {
        return '';
      });
  }

  /* ==========================================================
     封面与歌词获取
     ========================================================== */
  function resolvePic(music) {
    if (music.pic_id && /^https?:\/\//i.test(music.pic_id)) {
      return Promise.resolve(music.pic_id);
    }
    return fetch('backend.php?types=pic&id=' + encodeURIComponent(music.pic_id || music.id) +
                 '&source=' + encodeURIComponent(music.source) + '&_t=' + Date.now())
      .then(function (r) { return r.json(); })
      .then(function (j) { return (j && j.url) || ''; })
      .catch(function () { return ''; });
  }

  function resolveLyric(music) {
    return fetch('backend.php?types=lyric&id=' + encodeURIComponent(music.lyric_id || music.id) +
                 '&source=' + encodeURIComponent(music.source) + '&_t=' + Date.now())
      .then(function (r) { return r.json(); })
      .then(function (j) { return (j && j.lyric) || ''; })
      .catch(function () { return ''; });
  }

  /* ==========================================================
     自动预置音源加载 (Preload Preset Sources)
     ========================================================== */
  function preloadPresets() {
    return fetch('sources/manifest.json?_t=' + Date.now())
      .then(function (res) { return res.json(); })
      .catch(function () {
        return fetch('backend.php?types=sources&_t=' + Date.now()).then(function (r) { return r.json(); });
      })
      .then(function (manifest) {
        if (!manifest || !Array.isArray(manifest.sources)) return;

        var loadPromises = [];
        manifest.sources.forEach(function (meta) {
          // 只自动加载通过实测验证的推荐音源，其余按需挂载
          if (!meta.verified && !meta.default) return;

          var sid = 'preset_' + meta.id;
          // 若已加载过则跳过
          if (findBackend(sid)) return;

          var p = fetch('sources/' + encodeURIComponent(meta.file) + '?_t=' + Date.now())
            .then(function (r) {
              if (!r.ok) throw new Error('HTTP ' + r.status);
              return r.text();
            })
            .then(function (code) {
              var s = loadLxScript(code, sid, meta.name);
              var b = {
                id: sid,
                name: meta.name + (meta.default ? ' (推荐主力)' : ' (备选)'),
                kind: 'lx',
                isPreset: true,
                session: s,
                sources: Object.keys((s.inited && s.inited.sources) || {})
              };
              state.backends.push(b);
              if (meta.default && (state.urlId === 'builtin' || state.urlId === 'preset_quandouyao' || !findBackend(state.urlId))) {
                state.urlId = sid;
              }
              console.log('[SourceHub] 预置音源加载成功:', meta.name);
            }).catch(function (err) {
              console.warn('[SourceHub] 加载预置音源失败:', meta.file, err);
            });
          loadPromises.push(p);
        });

        return Promise.all(loadPromises);
      }).catch(function (err) {
        console.warn('[SourceHub] 检查预置音源清单失败:', err);
      });
  }

  /* ==========================================================
     挂钩 MKOnlinePlayer
     ========================================================== */
  function hookPlayer() {
    if (typeof window.ajaxUrl !== 'function') return false;

    // 1. 挂钩取音频直链
    window.__sh_orig_ajaxUrl = window.ajaxUrl;
    window.ajaxUrl = function (music, callback) {
      if (music.url !== null && music.url !== 'err' && music.url !== '') {
        return window.__sh_orig_ajaxUrl(music, callback);
      }
      if (music.id === null) {
        return window.__sh_orig_ajaxUrl(music, callback);
      }

      resolveUrl(music).then(function (u) {
        music.url = u || 'err';
        if (window.updateMinfo) window.updateMinfo(music);
        callback(music);
      }).catch(function () {
        return window.__sh_orig_ajaxUrl(music, callback);
      });
      return true;
    };

    // 2. 挂钩封面图片获取
    if (typeof window.ajaxPic === 'function') {
      window.__sh_orig_ajaxPic = window.ajaxPic;
      window.ajaxPic = function (music, callback) {
        if (music.pic !== null && music.pic !== 'err' && music.pic !== '') {
          return window.__sh_orig_ajaxPic(music, callback);
        }
        resolvePic(music).then(function (u) {
          if (u) {
            music.pic = u;
            if (window.updateMinfo) window.updateMinfo(music);
            callback(music);
          } else {
            window.__sh_orig_ajaxPic(music, callback);
          }
        }).catch(function () {
          window.__sh_orig_ajaxPic(music, callback);
        });
        return true;
      };
    }

    // 3. 挂钩搜索功能（确保强制加上防 CDN 缓存时间戳）
    if (typeof window.ajaxSearch === 'function') {
      window.__sh_orig_ajaxSearch = window.ajaxSearch;
      window.ajaxSearch = function () {
        // 将 mkPlayer.api 自动指向后端并附带防缓存参数
        if (window.mkPlayer) {
          window.mkPlayer.api = 'backend.php?_t=' + Date.now();
        }
        return window.__sh_orig_ajaxSearch();
      };
    }

    return true;
  }

  /* ==========================================================
     用户界面 (UI / 交互控制面板)
     ========================================================== */
  var CSS = [
    '#sh-btn{position:fixed;right:18px;bottom:18px;z-index:99999;',
    '  display:inline-flex;align-items:center;gap:6px;padding:8px 14px;',
    '  border-radius:999px;border:1px solid rgba(255,255,255,.2);cursor:pointer;',
    '  background:rgba(18,24,38,.9);color:#e2e8f0;font:500 12.5px/1 system-ui,-apple-system,sans-serif;',
    '  backdrop-filter:blur(14px);-webkit-backdrop-filter:blur(14px);',
    '  box-shadow:0 8px 24px -6px rgba(0,0,0,.6);transition:all .25s ease}',
    '#sh-btn:hover{transform:translateY(-2px);border-color:#38bdf8;box-shadow:0 12px 30px -6px rgba(56,189,248,.3)}',
    '#sh-btn .dot-pulse{width:7px;height:7px;border-radius:50%;background:#34d399;box-shadow:0 0 8px #34d399}',
    '#sh-mask{position:fixed;inset:0;z-index:99998;background:rgba(4,6,12,.68);display:none;backdrop-filter:blur(3px)}',
    '#sh-mask.open{display:block}',
    '#sh-modal{position:fixed;left:50%;top:50%;transform:translate(-50%,-50%);z-index:99999;',
    '  width:min(640px,calc(100vw - 32px));max-height:86vh;overflow-y:auto;display:none;',
    '  border-radius:18px;border:1px solid rgba(255,255,255,.14);',
    '  background:#0f172a;color:#f8fafc;font:13.5px/1.7 system-ui,-apple-system,sans-serif;',
    '  box-shadow:0 25px 60px -15px rgba(0,0,0,.85);padding:24px 22px 20px}',
    '#sh-modal.open{display:block}',
    '#sh-modal h3{margin:0 0 6px;font-size:16px;font-weight:600;display:flex;align-items:center;gap:8px}',
    '#sh-modal .sub{margin:0 0 16px;font-size:12px;color:#94a3b8}',
    '#sh-modal .row{margin:0 0 14px}',
    '#sh-modal label{display:block;font-size:11.5px;font-weight:500;color:#94a3b8;margin-bottom:6px}',
    '#sh-modal select,#sh-modal input,#sh-modal textarea{',
    '  width:100%;padding:8px 12px;border-radius:10px;box-sizing:border-box;',
    '  border:1px solid rgba(255,255,255,.14);background:rgba(255,255,255,.05);color:#f8fafc;font:inherit;font-size:12.5px}',
    '#sh-modal select option{background:#0f172a;color:#f8fafc}',
    '#sh-modal .btn{display:inline-flex;align-items:center;gap:4px;padding:6px 14px;',
    '  border-radius:999px;cursor:pointer;font:inherit;font-size:12px;',
    '  border:1px solid rgba(255,255,255,.16);background:rgba(255,255,255,.06);color:#f8fafc;transition:all .2s ease}',
    '#sh-modal .btn:hover{border-color:#38bdf8;background:rgba(56,189,248,.15)}',
    '#sh-modal .btn--primary{background:#0284c7;border-color:transparent;color:#fff;font-weight:600}',
    '#sh-modal .btn--primary:hover{background:#0369a1}',
    '#sh-modal .btn--sm{padding:4px 10px;font-size:11.5px}',
    '#sh-modal .list{list-style:none;margin:0 0 12px;padding:0}',
    '#sh-modal .list li{display:flex;align-items:center;gap:10px;padding:9px 4px;border-bottom:1px solid rgba(255,255,255,.07)}',
    '#sh-modal .list li:last-child{border-bottom:0}',
    '#sh-modal .tag{padding:2px 7px;border-radius:999px;font-size:10.5px;background:rgba(56,189,248,.15);color:#38bdf8;border:1px solid rgba(56,189,248,.3)}',
    '#sh-modal .tag--preset{background:rgba(52,211,153,.15);color:#34d399;border-color:rgba(52,211,153,.3)}',
    '#sh-modal .msg{font-size:12px;margin-top:8px;padding:8px 12px;border-radius:8px;display:none}',
    '#sh-modal .msg.show{display:block;background:rgba(52,211,153,.12);color:#34d399;border:1px solid rgba(52,211,153,.25)}',
    '#sh-modal .msg.err{background:rgba(244,63,94,.12);color:#f43f5e;border:1px solid rgba(244,63,94,.25)}',
    '#sh-modal .close-btn{position:absolute;right:16px;top:16px;width:28px;height:28px;border-radius:50%;border:1px solid rgba(255,255,255,.14);background:transparent;color:#94a3b8;cursor:pointer}',
    '@media(max-width:768px){',
    '  #sh-btn{right:12px;bottom:calc(72px + env(safe-area-inset-bottom,0px));padding:6px 11px;font-size:11.5px}',
    '  #sh-modal{width:min(540px,calc(100vw - 20px));padding:18px 14px;max-height:80vh}',
    '}'
  ].join('\n');

  function renderUI() {
    if (document.getElementById('sh-btn')) return;
    var st = document.createElement('style');
    st.textContent = CSS;
    document.head.appendChild(st);

    var btn = document.createElement('button');
    btn.id = 'sh-btn';
    btn.type = 'button';
    btn.innerHTML = '<span class="dot-pulse"></span><span>音源引擎 2.0</span>';
    btn.addEventListener('click', openModal);
    document.body.appendChild(btn);

    var mask = document.createElement('div');
    mask.id = 'sh-mask';
    mask.addEventListener('click', closeModal);
    document.body.appendChild(mask);

    var modal = document.createElement('div');
    modal.id = 'sh-modal';
    document.body.appendChild(modal);
  }

  function openModal() {
    document.getElementById('sh-mask').classList.add('open');
    var m = document.getElementById('sh-modal');
    m.classList.add('open');
    renderModalContent();
  }

  function closeModal() {
    document.getElementById('sh-mask').classList.remove('open');
    document.getElementById('sh-modal').classList.remove('open');
  }

  function renderModalContent() {
    var m = document.getElementById('sh-modal');
    if (!m) return;

    var rows = allBackends().map(function (b) {
      var isCur = (b.id === state.urlId);
      var tag = b.isPreset ? '<span class="tag tag--preset">内置预置</span>'
        : b.builtin ? '<span class="tag">官方聚合</span>' : '<span class="tag">自定义</span>';

      return '<li>' +
        '<input type="radio" name="sh_url_radio" value="' + b.id + '"' + (isCur ? ' checked' : '') + '>' +
        '<span style="flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">' + b.name + '</span>' +
        tag +
        '<button class="btn btn--sm" data-test="' + b.id + '">测试取流</button>' +
        '</li>';
    }).join('');

    m.innerHTML =
      '<button class="close-btn" id="sh-x">✕</button>' +
      '<h3>⚡ 音源后端引擎 (SourceHub 2.0)</h3>' +
      '<p class="sub">已自动挂载 20+ 洛雪音频解析源，具备自动容灾、HTTPS 混合内容防拦截与跨域代理能力。</p>' +
      '<div class="row"><label>选择默认主力音源（单源失败将自动无缝回退备用源）</label>' +
      '<ul class="list">' + rows + '</ul></div>' +
      '<div class="row">' +
        '<label>导入新的洛雪自定义音源 (.js 脚本文件)</label>' +
        '<input type="file" id="sh-file" accept=".js,.txt" multiple>' +
      '</div>' +
      '<div class="msg" id="sh-msg"></div>';

    var x = m.querySelector('#sh-x');
    if (x) x.addEventListener('click', closeModal);

    var radios = m.querySelectorAll('input[name="sh_url_radio"]');
    Array.prototype.forEach.call(radios, function (r) {
      r.addEventListener('change', function () {
        state.urlId = r.value;
        save();
        showMsg('已切换主力音源为: ' + (findBackend(r.value) ? findBackend(r.value).name : r.value));
      });
    });

    Array.prototype.forEach.call(m.querySelectorAll('[data-test]'), function (btn) {
      btn.addEventListener('click', function () {
        var bid = btn.dataset.test;
        var b = findBackend(bid);
        if (!b) return showMsg('音源不存在', true);

        showMsg('正在探测取链 (' + b.name.replace(/\s*\(.*\)/, '') + ')...');

        var probeSongs = [
          { source: 'kuwo', id: '2689227', name: '晴天', artist: '周杰伦', label: '酷我' },
          { source: 'netease', id: '436514312', name: '成都', artist: '赵雷', label: '网易云' },
          { source: 'qq', id: '0039MnYb0qxYAx', songmid: '0039MnYb0qxYAx', name: '晴天', artist: '周杰伦', label: 'QQ音乐' }
        ];

        var t0 = Date.now();
        var pIdx = 0;

        function runProbe() {
          if (pIdx >= probeSongs.length) {
            showMsg('⚠️ 该音源在主流歌曲库中未能成功返回可用直链', true);
            return;
          }
          var song = probeSongs[pIdx++];
          (b.kind === 'lx' ? resolveViaLx(b, song, '128k') : fallbackBuiltinUrl(song))
            .then(function (u) {
              var ms = Date.now() - t0;
              if (u && /^https?:\/\//i.test(u)) {
                showMsg('✅ 测试通过 [' + song.label + '] (' + ms + 'ms) 直链: ' + u.slice(0, 55) + '...');
              } else {
                runProbe();
              }
            }).catch(function () {
              runProbe();
            });
        }

        runProbe();
      });
    });

    var fileInp = m.querySelector('#sh-file');
    if (fileInp) {
      fileInp.addEventListener('change', function () {
        var files = fileInp.files;
        if (!files || !files.length) return;
        var count = 0;
        Array.prototype.forEach.call(files, function (f) {
          var reader = new FileReader();
          reader.onload = function () {
            try {
              var s = loadLxScript(reader.result, null, f.name.replace(/\.[^.]+$/, ''));
              state.backends.push({
                id: s.id,
                name: f.name.replace(/\.[^.]+$/, ''),
                kind: 'lx',
                code: reader.result,
                session: s,
                sources: Object.keys((s.inited && s.inited.sources) || {})
              });
              count++;
              save();
              showMsg('成功导入并激活 ' + count + ' 个自定义音源');
              renderModalContent();
            } catch (e) {
              showMsg('导入 ' + f.name + ' 失败: ' + (e && e.message), true);
            }
          };
          reader.readAsText(f);
        });
      });
    }
  }

  function showMsg(text, isErr) {
    var msg = document.getElementById('sh-msg');
    if (!msg) return;
    msg.textContent = text;
    msg.className = 'msg show' + (isErr ? ' err' : '');
  }

  /* ==========================================================
     启动入口
     ========================================================== */
  function init() {
    load();
    preloadPresets().then(function () {
      save();
    });

    var timer = setInterval(function () {
      if (hookPlayer()) {
        clearInterval(timer);
      }
    }, 200);
    setTimeout(function () { clearInterval(timer); }, 10000);

    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', renderUI);
    } else {
      renderUI();
    }

    console.log('[SourceHub 2.0] 音源引擎就绪');
  }

  window.SourceHub = {
    resolveUrl: resolveUrl,
    resolvePic: resolvePic,
    resolveLyric: resolveLyric,
    list: allBackends,
    open: openModal,
    getState: function () {
      return { urlId: state.urlId, searchId: state.searchId, backends: allBackends() };
    }
  };

  init();
})();
