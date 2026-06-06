<?php
function plustwo($content, $col){
    $safeContent = htmlspecialchars($content, ENT_QUOTES, 'UTF-8');
    $safeColor = htmlspecialchars($col, ENT_QUOTES, 'UTF-8');
    return <<<HTML
    <button type="submit" onclick="setCont('$safeContent'); return is_turn();" name="card" style="--uno-card-color: $safeColor;" class="du-uno-card div_war9a_plus2 du-uno-card-action" aria-label="Play $safeContent">
        <span class="du-uno-corner du-uno-corner-top">+2</span>
        <span class="du-action-glyph">+2</span>
        <span class="du-uno-corner du-uno-corner-bottom">+2</span>
        <span class="du-uno-brand-mini">D</span>
    </button>
HTML;
}
?>
