function onReady(callback) {
  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", callback);
  } else {
    callback();
  }
}

function replaceText(id, text) {
  var element = document.getElementById(id);
  if (element) element.textContent = text || "";
}

function cardColor(content) {
  var color =
    content && content.indexOf("-") !== -1
      ? content.charAt(content.length - 1)
      : "";
  switch (color) {
    case "r":
      return "#ff4747";
    case "g":
      return "#6fc763";
    case "b":
      return "#5496ff";
    case "y":
      return "#eddc1c";
    default:
      return "#111117";
  }
}

function cardLabel(content) {
  if (!content) return "?";
  if (content === "wc") return "W";
  if (content === "+4") return "+4";
  if (content.indexOf("+2") === 0) return "+2";
  if (content.indexOf("inv") === 0) return "↺";
  if (content.indexOf("blo") === 0) return "⊘";
  if (/^[0-9]/.test(content)) return content.charAt(0);
  return content;
}

function renderSpectateCard(content) {
  var target = document.getElementById("cardOnTable");
  if (!target) return;
  var card = document.createElement("div");
  var label = cardLabel(content);
  card.className = "du-uno-card du-uno-card-action";
  card.style.setProperty("--uno-card-color", cardColor(content));

  var top = document.createElement("span");
  top.className = "du-uno-corner du-uno-corner-top";
  top.textContent = label;
  var center = document.createElement("span");
  center.className = "du-action-glyph";
  center.textContent = label;
  var bottom = document.createElement("span");
  bottom.className = "du-uno-corner du-uno-corner-bottom";
  bottom.textContent = label;
  var brand = document.createElement("span");
  brand.className = "du-uno-brand-mini";
  brand.textContent = "D";
  card.appendChild(top);
  card.appendChild(center);
  card.appendChild(bottom);
  card.appendChild(brand);
  target.replaceChildren(card);
}

function renderPlayers(players) {
  var table = document.getElementById("spectatorPlayers");
  if (!table) return;
  table.replaceChildren();
  (players || []).forEach(function (player) {
    var row = document.createElement("tr");
    var avatarCell = document.createElement("td");
    var avatar = document.createElement("span");
    avatar.className = "du-avatar-dot";
    if (player.avatar_url) {
      avatar.style.backgroundImage =
        "url('" + String(player.avatar_url).replace(/'/g, "%27") + "')";
    } else {
      avatar.textContent = (player.name || "P").charAt(0).toUpperCase();
    }
    avatarCell.appendChild(avatar);

    var nameCell = document.createElement("td");
    nameCell.textContent =
      (player.active ? "▶ " : "") + (player.name || "Player");
    var cardsCell = document.createElement("td");
    cardsCell.textContent = "Cards: " + player.cards;
    if (player.active) row.className = "du-current-player";
    row.appendChild(avatarCell);
    row.appendChild(nameCell);
    row.appendChild(cardsCell);
    table.appendChild(row);
  });
}

function updateSpectatorState() {
  var xmlhttp = new XMLHttpRequest();
  xmlhttp.onreadystatechange = function () {
    if (this.readyState === 4 && this.status === 200) {
      var state = JSON.parse(this.responseText);
      replaceText("roomCode", state.room_code);
      replaceText("indicator", state.color);
      replaceText(
        "spectatorState",
        state.ended ? "Ended" : state.started ? "Live" : "Waiting",
      );
      renderSpectateCard(state.card_on_table);
      renderPlayers(state.players);
    }
  };
  xmlhttp.open("GET", "core/spectate/state.php", true);
  xmlhttp.send();
}

onReady(function () {
  updateSpectatorState();
  setInterval(updateSpectatorState, 1000);
});
