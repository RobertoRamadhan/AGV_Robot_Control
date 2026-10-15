# DB Manager: SQL Server CRUD controller

This module provides a reusable PHP controller for Microsoft SQL Server `WH2`. It lists tables, reads paginated records, and supports insert/update/delete. Other backend modules can reuse `DatabaseManager.php` instead of building their own generic SQL CRUD layer. Database credentials stay in the backend process environment and are never sent to the browser.

## Configuration (PowerShell)

The defaults target the local instance shown in SSMS: `localhost\SQLEXPRESS`, using only the `WH2` database and Windows Integrated Authentication. The PHP process must run as a Windows account with permission to the database. `VisionInspection` is not included.

Override the connection in the same PowerShell session used to start PHP when needed:

```powershell
$env:DB_SQLSRV_SERVER = "localhost\SQLEXPRESS"
$env:DB_SQLSRV_PORT = ""
$env:DB_SQLSRV_DATABASES = "WH2"
$env:DB_SQLSRV_DATABASE = "WH2"
$env:DB_SQLSRV_USERNAME = "your_sql_login"
$env:DB_SQLSRV_PASSWORD = "your_password"
$env:DB_SQLSRV_ENCRYPT = "false"
$env:DB_SQLSRV_TRUST_SERVER_CERTIFICATE = "false"
```

For Windows integrated authentication, set `DB_SQLSRV_TRUSTED_CONNECTION` to `true` and leave username/password unset. A named instance can be supplied as the server, for example `localhost\SQLEXPRESS`; leave the port empty in that case.

`DB_SQLSRV_DATABASES` is a comma-separated allowlist of databases available to this manager. `DB_SQLSRV_DATABASE` selects the default database. The checked-in defaults include only `WH2`. For production, enable encryption and configure a valid SQL Server certificate rather than trusting a self-signed certificate.

PHP must have both PDO and the Microsoft `pdo_sqlsrv` extension enabled. The matching Microsoft ODBC Driver for SQL Server is also required by that extension.

## Run locally

From the project root, start PHP's built-in server:

```powershell
php -S 127.0.0.1:8000 -t .
```

Open `http://127.0.0.1:8000/frontend/_fe_db_manager/`.

## Module mapping

- `Point Binding`: `dbo.tb_07_area_ship_bind`
- `Transport Order`: pallet tables `dbo.tb_01_pallet_topup`, `dbo.tb_02_pallet_prod`, and `dbo.tb_03_pallet_ship`, plus active/history task tables `dbo.tb_09_task_active` and `dbo.tb_10_task_history`.
- `Waypoint Interlock`: route tables `dbo.tb_04_routes_topup_to_prod`, `dbo.tb_05_routes_prod_to_ship`, `dbo.tb_06_routes_ship_to_topup`, plus `dbo.tb_08_area_strict`.
- `Semua data`: all application tables in `WH2`, including `dbo.tb_00_apps_setting`.

The mappings are defined in backend config and returned by `GET ?action=modules`. The API checks that a row operation's selected module contains the requested table.

The Transport Order, Waypoint Interlock, and DB Manager pages read their live operational data from these database tables. They refresh automatically every five seconds while the page is visible, so changes made in one part of the application appear in the others without manually reloading. Static labels and explanatory text are frontend content, not database records. Transport Order uses the existing pallet and task tables and does not require a separate trolley inventory table.

## API

- `GET ?action=databases` lists configured databases.
- `GET ?action=status&database=WH2` tests the database connection.
- `GET ?action=tables&database=WH2` lists application tables.
- `GET ?action=rows&database=WH2&module=transport_order&schema=dbo&table=tb_09_task_active&page=1&page_size=25` returns columns, primary-key metadata, and rows.
- The `rows` action accepts an optional JSON `filters` query parameter containing up to four `equals`, `not_equals`, `contains`, or `contains_any` filters. A `contains_any` filter uses `{ "operator": "contains_any", "columns": ["task_id", "status"], "value": "text" }`; filters are parameterized and applied before pagination. `sort=descending` reverses primary-key order.
- `POST ?action=create&database=WH2` with `{ "module": "point_binding", "schema": "dbo", "table": "tb_07_area_ship_bind", "values": { ... } }` inserts a row.
- `PUT ?action=update&database=WH2` with `{ "module": "point_binding", "schema": "dbo", "table": "tb_07_area_ship_bind", "key": { "code_ship": "..." }, "values": { ... } }` updates a row.
- `DELETE ?action=delete&database=WH2` with `{ "module": "point_binding", "schema": "dbo", "table": "tb_07_area_ship_bind", "key": { "code_ship": "..." } }` deletes a row.

All writable columns, including primary-key columns, can be edited. For a table with a primary key, its original key locates the row while the submitted values can change the key. Identity, computed, and rowversion columns remain read-only because SQL Server manages them. Tables without a primary key use the complete original row as a locator; the operation is rejected if that row is duplicated, has changed, or contains a type that cannot safely be compared. For those tables, send the locator as `"key": { "__row": { ...complete original row... } }`. Empty nullable form fields are saved as `NULL`; empty required fields must be filled explicitly, so blank inputs do not silently invoke database defaults. Table and column identifiers are resolved from SQL Server metadata and values use prepared statements. Keep this admin API on a trusted internal network; it does not yet include user authentication or authorization.
