<?php

namespace App\Services;

use App\Enums\Aptidao;
use App\Enums\Categoria;
use App\Enums\StatusReprodutivo;
use App\Enums\TipoEventoReprodutivo as Tipo;
use App\Models\Animal;
use App\Models\EventoReprodutivo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Registra eventos reprodutivos e aplica as automações de status (RF02).
 * Todos os prazos vêm de config/pecuaria.php.
 */
class ServicoReprodutivo
{
    /**
     * @param  array{uuid?: ?string, animal_id: int, tipo_evento: string, data_evento: string,
     *               observacoes?: ?string, brinco_bezerro?: ?string, sexo_bezerro?: ?string,
     *               nome_bezerro?: ?string}  $dados
     * @return array{evento: EventoReprodutivo, criado: bool}
     */
    public function registrar(array $dados): array
    {
        // Reenvio de um lote já sincronizado: devolve o registro existente sem reaplicar efeitos.
        if (! empty($dados['uuid']) && $existente = EventoReprodutivo::firstWhere('uuid', $dados['uuid'])) {
            return ['evento' => $existente, 'criado' => false];
        }

        // Parto altera a mãe e cria o bezerro: tudo ou nada.
        return DB::transaction(function () use ($dados) {
            $animal = Animal::lockForUpdate()->findOrFail($dados['animal_id']);
            $tipo = Tipo::from($dados['tipo_evento']);
            $data = Carbon::parse($dados['data_evento'])->startOfDay();

            // Lotes offline podem chegar fora de ordem: um evento mais antigo que o último
            // registrado entra no histórico, mas não pode regredir o status atual da matriz.
            $ehMaisRecente = ! $animal->eventosReprodutivos()
                ->where('data_evento', '>', $data->toDateString())
                ->exists();

            $evento = $animal->eventosReprodutivos()->create([
                'uuid' => $dados['uuid'] ?? null,
                'tipo_evento' => $tipo,
                'data_evento' => $data,
                'observacoes' => $dados['observacoes'] ?? null,
            ]);

            if ($tipo === Tipo::PARTO) {
                $bezerro = $this->registrarBezerro($animal, $data, $dados);
                $evento->update(['bezerro_id' => $bezerro->id]);
            }

            if ($ehMaisRecente) {
                $this->aplicarEfeitos($animal, $tipo, $data);
                $animal->save();
            }

            return ['evento' => $evento->load('bezerro'), 'criado' => true];
        });
    }

    private function aplicarEfeitos(Animal $animal, Tipo $tipo, Carbon $data): void
    {
        $config = config('pecuaria');

        match ($tipo) {
            Tipo::INSEMINACAO, Tipo::COBERTURA_NATURAL => $animal->fill([
                'status_reprodutivo' => StatusReprodutivo::PRENHE_A_CONFIRMAR,
                'data_prevista_toque' => $data->copy()->addDays($config['dias_para_toque']),
                'data_provavel_parto' => null,
                'data_prevista_secagem' => null,
            ]),

            Tipo::TOQUE_POSITIVO => $this->projetarGestacao($animal, $data, $config),

            Tipo::TOQUE_NEGATIVO, Tipo::ABORTO => $animal->fill([
                'status_reprodutivo' => StatusReprodutivo::APTA,
                ...$this->semProjecoes(),
            ]),

            Tipo::PARTO => $animal->fill([
                'status_reprodutivo' => StatusReprodutivo::PARIDA,
                ...($animal->aptidao === Aptidao::LEITE ? ['categoria' => Categoria::LACTACAO] : []),
                ...$this->semProjecoes(),
            ]),
        };
    }

    /**
     * Parto provável = data da concepção + dias de gestação; secagem = parto - dias ante-parto.
     * A concepção é a última inseminação/cobertura antes do toque. Sem ela (histórico
     * incompleto), usa a própria data do toque.
     */
    private function projetarGestacao(Animal $animal, Carbon $dataToque, array $config): void
    {
        $concepcao = $animal->eventosReprodutivos()
            ->whereIn('tipo_evento', [Tipo::INSEMINACAO, Tipo::COBERTURA_NATURAL])
            ->where('data_evento', '<=', $dataToque->toDateString())
            ->max('data_evento');

        $parto = Carbon::parse($concepcao ?? $dataToque)->addDays($config['dias_gestacao']);

        $animal->fill([
            'status_reprodutivo' => StatusReprodutivo::GESTANTE,
            'data_prevista_toque' => null,
            'data_provavel_parto' => $parto,
            'data_prevista_secagem' => $parto->copy()->subDays($config['dias_ante_parto_secagem']),
        ]);
    }

    private function registrarBezerro(Animal $mae, Carbon $data, array $dados): Animal
    {
        return Animal::create([
            'brinco' => $dados['brinco_bezerro'],
            'nome' => $dados['nome_bezerro'] ?? null,
            'sexo' => $dados['sexo_bezerro'],
            'data_nascimento' => $data,
            'aptidao' => $mae->aptidao,
            'categoria' => Categoria::BEZERRO,
            'status_reprodutivo' => StatusReprodutivo::INAPTA,
            'mae_id' => $mae->id,
        ]);
    }

    private function semProjecoes(): array
    {
        return ['data_prevista_toque' => null, 'data_provavel_parto' => null, 'data_prevista_secagem' => null];
    }
}
