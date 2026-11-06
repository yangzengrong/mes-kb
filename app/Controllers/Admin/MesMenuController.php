<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Request;
use App\Core\Validator;
use App\Core\XlsxReader;
use App\Models\MesMenu;
use App\Models\PinyinTerm;

/**
 * 菜单权限库管理：MES 开发商新增大板块/子板块时，第一时间手动录入知识库，
 * 保持与 MES 系统菜单对称，供前台 /permissions 授权前检索。
 *
 * 另提供 xlsx 批量「合并导入」：开发商大改版后一次性补充清单，
 * 只补入缺失行，后台手工录入/编辑过的数据一律保留。
 */
class MesMenuController extends BaseController
{
    /** 优先读取的工作表名（与《系统菜单明细MES系统权限.xlsx》一致） */
    private const PREFERRED_SHEET = '按层级浏览';

    /** 导入草稿保留时长（秒），超时自动清理 */
    private const DRAFT_TTL = 7200;
    public function index(): void
    {
        $this->guard('category.view');

        $keyword = trim((string) Request::get('keyword', ''));
        $module  = trim((string) Request::get('module', ''));
        $page    = max(1, Request::int('page', 1));

        $errors = $_SESSION['_errors'] ?? [];
        $old    = $_SESSION['_old'] ?? [];
        unset($_SESSION['_errors'], $_SESSION['_old']);

        $this->render('admin/menus', [
            'activeMenu' => 'menus',
            'result'     => MesMenu::adminSearch($keyword, $module, $page, 20),
            'modules'    => MesMenu::modules(),
            'roleNames'  => array_column(MesMenu::roles(), 'role'),
            'keyword'    => $keyword,
            'module'     => $module,
            'errors'     => $errors,
            'old'        => $old,
            'isSuper'    => Auth::instance()->isSuperAdmin(),
            'import'     => $this->readImportDraft(),
        ], 'MES菜单权限库');
    }

    /**
     * xlsx 批量导入 · 第一步：解析上传文件并给出预检结果（不写库）。
     */
    public function importPreview(): void
    {
        $this->guardSuper();
        $this->guardPost();

        $file = $_FILES['menu_file'] ?? null;
        if (!is_array($file) || (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $this->back('menus.php', 'error', '请选择要导入的 xlsx 文件（上传失败或未选择文件）。');
        }

        $tmpName = (string) ($file['tmp_name'] ?? '');
        if ($tmpName === '' || !is_uploaded_file($tmpName)) {
            $this->back('menus.php', 'error', '上传文件校验失败，请重新选择文件。');
        }

        $sizeMb = (int) ceil(((int) ($file['size'] ?? 0)) / 1048576);
        if ($sizeMb > 10) {
            $this->back('menus.php', 'error', 'xlsx 文件过大（超过 10MB），请精简后再导入。');
        }

        $origName = (string) ($file['name'] ?? 'menu.xlsx');
        if (strtolower((string) pathinfo($origName, PATHINFO_EXTENSION)) !== 'xlsx') {
            $this->back('menus.php', 'error', '仅支持 .xlsx 格式；老式 .xls 请先用 Excel 另存为 .xlsx。');
        }

        try {
            $parsed = XlsxReader::read($tmpName, self::PREFERRED_SHEET);
        } catch (\Throwable $e) {
            $this->back('menus.php', 'error', '解析失败：' . $e->getMessage());
        }

        $rows = $this->normalizeRows($parsed['rows']);
        if ($rows === []) {
            $this->back('menus.php', 'error', '未从文件中解析出有效数据行，请确认表格首行是表头、随后是「一级菜单 / 二 / 三 / 四级 / 编码 / 角色」各列。');
        }

        $preview = MesMenu::previewImport($rows);

        $token = bin2hex(random_bytes(8));
        $draft = [
            'token'      => $token,
            'file'       => $origName,
            'sheet'      => (string) $parsed['sheet'],
            'parsed'     => count($rows),
            'new'        => (int) $preview['new'],
            'dup'        => (int) $preview['dup'],
            'newRows'    => array_slice($preview['newRows'], 0, 50),
            'created_at' => now(),
            'rows'       => $rows,
        ];

        $this->writeImportDraft($token, $draft);

        $this->back('menus.php', 'success',
            '解析完成：共 ' . count($rows) . ' 行，其中可新增 ' . (int) $preview['new']
            . ' 条、已存在 ' . (int) $preview['dup'] . ' 条。请在页面下方确认导入。',
            ['import' => $token]);
    }

    /**
     * xlsx 批量导入 · 第二步：确认写入（合并导入，不覆盖手工数据）。
     */
    public function importConfirm(): void
    {
        $this->guardSuper();
        $this->guardPost();

        $token = (string) Request::post('token', '');
        $draft = $this->readImportDraft($token);

        if ($draft === null) {
            $this->back('menus.php', 'error', '导入草稿已失效（服务器临时文件被清理），请重新上传文件。');
        }

        /** @var array<int,array<string,mixed>> $rows */
        $rows   = is_array($draft['rows'] ?? null) ? $draft['rows'] : [];
        $result = MesMenu::mergeImport($rows);
        if ((int) $result['inserted'] > 0) {
            PinyinTerm::flush();
        }

        $this->deleteImportDraft($token);

        $this->log('mes_menu.import', 'mes_menu', null,
            'xlsx 批量导入：' . (string) ($draft['file'] ?? '') . '（工作表：' . (string) ($draft['sheet'] ?? '')
            . '，新增 ' . (int) $result['inserted'] . ' 条，跳过已存在 ' . (int) $result['skipped'] . ' 条）');

        $this->back('menus.php', 'success',
            '导入完成：新增 ' . (int) $result['inserted'] . ' 条，跳过已存在 ' . (int) $result['skipped']
            . ' 条；后台手工录入的数据未被改动。');
    }

    /**
     * 取消导入：清理草稿。
     */
    public function importCancel(): void
    {
        $this->guardSuper();
        $this->guardPost();

        $token = (string) Request::post('token', '');
        $this->deleteImportDraft($token);
        $this->back('menus.php', 'success', '已取消本次导入，未改动任何数据。');
    }

    public function save(): void
    {
        $this->guardSuper();
        $this->guardPost();

        $id = Request::int('id');

        $fields = [
            'module'    => trim((string) Request::post('module', '')),
            'menu_l2'   => trim((string) Request::post('menu_l2', '')),
            'menu_l3'   => trim((string) Request::post('menu_l3', '')),
            'menu_l4'   => trim((string) Request::post('menu_l4', '')),
            'menu_name' => trim((string) Request::post('menu_name', '')),
            'menu_code' => trim((string) Request::post('menu_code', '')),
            'role_name' => trim((string) Request::post('role_name', '')),
        ];

        $validator = new Validator($fields);
        $validator->rules([
            'module'    => 'required|maxlen:60',
            'menu_l2'   => 'maxlen:120',
            'menu_l3'   => 'maxlen:120',
            'menu_l4'   => 'maxlen:120',
            'menu_name' => 'required|maxlen:120',
            'menu_code' => 'maxlen:60',
            'role_name' => 'maxlen:60',
        ], [
            'module'    => '所属系统/一级板块',
            'menu_l2'   => '二级菜单',
            'menu_l3'   => '三级菜单',
            'menu_l4'   => '四级菜单',
            'menu_name' => '叶子菜单名',
            'menu_code' => '菜单编码',
            'role_name' => '角色权限',
        ]);
        if (!$validator->passes()) {
            $this->backWithErrors($_POST, $validator->errors(), 'menus.php');
        }

        // 叶子菜单名：未单独填写时自动取最深层级名
        if ($fields['menu_name'] === '') {
            foreach (['menu_l4', 'menu_l3', 'menu_l2'] as $key) {
                if ($fields[$key] !== '') {
                    $fields['menu_name'] = $fields[$key];
                    break;
                }
            }
        }

        if (MesMenu::isDuplicate($fields, $id)) {
            $this->backWithErrors($_POST, ['menu_name' => '已存在完全相同的菜单记录，请勿重复录入。'], 'menus.php');
        }

        if ($id > 0) {
            $existing = MesMenu::find($id);
            if ($existing === null) {
                $this->back('menus.php', 'error', '菜单记录不存在。');
            }
            // url 列为历史遗留数据，表单不再维护，编辑时原样保留
            $fields['url'] = (string) $existing['url'];
            MesMenu::modify($id, $fields);
            $this->log('mes_menu.update', 'mes_menu', $id,
                '更新菜单权限：' . $this->label($fields));
            PinyinTerm::flush();
            $this->back('menus.php', 'success', '菜单「' . $fields['menu_name'] . '」已更新。');
        }

        $fields['url'] = '';
        $newId = MesMenu::create($fields);
        $this->log('mes_menu.create', 'mes_menu', $newId,
            '新增菜单权限：' . $this->label($fields));
        PinyinTerm::flush();
        $this->back('menus.php', 'success', '菜单「' . $fields['menu_name'] . '」已录入。');
    }

    public function delete(): void
    {
        $this->guardSuper();
        $this->guardPost();

        $id   = Request::int('id');
        $menu = MesMenu::find($id);

        if ($menu === null) {
            $this->back('menus.php', 'error', '菜单记录不存在。');
        }

        Database::execute('DELETE FROM mes_menus WHERE id = :id', ['id' => $id]);
        $this->log('mes_menu.delete', 'mes_menu', $id,
            '删除菜单权限：' . $this->label($menu));
        PinyinTerm::flush();
        $this->back('menus.php', 'success', '菜单「' . (string) $menu['menu_name'] . '」已删除。');
    }

    /**
     * 日志用简洁路径标识。
     *
     * @param array<string,mixed> $fields
     */
    private function label(array $fields): string
    {
        $parts = [(string) $fields['module']];
        foreach (['menu_l2', 'menu_l3', 'menu_l4', 'menu_name'] as $key) {
            $v = (string) ($fields[$key] ?? '');
            if ($v !== '' && !in_array($v, $parts, true)) {
                $parts[] = $v;
            }
        }
        return implode(' › ', $parts);
    }

    // =========================================================
    // xlsx 批量导入辅助
    // =========================================================

    /**
     * 归一化解析结果：识别表头列顺序、跳过空行、计算叶子菜单名。
     *
     * @param array<int,array<int,string>> $rows
     * @return array<int,array<string,mixed>>
     */
    private function normalizeRows(array $rows): array
    {
        // 默认列顺序（与现有 xlsx 一致）：一级 / 二级 / 三级 / 四级 / 编码 / 角色 / 地址
        $map = ['module' => 0, 'menu_l2' => 1, 'menu_l3' => 2, 'menu_l4' => 3, 'menu_code' => 4, 'role_name' => 5, 'url' => 6];
        $start = 0;

        if ($rows !== [] && $this->looksLikeHeader($rows[0])) {
            $start = 1;
            $detected = [];
            foreach ($rows[0] as $index => $cell) {
                $cell = (string) $cell;
                foreach ([
                    'module'    => ['一级', '模块', '所属系统', '系统'],
                    'menu_l2'   => ['二级'],
                    'menu_l3'   => ['三级'],
                    'menu_l4'   => ['四级'],
                    'menu_code' => ['编码', '编号'],
                    'role_name' => ['角色', '权限'],
                    'url'       => ['地址', 'url', 'URL', '页面', '链接'],
                ] as $field => $needles) {
                    if (isset($detected[$field])) {
                        continue;
                    }
                    foreach ($needles as $needle) {
                        if (mb_stripos($cell, $needle) !== false) {
                            $detected[$field] = $index;
                            break;
                        }
                    }
                }
            }
            // 至少识别出 2 列才采用表头映射，否则退回默认顺序
            if (count($detected) >= 2) {
                $map = array_merge($map, $detected);
            }
        }

        $limit  = ['module' => 60, 'menu_l2' => 120, 'menu_l3' => 120, 'menu_l4' => 120, 'menu_code' => 60, 'role_name' => 60, 'url' => 255];
        $result = [];

        for ($i = $start; $i < count($rows); $i++) {
            $row   = $rows[$i];
            $item  = [];
            $empty = true;

            foreach ($map as $field => $index) {
                $value = trim((string) ($row[$index] ?? ''));
                if (mb_strlen($value, 'UTF-8') > $limit[$field]) {
                    $value = mb_substr($value, 0, $limit[$field], 'UTF-8');
                }
                $item[$field] = $value;
                if ($value !== '') {
                    $empty = false;
                }
            }

            if ($empty || $item['module'] === '') {
                continue; // 空行或缺少一级菜单的行直接跳过
            }

            $item['menu_name'] = $item['menu_l4'] !== '' ? $item['menu_l4']
                : ($item['menu_l3'] !== '' ? $item['menu_l3']
                : ($item['menu_l2'] !== '' ? $item['menu_l2'] : $item['module']));

            $result[] = $item;
        }

        return $result;
    }

    /**
     * 首行是否表头（含层级/编码/角色等字样）。
     *
     * @param array<int,string> $row
     */
    private function looksLikeHeader(array $row): bool
    {
        $joined = implode('|', array_map('strval', $row));
        foreach (['一级', '二级', '三级', '四级', '菜单', '编码', '角色'] as $needle) {
            if (mb_stripos($joined, $needle) !== false) {
                return true;
            }
        }
        return false;
    }

    /**
     * 草稿目录（storage/tmp，Web 不可访问）。
     */
    private function draftDir(): string
    {
        $dir = STORAGE_PATH . '/tmp';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        return $dir;
    }

    /**
     * @param array<string,mixed> $data
     */
    private function writeImportDraft(string $token, array $data): void
    {
        $dir = $this->draftDir();

        // 顺手清理过期草稿，避免长期堆积
        foreach (glob($dir . '/menu_import_*.json') ?: [] as $file) {
            if (filemtime($file) < time() - self::DRAFT_TTL) {
                @unlink($file);
            }
        }

        @file_put_contents($dir . '/menu_import_' . $token . '.json',
            (string) json_encode($data, JSON_UNESCAPED_UNICODE));
    }

    /**
     * 读取导入草稿（不传 token 时取最近一份，用于刷新后仍显示预检结果）。
     *
     * @return array<string,mixed>|null
     */
    private function readImportDraft(string $token = ''): ?array
    {
        if ($token === '') {
            $token = (string) Request::get('import', '');
        }
        if (!preg_match('/^[a-f0-9]{16}$/', $token)) {
            return null;
        }

        $file = $this->draftDir() . '/menu_import_' . $token . '.json';
        if (!is_file($file)) {
            return null;
        }
        if (filemtime($file) < time() - self::DRAFT_TTL) {
            @unlink($file);
            return null;
        }

        $decoded = json_decode((string) file_get_contents($file), true);
        return is_array($decoded) ? $decoded : null;
    }

    private function deleteImportDraft(string $token): void
    {
        if (!preg_match('/^[a-f0-9]{16}$/', $token)) {
            return;
        }
        @unlink($this->draftDir() . '/menu_import_' . $token . '.json');
    }
}
