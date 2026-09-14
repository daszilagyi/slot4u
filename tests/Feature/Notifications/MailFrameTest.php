<?php

use App\Models\Tenant;
use App\Models\User;
use App\Notifications\MessageReceivedNotification;
use App\Support\Mail\MailBrand;
use App\Tenancy\TenantManager;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Str;

/*
 * The slot4u mail frame (SLO-244): every system email — slot4u's own and the
 * ones a tenant sends its customers — renders in one branded frame, in
 * Hungarian, coloured by MailBrand. The framework's two auth mails used to go
 * out in English with Laravel's default look.
 */

afterEach(function () {
    app(TenantManager::class)->forget();
});

/** The HTML exactly as the mail channel renders it. */
function mailFrameHtml(MailMessage $mail): string
{
    return (string) $mail->render();
}

/**
 * None of the framework layout's English may survive. One expectation per
 * string: `not->toContain(a, b, c)` passes as soon as ANY one is missing.
 */
function expectNoFrameworkEnglish(string $html): void
{
    foreach (['Hello!', 'Regards', 'All rights reserved', "If you're having trouble", 'Verify Email Address', 'Reset Password'] as $english) {
        expect($html)->not->toContain($english);
    }
}

it('sends the verification mail in Hungarian inside the slot4u frame', function () {
    $user = User::factory()->unverified()->create(['name' => 'Kiss Anna']);

    $mail = (new VerifyEmail)->toMail($user);
    $html = mailFrameHtml($mail);

    expect($mail->subject)->toBe(__('app.mail.verify_email.subject'))
        ->and($mail->actionText)->toBe(__('app.mail.verify_email.action'))
        ->and($html)->toContain('Szia Kiss Anna!')
        ->toContain(e(__('app.mail.verify_email.intro')))
        ->toContain('class="header"');
    expectNoFrameworkEnglish($html);
});

it('keeps the signed verification link the framework builds', function () {
    $user = User::factory()->unverified()->create();

    $url = (new VerifyEmail)->toMail($user)->actionUrl;

    expect($url)->toContain('/email/verify/'.$user->id.'/'.sha1($user->email))
        ->toContain('signature=');
});

it('sends the password reset mail in Hungarian with the framework reset link', function () {
    $user = User::factory()->create(['name' => 'Kiss Anna', 'email' => 'anna@example.test']);

    $mail = (new ResetPassword('token-123'))->toMail($user);

    expect($mail->subject)->toBe(__('app.mail.reset_password.subject'))
        ->and($mail->actionUrl)->toBe(url(route('password.reset', ['token' => 'token-123', 'email' => 'anna@example.test'], false)))
        ->and(mailFrameHtml($mail))->toContain('Szia Kiss Anna!');
    expectNoFrameworkEnglish(mailFrameHtml($mail));
});

it('signs slot4u mail as slot4u', function () {
    $user = User::factory()->unverified()->create();

    $html = mailFrameHtml((new VerifyEmail)->toMail($user));

    // The tenant sentence, cut at its placeholder: whatever the tenant is
    // called, a slot4u letter must not carry it.
    $sentByTenant = e(Str::before(__('app.mail.layout.sent_by_tenant'), ':tenant'));

    expect($html)->toMatch('/class="brand-name"[^>]*>'.preg_quote(config('app.name'), '/').'</')
        ->toContain(__('app.mail.layout.salutation').'<br>'."\n".config('app.name'))
        ->not->toContain($sentByTenant);
});

it('names the tenant in the header, the sign-off and the footer of a tenant mail', function () {
    $tenant = Tenant::factory()->active()->create(['slug' => 'glam', 'name' => 'GlamZone Szalon']);
    app(TenantManager::class)->set($tenant);
    $customer = User::factory()->create(['tenant_id' => $tenant->id]);

    $html = mailFrameHtml((new MessageReceivedNotification($tenant))->toMail($customer));

    // Header band: the tenant, not the slot4u wordmark.
    expect($html)->toMatch('/class="header"(?:(?!<\\/td>).)*GlamZone Szalon/s')
        ->toContain(__('app.mail.layout.salutation').'<br>')
        ->toContain(e(__('app.mail.layout.sent_by_tenant', ['tenant' => 'GlamZone Szalon', 'brand' => config('app.name')])));
    expectNoFrameworkEnglish($html);
});

it('colours the frame from MailBrand, so the superadmin brand settings can replace it', function () {
    // SLO-245 swaps this binding for the stored brand. If a colour were written
    // into a template instead, that swap would silently change nothing.
    $this->app->bind(MailBrand::class, fn () => new MailBrand(
        headerBackground: '#123456',
        headerText: '#FFFFFF',
        buttonBackground: '#ABCDEF',
        buttonText: '#000000',
        canvas: '#F5F7FA',
        surface: '#FFFFFF',
        ink: '#14212F',
        inkMuted: '#5B6B7C',
        link: '#1B4F72',
        line: '#DCE4EC',
        logoUrl: 'https://cdn.example.test/logo.png',
        footerText: 'Kft. · 1111 Budapest',
    ));

    $html = mailFrameHtml((new VerifyEmail)->toMail(User::factory()->unverified()->create()));

    expect($html)->toContain('background-color: #123456')
        ->toContain('background-color: #ABCDEF')
        ->toContain('src="https://cdn.example.test/logo.png"')
        ->toContain('Kft. · 1111 Budapest');
});

it('drops the logo rather than showing a broken image when there is none', function () {
    $this->app->bind(MailBrand::class, fn () => new MailBrand(
        ...array_merge(get_object_vars(MailBrand::defaults()), ['logoUrl' => null]),
    ));

    $html = mailFrameHtml((new VerifyEmail)->toMail(User::factory()->unverified()->create()));

    expect($html)->not->toContain('class="logo"')
        ->toContain('class="brand-name"');
});
