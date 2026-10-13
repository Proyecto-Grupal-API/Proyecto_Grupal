<?php

namespace App\Http\Requests\StudentServices\ServiceAccess;

use App\Services\StudentServices\Payments\Money;
use App\Services\StudentServices\ServiceAccess\ServiceAccessService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ValidateServiceAccessRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
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
            'damage_charge' => ['nullable', 'numeric', 'min:0', 'max:100000', 'decimal:0,2'],
        ];
    }

    /**
     * Datos validados con los tipos que espera ServiceAccessService::validate().
     *
     * @return array{credential: string, method: string, service: string, action: string, reference?: string|null, condition?: string|null, damage_charge_cents?: int|null}
     */
    public function accessData(): array
    {
        return [
            'credential' => $this->string('credential')->value(),
            'method' => $this->string('method')->value(),
            'service' => $this->string('service')->value(),
            'action' => $this->string('action')->value(),
            'reference' => $this->filled('reference') ? $this->string('reference')->value() : null,
            'condition' => $this->filled('condition') ? $this->string('condition')->value() : null,
            'damage_charge_cents' => $this->filled('damage_charge') ? Money::toCents($this->string('damage_charge')->value()) : null,
        ];
    }

    public function messages(): array
    {
        return [
            'credential.required' => 'Escanea o captura la credencial del estudiante.',
            'action.in' => 'La operación no corresponde al servicio seleccionado.',
            'damage_charge.numeric' => 'El cargo por daños debe ser un número.',
            'damage_charge.min' => 'El cargo por daños no puede ser negativo.',
            'damage_charge.max' => 'El cargo por daños es demasiado alto.',
            'damage_charge.decimal' => 'El cargo por daños admite máximo 2 decimales.',
        ];
    }
}
