<?php

namespace App\Notifications\TransactionalEmails;

use App\Notifications\Channels\TransactionalEmailChannel;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;

/**
 * Avail: tells a guest their access ends soon ('ending') or has ended ('ended').
 * Sent through the transactional email settings; does nothing until email is configured.
 */
class AvailGuestAccessNotice extends Notification
{
    public function __construct(
        public string $kind,
        public Carbon $expiresAt,
        public string $teamName,
        public array $projectNames = [],
    ) {}

    public function via($notifiable): array
    {
        return [TransactionalEmailChannel::class];
    }

    public function toMail($notifiable): MailMessage
    {
        $mail = new MailMessage;
        $date = $this->expiresAt->format('M j, Y');
        $mail->subject($this->kind === 'ended'
            ? 'AvailCoolify: your guest access has ended'
            : "AvailCoolify: your guest access ends on {$date}");
        $mail->view($this->kind === 'ended' ? 'emails.avail-guest-access-ended' : 'emails.avail-guest-access-ending', [
            'date' => $date,
            'team' => $this->teamName,
            'projects' => $this->projectNames,
            'url' => base_url(),
        ]);

        return $mail;
    }
}
