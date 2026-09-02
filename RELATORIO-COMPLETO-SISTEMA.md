# Relatório Completo do Sistema Getfy

**Versão:** 2.0.4 | **Framework:** Laravel 12 + Vue 3 (Inertia.js) | **Data:** 24/08/2026

---

## 1. VISÃO GERAL

Getfy é uma **plataforma multi-tenant para venda de produtos digitais** (infoprodutos, áreas de membros, checkout, afiliados, assinaturas). Arquitetura em containers Docker com 4 serviços.

---

## 2. ARQUITETURA DE INFRAESTRUTURA

### 2.1 Stack Tecnológica

| Camada | Tecnologia |
|--------|-----------|
| Backend | Laravel 12 / PHP 8.2+ / Composer |
| Frontend | Vue 3 (Composition API) / Inertia.js / Pinia |
| CSS | Tailwind CSS v4 |
| Build | Vite 7 |
| Database | MySQL 8.0 |
| Cache/Fila | Redis 7 |
| Servidor | Nginx + PHP-FPM (Alpine Linux) |
| Container | Docker / Docker Compose |

### 2.2 Containers Docker

```
┌─────────────────────────────────────────────┐
│  APP CONTAINER (nginx:80 + php-fpm:9000)    │
│  → Todas as requisições HTTP, API, webhooks │
│  → Supervisord gerencia nginx + php-fpm     │
└──────────────────┬──────────────────────────┘
                   │
    ┌──────────────┼──────────────┐
    │              │              │
┌───▼────┐  ┌─────▼─────┐  ┌────▼──────────┐
│ MySQL  │  │   Redis    │  │ QUEUE CONTAINER│
│  8.0   │  │    7       │  │ queue:work     │
│ (dados)│  │(cache/fila)│  │ schedule:work  │
└────────┘  └───────────┘  └───────────────┘
```

### 2.3 Volumes Docker

| Volume | Uso |
|--------|-----|
| `mysql_data` | Dados do banco MySQL |
| `getfy_storage` | Cache, logs, uploads do Laravel |
| `getfy_env` | Chaves, plugins instalados, VAPID keys |

### 2.4 Scripts de Entrada (Entrypoint)

Sequência de inicialização do container `app`:
1. Criar diretórios necessários (cache, sessions, views, bootstrap/cache, .docker)
2. Gerar `APP_KEY` se ausente
3. Copiar `.env.example` → `.env` se ausente
4. Escrever variáveis do Docker no `.env`
5. Aguardar MySQL (até 60s)
6. Rodar `composer install`, `package:discover`, `migrate`, `pwa:vapid`
7. Persistir chaves VAPID em arquivo compartilhado

---

## 3. ESTRUTURA DE DIRETÓRIOS

| Diretório | Finalidade |
|-----------|-----------|
| `app/` | Código core: Controllers, Models, Jobs, Services, Middleware, Providers, Events, Plugins SDK |
| `bootstrap/` | Bootstrap do Laravel: `app.php`, `providers.php`, `cache/` |
| `config/` | 26 arquivos de configuração (core Laravel + Getfy custom) |
| `database/` | Migrations, seeders, factories |
| `docker/` | Suporte Docker: nginx, PHP-FPM, supervisord, entrypoint, scripts |
| `plugins/` | Plugins exemplos (commerce, gateway, integration, member, starter, white-label) |
| `public/` | Web root: `index.php`, build assets, service workers |
| `resources/` | Frontend: `css/`, `js/`, `views/`, `schemas/` |
| `routes/` | `web.php` (892 linhas), `api.php` (32 linhas), `console.php` (8 linhas) |
| `storage/` | Logs, cache, sessions, views compilados, uploads |
| `tests/` | `Feature/`, `Unit/`, `fixtures/`, `js/` |

---

## 4. MODELOS DE DADOS (68 Models)

### 4.1 Modelo Principal: `products` (UUID PK)

```
products (UUID)
├── id (CHAR 36 UUID)
├── tenant_id (INDEX)
├── name, slug (INDEX), checkout_slug (UNIQUE)
├── checkout_config (JSON)
├── description, type, billing_type
├── image, price, currency (default BRL)
├── is_active
├── cajupay_split_payout_enabled
├── conversion_pixels (JSON)
├── member_area_config (JSON)
├── combo_product_ids (JSON)
└── timestamps

Tipos de produto:
  - area_membros (área de membros)
  - area_membros_externa
  - aplicativo
  - link
  - link_pagamento
```

### 4.2 Modelo: `users`

```
users
├── id, name, email (UNIQUE), avatar
├── role (default 'aluno')
├── tenant_id (INDEX)
├── team_role_id (FK → team_roles)
├── username (UNIQUE)
├── pix_key, pix_key_type, pix_owner_document
├── email_verified_at, password, remember_token
└── timestamps

Roles: admin, infoprodutor, aluno, team, coprodutor, afiliado
```

### 4.3 Modelo: `orders`

```
orders
├── id, tenant_id
├── user_id (FK → users)
├── product_id (FK → products, NULLABLE)
├── product_offer_id (FK → product_offers)
├── subscription_plan_id (FK → subscription_plans)
├── api_application_id (FK → api_applications)
├── status (default 'pending')
├── amount, currency (default BRL)
├── email, cpf, phone, customer_ip, country_code
├── coupon_code, gateway, gateway_id
├── approved_manually, metadata (JSON)
├── period_start, period_end, is_renewal
├── recovery_email_stage/last_sent_at/next_at
├── recovery_sms_stage/last_sent_at/next_at
└── timestamps
```

### 4.4 Relacionamentos Principais

```
User ──┬── belongsToMany(Product) via product_user
       ├── hasMany(Subscription)
       ├── hasMany(SavedPaymentMethod)
       ├── hasMany(ProductCoproducer)
       ├── hasMany(ProductAffiliate)
       ├── hasMany(CommissionEntry)
       ├── hasMany(WalletTransaction)
       └── belongsTo(TeamRole)

Product ──┬── belongsToMany(User) via product_user
          ├── hasOne(ProductAffiliateProgram)
          ├── hasMany(ProductCoproducer)
          ├── hasMany(ProductAffiliate)
          ├── hasOne(MemberAreaDomain)
          ├── hasMany(MemberSection → MemberModule → MemberLesson)
          ├── hasMany(MemberTurma)
          ├── hasMany(MemberComment)
          ├── hasMany(MemberCommunityPage)
          ├── hasMany(MemberAchievementUnlock)
          ├── hasMany(ProductOffer)
          ├── hasMany(SubscriptionPlan)
          ├── hasMany(ProductOrderBump)
          └── hasMany(RefundRequest)

Order ──┬── belongsTo(User, Product, ProductOffer, SubscriptionPlan, ApiApplication)
        ├── hasMany(OrderItem)
        ├── hasOne(CheckoutSession)
        ├── hasOne(RefundRequest)
        └── hasMany(CommissionEntry)
```

### 4.5 Tabelas por Categoria

| Categoria | Tabelas |
|-----------|---------|
| Core/Framework | users, password_reset_tokens, sessions, cache, cache_locks, jobs, job_batches, failed_jobs |
| Comércio | products, product_user, orders, order_items, coupons, coupon_product, product_offers, subscription_plans, saved_payment_methods, subscriptions, product_order_bumps, checkout_sessions, commerce_carts, commerce_cart_lines, commerce_checkout_sessions |
| Área de Membros | member_area_domains, member_sections, member_modules, member_lessons, member_lesson_progress, member_lesson_likes, member_lesson_pdf_annotations, member_internal_products, member_turmas, member_turma_user, member_comments, member_community_pages, member_community_posts, member_community_post_likes, member_community_post_comments, member_certificates_issued, member_push_subscriptions, member_notifications, member_activity_logs, member_achievement_unlocks |
| Gateways/Pagamentos | gateway_credentials, gateway_fee_settings, refund_requests |
| Integrações | webhooks, webhook_logs, product_webhook, inbound_webhook_endpoints, utmify_integrations, spedy_integrations, cademi_integrations (+ pivots), conversion_pixel_integrations, pixel_x_integrations, integrax_connections |
| Parceiros/Afiliados | product_affiliate_programs, product_coproducers, product_affiliates, commission_entries, wallet_transactions, payout_requests, payout_request_allocations |
| Notificações | panel_push_subscriptions, panel_notifications |
| Equipe | team_roles, team_role_product, team_audit_logs |
| Diversos | plugins, settings, api_applications, api_checkout_sessions, proof_documents, storefront_domains, tracking_ad_spends, tracking_ad_spend_overrides, checkout_field_events |

**Total: ~70+ tabelas | 149 migrations**

---

## 5. ROTAS E CONTROLLERS

### 5.1 Rotas Públicas (sem auth)

| Rota | Controller | Finalidade |
|------|-----------|-----------|
| `/` | `RootController` | Redireciona para membro/dashboard/login |
| `/c/{slug}` | `CheckoutController@show` | Página de checkout |
| `/checkout/pix` | `CheckoutController@pix` | Checkout PIX |
| `/checkout/boleto` | `CheckoutController@boleto` | Checkout Boleto |
| `/checkout/pix-parcelado` | `CheckoutController@pixParcelado` | PIX Parcelado |
| `/checkout/upsell` | `UpsellController@show` | Pós-compra upsell |
| `/checkout/downsell` | `DownsellController@show` | Pós-compra downsell |
| `/checkout/obrigado` | `ThankYouController@show` | Página obrigado |
| `/api-checkout/{token}` | `ApiCheckoutController@show` | Checkout API |
| `/renovar/{token}` | `RenewalController@show` | Renovação de assinatura |
| `/afiliar/{slug}` | `AfiliarController@show` | Programa de afiliados |
| `/convite/co-producao/{token}` | `CoproducaoController@invite` | Convite co-produção |
| `/conquistas/{slug}/share` | `ConquistasController@share` | Compartilhar conquistas |
| `/verify/{code}` | `ProofController@verify` | Verificação de comprovante |
| `/webhooks/gateways/*` | `WebhookController` | Webhooks de gateways |
| `/cron` | Scheduler | Cron via HTTP |

### 5.2 Rotas de Autenticação

| Rota | Controller |
|------|-----------|
| `GET/POST /criar-admin` | `CreateFirstAdminController` |
| `GET/POST /login` | `LoginController` |
| `GET/POST /esqueci-senha` | `ForgotPasswordController` |
| `GET/POST /redefinir-senha/{token}` | `ResetPasswordController` |
| `GET/POST /logout` | `LogoutController` |

### 5.3 Rotas do Painel Admin (auth + role:admin|infoprodutor|team)

| Grupo de Rotas | Permissão | Finalidade |
|----------------|-----------|-----------|
| `/dashboard` | `dashboard.view` | Dashboard principal |
| `/vendas` | `vendas.view` | Vendas, exportações, comprovantes |
| `/produtos` | `produtos.view` | CRUD produtos, checkout builder, member builder |
| `/financeiro` | `financeiro.view` | Dashboard financeiro, payouts |
| `/reembolsos` | `reembolsos.view/manage` | Solicitações de reembolso |
| `/relatorios` | `relatorios.view` | Relatórios |
| `/assinaturas` | `assinaturas.view` | Assinaturas recorrentes |
| `/afiliados` | `afiliados.manage` | Hub de afiliados |
| `/integracoes` | `integracoes.view` | Webhooks, Utmify, Spedy, Cademi, etc. |
| `/email-marketing` | `email_marketing.view` | Campanhas de email |
| `/usuarios` | `role:admin` | Gestão de usuários |
| `/usuarios/equipe` | `team.permission:equipe.manage` | Gestão de equipe |
| `/configuracoes` | `configuracoes.view` | Configurações gerais |
| `/aplicacoes-api` | `api_pagamentos.view` | Aplicações API |
| `/gerenciar-plugins` | `plugins.view` | Gestão de plugins |
| `/meu-perfil` | auth | Perfil do usuário |
| `/conquistas` | `conquistas.view` | Gamificação |

### 5.4 Rotas da Área de Membros

| Padrão | Finalidade |
|--------|-----------|
| `/m/{slug}` | Home da área de membros |
| `/m/{slug}/modulo/{module}` | Conteúdo do módulo |
| `/m/{slug}/aula/{lesson}` | Visualização da aula |
| `/m/{slug}/loja` | Loja interna |
| `/m/{slug}/comunidade` | Comunidade |
| `/m/{slug}/certificado` | Certificado |
| `/m/{slug}/login` | Login da área de membros |

### 5.5 API REST (v1)

| Método | Endpoint | Finalidade |
|--------|---------|-----------|
| POST | `v1/checkout/sessions` | Criar sessão de checkout |
| POST | `v1/payments/pix` | Criar pagamento PIX |
| POST | `v1/payments/card` | Criar pagamento cartão |
| POST | `v1/payments/boleto` | Criar pagamento boleto |
| GET | `v1/payments/{order}` | Status do pagamento |

Autenticação: Bearer token ou X-API-Key header.

---

## 6. GATEWAYS DE PAGAMENTO (9 gateways)

### 6.1 Arquitetura

```
PaymentService (strategy pattern)
├── GatewayRegistry (registro central)
├── GatewayDriver (contrato: testConnection, createPixPayment, createCardPayment, createBoletoPayment, getTransactionStatus)
└── Fallback/Redundância: tenta gateways em ordem configurável com timeout de 25s
```

### 6.2 Gateways Disponíveis

| Gateway | Slug | Métodos | Escopo | Destaques |
|---------|------|---------|--------|-----------|
| **CajuPay** | `cajupay` | PIX, Cartão, Apple Pay, Google Pay, PIX Parcelado, PIX Auto | Internacional | SDK checkout sessions, splits, wallet balance/payouts |
| **Spacepag** | `spacepag` | PIX | Nacional | Validação HMAC SHA-256 |
| **Efí** | `efi` | PIX, Cartão, Boleto, PIX Auto | Nacional | Certificado P12, PIX Recorrente |
| **Stripe** | `stripe` | Cartão | Internacional | PaymentIntent, 3DS, zero-decimal |
| **Mercado Pago** | `mercadopago` | PIX, Cartão, Boleto | Internacional | SDK oficial, Bricks tokenização |
| **Pushin Pay** | `pushinpay` | PIX, PIX Auto | Nacional | PIX Recorrente |
| **Asaas** | `asaas` | PIX, Cartão, Boleto | Nacional | Customer CRUD, parcelas |
| **Pagar.me** | `pagarme` | PIX, Cartão, Boleto | Nacional | v5 Orders API, checkout transparente |
| **PayPal** | `paypal` | PayPal | Internacional | SDK client-side |

### 6.3 Fluxo de Processamento

```
1. Checkout → ProcessPayment job
2. PaymentService::createPixPayment/Card/Boleto
3. Tenta gateway primário → timeout 5s
4. Se falhar, tenta próximo na ordem de redundância
5. Deadline total: 25s
6. Cria Order (status: pending)
7. Webhook confirma pagamento → OrderCompleted event
8. Listeners disparam: email acesso, comissões, Meta CAPI, push
```

---

## 7. SISTEMA DE PLUGINS

### 7.1 Tipos de Plugin

| Tipo | Finalidade |
|------|-----------|
| `gateway` | Novo gateway de pagamento |
| `integration` | Integração externa (CRM, LMS, etc.) |
| `member` | Extensões da área de membros |
| `commerce` | Funcionalidades de comércio multi-produto |
| `generic` | Plugins genéricos |

### 7.2 Descoberta e Carregamento

1. **Bundled:** `plugins/` no diretório raiz
2. **User installed:** `GETFY_PLUGINS_USER_PATH` ou `.docker/plugins-installed/`
3. **Extra scan:** `GETFY_PLUGINS_EXTRA_SCAN`

### 7.3 plugin.json Manifest

Campos principais: `slug`, `name`, `version`, `type`, `requires`, `gateway`, `frontend`, `routes`, `public_routes`, `api_routes`, `events`, `migrations`, `commands`, `schedule`, `middleware`, `menu`, `settings_tab`, `integration_app`, `product_panel`, `checkout_builder_templates`, `checkout_extensions`, `dashboard_widgets`, `member_area_panels`, `order_fulfillment_providers`, `theme`, `capabilities`, `render_zones`, `commerce_scopes`.

### 7.4 Plugin SDK (Getfy Facade)

```php
Getfy::config()        // Ler/escrever config do plugin
Getfy::tenant()        // Info do tenant
Getfy::products()      // CRUD de produtos
Getfy::orders()        // Consulta de pedidos
Getfy::commerce()      // APIs de comércio/cart
Getfy::assets()        // Upload/resolução de assets
Getfy::hooks()         // Hooks estilo WordPress (actions/filters)
Getfy::gateways()      // Registro de gateways
Getfy::extensions()    // Slots de extensão UI
Getfy::productTypes()  // Tipos de produto customizados
Getfy::capabilities()  // Permissões
Getfy::events()        // Dispatch de eventos
Getfy::license()       // Verificação de licença
```

### 7.5 Sistema de Hooks (estilo WordPress)

- **Actions:** fire-and-forget (`doAction`)
- **Filters:** transformação de valor (`applyFilters`)
- Prioridade ordenada de callbacks
- Hooks ativos: `order.completed`, `order.completed.context`

---

## 8. WEBHOOKS

### 8.1 Eventos Disponíveis (13 eventos)

**Pagamento:** OrderPending, OrderCompleted, AccessDeliveryReady, OrderRejected, OrderCancelled, OrderRefunded, PixGenerated, BoletoGenerated

**Recuperação:** CartAbandoned

**Assinatura:** SubscriptionCreated, SubscriptionRenewed, SubscriptionCancelled, SubscriptionPastDue

### 8.2 Fluxo de Dispatch

```
Evento dispara → WebhookEventSubscriber
├── Resolve tenant IDs dos models
├── Carrega Webhooks ativos do tenant
├── Monta payload via WebhookPayloadBuilder
├── Dispatch DispatchWebhookJob (async ou sync)
│   ├── Async: fila Redis (padrão)
│   └── Sync: se fila indisponível ou evento crítico
└── Registra em webhook_logs (status, response, success)
```

### 8.3 Políticas de Reconfirmação

Por gateway: `accept` (padrão) ou `reject` (MercadoPago). Quando a reconfirmação via API do gateway falha para eventos destrutivos (cancel/reject/refund).

---

## 9. JOBS ASSÍNCRONOS (19 jobs)

| Job | Finalidade |
|-----|-----------|
| `ProcessPaymentWebhook` | Processar webhooks de pagamento recebidos |
| `DispatchWebhook` | Enviar webhooks de integração |
| `SendApiApplicationWebhookJob` | Notificar aplicações API |
| `DispatchPixelXJob` | Disparar eventos pixel X (Twitter) |
| `SendMetaPurchaseCapiJob` | Enviar Meta Conversions API |
| `SendPanelPushJob` | Push notifications no painel |
| `SendCampaignEmailJob` | Envio de campanhas de email |
| `SendCheckoutSessionRecoveryEmailJob` | Emails de recuperação de carrinho |
| `SendCheckoutSessionRecoverySmsJob` | SMS de recuperação de carrinho |
| `SendPendingOrderRecoveryEmailJob` | Emails de recuperação de pedido pendente |
| `SendPendingOrderRecoverySmsJob` | SMS de recuperação de pedido pendente |
| `SendSubscriptionRemindersJob` | Lembretes de renovação de assinatura |
| `CademiGrantAccessJob` | Conceder acesso no Cademi LMS |
| `IntegraXSendSmsJob` | Enviar SMS IntegraX |
| `SendIntegraXAccessSmsJob` | SMS de acesso IntegraX |
| `SendIntegraXPixSmsJob` | SMS PIX IntegraX |
| `SpedyIssueInvoiceJob` | Emissão de nota fiscal Spedy |
| `UtmifySendOrderJob` | Sincronização Utmify |
| `QueueHeartbeatJob` | Monitoramento de saúde da fila |

---

## 10. TAREFAS AGENDADAS (Scheduler)

| Comando | Frequência |
|---------|-----------|
| `subscriptions:process-lifecycle` | A cada hora |
| `subscriptions:send-reminders` | Diário às 09:00 |
| `checkout:fire-abandoned-cart-webhooks` | A cada 10 minutos |
| `checkout:send-cart-recovery-emails` | A cada minuto |
| `checkout:send-cart-recovery-sms` | A cada minuto |
| `email-campaign:process` | A cada minuto |
| `payments:reconcile-pending` | A cada minuto |
| `orders:cancel-stale-pending` | A cada hora |
| `commissions:release` | A cada hora |
| `payouts:reconcile` | A cada minuto |
| `coproducers:expire-invites` | Diário |
| `schedule:heartbeat` | A cada minuto |
| `QueueHeartbeatJob` | A cada minuto (via fila) |

---

## 11. MIDDLEWARE (27 middlewares)

### Global (prepending)
- `BlockSensitivePaths` — bloqueia acesso a .env, .git, vendor, etc.

### Web (prepend)
- `ForceHttpsWhenForwardedProto` — HTTPS via proxy
- `EnsureDockerSetup` — redireciona para setup Docker se necessário
- `EnsureInstalled` — verifica se o sistema está instalado

### Web (append)
- `PrepareCheckoutEmbed` — suporte a checkout embutido (iframe)
- `ApplyWhiteLabelBranding` — branding white-label
- `HandleInertiaRequests` — props do Inertia.js
- `PreventCacheForHtml` — cache headers para HTML
- `SecurityHeaders` — CSP, HSTS, X-Frame-Options, etc.
- `RunScheduleFallback` — fallback do scheduler

### Por Rota
- `EnsureRole` — verificação de角色 (admin, infoprodutor, team)
- `EnsureTeamPermission` — permissões granulares de equipe
- `AuditLogMiddleware` — log de auditoria
- `EnsureGuest` — apenas visitantes
- `AuthenticateApiApplication` — API key (Bearer/X-API-Key)
- `ResolveMemberAreaProduct` — resolver produto da área de membros
- `ResolveMemberAreaByHost` — resolver por hostname
- `EnsureMemberAreaAccess` — verificação de acesso
- `EnsureAdminHasTenant` — admin com tenant
- `PreventCheckoutAbuse` — honeypot + rate limit checkout
- `ReusePendingPixCheckout` — reutilizar checkout PIX pendente
- `EnsurePartnerProductAccess` — acesso de parceiro
- `EnsurePartnerPanel` — painel de parceiro
- `ResolveStorefrontTenant` — resolver tenant da vitrine
- `VerifyPluginApiSignature` — assinatura webhook plugin
- `EnforcePluginCommerceScope` — escopo de comércio plugin
- `SignedOrMemberAreaRedirect` — redirect assinado

---

## 12. SISTEMA DE PAGAMENTOS (Checkout)

### 12.1 Fluxo Completo

```
1. PRODUTO CONFIGURADO
   └── ProdutosController → ofertas, planos, bumps, cupons, upsell/downsell

2. PÁGINA DE CHECKOUT
   └── CheckoutController@show → resolve por slug (oferta > plano > produto)
       ├── Métodos de pagamento disponíveis
       ├── Geo-IP para detecção de país
       ├── Multi-moeda
       └── Configurações de checkout (imagem, fundo, traduções)

3. PROCESSAMENTO
   └── CheckoutController@process
       ├── Middleware: reuse-pix, throttle (process/pix/card/email/product-ip), abuse
       ├── PIX: PaymentService::createPixPayment (com redundância)
       ├── Cartão: PaymentService::createCardPayment (com tokenização)
       ├── Boleto: PaymentService::createBoletoPayment
       ├── CajuPay SDK: cajupaySession → cajupayConfirmOrder
       ├── PayPal: paypalCreateOrder → paypalCapture
       └── PIX Parcelado: cajupayParceladoSession → confirm → complete

4. PÓS-PAGAMENTO
   ├── Order criada (status: pending)
   ├── Webhook confirma → OrderCompleted event
   ├── Listeners:
   │   ├── SendAccessEmailOnOrderCompleted (email de acesso)
   │   ├── AllocateCommissionsOnOrderCompleted (comissões afiliados)
   │   ├── SendMetaPurchaseCapiOnOrderCompleted (Meta CAPI)
   │   ├── SendPanelPushOnOrderCompleted (push notification)
   │   └── InvalidateDashboardCacheOnOrderCompleted
   └── RevokeAccessOnOrderRefunded (em caso de reembolso)

5. UPSELL/DOWNSELL
   └── UpsellController → pós-compra, aceitar/recusar, pagamento adicional

6. API CHECKOUT
   └── ApiCheckoutController → checkout hospedado para integrações

7. COMÉRCIO (Multi-produto)
   └── Commerce/ → catálogo, carrinho, checkout multi-produto
       ├── TTL carrinho: 14 dias
       ├── Máx 50 linhas
       └── TTL checkout: 2 horas
```

### 12.2 Anti-Abuse Checkout

- Honeypot field
- Rate limits configuráveis (process/pix/card/email/product-ip)
- Limite de pedidos pendentes por IP/email
- Detecção de flood
- Cloudflare Turnstile (captcha adaptativo)

---

## 13. ÁREA DE MEMBROS

### 13.1 Estrutura Hierárquica

```
Product
├── MemberSection (seções)
│   └── MemberModule (módulos)
│       └── MemberLesson (aulas)
│           ├── content_url (vídeo)
│           ├── content_text (texto)
│           ├── content_files (JSON - PDFs, etc.)
│           ├── support_files (JSON)
│           ├── useful_links (JSON)
│           └── likes, annotations
├── MemberTurma (turmas)
├── MemberCommunityPage → MemberCommunityPost
├── MemberComment
├── MemberCertificateIssued
└── MemberAchievementUnlock
```

### 13.2 Tipos de Conteúdo

| Tipo | Descrição |
|------|-----------|
| `video` | Vídeo (Vidstack player) |
| `text` | Texto/HTML |
| `pdf` | PDF com reader integrado |
| `file` | Arquivo para download |
| `link` | Link externo |

### 13.3 Controle de Acesso (Drip Content)

- **Liberação por tempo:** `release_after_days`, `release_at_date`
- **Liberação por progresso:** `release_progress_percent`, `release_required_module_ids`
- **Duração de acesso:** `access_duration_days`
- **Acesso por assinatura:** `SubscriptionAccessService`
- **Acesso por compra:** `UserProductAccessService`

### 13.4 Funcionalidades

- Comunidade (posts, comentários, likes, imagens, vídeos)
- Certificados de conclusão
- Gamificação/Conquistas (achievements)
- Push notifications
- Notificações in-app
- Logs de atividade
- Leitor PDF com anotações e likes
- Loja interna (produtos relacionados)
- Modo cinema
- PWA com push

### 13.5 Estratégias de URL

| Estratégia | Exemplo |
|-----------|---------|
| Path | `meudominio.com/m/{slug}` |
| Subdomínio | `{slug}.members.meudominio.com` |
| Domínio customizado | `areademembros.com` |

---

## 14. SISTEMA DE PARCEIROS E AFILIADOS

### 14.1 Tipos de Parceiro

| Tipo | Descrição |
|------|-----------|
| **Afiliado** | Promove o produto e recebe comissão sobre vendas |
| **Co-produtor** | Co-cria o produto e recebe comissão sobre vendas |

### 14.2 Fluxo de Comissões

```
OrderCompleted event
├── CommissionAllocator resolve beneficiários
│   ├── Co-produtores (product_coproducers)
│   └── Afiliados (product_affiliates)
├── CommissionSplitService calcula splits
├── CommissionEntry criada (status: pending)
├── Após período de hold → liberação
└── PayoutRequest → saque via CajuPay
```

### 14.3 Tabelas

- `product_affiliate_programs` — configuração por produto
- `product_affiliates` — afiliados vinculados
- `product_coproducers` — co-produtores vinculados
- `commission_entries` — entradas de comissão
- `wallet_transactions` — ledger da carteira
- `payout_requests` — solicitações de saque
- `payout_request_allocations` — alocações do saque

---

## 15. INTEGRAÇÕES

### 15.1 Integrações Disponíveis

| Integração | Finalidade |
|-----------|-----------|
| **Utmify** | Sincronização de pedidos e tracking |
| **Spedy** | Emissão de notas fiscais |
| **Cademi** | LMS - concessão de acesso |
| **IntegraX** | SMS (PIX, acesso) |
| **Meta Conversions API** | Facebook/Instagram pixel server-side |
| **Pixel X (Twitter)** | Eventos de conversão |
| **Conversion Pixels** | Meta, Google, TikTok custom audiences |
| **Webhooks** | Integrações gerais via HTTP |

### 15.2 Meta Conversions API

```
OrderCompleted event
├── MetaConversionsApiService
├── Envia evento Purchase para Meta CAPI
├── Dados: email, telefone, valor, moeda, conteúdo
└── Via gateway HTTP direto (server-side)
```

### 15.3 Cademi (LMS)

```
OrderCompleted event
├── CademiEventSubscriber
├── CademiGrantAccessJob (async)
├── Concede acesso no Cademi via API
├── Mapeamento: produto → tag/produto Cademi
└── Delivery: postback customizado
```

---

## 16. PWA (Progressive Web App)

### 16.1 Funcionalidades

- `manifest.json` dinâmico (nome, tema, ícones, display: standalone)
- Service Worker (`painel-sw.js`)
- Push Notifications (VAPID keys)
- Preferências de notificação (PIX/Boleto/Cartão)
- Instalação em tela cheia
- Notificações in-app (panel_notifications)

### 16.2 Rotas

| Rota | Finalidade |
|------|-----------|
| `GET /manifest.json` | Manifest PWA |
| `GET /painel-sw.js` | Service Worker |
| `POST /painel/push-subscribe` | Inscrever push |
| `PATCH /painel/push-preferences` | Preferências |
| `GET /painel/notifications` | Centro de notificações |
| `POST /painel/notifications/mark-all-read` | Marcar todas como lidas |

---

## 17. SEGURANÇA

### 17.1 Content Security Policy (CSP)

- **script-src:** self, unsafe-inline, unsafe-eval + todos os SDKs de pagamento + analytics
- **connect-src:** APIs de pagamento + analytics + WebSocket/blob + Meta CAPI Gateway
- **frame-src:** iframes de pagamento + YouTube + Cloudflare + Meta Pixel
- Extensível via `.env`: `CSP_EXTRA_SCRIPT_SRC`, `CSP_EXTRA_CONNECT_SRC`

### 17.2 Rate Limiting

| Limiter | Taxa | Chave |
|---------|------|-------|
| `api` | 60/min | user ID ou IP |
| `checkout-process` | 20/min (config) | IP |
| `checkout-card` | 5/min | IP |
| `checkout-pix` | 20/5min | IP |
| `checkout-email` | 30/hora | hash email |
| `checkout-product-ip` | 40/hora | IP + produto |
| `platform-login` | 20/min | IP + email |
| `password-reset` | 6/min | IP + email |
| `payout` | 3/min | user ID |

### 17.3 Outros

- HSTS com preload em produção
- X-Content-Type-Options: nosniff
- X-Frame-Options: SAMEORIGIN
- Referrer-Policy: strict-origin-when-cross-origin
- Bloqueio de caminhos sensíveis (.env, .git, vendor, etc.)
- Validação de assinatura HMAC em webhooks de plugin
- Whitelist de IPs para API applications

---

## 18. SISTEMA DE EMAIL

### 18.1 Configuração

- Suporta: SMTP, SES, Postmark, Resend, Sendmail, log, array, failover, round-robin
- Configuração por tenant via `TenantMailConfigService`

### 18.2 Mailables

| Mailable | Finalidade |
|----------|-----------|
| `AccessGrantedMail` | Entrega de acesso pós-compra |
| `AffiliateApprovedMail` | Aprovação de afiliado |
| `CampaignMail` | Campanhas de email |
| `CartRecoveryMail` | Recuperação de carrinho |
| `CoproducerInviteMail` | Convite co-produtor |
| `SubscriptionReminderMail` | Lembrete renovação |
| `TeamMemberAccessMail` | Convite equipe |
| `TestEmail` | Teste de email |

### 18.3 Campanhas de Email

- Criação, filtração de destinatários, agendamento
- Envio assíncrono via `SendCampaignEmailJob`
- Controle de pausa, erro, status por envio

---

## 19. ARMAZENAMENTO

### 19.1 Providers

| Provider | Config |
|----------|--------|
| Local | `storage/app/public` |
| S3 (AWS) | Padrão S3 |
| Cloudflare R2 | Via S3-compatible API |
| Wasabi | Detectado via endpoint |
| DigitalOcean Spaces | Detectado via endpoint |

### 19.2 Configuração

- Variáveis: `R2_ACCESS_KEY_ID`, `R2_SECRET_ACCESS_KEY`, `R2_BUCKET`, `R2_ENDPOINT`, `R2_PUBLIC_URL`
- Per-tenant no banco: `storage_provider`, `storage_s3_key`, `storage_s3_secret`, `storage_s3_bucket`, etc.

---

## 20. FRONTEND (Vue 3 + Inertia.js)

### 20.1 Layouts

| Layout | Uso |
|--------|-----|
| `AppLayout` | Painel admin principal (sidebar + header) |
| `LayoutInfoprodutor` | Wrapper do AppLayout |
| `LayoutGuest` | Páginas de auth/guest |
| `LayoutDoc` | Documentação API |
| `AfiliarLayout` | Páginas de afiliados |
| `MemberAreaAppLayout` | Área de membros completa |
| `MemberBuilderFullLayout` | Builder de área de membros |
| `StudentAreaLayout` | Hub "Meus Produtos" |

### 20.2 Páginas Principais

**Checkout:** Builder, Show, Pix, Boleto, PixParcelado, Upsell, Downsell, ThankYou

**Painel Admin:** Dashboard, Vendas, Produtos (CRUD + Member Builder + Checkout Builder), Financeiro, Reembolsos, Relatórios, Assinaturas, Afiliados, Integrações, Email Marketing, Usuários, Equipe, Configurações, Plugins, API, Conquistas

**Área de Membros:** Home, Módulos, Aulas, Loja, Comunidade, Certificado, Login

**Parceiro:** Dashboard, Produtos, Vendas, Financeiro

### 20.3 Componentes Reutilizáveis

- **Checkout:** CheckoutForm, CheckoutSidebar, CheckoutPaymentMethods, CheckoutOrderBumps, CheckoutTimer, ExitPopup, SalesNotification, ConversionPixels
- **Dashboard:** TrackingPanel, TrackingFunnel, TrackingRevenueChart, TrackingKpiRow, TrackingWorldMap, TrackingAdSpendCard
- **Member Area:** MemberLessonContent, MemberLessonComments, MemberCommunityPost, MemberAreaHero
- **UI:** Button, Checkbox, Toggle, GatewaySelect, HorizontalScrollTabs, BetaBadge

### 20.4 Composables

| Composable | Finalidade |
|-----------|-----------|
| `useCheckoutLocale` | Multi-idioma checkout (PT_BR, EN, ES) |
| `useCajuPaySdk` | Loader do SDK CajuPay |
| `usePagarmeTokenizecard` | Tokenização Pagar.me |
| `usePanelPushSubscribe` | Push notification |
| `usePwaInstall` | Instalação PWA |
| `useSidebar` | Sidebar expand/collapse |
| `useTrackingPanel` | Dados de tracking |
| `useInertiaPagination` | Paginação Inertia |
| `useMemberAreaCinemaMode` | Modo cinema |
| `usePluginCheckoutRegistry` | Registro de plugins checkout |

---

## 21. CONFIGURAÇÕES IMPORTANTES

### 21.1 Variáveis de Ambiente (.env)

| Grupo | Variáveis |
|-------|----------|
| Core | APP_NAME, APP_ENV, APP_KEY, APP_DEBUG, APP_URL, APP_INSTALLED |
| Banco | DB_CONNECTION, DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME, DB_PASSWORD |
| Sessão | SESSION_DRIVER, SESSION_LIFETIME (7 dias), SESSION_DOMAIN |
| Cache/Fila | CACHE_STORE=redis, QUEUE_CONNECTION=redis |
| Redis | REDIS_CLIENT=predis, REDIS_HOST, REDIS_PORT |
| Gateways | CAJUPAY_API_BASE_URL, PAGARME_API_BASE_URL |
| Plugin Store | PLUGIN_STORE_URL, PLUGIN_STORE_API_KEY |
| Webhooks | GETFY_WEBHOOKS_SYNC_CRITICAL_PAYMENT |
| PWA | PWA_VAPID_PUBLIC, PWA_VAPID_PRIVATE |
| Anti-abuse | CHECKOUT_ABUSE_GUARD_ENABLED, CHECKOUT_RATE_*, CHECKOUT_TURNSTILE_* |

### 21.2 Config PHP (26 arquivos)

| Arquivo | Finalidade |
|---------|-----------|
| `getfy.php` | Config core: installed, cloud mode, version, cron secret, webhooks, VAPID, plugins |
| `gateways.php` | 9 gateways: slug, name, methods, driver class, credential keys, redundância |
| `plugins.php` | Caminhos, Docker mode, API version, licença, commerce cart TTL |
| `webhooks.php` | Políticas de reconfirmação por gateway |
| `checkout_security.php` | Rate limits e prevenção de abuso |
| `csp.php` | Content Security Policy domains |
| `members.php` | Estratégias de URL, VAPID keys |
| `commissions.php` | Configuração de comissões/afiliados |
| `conquistas.php` | Gamificação |

---

## 22. RESUMO ESTATÍSTICO

| Métrica | Quantidade |
|---------|-----------|
| Models Eloquent | 68 |
| Controllers | 70+ |
| Services | 62 |
| Middleware | 27 |
| Jobs | 19 |
| Console Commands | 25 |
| Events/Listeners | 31 events, 16 listeners |
| Migrations | 149 |
| Tabelas DB | ~70+ |
| Gateways de Pagamento | 9 |
| Vue Pages | 60+ |
| Vue Components | 100+ |
| Layouts | 8 |
| Composables | 14 |
| Plugins exemplos | 6 |
| Config PHP | 26 |

---

*Relatório gerado automaticamente em 24/08/2026*
