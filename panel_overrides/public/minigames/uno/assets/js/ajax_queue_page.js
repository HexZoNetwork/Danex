function onReady(callback) {
  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", callback);
  } else {
    callback();
  }
}

function updateStart() {
  var room = document.getElementById("roomC");
  var player = document.getElementById("playerI");
  if (!room || !player) return;

  var xmlhttp = new XMLHttpRequest();
  xmlhttp.onreadystatechange = function () {
    if (this.readyState === 4 && this.status === 200) {
      var arr = JSON.parse(this.responseText);
      if (arr[0].start === "1") {
        location.href = "game-play.php";
      }
    }
  };
  xmlhttp.open(
    "GET",
    "core/check-started.php?room-code=" +
      encodeURIComponent(room.value) +
      "&player-id=" +
      encodeURIComponent(player.value),
    true,
  );
  xmlhttp.send();
}

onReady(function () {
  setInterval(updateStart, 1000);
});
