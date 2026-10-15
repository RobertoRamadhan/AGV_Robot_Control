# Waypoint Interlock

This backend evaluates active rules in `dbo.tb_08_area_strict` in database `WH2`.

## Rule behavior

- `activate = 1` enables a rule; other values are skipped.
- Area names are never hardcoded: each rule reads `checkpoint_area` and `strict_area` directly from its `dbo.tb_08_area_strict` database row. The names in those columns must match the block names accepted by the robot API.
- Robot occupancy is read from `GET /getRobotsInBlock?block=<area>`, which must return JSON in the form `{ "robots": ["AGV01"] }`.
- `checkpoint_area` is the area that keeps operating; the interlock never pauses its robot.
- The robot in `checkpoint_area` has priority and is allowed to pass. If that area and `strict_area` are both occupied by different robots, only the robot in `strict_area` is stopped by `POST /gotoSitePause` with `{ "vehicles": ["AGV01"] }`; `status` is saved as `STOP`.
- The interlock remembers that priority robot in `checkpoint_agv`. It does not resume the strict-area robot merely because the checkpoint block becomes empty: the priority robot must no longer be detected in either `checkpoint_area` or `strict_area`. Once it has passed through and exited the strict area, the stopped vehicle from `strict_agv` is resumed by `POST /gotoSiteResume` with `{ "vehicles": ["AGV01"] }`, and `status` is saved as `RUN`.
- If another robot is waiting in `checkpoint_area` after the previous priority robot exits, it becomes the next priority robot and the strict-area robot remains stopped until that next robot has also passed.
- `checkpoint_agv` and `strict_agv` retain the robot identities needed to make the stop/resume decision safe. If multiple robots occupy a block and the priority or strict robot cannot be uniquely identified, the rule reports an error rather than choosing arbitrarily.
- Existing `OFF` rows are treated as stopped for compatibility. An `OFF` row without `checkpoint_agv` cannot be safely resumed automatically and is reported as an error.

## Configure the robot API

Set `WAYPOINT_ROBOT_API_BASE_URL` in the environment of the PHP process. The default is `http://idgbthb2a1vw103:8088`.

Example in PowerShell, before starting PHP:

```powershell
$env:WAYPOINT_ROBOT_API_BASE_URL = "http://idgbthb2a1vw103:8088"
php -S 127.0.0.1:8000 -t .
```

## Evaluate rules

Send a JSON POST to:

```text
http://127.0.0.1:8000/backend/_be_way_point_interlock/api.php?action=evaluate
```

Example:

```powershell
Invoke-RestMethod `
  -Method Post `
  -Uri "http://127.0.0.1:8000/backend/_be_way_point_interlock/api.php?action=evaluate" `
  -ContentType "application/json" `
  -Body "{}"
```

The endpoint returns per-rule results and errors. The main Waypoint Interlock page calls it immediately and every five seconds while the page is visible; the page must stay open for automatic evaluation. This action can send pause/resume commands to AGVs. Keep this endpoint and the robot API on a trusted internal network.

## Create a rule

The frontend sends only `checkpoint_area` and `strict_area` to `POST ?action=create-rule`. The backend allocates the next integer `id` while holding a table lock, initializes `checkpoint_agv` and `strict_agv` to `NULL`, and sets a new rule to `status=RUN` and `activate=1`. Robot identities are populated by the evaluator; they are not entered in the frontend.

## Toggle a rule

`POST ?action=set-activation` accepts `{ "id": 123, "activate": false }`. Turning a rule off resumes its stored `strict_agv` first if its status is `STOP` or `OFF`; when the resume succeeds, the backend disables the rule, clears both stored robot identities, and sets status to `OFF`. Turning it back on clears robot identities and sets status to `RUN`. The evaluator skips inactive rules, so both areas then operate without interlock priority. If the robot cannot be safely resumed, the API reports an error and leaves the rule state unchanged.
