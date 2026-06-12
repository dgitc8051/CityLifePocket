<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateEmployeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'sometimes|required|string|max:64',
            'phone' => 'nullable|string|max:32',
            'birthday' => 'nullable|date',
            'line_user_id' => 'nullable|string|max:64',
            'skill_score' => 'sometimes|required|integer|min:1|max:10',
            'level' => 'sometimes|required|in:trainee,junior,senior,lead',
            'system_role' => 'nullable|in:owner,manager,sub_manager,staff',
            'permission_template_id' => 'nullable|integer|exists:permission_templates,id',
            'permissions_json' => 'nullable|array',
            'permissions_json.*' => 'in:rw,r,none',
            'make_admin' => 'nullable|boolean',
            'employment_type' => 'sometimes|required|in:full,part,intern',
            'hire_date' => 'nullable|date',
            'leave_date' => 'nullable|date',
            'status' => 'sometimes|required|in:active,leave,terminated',
            'weekly_max_hours' => 'nullable|integer|min:1|max:168',
            'weekly_min_hours' => 'nullable|integer|min:0|max:168',
            'daily_max_hours' => 'nullable|integer|min:1|max:24',
            'hourly_wage' => 'nullable|integer|min:0|max:10000',
            'monthly_salary' => 'nullable|integer|min:0|max:9999999',
            'notes' => 'nullable|string|max:1000',
            'station_ids' => 'nullable|array',
            'station_ids.*' => 'integer|exists:stations,id',
        ];
    }
}
