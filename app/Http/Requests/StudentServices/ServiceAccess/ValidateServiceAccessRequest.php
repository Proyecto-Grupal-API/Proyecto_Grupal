<?php

namespace App\Http\Requests\StudentServices\ServiceAccess;

use App\Services\StudentServices\ServiceAccess\ServiceAccessService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ValidateServiceAccessRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $service = (string) $this->input('service');

        return [
            'credential' => ['required', 'string', 'max:120'],
            'method' => ['required', Rule::in(ServiceAccessService::METHODS)],
            'service' => ['required', Rule::in(array_keys(ServiceAccessService::OPERATIONS))],
            'action' => ['required', Rule::in(ServiceAccessService::OPERATIONS[$service] ?? [])],
            'reference' => ['nullable', 'string', 'max:80'],
            'condition' => ['nullable', Rule::in(ServiceAccessService::RENTAL_CONDITIONS)],
        ];
    }

    public function messages(): array
    {
        return [
            'credential.required' => 'Escanea o captura la credencial del estudiante.',
            'action.in' => 'La operación no corresponde al servicio seleccionado.',
        ];
    }
}
