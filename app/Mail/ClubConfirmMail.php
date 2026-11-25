<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class ClubConfirmMail extends Mailable
{
    public function __construct(public string $confirmUrl, public string $unsubscribeUrl) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'تأیید عضویت در باشگاه وب پیانو | Confirm your Web Piano Club sign-up');
    }

    public function content(): Content
    {
        return new Content(view: 'emails.club-confirm');
    }
}