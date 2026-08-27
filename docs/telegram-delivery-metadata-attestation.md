# Telegram delivery metadata attestation

The generic outbound Telegram delivery authority requires complete visibility of MariaDB InnoDB foreign keys before schema activation, rollback guard removal, or runtime queue/effect authority can arm.

## Security boundary

The ordinary application database principal (`DB_USERNAME`) must **not** have the global MariaDB `PROCESS` privilege. A separate `telegram_metadata` connection is used only for global InnoDB metadata attestation and must use a distinct principal configured through:

- `TELEGRAM_METADATA_DB_USERNAME`
- `TELEGRAM_METADATA_DB_PASSWORD`
- optional `TELEGRAM_METADATA_DB_URL` when the metadata principal must use an explicit connection URL; it must still reach the same MariaDB server as the application connection.

The metadata principal does not own application data and must not receive application schema/table DML or DDL privileges. Its required global grant is only:

```sql
GRANT PROCESS ON *.* TO '<metadata-user>'@'<application-host>';
```

Restrict the account host to the deployment path that needs attestation and manage its password as a protected deployment secret. Do not grant `PROCESS` to the ordinary application principal as a shortcut.

MariaDB requires `PROCESS` to read `information_schema.INNODB_SYS_FOREIGN`, which exposes the complete InnoDB foreign-key inventory. The ordinary `KEY_COLUMN_USAGE` visibility available to an application account can omit foreign keys owned by schemas/tables that account cannot inspect, so it is not sufficient as the sole proof that no incoming foreign key targets the Telegram authority tables.

## Fail-closed behavior

Telegram delivery authority is not ready when any of these conditions is true:

- the ordinary runtime principal can read `INNODB_SYS_FOREIGN` (indicating the privilege boundary was widened);
- the metadata connection cannot authenticate or lacks `PROCESS`;
- runtime and metadata connections do not resolve to the same MariaDB server identity;
- both connections resolve to the same database principal instead of distinct principals;
- the metadata principal has privileges beyond `PROCESS`/`USAGE` or has `GRANT OPTION`;
- any outgoing or incoming foreign key touches `telegram_delivery_authority_capability` or `telegram_delivery_operations`;
- the metadata inventory cannot be read completely.

The metadata connection performs read-only metadata queries. It does not mutate application tables, schema objects, grants, or provider state.

## Deployment order

1. Create/rotate the dedicated metadata account outside the application migration using the normal protected database-administration path.
2. Grant only global `PROCESS` and no application schema/table privileges.
3. Set the metadata credentials in the protected deployment environment.
4. Run the application migration/readiness checks. Missing or incomplete metadata authority intentionally blocks activation rather than silently falling back to `DB_USERNAME`.
5. Verify the ordinary application principal remains unable to query `information_schema.INNODB_SYS_FOREIGN`.

Credential/grant creation and rotation are deployment operations; repository migrations intentionally do not create database users or alter database privileges.
