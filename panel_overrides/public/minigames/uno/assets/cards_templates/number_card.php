<?php
function numbercard($content, $color){
    $label = htmlspecialchars($content[0], ENT_QUOTES, 'UTF-8');
    $safeContent = htmlspecialchars($content, ENT_QUOTES, 'UTF-8');
    $safeColor = htmlspecialchars($color, ENT_QUOTES, 'UTF-8');
    return <<<HTML
    <button type="submit" onclick="setCont('$safeContent'); return is_turn();" name="card" style="--uno-card-color: $safeColor;" class="du-uno-card div_war9a_aadiyya du-uno-card-number" aria-label="Play $safeContent">
        <span class="du-uno-corner du-uno-corner-top">$label</span>
        <span class="du-uno-center">$label</span>
        <span class="du-uno-corner du-uno-corner-bottom">$label</span>
        <span class="du-uno-brand-mini">D</span>
    </button>
HTML;
}
?>
