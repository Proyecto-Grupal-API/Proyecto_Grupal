<?php
namespace App\Http\Requests;
use Illuminate\Foundation\Http\FormRequest;

class ReturnRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array
    {
        return [
            'business_id'=>'required|string|max:64',
            'product_id'=>'required|string|max:64',
            'variant_id'=>'nullable|string|max:64',
            'location_id'=>'required|string|max:64',
            'quantity'=>'required|integer|min:1|max:100000',
            'reason'=>'required|string|max:250',
            'resolution'=>'required|string|in:RESTOCK,QUARANTINE,REPLACE,REFUND',
            'reference'=>'nullable|string|max:100',
        ];
    }
}
