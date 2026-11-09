<?php

namespace App\Http\Requests;

use App\Services\FinancialAuthorizationService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class FinancialAuthorizationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Transport authorization is performed by both OAuth middleware layers.
    }

    public function rules(): array
    {
        return [
            'subject_id' => ['required', 'string', 'max:100', 'regex:/^[^\x00-\x20\x7f]+$/'],
            'bonus_type' => ['required', Rule::in(array_keys(FinancialAuthorizationService::BONUS_CAPABILITIES))],
            'operation' => ['required', Rule::in(['issue'])],
            'scope_type' => ['present', 'nullable', Rule::in(['business'])],
            'scope_id' => ['present', 'nullable', 'string', 'max:100', 'regex:/^[^\x00-\x20\x7f]+$/'],
            'role' => ['prohibited'], 'role_key' => ['prohibited'], 'roles' => ['prohibited'],
            'capability' => ['prohibited'], 'membership' => ['prohibited'], 'actor_id' => ['prohibited'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $type = $this->input('scope_type');
            $id = $this->input('scope_id');
            if (($type === null) !== ($id === null)
                || ($type !== null && ! ($type === 'business' && $this->input('bonus_type') === 'NEGOCIO'))) {
                $validator->errors()->add('scope_type', 'Unsupported financial context.');
            }
        }];
    }
}
