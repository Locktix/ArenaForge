<?php
// Rendu du portrait du gladiateur à partir de son appearance JSON
// Variables attendues en scope : $appearance (array)
$app = $appearance ?? [];
$body = (int)($app['body'] ?? 1);
$hair = (int)($app['hair'] ?? 0);
$skin = (int)($app['skin'] ?? 1);
$tune = (int)($app['color'] ?? 200);

$skinPalette = ['#f3c2a6', '#d89c77', '#8b5a3c'];
$skinColor   = $skinPalette[$skin % 3];
$tuneColor   = "hsl($tune, 55%, 45%)";

// Largeurs de corps selon morphologie
$bodyW = [28, 36, 46][$body % 3];
$armW  = [6, 8, 10][$body % 3];
?>
<svg viewBox="0 0 120 180" class="gladiator" xmlns="http://www.w3.org/2000/svg" aria-label="Gladiateur">
    <!-- Ombre -->
    <ellipse cx="60" cy="170" rx="30" ry="5" fill="#000" opacity="0.25"/>

    <!-- Jambes -->
    <rect x="<?= 60 - $bodyW / 2 + 4 ?>" y="120" width="<?= $bodyW / 2 - 5 ?>" height="40" fill="<?= $skinColor ?>" stroke="#2a1a10" stroke-width="1.5" rx="3"/>
    <rect x="<?= 60 + 1 ?>" y="120" width="<?= $bodyW / 2 - 5 ?>" height="40" fill="<?= $skinColor ?>" stroke="#2a1a10" stroke-width="1.5" rx="3"/>

    <!-- Torse -->
    <rect x="<?= 60 - $bodyW / 2 ?>" y="68" width="<?= $bodyW ?>" height="60" fill="<?= $tuneColor ?>" stroke="#2a1a10" stroke-width="2" rx="6"/>
    <!-- Ceinture -->
    <rect x="<?= 60 - $bodyW / 2 ?>" y="115" width="<?= $bodyW ?>" height="6" fill="#b8862b" stroke="#2a1a10" stroke-width="1.5"/>

    <!-- Bras -->
    <rect x="<?= 60 - $bodyW / 2 - $armW ?>" y="72" width="<?= $armW ?>" height="50" fill="<?= $skinColor ?>" stroke="#2a1a10" stroke-width="1.5" rx="4"/>
    <rect x="<?= 60 + $bodyW / 2 ?>" y="72" width="<?= $armW ?>" height="50" fill="<?= $skinColor ?>" stroke="#2a1a10" stroke-width="1.5" rx="4"/>

    <!-- Cou -->
    <rect x="55" y="60" width="10" height="10" fill="<?= $skinColor ?>" stroke="#2a1a10" stroke-width="1.5"/>

    <!-- Tête -->
    <circle cx="60" cy="42" r="22" fill="<?= $skinColor ?>" stroke="#2a1a10" stroke-width="2"/>

    <!-- Coiffure -->
    <?php if ($hair === 0): ?>
        <path d="M38 42 Q40 20 60 18 Q80 20 82 42 Q78 28 60 26 Q42 28 38 42 Z" fill="#3a1f0a" stroke="#2a1a10" stroke-width="1.5"/>
    <?php elseif ($hair === 1): ?>
        <path d="M40 36 Q60 14 80 36 Q60 26 40 36 Z" fill="#c77a2a" stroke="#2a1a10" stroke-width="1.5"/>
    <?php elseif ($hair === 2): ?>
        <path d="M40 40 Q60 10 80 40 L78 48 Q60 30 42 48 Z" fill="#e6c35a" stroke="#2a1a10" stroke-width="1.5"/>
        <path d="M52 22 L58 14 L62 22 L68 14 L74 24" fill="none" stroke="#e6c35a" stroke-width="3" stroke-linecap="round"/>
    <?php else: ?>
        <path d="M42 44 Q42 22 60 20 Q78 22 78 44 L76 30 L70 22 L60 20 L50 22 L44 30 Z" fill="#1a0f08" stroke="#2a1a10" stroke-width="1.5"/>
    <?php endif; ?>

    <!-- Yeux -->
    <circle cx="52" cy="44" r="2" fill="#1a0f08"/>
    <circle cx="68" cy="44" r="2" fill="#1a0f08"/>
    <!-- Bouche -->
    <path d="M54 54 Q60 58 66 54" stroke="#2a1a10" stroke-width="1.5" fill="none" stroke-linecap="round"/>
</svg>
