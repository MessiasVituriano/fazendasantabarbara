<?php

namespace App\Enums;

enum FormaPagamento: string
{
    use Concerns;

    case DINHEIRO = 'DINHEIRO';
    case PIX = 'PIX';
    case TRANSFERENCIA = 'TRANSFERENCIA';
    case BOLETO = 'BOLETO';
    case CARTAO_DEBITO = 'CARTAO_DEBITO';
    case CARTAO_CREDITO = 'CARTAO_CREDITO';
    case CHEQUE = 'CHEQUE';
    case PROMISSORIA = 'PROMISSORIA';
    case OUTRO = 'OUTRO';

    public function label(): string
    {
        return match ($this) {
            self::DINHEIRO => 'Dinheiro',
            self::PIX => 'Pix',
            self::TRANSFERENCIA => 'Transferência/TED',
            self::BOLETO => 'Boleto',
            self::CARTAO_DEBITO => 'Cartão de débito',
            self::CARTAO_CREDITO => 'Cartão de crédito',
            self::CHEQUE => 'Cheque',
            self::PROMISSORIA => 'Nota promissória',
            self::OUTRO => 'Outro',
        };
    }
}
