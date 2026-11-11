<?php

namespace App\Services;

/** Links do painel (convite, nova senha) e de compartilhamento pelo WhatsApp. */
class Links
{
    public static function painel(string $caminho): string
    {
        return rtrim((string) config('pecuaria.url_painel'), '/').$caminho;
    }

    /**
     * Abre o WhatsApp com a mensagem pronta. Com telefone, já na conversa da pessoa (DDI 55 quando faltar);
     * sem telefone, quem envia escolhe o contato.
     */
    public static function whatsapp(?string $telefone, string $mensagem): string
    {
        $digitos = preg_replace('/\D/', '', (string) $telefone);
        if ($digitos !== '' && strlen($digitos) <= 11) {
            $digitos = '55'.$digitos;
        }

        return 'https://wa.me/'.$digitos.'?text='.rawurlencode($mensagem);
    }
}
