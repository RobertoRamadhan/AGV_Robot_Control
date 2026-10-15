const apiUrl = "../../backend/_be_db_manager/api.php";
const pageSize = 25;
const state = {
  database: "",
  module: "all",
  modules: {},
  tables: [],
  selected: null,
  columns: [],
  primaryKey: [],
  rows: [],
  page: 1,
  totalPages: 1,
  editing: null,
};

const elements = {
  databaseName: document.querySelector("#database-name"),
  connectionState: document.querySelector("#connection-state"),
  connectionLabel: document.querySelector("#connection-label"),
  serverAddress: document.querySelector("#server-address"),
  tableFilter: document.querySelector("#table-filter"),
  tableList: document.querySelector("#table-list"),
  tableCount: document.querySelector("#table-count"),
  breadcrumb: document.querySelector("#table-breadcrumb"),
  selectedTitle: document.querySelector("#selected-table-title"),
  refreshButton: document.querySelector("#refresh-button"),
  addButton: document.querySelector("#add-button"),
  notice: document.querySelector("#notice"),
  rowSummary: document.querySelector("#row-summary"),
  rowKeyNote: document.querySelector("#row-key-note"),
  gridHead: document.querySelector("#grid-head"),
  gridBody: document.querySelector("#grid-body"),
  emptyState: document.querySelector("#empty-state"),
  emptyRows: document.querySelector("#empty-rows"),
  overviewTitle: document.querySelector("#overview-title"),
  overviewCopy: document.querySelector("#overview-copy"),
  tableOverview: document.querySelector("#table-overview"),
  gridFrame: document.querySelector("#grid-frame"),
  pagination: document.querySelector("#pagination"),
  pageLabel: document.querySelector("#page-label"),
  previousPage: document.querySelector("#previous-page"),
  nextPage: document.querySelector("#next-page"),
  footerDatabase: document.querySelector("#footer-database"),
  dialog: document.querySelector("#record-dialog"),
  form: document.querySelector("#record-form"),
  dialogTitle: document.querySelector("#dialog-title"),
  dialogEyebrow: document.querySelector("#dialog-eyebrow"),
  fieldList: document.querySelector("#field-list"),
  dialogError: document.querySelector("#dialog-error"),
  saveRecord: document.querySelector("#save-record"),
};
let databaseLoading = false;

async function request(action, { method = "GET", params = {}, body } = {}) {
  const url = new URL(apiUrl, window.location.href);
  url.searchParams.set("action", action);
  if (state.database) url.searchParams.set("database", state.database);
  if (["rows", "create", "update", "delete"].includes(action)) {
    url.searchParams.set("module", state.module);
  }
  for (const [key, value] of Object.entries(params)) url.searchParams.set(key, String(value));

  let response;
  for (let attempt = 0; ; attempt += 1) {
    try {
      response = await fetch(url, {
        method,
        headers: { Accept: "application/json", ...(body ? { "Content-Type": "application/json" } : {}) },
        body: body ? JSON.stringify(body) : undefined,
        cache: "no-store",
      });
      break;
    } catch (error) {
      if (!(error instanceof TypeError) || method !== "GET" || attempt >= 1) throw error;
      await new Promise((resolve) => window.setTimeout(resolve, 500));
    }
  }
  const responseText = await response.text();
  let result;
  try {
    result = JSON.parse(responseText);
  } catch {
    if (responseText.trimStart().startsWith("<?php")) {
      throw new Error("Backend mengirim source PHP, bukan JSON. Buka frontend melalui server PHP dari root project; Live Server tidak menjalankan file PHP.");
    }
    throw new Error(`Respons backend bukan JSON (HTTP ${response.status}). Pastikan URL API memakai server PHP.`);
  }
  if (!response.ok || !result.ok) throw new Error(result.error || `Request gagal (${response.status}).`);
  return result;
}

function setConnection(connected, label, server = "") {
  elements.connectionState.dataset.state = connected ? "connected" : "error";
  elements.connectionLabel.textContent = label;
  elements.serverAddress.textContent = server || (connected ? "Koneksi tersedia" : "Koneksi belum tersedia");
}

function showNotice(message, type = "error") {
  elements.notice.textContent = message;
  elements.notice.dataset.type = type;
  elements.notice.hidden = false;
}

function clearNotice() {
  elements.notice.hidden = true;
  elements.notice.textContent = "";
}

function tableIdentity(table) {
  return `${table.schema_name}.${table.table_name}`;
}

async function initialize() {
  try {
    const result = await request("databases", { params: {} });
    if (result.databases.length === 0) throw new Error("Belum ada database yang dikonfigurasi pada backend.");

    const moduleResult = await request("modules", { params: {} });
    state.modules = moduleResult.modules;
    state.database = result.default_database || result.databases[0];
    elements.databaseName.textContent = state.database;
    elements.selectedTitle.textContent = state.modules[state.module].label;
    elements.breadcrumb.textContent = `${state.database} / PILIH TABEL`;
    await loadDatabase();
  } catch (error) {
    setConnection(false, "OFFLINE");
    showNotice(error.message);
  }
}

async function loadDatabase() {
  if (databaseLoading) return;
  databaseLoading = true;
  elements.gridFrame.dataset.loading = "true";
  clearNotice();
  state.selected = null;
  state.rows = [];
  state.columns = [];
  state.primaryKey = [];
  state.page = 1;
  state.totalPages = 1;
  renderTableList();
  renderRows();

  try {
    const status = await request("status");
    setConnection(true, "TERHUBUNG", status.connection.server_name);
    elements.footerDatabase.textContent = `${status.connection.database_name} / CRUD CONTROLLER`;
    const result = await request("tables");
    state.tables = result.tables;
    renderTableList();
    renderOverview();
    renderRows();
  } catch (error) {
    setConnection(false, "OFFLINE");
    showNotice(error.message);
    state.tables = [];
    renderTableList();
    renderOverview();
    renderRows();
  } finally {
    databaseLoading = false;
    delete elements.gridFrame.dataset.loading;
  }
}

function filteredTables() {
  const filter = elements.tableFilter.value.trim().toLocaleLowerCase();
  const allowedTables = state.modules[state.module]?.tables ?? null;
  return state.tables.filter((table) =>
    (allowedTables === null || allowedTables.includes(table.table_name))
      && tableIdentity(table).toLocaleLowerCase().includes(filter)
  );
}

function renderTableList() {
  const tables = filteredTables();
  elements.tableCount.textContent = String(tables.length);
  elements.tableList.replaceChildren();

  for (const table of tables) {
    const button = document.createElement("button");
    button.type = "button";
    button.className = "table-item";
    button.classList.toggle("active", state.selected !== null && tableIdentity(table) === tableIdentity(state.selected));
    button.addEventListener("click", () => selectTable(table));

    const name = document.createElement("span");
    name.className = "table-item-name";
    name.textContent = table.table_name;
    const meta = document.createElement("span");
    meta.className = "table-item-meta";
    meta.textContent = `${table.schema_name} · ${Number(table.row_count).toLocaleString("id-ID")}`;
    button.append(name, meta);
    elements.tableList.append(button);
  }

  if (tables.length === 0) {
    const empty = document.createElement("p");
    empty.className = "list-empty";
    empty.textContent = elements.tableFilter.value.trim() ? "Tabel tidak ditemukan." : "Belum ada tabel.";
    elements.tableList.append(empty);
  }
}

function renderOverview() {
  const tables = filteredTables();
  const module = state.modules[state.module];
  elements.overviewTitle.textContent = module?.label || "Semua data";
  elements.overviewCopy.textContent = tables.length
    ? `${tables.length} tabel tersedia di ${state.database}. Pilih satu untuk membuka datanya.`
    : "Tidak ada tabel yang cocok pada modul dan pencarian ini.";
  elements.tableOverview.replaceChildren();

  for (const table of tables) {
    const button = document.createElement("button");
    button.type = "button";
    button.className = "overview-row";
    button.addEventListener("click", () => selectTable(table));

    const icon = document.createElement("span");
    icon.className = "overview-icon";
    icon.textContent = "▤";
    icon.setAttribute("aria-hidden", "true");
    const name = document.createElement("span");
    name.className = "overview-name";
    const schema = document.createElement("span");
    schema.className = "overview-schema";
    schema.textContent = table.schema_name;
    const tableName = document.createElement("strong");
    tableName.textContent = table.table_name;
    name.append(schema, tableName);
    const count = document.createElement("span");
    count.className = "overview-count";
    count.textContent = `${Number(table.row_count).toLocaleString("id-ID")} baris`;
    const open = document.createElement("span");
    open.className = "overview-open";
    open.textContent = "Buka tabel";
    button.append(icon, name, count, open);
    elements.tableOverview.append(button);
  }
}

async function selectTable(table) {
  state.selected = table;
  state.page = 1;
  state.rows = [];
  state.columns = [];
  state.primaryKey = [];
  elements.selectedTitle.textContent = table.table_name;
  elements.breadcrumb.textContent = `${state.database} / ${table.schema_name}`;
  elements.addButton.disabled = false;
  renderTableList();
  renderRows();
  await loadRows();
}

async function loadRows() {
  if (!state.selected) return;
  clearNotice();
  elements.gridFrame.dataset.loading = "true";
  try {
    const result = await request("rows", {
      params: {
        schema: state.selected.schema_name,
        table: state.selected.table_name,
        page: state.page,
        page_size: pageSize,
      },
    });
    state.columns = result.columns;
    state.primaryKey = result.primary_key;
    state.rows = result.rows;
    state.totalPages = result.total_pages;
    renderRows(result.total_rows);
  } catch (error) {
    showNotice(error.message);
    state.rows = [];
    renderRows();
  } finally {
    delete elements.gridFrame.dataset.loading;
  }
}

function renderRows(totalRows = 0) {
  elements.gridHead.replaceChildren();
  elements.gridBody.replaceChildren();
  const hasTable = Boolean(state.selected);
  elements.emptyState.hidden = hasTable;
  elements.emptyRows.hidden = !hasTable || state.rows.length > 0;
  elements.pagination.hidden = !hasTable || state.totalPages <= 1;
  elements.addButton.disabled = !hasTable;

  if (!hasTable) {
    const tables = filteredTables();
    const totalRows = tables.reduce((sum, table) => sum + Number(table.row_count), 0);
    elements.rowSummary.textContent = `${tables.length} tabel · ${totalRows.toLocaleString("id-ID")} baris`;
    elements.rowKeyNote.textContent = `${state.modules[state.module]?.label || "Modul"} / pilih tabel untuk membuka data`;
    return;
  }

  elements.rowSummary.textContent = `${Number(totalRows).toLocaleString("id-ID")} baris · ${state.columns.length} kolom`;
  const hasWritableColumns = state.columns.some((column) => column.is_writable);
  elements.rowKeyNote.textContent = state.primaryKey.length
    ? `Primary key: ${state.primaryKey.join(", ")}`
    : hasWritableColumns
      ? "Tanpa primary key: data baris diperiksa agar tidak mengubah baris duplikat."
      : "Tabel ini tidak memiliki kolom yang dapat diubah.";
  elements.pageLabel.textContent = `Halaman ${state.page} dari ${state.totalPages}`;
  elements.previousPage.disabled = state.page <= 1;
  elements.nextPage.disabled = state.page >= state.totalPages;

  const headerRow = document.createElement("tr");
  for (const column of state.columns) {
    const heading = document.createElement("th");
    heading.textContent = column.column_name;
    heading.title = `${column.data_type}${column.is_primary_key ? " · primary key" : ""}`;
    headerRow.append(heading);
  }
  if (state.primaryKey.length || hasWritableColumns) {
    const actionHeading = document.createElement("th");
    actionHeading.className = "actions-heading";
    actionHeading.textContent = "AKSI";
    headerRow.append(actionHeading);
  }
  elements.gridHead.append(headerRow);

  for (const row of state.rows) {
    const rowElement = document.createElement("tr");
    for (const column of state.columns) {
      const cell = document.createElement("td");
      const value = row[column.column_name];
      cell.textContent = value === null ? "NULL" : typeof value === "object" ? JSON.stringify(value) : String(value ?? "");
      if (value === null) cell.className = "null-value";
      rowElement.append(cell);
    }
    if (state.primaryKey.length || hasWritableColumns) {
      rowElement.append(createRowActions(row));
    }
    elements.gridBody.append(rowElement);
  }

  if (state.rows.length === 0) {
    elements.emptyRows.hidden = false;
  }
}

function createRowActions(row) {
  const cell = document.createElement("td");
  cell.className = "row-actions";
  const edit = document.createElement("button");
  edit.type = "button";
  edit.className = "text-action";
  edit.textContent = "Edit";
  edit.addEventListener("click", () => openEditor("edit", row));
  const remove = document.createElement("button");
  remove.type = "button";
  remove.className = "text-action danger-action";
  remove.textContent = "Hapus";
  remove.addEventListener("click", () => removeRow(row));
  cell.append(edit, remove);
  return cell;
}

function editableColumns() {
  return state.columns.filter((column) => column.is_writable);
}

function openEditor(mode, row = null) {
  state.editing = row;
  elements.dialogTitle.textContent = mode === "create" ? "Tambah data" : "Edit data";
  elements.dialogEyebrow.textContent = `${state.selected.schema_name}.${state.selected.table_name}`;
  elements.dialogError.hidden = true;
  elements.fieldList.replaceChildren();

  const columns = editableColumns();
  if (columns.length === 0) {
    const note = document.createElement("p");
    note.className = "field-note";
    note.textContent = mode === "edit"
      ? "Tidak ada kolom yang dapat diubah; semua kolom dikelola otomatis oleh database."
      : "Semua kolom dibuat otomatis oleh database.";
    elements.fieldList.append(note);
  }

  for (const column of columns) elements.fieldList.append(createField(column, mode, row));
  elements.saveRecord.disabled = mode === "edit" && columns.length === 0;
  elements.dialog.showModal();
}

function createField(column, mode, row) {
  const wrapper = document.createElement("div");
  wrapper.className = "field-row";
  const label = document.createElement("label");
  label.className = "field-label";
  label.htmlFor = `field-${column.column_name}`;
  label.textContent = column.column_name;
  const type = document.createElement("span");
  type.className = "field-type";
  type.textContent = `${column.data_type}${column.is_nullable ? " · nullable" : ""}`;
  label.append(type);

  const inputWrap = document.createElement("div");
  inputWrap.className = "input-wrap";
  const input = document.createElement("input");
  input.id = `field-${column.column_name}`;
  input.name = column.column_name;
  input.type = column.data_type === "bit" ? "checkbox" : "text";
  input.autocomplete = "off";
  input.dataset.column = column.column_name;
  input.dataset.dataType = column.data_type;
  input.dataset.nullable = String(column.is_nullable);
  if (column.data_type === "bit") {
    input.checked = Boolean(row?.[column.column_name]);
    inputWrap.append(input);
  } else {
    const value = row?.[column.column_name];
    input.value = value === null || value === undefined ? "" : String(value);
    input.placeholder = column.is_nullable ? "Nilai; kosong = NULL" : `Isi ${column.column_name}`;
    inputWrap.append(input);
  }

  if (column.is_nullable) {
    const nullLabel = document.createElement("label");
    nullLabel.className = "null-toggle";
    const nullInput = document.createElement("input");
    nullInput.type = "checkbox";
    nullInput.className = "null-checkbox";
    nullInput.checked = mode === "edit" && row?.[column.column_name] === null;
    input.disabled = nullInput.checked;
    nullInput.addEventListener("change", () => { input.disabled = nullInput.checked; });
    nullLabel.append(nullInput, document.createTextNode("NULL"));
    inputWrap.append(nullLabel);
  }

  wrapper.append(label, inputWrap);
  return wrapper;
}

function formValues() {
  const values = {};
  for (const input of elements.fieldList.querySelectorAll("input[data-column]")) {
    const nullToggle = input.closest(".input-wrap")?.querySelector(".null-checkbox");
    const column = state.columns.find((item) => item.column_name === input.dataset.column);
    if (nullToggle?.checked) {
      values[input.dataset.column] = null;
    } else if (input.type === "checkbox") {
      values[input.dataset.column] = input.checked;
    } else if (input.value.trim() === "") {
      if (!column?.is_nullable) {
        throw new Error(`Kolom ${input.dataset.column} wajib diisi.`);
      }
      values[input.dataset.column] = null;
    } else {
      values[input.dataset.column] = input.value;
    }
  }
  return values;
}

function rowKey(row) {
  if (state.primaryKey.length) {
    return Object.fromEntries(state.primaryKey.map((column) => [column, row[column]]));
  }
  return { __row: row };
}

async function saveRecord(event) {
  event.preventDefault();
  const mode = state.editing ? "edit" : "create";
  elements.saveRecord.disabled = true;
  elements.dialogError.hidden = true;
  try {
    const body = {
      module: state.module,
      schema: state.selected.schema_name,
      table: state.selected.table_name,
      values: formValues(),
    };
    if (mode === "edit") body.key = rowKey(state.editing);

    await request(mode === "create" ? "create" : "update", {
      method: mode === "create" ? "POST" : "PUT",
      body,
    });
    elements.dialog.close();
    showNotice(mode === "create" ? "Data berhasil ditambahkan." : "Data berhasil diperbarui.", "success");
    await loadRows();
    await refreshTableCounts();
  } catch (error) {
    elements.dialogError.textContent = error.message;
    elements.dialogError.hidden = false;
  } finally {
    elements.saveRecord.disabled = false;
  }
}

async function removeRow(row) {
  const key = rowKey(row);
  const identifier = state.primaryKey.length ? JSON.stringify(key) : "data baris ini";
  if (!window.confirm(`Hapus ${identifier}?`)) return;

  try {
    await request("delete", {
      method: "DELETE",
      body: { module: state.module, schema: state.selected.schema_name, table: state.selected.table_name, key },
    });
    showNotice("Data berhasil dihapus.", "success");
    if (state.rows.length === 1 && state.page > 1) state.page -= 1;
    await loadRows();
    await refreshTableCounts();
  } catch (error) {
    showNotice(error.message);
  }
}

async function refreshTableCounts() {
  try {
    const result = await request("tables");
    state.tables = result.tables;
    renderTableList();
  } catch (error) {
    showNotice(error.message);
  }
}

elements.tableFilter.addEventListener("input", () => {
  renderTableList();
  if (!state.selected) renderOverview();
});
elements.refreshButton.addEventListener("click", () => state.selected ? loadRows() : loadDatabase());
elements.addButton.addEventListener("click", () => openEditor("create"));
elements.form.addEventListener("submit", saveRecord);
document.querySelector("#cancel-dialog").addEventListener("click", () => elements.dialog.close());
document.querySelector("#close-dialog").addEventListener("click", () => elements.dialog.close());
elements.previousPage.addEventListener("click", async () => { if (state.page > 1) { state.page -= 1; await loadRows(); } });
elements.nextPage.addEventListener("click", async () => { if (state.page < state.totalPages) { state.page += 1; await loadRows(); } });

initialize();
window.setInterval(() => {
  if (document.hidden || elements.dialog.open || databaseLoading || elements.gridFrame.dataset.loading) return;
  if (state.selected) loadRows();
  else loadDatabase();
}, 5000);
document.addEventListener("visibilitychange", () => {
  if (document.hidden || elements.dialog.open || databaseLoading || elements.gridFrame.dataset.loading) return;
  if (state.selected) loadRows();
  else loadDatabase();
});