<?php
/**
 * 前台案例详情（需求 4.8 案例详情页优化）。
 *
 * @var array<string,mixed> $case
 * @var array<int,array<string,mixed>> $attachments
 * @var array{prev:array<string,mixed>|null,next:array<string,mixed>|null} $neighbours
 * @var array<int,array<string,mixed>> $related
 */
$caseId  = (int) $case['id'];
$docType = (string) ($case['doc_type'] ?? 'case');

/**
 * 标签分组渲染（断点B 适用性标签）。
 *
 * 【为什么要分组而不是平铺】
 * 库里的 tags 混着两类语义完全不同的东西：
 *   · 适用性 ——「护套:8线」「版本:V3.2」，回答"这条内容适用于什么环境"
 *   · 关键词 ——「工艺参数」「串联机」，回答"用什么词能搜到它"
 * 平铺成一排 #xxx 时，一线看到案例仍不知道自己能不能用在自己那条线上。
 * 分组后适用性独立成块且视觉权重更高，这才是 MES 场景下最常被追问的信息。
 *
 * 分隔符与脏数据处理统一交给 CaseTag（库里历史上有顿号分隔的粘连数据）。
 *
 * 注意：视图里用全限定类名而非 use 语句 —— 模板由 View::render() 以
 * extract() 注入变量执行，不经过各视图独立的 use 解析，写全名最保险。
 */
$tagRaw       = (string) ($case['tags'] ?? '');
$caseScopes   = \App\Core\CaseTag::scopes($tagRaw);
$caseKeywords = \App\Core\CaseTag::keywords($tagRaw);
$scopeGroups  = \App\Core\CaseTag::groupByDimension($caseScopes);

/** 正文单区块标签（整体编辑器内容存于 phenomenon）+ 历史遗留分块标签 */
$contentLabel = [
    'case'      => '案例内容',
    'manual'    => '操作手册内容',
    'changelog' => '更新内容',
][$docType] ?? '案例内容';
$legacyLabels = [
    'case'      => ['cause' => '故障原因', 'solution' => '解决方案', 'prevention' => '预防措施'],
    'manual'    => ['cause' => '前提条件', 'solution' => '关键操作说明', 'prevention' => '注意事项'],
    'changelog' => ['cause' => '更新背景', 'solution' => '更新后的变化', 'prevention' => '影响范围'],
][$docType] ?? ['cause' => '故障原因', 'solution' => '解决方案', 'prevention' => '预防措施'];

/** 主内容 + 非空的历史遗留分块（旧版本回滚数据兼容） */
$sections = [
    ['key' => 'phenomenon', 'label' => $contentLabel],
];
foreach ($legacyLabels as $key => $label) {
    if (trim((string) ($case[$key] ?? '')) !== '') {
        $sections[] = ['key' => $key, 'label' => $label];
    }
}

/**
 * 排查记录折叠区（断点C）。
 *
 * 案例正文（phenomenon）里若写了「排查过程」类小标题，该小节会被切出来放进
 * 前台默认收起的折叠区 —— 一线进来先看到「现象 + 方案」这些结论，
 * 想深究「当时怎么一步步查出来的」再展开。
 *
 * 没有这个小标题的案例，$traceInfo['found'] 为 false，渲染与改造前完全一致。
 * 不新增数据库字段，靠小标题约定识别，详见 App\Core\CaseSection。
 */
$traceInfo     = \App\Core\CaseSection::splitTrace((string) ($case['phenomenon'] ?? ''));
$hasTrace      = $traceInfo['found'] && trim($traceInfo['trace']) !== '';
$traceLabel    = trim((string) ($traceInfo['label'] ?? '')) !== ''
    ? (string) $traceInfo['label']
    : '排查过程';

$previewable = ['pdf', 'png', 'jpg', 'jpeg', 'gif', 'txt', 'log'];
?>

<nav class="breadcrumb">
    <a href="<?= e(base_url('/')) ?>">首页</a>
    <span>/</span>
    <?php $detailProcess = (string) ($case['process_name'] ?? ''); ?>
    <?php if ($detailProcess !== ''): ?>
        <a href="<?= e(base_url('search?process_id=' . (int) $case['process_id'])) ?>"><?= e($detailProcess) ?></a>
        <span>/</span>
    <?php endif; ?>
    <?php $detailPosition = (string) ($case['position_name'] ?? ''); ?>
    <?php if ($detailPosition !== ''): ?>
        <a href="<?= e(base_url('search?doc_type=manual&position_id=' . (int) $case['position_id'])) ?>"><?= e($detailPosition) ?></a>
        <span>/</span>
    <?php endif; ?>
    <?php if ((int) $case['category_id'] > 0): ?>
        <a href="<?= e(base_url('search?category_id=' . (int) $case['category_id'])) ?>">
            <?= e((string) ($case['category_name'] ?? '未分类')) ?>
        </a>
        <span>/</span>
    <?php endif; ?>
    <em><?= $docType === 'manual' ? '操作手册' : ($docType === 'changelog' ? '版本更新' : '案例详情') ?></em>
</nav>

<article class="detail">
    <header class="detail-head">
        <h1><?= e((string) $case['title']) ?></h1>
        <div class="detail-tools">
            <button type="button" class="btn-copy-link js-copy-link"
                    data-path="<?= e(base_url('case/' . $caseId)) ?>"
                    data-title="<?= e((string) $case['title']) ?>"
                    title="复制本文标题和链接，发给同事打开即见">
                <svg class="btn-ico" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                    <rect x="9" y="9" width="11" height="11" rx="2"/>
                    <path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/>
                </svg><span class="copy-text">复制链接</span>
            </button>
            <?php /* 分享到微信 / 朋友圈。
                 【为什么是「长按图片转发」而不是按钮直接唤起微信分享】
                 微信的两条硬限制：
                   ① 分享到朋友圈**不允许网页程序触发** —— 微信明确禁止，没有任何 API 能绕过；
                   ② JS-SDK 需要「已备案公网域名 + 认证公众号」，当前内网 IP 下必然失败。
                 而「长按图片转发到聊天/朋友圈」是微信的基础能力，不依赖 SDK ——
                 所以做成弹层里放一张卡片图，用户长按即可。这条路**内网能用，外网开放后也不用改**。

                 【图标为什么用图片而不是 SVG/emoji】
                 这个微信图标（static/img/hoicon27.png，36×36 RGBA）来自用户既有站点，
                 用户明确要求沿用。下载到本地而非外链 —— 项目铁律是内网零 CDN 依赖，
                 页面不能因为外网图片挂了而显示裂图。 */ ?>
<?php /* 分享按钮跟随后台开关（系统配置 → 分享到微信）。
         端点本身也会校验开关，双保险：按钮藏了，直接访问地址也同样 404。 */ ?>
            <?php if (\App\Core\Setting::bool('share.enabled', true)): ?>
            <button type="button" class="btn-copy-link js-share-open"
                    data-share-card="<?= e(base_url('case/' . $caseId . '/share/card')) ?>"
                    data-share-qr="<?= e(base_url('case/' . $caseId . '/share/qr')) ?>"
                    data-share-title="<?= e((string) $case['title']) ?>"
                    title="生成分享卡片，长按图片可转发到微信好友或朋友圈">
                <img class="btn-ico btn-ico-wechat" src="<?= e(asset('img/hoicon27.png')) ?>"
                     alt="" width="14" height="14" aria-hidden="true" decoding="async">
                <span>分享到微信</span>
            </button>
            <?php endif; ?>
            <a class="btn-copy-link btn-print" target="_blank" rel="noopener"
               href="<?= e(base_url('case/' . $caseId . '/print')) ?>"
               title="打开打印视图，可打印纸质件或另存为 PDF">
                <svg class="btn-ico" viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                    <path d="M6 9V2h12v7"/>
                    <path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/>
                    <rect x="6" y="14" width="12" height="8" rx="1"/>
                </svg><span>打印 / 存为 PDF</span>
            </a>
            <span class="copy-hint">分享方式：点「分享」生成卡片，长按图片即可发到微信好友或朋友圈</span>
        </div>

        <?php /* 分享弹层。默认隐藏，点按钮才加载图片 ——
                 卡片图约 70KB，不该让每个访问者都下载。 */ ?>
        <div class="share-panel" id="sharePanel" hidden
             role="dialog" aria-modal="true" aria-labelledby="sharePanelTitle">
            <div class="share-panel-mask" data-share-close></div>
            <div class="share-panel-box">
            <div class="share-panel-head">
                <h2 id="sharePanelTitle">
                    <img class="share-head-ico" src="<?= e(asset('img/hoicon27.png')) ?>"
                         alt="" width="18" height="18" aria-hidden="true" decoding="async">
                    分享到微信
                </h2>
                <button type="button" class="share-panel-close" data-share-close
                        aria-label="关闭">×</button>
            </div>
                <div class="share-panel-body">
                    <div class="share-card-wrap">
                        <img id="shareCardImg" alt="" width="375" height="294">
                        <p class="share-loading" id="shareLoading">正在生成卡片…</p>
                    </div>
                    <ol class="share-steps">
                        <li><strong>长按</strong>上面的卡片图</li>
                        <li>选择「发送给朋友」或「分享到朋友圈」</li>
                        <li>微信同时会把链接带上，对方点开即可看原文</li>
                    </ol>
                    <div class="share-alt">
                        <span>不想发图片？直接复制链接：</span>
                        <button type="button" class="btn-link js-copy-link-inline"
                                data-path="<?= e(base_url('case/' . $caseId)) ?>"
                                data-title="<?= e((string) $case['title']) ?>">复制链接</button>
                    </div>
                    <p class="share-note">
                        提示：微信不允许网页直接唤起朋友圈分享，只能由你长按图片后手动选择，
                        这是微信的限制而非本站功能缺失。外网开放后如需自定义分享卡片，
                        可再接入微信 JS-SDK（需认证公众号与备案域名）。
                    </p>
                </div>
            </div>
        </div>
        <div class="detail-meta">
            <?php if ($detailProcess !== ''): ?>
                <span class="case-process"><?= e($detailProcess) ?></span>
            <?php endif; ?>
            <?php if ($detailPosition !== ''): ?>
                <span class="case-position"><?= e($detailPosition) ?></span>
            <?php endif; ?>
            <?php if ((int) $case['category_id'] > 0): ?>
                <span class="case-cat"><?= e((string) ($case['category_name'] ?? '未分类')) ?></span>
            <?php endif; ?>
            <?php if ((string) ($case['doc_type'] ?? 'case') !== 'case'): ?>
                <span class="case-doctype"><?= e(doc_type_label((string) $case['doc_type'])) ?></span>
            <?php endif; ?>
            <?php if ((string) $case['device'] !== ''): ?>
                <span class="meta-item">设备：<?= e((string) $case['device']) ?></span>
            <?php endif; ?>
            <?php if ((string) $case['handler'] !== ''): ?>
                <span class="meta-item">处理人：<?= e((string) $case['handler']) ?></span>
            <?php endif; ?>
            <?php if (!empty($case['handled_at'])): ?>
                <span class="meta-item">处理时间：<?= e(substr((string) $case['handled_at'], 0, 10)) ?></span>
            <?php endif; ?>
            <span class="meta-item meta-views"><?= (int) $case['views'] ?> 次浏览</span>
            <span class="meta-item meta-date">
                发布：<?= e(date('Y-m-d', strtotime((string) ($case['published_at'] ?? $case['created_at'])))) ?>
            </span>
        </div>
        <?php if ($scopeGroups !== []): ?>
        <div class="case-scope">
            <span class="case-scope-label">适用范围</span>
            <div class="case-scope-body">
                <?php foreach ($scopeGroups as $scopeDim => $scopeValues): ?>
                <div class="scope-dim">
                    <span class="scope-dim-name"><?= e($scopeDim) ?></span>
                    <?php foreach ($scopeValues as $scopeValue): ?>
                    <?php
                    // 适用性标签的检索入口：按完整标签串精确匹配（与 tags 列的存储格式一致）
                    $scopeTag = $scopeDim . ':' . $scopeValue;
                    ?>
                    <a class="scope-chip" href="<?= e(base_url('search?tag=' . urlencode($scopeTag))) ?>"
                       title="查看同样适用于 <?= e($scopeDim) ?>「<?= e($scopeValue) ?>」的案例"><?= e($scopeValue) ?></a>
                    <?php endforeach; ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($caseKeywords !== []): ?>
        <div class="case-tags">
            <?php foreach ($caseKeywords as $caseKeyword): ?>
                <a class="case-tag" href="<?= e(base_url('search?tag=' . urlencode($caseKeyword))) ?>"
                   title="查看标签【<?= e($caseKeyword) ?>】相关内容">#<?= e($caseKeyword) ?></a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </header>

    <div class="detail-body">
        <?php foreach ($sections as $section): ?>
            <?php
            // 主内容小节用切分后的正文（排查部分已剥离），其余小节仍用原字段
            $content = $section['key'] === 'phenomenon'
                ? ($hasTrace ? $traceInfo['main'] : (string) ($case['phenomenon'] ?? ''))
                : (string) ($case[$section['key']] ?? '');
            $content = trim($content);
            ?>
            <?php if ($content === '') { continue; } ?>
            <section class="detail-section">
                <h2>
                    <span class="section-bar"></span>
                    <?= e($section['label']) ?>
                </h2>
                <div class="section-content rich-content"><?= rich_html($content) ?></div>
            </section>
        <?php endforeach; ?>

        <?php if ($hasTrace): ?>
        <details class="trace-fold">
            <summary>
                <span class="trace-fold-title"><?= e($traceLabel) ?></span>
                <span class="trace-fold-hint">当时是怎么一步步查出来的（点击展开）</span>
            </summary>
            <div class="section-content rich-content trace-fold-body"><?= rich_html($traceInfo['trace']) ?></div>
        </details>
        <?php endif; ?>

        <?php if ($attachments !== []): ?>
        <section class="detail-section">
            <h2><span class="section-bar"></span>相关附件（<?= count($attachments) ?>）</h2>
            <ul class="attachment-list">
                <?php foreach ($attachments as $file): ?>
                    <?php
                    $fileId = (int) $file['id'];
                    $ext    = strtolower((string) $file['ext']);
                    ?>
                    <li>
                        <span class="file-ext"><?= e($ext) ?></span>
                        <span class="file-name"><?= e((string) $file['orig_name']) ?></span>
                        <span class="file-size"><?= e(human_size((int) $file['size'])) ?></span>
                        <span class="file-actions">
                            <?php if (in_array($ext, $previewable, true)): ?>
                                <a class="btn-line sm" target="_blank" rel="noopener"
                                   href="<?= e(base_url('file/' . $fileId . '/preview')) ?>">在线预览</a>
                            <?php endif; ?>
                            <a class="btn-line sm ghost"
                               href="<?= e(base_url('file/' . $fileId . '/download')) ?>">下载</a>
                        </span>
                    </li>
                <?php endforeach; ?>
            </ul>
            <p class="detail-hint">
                PDF、图片、TXT 可直接在线预览；Word、Excel 文件请下载后查看。
            </p>
        </section>
        <?php endif; ?>
    </div>

<?php
/**
 * 有效性反馈（断点D）。
 *
 * 【为什么放在正文之后】
 * 读者是「先找答案、顺手评价」，不是「进来就投票」。放在正文后符合这个顺序。
 *
 * 【为什么只有两个图标，不要文字评论】
 * 一线员工遇到「没帮到」时要多想一步（打开输入框、写字、提交）才会放弃，
 * 收了不到数据。所以只给两个一键按钮——想细说的人在「没帮到」下面有个可选输入框，
 * 不写也行（照样记录）。
 *
 * 【不点也算「没帮到」】
 * 后台的待复核规则里，「高浏览 + 零反馈」本身就是信号：
 * 很多人看了却没人投票，说明确实没解决 —— 看了都觉得没用，比没人看更严重。
 */
$eff = isset($effectiveness) && is_array($effectiveness) ? $effectiveness : [
    'helpful' => 0, 'unhelpful' => 0, 'total' => 0, 'rate' => -1, 'myVote' => null,
];
$myVote = $eff['myVote'];                  // true=帮到 false=没帮到 null=未投
$votedFlag = (string) ($votedFlag ?? '');
$votedMsg  = (string) ($votedMsg ?? '');
?>
<section class="eff-box" id="effBox">
    <?php if ($votedFlag !== ''): ?>
    <div class="eff-flash <?= $votedFlag === 'error' ? 'is-error' : '' ?>">
        <?php if ($votedFlag === 'helpful'): ?>
            已记录 —— 感谢反馈，这条帮到了你。
        <?php elseif ($votedFlag === 'unhelpful'): ?>
            已记录 —— 这条会进入管理员的待复核清单。
        <?php else: ?>
            <?= e($votedMsg !== '' ? $votedMsg : '提交未成功，请刷新页面后重试。') ?>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="eff-head">
        <strong>这条帮到你了吗？</strong>
        <?php if ((int) $eff['total'] > 0): ?>
        <span class="eff-rate">
            <?= (int) $eff['helpful'] ?> 人说帮到<?php
                echo (int) $eff['unhelpful'] > 0
                    ? '，' . (int) $eff['unhelpful'] . ' 人说没帮到'
                    : '';
            ?>
        </span>
        <?php endif; ?>
    </div>

    <form class="eff-form" method="post" action="<?= e(base_url('case/' . $caseId . '/vote')) ?>">
        <input type="hidden" name="_token" value="<?= e(\App\Core\Csrf::token()) ?>">
        <input type="text" name="website" value="" class="eff-hp" tabindex="-1" autocomplete="off" aria-hidden="true">

        <button type="submit" name="helpful" value="1"
                class="eff-btn<?= $myVote === true ? ' is-on' : '' ?>">
            <span class="eff-ico" aria-hidden="true">✓</span> 帮到了
        </button>
        <button type="submit" name="helpful" value="0"
                class="eff-btn<?= $myVote === false ? ' is-on' : '' ?>">
            <span class="eff-ico" aria-hidden="true">✗</span> 没帮到
        </button>

        <?php if ($myVote === false): ?>
        <input type="text" name="note" maxlength="120" class="eff-note"
               placeholder="哪一步对不上？（可不填）" value="">
        <?php endif; ?>
    </form>
</section>

<nav class="detail-nav">
    <div class="nav-prev">
            <?php if ($neighbours['prev'] !== null): ?>
                <span class="nav-label">上一篇</span>
                <a href="<?= e(base_url('case/' . (int) $neighbours['prev']['id'])) ?>">
                    <?= e(excerpt((string) $neighbours['prev']['title'], 34)) ?>
                </a>
            <?php else: ?>
                <span class="nav-label">上一篇</span>
                <em class="muted">已经是第一篇</em>
            <?php endif; ?>
        </div>
        <div class="nav-next">
            <?php if ($neighbours['next'] !== null): ?>
                <span class="nav-label">下一篇</span>
                <a href="<?= e(base_url('case/' . (int) $neighbours['next']['id'])) ?>">
                    <?= e(excerpt((string) $neighbours['next']['title'], 34)) ?>
                </a>
            <?php else: ?>
                <span class="nav-label">下一篇</span>
                <em class="muted">已经是最后一篇</em>
            <?php endif; ?>
        </div>
    </nav>
</article>

<?php if ($related !== []): ?>
<section class="block">
    <div class="block-head">
        <h2>相关案例</h2>
        <a class="block-more" href="<?= e(base_url('search?category_id=' . (int) $case['category_id'])) ?>">
            查看该分类全部 →
        </a>
    </div>
    <ul class="related-list">
        <?php foreach ($related as $item): ?>
            <li>
                <a href="<?= e(base_url('case/' . (int) $item['id'])) ?>">
                    <?= e(excerpt((string) $item['title'], 40)) ?>
                </a>
                <?php /* 补位来的条目工序与当前不同，显式标注工序，避免「为什么推这条」的困惑 */ ?>
                <?php if ((string) ($item['process_name'] ?? '') !== '' && (int) ($item['process_id'] ?? 0) !== (int) ($case['process_id'] ?? 0)): ?>
                    <span class="related-proc"><?= e((string) $item['process_name']) ?></span>
                <?php endif; ?>
                <span class="hot-views"><?= (int) $item['views'] ?> 次</span>
            </li>
        <?php endforeach; ?>
    </ul>
</section>
<?php endif; ?>
