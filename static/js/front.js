/* =============================================================
   MES系统知识库 —— 前台交互脚本
   实现检索联想提示（需求 4.8），无外部依赖
   ©Yangzengrong 版权所有
   ============================================================= */

(function () {
    'use strict';

    var config = window.MES_KB || {};
    var input = document.getElementById('searchInput');
    var box = document.getElementById('suggestBox');

    if (!input || !box || !config.suggestUrl) {
        return;
    }

    var timer = null;
    var lastKeyword = '';

    function hide() {
        box.classList.remove('show');
        box.innerHTML = '';
    }

    function render(items) {
        if (!items || items.length === 0) {
            hide();
            return;
        }
        box.innerHTML = '';
        items.forEach(function (item) {
            var link = document.createElement('a');
            link.href = (config.searchUrl || '') + '?keyword=' + encodeURIComponent(item);
            link.textContent = item;
            box.appendChild(link);
        });
        box.classList.add('show');
    }

    function request(keyword) {
        if (keyword === lastKeyword) {
            return;
        }
        lastKeyword = keyword;

        window.fetch(config.suggestUrl + '?keyword=' + encodeURIComponent(keyword), {
            credentials: 'same-origin'
        })
            .then(function (response) {
                return response.json();
            })
            .then(function (payload) {
                render(payload && payload.data ? payload.data : []);
            })
            .catch(hide);
    }

    input.addEventListener('input', function () {
        var keyword = input.value.trim();
        if (timer) {
            window.clearTimeout(timer);
        }
        if (keyword === '') {
            lastKeyword = '';
            hide();
            return;
        }
        timer = window.setTimeout(function () {
            request(keyword);
        }, 220);
    });

    input.addEventListener('blur', function () {
        window.setTimeout(hide, 180);
    });

    document.addEventListener('click', function (event) {
        if (event.target !== input && !box.contains(event.target)) {
            hide();
        }
    });
})();

/* =============================================================
   复制文章链接（纯 URL，发给内网同事直接打开）
   多级兼容：
   1) HTTPS/localhost：navigator.clipboard
   2) 传统浏览器：execCommand('copy')（contentEditable + Range，兼容 360/IE 内核）
   3) IE 专有：window.clipboardData
   4) 全部失败：弹出小窗，链接自动全选，Ctrl+C 100% 可用
   ============================================================= */
(function () {
    'use strict';

    /* 传统方案：hidden 区域 + Range 选区，覆盖不支持 textarea.select() 的内核 */
    function legacyCopy(text) {
        var done = false;
        var area = document.createElement('textarea');
        area.value = text;
        area.setAttribute('readonly', '');
        area.style.position = 'fixed';
        area.style.top = '0';
        area.style.left = '0';
        area.style.opacity = '0';
        document.body.appendChild(area);
        area.focus();
        try {
            area.select();
            area.setSelectionRange(0, text.length);
        } catch (e) { /* 老内核忽略 */ }
        try {
            done = document.execCommand('copy');
        } catch (e) {
            done = false;
        }
        /* IE 专有剪贴板 */
        if (!done && window.clipboardData && window.clipboardData.setData) {
            try {
                done = window.clipboardData.setData('Text', text);
            } catch (e) {
                done = false;
            }
        }
        document.body.removeChild(area);
        return done;
    }

    function copyText(text) {
        if (navigator.clipboard && window.isSecureContext) {
            return navigator.clipboard.writeText(text).catch(function () {
                if (!legacyCopy(text)) {
                    throw new Error('copy failed');
                }
            });
        }
        return new Promise(function (resolve, reject) {
            if (legacyCopy(text)) {
                resolve();
            } else {
                reject(new Error('copy failed'));
            }
        });
    }

    /* 确认小窗：无论自动复制是否成功都弹出，链接自动全选。
       自动复制已成功 → 直接粘贴即可；若粘贴为空 → 链接已选中，Ctrl+C 再复制一次。
       不依赖任何剪贴板 API，所有浏览器 100% 可用。 */
    function showCopyBox(url, autoCopied) {
        var old = document.getElementById('copyUrlMask');
        if (old && old.parentNode) {
            old.parentNode.removeChild(old);
        }
        var mask = document.createElement('div');
        mask.id = 'copyUrlMask';
        var box = document.createElement('div');
        box.className = 'copy-url-box';
        var title = document.createElement('h3');
        title.textContent = '复制本文链接';
        var input = document.createElement('input');
        input.type = 'text';
        input.readOnly = true;
        input.value = url;
        var tip = document.createElement('p');
        tip.textContent = autoCopied
            ? '标题和链接已复制，可直接粘贴发给同事；若粘贴为空，内容已选中，按 Ctrl+C 再复制一次。'
            : '内容已自动选中，按 Ctrl+C 复制（也可在框内右键→全选→复制）。';
        var close = document.createElement('button');
        close.type = 'button';
        close.className = 'copy-url-close';
        close.textContent = autoCopied ? '完成' : '关闭';
        var selectAll = function () {
            input.focus();
            if (input.select) {
                input.select();
            }
            try {
                input.setSelectionRange(0, input.value.length);
            } catch (e) { /* 老内核忽略 */ }
        };
        box.appendChild(title);
        box.appendChild(input);
        box.appendChild(tip);
        box.appendChild(close);
        mask.appendChild(box);
        document.body.appendChild(mask);
        selectAll();
        window.setTimeout(selectAll, 50);
        if (!autoCopied) {
            /* 自动复制失败时，弹窗内选中状态下再做一次尝试 */
            window.setTimeout(function () {
                try {
                    document.execCommand('copy');
                } catch (e) { /* 失败则用户手动 Ctrl+C */ }
            }, 120);
        }

        close.addEventListener('click', function () {
            if (mask.parentNode) {
                mask.parentNode.removeChild(mask);
            }
        });
        mask.addEventListener('click', function (event) {
            if (event.target === mask && mask.parentNode) {
                mask.parentNode.removeChild(mask);
            }
        });
        input.addEventListener('click', selectAll);
    }

    document.addEventListener('click', function (event) {
        var button = event.target.closest('.js-copy-link');
        if (button === null) {
            return;
        }
        event.preventDefault();
        var path = button.getAttribute('data-path') || '/';
        var url = path.indexOf('http://') === 0 || path.indexOf('https://') === 0
            ? path
            : window.location.origin + path;
        /* 格式：标题 + 空格 + 链接（对方一看就知道是什么故障） */
        var title = (button.getAttribute('data-title') || '').replace(/\s+/g, ' ');
        var text = title !== '' ? title + ' ' + url : url;
        copyText(text).then(function () {
            showCopyBox(text, true);
        }).catch(function () {
            showCopyBox(text, false);
        });
    });

    /* 弹层内那个「复制链接」按钮也走同一套逻辑，
       避免用户在弹层里还要关掉弹层再去点工具条。 */
    document.addEventListener('click', function (event) {
        var button = event.target.closest('.js-copy-link-inline');
        if (button === null) {
            return;
        }
        event.preventDefault();
        var path = button.getAttribute('data-path') || '/';
        var url = path.indexOf('http') === 0 ? path : window.location.origin + path;
        var title = (button.getAttribute('data-title') || '').replace(/\s+/g, ' ');
        var text = title !== '' ? title + ' ' + url : url;
        copyText(text).then(function () {
            showCopyBox(text, true);
        }).catch(function () {
            showCopyBox(text, false);
        });
    });
})();


/* =============================================================
   分享弹层：生成卡片图，长按转发到微信
   【为什么图片是「点开时才加载」】
   卡片图约 70KB，每个访问者都预加载是浪费 —— 绝大多数人不会点分享。
   首次打开后把 URL 记在 data-loaded 上，第二次打开直接显示不重复请求。
   ============================================================= */
(function () {
    var panel = document.getElementById('sharePanel');
    var opener = document.querySelector('.js-share-open');
    if (panel === null || opener === null) {
        return;
    }

    var img    = document.getElementById('shareCardImg');
    var tip    = document.getElementById('shareLoading');
    var lastFocus = null;

    function loadCard() {
        var url = opener.getAttribute('data-share-card');
        if (!url) {
            return;
        }
        if (img.getAttribute('data-loaded') === url) {
            return;
        }
        tip.hidden = false;
        tip.textContent = '正在生成卡片…';
        img.hidden = true;

        var probe = new Image();
        probe.onload = function () {
            img.src = url;
            img.hidden = false;
            img.setAttribute('data-loaded', url);
            tip.hidden = true;
        };
        probe.onerror = function () {
            tip.hidden = false;
            tip.textContent = '卡片生成失败，请改用下方的「复制链接」。';
        };
        probe.src = url;
    }

    function open() {
        lastFocus = document.activeElement;
        panel.hidden = false;
        document.body.classList.add('has-share-open');
        loadCard();
        var closeBtn = panel.querySelector('.share-panel-close');
        if (closeBtn) { closeBtn.focus(); }
    }

    function close() {
        panel.hidden = true;
        document.body.classList.remove('has-share-open');
        if (lastFocus && lastFocus.focus) { lastFocus.focus(); }
    }

    opener.addEventListener('click', function (event) {
        event.preventDefault();
        open();
    });

    panel.addEventListener('click', function (event) {
        if (event.target.closest('[data-share-close]')) {
            close();
        }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && !panel.hidden) {
            close();
        }
    });
})();

/* =============================================================
   导航溢出收纳：放不下的工序自动收进「更多▾」，宽度够时自动放回
   ============================================================= */
(function () {
    var navInner = document.getElementById('navInner');
    var more = document.getElementById('navMore');
    if (!navInner || !more) return;

    var panel = document.getElementById('navMorePanel');
    var btn = document.getElementById('navMoreBtn');
    /* 初始 DOM 顺序即期望顺序（只取直接子级，排除「更多」按钮自身） */
    var items = Array.prototype.slice.call(navInner.querySelectorAll(':scope > .nav-cat'));
    var GAP = 6;

    /* 当前导航内各项（含「更多」按钮）累计宽度是否放得下。
       2026-10-22 全局导航改版：吸顶条里还有 LOGO 与搜索框，
       可用宽度要先扣掉它们，否则会高估容量导致菜单被挤出条外。 */
    function fits() {
        var limit = navInner.clientWidth;
        var used = 0;
        Array.prototype.forEach.call(navInner.children, function (el) {
            if (el === more || el.classList.contains('nav-cat')) {
                return;
            }
            used += el.offsetWidth + GAP;
        });
        var total = 0;
        var first = true;
        navInner.querySelectorAll(':scope > .nav-cat').forEach(function (el) {
            total += el.offsetWidth + (first ? 0 : GAP);
            first = false;
        });
        if (!more.hidden) {
            total += more.offsetWidth + GAP;
        }
        return total <= limit - used;
    }

    function pack() {
        /* 先全部放回，再从尾部逐个收进下拉，直到放得下 */
        items.forEach(function (el) {
            if (el.parentNode !== navInner) {
                navInner.insertBefore(el, more);
            }
        });
        panel.innerHTML = '';
        more.hidden = true;
        more.classList.remove('open');

        /* 窄屏换行模式（≤860px，见 front.css ③）：菜单自然换行，不收纳 */
        if (window.getComputedStyle(navInner).flexWrap === 'wrap') {
            return;
        }

        var guard = items.length + 2;
        while (!fits() && guard-- > 0) {
            var movable = items.filter(function (el) {
                return el.parentNode === navInner && !el.classList.contains('active');
            });
            if (movable.length === 0) break;
            var victim = movable[movable.length - 1];
            var link = victim.cloneNode(true);
            panel.insertBefore(link, panel.firstChild);
            navInner.removeChild(victim);
            more.hidden = false;
        }
    }

    btn.addEventListener('click', function (event) {
        event.stopPropagation();
        more.classList.toggle('open');
    });
    document.addEventListener('click', function (event) {
        if (!more.contains(event.target)) {
            more.classList.remove('open');
        }
    });

    var resizeTimer = null;
    window.addEventListener('resize', function () {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(pack, 120);
    });
    pack();
})();
