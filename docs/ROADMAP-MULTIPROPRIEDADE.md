# Multipropriedade: vender o sistema para outros produtores

Hoje o sistema atende uma fazenda só: nenhuma tabela tem dono, o perfil fica no usuário e o nome "Fazenda Santa Bárbara"
está fixo no front. O objetivo é que cada produtor (cliente) tenha a sua conta, com uma ou mais fazendas, sem nunca ver
dados de outro.

**Decisões tomadas:**

1. O sistema será vendido para outros produtores (SaaS), não só usado por um dono com várias fazendas.
2. Pessoas (fornecedores, clientes, funcionários) são cadastradas por fazenda.
3. Visão consolidada entre fazendas fica para depois (fase 5).
4. Novos clientes entram só por convite da nossa equipe.

## Modelo

```
Cliente (quem assina e paga)
 └── Fazenda (uma ou mais por cliente)
      └── todos os dados: animais, lotes, piquetes, financeiro, estoque, engorda, pessoas, categorias
Usuário (login global) ── vínculo por fazenda, com perfil: GESTOR ou CASEIRO
                       └── papel no cliente: DONO (gerencia fazendas, usuários e assinatura) ou nenhum
Administrador da plataforma (nossa equipe): cria clientes, suspende, dá suporte
```

- **Uma base de dados com `fazenda_id`** em cada registro e escopo global no Laravel. Um banco por cliente isolaria mais,
  mas complicaria deploy, migrations e suporte; não compensa no volume esperado.
- **`fazenda_id` também nas tabelas filhas** (pesagens, produções, vacinas, movimentos, metas, anexos, fotos, histórico),
  e não só nas que têm dono direto: dá índice direto e evita vazamento em consultas que não passam pelo registro pai.
- **Login global, acesso por vínculo.** A mesma pessoa pode ser gestora numa fazenda e caseira em outra (ex.: um veterinário
  ou consultor que atende vários clientes). Depois do login, o usuário escolhe a fazenda; o front manda `X-Fazenda` em cada
  chamada e um middleware confere o vínculo, define a fazenda ativa e a assinatura do cliente.
- **Sem fazenda ativa, a consulta falha** em vez de devolver tudo. Comandos e o agendador definem a fazenda explicitamente.

## Fase 1 — fundação (isolamento por fazenda)

Ao final, a Santa Bárbara funciona exatamente como hoje, já como cliente 1 / fazenda 1.

- [ ] Tabelas `clientes`, `fazendas`, `fazenda_usuario` (com o perfil) e `cliente_usuario` (papel DONO).
- [ ] Perfil sai de `users` e passa para o vínculo; `ExigePerfil` lê o perfil da fazenda ativa.
- [ ] Middleware da fazenda ativa (`X-Fazenda`) e um contexto acessível ao resto do código.
- [ ] Trait `PertenceAFazenda`: escopo global + preenchimento de `fazenda_id` ao criar. Aplicar em todos os modelos de negócio
  (cerca de 24 tabelas). A busca por id na URL passa a dar 404 para registros de outra fazenda.
- [ ] Unicidade por fazenda, nas migrations e nos Requests: brinco, nome do lote, identificação do piquete, nome do item
  de estoque, nome da conta, categorias `(tipo, nome)` e `chave`. `users.usuario` continua global.
- [ ] As 18 regras `exists:` dos Requests filtram pela fazenda ativa (senão dá para apontar para a conta ou o animal de outra).
- [ ] Categorias financeiras e sanitárias por fazenda; o catálogo padrão, que hoje vem da migration, passa a ser criado
  junto com cada fazenda.
- [ ] `DesmamarBezerros` e o agendador percorrem as fazendas.
- [ ] `Auditavel` grava `fazenda_id` no histórico.
- [ ] Migração da produção: criar cliente e fazenda 1, adicionar `fazenda_id` opcional, preencher com 1, tornar obrigatório,
  vincular os usuários atuais com o perfil que já têm. Ver "Registros existentes" abaixo.
- [ ] **Testes de isolamento:** duas fazendas de clientes diferentes; para cada endpoint, garantir que uma não lê, não grava
  e não referencia dados da outra. Esses testes são o critério de pronto da fase.

### Registros existentes

Nada do que já foi lançado na Santa Bárbara é apagado ou recriado: as tabelas ganham uma coluna, e todos os registros
atuais recebem `fazenda_id = 1`. IDs, datas, valores, fotos e anexos continuam os mesmos.

- [ ] **Backup do banco e do volume de arquivos** imediatamente antes do deploy, com restauração testada.
- [ ] **Ensaio numa cópia da produção:** rodar a migração e conferir a contagem de registros por tabela antes e depois
  (animais, pesagens, ordenhas, lançamentos, estoque…), que precisa bater exatamente.
- [ ] **Migração em etapas e reversível:** coluna opcional → preenchimento → obrigatória; o `down` remove só o que foi
  adicionado.
- [ ] **Usuários e sessões:** `gestor`, `caseiro` e demais continuam com o mesmo login e senha, vinculados à fazenda 1 com
  o perfil atual; os tokens abertos continuam válidos.
- [ ] **Fila offline dos aparelhos:** registros feitos sem internet antes da atualização chegam sem `X-Fazenda`. Enquanto
  o usuário tiver uma fazenda só, a API usa essa fazenda quando o cabeçalho faltar, para nenhum registro pendente ser
  recusado.
- [ ] **Arquivos:** fotos e anexos são copiados para `fazendas/1/` e o caminho no banco é atualizado; os originais só são
  apagados depois de conferidos (fase 2).

## Fase 2 — front com várias fazendas

- [ ] Nome da fazenda e do produto vindos da API; sai o "Fazenda Santa Bárbara" fixo de `Marca.vue`, da tela de login e do
  `index.html`.
- [ ] Seletor de fazenda no topo (só aparece com mais de uma); a escolha fica na sessão.
- [ ] **Offline:** cache `cache-api:` separado por fazenda; cada item da fila guarda a fazenda em que foi registrado e sobe
  para ela, mesmo que o usuário tenha trocado de fazenda antes de sincronizar.
- [ ] Fotos e anexos gravados em `fazendas/{id}/...`; mover os arquivos existentes para `fazendas/1/`.

## Fase 3 — produto vendável

- [ ] **Painel do administrador da plataforma:** criar cliente e primeira fazenda, convidar o dono, suspender e reativar,
  entrar como suporte (com registro no histórico).
- [ ] **Área do dono:** cadastrar fazendas, convidar usuários por e-mail ou WhatsApp, definir o perfil em cada fazenda.
- [ ] **Recuperação de senha:** hoje não existe e o e-mail é opcional. Com clientes externos passa a ser obrigatório
  (por e-mail ou WhatsApp).
- [ ] **Nome e marca do produto:** hoje o sistema se chama "Fazenda Santa Bárbara"; o produto vendido precisa de outro nome,
  domínio e tela de login próprios.
- [ ] **LGPD:** termos de uso e política de privacidade, exportação completa dos dados de um cliente e exclusão ao cancelar.
- [ ] **Operação:** backups diários com restauração testada, monitoramento de erros, limite de requisições no login.
- [ ] **Ambiente de demonstração:** instância separada com os seeders, recriada periodicamente, para apresentar a clientes.

## Fase 4 — assinatura e cobrança

- [ ] Planos (ex.: por fazenda e faixa de cabeças) e limites por plano.
- [ ] Cobrança recorrente por um gateway brasileiro (Pix, boleto e cartão); gateway a escolher.
- [ ] Período de teste, aviso de vencimento e bloqueio para somente leitura por inadimplência (sem apagar dados).

Até essa fase ficar pronta, os primeiros clientes podem ser cadastrados pela nossa equipe e cobrados por fora.

## Fase 5 — visão consolidada (depois)

- [ ] Painel e financeiro do cliente somando as fazendas.
- [ ] Transferência de animais entre fazendas do mesmo cliente.
- [ ] Parâmetros de `config/pecuaria.php` por fazenda (peso de abate, dias retroativos do caseiro, desmame, avisos).

## Decisões

- **Cadastro: por convite.** O produtor não se cadastra sozinho; nossa equipe cria o cliente no painel do administrador
  (fase 3) e o dono recebe o convite. Cadastro próprio no site fica fora do escopo até nova decisão.

**Em aberto (nenhuma trava as fases 1 a 3):**

1. **Preço:** ainda não definido (por fazenda, por número de cabeças ou por usuário). Define o desenho dos planos e limites
   da fase 4.
2. **Gateway de cobrança:** ainda não escolhido. Só é necessário na fase 4.
3. **Nome do produto e domínio:** ainda não definidos. Necessários antes do primeiro cliente externo (fase 3); até lá o
   front passa a ler o nome da API (fase 2), então trocar depois não exige mudança de código.
