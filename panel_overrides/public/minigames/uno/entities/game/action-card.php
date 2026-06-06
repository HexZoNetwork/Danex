<?php
include_once("card-handler.php");

class ActionCard extends CardHandler{
    private function startsWith2($string, $startString){
        return strpos($string, $startString) === 0;
    }

    public function whichOne(){
        if($this->startsWith2($this->cardContent, "+2") === true){
            return "+2";
        }
        if($this->startsWith2($this->cardContent, "+4") === true){
            return "+4";
        }
        if($this->startsWith2($this->cardContent, "blo") === true){
            return "blo";
        }
        if($this->startsWith2($this->cardContent, "inv") === true){
            return "inv";
        }
        return false;
    }

    private function drawToNextPlayer($count){
        $link = $this->db();

        $stmt = $link->prepare("select numberOfCardsRemaining, nextCardNumber from stack where stack_id=? limit 1");
        $stmt->bind_param("s", $this->roomCode);
        $stmt->execute();
        $stack = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $stmt = $link->prepare("select nextPlayer from player where id=? and roomCode=? limit 1");
        $stmt->bind_param("ss", $this->playerId, $this->roomCode);
        $stmt->execute();
        $player = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$stack || !$player || empty($player["nextPlayer"])) {
            mysqli_close($link);
            return;
        }

        $d = (int) $stack["numberOfCardsRemaining"];
        $g = (int) $stack["nextCardNumber"];
        $targetPlayer = $player["nextPlayer"];

        for($i = 0; $i < $count; $i++){
            $stmt = $link->prepare("update card set id=? where order_in_stack=? and stack_id=?");
            $stmt->bind_param("sis", $targetPlayer, $g, $this->roomCode);
            $stmt->execute();
            $stmt->close();

            $stmt = $link->prepare("update player set numCards=numCards+1 where id=? and roomCode=?");
            $stmt->bind_param("ss", $targetPlayer, $this->roomCode);
            $stmt->execute();
            $stmt->close();

            $d--;
            $g++;
            $stmt = $link->prepare("update stack set numberOfCardsRemaining=?, nextCardNumber=? where stack_id=?");
            $stmt->bind_param("iis", $d, $g, $this->roomCode);
            $stmt->execute();
            $stmt->close();
        }

        mysqli_close($link);
    }

    private function plusTwo(){
        $this->drawToNextPlayer(2);
    }

    private function plusFour(){
        $this->drawToNextPlayer(4);
    }

    private function block(){
        $link = $this->db();

        $stmt = $link->prepare("select nextPlayer from player where id=? and roomCode=? limit 1");
        $stmt->bind_param("ss", $this->playerId, $this->roomCode);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($row && !empty($row["nextPlayer"])) {
            $stmt = $link->prepare("select nextPlayer from player where id=? and roomCode=? limit 1");
            $stmt->bind_param("ss", $row["nextPlayer"], $this->roomCode);
            $stmt->execute();
            $final = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($final && !empty($final["nextPlayer"])) {
                $stmt = $link->prepare("update room set playerTurn=? where roomCode=?");
                $stmt->bind_param("ss", $final["nextPlayer"], $this->roomCode);
                $stmt->execute();
                $stmt->close();
            }
        }

        mysqli_close($link);
    }

    private function inverse(){
        $link = $this->db();

        $stmt = $link->prepare("select numberOfPlayersRemaining, direction from room where roomCode=? limit 1");
        $stmt->bind_param("s", $this->roomCode);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        $stmt = $link->prepare("select nextPlayer, previousPlayer from player where id=? and roomCode=? limit 1");
        $stmt->bind_param("ss", $this->playerId, $this->roomCode);
        $stmt->execute();
        $row1 = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row || !$row1) {
            mysqli_close($link);
            return;
        }

        $numofplr = 4 - (int) $row["numberOfPlayersRemaining"];
        if($numofplr != 2){
            if($row["direction"] == 1){
                $direction = 0;
                $nextTurn = $row1["previousPlayer"];
            }else{
                $direction = 1;
                $nextTurn = $row1["nextPlayer"];
            }
            $stmt = $link->prepare("update room set direction=?, playerTurn=? where roomCode=?");
            $stmt->bind_param("iss", $direction, $nextTurn, $this->roomCode);
            $stmt->execute();
            $stmt->close();
        }else{
            $stmt = $link->prepare("update room set playerTurn=? where roomCode=?");
            $stmt->bind_param("ss", $this->playerId, $this->roomCode);
            $stmt->execute();
            $stmt->close();
        }

        mysqli_close($link);
    }

    public function applyActionCard(){
        $action = $this->whichOne();
        if($action == "+2"){
            $this->plusTwo();
        }elseif($action == "+4"){
            $this->plusFour();
        }elseif($action == "blo"){
            $this->block();
        }elseif($action == "inv"){
            $this->inverse();
        }
    }
}
?>
