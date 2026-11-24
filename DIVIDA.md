# Dívida técnica — V1 e V2 (controle de empréstimo de coisas)

Data: 2026-11-20 (V1) / 2026-11-21 (V2)
Escopo entregue na V1: cadastrar item, listar itens com status, agregado `Item` com `Loan` interno,
`Period` e identidades como VOs, persistência Doctrine (SQLite) e UI Twig de cadastro/listagem.
Fora do escopo da V1 por decisão (pagos na V2, seção 7): rotas de emprestar/devolver, Clock injetável,
read models. Continuam fora: autenticação, Messenger, Domain Events, interface de repositório.

**Prioridades**

| Nível | Significado |
|---|---|
| **P0** | Pode corromper estado/Invariante ou expõe falha de segurança agora |
| **P1** | Comportamento errado perceptível, segurança ou operação; pagar antes de crescer |
| **P2** | Manutenibilidade/escala; pagar quando o código da área for tocado |
| **P3** | Polimento; sem prazo |

Legenda de esforço: **S** (≤ meia hora), **M** (meio dia), **L** (mais de um dia).

> **Pagas na V2** (detalhe na seção 7.1): A1, A2, A5, H4, H5, H6, H8, T3. `D15` foi paga **em parte**
> (prazo de devolução e renovação); multa, perda/roubo e cancelamento continuam em aberto.
> As tabelas abaixo registram o estado da V1 e não foram reescritas.

---

## 1. Domínio e invariantes

| # | Dívida | Onde | Prio | Esforço | Como pagar |
|---|---|---|---|---|---|
| D1 | `Item::loans()` devolve as entidades `Loan` reais, e `Loan::markReturned()` é público: qualquer chamador fecha um empréstimo **sem passar por `Item::returnFrom()`**, deixando `status` em `Lent` com period fechado. | `src/Domain/Item/Item.php`, `Loan.php` | **P0** | S/M | Curto prazo: `@internal` em `Loan`/`markReturned` e nenhum chamador fora do agregado (já é o caso). Definitivo: `Item::returnFrom()` **substitui** o `Loan` na coleção em vez de mutá-lo; aí `Loan` pode ser `readonly` e `loans()` devolve objetos imutáveis. |
| D2 | `Loan::__construct` é público: dá para criar um `Loan` solto (sem `Item` coerente) e furar a ideia de entidade interna. | `src/Domain/Item/Loan.php` | P1 | M | Mesma solução de D1 (imutável + construção só via factory interna), ou aceitar como limitação de visibilidade do PHP e marcar `@internal`. |
| D3 | Invariante "no máximo um empréstimo aberto" só existe em memória. Nada no banco impede duas linhas com `returned_at IS NULL` para o mesmo `item_id`. | `migrations/`, `src/Domain/Item/Item.php` | P1 | S | Índice único parcial em migração (`CREATE UNIQUE INDEX ... ON loan (item_id) WHERE returned_at IS NULL`); SQLite e PostgreSQL suportam, MySQL não. |
| D4 | Sem `#[Version]`: dois requests concorrentes carregam `Available`, ambos gravam `Loan` e um sobrescreve o outro. `symfony/lock` também não é usado. | `Item.php`, `DoctrineItemRepository.php` | P1 | S | Adicionar `#[ORM\Version] private int $version` + coluna na migração e traduzir `OptimisticLockException` em 409. Alternativa: `LockFactory` por item. |
| D5 | `status` é coluna persistida **e** derivável de `loans`. Se qualquer caminho mudar um sem o outro (ver D1), o agregado fica inconsistente e nada detecta. | `Item.php` | P1 | M | Curto prazo: um `assertConsistent()` privado chamado em `lendTo`/`returnFrom` (barato, detecta cedo). Definitivo: derivar `status()` de `openLoan()` e materializar status só em read model (D-P4). |
| D6 | Doctrine hidrata `Period`/`Loan`/`Item` por reflexão, **sem passar pelo construtor**: dado ruim no banco (`returned_at < lent_at`) entra no domínio sem validação. | `Period.php` | P2 | M | Aceitar como risco de banco (migração + CHECK constraint no banco é o caminho), ou validar no `postLoad`. |
| D7 | `Period` perdeu `readonly` para o Doctrine hidratar; a imutabilidade agora é só convenção de API. | `Period.php` | P2 | M | Só resolve com mapping fora da entidade (XML) ou DTO de persistência separado do VO. |
| D8 | `Item::$title` não tem invariante no domínio: `new Item($id, '')` é válido. A validação existe só no Form. | `Item.php`, `RegisterItemHandler.php` | P2 | S | Validar no construtor do agregado (`NotBlank`/tamanho) e deixar o Form só para UX. |
| D9 | `ItemStatus` é enum backed; os valores `'available'`/`'lent'` viraram contrato de banco. Renomear um case exige migração de dados. | `ItemStatus.php` | P3 | S | Documentar no enum; renomear cases só com migração. |
| D10 | `BorrowerId` é string opaca: não existe entidade `Borrower`, nem FK, nem validação de formato/existência. `"x"` é um borrower válido. | `BorrowerId.php` | P2 | M | Introduzir `Borrower` quando houver cadastro de pessoas; até lá, no mínimo validar formato no VO. |
| D11 | `ItemId` é gerado no handler com `Uuid::v7()`; colisão (ou cliente reenviando id) estoura como erro de PK, sem tratamento. | `RegisterItemHandler.php` | P3 | S | Improvável na prática; mapear violação de unicidade para 409 se um dia aceitar id do cliente. |
| D12 | Sem `createdAt`/`updatedAt` no `Item`; não há como ordenar por "mais recente" ou auditar mudanças. | `Item.php` | P2 | S | Adicionar colunas + preencher no construtor/handlers (ou listener de Doctrine). |
| D13 | Datas são `datetime_immutable` **naive**: nenhuma política de UTC/timezone; o SQLite guarda string sem offset. | `Period.php`, `doctrine.yaml` | P2 | M | Padronizar UTC na entrada e converter na apresentação; testar troca de timezone. |
| D14 | `openLoan()` percorre a coleção ao contrário a cada chamada e, se `status` mentir, lança `LogicException` (erro 500). | `Item.php` | P3 | S | Manter referência ao loan aberto no agregado, ou derivar status (D5). |
| D15 | Sem prazo de devolução, renovação, multa, perda/roubo, cancelamento ou remoção de item. Histórico só cresce. | domínio inteiro | P2 | L | Modelar quando o produto pedir; `Period` já comporta `dueAt` mal? (não comporta — será outro VO). |

## 2. Aplicação

| # | Dívida | Onde | Prio | Esforço | Como pagar |
|---|---|---|---|---|---|
| A1 | `LendItemHandler`/`ReturnItemHandler` existem mas **nenhuma rota os usa**; o container os remove por serem serviços privados não referenciados. | `src/Application/Item/` | **P1** | S | Criar as ações no `ItemController` (POST `/items/{id}/loans`, `/items/{id}/return`) + templates; aí passam a ser resolvidos por argumento de action. |
| A2 | `new \DateTimeImmutable()` dentro dos handlers; não há `ClockInterface`, então não dá para testar "emprestado ontem" nem congelar tempo. | `LendItemHandler.php`, `ReturnItemHandler.php` | P1 | S | Injetar `Psr\Clock\ClockInterface` (`symfony/clock`) e passar `$this->clock->now()`; o domínio já recebe a data por parâmetro. |
| A3 | Cada `save()` faz `persist` + `flush`: a "transação" é uma chamada de repositório. Operações compostas futuras (ex.: emprestar + registrar evento) não são atômicas. | `DoctrineItemRepository.php` | P2 | M | Mover o `flush` para uma fronteira explícita (`transactional()`/`wrapInTransaction` no handler ou listener de kernel). |
| A4 | Handlers são `final readonly` e acoplados ao repositório concreto; não há como testá-los sem banco. | `src/Application/Item/` | P2 | M | Extrair interface quando houver segundo adapter (o teste já instancia à mão). |
| A5 | `ListItemsHandler` devolve agregados inteiros; a decisão de "o que a tela vê" mora no Twig. | `ListItemsHandler.php` | P2 | M | Read model/DTO (`ItemView`) com `status`, `currentBorrower`, `lentAt`. |
| A6 | Sem idempotência: duplo submit de `/items/new` cria dois itens; duplo clique em "emprestar" (quando houver UI) gera 409 no segundo. | `ItemController.php` | P3 | M | Token de idempotência/PRG com flash (já é PRG) e teste de duplo submit. |

## 3. Persistência e infraestrutura

| # | Dívida | Onde | Prio | Esforço | Como pagar |
|---|---|---|---|---|---|
| P1 | Repositório concreto sem interface (decisão de escopo): `Application` depende de `DoctrineItemRepository`. | `src/Repository/` | P2 | M | Interface em `src/Domain/Item/` quando existir segundo adapter ou teste in-memory. |
| P2 | Atributos Doctrine nos objetos de domínio (`Item`, `Loan`, `Period`), inclusive `repositoryClass` apontando para `App\Repository`. | `src/Domain/Item/` | P2 | L | Mapping XML/YAML separado, ou classes de persistência + mapeamento explícito. Só compensa quando o domínio ganhar outro transporte. |
| P3 | `allWithLoans()` carrega agregados completos, sem paginação, filtro (`status`, `borrower`) nem ordenação configurável; `ORDER BY title` fixo; sem índice em `title`/`status`/`borrower_id`. | `DoctrineItemRepository.php` | P2 | M | Query de read model com projeção + paginação (`Page`/`Limit`), índices conforme consultas reais. |
| P4 | `ItemNotFound` é `RuntimeException` sem tradução HTTP: hoje nem rota existe; quando existir, vira 500. | `ItemNotFound.php` | P1 | S | `#[AsEventListener]` de `kernel.exception` mapeando `ItemNotFound` → 404 e conflitos → 409. |
| P5 | Conflitos usam `\DomainException` genérica, sem hierarquia nem códigos estáveis; o cliente não distingue "já emprestado" de "não emprestado". | `Item.php` | P2 | S | `ItemException` base + subclasses (`ItemAlreadyLent`, `ItemNotLent`) e um contrato de erro (Problem Details). |
| P6 | Migração única, gerada automaticamente, sem `CHECK` de status, sem índice parcial (D3) e sem revisão de índices. | `migrations/Version20261120220743.php` | P3 | S | Revisar/expandir a migração quando D3/D12 entrarem. |
| P7 | `compose.yaml` + `compose.override.yaml` (Postgres 16) vieram do recipe e **não são usados** — o projeto roda em SQLite. | raiz | P2 | S | Remover os arquivos **ou** adotar Postgres e trocar `DATABASE_URL`; não deixar os dois mundos. |
| P8 | `when@test: doctrine.dbal.dbname_suffix` do recipe continua ativo; inócuo com `sqlite:///:memory:`, mas confunde quem ler. | `doctrine.yaml` | P3 | S | Remover o bloco quando o banco de teste estiver definido. |
| P9 | Mapping `AppDomain` varre `%kernel.project_dir%/src/Domain` inteiro; com mais módulos, cada classe é inspecionada à toa. | `doctrine.yaml` | P3 | S | Mapear por módulo (`src/Domain/Item`) conforme o domínio crescer. |
| P10 | SQLite em dev; banco de produção indefinido. Migrações geradas para SQLite precisam ser regeneradas por plataforma. | `.env` | P2 | M | Decidir produção (Postgres provável, dado P7) antes do primeiro deploy. |
| P11 | `Loan::$item` + `ON DELETE CASCADE` e `orphanRemoval: true`, mas não existe caminho de remoção de item. | `Loan.php`, migração | P3 | — | Manter; documentar que remoção exige decisão de produto (histórico some junto). |
| P12 | Sem projeção de leitura nem cache; toda listagem bate no banco. | — | P3 | — | Só quando houver métrica de uso. |

## 4. HTTP, UI e segurança

| # | Dívida | Onde | Prio | Esforço | Como pagar |
|---|---|---|---|---|---|
| H1 | **CSRF desligado na prática**: `config/packages/csrf.yaml` do recipe existe, mas `symfony/security-csrf` não está instalado; o form sai sem `_token` e `POST /items/new` aceita qualquer origem. | `csrf.yaml`, `templates/item/new.html.twig` | **P1** | S | `composer require symfony/security-csrf` e conferir `_token` no HTML + teste negativo (POST sem token → 403). |
| H2 | Sem autenticação/autorização: qualquer um cadastra, lista, e (quando houver rota) empresta/devolve. Sem `#[IsGranted]`, sem vínculo usuário↔borrower. | `ItemController.php` | P1 | L | SecurityBundle + authenticator quando houver usuários; `BorrowerId` passa a vir da sessão, não do formulário. |
| H3 | Sem tratamento de erro HTTP para domínio: `ItemNotFound`/`DomainException` caem no handler padrão do Symfony (página de exceção em dev, 500 em prod). | `src/` | P1 | S | Listener de exceção (ver P4) + template de erro. |
| H4 | Rotas de emprestar/devolver inexistentes (UI incompleta para o escopo "emprestar/devolver"). | `ItemController.php` | **P1** | M | `POST /items/{id}/loans` com `LendItemType` (borrower) e `POST /items/{id}/return` com CSRF; flash + redirect. |
| H5 | Listagem só mostra título, status e borrower; sem datas, histórico, ou indicação de "desde quando". | `templates/item/index.html.twig` | P2 | S | Expor `currentLoan()` no agregado e renderizar `lentAt` formatado. |
| H6 | Twig percorre `item.loans` para descobrir o empréstimo aberto (O(n) por linha); lógica de domínio vazando para o template. | `templates/item/index.html.twig` | P3 | S | `Item::currentLoan(): ?Loan` (ou read model) e template só formata. |
| H7 | Textos em inglês e status cru (`Available`/`Lent`); sem i18n nem humanização. | templates, exceções | P3 | M | `symfony/translation`, chaves de tradução, `templates` sem string literal. |
| H8 | `.flash` só para sucesso; erros de domínio não têm mensagem amigável. | `ItemController.php` | P2 | S | Com H3/H4, flash de erro + redirect. |
| H9 | Form entrega `null` para campo vazio, forçando `?string $title` no DTO e cast `(string)` no controller. | `RegisterItemInput.php`, `ItemController.php` | P3 | S | `'empty_data' => ''` no campo, ou manter e documentar. |
| H10 | Form inválido responde **422** (comportamento do Symfony 8) e isso não está documentado. | `ItemController.php` | P3 | S | Documentar no README/AGENTS ou fixar o status explicitamente. |
| H11 | CSS inline, sem assets, sem acessibilidade revisada, sem layout responsivo de verdade. | `templates/base.html.twig` | P3 | M | AssetMapper/UX quando houver design. |
| H12 | Sem página de erro customizada (404/409/500) e sem 404 amigável para `/items/{id}` inexistente. | — | P3 | S | Templates `error/*.html.twig`. |

## 5. Testes e qualidade

| # | Dívida | Onde | Prio | Esforço | Como pagar |
|---|---|---|---|---|---|
| T1 | Testes de integração/HTTP criam o schema com `SchemaTool`, **não** com as migrações: mapping e migração podem divergir sem ninguém perceber. | `tests/Integration/`, `tests/Functional/` | P2 | M | Rodar `doctrine:migrations:migrate` no bootstrap de teste (banco de arquivo por execução) ou teste que compare `SchemaTool::getUpdateSchemaSql()` vazio. |
| T2 | Sem isolamento transacional entre testes (não há `dama/doctrine-test-bundle`); funciona porque cada teste cria schema em `:memory:`. | testes de integração | P2 | S | Damas + banco de teste migrado, quando a suíte crescer. |
| T3 | Handlers são instanciados à mão no teste (porque são removidos do container). | `tests/Integration/ItemHandlersTest.php` | P2 | S | Voltar a resolver do container assim que A1/H4 existirem. |
| T4 | Sem fixtures/factories; objetos montados à mão em cada teste. | testes | P3 | S | Object mothers quando houver mais de um agregado. |
| T5 | Sem análise estática (PHPStan/Psalm), sem cobertura mínima, sem mutation testing. Só Pint. | `composer.json` | P2 | M | `phpstan` nível 6+ no CI antes de crescer o domínio. |
| T6 | `pint.json` não tem `exclude`; rodar `vendor/bin/pint` sem paths reformata `var/cache` e polui a saída. | `pint.json` | P3 | S | `"exclude": ["var", "vendor"]`. |
| T7 | Sem testes de concorrência (D4), timezone (D13) ou 404/409 (P4/H3). | testes | P2 | M | Um teste por dívida paga, no mesmo PR. |
| T8 | Setup de schema repetido em cada classe de teste. | testes | P3 | S | `KernelTestCase` base com `setUpSchema()`. |

## 6. Ambiente, repositório e processo

| # | Dívida | Onde | Prio | Esforço | Como pagar |
|---|---|---|---|---|---|
| E1 | O diretório **não é um repositório git**: sem histórico, sem diff revisável, sem PR para linkar dívida. | raiz | P1 | S | `git init`, `.gitignore` já existe, primeiro commit da V1. |
| E2 | Stubs residuais de outro exercício: `src/Entity/User.php` (classe vazia), `src/Formatters/*` (3 arquivos), `src/DTO/Input/CreateUser.php` e o alias manual `App\Formatters\FormatterInterface → HtmlFormatter` em `config/services.yaml` (fora do padrão attribute/autowiring). | `src/`, `config/services.yaml` | P2 | S | Confirmar que não são usados e remover. |
| E3 | Web profiler não instalado; `AGENTS.md` sugere `/_profiler`, que responde 404. | `composer.json` | P3 | S | `composer require --dev symfony/profiler-pack`. |
| E4 | Sem README: como rodar, rotas, decisões e dívida só existem na conversa/AGENTS.md. | raiz | P2 | S | README curto + link para este arquivo. |
| E5 | Sem CI (lint, testes, schema validate) e sem deploy definido. | — | P2 | M | Pipeline mínimo: `lint:container`, `doctrine:schema:validate`, `phpunit`, `pint --test`. |
| E6 | Sem comandos de seed/demonstração; para ver a tela é preciso cadastrar na mão. | `src/` | P3 | S | `#[AsCommand] app:demo:seed` (fora de produção). |
| E7 | `var/data_dev.db` é artefato local (já ignorado por `/var/`); sem política de reset além de apagar o arquivo. | `var/` | P3 | S | Comando `app:db:reset` (drop + migrate) quando incomodar. |
| E8 | Sem `.env.local` nem secrets gerenciados; `APP_SECRET` de teste é fixo. | `.env`, `.env.test` | P3 | S | Secrets vault quando houver credencial real. |

---

## 7. Versão 2 — Prazos, Atrasos e Renovação

Data: 2026-11-21
Escopo entregue: `Period` com `dueAt`; atraso em dias de calendário (`OverdueDays`); `Clock` injetável
(`symfony/clock`, `MockClock` nos testes); renovação append-only (`LoanRenewal`) integrada ao agregado
(`Item::renewLoan()` / `Item::currentLoan()`); read models DBAL (`WhoHasWhatQuery`, `OverdueItemsQuery`,
`ItemOverviewQuery` + `ItemRow`); rotas de emprestar, devolver e renovar; listagem Twig com filtro
`all`/`available`/`lent`/`overdue`.
Continua fora do escopo: multa, limite por borrower, fila de reservas, Domain Events, autenticação,
Messenger (o controller chama os handlers diretamente).

### 7.1 Dívidas da V1 pagas na V2

| # | Dívida | Como foi paga |
|---|---|---|
| A2 | `Clock` não injetável; `new \DateTimeImmutable()` dentro dos handlers. | `symfony/clock` + `Psr\Clock\ClockInterface` nos handlers e nas queries; `MockClock`/`ClockSensitiveTrait` nos testes de integração e funcionais. |
| A1 / H4 | Handlers de emprestar/devolver sem rota; UI sem essas ações. | `POST /items/{id}/loans`, `/items/{id}/return` e `/items/{id}/renewals` no `ItemController`, com formulário e flash. |
| H6 | Twig percorria `item.loans` para achar o empréstimo aberto. | `Item::currentLoan()` no agregado e empréstimo já resolvido em `ItemRow` (a tela nem toca no agregado). |
| H5 | Listagem sem datas nem "desde quando". | Colunas emprestado em / prazo / atraso no read model e na tabela. |
| H8 | Só flash de sucesso. | `base.html.twig` renderiza flashes de `error`; `\DomainException` vira flash + redirect. |
| A5 | `ListItemsHandler` devolvia agregados inteiros para a tela. | Classe removida; a listagem usa `ItemOverviewQuery`/`OverdueItemsQuery` + `ItemRow`. |
| T3 | Handlers instanciados à mão por não estarem no container. | O controller os referencia; voltaram a ser serviços privados resolvidos pelo container. |

Continuam abertas da V1 (não foram pagas na V2): D1/D2 (Loan mutável e construtor público), D3, D4,
H1 (CSRF), H2 (autenticação), H3/P4 (404/409 via listener), E1 (repositório git), E2 (stubs), T1
(schema de teste fora das migrações).

### 7.2 Dívidas da V2

| # | Dívida | Onde | Prio | Esforço | Como pagar |
|---|---|---|---|---|---|
| **V2-1** | **Prazo efetivo tem duas fontes**: `Period::dueAt` (original) e `Loan::effectiveDueAt()` (última renovação). Toda query repete a subquery; um `JOIN`/subquery errado mostra prazo velho. | `Loan.php`, `src/ReadModel/*Query.php` | **P1** | M | Denormalizar `current_due_at` no `loan`, atualizado na mesma transação da renovação (continua append-only, mas com cache), ou criar view `v_loan_current`. |
| **V2-2** | **Regra de atraso duplicada em dois mundos**: `OverdueDays` (PHP) e `effective_due_at < :startOfToday` (SQL). Hoje um teste amarra os dois, mas a duplicação continua. | `OverdueDays.php`, `OverdueItemsQuery.php` | **P1** | M | View `v_loan_overdue` no banco, ou calcular o atraso sempre em PHP a partir de uma query única. |
| **V2-10** | Ainda **um empréstimo aberto** sem garantia no banco (D3). Agora com mais um motivo: os read models assumem 1:1 e duplicariam linhas se houvesse dois `returned_at IS NULL`. | `migrations/` | **P1** | S | Índice único parcial `ON loan (item_id) WHERE returned_at IS NULL`. |
| **V2-11** | Ainda sem `#[Version]`: renovar e devolver em paralelo pode produzir histórico incoerente (renovação em loan já fechado). | `Item.php`, `Loan.php` | **P1** | S | `#[ORM\Version]` em `Loan` (ou no `Item`) + tradução de `OptimisticLockException` em 409. |
| **V2-16** | Rotas novas (emprestar/devolver/renovar) sem **CSRF** e sem **autenticação**: qualquer origem dispara POST e qualquer pessoa informa o `borrowerId`. | `ItemController.php`, templates | **P1** | M | `symfony/security-csrf` + `_token` nos três POSTs (teste negativo), SecurityBundle e `borrowerId` vindo da sessão. |
| V2-3 | Renovação **sem limite**, sem multa, sem aprovação e sem autor (`renewedAt` não diz quem renovou). | `LoanRenewal.php` | P2 | M | V3: multa/Money + regra de limite; autoria quando houver usuário. |
| V2-4 | Atraso é sempre "dias de calendário" e não configurável (empresa pode querer horas/24h corridas). | `OverdueDays.php` | P2 | S | Parametrizar a regra quando houver requisito; manter o teste de DST. |
| V2-5 | Prazo de empréstimo sem default configurável (`app.loan.default_days`); a tela obriga digitar a data toda vez. | `LendItemType.php` | P2 | S | Parameter + valor inicial no formulário. |
| V2-6 | Read models com SQL escrito à mão, hidratação manual, **sem paginação**, sem cache e sem serializer. | `src/ReadModel/` | P2 | M | Primeiro paginação por cursor; cache só com métrica. |
| V2-7 | Filtro da listagem faz fallback silencioso (`?filter=xyz` vira `all`); sem 400 nem validação. | `ItemFilter.php` | P3 | S | Documentar no README ou rejeitar com 400. |
| V2-8 | Sem índices para os read models: `loan(due_at)`, `loan(borrower_id)`, `loan` parcial por `returned_at`, e `loan_renewal(loan_id, id)` para a subquery. | `migrations/` | P2 | S | Migração de índices junto com V2-10. |
| V2-9 | Backfill da V1 inventou `due_at = lent_at + 14 dias`; itens antigos nasceram com prazo fictício. | `Version20261121143955.php` | P3 | S | Documentado na migração; corrigir manualmente se algum dado real aparecer. |
| V2-12 | `status` continua coluna redundante; "atrasado" não entra nele (correto), mas a tela mistura coluna persistida e cálculo. | `Item.php`, `ItemRow` | P2 | M | Derivar status de `currentLoan()` quando o read model for a única leitura. |
| V2-13 | Erros de domínio na tela são `try/catch` no controller com flash; sem 404/409 nem códigos estáveis. | `ItemController.php` | P2 | M | `#[AsEventListener]` de `kernel.exception` (H3/P4) + Problem Details. |
| V2-14 | Clock só nos handlers e read models; qualquer código futuro que use `new \DateTimeImmutable()` fura o determinismo sem aviso. | `src/` | P2 | S | Regra no `AGENTS.md` + grep no CI. |
| V2-15 | Timezone continua naive (D13); o teste de DST fixa a regra, não a política (UTC vs local). | `OverdueDays.php`, banco | P2 | M | Padronizar UTC na entrada e converter na apresentação. |
| V2-17 | Listagem não pagina e carrega todos os itens/loans; read model resolve o shape, não o volume. | `ItemOverviewQuery.php` | P2 | M | Paginação por cursor no `ItemOverviewQuery`. |
| V2-18 | `ItemRow`/`ItemFilter` em `src/ReadModel` misturam DTO de tela com contrato de query. | `src/ReadModel/` | P3 | S | Separar quando houver segundo consumidor (API). |

**Surgidas durante a implementação:**

| # | Dívida | Onde | Prio | Esforço | Como pagar |
|---|---|---|---|---|---|
| V2-19 | A listagem cria **um `Form` por linha** (um `lend_<id>` ou `renew_<id>` por item) só para renderizar inputs; com muitas linhas isso custa memória e tempo. | `ItemController.php` | P2 | S | Um formulário só com `id` oculto, ou inputs HTML simples validados por DTO (`MapRequestPayload` quando houver API). |
| V2-20 | Mensagens de domínio misturam inglês e português (`Item is already lent.`, `Item não está emprestado`, `A renovação deve estender o prazo`) e vão cruas para a flash. | `Item.php`, `Loan.php` | P3 | S | Unificar idioma junto com H7 (i18n) e mapear exceção → mensagem de UI. |

---

## Decisões já tomadas (não são dívida, registre antes de "consertar")

- Removidos `src/Controller/DefaultController.php` (exigia `MessageBusInterface`, não instalado) e
  `src/Repository/UserRepository.php` (namespace `App\Repositories`, quebrava a descoberta de serviços).
- `ItemStatus` é enum **backed** para permitir `#[ORM\Column(enumType: ...)]`.
- `Period` é **embeddable** com `columnPrefix: false`: datas na tabela `loan`, sem tabela extra.
- `ItemId`/`BorrowerId` usam **custom DBAL types** (`item_id`, `borrower_id`) em vez de string crua.
- Persistência é `ServiceEntityRepository` concreto, sem interface; `save()` faz `persist` + `flush`.
- UI escolhida: **Twig server-rendered** (não API JSON); sem Serializer/`MapRequestPayload`.
- Sem Messenger, sem Domain Events, sem interface de repositório — escopo explícito da V1.
- **V2:** renovação é **registro novo** em `loan_renewal`, não mutação do `Loan`; sem `previousDueAt` (o prazo
  anterior vem da cadeia de renovações).
- **V2:** `Period::dueAt` é o prazo **original** e nunca é reescrito; `Loan::effectiveDueAt()` resolve o vigente.
- **V2:** atraso é sempre em **dias de calendário** (`dueAt` conta como "em dia" até o fim do dia) e não é status.
- **V2:** `Clock` injetável é `Psr\Clock\ClockInterface`; nos testes funcionais o tempo é congelado pelo
  `ClockSensitiveTrait` (o serviço `clock` do container delega para o clock global).
- **V2:** sem Messenger, então o controller chama os handlers diretamente (fallback previsto no plano).
- **V2:** o formulário só valida "data não pode estar no passado" (constraint própria, com clock injetado);
  "nova data > prazo vigente" é regra do agregado, não do form (senão o `MockClock` não valeria).

---

## Próximos pagamentos sugeridos (ordem)

1. **V2-10 + V2-11 (D3 + D4)** — garantia de um empréstimo aberto no banco e concorrência otimista.
2. **V2-16 + H1 + H2** — CSRF e autenticação nas rotas novas (hoje qualquer origem/pessoa empresta).
3. **V2-1 + V2-2** — acabar com a dupla fonte do prazo efetivo e com a regra PHP/SQL duplicada.
4. **V2-17 + V2-19** — paginação e um formulário por linha custam pouco agora, muito depois.
5. **E1 + E2 + T6** — versionar o projeto, remover stubs residuais e domar o Pint.
