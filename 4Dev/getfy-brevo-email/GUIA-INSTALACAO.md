# Guia: Aplicar Modificacao Brevo no Getfy

## Cenário 1: Sistema existente (já instalado)

### Pré-requisitos
- Acesso ao servidor (SSH ou painel de hospedagem)
- Backup do banco de dados e dos arquivos
- Node.js/npm no servidor (para rebuildar o frontend)

### Passo a passo

#### 1. Backup
```bash
# Backup do banco
mysqldump -u getfy -p getfy > backup_$(date +%Y%m%d).sql

# Backup dos arquivos
cp -r app/ app_backup/
cp -r resources/js/ resources/js_backup/
```

#### 2. Copiar os arquivos modificados

Copie estes 6 arquivos para o servidor, mantendo a estrutura de pastas:

| Arquivo local | Destino no servidor |
|---------------|---------------------|
| `app/Services/TenantMailConfigService.php` | `app/Services/TenantMailConfigService.php` |
| `app/Http/Controllers/SettingsController.php` | `app/Http/Controllers/SettingsController.php` |
| `app/Http/Controllers/EmailTestController.php` | `app/Http/Controllers/EmailTestController.php` |
| `resources/js/Pages/Settings/Index.vue` | `resources/js/Pages/Settings/Index.vue` |
| `resources/js/components/EmailProviderSidebar.vue` | `resources/js/components/EmailProviderSidebar.vue` |
| `public/images/integrations/brevo.svg` | `public/images/integrations/brevo.svg` |

#### 3. Rebuildar o frontend
```bash
cd /caminho/do/projeto
npm install
npm run build
```

#### 4. Limpar cache do Laravel
```bash
php artisan cache:clear
php artisan config:clear
php artisan view:clear
```

#### 5. Testar
- Acesse **Configuracoes > E-mail**
- O card do Brevo deve aparecer como 4a opcao
- Configure e teste o envio

---

## Cenário 2: Instalação completa com código modificado

### Opção A: Clonar e buildar

```bash
# 1. Clonar o repositorio
git clone https://github.com/seu-usuario/getfy.git
cd getfy

# 2. Instalar dependencias PHP
composer install

# 3. Instalar dependencias JS e buildar
npm install
npm run build

# 4. Configurar o .env
cp .env.example .env
php artisan key:generate

# 5. Configurar banco de dados no .env
# DB_HOST=localhost
# DB_DATABASE=getfy
# DB_USERNAME=getfy
# DB_PASSWORD=sua_senha

# 6. Rodar migrations
php artisan migrate

# 7. Iniciar o servidor
php artisan serve
```

### Opção B: Docker (recomendado)

```bash
# 1. Clonar o repositorio
git clone https://github.com/seu-usuario/getfy.git
cd getfy

# 2. Criar docker-compose.override.yml para bind mount
cat > docker-compose.override.yml << 'EOF'
services:
  app:
    volumes:
      - .:/var/www/html
EOF

# 3. Iniciar os containers
docker compose up -d

# 4. Acessar o container
docker exec -it getfy-app-1 sh

# 5. Dentro do container, rodar migrations
php artisan migrate

# 6. Sair do container
exit

# 7. Acessar http://localhost
```

### Opção C: Download ZIP (hospedagem compartilhada)

```bash
# 1. Baixar a release mais recente do GitHub
# https://github.com/seu-usuario/getfy/releases

# 2. Descompactar no servidor

# 3. Configurar .env
cp .env.example .env
php artisan key:generate

# 4. Rodar migrations via SSH ou terminal de hospedagem
php artisan migrate

# 5. Acessar o painel e configurar email
```

---

## Diferenças entre os cenários

| Aspecto | Cenário 1 (sistema existente) | Cenário 2 (instalação completa) |
|---------|-------------------------------|--------------------------------|
| Migrações | Não precisa rodar | Precisa rodar |
| Configurações | Já existem | Precisa configurar |
| Frontend | Precisa rebuildar | Já vem buildado |
| Tempo | ~5 minutos | ~15 minutos |
| Risco | Baixo (só altera email) | Médio (instalação completa) |

---

## Solução de problemas

### Card do Brevo não aparece
```bash
# Limpar cache do navegador
Ctrl+Shift+R (Windows/Linux)
Cmd+Shift+R (Mac)

# Rebuildar frontend
npm run build
```

### Erro ao salvar configurações
```bash
# Verificar logs
tail -f storage/logs/laravel.log

# Limpar cache
php artisan cache:clear
```

### Chave SMTP não salva
- Verifique se a chave tem mais de 8 caracteres
- O sistema encripta automaticamente ao salvar

---

## Chaves salvas no banco

| Chave | Tipo | Descrição |
|-------|------|-----------|
| `email_provider` | Texto | Valor: 'brevo' |
| `brevo_smtp_username` | Texto | Email de login SMTP |
| `brevo_smtp_key` | Encriptado | Chave SMTP |
| `brevo_mail_from_address` | Texto | Email remetente |
| `brevo_mail_from_name` | Texto | Nome remetente |

---

## Referências

- [Brevo SMTP Relay](https://developers.brevo.com/docs/smtp-integration)
- [Gerenciar Chaves SMTP](https://help.brevo.com/hc/en-us/articles/7959631848850)
- [Documentação Getfy](https://getfy.org/developers)
