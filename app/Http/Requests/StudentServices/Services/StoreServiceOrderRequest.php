<?php

namespace App\Http\Requests\StudentServices\Services;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreServiceOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $serviceType = $this->input(
            'service_type'
        );

        return [
            'service_type' => [
                'required',
                'string',
                Rule::in([
                    'printing',
                    'copy',
                    'scanning',
                    'binding',
                ]),
            ],

            'quantity' => [
                'required',
                'integer',
                'min:1',
                'max:1000',
            ],

            'color_mode' => [
                Rule::requiredIf(
                    in_array(
                        $serviceType,
                        [
                            'printing',
                            'copy',
                            'scanning',
                        ],
                        true
                    )
                ),
                'nullable',
                Rule::in([
                    'bw',
                    'color',
                ]),
            ],

            'paper_size' => [
                Rule::requiredIf(
                    in_array(
                        $serviceType,
                        [
                            'printing',
                            'copy',
                            'scanning',
                        ],
                        true
                    )
                ),
                'nullable',
                Rule::in([
                    'Carta',
                    'Oficio',
                    'A4',
                ]),
            ],

            'sides' => [
                Rule::requiredIf(
                    in_array(
                        $serviceType,
                        [
                            'printing',
                            'copy',
                        ],
                        true
                    )
                ),
                'nullable',
                Rule::in([
                    'single',
                    'double',
                ]),
            ],

            'observations' => [
                'nullable',
                'string',
                'max:1000',
            ],

            'file' => [
                Rule::requiredIf(
                    $serviceType ===
                    'printing'
                ),
                'nullable',
                'file',
                'mimes:pdf,doc,docx,png,jpg,jpeg',
                'max:10240',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'service_type.required' =>
                'Selecciona el tipo de servicio.',

            'service_type.in' =>
                'El tipo de servicio seleccionado no es válido.',

            'quantity.required' =>
                'La cantidad es obligatoria.',

            'quantity.integer' =>
                'La cantidad debe ser un número entero.',

            'quantity.min' =>
                'La cantidad debe ser mayor a cero.',

            'color_mode.required' =>
                'Selecciona el modo de color.',

            'color_mode.in' =>
                'El modo de color seleccionado no es válido.',

            'paper_size.required' =>
                'Selecciona el tamaño de papel.',

            'paper_size.in' =>
                'El tamaño de papel seleccionado no es válido.',

            'sides.required' =>
                'Selecciona la configuración de caras.',

            'sides.in' =>
                'La configuración de caras no es válida.',

            'file.required' =>
                'Debes seleccionar el archivo que deseas imprimir.',

            'file.file' =>
                'El archivo seleccionado no es válido.',

            'file.mimes' =>
                'El archivo debe ser PDF, DOC, DOCX, PNG, JPG o JPEG.',

            'file.max' =>
                'El archivo no puede superar los 10 MB.',
        ];
    }
}
