<?php

namespace App\Http\Controllers\Super;

use App\Http\Controllers\Controller;
use App\Http\Requests\Super\PreviewMailTextRequest;
use App\Http\Requests\Super\UpdateMailTextRequest;
use App\Models\PlatformMailText;
use App\Services\Mail\MailTextCatalog;
use App\Services\Mail\MailTextPreviewRenderer;
use App\Services\Mail\MailTextStore;
use App\Support\Mail\MailText;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The words of every system email (SLO-246, docs/27 §5): slot4u's own mails,
 * and the base text of the customer mails for every tenant without its own
 * override. Edited in the platform locale; audited like the mail design.
 */
class MailTextController extends Controller
{
    public function __construct(
        private readonly MailTextCatalog $catalog,
        private readonly MailTextStore $store,
    ) {}

    public function index(): Response
    {
        Gate::authorize('manage', PlatformMailText::class);

        $locale = $this->locale();

        $mails = array_map(function (string $key) use ($locale): array {
            $default = $this->catalog->default($key);
            $stored = $this->store->stored($key, $locale);

            return [
                'key' => $key,
                'group' => $this->catalog->group($key),
                'has_outro' => $this->catalog->hasOutro($key),
                'has_button' => $this->catalog->hasButton($key),
                'variables' => $this->catalog->variables($key),
                'default' => $this->text($default),
                'stored' => $stored === null ? null : $this->text($stored),
            ];
        }, $this->catalog->keys());

        return Inertia::render('Super/MailTexts/Index', [
            'mails' => $mails,
            'locale' => $locale,
        ]);
    }

    public function update(UpdateMailTextRequest $request, string $key): RedirectResponse
    {
        $this->store->save($key, $this->locale(), $request->mailText());

        return back()->with('status', __('app.super.mail_texts.saved'));
    }

    public function destroy(string $key): RedirectResponse
    {
        Gate::authorize('manage', PlatformMailText::class);
        abort_unless($this->catalog->has($key), 404);

        $this->store->reset($key, $this->locale());

        return back()->with('status', __('app.super.mail_texts.reset_done'));
    }

    public function preview(PreviewMailTextRequest $request, MailTextPreviewRenderer $renderer, string $key): JsonResponse
    {
        return response()->json($renderer->render($key, $request->mailText()));
    }

    /**
     * The platform's own locale. Tenant mails render in the tenant's locale and
     * find no row for any other, so they fall back to that locale's lang default.
     */
    private function locale(): string
    {
        return (string) config('app.locale');
    }

    /**
     * @return array{subject: string, greeting: string, body: string, action_label: string|null, outro: string|null}
     */
    private function text(MailText $text): array
    {
        return [
            'subject' => $text->subject,
            'greeting' => $text->greeting,
            'body' => $text->body,
            'action_label' => $text->actionLabel,
            'outro' => $text->outro,
        ];
    }
}
