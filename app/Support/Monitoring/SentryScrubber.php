<?php

namespace App\Support\Monitoring;

use App\Tenancy\TenantManager;
use Sentry\Breadcrumb;
use Sentry\Event;
use Sentry\EventHint;
use Sentry\UserDataBag;
use Throwable;

/**
 * The last thing every Sentry event passes through (SLO-153, docs/17).
 *
 * The issue's rule is "tenant id and user id yes, customer content no". The SDK's
 * own `send_default_pii` switch is not enough for that: it governs IP addresses,
 * cookies and auth headers, while this app's personal data is the guest name,
 * email and phone on every public booking — carried in request bodies, log lines
 * and exception messages.
 *
 * So this class is the enforcement point, and it works by *rebuilding* the parts
 * of the event that can carry content rather than by listing fields to remove:
 * a new field on a future event is dropped by default instead of shipped by
 * omission.
 *
 * Wired as `[SentryScrubber::class, 'send']` rather than a closure because
 * `config:cache` can serialise the array form and cannot serialise a closure —
 * a closure here would break every deploy at the cache step.
 */
final class SentryScrubber
{
    /**
     * `before_send`. Never returns null: dropping the event would be a stronger
     * action than this class is for — an error the team never sees is worse than
     * an error whose message has been redacted.
     */
    public static function send(Event $event, ?EventHint $hint = null): Event
    {
        return (new self)->scrub($event);
    }

    /**
     * `before_breadcrumb` — every breadcrumb, whoever added it.
     */
    public static function breadcrumb(Breadcrumb $breadcrumb, ?EventHint $hint = null): Breadcrumb
    {
        return (new self)->scrubBreadcrumb($breadcrumb);
    }

    public function scrub(Event $event): Event
    {
        $this->scrubRequest($event);
        $this->scrubUser($event);
        $this->scrubExceptions($event);
        $this->scrubText($event);
        $this->tagTenant($event);

        $event->setBreadcrumb(array_map(
            fn (Breadcrumb $breadcrumb) => $this->scrubBreadcrumb($breadcrumb),
            $event->getBreadcrumbs()
        ));

        return $event;
    }

    public function scrubBreadcrumb(Breadcrumb $breadcrumb): Breadcrumb
    {
        $message = $breadcrumb->getMessage();

        if ($message !== null) {
            $breadcrumb = $breadcrumb->withMessage(PiiRedactor::text($message));
        }

        foreach ($breadcrumb->getMetadata() as $key => $value) {
            if (is_string($value)) {
                $breadcrumb = $breadcrumb->withMetadata($key, PiiRedactor::text($value));
            } elseif (is_array($value)) {
                $breadcrumb = $breadcrumb->withMetadata($key, PiiRedactor::deep($value));
            }
        }

        return $breadcrumb;
    }

    /**
     * Which URL failed is the single most useful field for triage, so it stays —
     * redacted, because a filter can carry a search term. Everything else the
     * request interface can hold (body, query, cookies, headers, server env) is
     * dropped: all of it is either customer content or a credential.
     */
    private function scrubRequest(Event $event): void
    {
        $request = $event->getRequest();

        if ($request === []) {
            return;
        }

        $kept = [];

        if (isset($request['url']) && is_string($request['url'])) {
            $kept['url'] = PiiRedactor::text($request['url']);
        }

        if (isset($request['method']) && is_string($request['method'])) {
            $kept['method'] = $request['method'];
        }

        $event->setRequest($kept);
    }

    /**
     * An id says which account hit the bug; a name and an email say who the
     * person is. Only the id (and the tenant it belongs to) survives — rebuilt
     * from scratch, so a username or IP the SDK attached cannot ride along.
     */
    private function scrubUser(Event $event): void
    {
        $user = $event->getUser();

        if ($user === null) {
            return;
        }

        $id = $user->getId();

        if ($id === null) {
            $event->setUser(null);

            return;
        }

        $event->setUser(UserDataBag::createFromUserIdentifier($id));
    }

    private function scrubExceptions(Event $event): void
    {
        foreach ($event->getExceptions() as $exception) {
            $exception->setValue(PiiRedactor::text($exception->getValue()));
        }
    }

    private function scrubText(Event $event): void
    {
        $message = $event->getMessage();

        if ($message !== null) {
            $event->setMessage(PiiRedactor::text($message), [], null);
        }

        $extra = $event->getExtra();

        if ($extra !== []) {
            $event->setExtra(PiiRedactor::deep($extra));
        }

        foreach ($event->getContexts() as $name => $context) {
            if ($context !== []) {
                $event->setContext($name, PiiRedactor::deep($context));
            }
        }
    }

    /**
     * Which tenant was being served. This is the one piece of context the issue
     * explicitly wants, and it is what turns "some 500s" into "this customer's
     * booking page is down".
     */
    private function tagTenant(Event $event): void
    {
        try {
            $tenant = app(TenantManager::class)->current();
        } catch (Throwable) {
            // Resolving the container can fail late in a crash; a missing tag is
            // never a reason to lose the error report itself.
            return;
        }

        if ($tenant === null) {
            return;
        }

        $event->setTag('tenant_id', (string) $tenant->id);
        $event->setTag('tenant', (string) $tenant->slug);
    }
}
