<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

/**
 * 更新项目请求校验
 *
 * 校验规则与 Api\ProjectController::update 中的 $this->validate(...) 完全等价。
 */
class UpdateProjectRequest extends FormRequest
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
            'name'               => 'required|between:1,100',
            'description'        => 'max:255',
            'visibility'         => 'required|in:1,2',
            'sort_level'         => 'integer|between:-9999999999,999999999',
            'catalog'            => 'required|integer',
            'catalog_sort_style' => 'in:0,1',
            'catalog_fold_style' => 'in:0,1,2',
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
