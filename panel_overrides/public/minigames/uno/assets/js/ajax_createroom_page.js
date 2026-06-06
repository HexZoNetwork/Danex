function onReady(callback) {
  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", callback);
  } else {
    callback();
  }
}

function setPlayersRemaining(value) {
  var remaining = document.querySelector(".Players_remaining");
  if (remaining && value !== null && value !== undefined) {
    remaining.textContent = String(value);
  }
}

function appendCell(row, text, header) {
  var cell = document.createElement(header ? "th" : "td");
  cell.textContent = text;
  row.appendChild(cell);
}

function renderPlayers(players) {
  var table = document.getElementById("players");
  if (!table) return;

  table.replaceChildren();

  var header = document.createElement("tr");
  appendCell(header, "Player", true);
  appendCell(header, "ID", true);
  appendCell(header, "Status", true);
  table.appendChild(header);

  if (!players || players.length === 0) {
    var empty = document.createElement("tr");
    var cell = document.createElement("td");
    cell.colSpan = 3;
    cell.textContent = "No players have joined yet.";
    empty.appendChild(cell);
    table.appendChild(empty);
    return;
  }

  players.forEach(function (player, index) {
    var row = document.createElement("tr");
    appendCell(row, player.name || "Player " + (index + 1), false);
    appendCell(row, player.id || "-", false);
    appendCell(row, player.current ? "You" : "Joined", false);
    if (player.current) row.className = "du-current-player";
    table.appendChild(row);
  });
}

function updateTable() {
  var room = document.getElementById("roomC");
  var player = document.getElementById("playerI");
  if (!room || !player) return;

  var xmlhttp = new XMLHttpRequest();
  xmlhttp.onreadystatechange = function () {
    if (this.readyState === 4 && this.status === 200) {
      var payload = JSON.parse(this.responseText);
      renderPlayers(payload.players);
      setPlayersRemaining(payload.remaining);
    }
  };
  xmlhttp.open(
    "GET",
    "core/ajax_create_room.php?room-code=" +
      encodeURIComponent(room.value) +
      "&player-id=" +
      encodeURIComponent(player.value),
    true,
  );
  xmlhttp.send();
}

onReady(function () {
  updateTable();
  setInterval(updateTable, 1000);
});
