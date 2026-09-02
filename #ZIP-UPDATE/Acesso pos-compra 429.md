# Clientes não conseguem acessar após compra (429 / tela preta)

Sintomas comuns:

- Ao clicar no link de acesso aparece **`429 | TOO MANY REQUESTS`**
- Tela escura/preta no login ou na área de membros (JavaScript não carrega)
- Link “não abre” e cai no login pedindo senha

---

## Causas mais frequentes

1. **Link sem login automático** (versões antigas): página de obrigado ou URL manual (`/m/slug`) em vez do botão do e-mail. Atualize para **2.0.3+** — o botão de obrigado passa a usar o mesmo magic link do e-mail.
2. **Muitas tentativas de login** — limite por IP (Laravel ou Cloudflare).
3. **`APP_URL` incorreto** — magic link assinado inválido → redireciona para login.
4. **Cloudflare** bloqueando ou cacheando `/login`, `/access`, `/m/*`.
5. **`public/build` ausente** na imagem Docker — tela preta no login (sem assets Vue).

---

## Checklist Docker + Cloudflare

### 1. APP_URL

No `.env` e em `.docker/app.url` (Docker), use a URL pública **exata**:

```env
APP_URL=https://seudominio.com
```

- Mesmo esquema (**https**), mesmo host (com ou sem `www` — igual ao que o cliente usa).
- Após alterar: `php artisan config:clear` (ou reinicie o container).

### 2. Cloudflare SSL

- SSL/TLS: **Full (strict)** (origin com certificado válido).
- O proxy deve enviar `X-Forwarded-Proto: https` (o Getfy já força HTTPS quando recebe esse header).

### 3. Rate limiting no Cloudflare

Em **Security → WAF** ou **Rate limiting rules**, evite regras agressivas em:

- `/login`
- `/access`
- `/m/*`
- `/checkout/*`

Se o 429 **não** aparecer em `storage/logs/laravel.log`, o bloqueio veio do **Cloudflare**, não do Laravel.

### 4. Cache

- Não cachear HTML dinâmico (`/login`, `/m/*`, `/access`).
- Não cachear requisições `POST`.
- Bypass cache para rotas autenticadas e magic links (`?signature=`).

### 5. Assets (tela preta)

Confirme na imagem/container:

```text
public/build/manifest.json
```

Se não existir, gere antes do build Docker:

```bash
npm install && npm run build
```

### 6. Variáveis opcionais de rate limit (`.env`)

A partir da 2.0.3:

```env
LOGIN_RATE_PER_MINUTE=20
MAGIC_ACCESS_RATE_PER_MINUTE=60
PASSWORD_RESET_RATE_PER_MINUTE=6
```

Aumente temporariamente se muitos clientes legítimos forem bloqueados (ex.: `LOGIN_RATE_PER_MINUTE=40`).

---

## O que orientar o cliente

1. Usar o **botão do e-mail de compra** (“Acessar agora”), não copiar URL da página de obrigado.
2. Se aparecer 429, **aguardar 1 minuto** e tentar de novo.
3. Se o link expirou (7 dias), fazer login com **e-mail e senha** do e-mail de compra.
4. Se a tela ficar preta, testar em outro navegador ou aba anônima (extensões do Chrome podem interferir).

---

## Workaround para o produtor (sem atualizar ainda)

Reenviar o e-mail de acesso pelo painel (**Vendas** → pedido) ou informar e-mail + senha enviados na compra e a URL de login da área (`/m/{slug}/login` ou domínio próprio).
