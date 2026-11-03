<?php

namespace App\Models\Concerns;

use App\Models\HistoricoAlteracao;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Facades\Auth;

/**
 * Grava em historico_alteracoes cada criação, alteração e exclusão do model,
 * com os campos alterados antes/depois. Para registrar uma ação com nome próprio
 * (BAIXA, ESTORNO...), defina $model->acaoAuditoria antes de salvar.
 */
trait Auditavel
{
    public ?string $acaoAuditoria = null;

    /** Informações extras gravadas junto do "depois" (ex.: motivo do estorno). */
    public array $extraAuditoria = [];

    /** Campos que não entram na trilha (ruído). */
    protected array $foraDaAuditoria = ['created_at', 'updated_at', 'atualizado_por', 'criado_por'];

    public static function bootAuditavel(): void
    {
        static::created(fn ($m) => $m->registrarAuditoria('CRIADO', null, $m->camposAuditaveis($m->getAttributes())));

        static::updated(function ($m) {
            $mudou = $m->camposAuditaveis($m->getChanges());
            if (! $mudou && ! $m->acaoAuditoria) {
                return;
            }
            $antes = array_intersect_key($m->getOriginal(), $mudou);
            $m->registrarAuditoria($m->acaoAuditoria ?? 'ALTERADO', $m->paraJson($antes), [...$m->paraJson($mudou), ...$m->extraAuditoria]);
        });

        static::deleted(fn ($m) => $m->registrarAuditoria('EXCLUIDO', $m->camposAuditaveis($m->getAttributes()), null));
    }

    public function historicoAlteracoes(): MorphMany
    {
        return $this->morphMany(HistoricoAlteracao::class, 'auditavel')->latest('id');
    }

    /** Registra um evento sem alteração de campos (ex.: anexo incluído). */
    public function registrarAuditoria(string $acao, ?array $antes = null, ?array $depois = null): void
    {
        HistoricoAlteracao::create([
            'auditavel_type' => $this->getMorphClass(),
            'auditavel_id' => $this->getKey(),
            'acao' => $acao,
            'user_id' => Auth::id(),
            'antes' => $antes,
            'depois' => $depois,
        ]);
        $this->acaoAuditoria = null;
        $this->extraAuditoria = [];
    }

    private function camposAuditaveis(array $valores): array
    {
        return $this->paraJson(array_diff_key($valores, array_flip($this->foraDaAuditoria)));
    }

    private function paraJson(array $valores): array
    {
        return array_map(fn ($v) => $v instanceof \BackedEnum ? $v->value : ($v instanceof \DateTimeInterface ? $v->format('Y-m-d') : $v), $valores);
    }
}
