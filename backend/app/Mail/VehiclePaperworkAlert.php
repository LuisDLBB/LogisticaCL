<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class VehiclePaperworkAlert extends Mailable
{
    public function __construct(public string $plate, public string $company, public array $document, public string $vehicleUrl) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->plate.' · '.($this->document['expired'] ? 'Documento vencido' : 'Documento por vencer'));
    }

    public function content(): Content
    {
        return new Content(view: 'fleet::vehicles.paperwork-mail');
    }
}
