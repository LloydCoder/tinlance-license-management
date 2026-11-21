<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

final class MembershipInvitationMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly string $acceptanceUrl,
        public readonly string $tenantName,
        public readonly string $roleName,
        public readonly string $membershipType,
        public readonly string $expiresAt,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'You are invited to join '.$this->tenantName.' in TLMS',
        );
    }

    public function content(): Content
    {
        return new Content(view: 'mail.membership-invitation');
    }
}
