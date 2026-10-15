const databaseApiUrl = "../../../backend/_be_db_manager/api.php";
const activationApiUrl = "../../../backend/_be_way_point_interlock/api.php";
const moduleName = "waypoint_interlock";
const pageSize = 100;
const state = {
  database: "WH2",
  columns: [],
  primaryKey: [],
  rows: [],
  page: 1,
  totalPages: 1,
  totalRows: 0,
  busyIds: new Set(),
};
let loadSequence = 0;
let filterTimer;

const elements = {
  connectionState: document.querySelector("#connection-state"),
  connectionLabel: document.querySelector("#connection-label"),
  checkpointFilter: document.querySelector("#checkpoint-filter"),
  strictFilter: document.querySelector("#strict-filter"),
  filterHint: document.querySelector("#filter-hint"),
  refreshButton: document.querySelector("#refresh-button"),
  notice: document.querySelector("#notice"),
  rowSummary: document.querySelector("#row-summary"),
  pagination: document.querySelector("#pagination"),
  pageLabel: document.querySelector("#page-label"),
  previousPage: document.querySelector("#previous-page"),
  nextPage: document.querySelector("#next-page"),
  gridFrame: document.querySelector("#grid-frame"),
  gridHead: document.querySelector("#grid-head"),
  gridBody: document.querySelector("#grid-body"),
  emptyState: document.querySelector("#empty-state"),
  emptyTitle: document.querySelector("#empty-title"),
  emptyCopy: document.querySelector("#empty-copy"),
};

async function request(endpoint, action, { method = "GET", params = {}, body } = {}) {
  const url = new URL(endpoint, window.location.href);
  url.searchParams.set("action", action);
  if (endpoint === databaseApiUrl) {
    url.searchParams.set("database", state.database);
    if (["rows", "create", "update", "delete"].includes(action)) {
      url.searchParams.set("module", moduleName);
    }
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
  if (!response.ok || !result.ok) throw new Error(result.error || `Request failed (${response.status}).`);
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

async function loadRows({ synchronizeInactive = true } = {}) {
  const sequence = ++loadSequence;
  elements.gridFrame.dataset.loading = "true";
  elements.refreshButton.disabled = true;
  clearNotice();
  try {
    const status = await request(databaseApiUrl, "status");
    if (sequence !== loadSequence) return;
    state.database = status.connection.database_name || state.database;
    const synchronizationErrors = synchronizeInactive
      ? await synchronizeInactiveRules(sequence)
      : [];
    if (sequence !== loadSequence) return;
    const result = await request(databaseApiUrl, "rows", {
      params: {
        schema: "dbo",
        table: "tb_08_area_strict",
        page: state.page,
        page_size: pageSize,
        filters: JSON.stringify(activeFilters()),
      },
    });
    if (sequence !== loadSequence) return;
    state.columns = result.columns.filter((column) =>
      ["id", "checkpoint_area", "checkpoint_agv", "strict_area", "strict_agv", "status", "activate"].includes(column.column_name)
    );
    state.primaryKey = result.primary_key;
    state.rows = result.rows;
    state.page = result.page;
    state.totalPages = result.total_pages;
    state.totalRows = result.total_rows;
    setConnection(true, "CONNECTED");
    renderRows();
    if (synchronizationErrors.length > 0) {
      showNotice(`Could not synchronize inactive rule status: ${synchronizationErrors.join(" ")}`);
    }
  } catch (error) {
    if (sequence !== loadSequence) return;
    setConnection(false, "OFFLINE");
    showNotice(error.message);
    state.columns = [];
    state.primaryKey = [];
    state.rows = [];
    state.totalRows = 0;
    state.totalPages = 1;
    renderRows();
  } finally {
    if (sequence === loadSequence) {
      elements.refreshButton.disabled = false;
      delete elements.gridFrame.dataset.loading;
    }
  }
}

async function synchronizeInactiveRules(sequence) {
  const filters = [
    { column: "activate", operator: "equals", value: "0" },
    { column: "status", operator: "equals", value: "RUN" },
  ];
  const firstPage = await request(databaseApiUrl, "rows", {
    params: {
      schema: "dbo",
      table: "tb_08_area_strict",
      page: 1,
      page_size: pageSize,
      filters: JSON.stringify(filters),
    },
  });
  const errors = [];
  for (let page = 1; page <= firstPage.total_pages; page += 1) {
    if (sequence !== loadSequence) return errors;
    const result = page === 1 ? firstPage : await request(databaseApiUrl, "rows", {
      params: {
        schema: "dbo",
        table: "tb_08_area_strict",
        page,
        page_size: pageSize,
        filters: JSON.stringify(filters),
      },
    });
    for (const row of result.rows) {
      if (sequence !== loadSequence) return errors;
      try {
        await request(activationApiUrl, "set-activation", {
          method: "POST",
          body: { id: Number(row.id), activate: false },
        });
      } catch (error) {
        errors.push(`Rule ${row.id}: ${error.message}`);
      }
    }
  }
  return errors;
}

function renderRows() {
  elements.gridHead.replaceChildren();
  elements.gridBody.replaceChildren();
  const rows = state.rows;
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
    ? "Add rules from the Waypoint Interlock page."
    : "Try a different area search.";
  if (state.columns.length === 0) return;

  const header = document.createElement("tr");
  for (const column of state.columns) {
    const th = document.createElement("th");
    th.textContent = column.column_name;
    th.title = `${column.data_type}${column.is_primary_key ? " · primary key" : ""}`;
    if (column.column_name === "activate") th.textContent = "ACTIVATE";
    header.append(th);
  }
  elements.gridHead.append(header);

  for (const row of rows) {
    const tr = document.createElement("tr");
    for (const column of state.columns) {
      const td = document.createElement("td");
      td.dataset.column = column.column_name;
      const value = row[column.column_name];
      if (column.column_name === "activate") {
        td.append(createActivationControl(row));
      } else if (value === null) {
        td.className = "null-value";
        td.textContent = "NULL";
      } else if (column.column_name === "status") {
        appendStatus(td, column.column_name, value, row);
      } else {
        td.textContent = typeof value === "object" ? JSON.stringify(value) : String(value ?? "");
      }
      tr.append(td);
    }
    elements.gridBody.append(tr);
  }
}

function activeFilters() {
  const checkpoint = elements.checkpointFilter.value.trim();
  const strict = elements.strictFilter.value.trim();
  if (checkpoint) return [{ column: "checkpoint_area", operator: "contains", value: checkpoint }];
  if (strict) return [{ column: "strict_area", operator: "contains", value: strict }];
  return [];
}

function appendStatus(cell, column, value, row) {
  const label = column === "status"
    && Number(row.activate) !== 1
    && String(value).toLocaleUpperCase() === "RUN"
    ? "OFF"
    : String(value).toLocaleUpperCase();
  const pill = document.createElement("span");
  pill.className = "status-pill";
  if (column === "activate" && Number(value) !== 1) pill.classList.add("inactive");
  if (column === "status" && label === "STOP") pill.classList.add("stopped");
  if (column === "status" && label === "OFF") pill.classList.add("off");
  if (column === "status" && ["RUN", "ON"].includes(label)) pill.classList.add("running");
  pill.textContent = label || "—";
  cell.append(pill);
}

function createActivationControl(row) {
  const control = document.createElement("div");
  control.className = "activation-control";
  const active = Number(row.activate) === 1;
  const button = document.createElement("button");
  button.type = "button";
  button.className = "activation-switch";
  button.setAttribute("role", "switch");
  button.setAttribute("aria-checked", String(active));
  button.setAttribute("aria-label", `${active ? "Turn off" : "Turn on"} interlock rule ${row.id}`);
  button.disabled = state.busyIds.has(String(row.id));
  button.addEventListener("click", () => toggleActivation(row, !active, button));
  const label = document.createElement("span");
  label.className = "activation-label";
  label.dataset.active = String(active);
  label.textContent = active ? "ON" : "OFF";
  control.append(button, label);
  return control;
}

async function toggleActivation(row, activate, button) {
  const message = activate
    ? `Turn on interlock for ${row.checkpoint_area} → ${row.strict_area}?`
    : `Turn off interlock for ${row.checkpoint_area} → ${row.strict_area}? Robots will pass without interlock priority, and any paused strict-area robot will be resumed.`;
  if (!window.confirm(message)) return;

  const id = String(row.id);
  state.busyIds.add(id);
  button.disabled = true;
  clearNotice();
  try {
    await request(activationApiUrl, "set-activation", {
      method: "POST",
      body: { id: Number(row.id), activate },
    });
    showNotice(`Interlock ${activate ? "enabled" : "disabled"} for ${row.checkpoint_area} → ${row.strict_area}.`, "success");
    await loadRows();
  } catch (error) {
    showNotice(error.message);
    button.disabled = false;
  } finally {
    state.busyIds.delete(id);
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
elements.refreshButton.addEventListener("click", loadRows);
elements.previousPage.addEventListener("click", () => {
  if (state.page > 1) {
    state.page -= 1;
    loadRows({ synchronizeInactive: false });
  }
});
elements.nextPage.addEventListener("click", () => {
  if (state.page < state.totalPages) {
    state.page += 1;
    loadRows({ synchronizeInactive: false });
  }
});
loadRows();
window.setInterval(() => {
  if (!document.hidden && state.busyIds.size === 0 && !elements.gridFrame.dataset.loading) {
    loadRows({ synchronizeInactive: false });
  }
}, 5000);
document.addEventListener("visibilitychange", () => {
  if (!document.hidden && state.busyIds.size === 0 && !elements.gridFrame.dataset.loading) {
    loadRows({ synchronizeInactive: false });
  }
});
