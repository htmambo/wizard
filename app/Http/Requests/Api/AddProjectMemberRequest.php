<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 添加项目成员请求校验
 *
 * 校验规则与 Api\ProjectController::addMember 中的 $this->validate(...) 完全等价。
 */
class AddProjectMemberRequest extends FormRequest
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
            'group_id'  => 'required|integer|min:1|exists:groups,id',
            'privilege' => 'in:wr,r',
        ];
    }
}
