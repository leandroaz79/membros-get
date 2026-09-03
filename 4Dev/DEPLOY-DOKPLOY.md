# Guia Completo: Deploy Getfy no Dokploy

## Visao Geral

O Getfy roda em Docker com 3 componentes:
- **app** - PHP + Nginx (porta 3000)
- **queue** - Worker de filas + scheduler
- **MySQL** - Gerenciado pelo Dokploy ou externo
- **Redis** - Gerenciado pelo Dokploy ou externo

---

## Passo a passo no Dokploy

### Passo 1: Criar o servico de MySQL

1. No Dokploy, va em **Databases** > **MySQL**
2. Clique em **Create**
3. Configure:
   - Name: `getfy-mysql`
   - Database: `getfy`
   - User: `getfy`
   - Password: (anote esta senha)
4. Aguarde o MySQL iniciar
5. Anote o **Host** (ex: `dokploy-mysql` ou `mysql`)

### Passo 2: Criar o servico de Redis

1. No Dokploy, va em **Databases** > **Redis**
2. Clique em **Create**
3. Configure:
   - Name: `getfy-redis`
4. Anote o **Host**

### Passo 3: Criar a aplicacao Getfy

1. No Dokploy, va em **Applications** > **Create**
2. Tipo: **Docker**
3. Fonte: **Git**
4. Configure:
   - Name: `getfy-app`
   - Repository: `https://github.com/seu-usuario/getfy`
   - Branch: `main` (ou a que voce criou)
   - Dockerfile: `Dockerfile`
   - Build Context: `.` (ponto)

### Passo 4: Configurar variaveis de ambiente

Na aba **Environment** da aplicacao, adicione:

```
APP_ENV=production
APP_DEBUG=false
APP_URL=https://seudominio.com
APP_INSTALLED=true
APP_AUTO_MIGRATE=true

# MySQL (use o host do Dokploy)
DB_CONNECTION=mysql
DB_HOST=getfy-mysql
DB_PORT=3306
DB_DATABASE=getfy
DB_USERNAME=getfy
DB_PASSWORD=sua_senha_aqui

# Redis (use o host do Dokploy)
REDIS_HOST=getfy-redis
REDIS_PORT=6379
CACHE_STORE=redis
QUEUE_CONNECTION=redis
SESSION_DRIVER=file

# Docker
GETFY_DOCKER=true
GETFY_RUN_SETUP=true
```

### Passo 5: Configurar portas

Na aba **Domains** da aplicacao:
1. Adicione um dominio (ex: `app.seudominio.com`)
2. Port: `3000`
3. Protocolo: `HTTP` (ou HTTPS se tiver SSL)

### Passo 6: Configurar volumes

Na aba **Advanced** > **Docker Compose**:

Cole o conteudo de `docker-compose.dokploy.yml` ou configure manualmente:

```yaml
volumes:
  - getfy_storage:/var/www/html/storage
  - getfy_env:/var/www/html/.docker
```

### Passo 7: Deploy

1. Clique em **Deploy**
2. Aguarde o build (~10-15 min)
3. Verifique os logs

---

## Arquivos modificados para Dokploy

### .dockerignore (NOVO)
Evita copiar arquivos desnecessarios para a imagem.

### docker/nginx/getfy.conf (MODIFICADO)
- Porta 80 -> 3000

### Dockerfile (MODIFICADO)
- EXPOSE 80 -> EXPOSE 3000

### docker-compose.yml (MODIFICADO)
- Port mapping 80 -> 3000

### docker-compose.dokploy.yml (NOVO)
- Compose simplificado para Dokploy

### docker/entrypoint.sh (MODIFICADO)
- Timeout MySQL: 60s -> 120s
- Logs mais informativos

---

## Variaveis de ambiente obrigatorias

| Variavel | Descricao | Exemplo |
|----------|-----------|---------|
| `APP_URL` | URL do sistema | `https://app.seudominio.com` |
| `DB_HOST` | Host do MySQL | `getfy-mysql` |
| `DB_DATABASE` | Nome do banco | `getfy` |
| `DB_USERNAME` | Usuario MySQL | `getfy` |
| `DB_PASSWORD` | Senha MySQL | `senha_segura` |
| `REDIS_HOST` | Host do Redis | `getfy-redis` |

---

## Portas

| Servico | Porta | Descricao |
|---------|-------|-----------|
| app | 3000 | Nginx + PHP-FPM |
| mysql | 3306 | Banco de dados |
| redis | 6379 | Cache/Filas |

**IMPORTANTE:** A porta 80 e 443 sao gerenciadas pelo Traefik do Dokploy. Nao mapeie para essas portas.

---

## Troubleshooting

### Erro: "port is already allocated"

**Causa:** Porta 80 ja esta em uso pelo Traefik.

**Solucao:** Use a porta 3000 (ja configurada).

### Erro: "MySQL indisponivel"

**Causa:** App nao consegue conectar no MySQL.

**Solucao:**
1. Verifique se o MySQL esta rodando no Dokploy
2. Verifique se o `DB_HOST` esta correto
3. Verifique se as credenciais estao corretas
4. Verifique se o MySQL e acessivel da rede Docker

### Erro: "Permission denied" no storage

**Causa:** Permissoes de pastas.

**Solucao:** O entrypoint ja resolve isso automaticamente.

### Build muito lento

**Causa:** Compilacao de extensoes PHP.

**Solucao:** Normal na primeira vez. Builds subsequentes usam cache.

---

## Deploy sem MySQL/Redis internos

Se voce ja tem MySQL e Redis rodando externamente:

1. Use as variaveis de ambiente para apontar para os servicos externos
2. Nao crie os servicos de MySQL/Redis no Dokploy
3. Use o `docker-compose.dokploy.yml` (sem services mysql/redis)

---

## Backup

### Banco de dados
```bash
# Via Dokploy
Databases > getfy-mysql > Backup

# Via SSH
mysqldump -h DB_HOST -u DB_USERNAME -p DB_DATABASE > backup.sql
```

### Arquivos
```bash
# O volume getfy_storage contem uploads e configuracoes
# Faca backup regular deste volume
```

---

## Referencias

- [Dokploy Docs](https://docs.dokploy.com)
- [Getfy Docker](https://getfy.org/docs/docker)
- [Brevo SMTP](https://developers.brevo.com/docs/smtp-integration)
