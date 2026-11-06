/* =============================================================
   MES系统知识库 —— 后台交互脚本
   无外部依赖，原生实现
   ©Yangzengrong 版权所有
   ============================================================= */

(function () {
    'use strict';

    /* ---------- 危险操作二次确认 + 防重复提交 ---------- */
    var lastSubmitAt = 0;

    document.addEventListener('submit', function (event) {
        var form = event.target;
        if (!form || form.tagName !== 'FORM') {
            return;
        }

        var message = form.getAttribute('data-confirm');
        if (message && !window.confirm(message)) {
            event.preventDefault();
            return;
        }

        // 3 秒内同一表单重复提交直接拦截，避免重复写入
        var now = Date.now();
        if (form.dataset.submitting === '1' && now - lastSubmitAt < 3000) {
            event.preventDefault();
            return;
        }
        form.dataset.submitting = '1';
        lastSubmitAt = now;
    });

    /* ---------- data-request 按钮：JS 提交写操作（用于不能嵌套在其它表单里的按钮） ----------
     * 场景：案例编辑页的主表单包裹整个版面，附件删除 / 版本回滚若是真实嵌套表单，
     * 其隐藏字段（do=delete / rollback）会被并入主表单提交，覆盖 do=save，
     * 造成「未知的操作类型」。改为点击后用 fetch 携带 data-data 里的参数 POST。
     */
    document.addEventListener('click', function (event) {
        var button = event.target.closest ? event.target.closest('[data-request]') : null;
        if (button === null) {
            return;
        }

        var message = button.getAttribute('data-confirm');
        if (message && !window.confirm(message)) {
            return;
        }

        var url = button.getAttribute('data-url') || '';
        if (url === '') {
            return;
        }

        var body = new FormData();
        try {
            var extra = JSON.parse(button.getAttribute('data-data') || '{}');
            Object.keys(extra).forEach(function (key) {
                body.append(key, extra[key]);
            });
        } catch (error) {
            window.alert('按钮参数异常，请刷新页面后重试。');
            return;
        }
        var tokenField = document.querySelector('form input[name="_token"]');
        if (tokenField) {
            body.append('_token', tokenField.value);
        }

        button.disabled = true;
        fetch(url, { method: 'POST', body: body, credentials: 'same-origin' })
            .then(function (response) {
                // 控制器以 redirect 跳转（fetch 自动跟随），直接导航到最终地址即可带上 flash 提示
                window.location.href = response.redirected ? response.url : window.location.href;
            })
            .catch(function () {
                button.disabled = false;
                window.alert('操作失败，请检查网络后重试。');
            });
    });

    /* ---------- 验证码点击刷新 ---------- */
    var captchaImage = document.getElementById('captchaImage');
    if (captchaImage) {
        captchaImage.addEventListener('click', function () {
            var base = captchaImage.getAttribute('src').split('?')[0];
            captchaImage.setAttribute('src', base + '?t=' + Date.now());
        });
    }

    /* ---------- 分类表单：编辑回填 ---------- */
    var categoryForm = document.getElementById('categoryForm');
    if (categoryForm) {
        var categoryIdField = document.getElementById('categoryId');
        var categoryNameField = document.getElementById('categoryName');
        var categoryParentField = document.getElementById('categoryParent');
        var categorySortField = document.getElementById('categorySort');
        var categoryStatusField = document.getElementById('categoryStatus');
        var categoryTitle = document.getElementById('categoryFormTitle');

        var fillCategory = function (button) {
            if (!categoryIdField) {
                return;
            }
            categoryIdField.value = button.getAttribute('data-id') || '0';
            categoryNameField.value = button.getAttribute('data-name') || '';
            categoryParentField.value = button.getAttribute('data-parent') || '0';
            categorySortField.value = button.getAttribute('data-sort') || '0';
            categoryStatusField.value = button.getAttribute('data-status') || '1';
            if (categoryTitle) {
                categoryTitle.textContent = '编辑分类 #' + categoryIdField.value;
            }
            categoryNameField.focus();
            window.scrollTo({ top: 0, behavior: 'smooth' });
        };

        document.querySelectorAll('.category-edit').forEach(function (button) {
            button.addEventListener('click', function () {
                fillCategory(button);
            });
        });

        var categoryReset = document.getElementById('categoryFormReset');
        if (categoryReset) {
            categoryReset.addEventListener('click', function () {
                categoryForm.reset();
                if (categoryIdField) { categoryIdField.value = '0'; }
                if (categoryTitle) { categoryTitle.textContent = '新增分类'; }
            });
        }
    }

    /* ---------- 用户表单：编辑回填 ---------- */
    var userForm = document.getElementById('userForm');
    if (userForm) {
        var userIdField = document.getElementById('userId');
        var userUsernameField = document.getElementById('userUsername');
        var userRealNameField = document.getElementById('userRealName');
        var userPasswordField = document.getElementById('userPassword');
        var userRoleField = document.getElementById('userRole');
        var userStatusField = document.getElementById('userStatus');
        var userRemarkField = document.getElementById('userRemark');
        var userTitle = document.getElementById('userFormTitle');
        var roleHint = document.getElementById('roleHint');

        var roleDescriptions = {
            super_admin: '系统全部权限，可管理账号、审核案例、配置系统',
            op_admin: '可新增/编辑本人案例、管理自有附件，无系统配置与账号管理权限',
            guest: '仅前台检索、预览、下载，无后台操作权限'
        };

        document.querySelectorAll('.user-edit').forEach(function (button) {
            button.addEventListener('click', function () {
                if (!userIdField) {
                    return;
                }
                userIdField.value = button.getAttribute('data-id') || '0';
                userUsernameField.value = button.getAttribute('data-username') || '';
                userRealNameField.value = button.getAttribute('data-real-name') || '';
                userRoleField.value = button.getAttribute('data-role') || 'op_admin';
                userStatusField.value = button.getAttribute('data-status') || '1';
                userRemarkField.value = button.getAttribute('data-remark') || '';
                if (userPasswordField) {
                    userPasswordField.value = '';
                    userPasswordField.required = false;
                }
                if (userTitle) {
                    userTitle.textContent = '编辑账号 #' + userIdField.value;
                }
                if (roleHint) {
                    roleHint.textContent = roleDescriptions[userRoleField.value] || '';
                }
                userUsernameField.focus();
                window.scrollTo({ top: 0, behavior: 'smooth' });
            });
        });

        if (userRoleField && roleHint) {
            userRoleField.addEventListener('change', function () {
                roleHint.textContent = roleDescriptions[userRoleField.value] || '';
            });
        }

        var userReset = document.getElementById('userFormReset');
        if (userReset) {
            userReset.addEventListener('click', function () {
                userForm.reset();
                if (userIdField) { userIdField.value = '0'; }
                if (userTitle) { userTitle.textContent = '新增账号'; }
                if (userPasswordField) { userPasswordField.required = true; }
                if (roleHint) { roleHint.textContent = roleDescriptions[userRoleField.value] || ''; }
            });
        }
    }

    /* ---------- 顶部导航 URL 地图：一键恢复默认四个工序 ---------- */
    document.addEventListener('click', function (event) {
        var restore = event.target.closest ? event.target.closest('.js-restore-url-map') : null;
        if (!restore) { return; }
        event.preventDefault();
        var textarea = document.getElementById('nav_url_map');
        if (!textarea) { return; }
        var fallback = restore.getAttribute('data-default') || '';
        if (textarea.value.trim() !== '' && !window.confirm('将覆盖当前入口配置，确定恢复默认四个工序（绞线 / 绝缘 / 成缆 / 护套）？')) {
            return;
        }
        textarea.value = fallback;
        textarea.focus();
    });

    /* ---------- 点击空白处关闭浮层表单 ---------- */
    document.addEventListener('click', function (event) {
        document.querySelectorAll('details.inline-details[open]').forEach(function (details) {
            if (!details.contains(event.target)) {
                details.removeAttribute('open');
            }
        });
    });
})();
