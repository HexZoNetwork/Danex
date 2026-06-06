<?php
class CardHandler{
    protected $roomCode;
    protected $playerId;
    protected $cardContent;
    protected $color;
    protected $cardNum;

    function __construct($room, $player, $card, $num){
        $this->roomCode = $room;
        $this->playerId = $player;
        $this->cardContent = $card;
        $this->cardNum = $num;
        $this->color = "none";
    }

    protected function db(){
        include("../../keys.php");
        $link = mysqli_connect($serverIp, $username, $pass, $dbName);
        if (!$link) {
            http_response_code(500);
            exit('Database connection failed.');
        }
        return $link;
    }

    protected function cardPrefix($content){
        $parts = explode('-', (string) $content, 2);
        return $parts[0];
    }

    public function setColor($color){
        $this->color = $color;
    }

    public function isCompatible(){
        if($this->cardContent == "+4" || $this->cardContent == "wc"){
            return true;
        }

        $link = $this->db();
        $stmt = $link->prepare("select cardOnTable, color from room where roomCode=? limit 1");
        $stmt->bind_param("s", $this->roomCode);
        $stmt->execute();
        $list = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        mysqli_close($link);

        if (!$list) {
            return false;
        }

        $cardColor = $this->cardContent[strlen($this->cardContent)-1];
        if($list["cardOnTable"] == "+4" || $list["cardOnTable"] == "wc"){
            return $list["color"] == $cardColor || $list["color"] == NULL;
        }

        return $list["color"] == $cardColor || $this->cardPrefix($list["cardOnTable"]) == $this->cardPrefix($this->cardContent);
    }

    public function updateCardOnTable(){
        $link = $this->db();
        $cardColor = $this->color == "none" ? $this->cardContent[strlen($this->cardContent)-1] : $this->color;
        $stmt = $link->prepare("update room set cardOnTable=?, color=? where roomCode=?");
        $stmt->bind_param("sss", $this->cardContent, $cardColor, $this->roomCode);
        $stmt->execute();
        $stmt->close();
        mysqli_close($link);
    }

    public function managePlayerCards(){
        // Remove the played card assignment. play-card.php owns the hand count update.
        $link = $this->db();
        $cardNum = (int) $this->cardNum;
        $stmt = $link->prepare("update card set id=NULL where number=? and stack_id=? and id=?");
        $stmt->bind_param("iss", $cardNum, $this->roomCode, $this->playerId);
        $stmt->execute();
        $stmt->close();
        mysqli_close($link);
    }

    public function passTurn(){
        $link = $this->db();

        $stmt = $link->prepare("select nextPlayer, previousPlayer from player where id=? and roomCode=? limit 1");
        $stmt->bind_param("ss", $this->playerId, $this->roomCode);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $stmt = $link->prepare("select direction from room where roomCode=? limit 1");
        $stmt->bind_param("s", $this->roomCode);
        $stmt->execute();
        $row1 = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($row && $row1) {
            $nextTurn = $row1["direction"] == 1 ? $row["nextPlayer"] : $row["previousPlayer"];
            $stmt = $link->prepare("update room set playerTurn=? where roomCode=?");
            $stmt->bind_param("ss", $nextTurn, $this->roomCode);
            $stmt->execute();
            $stmt->close();
        }
        mysqli_close($link);
    }

    public function isActionCard(){
        if(strpos($this->cardContent, "+2") === 0){
            return true;
        }
        if(strpos($this->cardContent, "+4") === 0){
            return true;
        }
        if(strpos($this->cardContent, "blo") === 0){
            return true;
        }
        if(strpos($this->cardContent, "inv") === 0){
            return true;
        }
        return false;
    }
}
?>
