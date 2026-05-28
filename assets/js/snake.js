// Snake — mini-jeu ArenaForge
// Score → pommes mangées. Vitesse augmente avec le score.

window.initSnake = function () {
    const canvas = document.getElementById('snake-canvas');
    if (!canvas) return;
    // Évite double-init sur le même élément DOM
    if (canvas._snakeInited) return;
    canvas._snakeInited = true;

    const ctx  = canvas.getContext('2d');
    const COLS = 20;
    const ROWS = 20;
    const CELL = canvas.width / COLS;

    let snake, dir, nextDir, apple, score, loopId, running;

    const btnStart    = document.getElementById('snake-start');
    const scoreEl     = document.getElementById('snake-score');
    const overlay     = document.getElementById('snake-overlay');
    const overlayScore = document.getElementById('snake-overlay-score');
    const mgScore     = document.getElementById('mg-score');
    const claimBtn    = document.getElementById('mg-claim-btn');
    const claimMsg    = document.getElementById('mg-claim-msg');

    function speed() { return Math.max(75, 190 - score * 6); }

    function rndPos() {
        let p;
        do {
            p = {
                x: 1 + ((Math.random() * (COLS - 2)) | 0),
                y: 1 + ((Math.random() * (ROWS - 2)) | 0),
            };
        } while (snake.some(s => s.x === p.x && s.y === p.y));
        return p;
    }

    function init() {
        snake   = [{ x: 10, y: 10 }, { x: 9, y: 10 }];
        dir     = { x: 1, y: 0 };
        nextDir = { x: 1, y: 0 };
        apple   = rndPos();
        score   = 0;
        running = true;
        if (overlay)  overlay.style.display  = 'none';
        if (scoreEl)  scoreEl.textContent    = '0';
        if (claimMsg) claimMsg.textContent   = '';
        if (claimBtn) claimBtn.disabled      = true;
        clearInterval(loopId);
        loopId = setInterval(tick, speed());
        draw();
    }

    function tick() {
        dir = nextDir;
        const head = { x: snake[0].x + dir.x, y: snake[0].y + dir.y };

        if (head.x < 0 || head.x >= COLS || head.y < 0 || head.y >= ROWS ||
            snake.some(s => s.x === head.x && s.y === head.y)) {
            endGame();
            return;
        }

        snake.unshift(head);
        if (head.x === apple.x && head.y === apple.y) {
            score++;
            if (scoreEl) scoreEl.textContent = score;
            apple = rndPos();
            clearInterval(loopId);
            loopId = setInterval(tick, speed());
        } else {
            snake.pop();
        }
        draw();
    }

    function draw() {
        ctx.fillStyle = '#1a120a';
        ctx.fillRect(0, 0, canvas.width, canvas.height);

        ctx.strokeStyle = 'rgba(255,255,255,0.04)';
        ctx.lineWidth = 0.5;
        for (let i = 0; i <= COLS; i++) {
            ctx.beginPath(); ctx.moveTo(i * CELL, 0); ctx.lineTo(i * CELL, canvas.height); ctx.stroke();
        }
        for (let j = 0; j <= ROWS; j++) {
            ctx.beginPath(); ctx.moveTo(0, j * CELL); ctx.lineTo(canvas.width, j * CELL); ctx.stroke();
        }

        ctx.fillStyle = '#e84040';
        ctx.shadowColor = 'rgba(232,64,64,0.6)';
        ctx.shadowBlur  = 8;
        ctx.beginPath();
        ctx.arc((apple.x + 0.5) * CELL, (apple.y + 0.5) * CELL, CELL * 0.38, 0, Math.PI * 2);
        ctx.fill();
        ctx.shadowBlur = 0;

        snake.forEach((seg, i) => {
            const isHead = i === 0;
            const fade   = isHead ? 1 : Math.max(0.25, 1 - i / (snake.length + 8));
            ctx.fillStyle = isHead ? '#4fc85a' : `rgba(62,200,112,${fade})`;
            ctx.shadowColor = isHead ? 'rgba(79,200,90,0.5)' : 'none';
            ctx.shadowBlur  = isHead ? 10 : 0;
            const p = 1;
            ctx.fillRect(seg.x * CELL + p, seg.y * CELL + p, CELL - p * 2, CELL - p * 2);

            if (isHead) {
                ctx.shadowBlur = 0;
                ctx.fillStyle  = '#1a120a';
                const ex = dir.x !== 0 ? (dir.x > 0 ? 0.65 : 0.25) : 0.3;
                const ey = dir.y !== 0 ? (dir.y > 0 ? 0.65 : 0.25) : 0.3;
                ctx.fillRect(seg.x * CELL + CELL * ex,       seg.y * CELL + CELL * ey,       2, 2);
                ctx.fillRect(seg.x * CELL + CELL * (1 - ex), seg.y * CELL + CELL * ey,       2, 2);
            }
        });
        ctx.shadowBlur = 0;
    }

    function endGame() {
        clearInterval(loopId);
        running = false;
        draw();

        if (overlayScore) overlayScore.textContent = score;
        if (overlay)      overlay.style.display    = 'flex';
        if (mgScore)      mgScore.value            = score;

        if (claimBtn) {
            claimBtn.disabled    = score < 5;
            claimBtn.textContent = score >= 5 ? 'Réclamer la récompense' : 'Score insuffisant (5 min.)';
        }
    }

    // Clavier — AbortController pour éviter duplicates si HTMX re-init
    if (window._snakeKeyAbort) window._snakeKeyAbort.abort();
    window._snakeKeyAbort = new AbortController();

    const KEYS = {
        ArrowUp: {x:0,y:-1}, ArrowDown: {x:0,y:1}, ArrowLeft: {x:-1,y:0}, ArrowRight: {x:1,y:0},
        w:{x:0,y:-1}, s:{x:0,y:1}, a:{x:-1,y:0}, d:{x:1,y:0},
        z:{x:0,y:-1}, q:{x:-1,y:0},
    };
    document.addEventListener('keydown', (e) => {
        const nd = KEYS[e.key];
        if (!nd) return;
        if (!dir) { if (nd) e.preventDefault(); return; }
        if (running) e.preventDefault();
        if (nd.x !== -dir.x || nd.y !== -dir.y) nextDir = nd;
    }, { signal: window._snakeKeyAbort.signal });

    // Touch swipe sur canvas + prevent scroll pendant jeu
    let tx0, ty0;
    canvas.addEventListener('touchstart', (e) => {
        tx0 = e.touches[0].clientX;
        ty0 = e.touches[0].clientY;
        if (running) e.preventDefault();
    }, { passive: false });
    canvas.addEventListener('touchend', (e) => {
        if (!dir) return;
        const dx = e.changedTouches[0].clientX - tx0;
        const dy = e.changedTouches[0].clientY - ty0;
        if (Math.abs(dx) > Math.abs(dy)) {
            nextDir = dx > 0 ? {x:1,y:0} : {x:-1,y:0};
        } else {
            nextDir = dy > 0 ? {x:0,y:1} : {x:0,y:-1};
        }
        if (nextDir.x === -dir.x && nextDir.y === -dir.y) nextDir = dir;
    }, { passive: true });

    // D-pad mobile
    const dpadDirs = { 'dpad-up': {x:0,y:-1}, 'dpad-down': {x:0,y:1}, 'dpad-left': {x:-1,y:0}, 'dpad-right': {x:1,y:0} };
    Object.entries(dpadDirs).forEach(([id, nd]) => {
        const btn = document.getElementById(id);
        if (!btn) return;
        btn.addEventListener('touchstart', (e) => {
            e.preventDefault();
            if (!dir || !running) return;
            if (nd.x !== -dir.x || nd.y !== -dir.y) nextDir = nd;
        }, { passive: false });
        btn.addEventListener('click', () => {
            if (!dir || !running) return;
            if (nd.x !== -dir.x || nd.y !== -dir.y) nextDir = nd;
        });
    });

    if (btnStart) btnStart.addEventListener('click', init);

    const startOverlay = document.getElementById('snake-start-overlay');
    if (startOverlay && btnStart) startOverlay.addEventListener('click', () => btnStart.click());

    // Dessin initial
    ctx.fillStyle = '#1a120a';
    ctx.fillRect(0, 0, canvas.width, canvas.height);
    ctx.fillStyle = 'rgba(240,193,112,0.5)';
    ctx.font      = `bold ${CELL * 1.1}px sans-serif`;
    ctx.textAlign = 'center';
    ctx.fillText('Appuie sur Démarrer', canvas.width / 2, canvas.height / 2);
};

// Appel immédiat (chargement direct) + après chaque swap HTMX
window.initSnake();
document.addEventListener('htmx:afterSettle', window.initSnake);
