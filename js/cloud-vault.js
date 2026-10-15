/**
 * 小白在线音乐 · 云端账户与持久化数据中枢 (CloudVault 1.0)
 * 深度联动后端 SQLite / JSON 数据库与网易云官方 API
 * 实现：网易云账号绑定、云端听歌历史多端漫游、红心收藏双向同步、Claude 极简暗色交互
 */

(function (window, $) {
    'use strict';

    var CloudVault = {
        apiEndpoint: 'api.php',
        user: {
            uid: '',
            nickname: '',
            avatar: ''
        },
        stats: {
            favorites: 0,
            history: 0,
            playlists: 0
        },

        // 初始化
        init: function () {
            this.loadLocalUser();
            this.renderBadge();
            this.bindEvents();
            if (this.user.uid) {
                this.syncCloudAccount(this.user.uid);
            }
        },

        // 从本地存储装载用户
        loadLocalUser: function () {
            var uid = '';
            if (typeof playerReaddata === 'function') {
                uid = playerReaddata('uid') || '';
            } else if (window.localStorage) {
                try {
                    uid = JSON.parse(localStorage.getItem('mkPlayer2_uid')) || '';
                } catch (e) {
                    uid = '';
                }
            }
            var nickname = '';
            var avatar = '';
            try {
                var stored = JSON.parse(localStorage.getItem('mk_cloud_vault_user')) || {};
                nickname = stored.nickname || '';
                avatar = stored.avatar || '';
            } catch (e) {}

            this.user.uid = uid;
            this.user.nickname = nickname || (uid ? '网易云用户' : '');
            this.user.avatar = avatar || 'images/player_cover.png';
        },

        // 保存用户到本地
        saveLocalUser: function (user) {
            this.user.uid = user.uid || '';
            this.user.nickname = user.nickname || '网易云用户';
            this.user.avatar = user.avatar || 'images/player_cover.png';

            if (typeof playerSavedata === 'function') {
                playerSavedata('uid', this.user.uid);
                playerSavedata('uname', this.user.nickname);
            } else if (window.localStorage) {
                localStorage.setItem('mkPlayer2_uid', JSON.stringify(this.user.uid));
                localStorage.setItem('mkPlayer2_uname', JSON.stringify(this.user.nickname));
            }
            if (window.localStorage) {
                localStorage.setItem('mk_cloud_vault_user', JSON.stringify(this.user));
            }
            this.renderBadge();
        },

        // 同步并拉取云端账户信息
        syncCloudAccount: function (uid) {
            var self = this;
            if (!uid) return;
            $.ajax({
                type: 'POST',
                url: self.apiEndpoint,
                data: {
                    types: 'sync_account',
                    uid: uid,
                    nickname: self.user.nickname,
                    avatar: self.user.avatar
                },
                dataType: 'json',
                success: function (res) {
                    if (res && res.code === 200) {
                        if (res.user) {
                            if (res.user.nickname) self.user.nickname = res.user.nickname;
                            if (res.user.avatar) self.user.avatar = res.user.avatar;
                            self.saveLocalUser(self.user);
                        }
                        if (res.stat) {
                            self.stats.favorites = res.stat.favorites || 0;
                            self.stats.history = res.stat.history || 0;
                        }
                        self.renderBadge();
                    }
                }
            });
        },

        // 渲染顶部账户微胶囊
        renderBadge: function () {
            var $badge = $('#cloud-account-badge, #netease-user-badge');
            if (!$badge.length) return;

            if (this.user.uid) {
                $badge.addClass('is-bound');
                $badge.find('.account-avatar, .badge-avatar').attr('src', this.user.avatar || 'images/player_cover.png');
                $badge.find('.account-name, .badge-name').text(this.user.nickname || ('UID:' + this.user.uid));
                $badge.attr('title', '已连接网易云云端数据库: ' + (this.user.nickname || this.user.uid) + ' (点击管理)');
            } else {
                $badge.removeClass('is-bound');
                $badge.find('.account-avatar, .badge-avatar').attr('src', 'images/player_cover.png');
                $badge.find('.account-name, .badge-name').text('绑定网易云');
                $badge.attr('title', '点击绑定网易云 UID，开启多端云同步与个性歌单');
            }
        },

        // 记录听歌历史到 SQLite
        recordHistory: function (music) {
            if (!this.user.uid || !music || !music.id) return;
            $.ajax({
                type: 'POST',
                url: this.apiEndpoint,
                data: {
                    types: 'record_history',
                    uid: this.user.uid,
                    song: JSON.stringify({
                        id: music.id,
                        name: music.name || '未知歌曲',
                        artist: music.artist || '群星',
                        album: music.album || '',
                        source: music.source || 'netease',
                        pic: music.pic || ''
                    })
                },
                dataType: 'json'
            });
        },

        // 云端红心收藏双向同步
        toggleFavorite: function (music, callback) {
            var self = this;
            if (!self.user.uid) {
                // 未绑定时提示可绑定体验云同步
                if (typeof showToast === 'function') {
                    showToast('提示：绑定网易云UID可永久云端漫游红心收藏哦');
                }
                if (typeof callback === 'function') callback(null);
                return;
            }
            $.ajax({
                type: 'POST',
                url: self.apiEndpoint,
                data: {
                    types: 'toggle_favorite',
                    uid: self.user.uid,
                    song: JSON.stringify({
                        id: music.id,
                        name: music.name || '未知歌曲',
                        artist: music.artist || '群星',
                        album: music.album || '',
                        source: music.source || 'netease',
                        pic: music.pic || ''
                    })
                },
                dataType: 'json',
                success: function (res) {
                    if (res && res.code === 200 && typeof callback === 'function') {
                        callback(res.liked);
                    }
                }
            });
        },

        // 拉取云端红心收藏
        getFavorites: function (callback) {
            if (!this.user.uid) {
                if (typeof callback === 'function') callback([]);
                return;
            }
            $.ajax({
                type: 'GET',
                url: this.apiEndpoint,
                data: {
                    types: 'get_favorites',
                    uid: this.user.uid,
                    _t: Date.now()
                },
                dataType: 'json',
                success: function (res) {
                    if (res && res.code === 200 && Array.isArray(res.list)) {
                        if (typeof callback === 'function') callback(res.list);
                    } else {
                        if (typeof callback === 'function') callback([]);
                    }
                },
                error: function () {
                    if (typeof callback === 'function') callback([]);
                }
            });
        },

        // 打开绑定/管理对话框 (Claude Code 典雅暗黑风格)
        openModal: function () {
            var self = this;
            if (self.user.uid) {
                self.showAccountCard();
            } else {
                self.showBindDialog();
            }
        },

        // 绑定弹窗
        showBindDialog: function () {
            var self = this;
            var html = '<div class="claude-modal-box">' +
                '<div class="claude-modal-head">' +
                '  <div class="modal-icon">☁️</div>' +
                '  <div class="modal-title">绑定网易云音乐账号</div>' +
                '  <div class="modal-subtitle">接入 SQLite 云端数据库，实现多端听歌记录漫游与个人歌单秒级同步</div>' +
                '</div>' +
                '<div class="claude-modal-body">' +
                '  <label class="modal-input-label">网易云 UID (纯数字用户ID)</label>' +
                '  <div class="modal-input-wrap">' +
                '    <input type="text" id="input-cloud-uid" class="claude-modal-input" placeholder="例如: 32953014" autocomplete="off" />' +
                '    <button type="button" id="btn-do-cloud-bind" class="claude-modal-submit-btn">立即同步</button>' +
                '  </div>' +
                '  <div class="modal-tip-box">' +
                '    <div class="tip-title">💡 如何获取您的网易云 UID：</div>' +
                '    <div class="tip-desc">1. 打开网易云音乐网页版或客户端，进入【个人主页】；<br>' +
                '    2. 查看网页地址栏，<code>/user/home?id=</code> 后面的纯数字即为您的 UID。</div>' +
                '  </div>' +
                '</div>' +
                '</div>';

            var idx = layer.open({
                type: 1,
                title: false,
                closeBtn: 1,
                shadeClose: true,
                area: $(window).width() < 560 ? ['92%', 'auto'] : ['480px', 'auto'],
                skin: 'claude-layer-skin',
                content: html
            });

            $('#btn-do-cloud-bind').on('click', function () {
                var uid = $.trim($('#input-cloud-uid').val());
                if (!uid || isNaN(uid)) {
                    layer.msg('请输入正确的纯数字网易云 UID', { anim: 6 });
                    return;
                }
                var loadIdx = layer.load(2);
                // 调用后台同步用户资料
                $.ajax({
                    type: 'POST',
                    url: self.apiEndpoint,
                    data: {
                        types: 'sync_account',
                        uid: uid
                    },
                    dataType: 'json',
                    success: function (res) {
                        layer.close(loadIdx);
                        layer.close(idx);
                        self.saveLocalUser({
                            uid: uid,
                            nickname: (res && res.user && res.user.nickname) ? res.user.nickname : ('网易云用户 ' + uid),
                            avatar: (res && res.user && res.user.avatar) ? res.user.avatar : 'images/player_cover.png'
                        });
                        layer.msg('✨ 云端绑定成功！正在加载个性歌单...', { icon: 1 });

                        // 触发 MKOnline 歌单加载
                        if (typeof ajaxUserList === 'function') {
                            ajaxUserList(uid);
                        } else if (typeof clearUserlist === 'function') {
                            clearUserlist();
                        }
                    },
                    error: function () {
                        layer.close(loadIdx);
                        layer.close(idx);
                        self.saveLocalUser({ uid: uid });
                        layer.msg('已绑定本地，正在同步歌单...', { icon: 1 });
                        if (typeof ajaxUserList === 'function') ajaxUserList(uid);
                    }
                });
            });
        },

        // 已登录管理卡片
        showAccountCard: function () {
            var self = this;
            var html = '<div class="claude-modal-box">' +
                '<div class="claude-modal-head">' +
                '  <div class="user-card-avatar-wrap">' +
                '    <img src="' + (self.user.avatar || 'images/player_cover.png') + '" class="user-card-avatar" alt="头像" />' +
                '    <span class="user-card-status-dot" title="云端数据库连通正常"></span>' +
                '  </div>' +
                '  <div class="modal-title">' + (self.user.nickname || '网易云音乐用户') + '</div>' +
                '  <div class="modal-subtitle">网易云 UID: ' + self.user.uid + ' · SQLite 云端数据库就绪</div>' +
                '</div>' +
                '<div class="claude-modal-body">' +
                '  <div class="user-stats-grid">' +
                '    <div class="user-stat-card">' +
                '      <div class="stat-num" id="stat-fav-count">' + (self.stats.favorites || '-') + '</div>' +
                '      <div class="stat-label">红心收藏</div>' +
                '    </div>' +
                '    <div class="user-stat-card">' +
                '      <div class="stat-num" id="stat-hist-count">' + (self.stats.history || '-') + '</div>' +
                '      <div class="stat-label">听歌历史</div>' +
                '    </div>' +
                '    <div class="user-stat-card">' +
                '      <div class="stat-num" id="stat-sheet-count">' + (typeof musicList !== 'undefined' ? Math.max(0, musicList.length - 3) : '-') + '</div>' +
                '      <div class="stat-label">同步歌单</div>' +
                '    </div>' +
                '  </div>' +
                '  <div class="user-action-buttons">' +
                '    <button type="button" id="btn-refresh-user-sheet" class="claude-card-btn primary">🔄 立即刷新歌单</button>' +
                '    <button type="button" id="btn-switch-user-bind" class="claude-card-btn secondary">✏️ 切换网易云账号</button>' +
                '    <button type="button" id="btn-logout-user-bind" class="claude-card-btn danger">🚪 解除云端绑定</button>' +
                '  </div>' +
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

            // 立即刷新歌单
            $('#btn-refresh-user-sheet').on('click', function () {
                layer.close(idx);
                if (typeof playerSavedata === 'function') playerSavedata('ulist', '');
                layer.msg('🔄 正在重新同步网易云歌单...');
                if (typeof clearUserlist === 'function') clearUserlist();
            });

            // 切换账号
            $('#btn-switch-user-bind').on('click', function () {
                layer.close(idx);
                self.showBindDialog();
            });

            // 解除绑定
            $('#btn-logout-user-bind').on('click', function () {
                layer.close(idx);
                self.saveLocalUser({ uid: '', nickname: '', avatar: '' });
                if (typeof playerSavedata === 'function') {
                    playerSavedata('uid', '');
                    playerSavedata('ulist', '');
                }
                layer.msg('已解除网易云账号绑定');
                if (typeof clearUserlist === 'function') clearUserlist();
            });
        },

        // 事件监听
        bindEvents: function () {
            var self = this;
            // 点击顶部网易云徽章
            $(document).on('click', '#cloud-account-badge, #netease-user-badge', function () {
                self.openModal();
            });
        }
    };

    window.CloudVault = CloudVault;

    // 页面就绪后启动
    $(function () {
        CloudVault.init();
    });

})(window, jQuery);
