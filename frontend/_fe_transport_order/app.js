const state = {
  currentPage: "page-home",
  selectedArea: "",
  requestArea: "",
  topupRequests: [],
  topupDeliveries: [],
  trolleyReturns: [],
  productionTrolleyTasks: [],
  shippingTrolleyTasks: [],
  productionDeliveries: [],
  requestAreas: [],
  productionAreas: [],
  productionPallets: [],
  shippingPallets: [],
  history: [],
  notice: "",
  noticeIsError: false,
};
let dashboardLoading = false;

const pageTitles = new Set([
  "page-home",
  "page-topup",
  "page-request",
  "page-prod-ship-area",
  "page-prod-ship-detail",
  "page-ship-topup",
  "page-history",
]);

function escapeHtml(value) {
  return String(value ?? "").replace(/[&<>"']/g, (character) => ({
    "&": "&amp;",
    "<": "&lt;",
    ">": "&gt;",
    '"': "&quot;",
    "'": "&#39;",
  })[character]);
}

function formatTimestamp(value) {
  if (!value) return "—";
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return String(value);
  return new Intl.DateTimeFormat("en-US", {
    month: "2-digit",
    day: "2-digit",
    year: "numeric",
    hour: "2-digit",
    minute: "2-digit",
    hour12: true,
  }).format(date);
}

function setTopupNotification(message) {
  const notification = document.querySelector("#topup-notification-bar");
  notification.querySelectorAll(".ticker-copy").forEach((copy) => {
    copy.textContent = message;
  });
  notification.title = message;
}

function announce(message, isError = false) {
  state.notice = message;
  state.noticeIsError = isError;
  renderNotification();
}

async function apiRequest(action, method = "GET", body = null, params = {}) {
  const url = new URL("../../backend/_be_transport_order/api.php", window.location.href);
  url.searchParams.set("action", action);
  for (const [key, value] of Object.entries(params)) {
    url.searchParams.set(key, String(value));
  }

  const response = await fetch(url, {
    method,
    headers: body ? { "Content-Type": "application/json" } : {},
    body: body ? JSON.stringify(body) : undefined,
  });
  let payload;
  try {
    payload = await response.json();
  } catch {
    throw new Error(`Transport Order returned an invalid response (${response.status}).`);
  }
  if (!response.ok || payload.ok !== true) {
    throw new Error(payload.error || `Transport Order request failed (${response.status}).`);
  }
  return payload;
}

function showPage(pageId) {
  if (!pageTitles.has(pageId)) return;
  state.currentPage = pageId;
  state.notice = "";
  state.noticeIsError = false;
  document.querySelectorAll(".page").forEach((page) => {
    page.classList.toggle("active", page.id === pageId);
  });
  renderNotification();
  if (pageId === "page-history") loadHistory().catch((error) => announce(error.message, true));
  window.scrollTo({ top: 0, behavior: "smooth" });
}

async function loadHistory() {
  const firstPage = await apiRequest("history", "GET", null, { page: 1 });
  const history = firstPage.history;
  const entries = [...history.entries];
  for (let page = 2; page <= history.total_pages; page += 1) {
    const nextPage = await apiRequest("history", "GET", null, { page });
    entries.push(...nextPage.history.entries);
  }
  state.history = entries;
  renderHistory();
}

async function loadDashboard() {
  if (dashboardLoading) return;
  dashboardLoading = true;
  try {
    const { dashboard } = await apiRequest("dashboard");
    state.topupRequests = dashboard.topup_requests;
    state.topupDeliveries = dashboard.topup_deliveries;
    state.trolleyReturns = dashboard.trolley_returns || [];
    state.productionTrolleyTasks = dashboard.production_trolley_tasks || [];
    state.shippingTrolleyTasks = dashboard.shipping_trolley_tasks || [];
    state.productionDeliveries = dashboard.production_deliveries;
    state.requestAreas = dashboard.request_areas;
    state.productionAreas = dashboard.production_areas;
    state.productionPallets = dashboard.production_pallets;
    state.shippingPallets = dashboard.shipping_pallets;
    if (!state.productionAreas.some((area) => area.name === state.selectedArea)) {
      state.selectedArea = state.productionAreas[0]?.name || "";
    }
    renderDashboard();
  } finally {
    dashboardLoading = false;
  }
}

function renderTopup() {
  renderNotification();

  const stationCards = state.requestAreas.map((area) => {
    const request = state.topupRequests.find((item) => item.area === area.name);
    const delivery = state.topupDeliveries.find((item) => item.area === area.name);
    return `
      <button class="area-card ${request ? "area-busy" : ""}" type="button"
        data-action="send-topup-pallet" data-task-id="${escapeHtml(request?.task_id || "")}"
        ${request ? "" : "disabled"}>
        <h3>${escapeHtml(displayStationAreaName(area.name))}</h3>
        <p>${request
          ? `Load the requested ${escapeHtml(request.type)} on the trolley, then send it.`
          : delivery
            ? `${escapeHtml(delivery.type)} is on the way.`
            : "Waiting for request."}</p>
        ${request ? '<span class="queue-status">SEND</span>' : ""}
      </button>`;
  }).join("");

  document.querySelector("#list-topup").innerHTML =
    stationCards || "<p>No TopUp stations are configured.</p>";
}

function renderNotification() {
  const notification = document.querySelector("#topup-notification-bar");
  let message = state.notice;
  let isError = state.noticeIsError;
  if (!message && state.currentPage === "page-topup") {
    message = [
      ...state.topupRequests.map((request) =>
        `Please top up ${request.type} to ${displayStationAreaName(request.area)}.`
      ),
      ...state.topupDeliveries.map((delivery) =>
        `${delivery.type} is on the way to ${displayStationAreaName(delivery.area)}.`
      ),
      ...state.trolleyReturns.map(() =>
        `Empty trolley is returning from Shipping to TopUp.`
      ),
    ].join(" ");
    isError = false;
  }

  if (message) {
    setTopupNotification(message);
    notification.classList.toggle("notification-error", isError);
    notification.style.display = "block";
  } else {
    notification.classList.remove("notification-error");
    notification.style.display = "none";
  }
}

function displayStationAreaName(area) {
  if (area === "Station 01" || area === "Area 1") return "Area 1";
  if (area === "Station 02" || area === "Area 2") return "Area 2";
  return area;
}

function renderRequestAreas() {
  document.querySelector("#list-request-area").innerHTML = state.requestAreas.map((area) => `
    <button class="area-card ${area.can_request ? "area-ready" : "area-busy"}"
      type="button" data-action="select-request-area" data-area="${escapeHtml(area.name)}"
      ${area.can_request ? "" : "disabled"}>
      <h3>${escapeHtml(area.name)}</h3>
      <p>${escapeHtml(area.availability)}</p>
    </button>`).join("") || "<p>No production areas are configured.</p>";
}

function renderProductionAreas() {
  document.querySelector("#list-prod-ship-area").innerHTML = state.productionAreas.map((area) => `
    <button class="area-card ${area.status === "READY" ? "area-ready" : ["WAITING_TOPUP", "IN_TRANSIT"].includes(area.status) ? "area-busy" : ""}"
      type="button" data-action="open-area" data-area="${escapeHtml(area.name)}">
      <h3>${escapeHtml(area.name)}</h3>
      <p>${area.pallets
        ? `${area.pallets} pallet${area.pallets === 1 ? "" : "s"} ready`
        : area.status === "WAITING_TOPUP"
          ? "Waiting for TopUp"
          : area.status === "IN_TRANSIT"
            ? "Pallet in transit"
            : "No pallet at Production"}</p>
    </button>`).join("") || "<p>No production areas are configured.</p>";
}

function renderProductionDetail() {
  document.querySelector("#selected-area-name").textContent = state.selectedArea || "Production Area";
  const pallets = state.productionPallets.filter((pallet) => pallet.area === state.selectedArea);
  const trolleyTasks = state.productionTrolleyTasks.filter((task) => task.area === state.selectedArea);
  const topupDelivery = state.topupDeliveries.find((item) => item.area === state.selectedArea);
  const delivery = state.productionDeliveries.find((item) => item.area === state.selectedArea);
  const topupDeliveryCard = topupDelivery ? `
    <article class="pallet-card">
      <div class="card-top"><span class="pallet-id">${escapeHtml(topupDelivery.pallet_code)}</span><span class="queue-status">In Transit</span></div>
      <h3>${escapeHtml(topupDelivery.type)}</h3>
      <p>The AGV is delivering this pallet from TopUp to Production.</p>
    </article>` : "";
  const palletCards = trolleyTasks.map((task) => {
    const pallet = pallets.find((item) => item.code === task.pallet_code);
    if (!pallet) return "";
    return `
    <article class="pallet-card">
      <div class="card-top"><span class="pallet-id">${escapeHtml(pallet.code)}</span><span class="queue-status">READY</span></div>
      <h3>${escapeHtml(pallet.type)}</h3>
      <p>Pallet arrived at Production and is ready. Send the loaded trolley and pallet to Shipping when Production is finished.</p>
      <button class="btn-primary" type="button" data-action="send-to-shipping" data-code="${escapeHtml(pallet.code)}">Send to Shipping</button>
    </article>`;
  }).join("");
  const deliveryCard = delivery ? `
    <article class="pallet-card">
      <div class="card-top"><span class="pallet-id">${escapeHtml(delivery.pallet_code)}</span><span class="queue-status">${escapeHtml(delivery.status)}</span></div>
      <h3>Shipping arrival</h3>
      <p>AGV delivery in progress.</p>
    </article>` : "";
  document.querySelector("#list-prod-ship-detail").innerHTML =
      topupDeliveryCard + palletCards + deliveryCard || "<p>No pallets are currently waiting in this area.</p>";
}

function renderShipping() {
  const cards = state.shippingTrolleyTasks.map((trolley) => `
    <article class="pallet-card">
      <div class="card-top"><span class="pallet-id">${escapeHtml(trolley.pallet_code || "Empty trolley")}</span><span class="queue-status">Trolley at Shipping</span></div>
      <h3>${escapeHtml(trolley.pallet_type)}</h3>
      <p>After unloading the pallet, send the empty trolley back to TopUp.</p>
      <button class="btn-primary" type="button" data-action="send-trolley-to-topup"
        data-task-id="${escapeHtml(trolley.task_id)}">Send Empty Trolley to TopUp</button>
    </article>`).join("");
  document.querySelector("#list-ship-topup").innerHTML =
    cards || "<p>No empty trolleys are currently waiting in Shipping.</p>";
}

function renderHistory() {
  document.querySelector("#history-table tbody").innerHTML = state.history.length
    ? state.history.map((entry) => `
      <tr>
        <td>${escapeHtml(entry.id)}</td>
        <td>${escapeHtml(entry.category)}</td>
        <td>${escapeHtml(entry.area)}</td>
        <td>${escapeHtml(entry.type)}</td>
        <td>${escapeHtml(entry.from)}</td>
        <td>${escapeHtml(entry.to)}</td>
        <td>${escapeHtml(entry.status)}</td>
        <td>${escapeHtml(formatTimestamp(entry.time))}</td>
        <td>—</td>
      </tr>`).join("")
    : '<tr><td colspan="9">No history is available.</td></tr>';
}

function renderDashboard() {
  renderTopup();
  renderRequestAreas();
  renderProductionAreas();
  renderProductionDetail();
  renderShipping();
}

function openModal(id) {
  document.querySelector(`#${id}`).classList.add("show");
}

function closeModal(id) {
  document.querySelector(`#${id}`).classList.remove("show");
}

function goHome() {
  showPage("page-home");
}

function toggleInfo() {
  const modal = document.querySelector("#info-modal");
  modal.classList.toggle("show");
}

function closeRequestModal() {
  closeModal("request-modal");
}

function confirmRequestAGV() {
  const button = document.querySelector("#request-modal .modal-action");
  withButtonDisabled(button, async () => {
    const palletType = document.querySelector("#req-pallet-type").value;
    const { task } = await apiRequest("create-task", "POST", {
      task_type: "PALLET_REQUEST",
      area: state.requestArea,
      pallet_type: palletType,
    });
    closeRequestModal();
    await loadDashboard();
    announce(`Pallet request ${task.task_id} submitted for ${displayStationAreaName(state.requestArea)}.`);
  });
}

function openPalletRequestModal(area) {
  state.requestArea = area;
  document.querySelector("#req-modal-title").textContent = "Request Pallet";
  document.querySelector("#req-modal-area").textContent = displayStationAreaName(area);
  document.querySelector("#request-modal .modal-action").textContent = "SEND REQUEST";
  document.querySelector("#request-modal .modal-action").disabled = false;
  document.querySelector("#req-pallet-availability").textContent = "The request will be sent to the TopUp page.";
  openModal("request-modal");
}

async function withButtonDisabled(button, action) {
  button.disabled = true;
  try {
    await action();
  } catch (error) {
    announce(error.message, true);
  } finally {
    button.disabled = false;
  }
}

document.addEventListener("click", (event) => {
  const button = event.target.closest("[data-page], [data-action], [data-close-modal]");
  if (!button) {
    if (event.target.classList.contains("modal")) closeModal(event.target.id);
    return;
  }
  if (button.dataset.page) {
    showPage(button.dataset.page);
    return;
  }
  if (button.dataset.closeModal) {
    closeModal(button.dataset.closeModal);
    return;
  }

  const action = button.dataset.action;
  if (action === "select-request-area") {
    openPalletRequestModal(button.dataset.area);
  } else if (action === "send-topup-pallet") {
    withButtonDisabled(button, async () => {
      const { task } = await apiRequest("send-request", "POST", { task_id: button.dataset.taskId });
      await loadDashboard();
      announce(`AGV task ${task.task_id} queued to deliver ${task.pallet_type} to ${displayStationAreaName(task.area)}.`);
    });
  } else if (action === "open-area") {
    state.selectedArea = button.dataset.area;
    renderProductionDetail();
    showPage("page-prod-ship-detail");
  } else if (action === "send-to-shipping") {
    withButtonDisabled(button, async () => {
      await loadDashboard();
      const pallet = state.productionPallets.find((item) => item.code === button.dataset.code);
      if (!pallet) throw new Error("The selected pallet is no longer available.");
      const { task } = await apiRequest("create-task", "POST", {
        task_type: "PRODUCTION_TO_SHIP",
        code: pallet.code,
        pallet_type: pallet.type === "Unassigned" ? "" : pallet.type,
      });

      await loadDashboard();
      announce(`AGV task ${task.task_id} queued to send the loaded trolley and pallet to Shipping.`);
    });
  } else if (action === "send-trolley-to-topup") {
    withButtonDisabled(button, async () => {
      const { task } = await apiRequest("dispatch-trolley-to-topup", "POST", {
        task_id: button.dataset.taskId,
      });
      await loadDashboard();
      announce(`AGV task ${task.task_id} queued to return the empty trolley to TopUp.`);
    });
  }
});

loadDashboard()
  .catch((error) => announce(error.message, true));
window.setInterval(() => {
  loadDashboard().catch((error) => console.error("Unable to refresh Transport Order dashboard.", error));
}, 5000);
