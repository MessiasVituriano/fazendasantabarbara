<?php

namespace App\Notifications;

use App\Models\Convite;
use App\Services\ServicoConvites;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Convite enviado por e-mail (quando o convidado tem e-mail); o mesmo link também pode ir pelo WhatsApp. */
class ConviteRecebido extends Notification
{
    use Queueable;

    public function __construct(public Convite $convite, public string $link) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $fazenda = $this->convite->fazenda->nome;

        return (new MailMessage)
            ->subject("Convite para acessar a {$fazenda}")
            ->greeting("Olá, {$this->convite->nome}!")
            ->line("Você foi convidado(a) para acessar a {$fazenda} como {$this->convite->perfil->label()}.")
            ->action('Aceitar o convite', $this->link)
            ->line('O link vale por '.ServicoConvites::DIAS_VALIDADE.' dias e só pode ser usado uma vez.');
    }
}
