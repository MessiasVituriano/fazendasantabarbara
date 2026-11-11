# Engorda: próximas entregas

Planejamento da projeção de peso, metas e momento de venda. A fase 1 está na branch `engorda/fase-1`.

## Fase 1 — projeção de peso e metas ✅

Entregue: animais no lote (com histórico), tendência de peso por regressão linear (janela de 120 dias, R²,
projeção de até 90 dias com faixa de incerteza), pesagens suspeitas, metas de engorda do lote com situação
calculada, saída de ração/sal para o lote com custo do dia, previsão do peso alvo no abate e avisos no painel.

### Pendências da fase 1

- [ ] Linha do tempo da ficha: marcar a pesagem suspeita e não mostrar o GMD bruto dela (hoje aparece, por exemplo, "-320 kg/dia").
- [ ] Branch `estoque/identidade-visual-lua` (já mergeada no PR #4) ainda tem uma cópia do commit da engorda (`fd4eace`): apagar a branch ou resetar para `54f0be6`.
- [ ] Abrir o PR de `engorda/fase-1` e rodar `php artisan migrate` em cada ambiente.

## Fase 2 — momento ótimo de venda

Objetivo: indicar quando vale vender cada lote, comparando quanto rende um dia a mais de engorda com quanto ele custa.

**Decisões em aberto (do gestor):**

1. Cotação da arroba: digitada pelo gestor ou importada (ex.: CEPEA)?
2. Custos gerais (arrendamento, mão de obra): rateio por cabeça ou por hectare ocupado?
3. Queda do GMD: começar por uma tabela de referência por categoria/faixa de peso, ajustada pelo próprio histórico? (recomendado)

**Entregas:**

- [ ] **Preço de venda:** cadastro da cotação da arroba por data; rendimento de carcaça por lote/categoria (padrão 52%);
  preço do kg vivo = arroba × rendimento ÷ 15.
- [ ] **Venda real:** gravar arrobas e rendimento obtidos na venda, para calibrar o rendimento padrão.
- [ ] **Custo diário por cabeça:** ração e sal (já existe) + despesas do lote no financeiro (por grupo de custo) ÷ animais × dias
  + custos gerais rateados + custo de oportunidade do capital parado no animal (taxa configurável).
- [ ] **Queda do GMD:** com a reta da fase 1 o GMD é constante e o ponto ótimo nunca aparece. Estimar a queda por
  (a) GMD de janelas sucessivas, (b) tabela de referência editável ou (c) curva em S (fase 3).
- [ ] **Margem diária e ponto ótimo:** margem = GMD × preço do kg vivo − custo do dia; ponto ótimo quando a margem chega a zero.
- [ ] **Telas:**
  - Lote: gráfico da margem diária com a data ótima e o resultado projetado vendendo hoje, na meta e na data ótima.
  - Simulador: arroba, rendimento e custo da ração.
  - Abate: coluna "melhor momento".
  - Painel: aviso "passou do ponto ótimo: cada dia custa R$ X/cabeça".
- [ ] Testes de feature e unitários para cada cálculo.

## Fase 3 — curva de crescimento e pasto

- [ ] Raça no cadastro do animal.
- [ ] Curva de crescimento em S (Gompertz ou Brody) ajustada com o histórico do rebanho, por sexo, categoria e raça.
- [ ] Sazonalidade águas/seca na projeção.
- [ ] Pasto: altura na entrada e na saída do piquete, lotação (UA/ha), dias de ocupação e kg ganhos por hectare.
