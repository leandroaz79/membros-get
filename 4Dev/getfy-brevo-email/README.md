# Getfy - Provedor de Email Brevo

Implementacao do provedor de email **Brevo** (antigo Sendinblue) para o sistema Getfy.

## O que e

Adiciona o Brevo como 4a opcao de provedor de email, junto com:
- SMTP (generico)
- Hostinger Mail
- SendGrid

## Configuracao Brevo

| Parametro | Valor |
|-----------|-------|
| Host SMTP | `smtp-relay.brevo.com` |
| Porta | `587` |
| Encryption | `tls` |
| Username | Email de login SMTP |
| Password | Chave SMTP |

## Arquivos modificados

| Arquivo | Descricao |
|---------|-----------|
| `app/Services/TenantMailConfigService.php` | Logica do provider |
| `app/Http/Controllers/SettingsController.php` | API de configuracao |
| `app/Http/Controllers/EmailTestController.php` | Teste de conexao/envio |
| `resources/js/Pages/Settings/Index.vue` | Interface do usuario |

## Documentacao

- [Guia de implementacao](IMPLEMENTACAO.md)
- [Prompt para LLM](PROMPT-LLM.md)

## Referencias

- [Brevo SMTP Relay](https://developers.brevo.com/docs/smtp-integration)
- [Gerenciar Chaves SMTP](https://help.brevo.com/hc/en-us/articles/7959631848850)
- [Documentacao Getfy](https://getfy.org/developers)
