<?php
/**
 * 系统运行配置示例。
 *
 * 正常安装流程下，本文件由 install/index.php 自动生成到 config/config.php，
 * 无需手工修改。仅在无法运行安装向导时，才手工复制本文件为 config.php 并填写。
 *
 * ©Yangzengrong 版权所有
 */

return [
    'app' => [
        // 网站名称（安装后可在后台「系统配置」中修改，此处仅作初始值）
        'name'     => 'MES系统知识库',
        // 调试模式：开启后页面会显示详细错误堆栈，生产环境务必保持 false
        'debug'    => false,
        // 站点地址，留空表示自动识别
        'url'      => '',
        // 站点加密盐，安装向导会自动生成随机值，请勿泄露
        'salt'     => '请替换为随机字符串',
        'timezone' => 'Asia/Shanghai',
    ],

    'database' => [
        'host'     => '127.0.0.1',
        'port'     => 3306,
        'name'     => 'mes_kb',
        'user'     => 'mes_kb',
        'password' => '',
        'charset'  => 'utf8mb4',
    ],
];
