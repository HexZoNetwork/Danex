<?php
    if($_GET["action"] == "create"){
        header("Location: ../identify.php");
    }else{
        header("Location: ../join-room.php");
    }
?>