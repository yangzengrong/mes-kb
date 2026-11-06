<?php
/**
 * 前台员工反馈页。
 *
 * @var array<string,mixed>|null      $submitted   刚提交成功的反馈（用于展示单号）
 * @var array<int,string>             $departments 部门候选
 * @var array<int,array<string,mixed>> $processes  工序候选
 * @var array<string,string>          $urgencies   紧急程度字典
 * @var array<string,string>          $errors      错误提示
 * @var array<string,string>          $oldInput    回填内容
 * @var array<string,string>|null     $flashMsg    一次性提示
 * @var array<string,mixed>|null      $trackResult 进度查询结果
 * @var array{code:string,name:string} $trackQuery 查询条件
 * @var int    $maxBytes
 * @var string $allowedExt
 * @var string $tip
 * @var bool   $enabled
 */
$errors     = $errors ?? [];
$oldInput   = $oldInput ?? [];
$val        = static function (string $key, string $default = '') use ($oldInput): string {
    return (string) ($oldInput[$key] ?? $default);
};
$departments = $departments ?? [];
$processes   = $processes ?? [];
$urgencies   = $urgencies ?? feedback_urgency_map();
$submitted   = $submitted ?? null;
$trackResult = $trackResult ?? null;
$trackQuery  = $trackQuery ?? ['code' => '', 'name' => ''];
$enabled     = (bool) ($enabled ?? true);
$maxBytes    = (int) ($maxBytes ?? 0);
$allowedExt  = (string) ($allowedExt ?? '');
?>

<?php if ($flashMsg !== null): ?>
    <div class="alert alert-<?= e((string) $flashMsg['type']) ?> front-alert">
        <?= e((string) $flashMsg['message']) ?>
    </div>
<?php endif; ?>

<?php if (!$enabled): ?>
    <section class="block">
        <div class="empty-state"><p>员工反馈功能已关闭，如需上报问题请联系系统管理员。</p></div>
    </section>
<?php else: ?>

<?php if ($submitted !== null): ?>
<section class="block">
    <div class="fb-done">
        <h2>提交成功</h2>
        <p class="fb-done-tip">请留存反馈单号，运维处理完成后可凭单号与姓名查询处理结论。</p>
        <div class="fb-code-box">
            <span>反馈单号</span>
            <strong id="fbDoneCode"><?= e((string) $submitted['code']) ?></strong>
            <button type="button" class="btn-copy-inline js-copy-code" data-code="<?= e((string) $submitted['code']) ?>">复制单号</button>
        </div>
        <div class="fb-done-meta">
            <span>问题：<?= e((string) $submitted['title']) ?></span>
            <span>提交人：<?= e((string) $submitted['employee_name']) ?>（<?= e((string) $submitted['department']) ?>）</span>
            <span>状态：<em class="fb-status fb-status-<?= e((string) $submitted['status']) ?>"><?= e(feedback_status_label((string) $submitted['status'])) ?></em></span>
        </div>
    </div>
</section>
<?php endif; ?>

<section class="block" id="feedback-form">
    <div class="block-head fb-form-head">
        <h2>员工反馈 · MES 疑难问题上报表</h2>
        <a class="btn-line" href="<?= e(base_url('feedback/stats')) ?>">查看反馈统计</a>
    </div>
    <?php if (($tip ?? '') !== ''): ?>
        <p class="block-sub"><?= e((string) $tip) ?></p>
    <?php endif; ?>

    <?php if (isset($errors['general'])): ?>
        <div class="alert alert-error front-alert"><?= e((string) $errors['general']) ?></div>
    <?php endif; ?>

    <form class="fb-form" method="post" action="<?= e(base_url('feedback')) ?>" enctype="multipart/form-data" autocomplete="off">
        <?= csrf_field() ?>
        <div class="fb-honeypot" aria-hidden="true">
            <label>网站（请勿填写）</label>
            <input type="text" name="website" value="" tabindex="-1">
        </div>

        <div class="fb-grid">
            <div class="fb-field">
                <label for="employee_name">员工姓名 <em>*</em></label>
                <input type="text" id="employee_name" name="employee_name" maxlength="50"
                       value="<?= e($val('employee_name')) ?>" placeholder="填写本人姓名">
                <?php if (isset($errors['employee_name'])): ?>
                    <span class="fb-error"><?= e((string) $errors['employee_name']) ?></span>
                <?php endif; ?>
            </div>

            <div class="fb-field">
                <label for="department">归属部门 <em>*</em></label>
                <input type="text" id="department" name="department" maxlength="60" list="departmentList"
                       value="<?= e($val('department')) ?>" placeholder="选择或直接填写部门">
                <datalist id="departmentList">
                    <?php foreach ($departments as $department): ?>
                        <option value="<?= e((string) $department) ?>"></option>
                    <?php endforeach; ?>
                </datalist>
                <?php if (isset($errors['department'])): ?>
                    <span class="fb-error"><?= e((string) $errors['department']) ?></span>
                <?php endif; ?>
            </div>

            <div class="fb-field">
                <label for="employee_no">工号</label>
                <input type="text" id="employee_no" name="employee_no" maxlength="40"
                       value="<?= e($val('employee_no')) ?>" placeholder="选填">
            </div>

            <div class="fb-field">
                <label for="contact">联系方式</label>
                <input type="text" id="contact" name="contact" maxlength="60"
                       value="<?= e($val('contact')) ?>" placeholder="分机 / 手机号，便于回访">
            </div>

            <div class="fb-field">
                <label for="process_id">发生工序</label>
                <select id="process_id" name="process_id">
                    <option value="0">未指定</option>
                    <?php foreach ($processes as $process): ?>
                        <option value="<?= (int) $process['id'] ?>"<?= $val('process_id') === (string) $process['id'] ? ' selected' : '' ?>>
                            <?= e((string) $process['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="fb-field">
                <label for="category_id">问题类型</label>
                <select id="category_id" name="category_id">
                    <option value="0">未指定</option>
                    <?php foreach (($faultTypes ?? []) as $faultType): ?>
                        <option value="<?= (int) $faultType['id'] ?>"<?= $val('category_id') === (string) $faultType['id'] ? ' selected' : '' ?>>
                            <?= e((string) $faultType['name']) ?>
                        </option>
                        <?php foreach (($faultType['children'] ?? []) as $childType): ?>
                            <option value="<?= (int) $childType['id'] ?>"<?= $val('category_id') === (string) $childType['id'] ? ' selected' : '' ?>>
                            　└ <?= e((string) $childType['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                </select>
                <span class="fb-hint">选了类型，运维处理后生成的案例会自动归到该故障分类下</span>
            </div>

            <div class="fb-field">
                <label for="device">涉及设备 / MES 模块</label>
                <input type="text" id="device" name="device" maxlength="120"
                       value="<?= e($val('device')) ?>" placeholder="例如：扫码枪、报工终端、WMS 出库">
            </div>
        </div>

        <div class="fb-field">
            <label for="title">问题标题 <em>*</em></label>
            <input type="text" id="title" name="title" maxlength="200"
                   value="<?= e($val('title')) ?>" placeholder="一句话概括，例如：绞线工序报工时扫码枪无反应">
            <?php if (isset($errors['title'])): ?>
                <span class="fb-error"><?= e((string) $errors['title']) ?></span>
            <?php endif; ?>
        </div>

        <div class="fb-field">
            <label for="content">问题描述 <em>*</em></label>
            <textarea id="content" name="content" rows="6" maxlength="5000"
                      placeholder="请写明：发生时间、操作步骤、报错提示、影响范围、已尝试过的处理"><?= e($val('content')) ?></textarea>
            <span class="fb-hint">写得越具体，运维定位越快。支持换行分段。</span>
            <?php if (isset($errors['content'])): ?>
                <span class="fb-error"><?= e((string) $errors['content']) ?></span>
            <?php endif; ?>
        </div>

        <div class="fb-field">
            <label>紧急程度</label>
            <div class="fb-radio-row">
                <?php $currentUrgency = $val('urgency', 'normal'); ?>
                <?php foreach ($urgencies as $key => $label): ?>
                    <label class="fb-radio">
                        <input type="radio" name="urgency" value="<?= e((string) $key) ?>"
                            <?= $currentUrgency === (string) $key ? ' checked' : '' ?>>
                        <span><?= e((string) $label) ?></span>
                    </label>
                <?php endforeach; ?>
            </div>
        </div>

        <div class="fb-field">
            <label for="attachments">附件（截图 / PDF / 文档，最多 5 个）</label>
            <input type="file" id="attachments" name="attachments[]" multiple
                   accept=".png,.jpg,.jpeg,.gif,.pdf,.doc,.docx,.xls,.xlsx,.txt,.log"
                   data-max-bytes="<?= $maxBytes ?>">
            <span class="fb-hint">允许格式：<?= e($allowedExt) ?>；单个文件不超过 <?= e(human_size($maxBytes)) ?>。</span>
            <ul class="fb-file-list" id="fbFileList"></ul>
        </div>

        <div class="fb-field fb-captcha-field">
            <label for="captcha">验证码 <em>*</em></label>
            <div class="fb-captcha-row">
                <input type="text" id="captcha" name="captcha" maxlength="6" autocomplete="off" placeholder="请输入右侧字符">
                <img class="fb-captcha-img js-captcha-img" src="<?= e(base_url('feedback/captcha')) ?>" alt="验证码" title="点击刷新验证码">
            </div>
            <?php if (isset($errors['captcha'])): ?>
                <span class="fb-error"><?= e((string) $errors['captcha']) ?></span>
            <?php endif; ?>
        </div>

        <div class="fb-actions">
            <button type="submit" class="fb-submit">提交反馈</button>
            <a class="fb-track-link" href="#fb-track">已提交过？查询处理进度 ↓</a>
        </div>
    </form>
</section>

<section class="block" id="fb-track">
    <div class="block-head">
        <h2>处理进度查询</h2>
    </div>
    <p class="block-sub">输入提交成功页给出的反馈单号与本人姓名，即可查看受理状态与运维给出的处理结论。</p>

    <?php if (isset($errors['track'])): ?>
        <div class="alert alert-error front-alert"><?= e((string) $errors['track']) ?></div>
    <?php endif; ?>

    <form class="fb-track-form" method="get" action="<?= e(base_url('feedback/track')) ?>">
        <input type="text" name="code" maxlength="24" placeholder="反馈单号，如 F260912-0007"
               value="<?= e((string) $trackQuery['code']) ?>">
        <input type="text" name="name" maxlength="50" placeholder="员工姓名"
               value="<?= e((string) $trackQuery['name']) ?>">
        <button type="submit">查询</button>
    </form>

    <?php if ($trackResult !== null): ?>
        <div class="fb-track-result">
            <div class="fb-track-head">
                <strong><?= e((string) $trackResult['code']) ?></strong>
                <em class="fb-status fb-status-<?= e((string) $trackResult['status']) ?>"><?= e(feedback_status_label((string) $trackResult['status'])) ?></em>
                <span class="fb-track-date">提交于 <?= e((string) $trackResult['created_at']) ?></span>
            </div>
            <h3><?= e((string) $trackResult['title']) ?></h3>
            <p class="fb-track-content"><?= nl2br(e((string) $trackResult['content'])) ?></p>

            <?php if (($trackResult['admin_note'] ?? '') !== ''): ?>
                <div class="fb-official">
                    <span class="fb-official-tag">运维处理结论</span>
                    <p><?= nl2br(e((string) $trackResult['admin_note'])) ?></p>
                </div>
            <?php endif; ?>

            <?php if ((int) ($trackResult['case_id'] ?? 0) > 0): ?>
                <a class="fb-case-link" href="<?= e(base_url('case/' . (int) $trackResult['case_id'])) ?>">
                    查看已发布的知识库案例 →
                </a>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</section>

<script>
(function () {
    /* 验证码点击刷新 */
    var captcha = document.querySelector('.js-captcha-img');
    if (captcha) {
        captcha.addEventListener('click', function () {
            var base = captcha.getAttribute('src').split('?')[0];
            captcha.setAttribute('src', base + '?t=' + Date.now());
        });
    }

    /* 附件选择即时提示（含体积超限提醒） */
    var fileInput = document.getElementById('attachments');
    var list = document.getElementById('fbFileList');
    if (fileInput && list) {
        var maxBytes = parseInt(fileInput.getAttribute('data-max-bytes') || '0', 10);
        fileInput.addEventListener('change', function () {
            list.innerHTML = '';
            var files = Array.prototype.slice.call(fileInput.files || []);
            files.forEach(function (file) {
                var li = document.createElement('li');
                var sizeKb = (file.size / 1024).toFixed(1);
                li.textContent = file.name + '（' + sizeKb + ' KB）';
                if (maxBytes > 0 && file.size > maxBytes) {
                    li.className = 'is-over';
                    li.textContent += ' — 超过系统上限，将被拒绝';
                }
                list.appendChild(li);
            });
            if (files.length > 5) {
                var warn = document.createElement('li');
                warn.className = 'is-over';
                warn.textContent = '最多上传 5 个附件，超出的会被忽略。';
                list.appendChild(warn);
            }
        });
    }

    /* 复制反馈单号 */
    var copyBtn = document.querySelector('.js-copy-code');
    if (copyBtn) {
        copyBtn.addEventListener('click', function () {
            var code = copyBtn.getAttribute('data-code') || '';
            var temp = document.createElement('textarea');
            temp.value = code;
            temp.style.position = 'absolute';
            temp.style.left = '-9999px';
            document.body.appendChild(temp);
            temp.select();
            var ok = false;
            try {
                ok = document.execCommand('copy');
            } catch (err) {
                ok = false;
            }
            document.body.removeChild(temp);
            copyBtn.textContent = ok ? '已复制' : '请手动复制：' + code;
        });
    }
})();
</script>
<?php endif; ?>
