<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Linha da trilha de auditoria. Somente inclusão: nunca é alterada nem excluída pela aplicação. */
class HistoricoAlteracao extends Model
{
    protected $table = 'historico_alteracoes';

    public const UPDATED_AT = null;

    protected $fillable = ['auditavel_type', 'auditavel_id', 'acao', 'user_id', 'antes', 'depois'];

    protected function casts(): array
    {
        return ['antes' => 'array', 'depois' => 'array'];
    }

    public function usuario(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
