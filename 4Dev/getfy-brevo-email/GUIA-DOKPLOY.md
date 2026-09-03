# Guia: Aplicar Brevo no Getfy via Dokploy

## Contexto

Voce tem o Getfy rodando em Docker via Dokploy, com dados de clientes e configuracoes existentes.
O objetivo e aplicar apenas a modificacao do Brevo sem perder nenhum dado.

## Como funciona o Getfy no Docker

```
┌─────────────────────────────────────────┐
│  Dokploy                                 │
├─────────────────────────────────────────┤
│  Container: getfy-app                    │
│  ├─ Codigo: baked na imagem (Dockerfile) │
│  ├─ storage/: volume persistido          │
│  ├─ .docker/: volume persistido          │
│  └─ .env: volume persistido              │
└─────────────────────────────────────────┘
```

**IMPORTANTE:** O codigo-fonte fica DENTRO da imagem Docker.
Para alterar o codigo, voce precisa reconstruir a imagem.

---

## Opcao 1: Fork + Build via Dokploy (Recomendada)

### Passo 1: Fork o repositorio

1. Acesse o GitHub do Getfy
2. Clique em "Fork" para copiar para sua conta
3. Clone o fork localmente:

```bash
git clone https://github.com/seu-usuario/getfy.git
cd getfy
```

### Passo 2: Aplicar as modificacoes do Brevo

Copie os 6 arquivos modificados para o fork:

```bash
# Exemplo: copiar de um repositorio local ou aplicar manualmente
# Os arquivos sao:
# - app/Services/TenantMailConfigService.php
# - app/Http/Controllers/SettingsController.php
# - app/Http/Controllers/EmailTestController.php
# - resources/js/Pages/Settings/Index.vue
# - resources/js/components/EmailProviderSidebar.vue
# - public/images/integrations/brevo.svg
```

### Passo 3: Build e push da imagem

```bash
# Build da imagem
docker build -t getfy-app:brevo .

# Tag para o registry (use o seu registry)
docker tag getfy-app:brevo registry.seudominio.com/getfy-app:brevo

# Push
docker push registry.seudominio.com/getfy-app:brevo
```

### Passo 4: Atualizar no Dokploy

1. Acesse o painel do Dokploy
2. Vá em **Applications** > **getfy-app**
3. Na aba **Settings**:
   - Atualize a imagem para: `registry.seudominio.com/getfy-app:brevo`
4. Clique em **Deploy**

### Passo 5: Verificar

1. Acesse o Getfy
2. Va em **Configuracoes > E-mail**
3. O card do Brevo deve aparecer

---

## Opcao 2: Build direto no Dokploy (Sem registry)

Se voce nao usa registry privado:

### Passo 1: Configure o Dokploy para build do codigo

No Dokploy, ao inves de usar uma imagem pronta, configure para buildar do repositorio:

1. Em **Applications**, crie uma nova aplicacao
2. Tipo: **Docker**
3. Fonte: **Git**
4. Repositorio: `https://github.com/seu-usuario/getfy`
5. Branch: `main` (ou a que voce criou)
6. Dockerfile: `Dockerfile`

O Dokploy vai buildar a imagem automaticamente!

### Passo 2: Configure as variaveis de ambiente

No Dokploy, configure as mesmas variaveis que voce ja usa:

```
APP_URL=https://seudominio.com
DB_HOST=mysql
DB_DATABASE=getfy
DB_USERNAME=getfy
DB_PASSWORD=sua_senha
# ... outras variaveis
```

### Passo 3: Configure os volumes

No Dokploy, adicione os volumes para persistir dados:

```
getfy_storage:/var/www/html/storage
getfy_env:/var/www/html/.docker
```

### Passo 4: Deploy

Clique em **Deploy** e aguarde o build.

---

## Opcao 3: Mount de arquivos (Sem rebuild)

**Nao recomendado** - mas funciona para testes rapidos:

### Passo 1: Copie os arquivos para o servidor

```bash
# No servidor, crie uma pasta temporaria
mkdir -p /tmp/brevo-patch

# Copie os arquivos modificados para la
# (use scp, SFTP, ou whatever)
```

### Passo 2: Mount no Dokploy

No Dokploy, configure volumes extras:

```
/tmp/brevo-patch/app/Services/TenantMailConfigService.php:/var/www/html/app/Services/TenantMailConfigService.php
/tmp/brevo-patch/app/Http/Controllers/SettingsController.php:/var/www/html/app/Http/Controllers/SettingsController.php
# ... etc
```

**Problema:** Precisa rebuildar o frontend (npm run build) dentro do container.

---

## O que NAO fazer

```bash
# NAO faca restore do banco de dados
mysqldump ... > backup.sql  # ❌ NÃO RESTAURE ESTE

# NAO delete o volume storage
docker volume rm getfy_storage  # ❌ PERDE Uploads

# NAO delete o volume .docker
docker volume rm getfy_env  # ❌ PERDE Chaves
```

---

## Checklist de seguranca

Antes de aplicar a modificacao:

- [ ] Backup do banco (mysqldump)
- [ ] Backup do volume storage
- [ ] Backup do volume .docker
- [ ] Testar em ambiente de homologacao primeiro

---

## Resumo rapido

| Opcao | Dificuldade | Tempo | Perde Dados? |
|-------|-------------|-------|--------------|
| Fork + Build | Media | 30min | Nao |
| Build no Dokploy | Facil | 20min | Nao |
| Mount files | Dificil | 10min | Nao |

**Recomendacao:** Use a **Opcao 2** (Build direto no Dokploy) se quisersimplicidade.
Use a **Opcao 1** (Fork + Registry) se quiser mais controle.

---

## Comandos uteis

```bash
# Ver logs do container
docker logs getfy-app-1

# Entrar no container
docker exec -it getfy-app-1 sh

# Limpar cache dentro do container
docker exec getfy-app-1 php artisan cache:clear

# Verificar se o Brevo esta configurado
docker exec getfy-app-1 php artisan tinker --execute="dd(\App\Models\Setting::where('key', 'email_provider')->first());"
```

---

## Solucao de problemas

### Erro: "npm not found" no build

Adicione Node.js ao Dockerfile ou use multi-stage build:

```dockerfile
# Adicione antes do build final
FROM node:20-alpine AS js_builder
WORKDIR /app
COPY package*.json ./
RUN npm ci
COPY . .
RUN npm run build

# Copie o build resultante
COPY --from=js_builder /app/public/build /var/www/html/public/build
```

### Container nao inicia apos deploy

```bash
# Ver logs
docker logs getfy-app-1

# Verificar se as variaveis estao corretas
docker exec getfy-app-1 env | grep DB_
```

### Card do Brevo nao aparece

```bash
# Rebuildar frontend dentro do container
docker exec getfy-app-1 npm run build

# Ou limpar cache
docker exec getfy-app-1 php artisan cache:clear
```
