<?php

namespace Pterodactyl\Http\Requests\Admin\Nodes;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StartAutoConfigureRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'target_host' => ['required', 'string', 'max:191', 'regex:/^[A-Za-z0-9\.\-\:\[\]]+$/'],
            'target_port' => ['nullable', 'integer', 'between:1,65535'],
            'target_username' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9_\-\.]+$/'],
            'bootstrap_password' => ['required', 'string', 'min:1', 'max:4096'],
            'wings_port' => ['nullable', 'integer', 'between:1,65535'],
            'fallback_port_range' => ['nullable', 'string', 'regex:/^[0-9\-,\s]+$/'],
            'host_key_policy' => ['nullable', 'in:strict_pinned'],
            'host_fingerprint' => ['nullable', 'string', 'max:512', 'regex:/^[A-Za-z0-9+\/=:\-]+$/'],
            'reconfigure_mode' => ['nullable', 'in:install,reconfigure'],
            'firewall_mode' => ['nullable', 'in:auto,minimal'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $policy = (string) $this->input('host_key_policy', 'strict_pinned');
            $fingerprint = trim((string) $this->input('host_fingerprint', ''));
            if ($policy !== 'strict_pinned') {
                $validator->errors()->add('host_key_policy', 'Password bootstrap requires strict_pinned host key verification.');
            }
            if ($fingerprint === '') {
                $validator->errors()->add('host_fingerprint', 'host_fingerprint is required before password bootstrap.');
            }
        });
    }
}
