<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Enums\Permission;
use App\Models\Scopes\TenantScope;
use App\Models\TenantDomain;
use App\Tenancy\DomainName;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation for claiming a custom domain (SLO-42).
 *
 * The submitted host is canonicalised BEFORE the rules run, so uniqueness is
 * decided on the same shape the unique index and the request resolver use —
 * otherwise `WWW.Acme.hu.` would pass a uniqueness check against a stored
 * `www.acme.hu` and then collide at insert time.
 */
class TenantDomainRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can(Permission::SettingsEdit->value);
    }

    protected function prepareForValidation(): void
    {
        $input = $this->input('domain');

        if (is_string($input)) {
            $this->merge(['domain' => DomainName::normalize($input) ?? $input]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'domain' => ['required', 'string', 'max:253'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $domain = $this->input('domain');

            if (! is_string($domain) || ! DomainName::isValid($domain)) {
                $validator->errors()->add('domain', __('validation.custom.custom_domain.invalid'));

                return;
            }

            if (DomainName::isCentral($domain)) {
                $validator->errors()->add('domain', __('validation.custom.custom_domain.reserved'));

                return;
            }

            // Unscoped on purpose: a host taken by ANOTHER tenant must be
            // rejected too, and the tenant-scoped query could not see it. The
            // message stays deliberately vague — which tenant holds it is not
            // this tenant's business (docs/01 cross-tenant probing).
            $taken = TenantDomain::query()
                ->withoutGlobalScope(TenantScope::class)
                ->where('domain', $domain)
                ->exists();

            if ($taken) {
                $validator->errors()->add('domain', __('validation.custom.custom_domain.taken'));
            }
        });
    }
}
