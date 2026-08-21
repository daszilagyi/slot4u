<?php

declare(strict_types=1);

namespace App\Actions\Legal;

use App\Enums\LegalDocumentType;
use App\Models\LegalDocument;
use App\Models\Tenant;
use App\Models\User;

/**
 * Publishes a new version of a document (SLO-161).
 *
 * One action for both scopes, because the rule that matters is the same in each:
 * a version is created, never edited. Two controllers writing their own version
 * of "create unless it exists" is how one of them eventually grows an update
 * path, and an updated text under a recorded acceptance is a forged record.
 */
class PublishLegalDocument
{
    /**
     * @param  array{type: string, version: string, title: string, body?: string|null, url?: string|null, effective_from: string}  $attributes
     * @param  Tenant|null  $tenant  null publishes a platform document
     */
    public function __invoke(array $attributes, ?Tenant $tenant, ?User $author = null): LegalDocument
    {
        $body = $this->blankToNull($attributes['body'] ?? null);
        $url = $this->blankToNull($attributes['url'] ?? null);

        return LegalDocument::create([
            'tenant_id' => $tenant?->getKey(),
            'type' => LegalDocumentType::from($attributes['type']),
            'version' => trim($attributes['version']),
            'title' => trim($attributes['title']),
            // A document is the text or a link to it, never a half-filled row
            // carrying both: two sources for one legal text is two texts.
            'body' => $url === null ? $body : null,
            'url' => $url,
            'effective_from' => $attributes['effective_from'],
            'created_by_id' => $author?->getKey(),
        ]);
    }

    private function blankToNull(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);

        return $value === '' ? null : $value;
    }
}
