<?php

declare(strict_types=1);

use App\Models\User;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;
use Laravel\Socialite\Two\User as SocialiteUser;
use Relaticle\EmailIntegration\Controllers\CallbackController;
use Relaticle\EmailIntegration\Controllers\RedirectController;
use Relaticle\EmailIntegration\Enums\SyncErrorReason;
use Relaticle\EmailIntegration\Filament\Pages\EmailAccountsPage;
use Relaticle\EmailIntegration\Jobs\InitialCalendarSyncJob;
use Relaticle\EmailIntegration\Models\ConnectedAccount;

mutates(CallbackController::class);

beforeEach(function (): void {
    $this->user = User::factory()->withWorkspace()->create();
    $this->actingAs($this->user);
    Filament::setTenant($this->user->currentWorkspace);
});

it('redirects with a flashed error when Socialite throws InvalidStateException', function (): void {
    Socialite::shouldReceive('driver')->andReturnSelf();
    Socialite::shouldReceive('user')->andThrow(new InvalidStateException);

    $response = $this->get(route('email-accounts.callback', ['provider' => 'gmail']));

    $response->assertRedirect(EmailAccountsPage::getUrl([
        'tenant' => $this->user->currentWorkspace->slug,
    ], panel: 'app'));
    $response->assertSessionHas('error', 'Your sign-in session expired. Please reconnect the account.');
});

it('does not connect a mailbox when the authorizing workspace is missing from the session', function (): void {
    Bus::fake();

    $social = new SocialiteUser;
    $social->id = 'gmail-missing-workspace';
    $social->email = 'missing-workspace@example.com';
    $social->name = 'Demo';
    $social->token = 'access-token';
    $social->refreshToken = 'refresh-token';
    $social->expiresIn = 3600;
    $social->approvedScopes = [
        'https://www.googleapis.com/auth/gmail.readonly',
        'https://www.googleapis.com/auth/gmail.send',
    ];

    Socialite::fake('gmail', $social);

    $this->get(route('email-accounts.callback', ['provider' => 'gmail']))
        ->assertRedirect(EmailAccountsPage::getUrl([
            'tenant' => $this->user->currentWorkspace->slug,
        ], panel: 'app'))
        ->assertSessionHas('error', 'Your sign-in session expired. Please reconnect the account.');

    $this->assertDatabaseMissing(ConnectedAccount::class, [
        'email_address' => 'missing-workspace@example.com',
    ]);
});

it('redirects with a generic error when Socialite throws any other exception', function (): void {
    Socialite::shouldReceive('driver')->andReturnSelf();
    Socialite::shouldReceive('user')->andThrow(new RuntimeException('boom'));

    $response = $this->get(route('email-accounts.callback', ['provider' => 'gmail']));

    $response->assertRedirect();
    $response->assertSessionHas('error', 'We could not connect that account. Please try again.');
});

it('tells an owner who cancels at the provider that nothing was connected', function (): void {
    bindMailboxOAuthWorkspace($this->user);
    session()->put(RedirectController::RETURN_URL_SESSION_KEY, 'https://relaticle.test/app/acme/setup');
    Log::spy();

    $this->get(route('email-accounts.callback', ['provider' => 'gmail', 'error' => 'access_denied']))
        ->assertRedirect('https://relaticle.test/app/acme/setup');

    Notification::assertNotified(
        Notification::make()->title(__('filament/pages/email-accounts.notifications.cancelled.title'))->warning(),
    );

    Log::shouldNotHaveReceived('error');
});

it('returns to the page that started the connect with a success notification', function (): void {
    Bus::fake();

    Socialite::fake('gmail', connectedGmailUser());
    bindMailboxOAuthWorkspace($this->user);
    session()->put(RedirectController::RETURN_URL_SESSION_KEY, 'https://relaticle.test/app/acme/email');

    $this->get(route('email-accounts.callback', ['provider' => 'gmail']))
        ->assertRedirect('https://relaticle.test/app/acme/email');

    Notification::assertNotified(__('filament/pages/email-accounts.notifications.connected.title'));

    expect(session()->has(RedirectController::RETURN_URL_SESSION_KEY))->toBeFalse();
});

it('returns a failed connect to the page that started it', function (): void {
    bindMailboxOAuthWorkspace($this->user);
    session()->put(RedirectController::RETURN_URL_SESSION_KEY, 'https://relaticle.test/app/acme/setup');

    Socialite::shouldReceive('driver')->andReturnSelf();
    Socialite::shouldReceive('user')->andThrow(new InvalidStateException);

    $this->get(route('email-accounts.callback', ['provider' => 'gmail']))
        ->assertRedirect('https://relaticle.test/app/acme/setup')
        ->assertSessionMissing('error');

    Notification::assertNotified(
        Notification::make()->title('Your sign-in session expired. Please reconnect the account.')->danger(),
    );

    expect(session()->has(RedirectController::RETURN_URL_SESSION_KEY))->toBeFalse();
});

it('returns to the page that started the connect when the mail read permission was declined', function (): void {
    Bus::fake();

    $social = connectedGmailUser();
    $social->approvedScopes = ['openid', 'https://www.googleapis.com/auth/gmail.send'];

    Socialite::fake('gmail', $social);
    bindMailboxOAuthWorkspace($this->user);
    session()->put(RedirectController::RETURN_URL_SESSION_KEY, 'https://relaticle.test/app/acme/setup');

    $this->get(route('email-accounts.callback', ['provider' => 'gmail']))
        ->assertRedirect('https://relaticle.test/app/acme/setup')
        ->assertSessionMissing('error');

    Notification::assertNotified(
        Notification::make()->title('Relaticle needs permission to read your mail. Reconnect and allow every permission.')->danger(),
    );

    expect(session()->has(RedirectController::RETURN_URL_SESSION_KEY))->toBeFalse();
});

it('lands on the account settings after a connect with no return page', function (): void {
    Bus::fake();

    Socialite::fake('gmail', connectedGmailUser());
    bindMailboxOAuthWorkspace($this->user);

    $this->get(route('email-accounts.callback', ['provider' => 'gmail']))
        ->assertRedirect(EmailAccountsPage::getUrl([
            'tenant' => $this->user->currentWorkspace->slug,
        ], panel: 'app'));

    Notification::assertNotified(__('filament/pages/email-accounts.notifications.connected.title'));
});

it('does not connect a gmail mailbox when the mail read permission was not granted', function (): void {
    Bus::fake();

    $social = connectedGmailUser();
    $social->approvedScopes = [
        'openid',
        'https://www.googleapis.com/auth/userinfo.email',
        'https://www.googleapis.com/auth/gmail.send',
    ];

    Socialite::fake('gmail', $social);
    bindMailboxOAuthWorkspace($this->user);

    $this->get(route('email-accounts.callback', ['provider' => 'gmail']))
        ->assertRedirect(EmailAccountsPage::getUrl([
            'tenant' => $this->user->currentWorkspace->slug,
        ], panel: 'app'))
        ->assertSessionHas('error', 'Relaticle needs permission to read your mail. Reconnect and allow every permission.');

    $this->assertDatabaseMissing(ConnectedAccount::class, [
        'email_address' => 'connected@example.com',
    ]);

    Bus::assertNothingDispatched();
});

it('refuses to connect a google account whose workspace has gmail switched off', function (): void {
    Bus::fake();
    fakeGoogleProbe(mail: googleError(400, 'failedPrecondition', 'Mail service not enabled'));

    Socialite::fake('gmail', connectedGmailUser());
    bindMailboxOAuthWorkspace($this->user);

    $this->get(route('email-accounts.callback', ['provider' => 'gmail']))
        ->assertRedirect(EmailAccountsPage::getUrl([
            'tenant' => $this->user->currentWorkspace->slug,
        ], panel: 'app'))
        ->assertSessionHas('error', SyncErrorReason::GoogleMailDisabled->getLabel());

    $this->assertDatabaseMissing(ConnectedAccount::class, [
        'email_address' => 'connected@example.com',
    ]);

    Bus::assertNothingDispatched();
});

it('connects without calendar when the user has no google calendar', function (): void {
    Bus::fake();
    fakeGoogleProbe(calendar: googleError(403, 'notACalendarUser', 'The user must be signed up for Google Calendar.'));

    $social = connectedGmailUser();
    $social->approvedScopes[] = 'https://www.googleapis.com/auth/calendar.readonly';

    Socialite::fake('gmail', $social);
    bindMailboxOAuthWorkspace($this->user);

    $this->get(route('email-accounts.callback', ['provider' => 'gmail']))->assertRedirect();

    $account = ConnectedAccount::query()->where('email_address', 'connected@example.com')->firstOrFail();

    expect($account->hasCalendar())->toBeFalse();

    Bus::assertNotDispatched(InitialCalendarSyncJob::class);

    Notification::assertNotified(
        Notification::make()->title(__('filament/pages/email-accounts.sync_error.reasons.google_calendar_disabled'))->warning(),
    );
});

it('does not warn about calendar when the user never granted calendar access', function (): void {
    Bus::fake();
    fakeGoogleProbe(calendar: googleError(403, 'notACalendarUser', 'The user must be signed up for Google Calendar.'));

    Socialite::fake('gmail', connectedGmailUser());
    bindMailboxOAuthWorkspace($this->user);

    $this->get(route('email-accounts.callback', ['provider' => 'gmail']))->assertRedirect();

    Notification::assertNotNotified(
        Notification::make()->title(__('filament/pages/email-accounts.sync_error.reasons.google_calendar_disabled'))->warning(),
    );
});

it('connects with both when the probe fails transiently', function (): void {
    Bus::fake();
    fakeGoogleProbe(
        mail: googleError(503, 'backendError', 'Backend Error'),
        calendar: googleError(403, 'rateLimitExceeded', 'Rate Limit Exceeded'),
    );

    $social = connectedGmailUser();
    $social->approvedScopes[] = 'https://www.googleapis.com/auth/calendar.readonly';

    Socialite::fake('gmail', $social);
    bindMailboxOAuthWorkspace($this->user);

    $this->get(route('email-accounts.callback', ['provider' => 'gmail']))->assertRedirect();

    $account = ConnectedAccount::query()->where('email_address', 'connected@example.com')->firstOrFail();

    expect($account->hasCalendar())->toBeTrue();

    Bus::assertDispatched(InitialCalendarSyncJob::class);

    Notification::assertNotNotified(
        Notification::make()->title(__('filament/pages/email-accounts.sync_error.reasons.google_calendar_disabled'))->warning(),
    );
});

it('probes gmail and calendar before connecting a google account', function (): void {
    Bus::fake();
    fakeGoogleProbe();

    Socialite::fake('gmail', connectedGmailUser());
    bindMailboxOAuthWorkspace($this->user);

    $this->get(route('email-accounts.callback', ['provider' => 'gmail']))->assertRedirect();

    Http::assertSent(fn ($request): bool => str_contains($request->url(), 'gmail.googleapis.com/gmail/v1/users/me/profile'));
    Http::assertSent(fn ($request): bool => str_contains($request->url(), 'calendar/v3/users/me/calendarList')
        && str_contains($request->url(), 'maxResults=1'));
});

it('does not probe google services for a microsoft account', function (): void {
    Bus::fake();

    Http::fake();

    $social = connectedGmailUser();
    $social->id = 'azure-connected';
    $social->email = 'azure@example.com';
    $social->approvedScopes = ['https://graph.microsoft.com/Mail.Read'];

    Socialite::fake('azure', $social);
    bindMailboxOAuthWorkspace($this->user);

    $this->get(route('email-accounts.callback', ['provider' => 'azure']))->assertRedirect();

    $this->assertDatabaseHas(ConnectedAccount::class, ['email_address' => 'azure@example.com']);

    Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'googleapis.com'));
});

function googleError(int $status, string $reason, string $message): PromiseInterface
{
    return Http::response([
        'error' => [
            'code' => $status,
            'message' => $message,
            'errors' => [['message' => $message, 'domain' => 'global', 'reason' => $reason]],
        ],
    ], $status);
}

function fakeGoogleProbe(?PromiseInterface $mail = null, ?PromiseInterface $calendar = null): void
{
    Http::fake([
        'gmail.googleapis.com/gmail/v1/users/me/profile*' => $mail ?? Http::response(['emailAddress' => 'connected@example.com']),
        'www.googleapis.com/calendar/v3/users/me/calendarList*' => $calendar ?? Http::response(['items' => []]),
    ]);
}

function connectedGmailUser(): SocialiteUser
{
    $social = new SocialiteUser;
    $social->id = 'gmail-connected';
    $social->email = 'connected@example.com';
    $social->name = 'Demo';
    $social->token = 'access-token';
    $social->refreshToken = 'refresh-token';
    $social->expiresIn = 3600;
    $social->approvedScopes = [
        'https://www.googleapis.com/auth/gmail.readonly',
        'https://www.googleapis.com/auth/gmail.send',
    ];

    return $social;
}
