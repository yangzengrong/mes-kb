/* =============================================================
 * MES系统知识库 —— 轻量富文本编辑器（零外部依赖，适配内网部署）
 * 用法：给 textarea 加 data-rich 属性即可自动升级为富文本编辑器。
 * 能力：段落格式（标题/正文）、加粗/斜体/下划线/删除线、字号、对齐、
 *       有序/无序列表、插入表格、插入图片（上传）、插入文件（上传，
 *       文档/表格/日志以下载链接形式插入）、粘贴/拖拽图片、撤销/重做、清除格式。
 * ©Yangzengrong 版权所有
 * ============================================================= */
(function () {
    'use strict';

    /* execCommand fontSize(1-7) -> 实际字号 */
    var FONT_SIZES = {
        '1': '0.8em', '2': '0.9em', '3': '1em',
        '4': '1.15em', '5': '1.3em', '6': '1.6em', '7': '2em'
    };
    /* 字号下拉选项（值对应 execCommand fontSize） */
    var FONT_OPTIONS = [
        ['3', '标准'],
        ['2', '小号'],
        ['5', '大号'],
        ['7', '特大']
    ];

    function escapeHtml(text) {
        var div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    /* 把 execCommand 产生的 <font size="n"> 统一转换为带样式的 span，便于净化与展示 */
    function normalizeFontTags(root) {
        var fonts = root.querySelectorAll('font[size]');
        fonts.forEach(function (font) {
            var size = font.getAttribute('size');
            var span = document.createElement('span');
            span.style.fontSize = FONT_SIZES[size] || '1em';
            while (font.firstChild) {
                span.appendChild(font.firstChild);
            }
            font.parentNode.replaceChild(span, font);
        });
    }

    function createEditor(textarea) {
        var form = textarea.form;
        var uploadUrl = textarea.getAttribute('data-upload-url') || '';
        var placeholder = textarea.getAttribute('placeholder') || '';

        var wrap = document.createElement('div');
        wrap.className = 'rich-editor';

        /* ---- 工具栏 ---- */
        var toolbar = document.createElement('div');
        toolbar.className = 'rich-toolbar';

        var savedRange = null;

        function saveSelection() {
            var sel = window.getSelection();
            if (sel.rangeCount > 0 && editable.contains(sel.anchorNode)) {
                savedRange = sel.getRangeAt(0).cloneRange();
            }
        }

        function restoreSelection() {
            var sel = window.getSelection();
            sel.removeAllRanges();
            if (savedRange) {
                sel.addRange(savedRange);
            }
        }

        function exec(command, value) {
            editable.focus();
            restoreSelection();
            document.execCommand(command, false, value || null);
            normalizeFontTags(editable);
            syncToTextarea();
        }

        var buttonDefs = [
            [
                { title: '加粗（Ctrl+B）', label: 'B', className: 'bold', cmd: 'bold' },
                { title: '斜体（Ctrl+I）', label: 'I', className: 'italic', cmd: 'italic' },
                { title: '下划线（Ctrl+U）', label: 'U', className: 'underline', cmd: 'underline' },
                { title: '删除线', label: 'S', className: 'strike', cmd: 'strikeThrough' }
            ],
            [
                { title: '有序列表', label: '1. 列表', cmd: 'insertOrderedList' },
                { title: '无序列表', label: '• 列表', cmd: 'insertUnorderedList' }
            ],
            [
                { title: '左对齐', label: '⬅', cmd: 'justifyLeft' },
                { title: '居中', label: '⬌', cmd: 'justifyCenter' },
                { title: '右对齐', label: '➡', cmd: 'justifyRight' }
            ],
            [
                { title: '清除格式', label: '清除格式', cmd: 'removeFormat' },
                { title: '撤销', label: '↶', cmd: 'undo' },
                { title: '重做', label: '↷', cmd: 'redo' }
            ]
        ];

        buttonDefs.forEach(function (group) {
            var groupEl = document.createElement('div');
            groupEl.className = 'rich-btn-group';
            group.forEach(function (def) {
                var btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'rich-btn' + (def.className ? ' rich-btn-' + def.className : '');
                btn.textContent = def.label;
                btn.title = def.title;
                btn.addEventListener('mousedown', function (e) {
                    e.preventDefault(); /* 防止编辑区失焦丢失选区 */
                    saveSelection();
                });
                btn.addEventListener('click', function (e) {
                    e.preventDefault();
                    exec(def.cmd);
                });
                groupEl.appendChild(btn);
            });
            toolbar.appendChild(groupEl);
        });

        /* ---- 段落格式 ---- */
        var blockGroup = document.createElement('div');
        blockGroup.className = 'rich-btn-group';
        var blockSelect = document.createElement('select');
        blockSelect.className = 'rich-font-select';
        blockSelect.title = '段落格式';
        [['p', '正文'], ['h3', '标题'], ['h4', '小标题']].forEach(function (option) {
            var opt = document.createElement('option');
            opt.value = option[0];
            opt.textContent = option[1];
            blockSelect.appendChild(opt);
        });
        blockSelect.addEventListener('mousedown', function () { saveSelection(); });
        blockSelect.addEventListener('change', function () {
            if (blockSelect.value) {
                exec('formatBlock', blockSelect.value);
            }
            blockSelect.value = 'p';
        });
        blockGroup.appendChild(blockSelect);
        toolbar.appendChild(blockGroup);

        /* ---- 字号选择 ---- */
        var fontGroup = document.createElement('div');
        fontGroup.className = 'rich-btn-group';
        var fontSelect = document.createElement('select');
        fontSelect.className = 'rich-font-select';
        fontSelect.title = '文字大小';
        FONT_OPTIONS.forEach(function (option) {
            var opt = document.createElement('option');
            opt.value = option[0];
            opt.textContent = option[1];
            fontSelect.appendChild(opt);
        });
        fontSelect.addEventListener('mousedown', function () { saveSelection(); });
        fontSelect.addEventListener('change', function () {
            if (fontSelect.value) {
                exec('fontSize', fontSelect.value);
            }
            fontSelect.value = '3';
        });
        fontGroup.appendChild(fontSelect);
        toolbar.appendChild(fontGroup);

        /* ---- 插入图片 / 插入文件 ---- */
        var imageGroup = document.createElement('div');
        imageGroup.className = 'rich-btn-group';
        var imageBtn = document.createElement('button');
        imageBtn.type = 'button';
        imageBtn.className = 'rich-btn rich-btn-image';
        imageBtn.textContent = '🖼 插入图片';
        imageBtn.title = '上传并插入图片（支持 png / jpg / gif，也可直接粘贴截图）';
        imageGroup.appendChild(imageBtn);

        var fileInput = document.createElement('input');
        fileInput.type = 'file';
        fileInput.accept = 'image/png,image/jpeg,image/gif';
        fileInput.style.display = 'none';
        imageGroup.appendChild(fileInput);

        var fileBtn = document.createElement('button');
        fileBtn.type = 'button';
        fileBtn.className = 'rich-btn rich-btn-file';
        fileBtn.textContent = '📎 插入文件';
        fileBtn.title = '上传并以下载链接插入文件（doc / docx / pdf / xls / xlsx / txt / log）';
        imageGroup.appendChild(fileBtn);

        var docInput = document.createElement('input');
        docInput.type = 'file';
        docInput.accept = '.doc,.docx,.pdf,.xls,.xlsx,.txt,.log';
        docInput.style.display = 'none';
        imageGroup.appendChild(docInput);
        toolbar.appendChild(imageGroup);

        /* ---- 插入表格 ---- */
        var tableGroup = document.createElement('div');
        tableGroup.className = 'rich-btn-group';
        var tableBtn = document.createElement('button');
        tableBtn.type = 'button';
        tableBtn.className = 'rich-btn rich-btn-table';
        tableBtn.textContent = '▦ 插入表格';
        tableBtn.title = '插入带表头的表格（插入后可直接在单元格中编辑）';
        tableGroup.appendChild(tableBtn);
        toolbar.appendChild(tableGroup);

        function insertHtml(html) {
            editable.focus();
            restoreSelection();
            document.execCommand('insertHTML', false, html);
            normalizeFontTags(editable);
            syncToTextarea();
        }

        function uploadAsset(file, kind) {
            if (!uploadUrl) {
                window.alert('编辑器未配置上传接口。');
                return;
            }
            var busyBtn = kind === 'image' ? imageBtn : fileBtn;
            var idleText = kind === 'image' ? '🖼 插入图片' : '📎 插入文件';
            var tokenInput = form ? form.querySelector('[name="_token"]') : null;
            var data = new FormData();
            data.append('file', file);
            if (tokenInput) {
                data.append('_token', tokenInput.value);
            }

            busyBtn.disabled = true;
            busyBtn.textContent = '上传中…';

            fetch(uploadUrl, { method: 'POST', body: data, credentials: 'same-origin' })
                .then(function (response) { return response.json(); })
                .then(function (result) {
                    if (result && result.ok && result.url) {
                        if (result.kind === 'image') {
                            insertHtml('<img src="' + escapeHtml(result.url) + '" alt="">');
                        } else {
                            var name = result.name || '附件文件';
                            insertHtml('<a href="' + escapeHtml(result.url) + '" target="_blank" title="点击下载">📎 '
                                + escapeHtml(name) + '</a>&nbsp;');
                        }
                    } else {
                        window.alert((result && result.message) || '上传失败，请重试。');
                    }
                })
                .catch(function () {
                    window.alert('上传失败，请检查网络后重试。');
                })
                .finally(function () {
                    busyBtn.disabled = false;
                    busyBtn.textContent = idleText;
                    fileInput.value = '';
                    docInput.value = '';
                });
        }

        imageBtn.addEventListener('mousedown', function (e) {
            e.preventDefault();
            saveSelection();
        });
        imageBtn.addEventListener('click', function (e) {
            e.preventDefault();
            fileInput.click();
        });
        fileInput.addEventListener('change', function () {
            if (fileInput.files && fileInput.files.length > 0) {
                uploadAsset(fileInput.files[0], 'image');
            }
        });

        fileBtn.addEventListener('mousedown', function (e) {
            e.preventDefault();
            saveSelection();
        });
        fileBtn.addEventListener('click', function (e) {
            e.preventDefault();
            docInput.click();
        });
        docInput.addEventListener('change', function () {
            if (docInput.files && docInput.files.length > 0) {
                uploadAsset(docInput.files[0], 'file');
            }
        });

        tableBtn.addEventListener('mousedown', function (e) {
            e.preventDefault();
            saveSelection();
        });
        tableBtn.addEventListener('click', function (e) {
            e.preventDefault();
            var input = window.prompt('输入表格规格：行数×列数（含表头行），例如 4x3', '4x3');
            if (input === null) {
                return;
            }
            var match = /^(\d{1,2})\s*[x×*]\s*(\d{1,2})$/.exec(input.trim());
            if (match === null) {
                window.alert('格式不正确，请按「行数x列数」输入，例如 4x3。');
                return;
            }
            var rows = Math.min(Math.max(parseInt(match[1], 10), 2), 20);
            var cols = Math.min(Math.max(parseInt(match[2], 10), 1), 10);
            var html = '<table><thead><tr>';
            for (var c = 0; c < cols; c++) {
                html += '<th>表头' + (c + 1) + '</th>';
            }
            html += '</tr></thead><tbody>';
            for (var r = 1; r < rows; r++) {
                html += '<tr>';
                for (var c2 = 0; c2 < cols; c2++) {
                    html += '<td><br></td>';
                }
                html += '</tr>';
            }
            html += '</tbody></table><p><br></p>';
            insertHtml(html);
        });

        /* ---- 编辑区 ---- */
        var editable = document.createElement('div');
        editable.className = 'rich-area';
        editable.contentEditable = 'true';
        editable.setAttribute('data-placeholder', placeholder);
        editable.spellcheck = false;

        /* 初始内容：旧纯文本按换行转 <br>，富文本直接载入 */
        var initial = textarea.value;
        if (initial && !/<\s*[a-z][^>]*>/i.test(initial)) {
            initial = initial.split(/\r?\n/).map(function (line) {
                return line === '' ? '<br>' : escapeHtml(line);
            }).join('<br>');
        }
        editable.innerHTML = initial || '';

        /* 粘贴：优先处理截图粘贴，其余按纯文本/基础 HTML 落入 */
        editable.addEventListener('paste', function (event) {
            var clipboard = event.clipboardData;
            if (!clipboard) { return; }
            var items = clipboard.items || [];
            for (var i = 0; i < items.length; i++) {
                if (items[i].type && items[i].type.indexOf('image/') === 0) {
                    event.preventDefault();
                    saveSelection();
                    uploadAsset(items[i].getAsFile(), 'image');
                    return;
                }
            }
        });

        /* 拖拽图片直接上传 */
        editable.addEventListener('drop', function (event) {
            var files = event.dataTransfer && event.dataTransfer.files;
            if (files && files.length > 0 && files[0].type.indexOf('image/') === 0) {
                event.preventDefault();
                saveSelection();
                uploadAsset(files[0], 'image');
            }
        });

        editable.addEventListener('blur', syncToTextarea);

        /* ---- 组装并替换 textarea ---- */
        wrap.appendChild(toolbar);
        wrap.appendChild(editable);
        textarea.parentNode.insertBefore(wrap, textarea);
        textarea.style.display = 'none';

        function syncToTextarea() {
            var html = editable.innerHTML;
            /* 空内容判定：没有任何文本、图片时置空 */
            var textOnly = (editable.textContent || '').replace(/\u00a0/g, ' ').trim();
            var hasImage = editable.querySelector('img') !== null;
            textarea.value = (textOnly === '' && !hasImage) ? '' : html;
        }

        if (form) {
            form.addEventListener('submit', syncToTextarea);
        }
    }

    function initAll() {
        var textareas = document.querySelectorAll('textarea[data-rich]');
        textareas.forEach(function (textarea) {
            if (!textarea.dataset.richReady) {
                textarea.dataset.richReady = '1';
                createEditor(textarea);
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initAll);
    } else {
        initAll();
    }
})();
