<?php

namespace Database\Seeders;

use App\Enums\LegalDocumentType;
use App\Models\LegalDocument;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Seeds the platform's first terms and privacy notice (SLO-161).
 *
 * ⚠️ These are PLACEHOLDERS, and they say so on their face. The text of a terms
 * of service and of a privacy notice has to be written by a lawyer; slot4u does
 * not give legal advice, and a plausible-sounding draft would be worse than an
 * obvious stub, because nobody would notice it needed replacing.
 *
 * What is real here is the machinery: without a version in force, sign-up asks
 * for no acceptance at all, which would leave art. 7(1) undemonstrable from the
 * platform's very first tenant. The superadmin panel publishes the lawyer's text
 * as a new version, and every tenant admin is walked through re-acceptance.
 *
 * Like the commission seeder, this only ever creates the baseline: legal
 * documents are versioned, and a seeder that overwrote one would rewrite a text
 * people have already accepted.
 */
class LegalDocumentSeeder extends Seeder
{
    public function run(): void
    {
        if (LegalDocument::query()->platform()->exists()) {
            return;
        }

        $effectiveFrom = Carbon::parse('2020-01-01T00:00:00Z');

        LegalDocument::query()->create([
            'tenant_id' => null,
            'type' => LegalDocumentType::Terms,
            'version' => '0.1-draft',
            'title' => 'Általános szerződési feltételek (előzetes)',
            'body' => $this->placeholder('Általános szerződési feltételek'),
            'effective_from' => $effectiveFrom,
        ]);

        LegalDocument::query()->create([
            'tenant_id' => null,
            'type' => LegalDocumentType::Privacy,
            'version' => '0.1-draft',
            'title' => 'Adatkezelési tájékoztató (előzetes)',
            'body' => $this->placeholder('Adatkezelési tájékoztató'),
            'effective_from' => $effectiveFrom,
        ]);
    }

    private function placeholder(string $name): string
    {
        return <<<TEXT
        # {$name}

        ⚠️ **Ez egy előzetes, jogilag nem véglegesített szöveg.** A slot4u
        működéséhez szükséges technikai keret elkészült; a végleges tartalmat
        ügyvéd készíti el, és a superadmin felületen új verzióként kerül
        közzétételre. Az új verzió közzétételekor minden meglévő felhasználó
        újra-elfogadásra kerül átirányításra.

        A slot4u többbérlős (multi-tenant) foglalási rendszer. A rendszert
        használó cégek (bérlők) a saját ügyfeleik adatai tekintetében
        **adatkezelőnek** minősülnek, a slot4u pedig **adatfeldolgozóként** jár
        el az utasításaik szerint. A bérlő ügyfelei felé a bérlő saját
        adatkezelési tájékoztatója az irányadó.
        TEXT;
    }
}
