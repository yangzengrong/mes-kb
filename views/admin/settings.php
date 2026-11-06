<?php
/**
 * 系统配置（需求 4.9 系统基础配置模块）+ 数据备份（需求 5.4）。
 *
 * @var array<string,string> $settings
 * @var array<string,string> $errors
 * @var array<int,array{name:string,size:int,time:int}> $backups
 * @var array<int,string> $allowedExt
 * @var string $phpUploadMax
 * @var string $phpPostMax
 * @var string $dbVersion
 * @var string $appVersion
 * @var bool $debug
 */
$extOptions = [
    'doc'  => 'Word 97-2003 (.doc)',
    'docx' => 'Word (.docx)',
    'pdf'  => 'PDF (.pdf)',
    'png'  => '图片 (.png)',
    'jpg'  => '图片 (.jpg)',
    'jpeg' => '图片 (.jpeg)',
    'gif'  => '动图 (.gif)',
    'txt'  => '文本 (.txt)',
    'log'  => '日志 (.log)',
    'xls'  => 'Excel 97-2003 (.xls)',
    'xlsx' => 'Excel (.xlsx)',
];
$val = static function (string $key, string $default = '') use ($settings): string {
    return (string) ($settings[$key] ?? $default);
};
?>

<?php if ($errors !== []): ?>
    <div class="alert alert-error">
        <strong>配置未保存：</strong>
        <ul class="alert-list">
            <?php foreach ($errors as $message): ?><li><?= e($message) ?></li><?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<form method="post" action="<?= e(admin_url('settings_action.php')) ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="do" value="save">

    <div class="panel-grid">
        <section class="card">
            <h2 class="card-title">网站基础设置</h2>

            <div class="field">
                <label for="site_name">网站名称 <span class="req">*</span></label>
                <input type="text" id="site_name" name="site_name" maxlength="100" required
                       value="<?= e($val('site.name')) ?>">
            </div>

            <div class="field">
                <label for="site_intro">首页简介</label>
                <textarea id="site_intro" name="site_intro" rows="3"><?= e($val('site.intro')) ?></textarea>
            </div>

            <div class="field">
                <label for="site_notice">公告提示</label>
                <textarea id="site_notice" name="site_notice" rows="2"><?= e($val('site.notice')) ?></textarea>
            </div>

            <div class="field">
                <label class="checkbox">
                    <input type="checkbox" name="home_notice_enabled" value="1"
                        <?= $val('home.notice_enabled', '1') === '1' ? ' checked' : '' ?>>
                    <span>在首页展示公告</span>
                </label>
            </div>

            <div class="field">
                <label for="site_copyright">底部版权信息</label>
                <input type="text" id="site_copyright" name="site_copyright" maxlength="120"
                       value="<?= e($val('site.copyright')) ?>">
                <span class="field-hint">全站页脚统一展示（需求 2.4 版权规范）。</span>
            </div>
        </section>

        <section class="card">
            <h2 class="card-title">文件上传配置</h2>

            <div class="field">
                <label for="upload_max_size_mb">单文件体积上限（MB）</label>
                <input type="number" id="upload_max_size_mb" name="upload_max_size_mb" min="1" max="512"
                       value="<?= e($val('upload.max_size_mb', '20')) ?>">
                <span class="field-hint">
                    服务器当前限制：upload_max_filesize = <?= e($phpUploadMax) ?>，
                    post_max_size = <?= e($phpPostMax) ?>。系统配置不会突破 PHP 限制。
                </span>
            </div>

            <div class="field">
                <label>允许上传的文件格式</label>
                <div class="checkbox-grid">
                    <?php foreach ($extOptions as $ext => $label): ?>
                        <label class="checkbox">
                            <input type="checkbox" name="allowed_ext[]" value="<?= e($ext) ?>"
                                <?= in_array($ext, $allowedExt, true) ? ' checked' : '' ?>>
                            <span><?= e($label) ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>
    </div>

    <div class="panel-grid">
        <section class="card">
            <h2 class="card-title">首页展示配置</h2>

            <div class="field-row">
                <div class="field">
                    <label for="home_latest_count">最新案例展示数量</label>
                    <input type="number" id="home_latest_count" name="home_latest_count" min="1" max="50"
                           value="<?= e($val('home.latest_count', '8')) ?>">
                </div>
                <div class="field">
                    <label for="home_hot_count">热门案例展示数量</label>
                    <input type="number" id="home_hot_count" name="home_hot_count" min="1" max="50"
                           value="<?= e($val('home.hot_count', '8')) ?>">
                </div>
            </div>

            <div class="field">
                <label for="home_sort">默认排序规则</label>
                <select id="home_sort" name="home_sort">
                    <option value="latest"<?= $val('home.sort', 'latest') === 'latest' ? ' selected' : '' ?>>
                        最新优先
                    </option>
                    <option value="hot"<?= $val('home.sort') === 'hot' ? ' selected' : '' ?>>
                        热门优先（按浏览量）
                    </option>
                </select>
            </div>
        </section>

        <section class="card">
            <h2 class="card-title">案例生命周期</h2>

            <div class="field">
                <label for="case_expire_days">案例默认有效期（天）</label>
                <input type="number" id="case_expire_days" name="case_expire_days" min="0" max="3650"
                       value="<?= e($val('case.expire_days', '0')) ?>">
                <span class="field-hint">
                    发布时若未单独指定有效期，则按此天数自动计算；填 0 表示不自动过期。
                </span>
            </div>

            <div class="field">
                <label for="case_new_days">新案例标识天数（NEW 角标）</label>
                <input type="number" id="case_new_days" name="case_new_days" min="0" max="365"
                       value="<?= e($val('case.new_days', '1')) ?>">
                <span class="field-hint">
                    案例发布后在此天数内，前台首页标题会显示醒目的 NEW 标识；按 24 小时滚动计算，
                    默认 1 天（即发布满 24 小时后自动取消标识）；填 0 表示关闭该标识。
                </span>
            </div>

            <div class="field">
                <label class="checkbox">
                    <input type="checkbox" name="case_auto_approve" value="1"
                        <?= $val('case.auto_approve', '0') === '1' ? ' checked' : '' ?>>
                    <span>运维管理员提交的案例直接发布（跳过审核）</span>
                </label>
                <span class="field-hint">关闭时，运维管理员提交的案例为「待审核」，需超级管理员审核后前台可见。</span>
            </div>
        </section>
    </div>

    <section class="card">
        <h2 class="card-title">同义词组</h2>
        <p class="hint">
            车间同事习惯叫法不同（如「扫码枪」与「扫描枪」），配置同义词后，
            搜索其中任意一个词，同组其它词的相关案例会一并命中。
        </p>

        <div class="field">
            <label for="search_synonyms">同义词组（每行一组，主词=别名1,别名2）</label>
            <textarea id="search_synonyms" name="search_synonyms" rows="6"
                      placeholder="<?= e(App\Models\Synonym::sample()) ?>"><?= e($val('search.synonyms', '')) ?></textarea>
            <span class="field-hint">
                每行一组，用等号分隔主词与别名，别名之间用中英文逗号均可；以 # 开头的行视为注释。
                最多 100 组，单个词不超过 20 字。留空表示不启用同义词扩展。
            </span>
        </div>
    </section>

    <section class="card">
        <h2 class="card-title">拼音首字母索引</h2>
        <p class="hint">
            已内置启用，无需配置：案例<b>标题 / 标签 / 设备名</b>、<b>MES 菜单名</b>、<b>工序</b>与<b>故障类型名</b>
            会自动生成拼音首字母索引，在检索框输入字母缩写即可命中对应中文术语。
        </p>
        <ul class="hint" style="margin:6px 0 0 18px;padding:0;list-style:disc">
            <li>输入 <code>smq</code> → 命中「扫码枪」；输入 <code>dyj</code> → 命中「打印机」</li>
            <li>支持术语内部的连续片段，例如「绞线效率」中的任意 2 字以上片段</li>
            <li>保存案例 / 录入菜单后索引自动刷新，新词立即可搜（索引缓存 6 小时）</li>
        </ul>
        <p class="hint <?= App\Models\PinyinTerm::enabled() ? '' : 'text-danger' ?>" style="margin-top:8px">
            运行环境：
            <?php if (App\Models\PinyinTerm::enabled()): ?>
                PHP intl 扩展可用，拼音联想已生效。
            <?php else: ?>
                未检测到 intl 扩展，拼音联想已自动关闭（同义词与常规检索不受影响）。
            <?php endif; ?>
        </p>
    </section>

    <section class="card">
        <h2 class="card-title">员工反馈</h2>
        <p class="hint">
            控制前台「✎ 员工反馈」入口与提交表单。关闭后导航入口与首页横幅会一并隐藏，历史反馈数据仍可在后台查看。
        </p>

        <div class="field">
            <label class="checkbox">
                <input type="checkbox" name="feedback_enabled" value="1"
                    <?= $val('feedback.enabled', '1') === '1' ? ' checked' : '' ?>>
                <span>启用员工反馈（前台可提交 MES 疑难问题）</span>
            </label>
        </div>

        <div class="field">
            <label for="feedback_departments">归属部门候选（每行一个）</label>
            <textarea id="feedback_departments" name="feedback_departments" rows="6"
                      placeholder="生产部&#10;质量部&#10;设备部"><?= e($val('feedback.departments')) ?></textarea>
            <span class="field-hint">
                前台表单下拉候选；员工也可选「＋ 自定义…」临时新增，最多 60 个。
            </span>
            <?php if (isset($errors['feedback_departments'])): ?>
                <span class="field-error"><?= e((string) $errors['feedback_departments']) ?></span>
            <?php endif; ?>
        </div>

        <div class="field">
            <label for="feedback_limit_per_hour">同一 IP 每小时提交上限</label>
            <input type="number" id="feedback_limit_per_hour" name="feedback_limit_per_hour" min="1" max="100"
                   value="<?= e($val('feedback.limit_per_hour', '5')) ?>">
            <span class="field-hint">
                配合图形验证码与蜜罐字段防刷单，建议 3 - 10。
            </span>
            <?php if (isset($errors['feedback_limit_per_hour'])): ?>
                <span class="field-error"><?= e((string) $errors['feedback_limit_per_hour']) ?></span>
            <?php endif; ?>
        </div>

        <div class="field">
            <label for="feedback_banner_title">反馈页横幅标题</label>
            <input type="text" id="feedback_banner_title" name="feedback_banner_title" maxlength="60"
                   value="<?= e($val('feedback.banner_title')) ?>"
                   placeholder="把你在 MES 里遇到的疑难问题告诉我们……">
            <span class="field-hint">
                展示在「员工反馈」页标题下方横幅的首行；留空则不显示该行。
            </span>
            <?php if (isset($errors['feedback_banner_title'])): ?>
                <span class="field-error"><?= e((string) $errors['feedback_banner_title']) ?></span>
            <?php endif; ?>
        </div>

        <div class="field">
            <label for="feedback_banner_desc">反馈页横幅描述</label>
            <textarea id="feedback_banner_desc" name="feedback_banner_desc" rows="2" maxlength="200"
                      placeholder="遇到 MES 系统疑难问题？在这里提交……"><?= e($val('feedback.banner_desc')) ?></textarea>
            <span class="field-hint">
                横幅第二行说明；标题与描述均留空时，整幅横幅不显示。
            </span>
            <?php if (isset($errors['feedback_banner_desc'])): ?>
                <span class="field-error"><?= e((string) $errors['feedback_banner_desc']) ?></span>
            <?php endif; ?>
        </div>

        <p class="hint">
            处理入口：<a href="<?= e(admin_url('feedbacks.php')) ?>">员工反馈管理 →</a>
        </p>
    </section>

    <section class="card">
        <h2 class="card-title">分享到微信</h2>

        <div class="field">
            <label class="checkbox">
                <input type="checkbox" name="share_enabled" value="1"
                    <?= $val('share.enabled', '1') === '1' ? ' checked' : '' ?>>
                <span>在前台案例详情页显示「分享」按钮</span>
            </label>
        </div>

        <div class="field">
            <label for="share_card_title">卡片副标题</label>
            <input type="text" id="share_card_title" name="share_card_title" maxlength="60"
                   value="<?= e($val('share.card_title')) ?>"
                   placeholder="<?= e($val('site.name')) ?>">
            <span class="field-hint">
                显示在分享卡片的标题下方。<strong>留空则自动使用「网站名称」</strong>。
                这里是「谁发的」标识，建议用部门或系统名，如「MES · 制造系统知识库」。
            </span>
        </div>

        <div class="field">
            <label for="share_card_hint">卡片底部提示语</label>
            <input type="text" id="share_card_hint" name="share_card_hint" maxlength="40"
                   value="<?= e($val('share.card_hint', '扫码或长按图片转发')) ?>">
            <span class="field-hint">
                二维码旁边的那行小字，告诉用户怎么操作。卡片幅面有限，<strong>建议 20 字以内</strong>。
            </span>
        </div>

        <div class="field">
            <label for="share_wechat_appid">微信 AppID（预留）</label>
            <input type="text" id="share_wechat_appid" name="share_wechat_appid" maxlength="32"
                   value="<?= e($val('share.wechat_appid')) ?>"
                   placeholder="留空即可，不影响当前分享功能">
        </div>

        <div class="field">
            <label for="share_wechat_secret">微信 AppSecret（预留）</label>
            <input type="text" id="share_wechat_secret" name="share_wechat_secret" maxlength="64"
                   value="<?= e($val('share.wechat_secret')) ?>"
                   placeholder="留空即可，不影响当前分享功能">
            <span class="field-error"><?= e((string) ($errors['share_wechat_secret'] ?? '')) ?></span>
        </div>

        <p class="hint">
            <strong>当前采用的分享方式：</strong>生成一张带标题和二维码的卡片图，用户<strong>长按图片</strong>
            即可转发到微信好友或朋友圈。这条路不依赖任何微信接口，
            内网可以直接用，将来开放外网也不用改。<br>
            <strong>为什么没有「一键分享」按钮：</strong>微信明确禁止网页直接唤起朋友圈分享；
            而微信 JS-SDK 需要<strong>已备案的公网域名 + 认证公众号</strong>，内网 IP 下必然失败。
            等这两项就位后，再填上面的 AppID / AppSecret 即可启用「自定义分享给朋友」。
        </p>
    </section>

    <section class="card">
        <h2 class="card-title">作业系统直达入口</h2>
        <p class="hint">
            配置前台顶部「作业系统直达」快捷入口条：每个工序可配置主页与作业页面两个外链地址。
            留空表示不展示该入口条。
        </p>

        <div class="field">
            <label for="nav_url_map">入口配置（JSON 数组）</label>
            <textarea id="nav_url_map" name="nav_url_map" rows="6"
                      placeholder='[{"name":"绞线","home":"http://mes.example.com","work":"http://mes.example.com/order/operate"}]'><?= e(url_map_json_resolved()) ?></textarea>
            <span class="field-hint">
                格式：JSON 数组，每项包含 name（工序名，必填）、home（主页地址）、work（作业页面地址），
                地址必须以 http:// 或 https:// 开头，最多 12 项。前台每项渲染为「名称 + 主页 + 作业页面」胶囊按钮，新窗口打开。
                留空并保存即隐藏该入口条；清空后想恢复四个工序，可点
                <button type="button" class="btn-link js-restore-url-map" data-default="<?= e(url_map_default_json()) ?>">恢复默认四个工序</button>。
            </span>
        </div>
    </section>

    <section class="card">
        <h2 class="card-title">安全防护</h2>

        <div class="field">
            <label class="checkbox">
                <input type="checkbox" name="security_ip_restrict" value="1"
                    <?= $val('security.ip_restrict', '0') === '1' ? ' checked' : '' ?>>
                <span>启用后台 IP 访问限制（仅白名单 IP 可访问 /admin）</span>
            </label>
        </div>

        <div class="field">
            <label for="security_ip_whitelist">IP 白名单</label>
            <textarea id="security_ip_whitelist" name="security_ip_whitelist" rows="3"
                      placeholder="每行一个，或用逗号分隔；支持网段，例如：&#10;192.168.90.216&#10;192.168.90.0/24"><?= e($val('security.ip_whitelist')) ?></textarea>
            <span class="field-hint">
                当前访问 IP 为 <?= e(client_ip()) ?>。启用前请务必把自己的 IP 加入白名单，否则将无法访问后台。
            </span>
        </div>
    </section>

    <div class="form-actions form-actions-sticky">
        <button type="submit" class="btn btn-primary">保存系统配置</button>
        <a class="btn btn-ghost" href="<?= e(admin_url('settings.php')) ?>">放弃修改</a>
    </div>
</form>

<div class="panel-grid">
    <section class="card">
        <h2 class="card-title">数据备份（需求 5.4）</h2>
        <p class="hint">
            一键备份会导出全部数据表结构与数据，生成 .sql 文件存放在 storage/backups 目录，
            可用于随时恢复。
        </p>

        <form method="post" action="<?= e(admin_url('settings_action.php')) ?>" class="inline-form"
              data-confirm="确认立即执行一次数据库备份？">
            <?= csrf_field() ?>
            <input type="hidden" name="do" value="backup">
            <button type="submit" class="btn btn-primary btn-sm">立即备份数据库</button>
        </form>

        <?php if ($backups === []): ?>
            <p class="hint" style="margin-top:14px">还没有备份文件。</p>
        <?php else: ?>
            <table class="table table-compact" style="margin-top:14px">
                <thead>
                    <tr><th>备份文件</th><th style="width:90px">大小</th><th style="width:150px">生成时间</th><th style="width:140px">操作</th></tr>
                </thead>
                <tbody>
                <?php foreach ($backups as $backup): ?>
                    <tr>
                        <td class="small"><?= e($backup['name']) ?></td>
                        <td class="small"><?= e(human_size((int) $backup['size'])) ?></td>
                        <td class="small muted"><?= e(date('Y-m-d H:i:s', (int) $backup['time'])) ?></td>
                        <td>
                            <div class="row-actions">
                                <a class="btn btn-ghost btn-xs"
                                   href="<?= e(admin_url('settings_action.php?do=download_backup&file=' . urlencode((string) $backup['name']))) ?>">
                                    下载
                                </a>
                                <form method="post" action="<?= e(admin_url('settings_action.php')) ?>"
                                      class="inline-form" data-confirm="确认删除该备份文件？">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="do" value="delete_backup">
                                    <input type="hidden" name="file" value="<?= e($backup['name']) ?>">
                                    <button type="submit" class="btn btn-danger btn-xs">删除</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </section>

    <section class="card">
        <h2 class="card-title">系统信息</h2>
        <div class="meta-list">
            <div><span>系统版本</span><strong>v<?= e($appVersion) ?></strong></div>
            <div><span>PHP 版本</span><strong><?= e(PHP_VERSION) ?></strong></div>
            <div><span>MySQL 版本</span><strong><?= e($dbVersion) ?></strong></div>
            <div><span>服务器软件</span><strong><?= e((string) ($_SERVER['SERVER_SOFTWARE'] ?? '—')) ?></strong></div>
            <div><span>调试模式</span><strong><?= $debug ? '已开启' : '已关闭' ?></strong></div>
            <div><span>存储目录</span><strong><?= e(str_replace(BASE_PATH, '', STORAGE_PATH)) ?></strong></div>
        </div>
        <p class="hint">
            调试模式由 config/config.php 中的 app.debug 控制，生产环境请保持关闭。
        </p>
    </section>
</div>
