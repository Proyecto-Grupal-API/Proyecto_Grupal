<?php

namespace App\Http\Requests\StudentServices\Benefits;

class BenefitAvailabilityRequest extends BenefitApiRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'beneficio_id' => ['required', 'string', 'max:40'],
            'inicio' => ['nullable', 'date'],
            'fin' => ['nullable', 'date', 'after:inicio'],
            'cantidad' => ['nullable', 'integer', 'min:1'],
            'zona' => ['nullable', 'string', 'max:60'],
            'edificio' => ['nullable', 'string', 'max:60'],
            'campus' => ['nullable', 'string', 'max:60'],
        ];
    }
}
