# Teste Técnico — Gerador de Relatórios (Inffus)

Aplicação fullstack de faturamento com autenticação, gestão de clientes/cobranças e relatório de faturamento preparado para grandes volumes.

## Stack

- **Backend:** PHP 8.4 + Laravel 13 + Sanctum + DomPDF
- **Frontend:** Next.js 15 + TypeScript + Tailwind CSS
- **Banco:** MySQL 8.4
- **Filas:** RabbitMQ 3.13 (exportação assíncrona de relatórios)
- **Infra:** Docker + Docker Compose + GitHub Actions (CI)

## Como executar

Pré-requisitos: Docker e Docker Compose.

```bash
cp .env.example .env
docker compose up --build
```

Serviços:

| Serviço | URL |
|---------|-----|
| Frontend | http://localhost:3000 |
| Backend API | http://localhost:8000/api |
| MySQL | localhost:3306 |
| RabbitMQ Management | http://localhost:15672 (guest/guest) |

Login padrão (seed):

- E-mail: `admin@inffus.test`
- Senha: `password`

Para gerar mais dados de teste:

```bash
docker compose exec backend php artisan db:seed
# ou com volume maior
docker compose exec -e BILLING_SEED_COUNT=50000 backend php artisan db:seed --class=BillingSeeder
```

## Como executar os testes

```bash
docker compose exec backend php artisan test
```

Os testes usam SQLite em memória (`phpunit.xml`).

## CI (GitHub Actions)

Pipeline em `.github/workflows/ci.yml`:

- **Backend:** PHPUnit (PHP 8.4)
- **Frontend:** ESLint + build Next.js
- **Docker:** validação do `docker-compose.yml` + build das imagens

Em produção real, o mesmo pipeline alimentaria ambientes de staging/homolog/prod; neste teste técnico mantemos CI executável no fork, sem dependência de infra externa.

## Arquitetura — exportação assíncrona

O módulo de relatórios mantém exportação **síncrona** (CSV stream / PDF imediato) para volumes pequenos e adiciona exportação **assíncrona** via fila para grandes volumes.

```text
Frontend ──► Laravel API ──► MySQL
                 │
                 │ dispatch ProcessReportExport
                 ▼
            RabbitMQ (report.exports)
                 │
                 ▼
         report-worker (reports:consume-exports)
                 │
                 ▼
      storage/app/report-exports/{uuid}.csv|pdf
                 │
                 ▼
Frontend ◄── polling status + download
```

O worker é um processo dedicado (`report-worker`) que consome mensagens RabbitMQ via `php-amqplib`, sem acoplar o CRUD ao pacote de filas do Laravel — evitando incompatibilidades de versão e mantendo controle sobre retry/ack.

### Endpoints de exportação assíncrona

| Método | Endpoint | Descrição |
|--------|----------|-----------|
| `POST` | `/api/reports/billing/exports` | Enfileira exportação (`format`: csv ou pdf) |
| `GET` | `/api/reports/billing/exports/{id}` | Consulta status (`pending` → `processing` → `completed` / `failed`) |
| `GET` | `/api/reports/billing/exports/{id}/download` | Download do arquivo gerado |

Exportação síncrona (mantida):

- `GET /api/reports/billing/export/csv`
- `GET /api/reports/billing/export/pdf`

### Por que microserviço no worker de relatórios

- Relatórios com milhões de linhas não devem bloquear a API HTTP.
- PDF (DomPDF) consome memória; isolar em worker evita impacto no CRUD.
- RabbitMQ permite retry, backpressure e escala horizontal de workers.
- CRUD, auth e consulta paginada permanecem no monolito Laravel (escopo adequado ao teste).

## Regra de juros (compostos)

Cobranças vencidas e não pagas têm o valor atualizado calculado **em tempo real no backend**:

```text
valor_atualizado = valor_original × (1 + taxa_mensal) ^ (dias_em_atraso / 30)
juros = valor_atualizado - valor_original
```

- Implementação: `backend/app/Services/InterestCalculator.php`
- Cobrança paga **não** continua acumulando juros; no pagamento são persistidos `payment_date`, `paid_amount` e `interest_amount_at_payment`.

## Decisões técnicas

- API REST com Laravel Sanctum (Bearer token) para proteger rotas e endpoints.
- Frontend Next.js consome apenas a API; estado de sessão via `localStorage`.
- Cálculo de juros centralizado no backend para garantir consistência em listagens, detalhe e relatórios.
- Relatórios com filtros/ordenação/paginação no banco; eager load de `customer` para evitar N+1.
- CSV streaming com `lazyById` (não carrega tudo em memória).
- PDF síncrono limitado a 2.000 linhas; exportação em fila grava arquivo em disco para download posterior.

## Estratégia de performance

### Índices criados

**customers**

- `name`, `email`, `status`
- `document` único

**billings**

- `customer_id`, `status`, `issue_date`, `due_date`, `payment_date`
- compostos: `(status, issue_date)`, `(status, due_date)`, `(status, payment_date)`, `(customer_id, status)`, `(customer_id, issue_date)`

**report_exports**

- `(user_id, status)`, `created_at`

### Por que esses índices

Os filtros do relatório combinam status + campo de data e opcionalmente cliente. Os compostos cobrem os caminhos mais comuns do `WHERE`/`ORDER BY` sem full scan em volumes altos.

### Comportamento com milhões de registros

- Listagens e relatório paginam no MySQL.
- Filtros e ordenação ficam na query.
- Totalizadores percorrem o conjunto filtrado com cursor (`cursor()`), sem materializar a lista completa em arrays PHP.
- Exportações grandes são enfileiradas; o worker processa com streaming (CSV) ou limite documentado (PDF).

### Exportações em grande volume

- **CSV síncrono/assíncrono:** stream por chunks de 500 IDs via `lazyById`.
- **PDF síncrono/assíncrono:** renderização HTML→PDF em memória; teto de 2.000 linhas por job.
- **Fila:** RabbitMQ com fila `report.exports`, worker dedicado, 3 tentativas, timeout de 600s.

### Melhorias adicionais em produção

- Armazenamento S3 + URL assinada para downloads
- Dead-letter queue para jobs falhos
- Read replica / particionamento por data em `billings`
- Cache Redis de totalizadores
- Observabilidade (slow query log, APM, métricas de fila)
- CD para staging/homolog/prod com secrets por ambiente

## Estrutura

```text
backend/           API Laravel + jobs de exportação
frontend/          Next.js
docker-compose.yml backend, frontend, mysql, rabbitmq, report-worker
.github/workflows/ CI
.env.example
.cursor/           instruções usadas no desenvolvimento assistido por IA
```

## Uso de IA no desenvolvimento

Foi utilizado suporte de agentes de IA durante a implementação. As orientações ficam em `.cursor/rules/`.
