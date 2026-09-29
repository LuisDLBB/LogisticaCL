<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PurchaseOrdersMail extends Mailable
{
    use Queueable, SerializesModels;

    /** @param array<int, array{0: string, 1: string, 2: string}> $files */
    public function __construct(public string $mailSubject, public string $messageText, public array $files) {}

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address('proveedores@4nlogistica.cl', '4N Logística · Proveedores'),
            subject: $this->mailSubject,
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            text: 'emails.purchase-orders',
            with: ['messageText' => $this->messageText],
        );
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        return array_map(
            fn (array $file): Attachment => Attachment::fromData(fn (): string => $file[1], $file[0])->withMime($file[2]),
            $this->files,
        );
    }
}
