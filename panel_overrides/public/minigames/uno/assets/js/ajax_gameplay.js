function setHidden(id, hidden) {
  var element = document.getElementById(id);
  if (element) element.hidden = hidden;
}
function replaceText(id, text) {
  var element = document.getElementById(id);
  if (element) element.textContent = text || "";
}
function onReady(callback) {
  if (document.readyState === "loading")
    document.addEventListener("DOMContentLoaded", callback);
  else callback();
}
function getFieldValue(id) {
  var element = document.getElementById(id);
  return element ? encodeURIComponent(element.value) : "";
}
function rawFieldValue(id) {
  var element = document.getElementById(id);
  return element ? element.value : "";
}
function query() {
  return (
    "room-code=" + getFieldValue("rc") + "&player-id=" + getFieldValue("pl_id")
  );
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
function cardKind(content) {
  if (!content) return "unknown";
  if (content === "wc") return "wild";
  if (content === "+4") return "plus4";
  if (content.indexOf("+2") === 0) return "plus2";
  if (content.indexOf("inv") === 0) return "inverse";
  if (content.indexOf("blo") === 0) return "block";
  if (/^[0-9]/.test(content)) return "number";
  return "action";
}
function cardLabel(content) {
  var kind = cardKind(content);
  if (kind === "wild") return "W";
  if (kind === "plus4") return "+4";
  if (kind === "plus2") return "+2";
  if (kind === "inverse") return "↺";
  if (kind === "block") return "⊘";
  if (kind === "number") return content.charAt(0);
  return content || "?";
}
function appendCorner(card, label, bottom) {
  var corner = document.createElement("span");
  corner.className =
    "du-uno-corner " + (bottom ? "du-uno-corner-bottom" : "du-uno-corner-top");
  corner.textContent = label;
  card.appendChild(corner);
}
function appendBrand(card) {
  var brand = document.createElement("span");
  brand.className = "du-uno-brand-mini";
  brand.textContent = "D";
  card.appendChild(brand);
}
function appendWildIcon(card, plus4) {
  var icon = document.createElement("span");
  icon.className = plus4 ? "du-stack-icon" : "du-wild-orb";
  icon.setAttribute("aria-hidden", "true");
  for (var i = 0; i < 4; i++) icon.appendChild(document.createElement("i"));
  card.appendChild(icon);
}
function renderUnoCard(content, playable) {
  var kind = cardKind(content);
  var label = cardLabel(content);
  var card = document.createElement(playable ? "button" : "div");
  card.className = "du-uno-card";
  card.style.setProperty("--uno-card-color", cardColor(content));
  card.setAttribute("aria-label", (playable ? "Play " : "Card ") + content);

  if (kind === "number")
    card.className += " div_war9a_aadiyya du-uno-card-number";
  else if (kind === "wild")
    card.className += " div_war9et_4_colors du-uno-card-wild";
  else if (kind === "plus4")
    card.className += " div_war9a_plus4 du-uno-card-plus4";
  else if (kind === "plus2")
    card.className += " div_war9a_plus2 du-uno-card-action";
  else if (kind === "inverse")
    card.className += " div_war9a_inverse du-uno-card-action";
  else if (kind === "block")
    card.className += " div_war9a_block du-uno-card-action";
  else card.className += " du-uno-card-action";

  appendCorner(card, label, false);
  if (kind === "wild" || kind === "plus4") {
    appendWildIcon(card, kind === "plus4");
  } else {
    var center = document.createElement("span");
    center.className = kind === "number" ? "du-uno-center" : "du-action-glyph";
    center.textContent = label;
    card.appendChild(center);
  }
  appendCorner(card, label, true);
  appendBrand(card);

  if (playable) {
    card.type = kind === "wild" || kind === "plus4" ? "button" : "submit";
    card.name = kind === "wild" || kind === "plus4" ? "card+" : "card";
    card.addEventListener("click", function (event) {
      setCont(content);
      if (kind === "wild" || kind === "plus4") {
        event.preventDefault();
        is_turn();
      }
    });
  }

  return card;
}
function renderTableCard(content) {
  replaceText("carot", content);
  var target = document.getElementById("cardOnTable");
  if (!target) return;
  target.replaceChildren(renderUnoCard(content, false));
}
function renderPlayers(players) {
  var table = document.getElementById("pt");
  if (!table) return;
  table.replaceChildren();
  var row = document.createElement("tr");
  (players || []).forEach(function (player) {
    var cell = document.createElement("td");
    var text = document.createElement("p");
    text.textContent = player.name + " - Cards: " + player.cards;
    text.style.color = player.active ? "#f87171" : "#d8d0ea";
    cell.appendChild(text);
    row.appendChild(cell);
  });
  table.appendChild(row);
}
function renderCards(cards) {
  var table = document.getElementById("cards");
  if (!table) return;
  table.replaceChildren();
  var row = document.createElement("tr");
  (cards || []).forEach(function (cardData) {
    var content = cardData.content || "";
    var cell = document.createElement("td");
    var form = document.createElement("form");
    form.action = "core/game/play-card.php";
    form.method = "get";
    form.name = "formcard";
    form.addEventListener("submit", function (event) {
      setCont(content);
      if (!is_turn()) event.preventDefault();
    });

    [
      ["room-code", rawFieldValue("rc")],
      ["card-content", content],
      ["player-id", rawFieldValue("pl_id")],
    ].forEach(function (pair) {
      var input = document.createElement("input");
      input.type = "hidden";
      input.name = pair[0];
      input.value = pair[1];
      form.appendChild(input);
    });

    form.appendChild(renderUnoCard(content, true));
    cell.appendChild(form);
    row.appendChild(cell);
  });
  table.appendChild(row);
}
function updateTable() {
  var xmlhttp = new XMLHttpRequest();
  xmlhttp.onreadystatechange = function () {
    if (this.readyState === 4 && this.status === 200) {
      var arr = JSON.parse(this.responseText);
      renderTableCard(arr[0].cardOnTable);
      replaceText("indicator", arr[0].colorInd);
    }
  };
  xmlhttp.open("GET", "core/game/ajax/update_table.php?" + query(), true);
  xmlhttp.send();
}
function updateStatus() {
  var xmlhttp = new XMLHttpRequest();
  xmlhttp.onreadystatechange = function () {
    if (this.readyState === 4 && this.status === 200) {
      var status;
      try {
        status = JSON.parse(this.responseText);
      } catch (error) {
        status = { ended: false, turn: this.responseText === "0" };
      }
      if (status.ended && status.result_url) {
        window.location.href = status.result_url;
        return;
      }
      var shouldHide = !status.turn;
      setHidden("stat", shouldHide);
      setHidden("stat-2", shouldHide);
    }
  };
  xmlhttp.open("GET", "core/game/ajax/update_status.php?" + query(), true);
  xmlhttp.send();
}
function updatePlayers() {
  var xmlhttp = new XMLHttpRequest();
  xmlhttp.onreadystatechange = function () {
    if (this.readyState === 4 && this.status === 200) {
      var arr = JSON.parse(this.responseText);
      renderPlayers(arr[0].players);
      var turn = document.getElementById("turn");
      if (turn) turn.value = arr[0].turn;
    }
  };
  xmlhttp.open("GET", "core/game/ajax/update_players.php?" + query(), true);
  xmlhttp.send();
}
function updateCards() {
  var xmlhttp = new XMLHttpRequest();
  xmlhttp.onreadystatechange = function () {
    if (this.readyState === 4 && this.status === 200) {
      var payload = JSON.parse(this.responseText);
      renderCards(payload.cards);
    }
  };
  xmlhttp.open("GET", "core/game/ajax/update_cards.php?" + query(), true);
  xmlhttp.send();
}
onReady(function () {
  updateTable();
  updateStatus();
  updatePlayers();
  updateCards();
  setInterval(function () {
    updateTable();
    updateStatus();
    updatePlayers();
    updateCards();
  }, 1000);
});
