<?php
namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReserveStockRequest extends FormRequest
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
            'source'=>'required|string|in:CHECKOUT,REWARD,CANJE,ORDER',
            'external_reference'=>'nullable|string|max:100',
            'expires_at'=>'required|date',
            'idempotency_key'=>'required|string|max:100|regex:/^[A-Za-z0-9._:-]+$/',
        ];
    }
}
