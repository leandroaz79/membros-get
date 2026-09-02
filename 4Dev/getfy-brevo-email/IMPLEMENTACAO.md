# Implementacao: Provedor de Email Brevo para Getfy

> **Data:** 25/08/2026
> **Status:** Planejamento
> **Versao do Getfy:** 2.0.4

---

## 1. Visao Geral

Adicionar **Brevo** (antigo Sendinblue) como 4a opcao de provedor de email no Getfy, ao lado de SMTP, Hostinger e SendGrid.

### Como o Getfy salva configuracoes

O Getfy usa uma tabela `settings` com estrutura key-value:

```
Tabela: settings
├── id (bigint, auto-increment)
├── tenant_id (bigint, nullable) - ID do tenant (infoprodutor)
├── key (string) - Nome da configuracao
├── value (text) - Valor (pode ser encriptado)
├── created_at (timestamp)
└── updated_at (timestamp)
```

**Nao precisa de migration!** As configuracoes sao salvas como chaves na tabela `settings`.

Exemplo de como ficam salvos:
| tenant_id | key | value |
|-----------|-----|-------|
| 1 | email_provider | brevo |
| 1 | brevo_smtp_username | usuario@brevo.com |
| 1 | brevo_smtp_key | encrypted:eyJpdiI6IkxY... |
| 1 | brevo_mail_from_address | naoresponda@dominio.com |
| 1 | brevo_mail_from_name | Minha Empresa |

### Configuracao Brevo SMTP (oficial)

| Parametro | Valor |
|-----------|-------|
| Host SMTP | `smtp-relay.brevo.com` |
| Porta | `587` (recomendado) ou `465` |
| Username | Email de login SMTP do Brevo |
| Password | Chave SMTP (nao API key) |
| Encryption | `tls` (porta 587) ou `ssl` (porta 465) |

> **Importante:** O Brevo usa CHAVE SMTP para autenticacao SMTP, nao a API Key.
> A API Key e usada para chamadas REST API, nao para SMTP relay.

---

## 2. Campos Necessarios no UI

De acordo com a documentacao oficial e类似 sistemas (FluentSMTP), o Brevo exige:

| Campo | Descricao | Obrigatorio |
|-------|-----------|-------------|
| E-mail do remetente | Email que aparece como "De:" | Sim |
| Nome do remetente | Nome que aparece como "De:" | Sim |
| Chave SMTP | Chave gerada no painel Brevo | Sim |

### Chaves no banco de dados (tabela settings)

| Chave | Tipo | Encriptado |
|-------|------|------------|
| `email_provider` | string | Nao |
| `brevo_smtp_username` | string | Nao |
| `brevo_smtp_key` | string | Sim (encrypt/decrypt) |
| `brevo_mail_from_address` | string | Nao |
| `brevo_mail_from_name` | string | Nao |

---

## 3. Arquivos a Serem Modificados

### 3.1 Backend (PHP)

#### `app/Services/TenantMailConfigService.php`

Adicionar caso para Brevo no metodo `getMailConfigForProvider()`:

```php
if ($provider === 'brevo') {
    $password = $overrides['brevo_smtp_key'] ?? null;
    if ($password === null) {
        $encrypted = Setting::get('brevo_smtp_key', null, $tenantId);
        $password = $encrypted ? @decrypt($encrypted) : null;
    }
    $username = $overrides['brevo_smtp_username'] ?? Setting::get('brevo_smtp_username', '', $tenantId);
    return [
        'host' => 'smtp-relay.brevo.com',
        'port' => 587,
        'encryption' => 'tls',
        'username' => $username,
        'password' => $password,
    ];
}
```

Adicionar caso no metodo `applyMailerConfigForTenant()`:

```php
} elseif ($provider === 'brevo') {
    $fromAddress = $overrides['brevo_mail_from_address'] ?? Setting::get('brevo_mail_from_address', config('mail.from.address'), $tenantId);
    $fromName = $overrides['brevo_mail_from_name'] ?? Setting::get('brevo_mail_from_name', config('mail.from.name'), $tenantId);
    $replyTo = Setting::get('brevo_reply_to', null, $tenantId);
}
```

Adicionar verificacao no metodo `isEmailConfigured()`:

```php
$brevoEncrypted = Setting::get('brevo_smtp_key', null, $tenantId);
if ($brevoEncrypted) {
    $key = @decrypt($brevoEncrypted);
    if ($key !== null && $key !== '') {
        return true;
    }
}
```

#### `app/Http/Controllers/SettingsController.php`

Adicionar `'brevo'` na validacao:

```php
'email_provider' => ['nullable', 'string', 'in:smtp,hostinger,sendgrid,brevo'],
```

Adicionar campos de Brevo na validacao:

```php
'brevo_smtp_username' => ['nullable', 'string', 'max:255'],
'brevo_smtp_key' => ['nullable', 'string', 'max:512'],
'brevo_mail_from_address' => ['nullable', 'email', 'max:255'],
'brevo_mail_from_name' => ['nullable', 'string', 'max:255'],
```

Adicionar campos no metodo `index()` (props):

```php
'brevo_smtp_username' => Setting::get('brevo_smtp_username', '', $tenantId),
'brevo_mail_from_address' => Setting::get('brevo_mail_from_address', config('mail.from.address', ''), $tenantId),
'brevo_mail_from_name' => Setting::get('brevo_mail_from_name', config('mail.from.name', ''), $tenantId),
```

Adicionar encriptacao da chave no metodo `update()`:

```php
if (array_key_exists('brevo_smtp_key', $validated) && $validated['brevo_smtp_key'] !== null && $validated['brevo_smtp_key'] !== '') {
    Setting::set('brevo_smtp_key', encrypt($validated['brevo_smtp_key']), $tenantId);
}
```

Adicionar `'brevo_smtp_key'` na lista de chaves que precisam de encriptacao:

```php
if (in_array($key, ['smtp_password', 'hostinger_smtp_password', 'sendgrid_api_key', 'brevo_smtp_key', 'storage_s3_secret'], true)) {
    Setting::set($key, encrypt($validated[$key]), $tenantId);
}
```

#### `app/Http/Controllers/EmailTestController.php`

Adicionar `'brevo'` na validacao:

```php
'email_provider' => ['nullable', 'string', 'in:smtp,hostinger,sendgrid,brevo'],
```

Adicionar campos de Brevo na validacao:

```php
'brevo_smtp_username' => ['nullable', 'string'],
'brevo_smtp_key' => ['nullable', 'string'],
'brevo_mail_from_address' => ['nullable', 'email', 'max:255'],
'brevo_mail_from_name' => ['nullable', 'string', 'max:255'],
```

Adicionar caso no metodo `buildMailOverridesFromRequest()`:

```php
} elseif ($provider === 'brevo') {
    if (! empty($validated['brevo_smtp_username'])) {
        $overrides['brevo_smtp_username'] = $validated['brevo_smtp_username'];
    }
    if (! empty($validated['brevo_smtp_key'])) {
        $overrides['brevo_smtp_key'] = $validated['brevo_smtp_key'];
    }
    if (! empty($validated['brevo_mail_from_address'])) {
        $overrides['brevo_mail_from_address'] = $validated['brevo_mail_from_address'];
    }
    if (isset($validated['brevo_mail_from_name'])) {
        $overrides['brevo_mail_from_name'] = $validated['brevo_mail_from_name'];
    }
}
```

### 3.2 Frontend (Vue)

#### `resources/js/Pages/Settings/Index.vue`

Adicionar Brevo no array `providers`:

```javascript
{
    id: 'brevo',
    title: 'Brevo',
    logo: '/images/integrations/brevo.png',
    description: 'Envio via API Brevo (antigo Sendinblue)',
},
```

Adicionar campos no `useForm()`:

```javascript
brevo_smtp_username: props.settings.brevo_smtp_username ?? '',
brevo_smtp_key: '', // never pre-fill
brevo_mail_from_address: props.settings.brevo_mail_from_address ?? '',
brevo_mail_from_name: props.settings.brevo_mail_from_name ?? '',
```

Adicionar caso no `isProviderConfigured()`:

```javascript
if (providerId === 'brevo') {
    return !!(form.brevo_smtp_username && form.brevo_mail_from_address);
}
```

Adicionar caso no `sendTestEmail()` e `testConnection()`:

```javascript
} else if (provider === 'brevo') {
    payload.brevo_smtp_username = form.brevo_smtp_username;
    payload.brevo_smtp_key = form.brevo_smtp_key;
    payload.brevo_mail_from_address = form.brevo_mail_from_address;
    payload.brevo_mail_from_name = form.brevo_mail_from_name;
}
```

Adicionar template para o sidebar de configuracao do Brevo (similar ao SendGrid):

```vue
<template v-if="selectedProvider?.id === 'brevo'">
    <div class="space-y-4">
        <div>
            <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">
                E-mail do remetente
            </label>
            <input v-model="form.brevo_mail_from_address" type="email" class="mt-1 block w-full rounded-xl border-2 border-zinc-200 bg-white px-4 py-2.5 dark:border-zinc-600 dark:bg-zinc-800 dark:text-white" placeholder="nao-responda@seudominio.com" />
        </div>
        <div>
            <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">
                Nome do remetente
            </label>
            <input v-model="form.brevo_mail_from_name" type="text" class="mt-1 block w-full rounded-xl border-2 border-zinc-200 bg-white px-4 py-2.5 dark:border-zinc-600 dark:bg-zinc-800 dark:text-white" placeholder="Nome da Empresa" />
        </div>
        <div>
            <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">
                E-mail de login SMTP
            </label>
            <input v-model="form.brevo_smtp_username" type="email" class="mt-1 block w-full rounded-xl border-2 border-zinc-200 bg-white px-4 py-2.5 dark:border-zinc-600 dark:bg-zinc-800 dark:text-white" placeholder="seu@email.com" />
            <p class="mt-1 text-xs text-zinc-500">Email usado para login no Brevo</p>
        </div>
        <div>
            <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">
                Chave SMTP
            </label>
            <input v-model="form.brevo_smtp_key" type="password" class="mt-1 block w-full rounded-xl border-2 border-zinc-200 bg-white px-4 py-2.5 dark:border-zinc-600 dark:bg-zinc-800 dark:text-white" placeholder="xsmtpsib-..." />
            <p class="mt-1 text-xs text-zinc-500">
                Gere em Brevo > Configuracoes > SMTP & API > Chaves SMTP
            </p>
        </div>
    </div>
</template>
```

### 3.3 Assets

Adicionar imagem do logo Brevo em:
- `public/images/integrations/brevo.png`

---

## 4. Fluxo de Dados

```
┌─────────────────────────────────────────────────────────────┐
│  UI (Settings/Index.vue)                                    │
│  - Usuario seleciona "Brevo"                                │
│  - Preenche: email remetente, nome, chave SMTP              │
│  - Clica "Salvar"                                           │
└──────────────────────┬──────────────────────────────────────┘
                       │ PUT /configuracoes
                       ▼
┌─────────────────────────────────────────────────────────────┐
│  SettingsController::update()                               │
│  - Valida: email_provider = 'brevo'                         │
│  - Encripta: brevo_smtp_key                                 │
│  - Salva no banco: settings table (per-tenant)              │
└──────────────────────┬──────────────────────────────────────┘
                       │
                       ▼
┌─────────────────────────────────────────────────────────────┐
│  TenantMailConfigService::applyMailerConfigForTenant()      │
│  - Le configuracoes do banco                                │
│  - Aplica: host=smtp-relay.brevo.com:587, tls              │
│  - Configura Laravel mailer                                 │
└──────────────────────┬──────────────────────────────────────┘
                       │
                       ▼
┌─────────────────────────────────────────────────────────────┐
│  Laravel Mail System                                         │
│  - Usa configuracao SMTP do Brevo                           │
│  - Envia emails normalmente                                 │
└─────────────────────────────────────────────────────────────┘
```

---

## 5. Seguranca

- A chave SMTP e armazenada encriptada (encrypt/decrypt do Laravel)
- A chave NUNCA e enviada ao frontend (similar ao SendGrid)
- Apenas email e nome do remetente sao expostos ao frontend

---

## 6. Testes

### Cenarios para testar:

1. **Configuracao inicial** - Selecionar Brevo, preencher campos, salvar
2. **Teste de conexao** - Clicar "Testar conexao" deve retornar sucesso
3. **Teste de envio** - Enviar email de teste deve chegar ao destinatario
4. **Persistencia** - Recarregar pagina deve manter configuracao
5. **Troca de provider** - Trocar de Brevo para SMTP e vice-versa
6. **Encriptacao** - Verificar que chave SMTP esta encriptada no banco

---

## 7. Dependencias

Nenhuma dependencia adicional necessaria. O Brevo usa SMTP padrao, que ja e suportado pelo Laravel.

---

## 8. Prioridade

**Alta** - Feature request do usuario, implementacao relativamente simples.

---

## 9. Tempo Estimado

- Backend: 2-3 horas
- Frontend: 2-3 horas
- Testes: 1 hora
- **Total: 5-7 horas**
