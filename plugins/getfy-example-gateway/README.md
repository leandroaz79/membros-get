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
