<?php
/**
 * 员工反馈详情（后台受理 / 生成故障案例）。
 *
 * @var array<string,mixed>        $feedback
 * @var array<int,array<string,mixed>> $attachments
 * @var array<string,mixed>|null   $linkedCase
 * @var array<int,string>          $categories
 * @var array<int,string>          $processes
 * @var array<string,string>       $statuses
 * @var array<string,string>       $urgencies
 * @var array<string,string>       $errors
 */
$feedback    = $feedback ?? [];
$attachments = $attachments ?? [];
$errors      = $errors ?? [];
$linkedCase  = $linkedCase ?? null;
$fbId        = (int) ($feedback['id'] ?? 0);
$status      = (string) ($feedback['status'] ?? 'pending');
$canHandle   = auth()->can('feedback.handle');
$err         = static function (string $key) use ($errors): string {
    return (string) ($errors[$key] ?? '');
};
$infoRow = static function (string $label, string $value): string {
    if ($value === '') {
        $value = '—';
    }
    return '<div class="fb-info-item"><span>' . e($label) . '</span><strong>' . e($value) . '</strong></div>';
};
?>
<div class="card">
    <div class="card-head">
        <h2 class="card-title">
            反馈 <?= e((string) ($feedback['code'] ?? '')) ?>
            <span class="fb-status fb-status-<?= e($status) ?>"><?= e(feedback_status_label($status)) ?></span>
            <span class="fb-urgency fb-urgency-<?= e((string) ($feedback['urgency'] ?? 'normal')) ?>">
                <?= e(feedback_urgency_label((string) ($feedback['urgency'] ?? 'normal'))) ?>
            </span>
        </h2>
        <div class="card-actions">
            <a class="btn btn-ghost btn-sm" href="<?= e(admin_url('feedbacks.php')) ?>">← 返回反馈列表</a>
        </div>
    </div>

    <div class="fb-info">
        <?= $infoRow('提交人', (string) ($feedback['employee_name'] ?? '')) ?>
        <?= $infoRow('归属部门', (string) ($feedback['department'] ?? '')) ?>
        <?= $infoRow('工号', (string) ($feedback['employee_no'] ?? '')) ?>
        <?= $infoRow('联系方式', (string) ($feedback['contact'] ?? '')) ?>
        <?= $infoRow('发生工序', (string) ($feedback['process_name'] ?? '')) ?>
        <?= $infoRow('问题类型', (string) ($feedback['category_name'] ?? '')) ?>
        <?= $infoRow('涉及设备 / 模块', (string) ($feedback['device'] ?? '')) ?>
        <?= $infoRow('提交时间', (string) ($feedback['created_at'] ?? '')) ?>
        <?= $infoRow('提交 IP', (string) ($feedback['ip'] ?? '')) ?>
    </div>
</div>

<div class="card">
    <h2 class="card-title">问题描述</h2>
    <h3 class="fb-detail-title"><?= e((string) ($feedback['title'] ?? '')) ?></h3>
    <div class="fb-detail-content"><?= nl2br(e((string) ($feedback['content'] ?? ''))) ?></div>
</div>

<div class="card">
    <h2 class="card-title">员工上传的附件（<?= count($attachments) ?>）</h2>
    <?php if ($attachments === []): ?>
        <p class="hint">该反馈未上传附件。</p>
    <?php else: ?>
        <table class="table table-compact">
            <thead>
                <tr><th>文件名</th><th style="width:70px">格式</th><th style="width:90px">大小</th><th style="width:140px">操作</th></tr>
            </thead>
            <tbody>
            <?php foreach ($attachments as $file): ?>
                <tr>
                    <td><?= e((string) $file['orig_name']) ?></td>
                    <td><span class="tag"><?= e((string) $file['ext']) ?></span></td>
                    <td class="small"><?= e(human_size((int) $file['size'])) ?></td>
                    <td>
                        <a class="btn btn-ghost btn-xs" target="_blank" rel="noopener"
                           href="<?= e(admin_url('feedback_file.php?id=' . (int) $file['id'] . '&mode=preview')) ?>">预览</a>
                        <a class="btn btn-ghost btn-xs"
                           href="<?= e(admin_url('feedback_file.php?id=' . (int) $file['id'] . '&mode=download')) ?>">下载</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<?php if ($linkedCase !== null): ?>
<div class="card">
    <h2 class="card-title">已生成的知识库案例</h2>
    <p class="hint">
        该反馈已于 <?= e((string) ($feedback['handled_at'] ?? '')) ?> 生成案例并发布，前台「最新故障案例」已可检索到。
    </p>
    <p class="fb-linked-case">
        <a class="link-strong" href="<?= e(admin_url('case_form.php?id=' . (int) $linkedCase['id'])) ?>">
            #<?= (int) $linkedCase['id'] ?> <?= e((string) $linkedCase['title']) ?>
        </a>
        <span class="badge badge-<?= e((string) $linkedCase['status']) ?>"><?= e(case_status_label((string) $linkedCase['status'])) ?></span>
        <a class="btn btn-ghost btn-xs" target="_blank" rel="noopener"
           href="<?= e(base_url('case/' . (int) $linkedCase['id'])) ?>">前台查看 ↗</a>
    </p>
    <?php if (($feedback['admin_note'] ?? '') !== ''): ?>
        <div class="fb-official">
            <span class="fb-official-tag">给员工的处理结论</span>
            <p><?= nl2br(e((string) $feedback['admin_note'])) ?></p>
        </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($canHandle): ?>
<div class="card">
    <h2 class="card-title">受理与关闭</h2>
    <form method="post" action="<?= e(admin_url('feedback_action.php')) ?>" class="fb-handle-form">
        <?= csrf_field() ?>
        <input type="hidden" name="do" value="status">
        <input type="hidden" name="id" value="<?= $fbId ?>">

        <div class="field">
            <label for="status">更新状态</label>
            <select id="status" name="status">
                <?php foreach (($statuses ?? feedback_status_map()) as $key => $label): ?>
                    <?php if ($key === 'resolved') { continue; } ?>
                    <option value="<?= e((string) $key) ?>"<?= $status === (string) $key ? ' selected' : '' ?>>
                        <?= e((string) $label) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <span class="field-hint">
                「已解决」由下方生成案例时自动置位；选择「已关闭」时必须填写原因。
            </span>
        </div>

        <div class="field">
            <label for="status_note">处理说明（员工可在进度查询中看到）</label>
            <textarea id="status_note" name="admin_note" rows="3" placeholder="例如：已联系设备组现场处理，预计今日 15:00 前恢复"><?= e((string) ($feedback['admin_note'] ?? '')) ?></textarea>
        </div>

        <button type="submit" class="btn btn-primary btn-sm">保存状态</button>
    </form>
</div>

<?php if ($canHandle && $linkedCase === null): ?>
<div class="card">
    <h2 class="card-title">生成故障案例并发布</h2>
    <p class="hint">
        员工描述会作为「故障现象」自动带入（末尾标注来源），你只需补充<strong>解决方案</strong>并指定工序与故障类型；
        保存后案例直接发布到前台「最新故障案例」，员工上传的附件会一并挂到案例上。
    </p>

    <?php if ($err('title') !== '' || $err('solution') !== '' || $err('process_id') !== '' || $err('category_id') !== ''): ?>
        <div class="alert alert-error">请补全必填项后再生成案例。</div>
    <?php endif; ?>

    <form method="post" action="<?= e(admin_url('feedback_action.php')) ?>" class="fb-tocase-form">
        <?= csrf_field() ?>
        <input type="hidden" name="do" value="to_case">
        <input type="hidden" name="id" value="<?= $fbId ?>">

        <div class="field-row">
            <div class="field">
                <label for="case_title">案例标题 <span class="req">*</span></label>
                <input type="text" id="case_title" name="title" maxlength="200"
                       value="<?= e((string) ($feedback['title'] ?? '')) ?>"
                       class="<?= $err('title') !== '' ? 'has-error' : '' ?>">
                <?php if ($err('title') !== ''): ?><span class="field-error"><?= e($err('title')) ?></span><?php endif; ?>
            </div>
        </div>

        <div class="field-row">
            <div class="field">
                <label for="case_process">所属工序 <span class="req">*</span></label>
                <select id="case_process" name="process_id" class="<?= $err('process_id') !== '' ? 'has-error' : '' ?>">
                    <option value="">请选择工序</option>
                    <?php foreach (($processes ?? []) as $pid => $pname): ?>
                        <option value="<?= (int) $pid ?>"<?= (int) ($feedback['process_id'] ?? 0) === (int) $pid ? ' selected' : '' ?>>
                            <?= e((string) $pname) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php if ($err('process_id') !== ''): ?><span class="field-error"><?= e($err('process_id')) ?></span><?php endif; ?>
            </div>
            <div class="field">
                <label for="case_category">故障类型 <span class="req">*</span></label>
                <select id="case_category" name="category_id" class="<?= $err('category_id') !== '' ? 'has-error' : '' ?>">
                    <option value="">请选择故障类型</option>
                    <?php $fbCategoryId = (int) ($feedback['category_id'] ?? 0); ?>
                    <?php foreach (($categories ?? []) as $cid => $cname): ?>
                        <option value="<?= (int) $cid ?>"<?= $fbCategoryId === (int) $cid ? ' selected' : '' ?>><?= e((string) $cname) ?></option>
                    <?php endforeach; ?>
                </select>
                <?php if ($fbCategoryId > 0 && $err('category_id') === ''): ?>
                    <span class="field-hint">已自动带入员工提交时选择的问题类型</span>
                <?php endif; ?>
                <?php if ($err('category_id') !== ''): ?><span class="field-error"><?= e($err('category_id')) ?></span><?php endif; ?>
            </div>
        </div>

        <div class="field-row">
            <div class="field">
                <label for="case_device">涉及设备 / 模块</label>
                <input type="text" id="case_device" name="device" maxlength="120"
                       value="<?= e((string) ($feedback['device'] ?? '')) ?>">
            </div>
            <div class="field">
                <label for="case_tags">标签关键字</label>
                <input type="text" id="case_tags" name="tags" maxlength="255" placeholder="英文逗号分隔，最多 10 个">
            </div>
        </div>

        <div class="field">
            <label for="solution">解决方案 <span class="req">*</span></label>
            <textarea id="solution" name="solution" rows="14" data-rich
                      data-server-url="<?= e(admin_url('ueditor_upload.php?_token=' . \App\Core\Csrf::token())) ?>"
                      placeholder="写明排查过程与具体处置步骤，可直接粘贴 / 拖入现场截图"
                      class="<?= $err('solution') !== '' ? 'has-error' : '' ?>"></textarea>
            <?php if ($err('solution') !== ''): ?><span class="field-error"><?= e($err('solution')) ?></span><?php endif; ?>
        </div>

        <div class="field">
            <label for="case_note">给员工的处理结论（进度查询可见，选填）</label>
            <textarea id="case_note" name="admin_note" rows="3" placeholder="例如：原因为扫码枪供电不足，已更换电池并加固接线"><?= e((string) ($feedback['admin_note'] ?? '')) ?></textarea>
        </div>

        <button type="submit" class="btn btn-primary btn-sm" onclick="return confirm('确认生成案例并立即发布到前台？');">
            生成案例并发布
        </button>
    </form>
</div>
<?php endif; ?>
<?php endif; ?>
