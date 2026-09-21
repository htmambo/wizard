<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 创建/上传文档请求校验
 *
 * 校验规则与 Api\DocumentController::create 中的 $this->validate(...) 完全等价。
 *
 * 注:DocumentController::create 没有显式 messages() 覆写,
 * 这里也不覆写,Laravel 会使用默认的字段名翻译。
 */
class CreateDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, string>
     */
    public function rules(): array
    {
        return [
            'title'   => 'required|string|max:255',
            'content' => 'required|string',
            'url'     => 'required|url',
            'format'  => 'in:html,markdown',
        ];
    }
}
