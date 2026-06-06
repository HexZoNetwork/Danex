<?php
function wildcard(){
    return <<<HTML
    <button type="button" onclick="setCont('wc'); is_turn();" name="card+" class="du-uno-card div_war9et_4_colors du-uno-card-wild" aria-label="Play wild card">
        <span class="du-uno-corner du-uno-corner-top">W</span>
        <span class="du-wild-orb" aria-hidden="true"><i></i><i></i><i></i><i></i></span>
        <span class="du-uno-corner du-uno-corner-bottom">W</span>
        <span class="du-uno-brand-mini">D</span>
    </button>
HTML;
}
?>
