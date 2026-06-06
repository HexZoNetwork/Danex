<?php
function block($content, $col){
    $safeContent = htmlspecialchars($content, ENT_QUOTES, 'UTF-8');
    $safeColor = htmlspecialchars($col, ENT_QUOTES, 'UTF-8');
    return <<<HTML
    <button type="submit" onclick="setCont('$safeContent'); return is_turn();" name="card" style="--uno-card-color: $safeColor;" class="du-uno-card div_war9a_block du-uno-card-action" aria-label="Play $safeContent">
        <span class="du-uno-corner du-uno-corner-top">⊘</span>
        <span class="du-action-glyph">⊘</span>
        <span class="du-uno-corner du-uno-corner-bottom">⊘</span>
        <span class="du-uno-brand-mini">D</span>
    </button>
HTML;
}
?>
