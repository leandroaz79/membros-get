# Contexto de Desenvolvimento - Getfy

**Ultima atualização:** 25/08/2026 (v2 - MIME type fix)

---

## 1. AMBIENTE DE DESENVOLVIMENTO

### 1.1 Stack do projeto

| Camada | Tecnologia |
|--------|-----------|
| Backend | Laravel 12 / PHP 8.2+ |
| Frontend | Vue 3 (Composition API) / Inertia.js / Pinia |
| CSS | Tailwind CSS v4 |
| Build | Vite 7 |
| Database | MySQL 8.0 |
| Cache/Fila | Redis 7 |
| Servidor | Nginx + PHP-FPM (Alpine Linux) |
| Container | Docker / Docker Compose |

### 1.2 Maquina do desenvolvedor

- **SO:** Windows (Docker Desktop com WSL2 - Ubuntu)
- **Git:** 2.52.0
- **Docker:** 28.5.1
- **Caminho do projeto:** `C:\Getfy`
- **Linguagem de comunicacao:** Portugues do Brasil

### 1.3 Containers Docker

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

### 1.4 Volumes Docker

| Volume | Uso |
|--------|-----|
| `mysql_data` | Dados do banco MySQL |
| `getfy_storage` | Pasta storage do Laravel (upload de arquivos) |
| `getfy_env` | Pasta .docker (plugins instalados, uploads de tenant) |

---

## 2. CONFIGURACOES DO DOCKER

### 2.1 Arquivo de override (docker-compose.override.yml)

Criado em `C:\Getfy\docker-compose.override.yml` para permitir edicao ao vivo do codigo:

```yaml
services:
  app:
    volumes:
      - .:/var/www/html
      - getfy_storage:/var/www/html/storage
      - getfy_env:/var/www/html/.docker
  queue:
    volumes:
      - .:/var/www/html
      - getfy_storage:/var/www/html/storage
      - getfy_env:/var/www/html/.docker
```

### 2.2 Como iniciar o projeto

O script `docker\up.ps1` usa `-f docker-compose.yml` explicitamente, entao o override NAO e carregado automaticamente. Para usar o override:

```powershell
$env:GETFY_COMPOSE_FILES = "docker-compose.yml;docker-compose.override.yml"
.\docker\up.ps1
```

Ou manualmente:
```powershell
$env:GETFY_COMPOSE_FILES = "docker-compose.yml;docker-compose.override.yml"
docker compose up -d
```

### 2.3 Variavel de ambiente necessaria no .env

O arquivo `.env` ja esta configurado com:
- `APP_URL=http://localhost`
- `APP_ENV=local`

---

## 3. BUGS CORRIGIDOS

### 3.1 Bug: DockerSetupState rejeitava localhost

**Arquivo:** `C:\Getfy\app\Support\DockerSetupState.php`
**Linha:** 80-91
**Problema:** O metodo `isSetupDone()` verificava se o dominio era publico, mas `localhost` nao e um dominio publico. Isso causava redirecionamento infinito para `/docker-setup`.

**Correcao:** Adicionada verificacao para aceitar `localhost`, `127.0.0.1` e `::1` quando `APP_ENV=local`:

```php
if (in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
    return app()->environment('local');
}
```

### 3.2 Bug: Plugin White Label retornava 404

**Arquivo:** `C:\Getfy\app\Http\Controllers\PluginsController.php`
**Linha:** 37
**Problema:** O link "Configurar" na pagina de plugins gerava `/white-label`, mas o plugin so tem rotas de API (`/white-label/settings/*`). Nao existe rota GET para renderizar uma pagina em `/white-label`.

**Correcao:** Plugins com `settings_tab` agora apontam para `/configuracoes?tab=<id>`:

```php
'settings_url' => ($p['settings_tab']['id'] ?? null)
    ? '/configuracoes?tab='.$p['settings_tab']['id']
    : ($p['routes'] ? '/'.$p['slug'] : null),
```

### 3.3 Bug: Plugins runtime retornavam "Erro ao carregar UI do plugin" (403)

**Arquivo:** `C:\Getfy\app\Http\Middleware\BlockSensitivePaths.php`
**Linha:** 18-30 (BLOCKED_PREFIXES)
**Problema:** O middleware bloqueava qualquer request com path começando por `plugins/`. A rota de assets dos plugins (`/plugins/{slug}/assets/{path}`) foi bloqueada, impedindo o carregamento do bundle JS dos plugins com UI runtime (ex: Example Commerce).

**Correcao:** Removido `'plugins/'` da lista `BLOCKED_PREFIXES`. O `PluginAssetController` ja protege contra traversal de diretorios via `realpath()`.

### 3.4 Bug: Plugins runtime retornavam "Erro ao carregar UI do plugin" (MIME type)

**Arquivo:** `C:\Getfy\Dockerfile` e `C:\Getfy\docker\nginx\getfy.conf`
**Problema:** O Nginx servia arquivos `.mjs` com MIME type `application/octet-stream`. O browser recusava executar o modulo JavaScript (strict MIME checking). Isso impedia o carregamento do Vue para plugins runtime (Example Commerce, Plugin Starter, etc).

**Correcao:**
1. No Dockerfile, adicionar `sed` para incluir `.mjs` no `/etc/nginx/mime.types`
2. No `getfy.conf`, remover `include /etc/nginx/mime.types` duplicado (ja existe no `nginx.conf` principal)

### 3.5 Bug: Laravel\Pail\PailServiceProvider nao encontrado

**Problema:** Apos `composer install --no-dev`, o cache de servicos precisava ser limpo.

**Correcao (executar no container):**
```bash
docker exec getfy-app-1 sh -c "cd /var/www/html && php artisan config:clear && php artisan cache:clear"
```

---

## 4. PLUGIN WHITE LABEL

### 4.1 Localizacao dos arquivos

O plugin instalado pela loja fica no volume Docker `getfy_env`:
- **Dentro do container:** `/var/www/html/.docker/plugins-installed/white-label/`
- **No Windows (via Docker):** Acessivel apenas via `docker exec`

Os arquivos Vue do plugin estao no repositorio:
- `C:\Getfy\resources\js\PluginPages\WhiteLabel\SettingsTab.vue`

### 4.2 Arquitetura do plugin

| Arquivo | Funcao |
|---------|--------|
| `plugin.json` | Manifesto (slug, nome, settings_tab, routes) |
| `routes.php` | Rotas de API (prefixo `/white-label/`) |
| `src/WhiteLabelController.php` | Controller com respostas JSON |
| `database/migrations/` | Migracoes do plugin |

### 4.3 Rotas do plugin

Todas sao rotas de API (sem renderizacao de pagina):

| Metodo | Rota | Funcao |
|--------|------|--------|
| GET | `/white-label/settings/data` | Buscar configuracoes |
| PUT | `/white-label/settings` | Atualizar configuracoes |
| POST | `/white-label/settings/upload` | Upload de imagens |
| POST | `/white-label/settings/clear-field` | Limpar campo |
| POST | `/white-label/settings/sync-global` | Sincronizar globalmente |

### 4.4 Como o settings_tab funciona

1. O `plugin.json` define `settings_tab` com `id`, `label` e `component`
2. O backend coleta tabs de plugins via `PluginRegistry::getSettingsTabs()`
3. A pagina `/configuracoes` recebe `settings_plugin_tabs` via Inertia props
4. O componente Vue `SettingsTab.vue` e renderizado como aba dentro de `/configuracoes`
5. A URL correta para acessar e `/configuracoes?tab=white-label`

### 4.5 Componentes Vue do plugin

- **Localizacao:** `C:\Getfy\resources\js\PluginPages\WhiteLabel\SettingsTab.vue`
- Usa `window.axios` para chamadas API
- Usa `whiteLabelApi()` helper para construir URLs
- After save: `router.reload({ preserveScroll: true })`

---

## 5. SISTEMA DE PLUGINS DO GETFY

### 5.1 Tipos de plugin

| Tipo | Descricao |
|------|-----------|
| `integration` | Integracoes (webhook, UTMfy, Spedy, etc) |
| `gateway` | Gateways de pagamento |
| `commerce` | Extensoes de commerce |
| `marketing` | Ferramentas de marketing |

### 5.2 Slots de UI

Plugins podem registrar slots de UI que sao renderizados em paginas especificas:

| Slot | Onde aparece |
|------|-------------|
| `settings_tab` | Aba na pagina /configuracoes |
| `integration_app` | Card na pagina /integracoes |
| `product_panel` | Painel na pagina de produto |
| `checkout_extensions` | Extensoes no checkout |
| `dashboard_widgets` | Widgets no dashboard |
| `member_area_panels` | Painels na area de membros |
| `financeiro_tabs` | Abas na pagina financeiro |

### 5.3 Resolucao de componentes Vue

O sistema resolve componentes de plugins de duas formas:

1. **Legacy (Plugin/...):** Componentes em `resources/js/PluginPages/`
2. **Runtime:** Componentes via manifest de build (`dist/ui.manifest.json`)

O composable `usePluginComponentResolver` gerencia essa resolucao no frontend.

### 5.4 Estrutura de um plugin

```
meu-plugin/
├── plugin.json          # Manifesto obrigatorio
├── routes.php           # Rotas Laravel (opcional)
├── src/                 # PHP classes
│   └── Controllers/
├── database/
│   └── migrations/
├── resources/
│   └── js/              # Componentes Vue (legacy)
└── dist/                # Build de frontend (runtime)
    └── ui.manifest.json
```

### 5.5 Exemplo de plugin.json

```json
{
    "slug": "white-label",
    "name": "White Label",
    "version": "1.0.0",
    "type": "integration",
    "category": "branding",
    "menu": [],
    "routes": "routes.php",
    "settings_tab": {
        "id": "white-label",
        "label": "White Label",
        "component": "Plugin/WhiteLabel/SettingsTab"
    }
}
```

---

## 6. ROTAS PRINCIPAIS DO SISTEMA

### 6.1 Rotas web (Inertia pages)

| Rota | Pagina | Descricao |
|------|--------|-----------|
| `/` | Redirect | Redireciona para login/dashboard |
| `/dashboard` | Dashboard | Painel principal |
| `/produtos` | Produtos | Lista de produtos |
| `/vendas` | Vendas | Lista de vendas |
| `/financeiro` | Financeiro | Financeiro com abas |
| `/configuracoes` | Settings | Configuracoes com abas |
| `/integracoes` | Integracoes | Apps e gateways |
| `/gerenciar-plugins` | Plugins | Gerenciamento de plugins |
| `/usuarios` | Usuarios | Gestao de usuarios |
| `/docker-setup` | DockerSetup | Setup inicial Docker |

### 6.2 Como as abas de configuracao funcionam

A pagina `/configuracoes` usa query param `?tab=`:

```javascript
// URL: /configuracoes?tab=white-label
const t = new URLSearchParams(window.location.search).get('tab');
if (t && allAllowedTabIds().includes(t)) activeTab.value = t;
```

Abas core: `email`, `storage`, `traducoes`, `moedas`, `push`, `cron`, `update`
Abas de plugins: coletadas dinamicamente via `settings_plugin_tabs`

---

## 7. COMANDOS UTIS

### 7.1 Docker

```powershell
# Ver status dos containers
docker compose ps

# Ver logs do app
docker compose logs -f app

# Entrar no container
docker exec -it getfy-app-1 sh

# Limpar caches (dentro do container)
php artisan config:clear && php artisan cache:clear && php artisan route:clear

# Rodar migrations
php artisan migrate

# Ver rotas registradas
php artisan route:list
```

### 7.2 Composer (dentro do container)

```bash
# Instalar dependencias (producao)
composer install --no-dev

# Instalar dependencias (desenvolvimento)
composer install
```

### 7.3 NPM (fora do container, no Windows)

```powershell
# Instalar dependencias JS
npm install

# Build de producao
npm run build

# Build de desenvolvimento (watch)
npm run dev
```

---

## 8.PENDENCIAS E PROXIMOS PASSOS

### 8.1 Pendencias abertas

- [ ] Verificar se o fix do settings_url do White Label esta funcionando corretamente apos cache clear
- [ ] Testar todas as funcionalidades do plugin White Label (upload, clear, sync)
- [ ] Verificar se outros plugins com `settings_tab` precisam da mesma correcao
- [ ] Implementar provedor de email Brevo (EM ANDAMENTO)

### 8.2 Provedor de Email Brevo (Implementado e Testado)

**Status:** Implementado e funcional
**Data conclusao:** 01/09/2026
**Arquivos modificados:**
- `app/Services/TenantMailConfigService.php` - Caso Brevo adicionado
- `app/Http/Controllers/SettingsController.php` - Validacao e props
- `app/Http/Controllers/EmailTestController.php` - Validacao e overrides
- `resources/js/Pages/Settings/Index.vue` - Card Brevo no UI
- `resources/js/components/EmailProviderSidebar.vue` - UI de configuracao
- `public/images/integrations/brevo.svg` - Logo placeholder

**Configuracao SMTP:**
- Host: smtp-relay.brevo.com
- Porta: 587
- Encryption: TLS

**Chaves no banco (tabela settings):**
- `email_provider` = 'brevo'
- `brevo_smtp_username` = email login (nao encriptado)
- `brevo_smtp_key` = chave SMTP (encriptada)
- `brevo_mail_from_address` = email remetente
- `brevo_mail_from_name` = nome remetente

---

## 9. ARQUIVOS MODIFICADOS NESTA SESSAO

| Arquivo | Tipo de alteracao |
|---------|------------------|
| `C:\Getfy\docker-compose.override.yml` | **CRIADO** - Bind mount para edicao ao vivo |
| `C:\Getfy\app\Support\DockerSetupState.php` | **EDITADO** - Aceitar localhost em ambiente local |
| `C:\Getfy\app\Http\Controllers\PluginsController.php` | **EDITADO** - Fix settings_url para plugins com settings_tab |
| `C:\Getfy\app\Http\Middleware\BlockSensitivePaths.php` | **EDITADO** - Removido 'plugins/' de BLOCKED_PREFIXES |
| `C:\Getfy\Dockerfile` | **EDITADO** - Adicionado sed para MIME type .mjs |
| `C:\Getfy\docker\nginx\getfy.conf` | **EDITADO** - Removido include duplicado de mime.types |
| `C:\Getfy\4Dev\getfy-brevo-email\` | **CRIADO** - Documentacao de implementacao do Brevo |
| `C:\Getfy\RELATORIO-COMPLETO-SISTEMA.md` | **CRIADO** - Relatorio completo do sistema |
| `C:\Getfy\CONTEXTO-DESENVOLVIMENTO.md` | **CRIADO** - Este arquivo de contexto |

---

## 10. REFERENCIA RAPIDA

- **Repo:** `C:\Getfy`
- **Container app:** `getfy-app-1`
- **Container mysql:** `getfy-mysql-1`
- **Container redis:** `getfy-redis-1`
- **Container queue:** `getfy-queue-1`
- **URL local:** `http://localhost`
- **Plugins instalados:** white-label (em `.docker/plugins-installed/`)
