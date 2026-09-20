<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 创建项目请求校验
 *
 * 校验规则与 Api\ProjectController::create 中的 $this->validate(...) 完全等价,
 * 抽取后由 Laravel 在路由层自动解析,校验失败抛出 ValidationException → 422。
 *
 * 授权由 Controller 在调用本 FormRequest 之前通过 Policy 完成,
 * 故 authorize() 直接返回 true。
 */
class CreateProjectRequest extends FormRequest
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
            'name'        => 'required|between:1,100',
            'description' => 'max:255',
            'visibility'  => 'required|in:1,2',
            'sort_level'  => 'integer|between:-9999999999,999999999',
            'catalog'     => 'required|integer',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required'   => __('project.validation.project_name_required'),
            'name.between'    => __('project.validation.project_name_between'),
            'description.max' => __('project.validation.project_description_max'),
        ];
    }
}
