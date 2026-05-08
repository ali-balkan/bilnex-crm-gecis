# Bilnex CRM Coolify Deploy

## Application

- Project: `Bilnex CRM`
- Domain: `bilnexcrm.tumotomasyonlar.cloud`
- Build pack: Dockerfile
- Dockerfile path: `apps/crm-web/Dockerfile`
- Build context: repository root
- Port: `80`

## Environment Variables

Set these in Coolify before first deploy:

```env
CRM_BASE_URL=
CRM_COMPANY_SOURCE=sqlserver
BILNEX_SQL_SERVER=SQL_SERVER_HOST_OR_IP
BILNEX_SQL_DATABASE=BILNEX_CRMDB
BILNEX_SQL_USERNAME=CRM_SQL_USER
BILNEX_SQL_PASSWORD=CRM_SQL_PASSWORD
BILNEX_SQL_TRUST_CERTIFICATE=1
BILNEX_SQL_DOTNET_BRIDGE=0
```

Use a limited SQL user for CRM. Do not use the `sa` account in production.

## Persistence

The application uses SQLite only for CRM users, tasks, opportunities, and local CRM records. Bilnex customer cards are read from SQL Server `dbo.Customer`.

For persistent CRM data in Coolify, mount a volume to:

```text
/var/www/html/apps/crm-web/data
```

If the volume is empty on first deploy, the app will create a fresh SQLite database with the initial `superadmin` user.
