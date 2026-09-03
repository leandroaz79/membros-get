# Getfy Plugin SDK - documentação completa para LLMs
> Gerado em 2026-08-13T18:27:10.909Z. Não edite à mão: rode `npm run sync:plugin-docs` no frontend.
> Portal: https://getfy.org/developers

---

<!-- module: 00-instructions-for-llm -->

# Instruções para o modelo de IA

1. **Não invente APIs.** Use apenas `App\\PluginSdk\\Getfy` e contratos documentados neste bundle.
2. Prefira hooks/events/SDK a alterar o core. Core patches só quando inevitável (módulo core-patches).
3. Namespace PHP: `Plugins\\{PascalSlug}\\` → pasta `src/`.
4. Empacote o plugin com pasta raiz = slug; core patch espelha o root do Getfy sem pasta wrapper.
5. Exemplos oficiais: `getfy-plugin-starter` e `getfy-example-gateway`.
6. Plugin API: `requires.plugin_api: 2`.

---

<!-- module: getting-started -->

# Getting started

## Pré-requisitos

- Getfy instalado (PHP 8.2+, Composer, Node para UI)
- Pasta `plugins/` gravável

## Criar um plugin

```bash
# Em branco (settings tab + hooks demo)
php artisan plugin:make meu-plugin

# A partir dos exemplos oficiais
php artisan plugin:make meu-pix --type=gateway
php artisan plugin:make minha-loja --type=commerce
php artisan plugin:make minha-integracao --type=integration
php artisan plugin:make meu-membro --type=member
```

Isso copia o starter/exemplo para `plugins/{slug}` e renomeia namespace/slug.

## Ciclo de desenvolvimento

1. Edite `plugin.json`, `bootstrap.php` e `src/`
2. Se tiver frontend: `cd plugins/{slug}/frontend && npm install && npm run build`  
   (os exemplos já trazem `dist/` pronto para validar)
3. `php artisan plugin:validate {slug}`
4. No painel: **Gerenciar plugins** → registrar/habilitar (ou Integrações → Plugins)

Namespace PHP esperado: `Plugins\{PascalSlug}\` → `src/` (autoload dinâmico).

## API pública

Use **somente** `App\PluginSdk\*` (fachada `Getfy`). Evite depender de classes internas de `App\Http`, `App\Services`, etc., exceto contratos reexportados no SDK.

```php
use App\PluginSdk\Getfy;

Getfy::hooks()->addAction('order.completed', function ($order) { /* ... */ });
Getfy::gateways()->register([/* ... */]);
```

## Próximos passos

- Gateway: [guides/payment-gateway.md](/developers/docs/guides/payment-gateway)
- Empacotar para a loja: [packaging-and-store.md](/developers/docs/packaging-and-store)


---

<!-- module: plugin-json -->

# plugin.json

Schema JSON: [`resources/schemas/plugin.schema.json`](../../resources/schemas/plugin.schema.json)

## Campos obrigatórios

| Campo | Exemplo |
|-------|---------|
| `slug` | `meu-plugin` (kebab-case) |
| `name` | `Meu Plugin` |
| `version` | `1.0.0` (SemVer) |

## Recomendados

```json
{
  "type": "gateway",
  "requires": { "getfy": ">=2.0.0", "plugin_api": 2 },
  "description": "...",
  "frontend": {
    "entry": "dist/plugin-ui.js",
    "manifest": "dist/ui.manifest.json",
    "exports": { "settings": "SettingsTab" },
    "checkout_gateway_slug": "meu-gateway"
  }
}
```

## Tipos (`type`)

`generic` | `gateway` | `payment_gateway` | `commerce` | `integration` | `member` | `theme`

## Extensões comuns

- `settings_tab`, `integration_app`, `product_panel`
- `member_area_panels`, `checkout_extensions`, `render_zones`
- `menu`, `routes`, `public_routes`, `api_routes`
- `migrations`, `events`, `commands`, `capabilities`
- `commerce_scopes`, `theme`, `gateway.methods`

Validação: `php artisan plugin:validate {slug}` (schema + requires + frontend + integrity).


---

<!-- module: sdk-reference -->

# SDK reference - `App\PluginSdk\Getfy`

Fachada estável. Preferir estes métodos a classes internas.

```php
use App\PluginSdk\Getfy;
```

| Método | Uso |
|--------|-----|
| `Getfy::config()` | Lê/grava JSON do plugin na tabela `plugins` |
| `Getfy::tenant()` | Resolve `tenant_id` |
| `Getfy::products()` | Produtos do tenant |
| `Getfy::orders()` | Pedido + `PluginOrderContext` |
| `Getfy::commerce()` | Catálogo / `startCheckout` |
| `Getfy::assets()` | URLs e enqueue CSS/JS |
| `Getfy::hooks()` | Actions/filters (`addAction`, `addFilter`, …) |
| `Getfy::gateways()` | `register` / `get` / `driver` |
| `Getfy::extensions()` | UI slots / checkout extensions |
| `Getfy::productTypes()` | Tipos virtuais de produto |
| `Getfy::capabilities()` | RBAC `plugin:{slug}:{cap}` |
| `Getfy::events()` | `listen` dinâmico (preferir `events` no JSON) |
| `Getfy::license()` | Entitlement / purchase_token |
| `Getfy::pluginApiVersion()` | int (ex.: 2) |
| `Getfy::version()` | versão do core |

## Gateway contract

```php
use App\PluginSdk\Contracts\GatewayDriver;
```

Estende o driver interno; implemente no plugin e registre via `Getfy::gateways()->register([...])`.

## Licença

```php
Getfy::license()->check('meu-plugin');
Getfy::license()->assertValid('meu-plugin');
Getfy::license()->storePurchaseToken('meu-plugin', $token);
```


---

<!-- module: hooks-and-events -->

# Hooks & events

## Hooks (PluginHookBus)

Registre com `Getfy::hooks()` ou helpers `getfy_add_action` / `getfy_add_filter`.

### Actions

| Hook | Args | Desde |
|------|------|-------|
| `checkout.before_process` | `$event`, `$product`, `$validated`, `$pluginCheckoutData` | 2.x |
| `product.before_save` | `$event`, `$product`, `$validated`, `$isCreate` | 2.x |
| `order.completed` | `$order` | plugin_api 2 |
| `order.completed.context` | `PluginOrderContext` | plugin_api 2 |
| `member_area.show` | `$product`, `$user`, `$request` | plugin_api 2 |
| `assets.{context}.head` | - | 2.x |

`context` ∈ `panel`, `checkout`, `member_area`, …

### Filters

| Hook | Value + args | Desde |
|------|--------------|-------|
| `checkout.payload` | `$payload`, `$product`, `$request` | 2.x |
| `product.form.sections` | seções do form de produto | 2.x |
| `panel.menu` | itens de menu, `$request` | 2.x |
| `inertia.shared` | props shared, `$request` | 2.x |
| `member_area.payload` | props da home da área, `$product`, `$user`, `$request` | plugin_api 2 |
| `plugin.render_zones` | zones | 2.x |
| `theme.tokens` / `theme.styles` | tokens/styles | 2.x |

## Eventos Laravel (manifest `events`)

Declare no `plugin.json`:

```json
"events": {
  "App\\Events\\OrderCompleted": ["Plugins\\MeuPlugin\\Listeners\\OnPaid"]
}
```

Eventos úteis: `OrderCompleted`, `OrderRefunded`, `OrderPending`, `PixGenerated`, `BoletoGenerated`, `SubscriptionCreated`, `SubscriptionRenewed`, `CheckoutBeforeProcess`, `CommerceCheckoutBuilding`, `MemberAreaLoaded`, `ProductCreated`, `ProductUpdated`.

## Extensões UI (manifest)

settings_tab · integration_app · product_panel · member_area_panels · checkout_extensions · render_zones · dashboard widgets · frontend.pages


---

<!-- module: plugin-events -->

# Eventos para plugins

Plugins podem escutar eventos Laravel declarando-os em `plugin.json` (chave `events`) e registrando listeners no `bootstrap.php`. Esta página lista os eventos emitidos pelo core e como usá-los.

## Registro no plugin

Em `plugin.json`:

```json
{
  "slug": "meu-plugin",
  "name": "Meu Plugin",
  "version": "1.0.0",
  "events": [
    "App\\Events\\OrderCompleted",
    "App\\Events\\DashboardLoading"
  ]
}
```

No `bootstrap.php`:

```php
<?php

use App\Events\OrderCompleted;
use Illuminate\Contracts\Events\Dispatcher;

return function ($app, Dispatcher $events): void {
    $events->listen(OrderCompleted::class, function (OrderCompleted $e): void {
        // $e->order
    });
};
```

---

## Eventos disponíveis

### OrderCompleted

**Classe:** `App\Events\OrderCompleted`

Emitido quando uma compra é concluída (checkout processado com sucesso).

| Propriedade | Tipo        | Descrição      |
|------------|-------------|----------------|
| `$order`   | `Order`     | Pedido criado. |

**Uso típico:** enviar e-mail, integrar CRM, liberar acesso em sistema externo.

---

### DashboardLoading

**Classe:** `App\Events\DashboardLoading`

Emitido antes de renderizar a página do dashboard do infoprodutor. Plugins podem adicionar dados ao payload que será enviado para o frontend.

| Propriedade | Tipo          | Descrição                                                                 |
|------------|---------------|----------------------------------------------------------------------------|
| `$data`    | `ArrayObject` | Dados do dashboard. Listeners podem adicionar chaves (ex.: `$data['plugin_widgets'] = [...]`). |

**Uso típico:** injetar widgets, métricas ou links extras no dashboard.

**Exemplo:**

```php
$events->listen(\App\Events\DashboardLoading::class, function (\App\Events\DashboardLoading $e): void {
    $e->data['plugin_widgets'] = [
        ['title' => 'Meu widget', 'content' => '...'],
    ];
});
```

---

### CheckoutBeforeProcess

**Classe:** `App\Events\CheckoutBeforeProcess`

Emitido antes de criar o pedido e o vínculo do aluno ao produto. Um listener pode abortar o checkout definindo `$event->abort`.

| Propriedade  | Tipo     | Descrição                                                                 |
|-------------|----------|----------------------------------------------------------------------------|
| `$product`  | `Product`| Produto da compra.                                                        |
| `$validated`| `array`  | Dados validados do request (`product_id`, `email`, `name`).               |
| `$abort`    | `?string`| Se definido pelo listener, o checkout é interrompido e a mensagem é exibida. |

**Uso típico:** validações extras, limite de compras, integração com antifraude.

**Exemplo:**

```php
$events->listen(\App\Events\CheckoutBeforeProcess::class, function (\App\Events\CheckoutBeforeProcess $e): void {
    if (/* alguma condição */) {
        $e->abort = 'Não foi possível processar a compra. Tente mais tarde.';
    }
});
```

---

### CheckoutPageLoading

**Classe:** `App\Events\CheckoutPageLoading`

Emitido antes de renderizar a página pública do checkout (rota `/c/{slug}`). Plugins podem alterar os dados enviados ao frontend (produto, config).

| Propriedade | Tipo          | Descrição                                                                 |
|------------|----------------|----------------------------------------------------------------------------|
| `$product` | `Product`      | Produto do checkout.                                                      |
| `$data`    | `ArrayObject`  | Payload da página. Contém `product` (array) e `config` (array). Listeners podem modificar `$data['product']` ou `$data['config']` para alterar o que será exibido. |

**Uso típico:** injetar dados extras no checkout, alterar configuração por tenant, integrar tracking ou A/B test.

**Exemplo:**

```php
$events->listen(\App\Events\CheckoutPageLoading::class, function (\App\Events\CheckoutPageLoading $e): void {
    $e->data['config']['appearance']['primary_color'] = '#1a1a1a'; // override cor
    $e->data['custom_field'] = 'valor'; // adicionar chave para o frontend
});
```

---

### MemberAreaLoaded

**Classe:** `App\Events\MemberAreaLoaded`

Emitido quando a área de membros é carregada (lista de produtos do aluno).

| Propriedade | Tipo         | Descrição                    |
|------------|--------------|------------------------------|
| `$user`    | `User`       | Usuário (aluno) logado.      |
| `$produtos`| `Collection` | Produtos que o aluno possui. |

**Uso típico:** analytics, personalização, integração com LMS externo.

---

### ProductCreated

**Classe:** `App\Events\ProductCreated`

Emitido após a criação de um produto (store com sucesso).

| Propriedade | Tipo      | Descrição       |
|------------|-----------|-----------------|
| `$product`| `Product` | Produto criado. |

**Uso típico:** enviar e-mail, sincronizar CRM, criar registro em sistema externo.

---

### ProductUpdated

**Classe:** `App\Events\ProductUpdated`

Emitido após a atualização de um produto (update com sucesso).

| Propriedade | Tipo      | Descrição         |
|------------|-----------|-------------------|
| `$product`| `Product` | Produto atualizado. |

**Uso típico:** sincronizar catálogo externo, invalidar cache.

---

### ProductDeleted

**Classe:** `App\Events\ProductDeleted`

Emitido antes da exclusão do produto (destroy). O model ainda está em memória.

| Propriedade | Tipo      | Descrição        |
|------------|-----------|------------------|
| `$product`| `Product` | Produto excluído. |

**Uso típico:** limpar dados em sistema externo, remover arquivos.

---

### ProductDuplicated

**Classe:** `App\Events\ProductDuplicated`

Emitido após duplicar um produto (duplicate com sucesso).

| Propriedade    | Tipo      | Descrição              |
|----------------|-----------|------------------------|
| `$original`   | `Product` | Produto original.      |
| `$newProduct` | `Product` | Novo produto (cópia).   |

**Uso típico:** replicar configurações em sistema externo.

---

### ProductIndexLoading

**Classe:** `App\Events\ProductIndexLoading`

Emitido antes de renderizar a página de listagem de produtos. Plugins podem injetar dados no payload enviado ao frontend.

| Propriedade | Tipo          | Descrição                                                                 |
|------------|---------------|----------------------------------------------------------------------------|
| `$data`    | `ArrayObject` | Dados da página. Contém `produtos`, `productTypes`, `exchange_rates`. Listeners podem adicionar, por exemplo: `$data['plugin_card_actions'][$productId] = [{ 'label' => '...', 'href' => '...', 'icon' => '...' }]` ou `$data['plugin_form_sections'] = [...]`. |

**Uso típico:** adicionar ações extras no menu do card (por produto) ou seções no formulário de criação.

**Exemplo:**

```php
$events->listen(\App\Events\ProductIndexLoading::class, function (\App\Events\ProductIndexLoading $e): void {
    foreach ($e->data['produtos'] ?? [] as $p) {
        $e->data['plugin_card_actions'][$p['id']][] = [
            'label' => 'Enviar para CRM',
            'href' => '/meu-plugin/sync/' . $p['id'],
        ];
    }
});
```

---

### ProductBeforeSave

**Classe:** `App\Events\ProductBeforeSave`

Emitido antes de persistir criação ou atualização de produto. Um listener pode abortar definindo `$event->abort`.

| Propriedade  | Tipo      | Descrição                                                                 |
|-------------|-----------|----------------------------------------------------------------------------|
| `$product`  | `Product` | Model do produto (em create pode estar vazio no DB).                       |
| `$validated`| `array`   | Dados validados do request.                                                |
| `$isCreate` | `bool`    | True se for criação, false se for atualização.                             |
| `$abort`    | `?string` | Se definido pelo listener, o save é interrompido e a mensagem é exibida.   |

**Uso típico:** validações extras, integração com catálogo externo.

**Exemplo:**

```php
$events->listen(\App\Events\ProductBeforeSave::class, function (\App\Events\ProductBeforeSave $e): void {
    if (/* alguma condição */) {
        $e->abort = 'Não foi possível salvar o produto.';
    }
});
```

---

## Boas práticas

- Não bloqueie a resposta por muito tempo; para tarefas pesadas use filas (queues).
- Em modo cloud, considere `app('current_tenant_id')` ao salvar dados por tenant.
- Não exponha dados sensíveis nos payloads que forem enviados ao frontend (ex.: `DashboardLoading`).


---

<!-- module: plugin-migrations -->

# Migrations em plugins

Plugins podem adicionar e rodar suas próprias migrations, criando tabelas ou alterando o banco sem modificar o core.

## Configuração

No `plugin.json`, declare o caminho da pasta de migrations (relativo à raiz do plugin):

```json
{
  "slug": "meu-plugin",
  "name": "Meu Plugin",
  "version": "1.0.0",
  "migrations": "database/migrations"
}
```

Estrutura de pastas:

```
plugins/
  meu-plugin/
    plugin.json
    bootstrap.php
    database/
      migrations/
        2025_02_20_100000_plugin_meu_plugin_create_minha_tabela.php
```

As migrations são carregadas apenas para **plugins ativados**. Ao rodar `php artisan migrate`, o Laravel executa as migrations do app e as de todos os plugins ativados.

## Nomenclatura

- **Nome do arquivo:** use o prefixo `plugin_{slug}_` para evitar conflito com outras migrations e com o core (ex.: `plugin_example_2025_02_20_200000_create_demo_table.php`).
- **Tabelas:** prefira prefixo nas tabelas (ex.: `plugin_example_demo`) para não colidir com tabelas do app ou de outros plugins.

## Exemplo

```php
// plugins/meu-plugin/database/migrations/plugin_meu_plugin_2025_02_20_100000_create_log_table.php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plugin_meu_plugin_log', function (Blueprint $table) {
            $table->id();
            $table->string('action');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plugin_meu_plugin_log');
    }
};
```

## Comandos

- **Rodar todas as migrations (app + plugins ativados):** `php artisan migrate`
- **Reverter:** `php artisan migrate:rollback`
- **Status:** `php artisan migrate:status`

As migrations de plugins entram na mesma fila do Laravel; não há comando separado para “migrar só plugins”.

## Modo cloud (multi-tenant)

Se o plugin criar tabelas com dados por tenant, use a coluna `tenant_id` (nullable quando a tabela for global) e o scope do app para filtrar por `app('current_tenant_id')`.


---

<!-- module: packaging-and-store -->

# Packaging & store (getfy.org)

## Empacotar o plugin

1. `php artisan plugin:validate {slug}` (obrigatório)
2. ZIP com **uma pasta raiz** = slug:

```
meu-plugin/
  plugin.json
  bootstrap.php
  src/
  dist/
  ...
```

## Core patch (opcional)

Se o plugin alterou arquivos do Getfy Open Source (fora de `plugins/{slug}/`), envie um **segundo ZIP** na publicação:

- Formato e como gerar: [Core patches](/developers/docs/core-patches)
- Campo na loja: **ZIP de patches do core** (opcional)
- O ZIP espelha o root do core com **só** os arquivos alterados/novos

## Integridade (opcional)

- `.getfy-checksum` - SHA-256 do diretório (ver `PluginPackageIntegrity`)
- `.getfy-signature` - assinatura OpenSSL (requer `GETFY_PLUGIN_PACKAGE_PUBLIC_KEY` no core)

## Publicar

1. Conta em [getfy.org](https://getfy.org) → [Publicar plugin](https://getfy.org/plugins/submit)
2. Preencha metadados, capa, screenshots, guias
3. Anexe o ZIP do plugin (obrigatório)
4. Anexe o ZIP de core patch se houver alterações no core
5. Aguarde moderação (Ananin)

Env vars no core (instalação local / sync com a loja):

- `PLUGIN_STORE_URL=https://getfy.org`
- `PLUGIN_STORE_SUBMIT_URL` (API da loja)
- Instalação no painel: Gerenciar plugins → Loja / ZIP
- Update: `POST /gerenciar-plugins/update/{slug}` (backup em `storage/app/plugin-backups`)

## Licença

Na instalação com `purchase_token`, o core grava em `plugins.config.license` e `Getfy::license()->check()` consulta a loja.

## Comprador

Após a compra/download:

1. Instalar o ZIP do plugin
2. Se existir core patch, extrair sobre o root da instalação (ver [Core patches](/developers/docs/core-patches))
3. Limpar cache / validar o plugin


---

<!-- module: core-patches -->

# Core patches (alterações no core)

Plugins devem preferir a API pública (`App\PluginSdk\Getfy`). Só altere o core quando o SDK **não** cobrir o caso.

Quando o plugin depender de arquivos do Getfy Open Source (fora de `plugins/{slug}/`), envie um **segundo ZIP** na loja getfy.org: o **core patch**.

## Quando usar

- Hook/evento necessário ainda não existe no SDK
- Correção pontual em um arquivo do core exigida pelo plugin
- Integração que precisa de um ponto de extensão ainda não exposto

## Quando **não** usar

- Lógica que cabe em `bootstrap.php` / `src/` / hooks / gateways
- Mudanças cosméticas no painel (use UI slots / extensions)
- Dependências de `vendor/`, `node_modules/` ou `.env`

## Formato do ZIP

Árvore **espelhando o root** do Getfy Open Source, **somente** arquivos alterados ou novos. Sem pasta raiz extra.

```text
core-patch.zip
  app/Services/Foo.php
  resources/js/Pages/Bar.tsx
  routes/web.php
```

Paths relativos ao root do core. Exemplo inválido:

```text
# ERRADO - pasta raiz extra
meu-patch/
  app/Services/Foo.php
```

### Proibido no ZIP

- `.env`, `.env.*`
- `vendor/`, `node_modules/`
- `storage/`, `bootstrap/cache/`
- Binários grandes sem necessidade, dumps de DB, secrets

## Como gerar o ZIP

### 1) Liste só o que mudou (git)

No root do Getfy Open Source:

```bash
git status --short
git diff --name-only
git ls-files --others --exclude-standard
```

Confirme que cada path é necessário para o plugin.

### 2) Empacote (Linux / macOS / Git Bash)

```bash
# Liste paths touchados (modificados + novos)
git diff --name-only HEAD > /tmp/core-files.txt
git ls-files --others --exclude-standard >> /tmp/core-files.txt

# ZIP na raiz do core, sem pasta wrapper
zip -@ core-patch.zip < /tmp/core-files.txt
```

Ou com `git archive` se tudo já estiver commitado numa branch de patch:

```bash
git archive -o core-patch.zip HEAD -- $(git diff --name-only main...HEAD)
```

### 3) Empacote (PowerShell / Windows)

No root do core:

```powershell
$files = @(
  git diff --name-only HEAD
  git ls-files --others --exclude-standard
) | Where-Object { $_ -and (Test-Path $_) }

Compress-Archive -Path $files -DestinationPath core-patch.zip -Force
```

> Se `Compress-Archive` criar pastas intermediárias indesejadas, use 7-Zip (`7z a core-patch.zip @list.txt`) com paths relativos.

### 4) Verifique

Abra o ZIP e confirme:

1. O primeiro nível contém pastas como `app/`, `resources/`, `routes/` - **não** uma pasta `core-patch/` ou `getfy/`.
2. Nenhum arquivo sensível (`.env`, keys).
3. O conjunto é o mínimo necessário.

## Enviar na loja

Em [Publicar plugin](/plugins/submit):

1. ZIP do plugin (pasta raiz = slug) - obrigatório
2. ZIP de core patch - **opcional**, só se houver alterações no core

Documente no changelog / descrição do plugin **quais arquivos do core** foram tocados e por quê. A moderação (Ananin) revisa o patch antes de publicar.

## Instalação pelo comprador

1. Instalar o ZIP do plugin pelo painel (Loja / ZIP)
2. Se houver core patch: extrair o ZIP **sobre o root** da instalação Getfy (mesclar arquivos)
3. Rodar `php artisan optimize:clear` (e migrations se o patch/plugin exigir)
4. Validar: `php artisan plugin:validate {slug}`

A loja **distribui** o patch; a aplicação automática no instalador fica a cargo da instalação local (backup antes de sobrescrever).

## Checklist de review

- [ ] Preferiu SDK/hooks antes de tocar no core?
- [ ] Paths relativos corretos (sem wrapper)?
- [ ] Sem secrets / vendor / storage?
- [ ] Compatível com `requires.getfy` declarado no `plugin.json`?
- [ ] Comprador consegue aplicar o patch com instruções claras?


---

<!-- module: security -->

# Security

- Plugins executam PHP no host - só publique/instale código confiável (review na loja getfy.org).
- Não armazene segredos de outros tenants; sempre filtre por `Getfy::tenant()`.
- Webhooks: valide assinatura/secret do provedor no handler.
- Assets públicos via `/plugins/{slug}/assets/...` - não exponha credenciais.
- Respeite capabilities RBAC (`Getfy::capabilities()`).
- Evite `eval`, shell remoto e dependências ofuscadas no ZIP.
- Declare `requires.getfy` / `requires.plugin_api` corretos para não bootar em cores incompatíveis.


---

<!-- module: guides-payment-gateway -->

# Guia: Payment gateway

Referência oficial: `plugins/getfy-example-gateway`

```bash
php artisan plugin:make meu-provedor --type=gateway
```

## Passos

1. Em `bootstrap.php`, registre com o SDK:

```php
Getfy::gateways()->register([
    'slug' => 'meu-provedor',
    'name' => 'Meu Provedor',
    'image' => 'plugin:meu-provedor/logo.svg',
    'methods' => ['pix', 'card'],
    'driver' => \Plugins\MeuProvedor\MeuDriver::class,
    'webhook_handler' => \Plugins\MeuProvedor\WebhookHandler::class,
    'credential_keys' => [/* ... */],
    'checkout_payload_keys' => ['publishable_key'],
]);
```

2. Implemente `App\PluginSdk\Contracts\GatewayDriver`.
3. Webhook em `POST /webhooks/gateways/meu-provedor` → despache `ProcessPaymentWebhook`.
4. Frontend (opcional):
   - `frontend.checkout_gateway_slug` = slug do gateway
   - `frontend.exports.checkout` = `{ "pix": "PixMethod", "card": "CardMethod" }`
   - `frontend.exports.tokenizeCard` = função async `(ctx) => ({ payment_token, card_mask })`
5. `php artisan plugin:validate meu-provedor`

O checkout carrega tiles/tokenizers de plugins automaticamente (`usePluginCheckoutRegistry`).


---

<!-- module: guides-checkout-ui -->

# Guia: Checkout UI

## Tiles de método

Em `plugin.json`:

```json
"frontend": {
  "checkout_gateway_slug": "meu-gateway",
  "exports": {
    "checkout": { "pix": "PixMethod", "card": "CardMethod" }
  }
}
```

No bundle, registre em `window.__GETFY_PLUGIN_UI__[slug]`.

## Extensões de formulário

`checkout_extensions` no manifest + `Getfy::extensions()->registerCheckout($slug, [...])` para handlers de processo.

## Dados no submit

O checkout envia `plugin_checkout_data` (JSON). Leia em `checkout.before_process`.

## Tokenização de cartão

Exporte `tokenizeCard` ou chame `window.__GETFY_REGISTER_CARD_TOKENIZER__(slug, fn)`.


---

<!-- module: guides-commerce -->

# Guia: Commerce

Exemplo: `plugins/getfy-example-commerce`

```bash
php artisan plugin:make minha-loja --type=commerce
```

```php
Getfy::commerce()->catalog($tenantId);
Getfy::commerce()->startCheckout(
    tenantId: $tenantId,
    pluginSlug: 'minha-loja',
    customer: ['name' => '...', 'email' => '...', 'document' => '...'],
    amount: 99.90,
    metadata: ['source' => 'storefront'],
    lineItems: [['name' => 'SKU', 'qty' => 1, 'amount' => 99.90]],
);
```

Declare `commerce_scopes` no manifesto. Registre handlers em `CommerceCheckoutContextRegistry` se precisar enriquecer vendas.


---

<!-- module: guides-member-area -->

# Guia: Member area

Exemplo: `plugins/getfy-example-member`

```bash
php artisan plugin:make meu-painel --type=member
```

## Painéis

`member_area_panels` + export em `frontend.member_area` / `frontend.exports.member_area`.

Props compartilhadas: `plugin_member_panels`, `plugin_ui`.

## Hooks

- `member_area.show` (action)
- `member_area.payload` (filter) - altere/adicione props da home

```php
Getfy::hooks()->addFilter('member_area.payload', function (array $payload, $product, $user, $request) {
    $payload['meu_bloco'] = ['ok' => true];
    return $payload;
});
```


---

<!-- module: guides-integrations -->

# Guia: Integrations

Exemplo: `plugins/getfy-example-integration`

```bash
php artisan plugin:make minha-crm --type=integration
```

Use `integration_app` no manifesto + export `integrations` no frontend.

Persistência: `Getfy::config()->get/set($slug)`.

Reaja a `order.completed` / eventos Laravel para webhooks outbound.


---

<!-- module: guides-admin-pages -->

# Guia: Admin pages

## Menu + rotas painel

```json
"menu": { "label": "Meu Plugin", "route": "meu-plugin.index", "icon": "puzzle" },
"routes": "routes.php"
```

Rotas do painel ficam sob `/{slug}/...` com middleware auth + role admin/infoprodutor.

## Página Inertia (runtime)

```json
"frontend": {
  "pages": { "Dashboard": "DashboardPage" },
  "exports": { "settings": "SettingsTab" }
}
```

Acesse via `PluginInertiaController` / rotas do plugin. Bundle em `dist/plugin-ui.js` com Vue externalizado (import map Getfy).

## Settings / product panel

`settings_tab` e `product_panel` no manifesto + exports correspondentes.


---

<!-- module: example-getfy-plugin-starter -->

# Exemplo: getfy-plugin-starter

# Getfy Plugin Starter

Template em branco. Crie o seu com:

```bash
php artisan plugin:make meu-plugin
# ou
php artisan plugin:make meu-pix --type=gateway
```

Documentação: `docs/developers/getting-started.md`


---

<!-- module: example-getfy-example-gateway -->

# Exemplo: getfy-example-gateway

# Getfy Example Gateway

Plugin de referência para criar **qualquer gateway de pagamento** sem alterar o core Getfy.

## Como usar

```bash
php artisan plugin:make meu-provedor --type=gateway
```

Depois:

1. Ajuste `bootstrap.php` (slug, nome, credential_keys, driver).
2. Implemente a API real em `src/*GatewayDriver.php`.
3. Ajuste o webhook em `*WebhookHandler.php` (`POST /webhooks/gateways/{slug}`).
4. (Opcional) Customize tiles/tokenização em `dist/plugin-ui.js` ou `frontend/`.
5. `php artisan plugin:validate meu-provedor`

## Checklist

- [ ] `Getfy::gateways()->register([...])` no bootstrap
- [ ] Driver implementa `App\PluginSdk\Contracts\GatewayDriver`
- [ ] Métodos declarados ⊆ implementados
- [ ] Webhook na rota genérica
- [ ] Credenciais + `checkout_payload_keys` se o front precisar de chave pública
- [ ] `frontend.checkout_gateway_slug` alinhado ao slug do gateway

Docs: `docs/developers/guides/payment-gateway.md`

