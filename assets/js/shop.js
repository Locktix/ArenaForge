// ArenaForge — Boutique d'armes
(function () {
    const BASE_URL = (function () {
        const el = document.querySelector('script[src*="shop.js"]');
        return el ? el.src.replace(/\/assets\/js\/shop\.js(\?.*)?$/, '') : '';
    })();

    function navigate(url) {
        if (window.arenaNavigate) window.arenaNavigate(url);
        else window.location.href = url;
    }

    document.querySelectorAll('.shop-buy-btn:not([disabled])').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const name  = btn.dataset.weaponName;
            const price = btn.dataset.price;
            if (!confirm('Acheter ' + name + ' pour ' + price + ' or ?')) return;

            btn.disabled = true;
            btn._orig = btn.textContent;
            btn.textContent = '⏳';

            const fd = new FormData();
            fd.append('csrf',      btn.dataset.csrf);
            fd.append('weapon_id', btn.dataset.weaponId);

            fetch(BASE_URL + '/api/shop_buy.php', { method: 'POST', body: fd })
                .then(r => r.json())
                .then(function (data) {
                    if (data.ok) {
                        if (window.Toast) {
                            window.Toast.queue([{
                                title:       '⚒ ' + data.weapon_name + ' acquis !',
                                description: data.price + ' or dépensés.',
                                icon_path:   'assets/svg/ui/nav_fight.svg',
                            }]);
                        }
                        navigate(data.redirect || 'shop.php');
                    } else {
                        btn.disabled  = false;
                        btn.textContent = btn._orig;
                        alert(data.error || 'Erreur lors de l\'achat.');
                    }
                })
                .catch(function () {
                    btn.disabled  = false;
                    btn.textContent = btn._orig;
                    alert('Erreur réseau.');
                });
        });
    });
})();
