<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 更新文档请求校验
 *
 * Api\DocumentController::update 当前未在方法内做 request->validate(),
 * 所有字段都被视作可选（如 title 仅在提供时更新,project_id 仅在变更时搬迁）。
 *
 * 这里建立最小化校验规则:仅对会触发下游迁移/重建的字段做边界检查,
 * 以便把字段约束收敛到 FormRequest 中、便于阅读与单测。
 */
class UpdateDocumentRequest extends FormRequest
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
            'title'      => 'sometimes|string|max:255',
            'project_id' => 'sometimes|integer',
            'tags'       => 'sometimes|string',
        ];
    }
}
