<?php
/**
 * 附件管理（需求 4.4 附件文件精细化管理）。
 *
 * @var array<string,mixed> $filters
 * @var array{items:array<int,array<string,mixed>>,total:int,page:int,per_page:int,last_page:int} $result
 * @var array<int,array{ext:string,count:int}> $extStats
 * @var array{count:int,bytes:int} $storage
 * @var string $allowedExt
 * @var int $maxSizeMb
 * @var array<int,string> $caseOptions
 * @var bool $isSuper
 */
$query = array_filter([
    'keyword' => $filters['keyword'],
    'ext'     => $filters['ext'],
    'case_id' => $filters['case_id'] ?: '',
], static function ($value): bool {
    return $value !== '' && $value !== null;
});

$previewable = ['pdf', 'png', 'jpg', 'jpeg', 'gif', 'txt', 'log'];
?>

<div class="stat-grid stat-grid-sm">
    <div class="stat-card">
        <span class="stat-label">附件总数</span>
        <span class="stat-value"><?= (int) $storage['count'] ?></span>
        <span class="stat-foot">已绑定与未绑定合计</span>
    </div>
    <div class="stat-card">
        <span class="stat-label">磁盘占用</span>
        <span class="stat-value"><?= e(human_size((int) $storage['bytes'])) ?></span>
        <span class="stat-foot">storage/uploads</span>
    </div>
    <div class="stat-card">
        <span class="stat-label">格式种类</span>
        <span class="stat-value"><?= count($extStats) ?></span>
        <span class="stat-foot">
            <?php
            $extNames = [];
            foreach (array_slice($extStats, 0, 4) as $item) {
                $extNames[] = $item['ext'] . '(' . $item['count'] . ')';
            }
            echo e(implode(' · ', $extNames));
            ?>
        </span>
    </div>
</div>

<div class="card">
    <h2 class="card-title">上传附件</h2>
    <p class="hint">
        允许格式：<?= e($allowedExt) ?>；单文件不超过 <?= e($maxSizeMb) ?> MB。
        文件会先校验扩展名、体积与真实类型，再落盘保存。
    </p>
    <form method="post" action="<?= e(admin_url('attachment_action.php')) ?>" enctype="multipart/form-data"
          class="upload-form">
        <?= csrf_field() ?>
        <input type="hidden" name="do" value="upload">
        <div class="field-row">
            <div class="field">
                <label for="uploadFile">选择文件</label>
                <input type="file" id="uploadFile" name="file" required>
            </div>
            <div class="field">
                <label for="uploadCase">绑定案例（可选）</label>
                <select id="uploadCase" name="case_id">
                    <?php foreach ($caseOptions as $id => $label): ?>
                        <option value="<?= (int) $id ?>"><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field field-action">
                <button type="submit" class="btn btn-primary">上传</button>
            </div>
        </div>
    </form>
</div>

<div class="card">
    <form class="filter-bar" method="get" action="<?= e(admin_url('attachments.php')) ?>">
        <input type="text" name="keyword" placeholder="搜索文件名 / 所属案例"
               value="<?= e((string) $filters['keyword']) ?>">
        <select name="ext">
            <option value="">全部格式</option>
            <?php foreach ($extStats as $item): ?>
                <option value="<?= e($item['ext']) ?>"<?= $filters['ext'] === $item['ext'] ? ' selected' : '' ?>>
                    <?= e($item['ext']) ?>（<?= (int) $item['count'] ?>）
                </option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-primary btn-sm">筛选</button>
        <a class="btn btn-ghost btn-sm" href="<?= e(admin_url('attachments.php')) ?>">重置</a>
    </form>
</div>

<div class="card">
    <h2 class="card-title">附件列表 <em class="muted">共 <?= (int) $result['total'] ?> 个</em></h2>

    <?php if ($result['items'] === []): ?>
        <div class="empty-state"><p>没有符合条件的附件。</p></div>
    <?php else: ?>
    <div class="table-wrap">
        <table class="table">
            <thead>
                <tr>
                    <th style="width:52px">ID</th>
                    <th>文件名</th>
                    <th style="width:70px">格式</th>
                    <th style="width:90px">大小</th>
                    <th style="width:200px">所属案例</th>
                    <th style="width:130px">上传时间</th>
                    <th style="width:230px">操作</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($result['items'] as $item): ?>
                <?php
                $fileId   = (int) $item['id'];
                $ext      = strtolower((string) $item['ext']);
                $caseId   = (int) $item['case_id'];
                $caseOk   = $caseId > 0 && (string) ($item['case_status'] ?? '') === 'published';
                $canSee   = $isSuper || (int) $item['uploader_id'] === auth()->id();
                ?>
                <tr>
                    <td class="muted"><?= $fileId ?></td>
                    <td>
                        <?= e((string) $item['orig_name']) ?>
                        <div class="muted small">
                            上传人：<?= e((string) ($item['uploader_name'] ?? '—')) ?>
                            · 下载 <?= (int) $item['download_count'] ?> 次
                        </div>
                    </td>
                    <td><span class="tag"><?= e($ext) ?></span></td>
                    <td><?= e(human_size((int) $item['size'])) ?></td>
                    <td class="small">
                        <?php if ($caseId > 0): ?>
                            <a href="<?= e(admin_url('case_form.php?id=' . $caseId)) ?>">
                                <?= e(excerpt((string) ($item['case_title'] ?? ('#' . $caseId)), 20)) ?>
                            </a>
                            <div class="muted">
                                <?= e(case_status_label((string) ($item['case_status'] ?? ''))) ?>
                            </div>
                        <?php else: ?>
                            <span class="muted">未绑定案例</span>
                        <?php endif; ?>
                    </td>
                    <td class="small muted"><?= e((string) $item['created_at']) ?></td>
                    <td>
                        <div class="row-actions">
                            <?php if ($caseOk): ?>
                                <?php if (in_array($ext, $previewable, true)): ?>
                                    <a class="btn btn-ghost btn-xs" target="_blank" rel="noopener"
                                       href="<?= e(base_url('file/' . $fileId . '/preview')) ?>">预览</a>
                                <?php endif; ?>
                                <a class="btn btn-ghost btn-xs"
                                   href="<?= e(base_url('file/' . $fileId . '/download')) ?>">下载</a>
                            <?php else: ?>
                                <span class="muted small">案例未发布，前台不可见</span>
                            <?php endif; ?>

                            <details class="inline-details">
                                <summary class="btn btn-ghost btn-xs">绑定</summary>
                                <form method="post" action="<?= e(admin_url('attachment_action.php')) ?>"
                                      class="popover-form">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="do" value="bind">
                                    <input type="hidden" name="id" value="<?= $fileId ?>">
                                    <select name="case_id">
                                        <?php foreach ($caseOptions as $cid => $label): ?>
                                            <option value="<?= (int) $cid ?>"<?= $caseId === (int) $cid ? ' selected' : '' ?>>
                                                <?= e($label) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button type="submit" class="btn btn-primary btn-xs">保存</button>
                                </form>
                            </details>

                            <?php if ($canSee): ?>
                                <form method="post" action="<?= e(admin_url('attachment_action.php')) ?>"
                                      class="inline-form" data-confirm="确认删除该附件？">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="do" value="delete">
                                    <input type="hidden" name="id" value="<?= $fileId ?>">
                                    <button type="submit" class="btn btn-danger btn-xs">删除</button>
                                </form>
                            <?php endif; ?>

                            <?php if ($isSuper): ?>
                                <form method="post" action="<?= e(admin_url('attachment_action.php')) ?>"
                                      class="inline-form" data-confirm="彻底删除会同时移除磁盘文件，且不可恢复，确认继续？">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="do" value="purge">
                                    <input type="hidden" name="id" value="<?= $fileId ?>">
                                    <button type="submit" class="btn btn-danger btn-xs">彻底删除</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?= App\Core\View::capture('partials/pagination', [
        'result'      => $result,
        'basePath'    => admin_url('attachments.php'),
        'queryParams' => $query,
    ]) ?>
    <?php endif; ?>
</div>
