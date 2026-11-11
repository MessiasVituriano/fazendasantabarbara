<?php

namespace App\Notifications;

use App\Services\ServicoSenha;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Link para criar uma nova senha, pedido na tela de login por quem tem e-mail cadastrado. */
class LinkNovaSenha extends Notification
{
    use Queueable;

    public function __construct(public string $link) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Criar uma nova senha')
            ->greeting("Olá, {$notifiable->name}!")
            ->line('Recebemos um pedido para criar uma nova senha para o seu acesso.')
            ->action('Criar nova senha', $this->link)
            ->line('O link vale por '.ServicoSenha::HORAS_VALIDADE.' horas e só pode ser usado uma vez. Se não foi você, ignore este e-mail.');
    }
}
