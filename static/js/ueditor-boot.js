/* =============================================================
 * MES系统知识库 —— UEditor 启动适配
 * 页面上带 data-rich 的 textarea 自动升级为 UEditor；
 * 提交同步由 UEditor 内置的 form submit -> sync() 完成（生成
 * name=原textarea名 的隐藏域），这里仅去掉原 textarea 的 name 避免重名。
 * ©Yangzengrong 版权所有
 * ============================================================= */
(function () {
    'use strict';

    /* 与后台浅色主题一致的精简工具栏（去网络图片抓取 / 视频 / 地图等） */
    var TOOLBARS = [[
        'source', '|', 'undo', 'redo', '|',
        'bold', 'italic', 'underline', 'strikethrough', 'forecolor', 'backcolor', '|',
        'paragraph', 'fontfamily', 'fontsize', '|',
        'insertorderedlist', 'insertunorderedlist', '|',
        'justifyleft', 'justifycenter', 'justifyright', 'justifyjustify', '|',
        'link', 'unlink', '|',
        'simpleupload', 'insertimage', 'attachment', '|',
        'inserttable', 'deletetable', 'insertrow', 'deleterow', 'insertcol', 'deletecol', 'mergecells', '|',
        'removeformat', 'formatmatch', 'searchreplace', '|',
        'wordimage', '|',
        'fullscreen'
    ]];

    function initAll() {
        if (typeof window.UE === 'undefined') {
            return;
        }
        var textareas = document.querySelectorAll('textarea[data-rich]');
        textareas.forEach(function (textarea) {
            if (textarea.dataset.ueditorReady) {
                return;
            }
            var serverUrl = textarea.getAttribute('data-server-url') || '';
            if (serverUrl === '') {
                return;
            }
            textarea.dataset.ueditorReady = '1';
            if (!textarea.id) {
                textarea.id = 'ueditor-' + String(Date.now());
            }

            var height = parseInt(textarea.getAttribute('rows') || '18', 10) * 24 || 420;

            var editor = window.UE.getEditor(textarea.id, {
                serverUrl: serverUrl,
                initialFrameWidth: '100%',
                initialFrameHeight: height < 320 ? 320 : height,
                /* 图片/媒体约束：内联注入编辑 iframe，不依赖 iframe.css 的浏览器缓存；
                   与前台 .rich-content img 展示规则一致，大图自动缩放至编辑区宽度 */
                initialStyle: 'img{max-width:100%;height:auto;}embed,video,iframe{max-width:100%;}',
                iframeCssUrl: '/static/ueditor/themes/iframe.css?v=20260928',
                /* 开启自动高度：编辑区随内容增高。这是 UEditor 缩放选框（拖角改尺寸）
                   不溢出整页的前提——选框画在外层容器上、高度=图片高度，固定高度时
                   高图会把选框撑到数千像素盖住整页；自动高度让外层容器同步增高，
                   选框始终落在容器内。代价是长文时编辑器变高（UEditor 默认行为）。 */
                autoHeightEnabled: true,
                autoFloatEnabled: false,
                enableAutoSave: false,
                saveInterval: 0,
                lang: 'zh-cn',
                theme: 'default',
                topOffset: 0,
                toolbars: TOOLBARS,
                /* 粘贴 Word 内容时保留基本样式，过滤脚本等由后端 Sanitizer 兜底 */
                pasteplain: false,
                allowDivTransToP: true,
                /* 恢复图片点选拖角缩放（2026-09-28 为修溢出误关，导致无法自由改尺寸）；
                   溢出问题已由 autoHeightEnabled:true 从根上解决，无需再关 */
                imageScaleEnabled: true,
                /* 恢复图片/iframe 属性小气泡（悬停显示尺寸、对齐、修改入口） */
                imagePopup: true
            });

            editor.ready(function () {
                /* UEditor 已按原 name 生成自己的同步隐藏域，原 textarea 去掉 name 防重名 */
                textarea.removeAttribute('name');
            });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initAll);
    } else {
        initAll();
    }
})();
