# 📚 Lending System

Um sistema de controle de empréstimo de itens (livros, ferramentas, equipamentos) desenvolvido para demonstrar como aplicar **Domain-Driven Design (DDD)**, **Arquitetura Hexagonal** e **CQRS (Leve)** em um projeto real no Symfony, sem perder a sustentabilidade e a performance ao longo do tempo.

O grande objetivo deste projeto é servir como um estudo prático de **como escalar a complexidade de um domínio** (prazos, atrasos, renovações, filas de espera e assincronismo) **mantendo a base de código testável, previsível e de fácil manutenção**.

---

## 🛠️ Tecnologias Utilizadas

*   **PHP 8.3+ / Symfony 7+**
*   **Doctrine ORM & DBAL** (com Custom DBAL Types para Value Objects)
*   **Symfony Messenger** (para processamento assíncrono e filas)
*   **Symfony UX / Twig Components** (com UI baseada em *shadcn/ui* e Tailwind CSS)
*   **PHPUnit** (Testes Unitários puros e Testes de Integração com banco real)

---

## 🏗️ Arquitetura: Domínio Rico e Hexagonal

O sistema é estruturado para proteger regras de negócio complexas (invariantes) e isolar o núcleo da aplicação de detalhes de infraestrutura (banco de dados, HTTP, filas).

### Camadas
*   **Domain (Núcleo):** Contém as Entidades, *Aggregates*, *Value Objects* e *Domain Events*. É **PHP puro**. Não possui importações do Symfony ou Doctrine.
*   **Application:** Casos de uso (Handlers). Orquestra o carregamento dos Aggregates, a execução das regras e a persistência via Interfaces (Portas).
*   **Infrastructure:** Implementações concretas (Controllers, Repositórios Doctrine, Mensageria, Templates).

### Padrões e Princípios Aplicados
*   **Aggregates e Invariantes:** O `Item` é a raiz de agregação para empréstimos. O `Reservation` é um agregado separado para a fila. As regras (ex: *"um item emprestado não pode ser emprestado novamente"*) vivem dentro do domínio, não em Services ou Controllers.
*   **Value Objects (VOs):** Tipos como `ItemId`, `Period`, `OverdueDays` encapsulam validações e comportamentos, evitando o uso de tipos primitivos soltos (Primitive Obsession).
*   **Inversão de Dependência:** O domínio dita o que precisa (ex: `ReservationPolicy`) e a infraestrutura fornece a implementação.
*   **CQRS Leve:** Escrita e Leitura são separadas. Os Aggregates são usados apenas para mutação (Commands). A leitura (telas) é feita por *Read Models* otimizados (Queries com DBAL e DTOs).

---

## 🧱 Princípios SOLID: A Teoria na Prática

A arquitetura do sistema aplica princípios SOLID para resolver problemas reais que surgiram durante a evolução do projeto (V1 a V3).

### 1. Single Responsibility Principle (SRP)
*Uma classe deve ter um, e apenas um, motivo para mudar.*
*   **Como foi aplicado:** O tratamento de exceções foi retirado dos Controllers. Criamos `EventSubscribers` (Listeners) específicos para `kernel.exception`. O `NotifyNextInQueueHandler` só sabe notificar o próximo da fila; o `ReturnItemHandler` só sabe devolver o item.
*   **A diferença crucial:** Se amanhã a regra de "como notificar" mudar (ex: enviar e-mail em vez de log), alteramos apenas um Handler. O Controller e a regra de devolução permanecem intocados. Isso evita o "Controller Gordo" e o "God Service".

### 2. Open/Closed Principle (OCP)
*Entidades de software devem estar abertas para extensão, mas fechadas para modificação.*
*   **Como foi aplicado:** O tratamento de erros de domínio usa uma **Chain of Responsibility** via Event Listeners. Quando adicionamos a exceção `ItemIsAvailableForReservation` ou `BorrowerAlreadyQueued`, nós simplesmente criamos um novo listener para capturá-la.
*   **A diferença crucial:** Nós adicionamos novos comportamentos de erro (e até separamos respostas de API JSON de respostas HTML/Twig) **sem modificar** um único caractere dos listeners de erro que já existiam. O código antigo continua estável enquanto o sistema cresce.

### 3. Liskov Substitution Principle (LSP)
*Objetos de uma superclasse devem ser substituíveis por objetos de suas subclasses sem quebrar a aplicação.*
*   **Como foi aplicado:** O `ClockInterface` (PSR-20). O código de produção injeta o relógio real do sistema, mas os testes injetam o `MockClock` (ou um `FrozenClock`).
*   **A diferença crucial:** A capacidade de "viajar no tempo" sem esperar dias reais para testar expirações de prazos e regras de atraso. A aplicação não sente a diferença entre o relógio real e o simulado, garantindo testes determinísticos e em milissegundos.

### 4. Interface Segregation Principle (ISP)
*Uma classe não deve ser forçada a implementar interfaces que não usa.*
*   **Como foi aplicado:** Em vez de carregar o Agregado inteiro para a UI, criamos os **Read Models** (`ItemRow`, `ReservationRow`). Além disso, no lado da escrita, temos interfaces de repositório segregadas (ex: a lógica de reserva não conhece métodos de renovação).
*   **A diferença crucial:** A camada de apresentação (Twig) recebe apenas um DTO leve com os campos que a tela precisa. Se adicionarmos 10 novos métodos no `ItemRepository` para relatórios, o `ItemOverviewQuery` (que monta a tela) não precisa ser alterado nem recompilado.

### 5. Dependency Inversion Principle (DIP)
*Módulos de alto nível não devem depender de módulos de baixo nível. Ambos devem depender de abstrações.*
*   **Como foi aplicado:** O coração do sistema (Domain) não conhece o Symfony, o Doctrine ou o Messenger. O `ReturnItemHandler` (Application) não conhece o banco; ele despacha um Domain Event. O Symfony Messenger transforma esse evento em uma `Message` e a envia para uma fila.
*   **A diferença crucial:** Isso é o que permite a **suíte de testes ultrarrápida**. Nós podemos testar a regra "não é possível reservar um item disponível" instanciando `new Item()` e `new ReservationPolicy()`, sem precisar subir o banco de dados ou o kernel do framework. O banco é apenas um detalhe substituível.

---

## 🚀 Como o sistema escala (Pontos de Escalabilidade)

### 1. Testabilidade Instantânea (Domínio Puro)
Como as regras de negócio não conhecem o banco de dados nem o framework, **70% da suíte de testes roda em milissegundos**. Isso permite refatorar o núcleo do sistema sem medo.

### 2. Read Models Otimizados (Sem N+1)
As telas de listagem (ex: *Quem está com o quê*, *Fila de reservas*) não carregam os Aggregates via ORM. Elas usam **DBAL puro e DTOs**. Isso evita problemas graves de performance (N+1) e o consumo excessivo de memória do Doctrine. O SQL é explícito e performático.

### 3. Processamento Assíncrono (Mensageria)
Quando um item é devolvido, o sistema não bloqueia a resposta HTTP do usuário para notificar a fila. O domínio registra um **Domain Event** (`ItemReturned`), e a camada de aplicação o converte em uma mensagem (`NotifyNextInQueue`) despachada via **Symfony Messenger** para o transporte `async`. O worker processa isso em background com políticas de retry.

### 4. Concorrência e Constraint no Banco
Regras que cruzam múltiplos Aggregates (ex: *"um usuário não pode reservar o mesmo item duas vezes"*) são garantidas por **índices únicos parciais no banco de dados** (`WHERE status IN ('pending', 'notified')`), aliados à validação na aplicação. O banco atua como a última linha de defesa contra condições de corrida.

---

## 🧾 Gestão de Dívida Técnica (Como não afundar o projeto)

Nenhum projeto complexo é escrito perfeitamente na primeira tentativa. A chave para a escalabilidade a longo prazo não é evitar a dívida técnica, mas **gerenciá-la conscientemente**.

### O `DIVIDA.md` e a Tomada de Decisão
Todo o projeto é guiado por um arquivo `DIVIDA.md` rigorosamente mantido. A regra é: *"Se fizermos algo rápido e não ideal, devemos documentar o porquê, o impacto e a prioridade de pagamento."*

**Exemplos de Dívidas Conscientes e como foram geridas:**
1.  **Mapeamento Doctrine no Domínio (Atributos):** Em vez de usar mapeamento XML e manter o domínio 100% limpo, aceitamos os atributos do Doctrine nas entidades. *Trade-off:* Acelera o desenvolvimento. *Mitigação:* A lógica de negócio continua isolada e testável sem banco.
2.  **Ausência de Transactional Outbox (V3):** A notificação da fila é despachada *após* o `flush()` do banco. Se o worker falhar entre o commit e o dispatch, a mensagem pode ser perdida. *Trade-off:* Implementar Outbox exige uma tabela extra e mais complexidade. *Mitigação:* Registrado como dívida prioritária para a V4.
3.  **Idempotência de Mensagens:** O Symfony Messenger garante entrega *at-least-once* (pelo menos uma vez). Para não notificar o mesmo usuário duas vezes, a aplicação verifica o `notified_at` da reserva antes de agir.

### O que já foi pago (Refatorações bem-sucedidas)
*   **Injeção do `Clock`:** Na V1, usávamos `new \DateTimeImmutable()`. Isso impedia testes determinísticos com tempo (atrasos, expirações). Na V2, o tempo foi abstraído via injeção de dependência.
*   **Fim do "Controller Gordo":** Erros de domínio (ex: *"Item indisponível"*) não são mais capturados com `try/catch` nos Controllers. A **Chain of Responsibility** resolveu isso (exemplo claro de SRP e OCP em ação).

---

## ⚙️ Como Executar o Projeto

1. **Instale as dependências:**
   ```bash
   composer install
   ```

2. **Configure o banco de dados e rode as migrações:**
   ```bash
   php bin/console doctrine:migrations:migrate
   ```

3. **Compile os assets (Tailwind):**
   ```bash
   php bin/console tailwind:build
   ```

4. **Inicie o servidor web:**
   ```bash
   symfony serve -d
   ```

5. **Inicie o worker do Messenger (para notificações assíncronas de reserva):**
   ```bash
   php bin/console messenger:consume async -vv
   ```

6. **Rode a suíte de testes:**
   ```bash
   php bin/phpunit
