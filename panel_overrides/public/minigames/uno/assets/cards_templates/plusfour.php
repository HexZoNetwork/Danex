<?php
function plusfour(){
    return <<<HTML
    <button type="button" onclick="setCont('+4'); is_turn();" name="card+" class="du-uno-card div_war9a_plus4 du-uno-card-plus4" aria-label="Play plus four card">
        <span class="du-uno-corner du-uno-corner-top">+4</span>
        <span class="du-stack-icon" aria-hidden="true"><i></i><i></i><i></i><i></i></span>
        <span class="du-uno-corner du-uno-corner-bottom">+4</span>
        <span class="du-uno-brand-mini">D</span>
    </button>
HTML;
}
?>
