/**
 * 小白在线音乐 · 用户密码体系与云端持久化中枢 (CloudVault 2.0)
 * 深度联动后端 SQLite / JSON 数据库
 * 功能：
 *   1. 用户名 + 密码 安全注册与登录 (加盐哈希 + Token 凭证)
 *   2. 账号下绑定 / 更改 / 解除网易云音乐 UID
 *   3. 听歌记录云端永久漫游 (随用户名密码在多设备自动漫游)
 *   4. 红心收藏双向云同步 (安全归属到当前用户)
 *   5. Claude Code 沉静极简暗黑弹窗与个人卡片控制台
 */

(function (window, $) {
    'use strict';

    var CloudVault = {
        apiEndpoint: 'api.php',
        tokenKey: 'mk_cloud_vault_token',
        userKey: 'mk_cloud_vault_account',
        token: '',
        user: {
            id: 0,
            username: '',
            netease_uid: '',
            netease_name: '',
            netease_avatar: ''
        },
        stats: {
            favorites: 0,
            history: 0
        },

        // 初始化
        init: function () {
            this.loadLocalAuth();
            this.renderBadge();
            this.bindEvents();
            if (this.token) {
                this.fetchProfile();
            }
        },

        // 从本地 localStorage 读取 token 和缓存用户信息
        loadLocalAuth: function () {
            try {
                this.token = localStorage.getItem(this.tokenKey) || '';
                var u = JSON.parse(localStorage.getItem(this.userKey) || 'null');
                if (u && typeof u === 'object') {
                    this.user = $.extend(this.user, u);
                }
            } catch (e) {
                this.token = '';
            }
        },

        // 保存授权态
        saveAuth: function (token, user) {
            this.token = token || '';
            if (user) {
                this.user = $.extend(this.user, user);
            }
            try {
                if (this.token) {
                    localStorage.setItem(this.tokenKey, this.token);
                    localStorage.setItem(this.userKey, JSON.stringify(this.user));
                    // 兼容旧版 MKOnline 的 uid 与 uname 缓存
                    if (this.user.netease_uid && typeof playerSavedata === 'function') {
                        playerSavedata('uid', this.user.netease_uid);
                        playerSavedata('uname', this.user.netease_name || this.user.username);
                    }
                } else {
                    localStorage.removeItem(this.tokenKey);
                    localStorage.removeItem(this.userKey);
                    if (typeof playerSavedata === 'function') {
                        playerSavedata('uid', '');
                        playerSavedata('uname', '');
                        playerSavedata('ulist', '');
                    }
                }
            } catch (e) {}

            this.renderBadge();
        },

        // 登出
        logout: function () {
            this.saveAuth('', {
                id: 0,
                username: '',
                netease_uid: '',
                netease_name: '',
                netease_avatar: ''
            });
            this.stats.favorites = 0;
            this.stats.history = 0;
            if (typeof clearUserlist === 'function') {
                clearUserlist();
            }
            if (typeof showToast === 'function') {
                showToast('已安全退出账号');
            } else {
                layer.msg('已安全退出账号');
            }
        },

        // 验证 token 并拉取用户最新资料与统计
        fetchProfile: function () {
            var self = this;
            if (!self.token) return;

            $.ajax({
                type: 'POST',
                url: self.apiEndpoint,
                data: {
                    types: 'user_profile',
                    token: self.token
                },
                dataType: 'json',
                success: function (res) {
                    if (res && res.code === 200 && res.user) {
                        self.saveAuth(self.token, res.user);
                        if (res.stat) {
                            self.stats.favorites = res.stat.favorites || 0;
                            self.stats.history = res.stat.history || 0;
                        }
                        self.renderBadge();
                        // 如果绑定了网易云并且尚未加载歌单，自动触发歌单拉取
                        if (self.user.netease_uid && typeof ajaxUserList === 'function') {
                            if (!window.rem || !rem.uid || rem.uid != self.user.netease_uid) {
                                ajaxUserList(self.user.netease_uid);
                            }
                        }
                    } else if (res && res.code === 401) {
                        // Token 过期或被清除
                        self.saveAuth('', null);
                    }
                }
            });
        },

        // 渲染顶部/顶栏右上角微徽章
        renderBadge: function () {
            var $badges = $('.cloud-account-badge, #cloud-account-badge, #netease-user-badge');
            if (!$badges.length) return;

            if (this.token && this.user.username) {
                var displayName = this.user.username;
                var avatarSrc = this.user.netease_avatar || 'images/player_cover.png';
                $badges.addClass('is-logged-in');
                $badges.find('.account-avatar, .badge-avatar').attr('src', avatarSrc);
                $badges.find('.account-name, .badge-name').text(displayName);
                $badges.attr('title', '已登录: ' + displayName + (this.user.netease_uid ? ' (已绑定网易云: ' + this.user.netease_uid + ')' : ' (未绑定网易云)') + ' - 点击管理');
            } else {
                $badges.removeClass('is-logged-in');
                $badges.find('.account-avatar, .badge-avatar').attr('src', 'images/player_cover.png');
                $badges.find('.account-name, .badge-name').text('登录 / 注册');
                $badges.attr('title', '点击登录或注册账号，支持多端密码登录并绑定网易云歌单');
            }
        },

        // 记录听歌历史到云端
        recordHistory: function (music) {
            if (!music || !music.id) return;
            var payload = {
                types: 'record_history',
                song: JSON.stringify({
                    id: music.id,
                    name: music.name || '未知歌曲',
                    artist: music.artist || '群星',
                    album: music.album || '',
                    source: music.source || 'netease',
                    pic: music.pic || ''
                })
            };
            if (this.token) {
                payload.token = this.token;
            } else if (this.user.netease_uid) {
                payload.uid = this.user.netease_uid;
            } else {
                return;
            }

            $.ajax({
                type: 'POST',
                url: this.apiEndpoint,
                data: payload,
                dataType: 'json'
            });
        },

        // 红心收藏云端双向同步
        toggleFavorite: function (music, callback) {
            var self = this;
            var payload = {
                types: 'toggle_favorite',
                song: JSON.stringify({
                    id: music.id,
                    name: music.name || '未知歌曲',
                    artist: music.artist || '群星',
                    album: music.album || '',
                    source: music.source || 'netease',
                    pic: music.pic || ''
                })
            };

            if (self.token) {
                payload.token = self.token;
            } else if (self.user.netease_uid) {
                payload.uid = self.user.netease_uid;
            } else {
                if (typeof showToast === 'function') {
                    showToast('提示：登录账号即可将红心收藏永久保存至云端');
                }
                if (typeof callback === 'function') callback(null);
                return;
            }

            $.ajax({
                type: 'POST',
                url: self.apiEndpoint,
                data: payload,
                dataType: 'json',
                success: function (res) {
                    if (res && res.code === 200 && typeof callback === 'function') {
                        callback(res.liked);
                    }
                }
            });
        },

        // 呼出弹窗中枢
        openModal: function () {
            if (this.token && this.user.username) {
                this.showAccountDashboard();
            } else {
                this.showAuthDialog('login');
            }
        },

        // 登录 / 注册综合弹窗 (Claude Code 风格)
        showAuthDialog: function (defaultTab) {
            var self = this;
            defaultTab = defaultTab || 'login';

            var html = '<div class="claude-modal-box claude-auth-box">' +
                '<div class="claude-modal-head">' +
                '  <div class="modal-icon">🎵</div>' +
                '  <div class="modal-title">小白音乐 · 账号中心</div>' +
                '  <div class="modal-subtitle">使用用户名密码安全登录，自动漫游听歌记录并绑定网易云歌单</div>' +
                '</div>' +

                '<!-- 选项卡切换 -->' +
                '<div class="claude-auth-tabs">' +
                '  <div class="auth-tab-item ' + (defaultTab === 'login' ? 'active' : '') + '" data-tab="login">账号登录</div>' +
                '  <div class="auth-tab-item ' + (defaultTab === 'register' ? 'active' : '') + '" data-tab="register">快速注册</div>' +
                '</div>' +

                '<!-- 登录表单 -->' +
                '<div class="auth-tab-pane ' + (defaultTab === 'login' ? 'active' : '') + '" id="pane-login">' +
                '  <div class="auth-form-group">' +
                '    <label class="modal-input-label">用户名</label>' +
                '    <input type="text" id="login-username" class="claude-modal-input" placeholder="输入您的用户名" autocomplete="username" />' +
                '  </div>' +
                '  <div class="auth-form-group">' +
                '    <label class="modal-input-label">密码</label>' +
                '    <input type="password" id="login-password" class="claude-modal-input" placeholder="输入您的密码" autocomplete="current-password" />' +
                '  </div>' +
                '  <button type="button" id="btn-submit-login" class="claude-modal-block-btn primary">立即登录</button>' +
                '</div>' +

                '<!-- 注册表单 -->' +
                '<div class="auth-tab-pane ' + (defaultTab === 'register' ? 'active' : '') + '" id="pane-register">' +
                '  <div class="auth-form-group">' +
                '    <label class="modal-input-label">设置用户名 <span class="label-hint">(2~30位字符)</span></label>' +
                '    <input type="text" id="reg-username" class="claude-modal-input" placeholder="例如: dabai" autocomplete="off" />' +
                '  </div>' +
                '  <div class="auth-form-group">' +
                '    <label class="modal-input-label">设置密码 <span class="label-hint">(至少4位)</span></label>' +
                '    <input type="password" id="reg-password" class="claude-modal-input" placeholder="设置一个易记的密码" autocomplete="new-password" />' +
                '  </div>' +
                '  <div class="auth-form-group">' +
                '    <label class="modal-input-label">绑定网易云 UID <span class="label-hint">(选填，注册时直接绑定)</span></label>' +
                '    <input type="text" id="reg-netease-uid" class="claude-modal-input" placeholder="例如: 32953014 (选填)" autocomplete="off" />' +
                '  </div>' +
                '  <button type="button" id="btn-submit-register" class="claude-modal-block-btn primary">创建账号并自动登录</button>' +
                '  <div class="modal-tip-box" style="margin-top:12px;">' +
                '    💡 提示：网易云 UID 是纯数字，位于个人主页网址 <code>/user/home?id=</code> 后。注册时绑定可自动同步你的公开歌单与个人头像。' +
                '  </div>' +
                '</div>' +

                '</div>';

            var idx = layer.open({
                type: 1,
                title: false,
                closeBtn: 1,
                shadeClose: true,
                area: $(window).width() < 560 ? ['92%', 'auto'] : ['440px', 'auto'],
                skin: 'claude-layer-skin',
                content: html
            });

            // Tab 切换响应
            $('.auth-tab-item').on('click', function () {
                var tab = $(this).data('tab');
                $('.auth-tab-item').removeClass('active');
                $(this).addClass('active');
                $('.auth-tab-pane').removeClass('active');
                $('#pane-' + tab).addClass('active');
            });

            // 登录提交
            $('#btn-submit-login').on('click', function () {
                var u = $.trim($('#login-username').val());
                var p = $.trim($('#login-password').val());
                if (!u || !p) {
                    layer.msg('请输入用户名和密码', { anim: 6 });
                    return;
                }
                var loadIdx = layer.load(2);
                $.ajax({
                    type: 'POST',
                    url: self.apiEndpoint,
                    data: {
                        types: 'user_login',
                        username: u,
                        password: p
                    },
                    dataType: 'json',
                    success: function (res) {
                        layer.close(loadIdx);
                        if (res && res.code === 200) {
                            layer.close(idx);
                            self.saveAuth(res.token, res.user);
                            layer.msg('🎉 欢迎回来，' + (res.user.username || u) + '！', { icon: 1 });
                            if (res.user.netease_uid && typeof ajaxUserList === 'function') {
                                ajaxUserList(res.user.netease_uid);
                            }
                        } else {
                            layer.msg((res && res.msg) || '登录失败，请检查账号密码', { anim: 6 });
                        }
                    },
                    error: function () {
                        layer.close(loadIdx);
                        layer.msg('网络通信异常，请重试', { anim: 6 });
                    }
                });
            });

            // 注册提交
            $('#btn-submit-register').on('click', function () {
                var u = $.trim($('#reg-username').val());
                var p = $.trim($('#reg-password').val());
                var nuid = $.trim($('#reg-netease-uid').val());
                if (!u || !p) {
                    layer.msg('请填写用户名与密码', { anim: 6 });
                    return;
                }
                if (p.length < 4) {
                    layer.msg('密码长度至少需要4位', { anim: 6 });
                    return;
                }
                var loadIdx = layer.load(2);
                $.ajax({
                    type: 'POST',
                    url: self.apiEndpoint,
                    data: {
                        types: 'user_register',
                        username: u,
                        password: p,
                        netease_uid: nuid
                    },
                    dataType: 'json',
                    success: function (res) {
                        layer.close(loadIdx);
                        if (res && res.code === 200) {
                            layer.close(idx);
                            self.saveAuth(res.token, res.user);
                            layer.msg('✨ 账号创建成功，已自动登录！', { icon: 1 });
                            if (res.user.netease_uid && typeof ajaxUserList === 'function') {
                                ajaxUserList(res.user.netease_uid);
                            }
                        } else {
                            layer.msg((res && res.msg) || '注册失败', { anim: 6 });
                        }
                    },
                    error: function () {
                        layer.close(loadIdx);
                        layer.msg('网络通信异常，请重试', { anim: 6 });
                    }
                });
            });
        },

        // 已登录管理面板 (Claude Code 风格卡片)
        showAccountDashboard: function () {
            var self = this;
            var isBoundNetease = !!self.user.netease_uid;

            var html = '<div class="claude-modal-box claude-dash-box">' +
                '<div class="claude-modal-head">' +
                '  <div class="user-card-avatar-wrap">' +
                '    <img src="' + (self.user.netease_avatar || 'images/player_cover.png') + '" class="user-card-avatar" alt="头像" />' +
                '    <span class="user-card-status-dot" title="云端账号正常连接"></span>' +
                '  </div>' +
                '  <div class="modal-title">' + (self.user.username || '我的账号') + '</div>' +
                '  <div class="modal-subtitle">账号密码登录就绪 · 听歌记录永久漫游</div>' +
                '</div>' +

                '<!-- 网易云账号绑定状态卡片 -->' +
                '<div class="netease-binding-panel">' +
                (isBoundNetease ? 
                    ('  <div class="binding-info-row">' +
                     '    <div class="binding-label">已绑网易云:</div>' +
                     '    <div class="binding-val">' + (self.user.netease_name || self.user.netease_uid) + ' <span class="uid-tag">(UID: ' + self.user.netease_uid + ')</span></div>' +
                     '  </div>' +
                     '  <div class="binding-action-row">' +
                     '    <button type="button" id="btn-sync-netease-sheets" class="claude-pill-btn primary">🔄 刷新歌单</button>' +
                     '    <button type="button" id="btn-change-netease-uid" class="claude-pill-btn secondary">✏️ 更换UID</button>' +
                     '    <button type="button" id="btn-unbind-netease" class="claude-pill-btn danger">解除绑定</button>' +
                     '  </div>') :
                    ('  <div class="binding-empty-notice">尚未绑定网易云 UID，绑定后可一键拉取歌单</div>' +
                     '  <div class="binding-input-group">' +
                     '    <input type="text" id="input-new-netease-uid" class="claude-modal-input" placeholder="输入纯数字网易云 UID" autocomplete="off" />' +
                     '    <button type="button" id="btn-submit-bind-uid" class="claude-modal-submit-btn">立即绑定</button>' +
                     '  </div>')
                ) +
                '</div>' +

                '<!-- 数据指标看板 -->' +
                '<div class="user-stats-grid">' +
                '  <div class="user-stat-card">' +
                '    <div class="stat-num">' + (self.stats.favorites || 0) + '</div>' +
                '    <div class="stat-label">红心收藏</div>' +
                '  </div>' +
                '  <div class="user-stat-card">' +
                '    <div class="stat-num">' + (self.stats.history || 0) + '</div>' +
                '    <div class="stat-label">漫游历史</div>' +
                '  </div>' +
                '  <div class="user-stat-card">' +
                '    <div class="stat-num">' + (typeof musicList !== 'undefined' ? Math.max(0, musicList.length - 3) : 0) + '</div>' +
                '    <div class="stat-label">同步歌单</div>' +
                '  </div>' +
                '</div>' +

                '<!-- 操作栏 -->' +
                '<div class="user-action-buttons">' +
                '  <button type="button" id="btn-user-logout" class="claude-card-btn danger">🚪 退出当前账号</button>' +
                '</div>' +

                '</div>';

            var idx = layer.open({
                type: 1,
                title: false,
                closeBtn: 1,
                shadeClose: true,
                area: $(window).width() < 560 ? ['92%', 'auto'] : ['460px', 'auto'],
                skin: 'claude-layer-skin',
                content: html
            });

            // 绑定新网易云 UID
            $('#btn-submit-bind-uid').on('click', function () {
                var uid = $.trim($('#input-new-netease-uid').val());
                if (!uid || isNaN(uid)) {
                    layer.msg('请输入纯数字的网易云 UID', { anim: 6 });
                    return;
                }
                var loadIdx = layer.load(2);
                $.ajax({
                    type: 'POST',
                    url: self.apiEndpoint,
                    data: {
                        types: 'user_bind_netease',
                        token: self.token,
                        netease_uid: uid
                    },
                    dataType: 'json',
                    success: function (res) {
                        layer.close(loadIdx);
                        if (res && res.code === 200 && res.user) {
                            layer.close(idx);
                            self.saveAuth(self.token, res.user);
                            layer.msg('✨ 网易云绑定成功！正在拉取个性歌单...', { icon: 1 });
                            if (typeof ajaxUserList === 'function') ajaxUserList(uid);
                        } else {
                            layer.msg((res && res.msg) || '绑定失败', { anim: 6 });
                        }
                    },
                    error: function () {
                        layer.close(loadIdx);
                        layer.msg('网络异常，请重试');
                    }
                });
            });

            // 刷新网易云歌单
            $('#btn-sync-netease-sheets').on('click', function () {
                layer.close(idx);
                if (typeof playerSavedata === 'function') playerSavedata('ulist', '');
                layer.msg('🔄 正在拉取网易云最新歌单...');
                if (typeof clearUserlist === 'function') clearUserlist();
            });

            // 更换 UID
            $('#btn-change-netease-uid').on('click', function () {
                layer.prompt({ title: '请输入新的网易云纯数字 UID', formType: 0 }, function (val, pIdx) {
                    val = $.trim(val);
                    if (!val || isNaN(val)) {
                        layer.msg('UID 只能是纯数字');
                        return;
                    }
                    layer.close(pIdx);
                    var loadIdx = layer.load(2);
                    $.ajax({
                        type: 'POST',
                        url: self.apiEndpoint,
                        data: {
                            types: 'user_bind_netease',
                            token: self.token,
                            netease_uid: val
                        },
                        dataType: 'json',
                        success: function (res) {
                            layer.close(loadIdx);
                            layer.close(idx);
                            if (res && res.code === 200 && res.user) {
                                self.saveAuth(self.token, res.user);
                                layer.msg('已更换绑定的网易云 UID', { icon: 1 });
                                if (typeof ajaxUserList === 'function') ajaxUserList(val);
                            } else {
                                layer.msg(res.msg || '更换失败');
                            }
                        }
                    });
                });
            });

            // 解除网易云绑定
            $('#btn-unbind-netease').on('click', function () {
                layer.confirm('确定要从当前账号解除网易云 UID 绑定吗？', { icon: 3, title: '解除绑定确认' }, function (cIdx) {
                    layer.close(cIdx);
                    var loadIdx = layer.load(2);
                    $.ajax({
                        type: 'POST',
                        url: self.apiEndpoint,
                        data: {
                            types: 'user_unbind_netease',
                            token: self.token
                        },
                        dataType: 'json',
                        success: function (res) {
                            layer.close(loadIdx);
                            layer.close(idx);
                            self.user.netease_uid = '';
                            self.user.netease_name = '';
                            self.user.netease_avatar = '';
                            self.saveAuth(self.token, self.user);
                            layer.msg('已解除网易云账号绑定');
                            if (typeof clearUserlist === 'function') clearUserlist();
                        }
                    });
                });
            });

            // 退出当前账号
            $('#btn-user-logout').on('click', function () {
                layer.close(idx);
                self.logout();
            });
        },

        // 全局事件监听绑定
        bindEvents: function () {
            var self = this;
            $(document).on('click', '.cloud-account-badge, #cloud-account-badge, #netease-user-badge', function () {
                self.openModal();
            });
        }
    };

    window.CloudVault = CloudVault;

    $(function () {
        CloudVault.init();
    });

})(window, jQuery);
