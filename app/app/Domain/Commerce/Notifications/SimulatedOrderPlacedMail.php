<?php

declare(strict_types=1);

namespace App\Domain\Commerce\Notifications;

use App\Models\Order;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** 模擬訂單建立通知（純文字；不含訂單 token、Session 綁定或任何個資）。 */
final class SimulatedOrderPlacedMail extends Mailable
{
    public function __construct(public readonly Order $order) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: '【模擬】訂單 '.$this->order->displayNumber().' 已建立（不收款、不出貨）');
    }

    public function content(): Content
    {
        return new Content(text: 'shop.mail.order-placed');
    }
}
