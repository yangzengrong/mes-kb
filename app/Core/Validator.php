<?php
declare(strict_types=1);

namespace App\Core;

/**
 * 轻量表单校验器。
 *
 * 用法：
 *   $v = new Validator($_POST);
 *   $v->rules([
 *       'title' => 'required|maxlen:120',
 *       'status' => 'in:pending,published,offline,expired',
 *   ], ['title' => '故障标题']);
 *   if (!$v->passes()) { $errors = $v->errors(); }
 */
class Validator
{
    /** @var array<string,mixed> */
    private $data;

    /** @var array<string,string> 字段 => 中文名 */
    private $labels = [];

    /** @var array<string,string> */
    private $errors = [];

    /**
     * @param array<string,mixed> $data
     */
    public function __construct(array $data)
    {
        $this->data = $data;
    }

    /**
     * @param array<string,string> $rules  字段 => 规则串
     * @param array<string,string> $labels 字段 => 中文名
     */
    public function rules(array $rules, array $labels = []): self
    {
        $this->labels = $labels;

        foreach ($rules as $field => $ruleString) {
            $value = $this->data[$field] ?? null;
            foreach (explode('|', $ruleString) as $rule) {
                if ($rule === '') {
                    continue;
                }
                $params = [];
                if (strpos($rule, ':') !== false) {
                    [$rule, $paramString] = explode(':', $rule, 2);
                    $params = explode(',', $paramString);
                }
                $this->apply($field, (string) $rule, $params, $value);
                if (isset($this->errors[$field])) {
                    break; // 同一字段只保留第一条错误
                }
            }
        }
        return $this;
    }

    /**
     * @param array<int,string> $params
     * @param mixed             $value
     */
    private function apply(string $field, string $rule, array $params, $value): void
    {
        $label = $this->labels[$field] ?? $field;
        $text  = is_scalar($value) ? trim((string) $value) : '';

        switch ($rule) {
            case 'required':
                if ($text === '') {
                    $this->fail($field, $label . '不能为空。');
                }
                break;

            case 'maxlen':
                $limit = (int) ($params[0] ?? 255);
                if ($text !== '' && mb_strlen($text, 'UTF-8') > $limit) {
                    $this->fail($field, $label . '长度不能超过 ' . $limit . ' 个字符。');
                }
                break;

            case 'minlen':
                $limit = (int) ($params[0] ?? 0);
                if ($text !== '' && mb_strlen($text, 'UTF-8') < $limit) {
                    $this->fail($field, $label . '长度不能少于 ' . $limit . ' 个字符。');
                }
                break;

            case 'int':
                if ($text !== '' && !ctype_digit($text)) {
                    $this->fail($field, $label . '必须是整数。');
                }
                break;

            case 'in':
                if ($text !== '' && !in_array($text, $params, true)) {
                    $this->fail($field, $label . '取值不合法。');
                }
                break;

            case 'date':
                if ($text !== '' && strtotime($text) === false) {
                    $this->fail($field, $label . '不是有效的日期格式。');
                }
                break;

            case 'username':
                if ($text !== '' && !preg_match('/^[A-Za-z0-9_]{3,20}$/', $text)) {
                    $this->fail($field, $label . '只能是 3-20 位字母、数字或下划线。');
                }
                break;

            case 'password':
                if ($text !== '' && mb_strlen($text, 'UTF-8') < 6) {
                    $this->fail($field, $label . '长度不能少于 6 位。');
                }
                break;

            default:
                break;
        }
    }

    private function fail(string $field, string $message): void
    {
        if (!isset($this->errors[$field])) {
            $this->errors[$field] = $message;
        }
    }

    public function passes(): bool
    {
        return $this->errors === [];
    }

    /**
     * @return array<string,string>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    public function firstError(): string
    {
        foreach ($this->errors as $message) {
            return $message;
        }
        return '';
    }
}
