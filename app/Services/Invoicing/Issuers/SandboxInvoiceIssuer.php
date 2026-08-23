<?php

declare(strict_types=1);

namespace App\Services\Invoicing\Issuers;

use App\Enums\InvoiceProvider;
use App\Services\Invoicing\Contracts\InvoiceIssuer;
use App\Services\Invoicing\InvoiceRequest;
use App\Services\Invoicing\IssuedInvoice;
use App\Services\Invoicing\StornoRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * The built-in test issuer (SLO-133): mints a number and a minimal PDF locally so
 * the whole issue → storno → download flow is demoable and testable without an
 * external invoicing account. Billingo (SLO-167) is the first real adapter
 * behind the same contract.
 *
 * The document it produces is a real (if spartan) one-page PDF — enough for the
 * download path, the storage layout and the UI to be exercised end to end.
 */
final class SandboxInvoiceIssuer implements InvoiceIssuer
{
    public function provider(): InvoiceProvider
    {
        return InvoiceProvider::Sandbox;
    }

    public function issue(InvoiceRequest $request): IssuedInvoice
    {
        $number = 'SBX-'.Carbon::now()->format('Y').'-'.Str::upper(Str::random(6));

        return new IssuedInvoice(
            number: $number,
            providerRef: 'sbxi_'.Str::lower(Str::random(24)),
            pdf: $this->pdf([
                'SZAMLA (sandbox) '.$number,
                'Kiallito: '.($request->seller->sellerName ?? '-'),
                'Vevo: '.$request->buyerName,
                'Tetel: '.$request->itemName,
                'Osszeg: '.number_format($request->amountMinor / 100, 2, '.', ' ').' '.$request->currency,
                'Kelt: '.$request->issueDate,
            ]),
        );
    }

    public function storno(StornoRequest $request): IssuedInvoice
    {
        $number = 'SBX-ST-'.Str::upper(Str::random(6));

        return new IssuedInvoice(
            number: $number,
            providerRef: 'sbxs_'.Str::lower(Str::random(24)),
            pdf: $this->pdf([
                'STORNO SZAMLA (sandbox) '.$number,
                'Sztornozott szamla: '.((string) $request->number),
                'Osszeg: '.number_format($request->amountMinor / 100, 2, '.', ' ').' '.$request->currency,
                'Kelt: '.Carbon::now()->toDateString(),
            ]),
        );
    }

    /**
     * A minimal, valid single-page PDF with the given lines. Hand-built rather
     * than pulled from a rendering library: this is a stand-in for a document the
     * real provider returns, so it is not worth a dependency.
     *
     * @param  list<string>  $lines
     */
    private function pdf(array $lines): string
    {
        $text = '';
        $y = 780;
        foreach ($lines as $line) {
            // Escape the PDF string delimiters; the content is ASCII by construction.
            $escaped = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $line);
            $text .= "BT /F1 12 Tf 60 {$y} Td ({$escaped}) Tj ET\n";
            $y -= 20;
        }

        $objects = [
            "1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n",
            "2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n",
            "3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>\nendobj\n",
            "4 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>\nendobj\n",
            "5 0 obj\n<< /Length ".strlen($text)." >>\nstream\n{$text}endstream\nendobj\n",
        ];

        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $object) {
            $offsets[] = strlen($pdf);
            $pdf .= $object;
        }

        $xrefOffset = strlen($pdf);
        $pdf .= 'xref'."\n".'0 '.(count($objects) + 1)."\n".'0000000000 65535 f '."\n";
        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }
        $pdf .= 'trailer'."\n".'<< /Size '.(count($objects) + 1).' /Root 1 0 R >>'."\n".'startxref'."\n".$xrefOffset."\n".'%%EOF';

        return $pdf;
    }
}
