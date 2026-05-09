<?php

namespace Pterodactyl\Http\Requests\Admin\Nodes;

use Illuminate\Foundation\Http\FormRequest;

class StartAutoConfigureRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'target_host' => ['required', 'string', 'max:191'],
            'target_port' => ['nullable', 'integer', 'between:1,65535'],
            'target_username' => ['nullable', 'string', 'max:64'],
            'bootstrap_password' => ['required', 'string', 'min:1', 'max:4096'],
            'wings_port' => ['nullable', 'integer', 'between:1,65535'],
            'fallback_port_range' => ['nullable', 'string', 'regex:/^[0-9\-,\s]+$/'],
            'host_key_policy' => ['nullable', 'in:strict_tofu,strict_pinned'],
            'reconfigure_mode' => ['nullable', 'in:install,reconfigure'],
            'firewall_mode' => ['nullable', 'in:auto,minimal'],
        ];
    }
}
