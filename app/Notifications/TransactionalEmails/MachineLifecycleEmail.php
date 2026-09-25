<?php

namespace App\Notifications\TransactionalEmails;

use App\Models\User;
use App\Notifications\Channels\TransactionalEmailChannel;
use App\Notifications\CustomEmailNotification;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * A transactional email about a team's machines: a stop for an unpaid plan, or the countdown to
 * deletion after a plan ended. Sent to the team's admins through the instance's own mail settings.
 */
class MachineLifecycleEmail extends CustomEmailNotification
{
    public function via(): array
    {
        return [TransactionalEmailChannel::class];
    }

    /**
     * @param  list<string>  $lines
     */
    public function __construct(
        public string $subject,
        public array $lines,
    ) {
        $this->onQueue('high');
    }

    public function toMail(User $notifiable): MailMessage
    {
        $mail = new MailMessage;
        $mail->subject($this->subject);
        foreach ($this->lines as $line) {
            $mail->line($line);
        }

        return $mail;
    }
}
