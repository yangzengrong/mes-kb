<?php
/**
 * 案例打印视图（现场纸质对照 / 另存为 PDF）。
 *
 * 独立完整页面：不套前台布局、无导航，避免打印出无关元素。
 * 复用 front.css 以保证富文本（表格 / 图片 / 列表）排版一致。
 *
 * @var array<string,mixed> $case
 * @var array<int,array<string,mixed>> $attachments
 * @var string $siteName
 * @var string $copyright
 * @var string $printUrl
 */
$caseId  = (int) $case['id'];
$docType = (string) ($case['doc_type'] ?? 'case');

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

$sections = [['key' => 'phenomenon', 'label' => $contentLabel]];
foreach ($legacyLabels as $key => $label) {
    if (trim((string) ($case[$key] ?? '')) !== '') {
        $sections[] = ['key' => $key, 'label' => $label];
    }
}
// 打印稿上「适用于哪条线/机台/版本」比关键词更关键 —— 现场拿着纸最需要判断能不能用。
$tagRaw        = (string) ($case['tags'] ?? '');
$printScopes   = \App\Core\CaseTag::scopes($tagRaw);
$printKeywords = \App\Core\CaseTag::keywords($tagRaw);
$scopeGroups   = \App\Core\CaseTag::groupByDimension($printScopes);

/**
 * 排查记录：打印稿上**不折叠**。
 *
 * 前台用 <details> 收起是因为读者多数只想拿结论；打印稿是给现场排查用的，
 * 「怎么查出来的」恰恰是最该看到的信息，所以完整展开并保留原小标题。
 */
$printTrace = \App\Core\CaseSection::splitTrace((string) ($case['phenomenon'] ?? ''));
$hasTrace   = $printTrace['found'] && trim($printTrace['trace']) !== '';
$traceText  = trim((string) ($printTrace['label'] ?? '')) !== ''
    ? (string) $printTrace['label']
    : '排查过程';
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= e((string) $case['title']) ?> · 打印视图</title>
    <link rel="stylesheet" href="<?= e(asset('css/front.css')) ?>">
    <style>
        .print-page { background: #EEF0F4; margin: 0; padding: 20px 16px 40px; }
        .print-toolbar {
            max-width: 860px; margin: 0 auto 14px; display: flex; align-items: center;
            flex-wrap: wrap; gap: 12px; font-size: 13px; color: #5F5E5A;
        }
        .print-toolbar .print-btn {
            padding: 8px 18px; border: none; border-radius: var(--radius-sm); cursor: pointer;
            background: var(--primary); color: #fff; font-size: 13.5px; font-weight: 600;
        }
        .print-toolbar .print-btn:hover { background: var(--primary-dark); }
        .print-toolbar .back-link { font-size: 13px; }
        .print-sheet {
            max-width: 860px; margin: 0 auto; background: #fff; padding: 34px 42px 28px;
            border-radius: var(--radius); box-shadow: 0 2px 10px rgba(0, 0, 0, .06);
        }
        .print-head { border-bottom: 2px solid var(--primary); padding-bottom: 14px; margin-bottom: 18px; }
        .print-head h1 { font-size: 21px; line-height: 1.45; margin: 0 0 10px; }
        .print-meta { display: flex; flex-wrap: wrap; gap: 6px 14px; font-size: 12.5px; color: #5F5E5A; }
        .print-tags { margin-top: 8px; font-size: 12.5px; color: var(--primary); }
        .print-section { margin-bottom: 20px; }
        .print-section h2 {
            font-size: 15px; margin: 0 0 8px; padding-left: 9px;
            border-left: 4px solid var(--primary); line-height: 1.3;
        }
        .print-attach { list-style: none; margin: 0; padding: 0; font-size: 13px; }
        .print-attach li { padding: 5px 0; border-bottom: 1px dashed var(--border); }
        .print-foot {
            margin-top: 24px; padding-top: 12px; border-top: 1px solid var(--border);
            font-size: 12px; color: var(--muted); line-height: 1.8;
        }
        @media print {
            .no-print { display: none !important; }
            .print-page { background: #fff; padding: 0; }
            .print-sheet { max-width: none; box-shadow: none; border-radius: 0; padding: 0; }
            .print-head h1 { font-size: 19px; }
            .print-section { page-break-inside: avoid; }
            .print-section h2 { page-break-after: avoid; }
            .rich-content img { max-width: 100% !important; }
            @page { margin: 16mm 14mm; }
        }
    </style>
</head>
<body class="print-page">
    <div class="print-toolbar no-print">
        <button type="button" class="print-btn" onclick="window.print()">🖨 打印 / 另存为 PDF</button>
        <a class="back-link" href="<?= e(base_url('case/' . $caseId)) ?>">‹ 返回文章页</a>
        <span>提示：打印窗口中把「目标打印机」选为「另存为 PDF」即可保存为 PDF 文件。</span>
    </div>

    <article class="print-sheet">
        <header class="print-head">
            <h1><?= e((string) $case['title']) ?></h1>
            <div class="print-meta">
                <?php if ((string) ($case['process_name'] ?? '') !== ''): ?>
                    <span>工序：<?= e((string) $case['process_name']) ?></span>
                <?php endif; ?>
                <?php if ((string) ($case['position_name'] ?? '') !== ''): ?>
                    <span>岗位：<?= e((string) $case['position_name']) ?></span>
                <?php endif; ?>
                <?php if ((int) $case['category_id'] > 0): ?>
                    <span>故障类型：<?= e((string) ($case['category_name'] ?? '未分类')) ?></span>
                <?php endif; ?>
                <span>文档类型：<?= e(doc_type_label($docType)) ?></span>
                <?php if ((string) $case['device'] !== ''): ?>
                    <span>设备：<?= e((string) $case['device']) ?></span>
                <?php endif; ?>
                <?php if ((string) $case['handler'] !== ''): ?>
                    <span>处理人：<?= e((string) $case['handler']) ?></span>
                <?php endif; ?>
                <?php if (!empty($case['handled_at'])): ?>
                    <span>处理时间：<?= e(substr((string) $case['handled_at'], 0, 10)) ?></span>
                <?php endif; ?>
                <span>发布：<?= e(date('Y-m-d', strtotime((string) ($case['published_at'] ?? $case['created_at'])))) ?></span>
                <span>浏览：<?= (int) $case['views'] ?> 次</span>
            </div>
            <?php if ($scopeGroups !== []): ?>
                <div class="print-tags">适用范围：<?php
                    $scopeText = [];
                    foreach ($scopeGroups as $dim => $values) {
                        $scopeText[] = $dim . ' ' . implode('/', $values);
                    }
                    echo e(implode('；', $scopeText));
                ?></div>
            <?php endif; ?>
            <?php if ($printKeywords !== []): ?>
                <div class="print-tags">关键词：<?= e(implode(' / ', $printKeywords)) ?></div>
            <?php endif; ?>
        </header>

        <?php foreach ($sections as $section): ?>
            <?php
            // 主内容用切分后的正文（排查部分已剥离），后面单独整段展开打印
            $content = $section['key'] === 'phenomenon'
                ? ($hasTrace ? $printTrace['main'] : (string) ($case['phenomenon'] ?? ''))
                : (string) ($case[$section['key']] ?? '');
            $content = trim($content);
            ?>
            <?php if ($content === '') { continue; } ?>
            <section class="print-section">
                <h2><?= e($section['label']) ?></h2>
                <div class="rich-content"><?= rich_html($content) ?></div>
            </section>
        <?php endforeach; ?>

        <?php if ($hasTrace): ?>
        <section class="print-section">
            <h2><?= e($traceText) ?></h2>
            <div class="rich-content"><?= rich_html($printTrace['trace']) ?></div>
        </section>
        <?php endif; ?>

        <?php if ($attachments !== []): ?>
            <section class="print-section">
                <h2>相关附件（<?= count($attachments) ?>）</h2>
                <ul class="print-attach">
                    <?php foreach ($attachments as $file): ?>
                        <li>
                            <?= e((string) $file['orig_name']) ?>
                            （<?= e(human_size((int) $file['size'])) ?>）
                            —— 见知识库原文附件
                        </li>
                    <?php endforeach; ?>
                </ul>
            </section>
        <?php endif; ?>

        <footer class="print-foot">
            来源：<?= e($siteName) ?> · <?= e($printUrl) ?><br>
            附件与最新内容请以线上原文为准。<?= e($copyright) ?>
        </footer>
    </article>
</body>
</html>
