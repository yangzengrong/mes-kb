<?php
/**
 * 案例新增 / 编辑表单（需求 4.3 案例字段标准化 + 4.4 附件上传）。
 *
 * @var array<string,mixed>|null $case
 * @var array<string,mixed> $old
 * @var array<string,string> $errors
 * @var array<int,string> $categories
 * @var array<int,string> $positions
 * @var array<int,array<string,mixed>> $attachments
 * @var array<int,array<string,mixed>> $versions
 * @var int $maxBytes
 * @var string $allowedExt
 */
$id       = (int) ($case['id'] ?? 0);
$isEdit   = $id > 0;
$isSuper  = auth()->isSuperAdmin();

/** 取值优先级：回填值 > 数据库值 > 空 */
$val = static function (string $field, string $default = '') use ($old, $case) {
    if (array_key_exists($field, $old)) {
        return (string) $old[$field];
    }
    if ($case !== null && array_key_exists($field, $case)) {
        return (string) ($case[$field] ?? '');
    }
    return $default;
};
$err = static function (string $field) use ($errors): string {
    return isset($errors[$field]) ? (string) $errors[$field] : '';
};
$dateOnly = static function (?string $datetime): string {
    return $datetime !== null && $datetime !== '' ? substr($datetime, 0, 10) : '';
};
?>

<?php if ($errors !== []): ?>
    <div class="alert alert-error">
        <strong>表单校验未通过：</strong>
        <ul class="alert-list">
            <?php foreach ($errors as $message): ?>
                <li><?= e($message) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<form method="post" action="<?= e(admin_url('case_action.php')) ?>" enctype="multipart/form-data">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="save">
    <input type="hidden" name="id" value="<?= $id ?>">

    <div class="form-layout">
        <div class="form-main">
            <div class="card">
                <h2 class="card-title">故障基本信息</h2>

                <div class="field">
                    <label for="title"><span id="labelTitle">故障标题</span> <span class="req">*</span></label>
                    <input type="text" id="title" name="title" maxlength="200" required
                           placeholder="请输入标题"
                           value="<?= e($val('title')) ?>" class="<?= $err('title') !== '' ? 'has-error' : '' ?>">
                    <?php if ($err('title') !== ''): ?><span class="field-error"><?= e($err('title')) ?></span><?php endif; ?>
                </div>

                <div class="field-row">
                    <div class="field" id="fieldProcess">
                        <label for="process_id">所属工序 <span class="req" data-process-only>*</span></label>
                        <select id="process_id" name="process_id"
                                class="<?= $err('process_id') !== '' ? 'has-error' : '' ?>">
                            <option value="">请选择工序</option>
                            <?php foreach ($processes as $pid => $pname): ?>
                                <option value="<?= (int) $pid ?>"
                                    <?= (int) $val('process_id') === (int) $pid ? ' selected' : '' ?>>
                                    <?= e($pname) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if ($err('process_id') !== ''): ?>
                            <span class="field-error"><?= e($err('process_id')) ?></span>
                        <?php endif; ?>
                        <span class="field-hint" id="processHint">一级分类：前台按工序检索，仅「故障案例」需要选择。</span>
                    </div>
                    <?php /* 【为什么文档类型不再是一个下拉框】
                         原设计让用户在表单里手选「故障案例 / 系统操作手册 / 版本更新日志」。
                         这个选择不该由用户做 —— 它完全由「从哪个菜单进来」决定：
                           内容管理 → 故障案例      → case
                           内容管理 → 系统操作手册  → manual
                           内容管理 → 版本更新日志  → changelog
                         手选带来三个具体问题（均为实测/亲历）：
                           ① 选错类型后，下面所有字段（工序、故障类型、岗位）会跟着变，
                              已填的内容要么被清空、要么残留成看不见的旧值；
                           ② 用户要读三遍表单才能判断该选哪个；
                           ③ 存成「案例」后在手册列表里找不到，纯属白填。
                         现在改为：类型由 URL 带入（CaseController:99 已支持 ?doc_type=manual），
                         页面顶部用只读标识条明示「你正在写什么」，
                         并给出切换到其他类型的入口 —— 想换类型仍可换，但不再是默认动作。 */ ?>
                    <div class="field">
                        <label>文档类型</label>
                        <input type="hidden" id="doc_type" name="doc_type"
                               value="<?= e($val('doc_type', (string) ($initDocType ?? 'case'))) ?>">
                        <div class="doctype-lock" id="doctypeLock">
                            <span class="doctype-lock-icon" aria-hidden="true"></span>
                            <span class="doctype-lock-text">
                                <strong id="docTypeName"><?= e(doc_type_map()[$val('doc_type', (string) ($initDocType ?? 'case'))] ?? '故障案例') ?></strong>
                                <em id="docTypeDesc">由左侧菜单决定，无需选择</em>
                            </span>
                        </div>
                        <span class="field-hint" id="docTypeHint">
                            要改成其他类型？<a href="<?= e(admin_url('cases.php')) ?>" class="btn-link">返回列表</a>后从对应菜单进入。
                        </span>
                    </div>
                </div>

                <div class="field-row">
                    <div class="field" id="fieldPosition" data-manual-only>
                        <label for="position_id">匹配岗位</label>
                        <select id="position_id" name="position_id"
                                class="<?= $err('position_id') !== '' ? 'has-error' : '' ?>">
                            <option value="">通用（不限岗位）</option>
                            <?php foreach ($positions as $positionId => $positionName): ?>
                                <option value="<?= (int) $positionId ?>"
                                    <?= (int) $val('position_id') === (int) $positionId ? ' selected' : '' ?>>
                                    <?= e($positionName) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if ($err('position_id') !== ''): ?>
                            <span class="field-error"><?= e($err('position_id')) ?></span>
                        <?php endif; ?>
                        <span class="field-hint">可选：该手册主要面向的岗位（销售/研发/质量/物控/生产/仓库/技术/设备/实验室）。不选则为全岗位通用手册。</span>
                    </div>
                    <div class="field" id="fieldCategory" data-case-only>
                        <label for="category_id">故障类型 <span class="req" data-fault-only>*</span></label>
                        <select id="category_id" name="category_id"
                                class="<?= $err('category_id') !== '' ? 'has-error' : '' ?>">
                            <option value="">请选择故障类型</option>
                            <?php foreach ($categories as $cid => $name): ?>
                                <option value="<?= (int) $cid ?>"
                                    <?= (int) $val('category_id') === (int) $cid ? ' selected' : '' ?>>
                                    <?= e($name) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <?php if ($err('category_id') !== ''): ?>
                            <span class="field-error"><?= e($err('category_id')) ?></span>
                        <?php endif; ?>
                        <span class="field-hint">二级分类：选「故障案例」时必选；手册 / 版本日志可不选。</span>
                    </div>
                </div>

                <div class="field-row" id="rowFaultMeta">
                    <div class="field">
                        <label for="device">故障设备 / 模块</label>
                           <input type="text" id="device" name="device" maxlength="120"
                               value="<?= e($val('device')) ?>">
                    </div>
                </div>

                <div class="field-row">
                    <div class="field">
                        <?php
                        /**
                         * 关键词输入框只回显纯关键词，不含适用性标签。
                         *
                         * 【为什么】适用性标签存于同一个 tags 列，若这里原样回显，
                         * 提交时会与下方的 scope_tags 合并成重复内容。
                         * 两个输入框各自只负责自己那一类。
                         */
                        $keywordEcho = implode(',', \App\Core\CaseTag::keywords((string) $val('tags')));
                        ?>
                        <label for="tags">标签关键字</label>
                        <input type="text" id="tags" name="tags" maxlength="200"
                               placeholder="多个标签用逗号分隔，如：伺服报警,收料锁定（案例与手册通用，发布后可按标签搜索）"
                               value="<?= e($keywordEcho) ?>">
                        <span class="field-hint">用于后期按关键字快速检索；系统操作手册建议填写对应模块名，如「同步平台,ERP对接,工单」。中英文逗号、顿号、分号均可，系统会自动统一。</span>
                    </div>
                </div>

                <div class="field-row">
                    <div class="field">
                        <label for="scope_tags">适用范围标签</label>
                        <div class="scope-input">
                            <?php
                            /**
                             * 适用范围（断点B）。
                             *
                             * MES 按线体区分系统，一线拿到一条案例后第一个要判断的是
                             * 「这条是不是我这条线的情况」。原先 tags 里没有表达这个维度的位置，
                             * 于是只能写在正文里，或干脆不写 —— 于是看到案例还得自己判断适不适用。
                             *
                             * 约定：形如「产线:护套」「机台:8线」「版本:V3.2」，用半角冒号分隔维度与取值。
                             * 与关键词标签同存于 tags 列（零表结构变更），前台按冒号自动识别并
                             * 独立成组渲染。
                             */
                            $scopeRaw  = (string) ($case['tags'] ?? '');
                            $scopeList = \App\Core\CaseTag::scopes($scopeRaw);
                            ?>
                            <input type="text" id="scope_tags" name="scope_tags" maxlength="120"
                                   placeholder="如：产线:护套,机台:8线,版本:V3.2"
                                   value="<?= e(implode(',', array_column($scopeList, 'raw'))) ?>">
                            <span class="field-hint">
                                告诉一线「这条内容适用于什么环境」，前台会以蓝色块独立展示并支持点击查看同环境案例。
                                格式为「维度:取值」，常用维度：<?= e(implode(' / ', \App\Core\CaseTag::suggestedDimensions())) ?>，也可自造。
                                <br>工序不用填 —— 该维度系统已有，前台会自动展示。
                            </span>
                        </div>
                    </div>
                </div>

                <div class="field-row">
                    <div class="field">
                        <label for="handler"><span id="labelHandler">处理人</span></label>
                        <input type="text" id="handler" name="handler" maxlength="60"
                               value="<?= e($val('handler')) ?>">
                    </div>
                    <div class="field">
                        <label for="handled_at"><span id="labelHandledAt">处理时间</span></label>
                        <input type="date" id="handled_at" name="handled_at"
                               value="<?= e($val('handled_at') !== '' ? substr($val('handled_at'), 0, 10) : '') ?>"
                               class="<?= $err('handled_at') !== '' ? 'has-error' : '' ?>">
                        <?php if ($err('handled_at') !== ''): ?>
                            <span class="field-error"><?= e($err('handled_at')) ?></span>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="field-row" id="rowExpire">
                    <div class="field">
                        <label for="expired_at">案例有效期至</label>
                        <input type="date" id="expired_at" name="expired_at"
                               value="<?= e($dateOnly($val('expired_at'))) ?>"
                               class="<?= $err('expired_at') !== '' ? 'has-error' : '' ?>">
                        <span class="field-hint">留空则永久有效；到期后自动转为「已过期」并从前台隐藏。</span>
                    </div>
                </div>
            </div>

            <div class="card">
                <h2 class="card-title" id="contentCardTitle">故障处置内容</h2>
                <p class="hint" id="contentHint">
                    以下内容支持富文本编辑：可调整文字大小、加粗、使用列表，并可直接
                    <strong>插入 / 粘贴（Ctrl+V）/ 拖入图片</strong>作为故障现场凭证。
                </p>

                <?php
                /**
                 * 排查记录录入提示（断点C）。
                 *
                 * 这个功能靠「小标题约定」识别，管理员不知道就等于没有。
                 * 所以必须在编辑器上方明确告诉他：小标题写成这几个词之一，
                 * 该段就会在前台自动收进折叠区。
                 *
                 * 反过来说，不写这个提示也没坏处 —— 只是排查过程会混在正文里显示，
                 * 不会丢内容。这是「引导而非强制」。
                 */
                ?>
                <p class="hint hint-trace">
                    <strong>可选：</strong>如果这条故障是「一步步排查出来的」，
                    建议单独加一个<strong>小标题</strong>段落：<?php
                    echo e(implode(' / ', \App\Core\CaseSection::suggestedHeadings()));
                    ?>。写了之后，这一段在前台会<strong>默认收起</strong>，
                    别人想看「当时怎么查出来的」时点开即可；
                    不写则照常显示在正文里，内容不会丢。
                </p>

                <div class="field">
                    <label for="phenomenon"><span id="labelPhenomenon">案例内容</span> <span class="req">*</span></label>
                    <textarea id="phenomenon" name="phenomenon" rows="18" data-rich
                              data-server-url="<?= e(admin_url('ueditor_upload.php?_token=' . \App\Core\Csrf::token())) ?>"
                              placeholder="请输入正文内容，可用「标题 / 小标题」分段，支持图片、表格、文件附件"
                              class="<?= $err('phenomenon') !== '' ? 'has-error' : '' ?>"><?= e($val('phenomenon')) ?></textarea>
                    <?php if ($err('phenomenon') !== ''): ?>
                        <span class="field-error"><?= e($err('phenomenon')) ?></span>
                    <?php endif; ?>
                </div>
            </div>

            <div class="card">
                <h2 class="card-title">附件（需求 4.4）</h2>
                <p class="hint">
                    支持格式：<?= e($allowedExt) ?>；单个文件不超过 <?= e(human_size($maxBytes)) ?>。
                    可一次选择多个文件。
                </p>

                <?php if ($isEdit && $attachments !== []): ?>
                    <table class="table table-compact">
                        <thead>
                            <tr><th>文件名</th><th style="width:70px">格式</th><th style="width:90px">大小</th><th style="width:90px">操作</th></tr>
                        </thead>
                        <tbody>
                        <?php foreach ($attachments as $file): ?>
                            <tr>
                                <td><?= e((string) $file['orig_name']) ?></td>
                                <td><span class="tag"><?= e((string) $file['ext']) ?></span></td>
                                <td><?= e(human_size((int) $file['size'])) ?></td>
                                <td>
                                    <!-- 表单嵌套会导致本字段并入主表单、覆盖 do=save，
                                         因此删除 / 回滚类操作改用 JS 提交（admin.js data-request） -->
                                    <button type="button" class="btn btn-danger btn-xs" data-request
                                            data-url="<?= e(admin_url('attachment_action.php')) ?>"
                                            data-confirm="确认删除该附件？"
                                            data-data='{"do":"delete","id":<?= (int) $file['id'] ?>}'>删除</button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php elseif (!$isEdit): ?>
                    <p class="hint">附件将在案例保存后一并上传。</p>
                <?php else: ?>
                    <p class="hint">该案例暂无附件。</p>
                <?php endif; ?>

                <div class="field">
                    <label for="attachments">选择文件上传</label>
                    <input type="file" id="attachments" name="attachments[]" multiple>
                </div>
            </div>
        </div>

        <aside class="form-side">
            <div class="card">
                <h2 class="card-title">发布设置</h2>

                <?php if ($isEdit): ?>
                    <div class="meta-list">
                        <div><span>当前状态</span><strong>
                            <span class="badge badge-<?= e((string) $case['status']) ?>">
                                <?= e(case_status_label((string) $case['status'])) ?>
                            </span>
                        </strong></div>
                        <div><span>版本号</span><strong>v<?= (int) $case['version_no'] ?></strong></div>
                        <div><span>浏览量</span><strong><?= (int) $case['views'] ?></strong></div>
                        <div><span>创建人</span><strong><?= e((string) ($case['author_name'] ?? '—')) ?></strong></div>
                        <div><span>创建时间</span><strong><?= e((string) $case['created_at']) ?></strong></div>
                        <div><span>审核意见</span><strong><?= e((string) ($case['review_note'] ?? '—')) ?></strong></div>
                    </div>
                <?php endif; ?>

                <div class="field">
                    <label for="change_note">本次修改说明</label>
                    <input type="text" id="change_note" name="change_note" maxlength="255"
                           placeholder="例如：补充了伺服驱动器参数复位步骤"
                           value="<?= e($val('change_note')) ?>">
                    <span class="field-hint">会记入版本历史，便于追溯内容变化。</span>
                </div>

                <div class="field">
                    <label class="checkbox">
                        <input type="checkbox" name="is_top" value="1"
                            <?= (int) $val('is_top', '0') === 1 ? ' checked' : '' ?>>
                        <span>在首页置顶展示</span>
                    </label>
                </div>

                <div class="form-actions">
                    <button type="submit" name="submit" value="pending" class="btn btn-ghost btn-block">
                        <?= $isEdit ? '保存为待审核' : '提交（待审核）' ?>
                    </button>
                    <?php if ($isSuper): ?>
                        <button type="submit" name="submit" value="published" class="btn btn-primary btn-block">
                            保存并发布
                        </button>
                    <?php endif; ?>
                    <?php $formDocType = $val('doc_type', (string) ($initDocType ?? 'case')); ?>
                    <a class="btn btn-ghost btn-block" href="<?= e(admin_url('cases.php' . ($formDocType !== 'case' ? '?doc_type=' . $formDocType : ''))) ?>">返回列表</a>
                </div>
            </div>

            <?php if ($isEdit && $versions !== []): ?>
            <div class="card">
                <h2 class="card-title">版本历史（<?= count($versions) ?> 条）</h2>
                <ul class="version-list">
                    <?php foreach ($versions as $version): ?>
                        <li>
                            <div class="version-head">
                                <strong>v<?= (int) $version['version_no'] ?></strong>
                                <span class="muted small"><?= e((string) $version['created_at']) ?></span>
                            </div>
                            <div class="small muted">
                                <?= e((string) $version['editor_name']) ?> ·
                                <?= e((string) $version['change_note']) ?>
                            </div>
                            <?php if ($isSuper): ?>
                                <button type="button" class="btn btn-ghost btn-xs" data-request
                                        data-url="<?= e(admin_url('case_action.php')) ?>"
                                        data-confirm="回滚会用该版本内容覆盖当前案例，确认继续？"
                                        data-data='{"do":"rollback","case_id":<?= $id ?>,"version_id":<?= (int) $version['id'] ?>}'>回滚到此版本</button>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>
        </aside>
    </div>
</form>

<script>
/* 文档类型自适应表单。
 *
 * 【2026-10-17 变更：文档类型不再由用户选择】
 * 原本这里是「下拉框 + change 事件」，用户手选文档类型，代价是：
 *   ① 选错类型 → 工序/故障类型/岗位跟着变，已填内容被清空或残留成看不见的旧值
 *   ② 要读三遍表单才能判断该选哪个
 *   ③ 存成「案例」后在手册列表里找不到，纯属白填
 * 现在文档类型由 URL 带入（CaseController:99 已支持 ?doc_type=manual/changelog），
 * 页面只做「按类型适配字段 + 改写措辞」，用户不需要做任何选择。
 *
 * 【因此不再清空字段】原代码在切类型时执行 process.value = '' 以防残留值提交，
 * 现在类型不会变，这行不仅没必要，而且正是「切换已填内容就消失」的元凶 ——
 * 用户在故障案例里选好工序，切一下类型就没了。
 */
(function () {
    var docType    = document.getElementById('doc_type');
    var title      = document.getElementById('title');
    var category   = document.getElementById('category_id');
    var position   = document.getElementById('position_id');
    var process    = document.getElementById('process_id');
    var fieldProcess  = document.getElementById('fieldProcess');
    var fieldCategory = document.getElementById('fieldCategory');
    var fieldPosition = document.getElementById('fieldPosition');
    var rowFaultMeta  = document.getElementById('rowFaultMeta');
    var rowExpire     = document.getElementById('rowExpire');
    if (docType === null) {
        return;
    }

    var meta = {
        'case': {
            card: '案例内容',
            hint: '以下内容支持富文本编辑：可调整文字大小、加粗、使用列表与表格，并可直接插入 / 粘贴（Ctrl+V）/ 拖入图片作为故障现场凭证。',
            titleLabel: '故障标题',
            handler: '处理人', handledAt: '处理时间',
            contentLabel: '案例内容',
            docName: '故障案例',
            desc: '来自「内容管理 → 故障案例」',
            contentPlaceholder: '请输入正文内容：故障现象、原因分析、处理步骤、预防措施等，可用「标题 / 小标题」分段，支持图片、表格、文件附件'
        },
        'manual': {
            card: '系统操作手册',
            hint: '编写 MES 系统操作手册：支持富文本与配图，建议每个操作步骤配一张系统界面截图；可指定匹配岗位，也可作为全岗位通用的系统手册。',
            titleLabel: '手册标题',
            handler: '编制人', handledAt: '编制日期',
            contentLabel: '操作手册内容',
            docName: '系统操作手册',
            desc: '来自「内容管理 → 系统操作手册」',
            contentPlaceholder: '第一步、第二步……逐步写清在 MES 系统中的操作路径与点击顺序；可先写前提条件，再写关键操作与注意事项，可配界面截图、表格、文件'
        },
        'changelog': {
            card: '版本更新说明',
            hint: '记录 MES 系统每次版本更新：更新内容、更新时间与更新后的变化，支持富文本与配图。',
            titleLabel: '更新标题',
            handler: '记录人', handledAt: '更新时间',
            contentLabel: '更新内容',
            docName: '版本更新日志',
            desc: '来自「内容管理 → 版本更新日志」',
            contentPlaceholder: '本次更新了哪些功能？修复了哪些问题？更新后使用方式有何变化？涉及哪些岗位 / 工序……'
        }
    };

    function setText(id, text) {
        var el = document.getElementById(id);
        if (el !== null) {
            el.textContent = text;
        }
    }

    function sync() {
        /* 类型是固定值（来自 URL），不是用户可改的输入 */
        var type   = docType.value;
        var isCase = type === 'case';
        var isManual = type === 'manual';
        var isChangelog = type === 'changelog';
        var conf = meta[type] || meta['case'];

        /* 类型标识条 */
        setText('docTypeName', conf.docName);
        setText('docTypeDesc', conf.desc);

        /* 标题 */
        setText('labelTitle', conf.titleLabel);
        if (title !== null) {
            title.placeholder = '请输入' + conf.titleLabel.replace(/标题$/, '') + '标题';
        }

        /* 基本信息字段显隐 */
        if (fieldProcess !== null) {
            fieldProcess.style.display = isCase ? '' : 'none';
        }
        if (fieldCategory !== null) {
            fieldCategory.style.display = isCase ? '' : 'none';
        }
        if (fieldPosition !== null) {
            fieldPosition.style.display = isManual ? '' : 'none';
        }
        if (rowFaultMeta !== null) {
            rowFaultMeta.style.display = isCase ? '' : 'none';
        }
        if (rowExpire !== null) {
            rowExpire.style.display = isChangelog ? 'none' : '';
        }

        /* 必填切换。
           【关键改动：这里不再清空任何字段值】
           原实现在 !isCase 时执行 process.value = ''，理由是「防止隐藏字段的残留值被提交」。
           但既然类型已固定、用户无法切换，这行就是纯粹的破坏 —— 用户选好的工序会凭空消失。
           残留值问题改由服务端按 doc_type 判定（CaseController:181 已按类型分支校验），
           隐藏字段的残留值不会造成业务影响：非案例类型本就不使用 process_id。
        */
        if (category !== null) {
            category.required = isCase;
        }
        /* 匹配岗位为可选项：手册与非手册均不做必填校验（不选=全岗位通用） */
        if (position !== null) {
            position.required = false;
        }
        if (process !== null) {
            process.required = isCase;
        }

        /* 必填星号显隐（匹配岗位已改为可选，positionMark 可能不存在） */
        var faultMark = document.querySelector('[data-fault-only]');
        if (faultMark !== null) {
            faultMark.style.display = isCase ? '' : 'none';
        }
        var positionMark = document.querySelector('[data-position-only]');
        if (positionMark !== null) {
            positionMark.style.display = isManual ? '' : 'none';
        }
        var processMark = document.querySelector('[data-process-only]');
        if (processMark !== null) {
            processMark.style.display = isCase ? '' : 'none';
        }

        /* 处理人 / 处理时间措辞 */
        setText('labelHandler', conf.handler);
        setText('labelHandledAt', conf.handledAt);

        /* 内容区适配：卡片标题 / 提示 / 编辑器标签与占位文案 */
        setText('contentCardTitle', conf.card);
        var hintEl = document.getElementById('contentHint');
        if (hintEl !== null) {
            hintEl.innerHTML = e(conf.hint);
        }
        setText('labelPhenomenon', conf.contentLabel);
        var contentEl = document.getElementById('phenomenon');
        if (contentEl !== null) {
            contentEl.placeholder = conf.contentPlaceholder;
        }
    }

    /* 简易转义，避免把提示文案当 HTML 注入 */
    function e(text) {
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(text));
        return div.innerHTML;
    }

    sync();
})();
</script>

