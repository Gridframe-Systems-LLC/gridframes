<?php

use App\Exceptions\NonReportableException;
use App\Models\Team;
use App\Models\User;
use App\Notifications\Channels\EmailChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Psr\Http\Client\ClientExceptionInterface;
use Resend\Exceptions\ErrorException;
use Resend\Exceptions\TransporterException;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

// The channel's catch blocks map Resend SDK exceptions no matter where in the
// send pipeline they surface, so these tests throw the real exception classes
// from toMail() — the last call before the Resend/SMTP branches. Resend::client()
// is a plain static factory the app boots eagerly (no container seam), so it
// cannot be mocked; the error-mapping stays under test without any network.

final class ResendFailureNotification extends Notification
{
    public bool $isTransactionalEmail = false;

    public ?string $emails = null;

    public bool $isTestNotification = false;

    public function __construct(private Throwable $throwable) {}

    public function toMail($notifiable): MailMessage
    {
        throw $this->throwable;
    }
}

final class PlainMailNotification extends Notification
{
    public bool $isTransactionalEmail = false;

    public ?string $emails = null;

    public bool $isTestNotification = false;

    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)->subject('Test Email')->line('Test body');
    }
}

beforeEach(function () {
    seedInstanceSettings();

    $this->team = Team::factory()->create();
    $this->member = User::factory()->create(['email' => 'member@example.com']);
    $this->team->members()->attach($this->member->id, ['role' => 'owner']);

    // Team::booted() already created a settings row; app.env=local makes it
    // default to instance settings, so point it back at team-level settings
    // with both senders disabled.
    $this->settings = $this->team->emailNotificationSettings;
    $this->settings->update([
        'use_instance_email_settings' => false,
        'smtp_enabled' => false,
        'resend_enabled' => false,
    ]);
});

it('throws user-friendly error for invalid Resend API key (403)', function () {
    $notification = new ResendFailureNotification(new ErrorException([
        'message' => 'API key is invalid.',
        'type' => 'invalid_api_key',
        'code' => 403,
    ]));

    $channel = new EmailChannel;

    expect(fn () => $channel->send($this->team, $notification))
        ->toThrow(
            NonReportableException::class,
            'Invalid Resend API key. Please verify your API key in the Resend dashboard and update it in settings.'
        );
});

it('throws user-friendly error for restricted Resend API key (401)', function () {
    $notification = new ResendFailureNotification(new ErrorException([
        'message' => 'This API key is restricted to only send emails.',
        'type' => 'restricted_api_key',
        'code' => 401,
    ]));

    $channel = new EmailChannel;

    expect(fn () => $channel->send($this->team, $notification))
        ->toThrow(
            NonReportableException::class,
            'Your Resend API key has restricted permissions. Please use an API key with Full Access permissions.'
        );
});

it('throws user-friendly error for rate limiting (429)', function () {
    $notification = new ResendFailureNotification(new ErrorException([
        'message' => 'Too many requests.',
        'type' => 'rate_limit_exceeded',
        'code' => 429,
    ]));

    $channel = new EmailChannel;

    expect(fn () => $channel->send($this->team, $notification))
        ->toThrow(Exception::class, 'Resend rate limit exceeded. Please try again in a few minutes.');
});

it('throws user-friendly error for validation errors (400)', function () {
    $notification = new ResendFailureNotification(new ErrorException([
        'message' => 'Invalid email format.',
        'type' => 'validation_error',
        'code' => 400,
    ]));

    $channel = new EmailChannel;

    expect(fn () => $channel->send($this->team, $notification))
        ->toThrow(NonReportableException::class, 'Email validation failed: Invalid email format.');
});

it('throws generic error with message for unknown error codes', function () {
    $notification = new ResendFailureNotification(new ErrorException([
        'message' => 'Internal server error.',
        'type' => 'internal_server_error',
        'code' => 500,
    ]));

    $channel = new EmailChannel;

    expect(fn () => $channel->send($this->team, $notification))
        ->toThrow(Exception::class, 'Failed to send email via Resend: Internal server error.');
});

it('throws user-friendly error for network/transport errors', function () {
    $notification = new ResendFailureNotification(new TransporterException(
        new class('Network error') extends Exception implements ClientExceptionInterface {}
    ));

    $channel = new EmailChannel;

    expect(fn () => $channel->send($this->team, $notification))
        ->toThrow(Exception::class, 'Unable to connect to Resend API. Please check your internet connection and try again.');
});

it('rejects recipients that are not part of the team', function () {
    $notification = new PlainMailNotification;
    $notification->emails = 'intruder@example.com';

    $channel = new EmailChannel;

    expect(fn () => $channel->send($this->team, $notification))
        ->toThrow(Exception::class, 'Recipient is not part of the team');
});

it('fails when the team has no valid email recipients', function () {
    $this->member->update(['email' => 'not-an-email']);

    $channel = new EmailChannel;

    expect(fn () => $channel->send($this->team, new PlainMailNotification))
        ->toThrow(Exception::class, 'No email recipients found');
});

it('sends nothing without error when both email senders are disabled', function () {
    $channel = new EmailChannel;

    expect(fn () => $channel->send($this->team, new PlainMailNotification))->not->toThrow(Exception::class);
});
