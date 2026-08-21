<?php

namespace App\Http\Requests\Admin;

use App\Enums\LegalDocumentType;
use App\Models\LegalDocument;
use App\Tenancy\TenantManager;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Publishing a new version of a legal document (SLO-161).
 *
 * Used by both the tenant panel and the superadmin one; the scope comes from the
 * bound tenant, which is null on the superadmin host. Authorisation is the
 * route's (`can:privacy.manage` / `ensure.superadmin`), not this class's.
 */
class LegalDocumentRequest extends FormRequest
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
        $tenantId = app(TenantManager::class)->id();

        return [
            'type' => ['required', Rule::enum(LegalDocumentType::class)],
            'version' => [
                'required', 'string', 'max:32',
                // Unique per scope and type. The platform side of this is the
                // only guard there is — see the migration's note on NULLs in a
                // unique index.
                Rule::unique(LegalDocument::class, 'version')
                    ->where(fn ($query) => $query
                        ->where('type', $this->input('type'))
                        ->when($tenantId === null,
                            fn ($query) => $query->whereNull('tenant_id'),
                            fn ($query) => $query->where('tenant_id', $tenantId))),
            ],
            'title' => ['required', 'string', 'max:255'],
            'body' => ['nullable', 'string', 'max:200000'],
            'url' => ['nullable', 'url', 'max:2048'],
            'effective_from' => ['required', 'date'],
        ];
    }

    /**
     * A document is a text or a link to it. Neither means an empty page people
     * are asked to accept, which is worse than asking nothing at all.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $hasBody = trim((string) $this->input('body')) !== '';
            $hasUrl = trim((string) $this->input('url')) !== '';

            if (! $hasBody && ! $hasUrl) {
                $validator->errors()->add('body', __('app.legal.admin.body_or_url'));
            }
        });
    }
}
