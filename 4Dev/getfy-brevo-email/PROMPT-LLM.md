# Prompt para LLM: Implementar Provedor de Email Brevo no Getfy

> **Uso:** Cole este prompt em uma IDE com opencode para implementar o provedor Brevo.
> **Requisitos:** Acesso ao codigo-fonte do Getfy 2.0.4+

---

## Prompt

```
Implemente o provedor de email Brevo (antigo Sendinblue) no sistema Getfy.

## Contexto

O Getfy possui 3 provedores de email: SMTP (generico), Hostinger e SendGrid.
Preciso adicionar o Brevo como 4a opcao. O Brevo usa SMTP padrao:

- Host: smtp-relay.brevo.com
- Porta: 587
- Encryption: tls
- Username: Email de login SMTP do Brevo
- Password: Chave SMTP (nao API key)

## Arquivos para modificar

### 1. app/Services/TenantMailConfigService.php

Adicionar caso para Brevo em:
- `getMailConfigForProvider()` - retornar config SMTP do Brevo
- `applyMailerConfigForTenant()` - configurar from address/name
- `isEmailConfigured()` - verificar se Brevo esta configurado
- `resolveTenantIdForMail()` - adicionar 'brevo_smtp_key' na busca

### 2. app/Http/Controllers/SettingsController.php

Adicionar em:
- Validacao: `'email_provider' => [..., 'brevo']`
- Validacao: campos `brevo_smtp_username`, `brevo_smtp_key`, `brevo_mail_from_address`, `brevo_mail_from_name`
- `index()`: retornar settings do Brevo para o frontend
- `update()`: salvar e encriptar `brevo_smtp_key`

### 3. app/Http/Controllers/EmailTestController.php

Adicionar em:
- Validacao: `'email_provider' => [..., 'brevo']`
- Validacao: campos do Brevo
- `buildMailOverridesFromRequest()`: caso para Brevo

### 4. resources/js/Pages/Settings/Index.vue

Adicionar em:
- Array `providers`: objeto Brevo com id, title, logo, description
- `useForm()`: campos brevo_smtp_username, brevo_smtp_key, brevo_mail_from_address, brevo_mail_from_name
- `isProviderConfigured()`: caso para Brevo
- `sendTestEmail()` e `testConnection()`: payload do Brevo
- Template: sidebar de configuracao do Brevo (campos: email remetente, nome remetente, email login SMTP, chave SMTP)

## Regras importantes

1. A chave SMTP (`brevo_smtp_key`) DEVE ser encriptada com `encrypt()` antes de salvar
2. A chave SMTP NUNCA deve ser enviada ao frontend (apenas email e nome)
3. Seguir o padrao do SendGrid (campos encriptados, validacao, etc.)
4. Usar as mesmas cores/estilo do UI existente
5. Logo do Brevo em `public/images/integrations/brevo.png`

## Chaves no banco (tabela settings)

- `email_provider` = 'brevo'
- `brevo_smtp_username` = email login (nao encriptado)
- `brevo_smtp_key` = chave SMTP (encriptada)
- `brevo_mail_from_address` = email remetente
- `brevo_mail_from_name` = nome remetente

## Fluxo

1. Usuario seleciona "Brevo" na aba Email
2. Preenche: email remetente, nome, email login SMTP, chave SMTP
3. Clica Salvar -> chave e encriptada e salva
4. Ao enviar email, Laravel usa config SMTP do Brevo
5. Teste de conexao verifica se SMTP do Brevo responde

## Documentacao Brevo

- SMTP Relay: https://developers.brevo.com/docs/smtp-integration
- Chaves SMTP: https://help.brevo.com/hc/en-us/articles/7959631848850
- Host: smtp-relay.brevo.com, Porta: 587, TLS
```

---

## Como Usar

1. Abra o opencode na IDE
2. Cole o prompt acima
3. A LLM implementara as mudancas nos arquivos
4. Teste a funcionalidade
5. Commit das alteracoes
