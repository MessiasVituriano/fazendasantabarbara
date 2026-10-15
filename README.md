# Fazenda Santa Bárbara — Gestão Agropecuária

Monorepo com a API Laravel (`backend/`) e o painel Vue 3 (`frontend/`), conforme o PRD.

## Rodando

```bash
# Back-end (PostgreSQL 16+; ajuste DB_* em backend/.env)
cd backend
createdb fazenda
php artisan migrate --seed          # --seed carrega um rebanho de demonstração
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

## Usuários e perfis

| Perfil | Acesso |
|---|---|
| **Gestor** | Tudo: painel e indicadores, abate, cadastro/edição/exclusão de animais e piquetes, usuários |
| **Caseiro** | Operacional: ordenha, pesagens, eventos reprodutivos, vacinas, fotos e movimentação nos piquetes; consulta rebanho e fichas. Lança/corrige somente os últimos `PECUARIA_DIAS_RETROATIVOS_CASEIRO` dias (padrão 3) |

- Login por **nome de usuário** (e-mail opcional) em `POST /api/login` → token Bearer (Sanctum), válido por 30 dias (`SANCTUM_EXPIRATION`, em minutos).
- O seeder cria `gestor` / `gestor123` e `caseiro` / `caseiro123` — **apenas para demonstração**.
- Primeiro gestor em produção: `php artisan pecuaria:criar-gestor admin --nome="Fulano"` (pede a senha).
- Usuários não são excluídos, são **desativados**; desativar, trocar o perfil ou redefinir a senha encerra as sessões abertas. O sistema impede remover o último gestor ativo.

## Regras parametrizadas (`backend/.env` → `config/pecuaria.php`)

| Variável | Padrão | Uso |
|---|---|---|
| `PECUARIA_DIAS_GESTACAO` | 283 | Parto provável = concepção + N dias |
| `PECUARIA_DIAS_PARA_TOQUE` | 35 | Toque previsto = inseminação/cobertura + N dias |
| `PECUARIA_DIAS_ANTE_PARTO_SECAGEM` | 60 | Secagem prevista = parto provável − N dias |
| `PECUARIA_DIAS_DESMAME_BEZERRO` | 210 | Idade em que BEZERRO vira NOVILHA/GARROTE |
| `PECUARIA_DIAS_RETROATIVOS_CASEIRO` | 3 | Quantos dias para trás o caseiro pode lançar/corrigir |

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
| GET/POST/DELETE | `/api/vacinas` | `data_fim_carencia` calculada automaticamente |
| GET | `/api/manejo/ordenha`, `/api/manejo/abate` | RF04 — `alerta.nivel = CRITICO`; `?ocultar_em_carencia=1` omite |
| GET/POST | `/api/animais/{id}/fotos` | Galeria; POST multipart com `foto` (+ `legenda`, `principal`, `data_registro`) |
| PATCH/DELETE | `/api/animais/{id}/fotos/{foto}` | `{principal: true}` / `{legenda}`; excluir a principal promove a mais recente |
| CRUD | `/api/piquetes` | RN03 — `animais_alocados` |
| POST | `/api/piquetes/{id}/entradas` | `{animal_ids, data_entrada}` — fecha a ocupação anterior de cada animal |
| POST | `/api/piquetes/{id}/saidas` | `{data_saida, animal_ids?}` — sem `animal_ids` esvazia o piquete |

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

Todas as rotas, exceto `/api/login`, exigem `Authorization: Bearer <token>`. Rotas de gestão respondem `403` para o caseiro.

### Erros (padrão JSON API)

```json
{ "errors": [ { "status": "422", "code": "VALIDACAO", "title": "Dados inválidos",
  "detail": "O campo brinco do bezerro é obrigatório quando tipo de evento for PARTO.",
  "source": { "pointer": "/registros/0/brinco_bezerro" } } ] }
```

Códigos: `VALIDACAO` (422), regras de negócio (ex.: `PIQUETE_DEGRADADO`), `NAO_ENCONTRADO` (404),
`REGISTRO_DUPLICADO` (409), `NAO_AUTENTICADO` (401), `SEM_PERMISSAO` (403), `ERRO_INTERNO` (500).
