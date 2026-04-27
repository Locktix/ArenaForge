<?php
// Marché noir — boutique journalière à or
//
// Les offres sont générées une fois par jour (déterministes par date, pour
// que tous les joueurs voient les mêmes lots). Chaque offre n'est achetable
// qu'une seule fois par brute.

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/brute_generator.php';

const MARKET_SLOTS = 4;

// Pool d'offres possibles. La génération quotidienne en pioche 4 distinctes.
const MARKET_POOL = [
    ['type' => 'fragments',   'value' => 30,  'cost' => 20,  'label' => 'Sac de fragments',     'icon' => 'assets/svg/weapons/axe.svg'],
    ['type' => 'fragments',   'value' => 80,  'cost' => 55,  'label' => 'Coffre de fragments',  'icon' => 'assets/svg/weapons/axe.svg'],
    ['type' => 'fragments',   'value' => 200, 'cost' => 140, 'label' => 'Bahut du forgeron',    'icon' => 'assets/svg/weapons/axe.svg'],
    ['type' => 'xp',          'value' => 30,  'cost' => 40,  'label' => 'Elixir d\'experience', 'icon' => 'assets/svg/quests/sword.svg'],
    ['type' => 'xp',          'value' => 75,  'cost' => 110, 'label' => 'Potion antique',       'icon' => 'assets/svg/quests/sword.svg'],
    ['type' => 'bonus_fight', 'value' => 1,   'cost' => 30,  'label' => 'Permis de combat',     'icon' => 'assets/svg/quests/fire.svg'],
    ['type' => 'bonus_fight', 'value' => 3,   'cost' => 75,  'label' => 'Trio de pancartes',    'icon' => 'assets/svg/quests/fire.svg'],
    ['type' => 'potion',      'value' => 25,  'cost' => 35,  'label' => 'Onguent de soin',      'icon' => 'assets/svg/skills/regen.svg'],
];

// ============================================================
// Génération journalière
// ============================================================

function ensure_market_offers_for_date(string $date): void
{
    $pdo = db();

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM black_market_offers WHERE offer_date = ?');
    $stmt->execute([$date]);
    if ((int)$stmt->fetchColumn() >= MARKET_SLOTS) {
        return;
    }

    // Seed déterministe : hash de la date
    $seed = (int)(hexdec(substr(md5($date), 0, 8)) & 0x7FFFFFFF);

    // Mélange du pool avec l'ordre seedé
    $pool = MARKET_POOL;
    for ($i = count($pool) - 1; $i > 0; $i--) {
        $seed = ($seed * 1103515245 + 12345) & 0x7FFFFFFF;
        $j = $seed % ($i + 1);
        [$pool[$i], $pool[$j]] = [$pool[$j], $pool[$i]];
    }

    $picked = array_slice($pool, 0, MARKET_SLOTS);

    $insert = $pdo->prepare('
        INSERT IGNORE INTO black_market_offers
          (offer_date, slot, item_type, item_value, cost_gold, label, icon_path)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ');
    foreach ($picked as $i => $offer) {
        $insert->execute([
            $date, $i + 1,
            $offer['type'], (int)$offer['value'], (int)$offer['cost'],
            $offer['label'], $offer['icon'],
        ]);
    }
}

// ============================================================
// Lecture
// ============================================================

function list_today_market(int $bruteId): array
{
    $today = date('Y-m-d');
    ensure_market_offers_for_date($today);

    $stmt = db()->prepare('
        SELECT o.*,
               (SELECT 1 FROM brute_market_purchases bmp
                 WHERE bmp.brute_id = ? AND bmp.offer_id = o.id LIMIT 1) AS bought
        FROM black_market_offers o
        WHERE o.offer_date = ?
        ORDER BY o.slot ASC
    ');
    $stmt->execute([$bruteId, $today]);
    return $stmt->fetchAll();
}

function get_brute_gold(int $bruteId): int
{
    $stmt = db()->prepare('SELECT gold FROM brutes WHERE id = ? LIMIT 1');
    $stmt->execute([$bruteId]);
    return (int)$stmt->fetchColumn();
}

// ============================================================
// Achat
// ============================================================

function buy_market_offer(int $bruteId, int $offerId): array
{
    $pdo = db();

    $stmt = $pdo->prepare('SELECT * FROM black_market_offers WHERE id = ? LIMIT 1');
    $stmt->execute([$offerId]);
    $offer = $stmt->fetch();
    if (!$offer) return ['ok' => false, 'error' => 'Offre introuvable'];

    if ((string)$offer['offer_date'] !== date('Y-m-d')) {
        return ['ok' => false, 'error' => 'Cette offre n\'est plus disponible'];
    }

    $stmt = $pdo->prepare('SELECT 1 FROM brute_market_purchases WHERE brute_id = ? AND offer_id = ? LIMIT 1');
    $stmt->execute([$bruteId, $offerId]);
    if ($stmt->fetchColumn()) {
        return ['ok' => false, 'error' => 'Tu as déjà acheté cette offre'];
    }

    $stmt = $pdo->prepare('SELECT gold, xp, level, hp_max, pending_levelup FROM brutes WHERE id = ? LIMIT 1');
    $stmt->execute([$bruteId]);
    $brute = $stmt->fetch();
    if (!$brute) return ['ok' => false, 'error' => 'Gladiateur introuvable'];

    $cost = (int)$offer['cost_gold'];
    if ((int)$brute['gold'] < $cost) {
        return ['ok' => false, 'error' => "Or insuffisant ({$brute['gold']}/{$cost})"];
    }

    $type  = (string)$offer['item_type'];
    $value = (int)$offer['item_value'];

    $pdo->beginTransaction();
    try {
        $pdo->prepare('UPDATE brutes SET gold = gold - ? WHERE id = ?')->execute([$cost, $bruteId]);
        $pdo->prepare('INSERT INTO brute_market_purchases (brute_id, offer_id) VALUES (?, ?)')
            ->execute([$bruteId, $offerId]);

        $reward = ['type' => $type, 'value' => $value];

        switch ($type) {
            case 'fragments':
                $pdo->prepare('UPDATE brutes SET fragments = fragments + ? WHERE id = ?')
                    ->execute([$value, $bruteId]);
                break;

            case 'xp': {
                $newXp    = (int)$brute['xp'] + $value;
                $newLevel = (int)$brute['level'];
                $levelUp  = (int)$brute['pending_levelup'] === 1;
                while ($newXp >= xp_for_level($newLevel + 1)) {
                    $newLevel++;
                    $levelUp = true;
                }
                $pdo->prepare('UPDATE brutes SET xp = ?, level = ?, pending_levelup = ? WHERE id = ?')
                    ->execute([$newXp, $newLevel, $levelUp ? 1 : 0, $bruteId]);
                $reward['level_up'] = $levelUp;
                break;
            }

            case 'bonus_fight':
                $pdo->prepare('UPDATE brutes SET bonus_fights_available = bonus_fights_available + ? WHERE id = ?')
                    ->execute([$value, $bruteId]);
                break;

            case 'potion':
                // Onguent : +N PV max permanent (petit boost)
                $pdo->prepare('UPDATE brutes SET hp_max = hp_max + ? WHERE id = ?')
                    ->execute([$value, $bruteId]);
                break;

            default:
                throw new RuntimeException('Type d\'offre inconnu : ' . $type);
        }

        $pdo->commit();
        return [
            'ok'         => true,
            'cost'       => $cost,
            'remaining'  => (int)$brute['gold'] - $cost,
            'reward'     => $reward,
        ];
    } catch (Throwable $e) {
        $pdo->rollBack();
        return ['ok' => false, 'error' => 'Erreur achat : ' . $e->getMessage()];
    }
}
