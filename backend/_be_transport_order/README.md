# Transport Order

This module serves the Transport Order frontend from the SQL Server database configured by `backend/_be_db_manager/config.php`. The dashboard refreshes every five seconds.

## AGV workflow

1. Production creates a pallet request for Station 01 or Station 02. It occupies one of the two trolley slots while waiting.
2. The TopUp page shows the request and requested pallet type. After physically loading the pallet onto the trolley, the TopUp operator presses **Send**. No pallet inventory form or TopUp stock record is required.
3. Before TopUp sends the pallet, the Production area shows **Waiting for TopUp**. After **Send**, it shows **Pallet in transit** and the detail page shows the inbound pallet. The AGV reads the `PENDING` task from `dbo.tb_09_task_active`, picks it up, and reports pickup and arrival through `confirm-pickup` and `confirm-arrival`. At arrival, the system creates a **READY** Production pallet and reserves the trolley at that station. Production can then press **Send to Shipping** when the pallet is ready to leave.
4. After Production has filled the pallet, the operator presses **Send to Shipping**. The AGV moves the trolley and pallet to Shipping. On arrival, the pallet becomes available in Shipping and the active trolley task waits for Shipping.
5. After unloading the pallet, Shipping presses **Send Empty Trolley to TopUp**. The pallet stays in Shipping; only the empty trolley is sent back. The trolley slot remains occupied until the AGV reports arrival at TopUp.

AGV task destinations are the agreed literal area names: `Station 01`, `Station 02`, `Shipping`, and `TopUp Station`. A station can have only one pallet/request in progress. New Production requests are rejected while both trolley slots are in use.

## API

- `GET ?action=dashboard` returns TopUp requests and deliveries, trolley returns, Production/Shipping area state, and pallet data.
- `GET ?action=history&page=1&search=` returns up to 100 matching history entries and pagination totals.
- `POST ?action=create-task` with `{ "task_type": "PALLET_REQUEST", "area": "Station 01", "pallet_type": "Plastic Pallet" }` creates a Production request.
- `POST ?action=send-request` with `{ "task_id": "TO-..." }` queues the matching Production request as a `PENDING` TopUp-to-Production AGV task.
- `POST ?action=create-task` with `{ "task_type": "PRODUCTION_TO_SHIP", "code": "PLT-...", "pallet_type": "Plastic Pallet" }` queues a loaded trolley and pallet from Production to Shipping.
- `POST ?action=dispatch-trolley-to-topup` with `{ "task_id": "TO-..." }` queues only the empty trolley from Shipping to TopUp and releases the unloaded Shipping pallet from its transport task.
- `POST ?action=confirm-pickup` with `{ "task_id": "TO-..." }` changes a `PENDING` AGV task to `IN_PROGRESS`.
- `POST ?action=confirm-arrival` with `{ "task_id": "TO-..." }` completes an AGV leg and advances the trolley cycle or records the final return.

Pallet types are `Plastic Pallet`, `Wooden Pallet`, and `Blue Pallet`. Internal Production pallet codes for pallets physically loaded at TopUp are generated from their request task IDs. Workflow writes use SQL transactions and prepared statements. Run the PHP server and open `http://127.0.0.1:8000/frontend/_fe_transport_order/`.
