const apiUrl = "../../backend/_be_db_manager/api.php";
const moduleName = "waypoint_interlock";
const table = { schema: "dbo", name: "tb_08_area_strict" };
const pageSize = 100;
const state = {
  database: "WH2",
  columns: [],
  primaryKey: [],
  rows: [],
  page: 1,
  totalPages: 1,
  totalRows: 0,
  editing: null,
};
let loadSequence = 0;
let filterTimer;
let evaluationRunning = false;

const elements = {
  connectionState: document.querySelector("#connection-state"),
  connectionLabel: document.querySelector("#connection-label"),
  checkpointFilter: document.querySelector("#checkpoint-filter"),
  strictFilter: document.querySelector("#strict-filter"),
  filterHint: document.querySelector("#filter-hint"),
  refreshButton: document.querySelector("#refresh-button"),
  addButton: document.querySelector("#add-button"),
  notice: document.querySelector("#notice"),
  rowSummary: document.querySelector("#row-summary"),
  pagination: document.querySelector("#pagination"),
  pageLabel: document.querySelector("#page-label"),
  previousPage: document.querySelector("#previous-page"),
  nextPage: document.querySelector("#next-page"),
  gridFrame: document.querySelector("#grid-frame"),
  gridHead: document.querySelector("#grid-head"),
  gridBody: document.querySelector("#grid-body"),
  gridFoot: document.querySelector("#grid-foot"),
  emptyState: document.querySelector("#empty-state"),
  emptyTitle: document.querySelector("#empty-title"),
  emptyCopy: document.querySelector("#empty-copy"),
  dialog: document.querySelector("#record-dialog"),
  form: document.querySelector("#record-form"),
  dialogTitle: document.querySelector("#dialog-title"),
  fieldList: document.querySelector("#field-list"),
  dialogError: document.querySelector("#dialog-error"),
  saveRecord: document.querySelector("#save-record"),
};

async function request(action, { method = "GET", params = {}, body, endpoint = apiUrl } = {}) {
  const url = new URL(endpoint, window.location.href);
  url.searchParams.set("action", action);
  url.searchParams.set("database", state.database);
  if (["rows", "create", "update", "delete"].includes(action)) {
    url.searchParams.set("module", moduleName);
  }
  for (const [key, value] of Object.entries(params)) url.searchParams.set(key, String(value));

  const response = await fetch(url, {
    method,
    headers: { Accept: "application/json", ...(body ? { "Content-Type": "application/json" } : {}) },
    body: body ? JSON.stringify(body) : undefined,
    cache: "no-store",
  });
  const responseText = await response.text();
  let result;
  try {
    result = JSON.parse(responseText);
  } catch {
    if (responseText.trimStart().startsWith("<?php")) {
      throw new Error("The backend returned PHP source instead of JSON. Serve this page through PHP from the project root.");
    }
    throw new Error(`The backend response is not JSON (HTTP ${response.status}). Make sure the PHP server is running.`);
  }
  if (!response.ok || !result.ok) {
    const ruleErrors = Array.isArray(result.errors)
      ? result.errors.map((item) => `Rule ${item.id}: ${item.error}`).join(" ")
      : "";
    throw new Error(result.error || ruleErrors || `Request failed (${response.status}).`);
  }
  return result;
}

function setConnection(connected, label) {
  elements.connectionState.dataset.state = connected ? "connected" : "error";
  elements.connectionLabel.textContent = label;
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

async function evaluateRules() {
  if (
    evaluationRunning
    || document.hidden
    || elements.dialog.open
    || elements.gridFrame.dataset.loading
  ) return;

  evaluationRunning = true;
  let evaluationError = "";
  try {
    await request("evaluate", {
      method: "POST",
      endpoint: "../../backend/_be_way_point_interlock/api.php",
      body: {},
    });
  } catch (error) {
    evaluationError = `Waypoint evaluation failed: ${error.message}`;
  } finally {
    evaluationRunning = false;
  }

  await loadRows();
  if (evaluationError) showNotice(evaluationError);
}

async function loadRows() {
  const sequence = ++loadSequence;
  elements.gridFrame.dataset.loading = "true";
  elements.addButton.disabled = true;
  clearNotice();
  try {
    const status = await request("status");
    if (sequence !== loadSequence) return;
    state.database = status.connection.database_name || state.database;
    const result = await request("rows", {
      params: {
        schema: table.schema,
        table: table.name,
        page: state.page,
        page_size: pageSize,
        filters: JSON.stringify(activeFilters()),
      },
    });
    if (sequence !== loadSequence) return;
    state.columns = result.columns;
    state.primaryKey = result.primary_key;
    state.rows = result.rows;
    state.page = result.page;
    state.totalPages = result.total_pages;
    state.totalRows = result.total_rows;
    setConnection(true, "CONNECTED");
    elements.addButton.disabled = false;
    renderRows();
  } catch (error) {
    if (sequence !== loadSequence) return;
    setConnection(false, "OFFLINE");
    showNotice(error.message);
    state.columns = [];
    state.rows = [];
    state.totalPages = 1;
    state.totalRows = 0;
    renderRows();
  } finally {
    if (sequence === loadSequence) {
      elements.addButton.disabled = false;
      delete elements.gridFrame.dataset.loading;
    }
  }
}

function activeFilters() {
  const checkpoint = elements.checkpointFilter.value.trim();
  const strict = elements.strictFilter.value.trim();
  if (checkpoint) return [{ column: "checkpoint_area", operator: "contains", value: checkpoint }];
  if (strict) return [{ column: "strict_area", operator: "contains", value: strict }];
  return [];
}

function displayedColumns() {
  return state.columns.filter((column) => column.column_name !== "activate");
}

function renderRows() {
  elements.gridHead.replaceChildren();
  elements.gridBody.replaceChildren();
  const rows = state.rows;
  const columns = displayedColumns();
  const columnCount = Math.max(columns.length + 1, 1);
  elements.gridFoot.querySelector("td").colSpan = columnCount;
  elements.rowSummary.textContent = `${state.totalRows.toLocaleString("en-US")} rules`;
  elements.pagination.hidden = state.totalPages <= 1;
  elements.pageLabel.textContent = `Page ${state.page} of ${state.totalPages}`;
  elements.previousPage.disabled = state.page <= 1;
  elements.nextPage.disabled = state.page >= state.totalPages;
  elements.emptyState.hidden = rows.length > 0;
  elements.emptyTitle.textContent = state.totalRows === 0 && activeFilters().length === 0
    ? "No interlock rules yet"
    : "No matching rules";
  elements.emptyCopy.textContent = state.totalRows === 0 && activeFilters().length === 0
    ? "Add a rule to configure area priority."
    : "Try a different area search.";

  if (state.columns.length === 0) return;
  const header = document.createElement("tr");
  for (const column of columns) {
    const th = document.createElement("th");
    th.textContent = column.column_name;
    th.title = `${column.data_type}${column.is_primary_key ? " · primary key" : ""}`;
    header.append(th);
  }
  const actionsHeader = document.createElement("th");
  actionsHeader.className = "actions-heading";
  actionsHeader.textContent = "ACTIONS";
  header.append(actionsHeader);
  elements.gridHead.append(header);

  for (const row of rows) {
    const tr = document.createElement("tr");
    for (const column of columns) {
      const td = document.createElement("td");
      const value = row[column.column_name];
      td.dataset.column = column.column_name;
      if (value === null) {
        td.className = "null-value";
        td.textContent = "NULL";
      } else if (column.column_name === "status" || column.column_name === "activate") {
        appendStatus(td, column.column_name, value);
      } else {
        td.textContent = typeof value === "object" ? JSON.stringify(value) : String(value ?? "");
      }
      tr.append(td);
    }
    tr.append(createRowActions(row));
    elements.gridBody.append(tr);
  }
}

function appendStatus(cell, column, value) {
  const label = column === "activate"
    ? (Boolean(Number(value)) ? "ACTIVE" : "INACTIVE")
    : String(value).toLocaleUpperCase();
  const pill = document.createElement("span");
  pill.className = "status-pill";
  if (column === "activate" && !Boolean(Number(value))) pill.classList.add("inactive");
  if (column === "status" && label === "STOP") pill.classList.add("stopped");
  if (column === "status" && label === "OFF") pill.classList.add("off");
  if (column === "status" && ["RUN", "ON"].includes(label)) pill.classList.add("running");
  pill.textContent = label || "—";
  cell.append(pill);
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
  remove.textContent = "Delete";
  remove.addEventListener("click", () => removeRow(row));
  cell.append(edit, remove);
  return cell;
}

function openEditor(mode, row = null) {
  state.editing = row;
  elements.dialogTitle.textContent = mode === "create" ? "Add rule" : "Edit rule";
  elements.dialogError.hidden = true;
  elements.fieldList.replaceChildren();
  const areaColumns = state.columns.filter((column) =>
    column.is_writable && ["checkpoint_area", "strict_area"].includes(column.column_name)
  );
  if (areaColumns.length !== 2) {
    const note = document.createElement("p");
    note.className = "field-note";
    note.textContent = "The checkpoint_area and strict_area fields are unavailable.";
    elements.fieldList.append(note);
  }
  for (const column of areaColumns) elements.fieldList.append(createField(column, mode, row));
  elements.saveRecord.disabled = areaColumns.length !== 2;
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
  type.textContent = column.data_type;
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
  if (input.type === "checkbox") {
    input.checked = Boolean(Number(row?.[column.column_name] ?? 0));
  } else {
    const value = row?.[column.column_name];
    input.value = value === null || value === undefined ? "" : String(value);
    input.required = ["checkpoint_area", "strict_area"].includes(column.column_name);
    input.placeholder = `Enter ${column.column_name === "checkpoint_area" ? "checkpoint area" : "strict area"}`;
  }
  inputWrap.append(input);

  if (column.is_nullable && input.type !== "checkbox"
    && !["checkpoint_area", "strict_area"].includes(column.column_name)) {
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
    if (!["checkpoint_area", "strict_area"].includes(input.dataset.column)) {
      throw new Error("The form only accepts checkpoint_area and strict_area.");
    }
    const column = state.columns.find((item) => item.column_name === input.dataset.column);
    const value = input.value.trim();
    if (value === "") throw new Error(`${input.dataset.column} is required.`);
    if (!column?.is_writable) throw new Error(`${input.dataset.column} cannot be changed.`);
    values[input.dataset.column] = value;
  }
  if (!Object.hasOwn(values, "checkpoint_area") || !Object.hasOwn(values, "strict_area")) {
    throw new Error("Both checkpoint area and strict area are required.");
  }
  return values;
}

function rowKey(row) {
  return state.primaryKey.length
    ? Object.fromEntries(state.primaryKey.map((column) => [column, row[column]]))
    : { __row: row };
}

async function saveRecord(event) {
  event.preventDefault();
  elements.saveRecord.disabled = true;
  elements.dialogError.hidden = true;
  try {
    const mode = state.editing ? "edit" : "create";
    const body = {
      module: moduleName,
      schema: table.schema,
      table: table.name,
      values: formValues(),
    };
    if (mode === "edit") body.key = rowKey(state.editing);
    if (mode === "create") {
      await request("create-rule", {
        method: "POST",
        endpoint: "../../backend/_be_way_point_interlock/api.php",
        body: {
          checkpoint_area: body.values.checkpoint_area,
          strict_area: body.values.strict_area,
        },
      });
    } else {
      await request("update", {
        method: "PUT",
        body,
      });
    }
    elements.dialog.close();
    showNotice(mode === "create" ? "Rule added successfully." : "Rule updated successfully.", "success");
    await loadRows();
  } catch (error) {
    elements.dialogError.textContent = error.message;
    elements.dialogError.hidden = false;
  } finally {
    elements.saveRecord.disabled = false;
  }
}

async function removeRow(row) {
  const key = rowKey(row);
  const identifier = state.primaryKey.length ? JSON.stringify(key) : "this rule";
  if (!window.confirm(`Delete ${identifier}?`)) return;
  try {
    await request("delete", {
      method: "DELETE",
      body: { module: moduleName, schema: table.schema, table: table.name, key },
    });
    showNotice("Rule deleted successfully.", "success");
    await loadRows();
  } catch (error) {
    showNotice(error.message);
  }
}

function updateFilters(activeInput) {
  const otherInput = activeInput === elements.checkpointFilter ? elements.strictFilter : elements.checkpointFilter;
  const hasActiveFilter = activeInput.value.trim() !== "";
  otherInput.disabled = hasActiveFilter;
  elements.filterHint.textContent = hasActiveFilter
    ? `${activeInput === elements.checkpointFilter ? "Checkpoint" : "Strict"} filter is active. Clear it to search the other area.`
    : "Use one area search at a time.";
  state.page = 1;
  window.clearTimeout(filterTimer);
  filterTimer = window.setTimeout(loadRows, 250);
}

elements.checkpointFilter.addEventListener("input", () => updateFilters(elements.checkpointFilter));
elements.strictFilter.addEventListener("input", () => updateFilters(elements.strictFilter));
elements.refreshButton.addEventListener("click", evaluateRules);
elements.addButton.addEventListener("click", () => openEditor("create"));
elements.form.addEventListener("submit", saveRecord);
document.querySelector("#cancel-dialog").addEventListener("click", () => elements.dialog.close());
document.querySelector("#close-dialog").addEventListener("click", () => elements.dialog.close());
elements.previousPage.addEventListener("click", () => {
  if (state.page > 1) {
    state.page -= 1;
    loadRows();
  }
});
elements.nextPage.addEventListener("click", () => {
  if (state.page < state.totalPages) {
    state.page += 1;
    loadRows();
  }
});

evaluateRules();
window.setInterval(() => {
  evaluateRules();
}, 5000);
document.addEventListener("visibilitychange", () => {
  if (!document.hidden) evaluateRules();
});
