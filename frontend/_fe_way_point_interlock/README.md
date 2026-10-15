# Waypoint Interlock frontend

This page manages the `dbo.tb_08_area_strict` rules in the configured `WH2` database, using the DB Manager API for table reads and CRUD operations.

The rules list refreshes from the database every five seconds while the page is visible. Changes made through DB Manager are shown automatically.

Start PHP's built-in server from the project root:

```powershell
php -S 127.0.0.1:8000 -t .
```

Open `http://127.0.0.1:8000/frontend/_fe_way_point_interlock/`.

The checkpoint-area and strict-area search filters are mutually exclusive: clear the active filter before using the other one. Add/edit forms accept only `checkpoint_area` and `strict_area`; the table shows robot identities and status but omits `activate`, which is enabled automatically for newly created rules. Add/edit/delete operations follow the table's database metadata and primary key.

Use `Page_Activate/` to turn a rule's interlock on or off. Turning a stopped rule off resumes its recorded strict-area robot before clearing its stored robot state and setting status to `OFF`. Turning it on resets status to `RUN`. From the main page, select **Manage activation** to open this page.
