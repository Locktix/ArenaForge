<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/skill_tree.php';

require_login();

$me = current_brute();
if (!$me) { header('Location: dashboard.php'); exit; }

$bruteId = (int)$me['id'];
skill_tree_ensure_schema();

$stmt = db()->prepare('SELECT skill_points FROM brutes WHERE id = ? LIMIT 1');
$stmt->execute([$bruteId]);
$sp = (int)($stmt->fetchColumn() ?: 0);

$unlocked = skill_tree_nodes_for_brute($bruteId);
$csrf     = csrf_token();
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="utf-8">
<title>Compétences – ArenaForge</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="icon" href="../assets/svg/logo/favicon.svg" type="image/svg+xml">
<link rel="stylesheet" href="../assets/css/main.css">
</head>
<body>
<?php include __DIR__ . '/_nav.php'; ?>

<main class="wrap">

<section class="card skill-header">
    <div class="skill-header-info">
        <h1 class="skill-title">Arbre de Compétences</h1>
        <p class="muted">Investis tes points dans l'une des trois voies. Chaque niveau gagné te rapporte +1 point.</p>
    </div>
    <div class="skill-points-badge <?= $sp > 0 ? 'skill-points-badge--available' : '' ?>">
        <span class="skill-points-count"><?= $sp ?></span>
        <span class="skill-points-label">point<?= $sp !== 1 ? 's' : '' ?> disponible<?= $sp !== 1 ? 's' : '' ?></span>
    </div>
</section>

<div class="skill-tree-canvas card">
<div class="skill-tree-columns">
<?php foreach (SKILL_TREE as $branchId => $branch):
    $branchNodes = $branch['nodes'];
    $tiers = [];
    foreach ($branchNodes as $nid => $n) {
        $tiers[$n['tier']][$nid] = $n;
    }
    ksort($tiers);
    $lastTier = max(array_keys($tiers));
?>
<div class="skill-branch-col" style="--branch-color:<?= h($branch['color']) ?>">
    <div class="skill-branch-head">
        <span class="branch-icon-lg"><?= $branch['icon'] ?></span>
        <div>
            <h2 class="branch-label"><?= h($branch['label']) ?></h2>
            <p class="muted small branch-desc"><?= h($branch['desc']) ?></p>
        </div>
    </div>

    <div class="skill-branch-nodes">
    <?php foreach ($tiers as $tier => $nodes):
        $anyUnlocked = false;
        foreach ($nodes as $nid => $_) {
            if (in_array($nid, $unlocked, true)) { $anyUnlocked = true; break; }
        }
    ?>
        <div class="skill-tier">
        <?php foreach ($nodes as $nodeId => $node):
            $isUnlocked  = in_array($nodeId, $unlocked, true);
            $canUnlock   = !$isUnlocked && $sp >= (int)$node['cost'] && skill_tree_can_unlock($bruteId, $nodeId, $unlocked);
            $stateClass  = $isUnlocked ? 'skill-node--unlocked' : ($canUnlock ? 'skill-node--available' : 'skill-node--locked');
        ?>
        <div class="skill-node <?= $stateClass ?>"
             data-node-id="<?= h($nodeId) ?>"
             data-cost="<?= (int)$node['cost'] ?>"
             data-csrf="<?= h($csrf) ?>"
             <?= $canUnlock ? 'role="button" tabindex="0"' : '' ?>>
            <div class="skill-node-icon"><?= $node['icon'] ?></div>
            <div class="skill-node-name"><?= h($node['name']) ?></div>
            <div class="skill-node-desc muted small"><?= h($node['desc']) ?></div>
            <div class="skill-node-cost">
                <?php if ($isUnlocked): ?>
                    <span class="skill-node-done">✓ Débloqué</span>
                <?php else: ?>
                    <span class="skill-node-pts <?= $canUnlock ? 'skill-node-pts--ok' : '' ?>">
                        <?= (int)$node['cost'] ?> pt<?= $node['cost'] > 1 ? 's' : '' ?>
                    </span>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
        </div>
        <?php if ($tier < $lastTier): ?>
        <div class="skill-connector <?= $anyUnlocked ? 'skill-connector--lit' : '' ?>"></div>
        <?php endif; ?>
    <?php endforeach; ?>
    </div>
</div>
<?php endforeach; ?>
</div>

<div class="skill-tree-trunk">
    <div class="trunk-drops">
        <?php foreach (SKILL_TREE as $b): ?>
        <div class="trunk-drop" style="--branch-color:<?= h($b['color']) ?>"></div>
        <?php endforeach; ?>
    </div>
    <div class="trunk-bar">
        <div class="trunk-root">⚜</div>
    </div>
</div>
</div>

<?php if (array_sum(array_map(fn($b) => array_sum(array_map(fn($n) => (int)$n['cost'], $b['nodes'])), SKILL_TREE)) === array_sum(array_map('intval', array_map(fn($nid) => skill_tree_get_node($nid)['cost'] ?? 0, $unlocked)))): ?>
<p class="text-center muted small" style="margin-top:16px">🏆 Arbre entièrement débloqué !</p>
<?php endif; ?>

</main>

<script>
(function () {
    function showToast(title, desc, ok) {
        if (window.Toast) {
            window.Toast.queue([{ title, description: desc, icon_path: ok ? 'assets/svg/skills/strength.svg' : 'assets/svg/ui/nav_settings.svg' }]);
        }
    }

    document.querySelectorAll('.skill-node--available').forEach(function (el) {
        function tryUnlock() {
            const nodeId = el.dataset.nodeId;
            const csrf   = el.dataset.csrf;

            el.style.pointerEvents = 'none';
            el.style.opacity = '0.6';

            const fd = new FormData();
            fd.append('csrf', csrf);
            fd.append('node_id', nodeId);

            fetch('../api/skill_unlock.php', { method: 'POST', body: fd })
                .then(r => r.json())
                .then(function (data) {
                    if (data.ok) {
                        showToast('Nœud débloqué !', el.querySelector('.skill-node-name').textContent, true);
                        setTimeout(() => window.location.reload(), 600);
                    } else {
                        el.style.pointerEvents = '';
                        el.style.opacity = '';
                        showToast('Erreur', data.error || 'Impossible', false);
                    }
                })
                .catch(function () {
                    el.style.pointerEvents = '';
                    el.style.opacity = '';
                    showToast('Erreur réseau', 'Réessaie.', false);
                });
        }

        el.addEventListener('click', tryUnlock);
        el.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); tryUnlock(); }
        });
    });
})();
</script>
</body>
</html>
