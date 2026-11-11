# Fazenda Santa Bárbara — Gestão Agropecuária

Monorepo com a API Laravel (`backend/`) e o painel Vue 3 (`frontend/`), conforme o PRD.

## Rodando

```bash
# Back-end (PostgreSQL 16+; ajuste DB_* em backend/.env)
cd backend
createdb fazenda
php artisan migrate --seed          # --seed cria a fazenda de demonstração (id 2) com rebanho e financeiro (ignorado em produção)
php artisan serve                   # http://127.0.0.1:8000

# Front-end (Vue 3 + shadcn-vue + Tailwind v4)
cd frontend
npm install
npm run dev                         # http://localhost:5173 (proxy /api -> :8000)

# Agendador (RF03 — desmame diário às 01:00). Em produção, no cron:
* * * * * cd /caminho/backend && php artisan schedule:run >> /dev/null 2>&1
```

Sem PostgreSQL local, dá para testar com SQLite:
`DB_CONNECTION=sqlite DB_DATABASE=$PWD/database/database.sqlite php artisan migrate:fresh --seed`
(o mesmo prefixo vale para o `php artisan serve`).

Testes: `cd backend && php artisan test` (SQLite em memória).

## Deploy (Digital Ocean)

Um droplet com Docker roda dois containers: `app` (Nginx, PHP-FPM, painel Vue e agendador do desmame) e `db` (PostgreSQL 16).
Fotos, comprovantes e logs ficam no volume `storage-data`, e o banco no volume `pgdata`.

Primeira instalação, no droplet:

```bash
git clone https://github.com/MessiasVituriano/fazendasantabarbara.git
cd fazendasantabarbara
./deploy.sh
nano .env
./deploy.sh
docker compose -f docker-compose.prod.yml exec -u www-data app php artisan pecuaria:criar-gestor admin --nome="Fulano"
```

A primeira execução cria o `.env` com `APP_KEY` e `DB_PASSWORD` gerados; só falta ajustar `APP_URL`. Se outro projeto
já usar a porta 80 no droplet, troque `APP_PORT` e inclua a porta no `APP_URL`.

Atualizações, no droplet:

```bash
cd fazendasantabarbara
git pull
./deploy.sh
```

O script para com erro se a aplicação não subir.

## Usuários e perfis

| Perfil | Acesso |
|---|---|
| **Gestor** | Tudo: painel e indicadores, abate, cadastro/edição/exclusão de animais e piquetes, usuários |
| **Caseiro** | Operacional: ordenha, pesagens, eventos reprodutivos, vacinas, fotos e movimentação nos piquetes; consulta rebanho e fichas. Lança/corrige somente os últimos `PECUARIA_DIAS_RETROATIVOS_CASEIRO` dias (padrão 3) |

- Login por **nome de usuário** (e-mail opcional) em `POST /api/login` → token Bearer (Sanctum), válido por 30 dias (`SANCTUM_EXPIRATION`, em minutos).
- O seeder cria `gestor` / `gestor123` e `caseiro` / `caseiro123` na fazenda de demonstração — **apenas para demonstração**.
- Primeiro gestor em produção: `php artisan pecuaria:criar-gestor admin --nome="Fulano"` (pede a senha; `--fazenda=ID`, padrão 1).
- Usuários não são excluídos, são **desativados**; desativar, trocar o perfil ou redefinir a senha encerra as sessões abertas. O sistema impede remover o último gestor ativo da fazenda.

## Fazendas (multipropriedade)

Cada produtor (cliente) tem uma ou mais fazendas, e todo registro pertence a uma fazenda. O login é único; o perfil e a
situação (ativo/desativado) valem por fazenda. Plano completo em [docs/ROADMAP-MULTIPROPRIEDADE.md](docs/ROADMAP-MULTIPROPRIEDADE.md).

- **Fazenda 1** é a Fazenda Santa Bárbara, criada pela migration com todos os registros que já existiam. **Fazenda 2** é a
  demonstração, criada pelo seeder.
- A fazenda da requisição vem do cabeçalho `X-Fazenda: <id>`. Sem ele, vale a única fazenda do usuário; quem tem mais
  de uma recebe `400 FAZENDA_NAO_INFORMADA`. Fazenda sem vínculo ativo: `403 SEM_ACESSO_FAZENDA`.
- `POST /api/login` e `GET /api/eu` trazem `fazendas` (id, nome e perfil em cada uma).
- Registros de outra fazenda não aparecem nas listagens e dão `404` pelo id; validações (`exists`/`unique`) valem dentro da fazenda.
- No código: models de negócio usam `PertenceAFazenda` (filtro e gravação automáticos pela `ContextoFazenda`); fora de uma
  requisição (comandos, seeders), use `ContextoFazenda::executarEm($fazenda, fn () => ...)`. Fazendas novas:
  `ServicoFazenda::criar()`, que já cria as categorias padrão.

## Registro offline (painel web)

Os registros de manejo funcionam sem internet: **ordenha**, **pesagens**, **produção e pesagem rápidas** da ficha,
**eventos reprodutivos**, **vacinas/medicamentos** e **entradas/saídas de estoque**.

- Sem conexão, o registro fica numa fila no `localStorage` do aparelho e sobe sozinho quando a internet volta
  (também ao abrir o app, ao voltar para a aba e a cada minuto). O indicador no cabeçalho mostra
  *Offline*, quantos registros faltam enviar e os recusados pelo servidor (com o motivo, para tentar de novo ou descartar).
- Os envios usam endpoints idempotentes (`uuid` por registro, ou o "salvar turno" da ordenha): reenviar não duplica.
- As leituras da API ficam guardadas no aparelho (últimas 80 respostas) e são usadas quando não há conexão.
  Ao entrar, o app pré-carrega rebanho, vacas em lactação, ordenhas de hoje/ontem, últimas pesagens e cadastros sanitários.
- Um service worker (`frontend/sw.js`, gerado no build com a lista de arquivos) guarda o app para abrir sem internet.
  Exige HTTPS (ou `localhost`).
- A fila é por usuário: ao sair com pendências, o sistema avisa, e elas sobem no próximo login do mesmo usuário.
  As leituras guardadas são apagadas ao sair.
- O limite de dias do caseiro vale na data do envio: um registro offline que só sobe depois do prazo é recusado e
  aparece no indicador. Cadastros do gestor, movimentação de piquetes, fotos e financeiro continuam exigindo conexão.

## Regras parametrizadas (`backend/.env` → `config/pecuaria.php`)

| Variável | Padrão | Uso |
|---|---|---|
| `PECUARIA_DIAS_GESTACAO` | 283 | Parto provável = concepção + N dias |
| `PECUARIA_DIAS_PARA_TOQUE` | 35 | Toque previsto = inseminação/cobertura + N dias |
| `PECUARIA_DIAS_ANTE_PARTO_SECAGEM` | 60 | Secagem prevista = parto provável − N dias |
| `PECUARIA_DIAS_DESMAME_BEZERRO` | 210 | Idade em que BEZERRO vira NOVILHA/GARROTE |
| `PECUARIA_DIAS_RETROATIVOS_CASEIRO` | 3 | Quantos dias para trás o caseiro pode lançar/corrigir |
| `PECUARIA_DIAS_AVISO_VALIDADE` | 30 | Itens do estoque que vencem nesse prazo aparecem como "vence em breve" |
| `PECUARIA_ENGORDA_JANELA_DIAS` | 120 | Pesagens usadas na tendência de peso (regressão linear) |
| `PECUARIA_ENGORDA_RITMO_OK` / `_ATENCAO` | 0.95 / 0.80 | GMD real ÷ necessário para a meta ficar "no ritmo" / "atenção" |
| `PECUARIA_ENGORDA_GMD_MAXIMO` | 1.2 | GMD necessário acima disso torna a meta inviável |
| `PECUARIA_ENGORDA_DIAS_SEM_PESAGEM` | 45 | Aviso de lote com animais sem pesagem recente |
| `PECUARIA_PESO_ABATE_KG` | 540 | Peso alvo da previsão de abate quando o animal não tem meta de lote |

Após alterar o `.env` em produção com `config:cache`, rode `php artisan config:cache` de novo.

## API

| Método | Rota | Observação |
|---|---|---|
| POST | `/api/login` | `{usuario, senha}` → `{token, usuario}` (público) |
| GET / POST | `/api/eu`, `/api/logout` | Sessão atual, sair |
| PUT | `/api/eu`, `/api/eu/senha` | Editar os próprios dados (nome, usuário, e-mail, telefone) e a senha — só gestor; os do caseiro são alterados pelo gestor em Usuários |
| GET/POST/PATCH | `/api/usuarios` | Gerenciamento de usuários (gestor) |
| GET | `/api/opcoes` | Enums com rótulos + parâmetros da pecuária |
| CRUD | `/api/animais` | Filtros: `busca`, `categoria`, `aptidao`, `sexo`, `status_reprodutivo`, `status_geral` (padrão ATIVO) |
| GET | `/api/animais/{id}/timeline` | RN01 — linha do tempo unificada |
| GET/POST | `/api/producoes-leite` | RF01 — POST aceita lote; GET filtra por `periodo`, `data_inicio`, `data_fim` |
| PUT | `/api/producoes-leite/turno` | Tela de ordenha: `{data_pesagem, periodo, registros:[{animal_id, quantidade_litros}]}` cria/corrige; `null` remove |
| GET | `/api/painel/leite?dias=7\|30\|90` | Indicadores, série diária e ranking |
| GET | `/api/painel/leite/comparativo?inicio=&fim=` | Comparativo por vaca em intervalo livre (máx. 1 ano) |
| GET/POST | `/api/pesagens` | RF01 — lote; GET traz `gmd_kg_dia` (RF03) |
| GET/POST | `/api/eventos-reprodutivos` | RF01 + RF02 — lote, automações de status |
| GET/POST/DELETE | `/api/vacinas` | Vacinas e medicamentos aplicados. POST exige `tipo` e `dias_carencia_leite_carne`, com `produto_sanitario_id` ou `nome_vacina` (campo livre) e `categoria_sanitaria_id` opcional. GET filtra por `animal_id`, `tipo`, `categoria_sanitaria_id`, `vigentes`, `busca`. `data_fim_carencia` calculada automaticamente |
| GET/POST/PATCH | `/api/sanidade/categorias`, `/api/sanidade/produtos` | Categorias e produtos com carência sugerida (o produto prevalece sobre a categoria). Consulta para todos; alteração só do gestor. Não são excluídos, são desativados |
| GET | `/api/manejo/ordenha`, `/api/manejo/abate` | RF04 — `alerta.nivel = CRITICO`; `?ocultar_em_carencia=1` omite |
| GET/POST | `/api/animais/{id}/fotos` | Galeria; POST multipart com `foto` (+ `legenda`, `principal`, `data_registro`) |
| PATCH/DELETE | `/api/animais/{id}/fotos/{foto}` | `{principal: true}` / `{legenda}`; excluir a principal promove a mais recente |
| CRUD | `/api/piquetes` | RN03 — `animais_alocados` |
| POST | `/api/piquetes/{id}/entradas` | `{animal_ids, data_entrada}` — fecha a ocupação anterior de cada animal |
| POST | `/api/piquetes/{id}/saidas` | `{data_saida, animal_ids?}` — sem `animal_ids` esvazia o piquete |

### Estoque (medicamentos, vacinas, ração e insumos)

Tela **Estoque** no menu Operação. Cada item tem tipo (medicamento, vacina, ração, suplemento/sal mineral, outros),
unidade (un, kg, saco, L, mL, dose, frasco, caixa), estoque mínimo, validade do lote em uso e localização.

- **O saldo nunca é digitado:** muda só por **entradas**, **saídas** e **ajustes de inventário** (informa-se o saldo contado
  e a diferença é lançada). Saída maior que o saldo é recusada (`ESTOQUE_INSUFICIENTE`).
- **Alertas:** "Para repor" quando o saldo chega ao mínimo; "Validade" para itens vencidos ou que vencem em
  `PECUARIA_DIAS_AVISO_VALIDADE` dias (padrão 30).
- **Custo (só gestor):** o preço unitário informado na entrada vira o custo do item; o valor em estoque é saldo × custo.
- **Sem exclusão:** movimentos são *cancelados* (com motivo) e o saldo é corrigido; itens são desativados.
- **Perfis:** o caseiro consulta e registra entradas e saídas (sem preço, dentro do limite de dias); cadastro de itens,
  ajuste e cancelamento são do gestor. Entradas e saídas funcionam offline (fila do aparelho, `uuid` idempotente).

| Método | Rota | Observação |
|---|---|---|
| GET | `/api/estoque/itens` | Filtros: `tipo`, `busca`, `alerta` (REPOR, VALIDADE), `inativos=1`. `meta.resumo` traz contagem de alertas (e `valor_total` para o gestor) |
| GET | `/api/estoque/itens/{id}` | Item com saldo e alertas |
| POST/PATCH | `/api/estoque/itens` | Cadastro e edição (gestor) |
| GET/POST | `/api/estoque/movimentos` | GET filtra por `item_estoque_id`, `tipo`, `inicio`, `fim`. POST: `{item_estoque_id, tipo, data_movimento, quantidade}` (ou `saldo_contado` no AJUSTE), `valor_unitario` opcional na entrada (gestor), `observacao`, `uuid` |
| POST | `/api/estoque/movimentos/{id}/cancelamento` | Exige `motivo` (gestor); recusado se deixar o saldo negativo |

Saídas aceitam `lote_id` (lote de destino, ativo); o custo do item no dia fica gravado no movimento. `GET` filtra por `lote_id`.

### Engorda (animais no lote, projeção de peso e metas)

Na tela **Lotes** (gestor), cada lote mostra animais, peso médio, GMD e meta; o detalhe traz o painel de engorda.
A ficha do animal mostra o crescimento, e a lista de **Abate** mostra a previsão para o peso alvo.

- **Animais no lote:** com histórico (entrada/saída), um lote por vez. Só animais ativos de corte ou mista.
  Entrar em outro lote encerra a passagem anterior; encerrar o lote retira os animais na data do encerramento.
- **Tendência de peso:** regressão linear simples das pesagens dos últimos `PECUARIA_ENGORDA_JANELA_DIAS` (padrão 120),
  com no mínimo 3 pesagens cobrindo 30 dias. O GMD de tendência é a inclinação da reta; o R² mostra se é confiável
  (≥ 0,8), instável (≥ 0,5) ou irregular (sem projeção). A projeção vai no máximo a 90 dias, com faixa de ~95%:
  o crescimento do bovino é uma curva em S e muda entre águas e seca, então a reta só vale para a fase atual.
- **Pesagem suspeita:** a que gera ganho fora de −1 a +3 kg/dia com as vizinhas é marcada e fica fora da conta (nada é apagado).
  A tela Pesagens avisa "Confira o peso" ao digitar.
- **Meta do lote:** peso médio alvo numa data. Situação calculada (não gravada) pelo GMD real ÷ GMD necessário:
  no ritmo (≥ `PECUARIA_ENGORDA_RITMO_OK`, 95%), atenção (≥ `PECUARIA_ENGORDA_RITMO_ATENCAO`, 80%), atrasada; inviável se o
  GMD necessário passar de `PECUARIA_ENGORDA_GMD_MAXIMO` (1,2 kg/dia); atingida; vencida. Nova meta substitui a anterior (histórico mantido).
- **Ração do lote:** a saída de estoque aceita `lote_id` (lote de destino) e grava o custo do item no dia. O painel do lote mostra
  consumo e custo (30 dias, por cabeça/dia e um indicativo por kg ganho).
- Sem meta, a previsão de abate usa `PECUARIA_PESO_ABATE_KG` (540). Painel avisa metas atrasadas/inviáveis/vencidas/atingidas,
  lotes com animais sem pesagem há `PECUARIA_ENGORDA_DIAS_SEM_PESAGEM` dias (45) e animais perdendo peso.

| Método | Rota | Observação |
|---|---|---|
| GET | `/api/lotes/{id}/engorda` | Resumo, meta avaliada, série do peso médio, animais e ração (gestor) |
| POST | `/api/lotes/{id}/animais`, `/api/lotes/{id}/saidas` | `{animal_ids, data_entrada}` / `{data_saida, animal_ids?}` (gestor) |
| POST | `/api/lotes/{id}/metas`, `/api/lotes/{id}/metas/{meta}/cancelamento` | `{peso_alvo_kg, data_alvo, data_inicio?, gmd_alvo?, observacoes?}` (gestor) |
| GET | `/api/animais/{id}/crescimento` | Pesagens (com suspeitas), tendência, lote/meta e previsão para o peso alvo |
| GET | `/api/manejo/lotes` | Lotes ativos (id, nome, animais) para o destino da saída de estoque — os dois perfis |
| GET | `/api/painel/engorda` | Avisos da engorda (gestor) |

### Financeiro (somente gestor)

Três datas por lançamento, com significados distintos:
**competência** (quando o fato aconteceu: base do resultado e do custo do lote), **vencimento** (base de contas a pagar/receber
e da previsão de caixa) e **pagamento** (quando o dinheiro de fato entrou/saiu: base do saldo e do fluxo de caixa).

- **Realizado × previsto:** só lançamentos `PAGO` mexem no saldo, pelo `valor_pago` (pode ter juros/desconto). Em aberto aparece
  como *previsto*, nunca somado ao saldo. "Vencido" não é gravado: é um pendente com vencimento anterior a hoje.
- **Sem exclusão:** lançamentos são *cancelados* (saem dos totais) ou *estornados* (pago → em aberto). Pago não muda valor/conta
  sem estorno. Tudo fica em `historico_alteracoes` (quem, quando, antes/depois). Contas e categorias são desativadas, não excluídas.
- **Custo do lote sem duplicidade:** a despesa lançada com `lote_id` alimenta o custo do lote, sem cadastro paralelo. O
  grupo de custo vem da categoria e é copiado no lançamento, então mudar a categoria depois não reescreve custos antigos. **Aquisição**
  (compra, frete e comissão de compra) é sempre exibida separada do **custo operacional** da recria.
- **Precisão:** valores `decimal(14,2)`; parcelamento dividido em centavos (diferença na 1ª parcela); arredondamento só na saída.
- **Saldo inicial:** cada conta tem saldo inicial numa data; pagamentos anteriores a essa data são recusados.
- Categorias padrão do plano de contas rural são criadas pela migration (existem também em produção, com `chave` estável).
- Comprovantes: disco privado `PECUARIA_DISCO_ANEXOS` (padrão `local`), baixados só com token.

| Método | Rota | Observação |
|---|---|---|
| GET | `/api/financeiro/resumo?referencia=` | Saldo por conta, mês (caixa e competência), agenda a pagar/receber, acumulado, série 12 meses |
| GET | `/api/financeiro/fluxo-caixa?inicio=&fim=&conta_financeira_id=&agrupamento=mes\|dia` | Saldo inicial/final, realizado e previsto separados, comparativo com período anterior |
| GET | `/api/financeiro/demonstrativo?inicio=&fim=&lote_id=` | Resultado por competência, por categoria e grupo de custo |
| GET/POST/PATCH | `/api/financeiro/lancamentos` | Filtros: `tipo`, `situacao[]` (PENDENTE, VENCIDO, PAGO, CANCELADO, EM_ABERTO), `categoria_id[]`, `lote_id` (ou `sem`), `pessoa_id`, `conta_financeira_id`, `busca`, `inicio`/`fim` sobre `base` (competencia, vencimento, pagamento). POST aceita `parcelas` ou já pago (`data_pagamento` + `conta_financeira_id`) |
| POST | `/api/financeiro/lancamentos/{id}/baixa`, `/baixa-em-lote`, `/{id}/estorno`, `/{id}/cancelamento` | Baixa em lote é tudo ou nada; cancelamento aceita `parcelas_seguintes` |
| GET | `/api/financeiro/lancamentos/{id}/historico` | Trilha de auditoria |
| POST/GET/DELETE | `/api/financeiro/lancamentos/{id}/anexos`, `/api/financeiro/anexos/{id}` | Comprovantes (PDF/imagem/planilha, até 10 MB) |
| GET/POST | `/api/financeiro/aportes-retiradas` | Aportes e retiradas de investidores (pessoa do tipo `INVESTIDOR`): alteram o saldo das contas e o fluxo de caixa, mas não são receita nem despesa. Filtros: `tipo`, `pessoa_id`, `inicio`, `fim`; `meta.investidores` traz a posição acumulada de cada investidor |
| POST | `/api/financeiro/aportes-retiradas/{id}/cancelamento` | Exige `motivo`; o registro continua na lista como cancelado |
| GET/POST/PATCH | `/api/financeiro/contas`, `/api/financeiro/categorias`, `/api/pessoas`, `/api/lotes` | Cadastros; `GET /api/lotes/{id}` traz `custos` (aquisição × operacional × total, por grupo e por mês) |

### Fotos dos animais

- O navegador reduz cada foto para no máximo 1600 px (JPEG) antes do envio. O servidor corrige a rotação,
  grava em WebP e gera uma miniatura quadrada de 320 px, usada como avatar no lugar da silhueta.
- A primeira foto de um animal vira a principal. Excluir o animal apaga também os arquivos.
- Arquivos em `storage/app/public/animais/{id}/`. Rode `php artisan storage:link` uma vez em cada ambiente.
- Para guardar em S3 (ou compatível): configure o disco `s3` em `.env` e defina `PECUARIA_DISCO_FOTOS=s3`.
- `APP_URL` precisa apontar para o endereço público da API, pois as URLs das fotos são montadas a partir dele.
- O PHP aceita uploads de até 2 MB por padrão. O app já comprime, mas para clientes que enviam o original
  (ex.: app mobile) ajuste no `php.ini`: `upload_max_filesize = 10M`, `post_max_size = 12M`, `memory_limit = 256M`.

### Lotes (sincronização mobile)

Os POSTs de produção, pesagem e eventos aceitam `[ {...}, {...} ]`, `{ "registros": [...] }` ou um único objeto.
Cada lote é atômico (tudo ou nada). Envie um `uuid` gerado no aparelho em cada registro: um reenvio do mesmo
lote não duplica nada e responde `200` com `meta.ja_sincronizados`. Eventos de um lote são aplicados em ordem
cronológica, e um evento mais antigo que o último já registrado entra no histórico sem regredir o status da matriz.
`POST /api/vacinas` (um registro por vez) também aceita `uuid`: o reenvio devolve a aplicação já gravada.

Todas as rotas, exceto `/api/login`, exigem `Authorization: Bearer <token>`. Rotas de gestão respondem `403` para o caseiro.

### Erros (padrão JSON API)

```json
{ "errors": [ { "status": "422", "code": "VALIDACAO", "title": "Dados inválidos",
  "detail": "O campo brinco do bezerro é obrigatório quando tipo de evento for PARTO.",
  "source": { "pointer": "/registros/0/brinco_bezerro" } } ] }
```

Códigos: `VALIDACAO` (422), regras de negócio (ex.: `PIQUETE_DEGRADADO`), `NAO_ENCONTRADO` (404),
`REGISTRO_DUPLICADO` (409), `NAO_AUTENTICADO` (401), `SEM_PERMISSAO` (403), `ERRO_INTERNO` (500).
