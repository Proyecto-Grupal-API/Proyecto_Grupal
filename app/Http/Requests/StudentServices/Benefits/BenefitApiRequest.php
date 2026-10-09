<?php

namespace App\Http\Requests\StudentServices\Benefits;

use App\Http\Controllers\StudentServices\Benefits\ApiResponse;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Base de las peticiones de la API de beneficios: la autorización ya la
 * hizo el token de servicio, y los errores de validación se devuelven en
 * el formato acordado (codigo/mensaje/correlacion_id) con HTTP 422.
 */
abstract class BenefitApiRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(ApiResponse::error(
            $this,
            'validacion',
            (string) $validator->errors()->first(),
            422,
            ['errores' => $validator->errors()->toArray()]
        ));
    }
}
