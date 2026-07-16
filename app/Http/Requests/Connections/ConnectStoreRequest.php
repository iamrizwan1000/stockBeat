<?php

namespace App\Http\Requests\Connections;

use App\Models\StoreConnection;
use Illuminate\Foundation\Http\FormRequest;

class ConnectStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = [
            'name' => ['required', 'string', 'max:255'],
        ];

        if ($this->route('platform') === StoreConnection::PLATFORM_WOO) {
            $rules['credentials.store_url'] = ['required', 'url'];
            $rules['credentials.consumer_key'] = ['required', 'string'];
            $rules['credentials.consumer_secret'] = ['required', 'string'];
        } else {
            $rules['credentials'] = ['sometimes', 'array'];
        }

        return $rules;
    }
}
