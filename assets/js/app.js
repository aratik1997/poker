const SUIT_SYMBOL = { S: '♠', H: '♥', D: '♦', C: '♣' };
const RANK_LABEL = { T: '10' };

let session = JSON.parse(localStorage.getItem('poker_session') || 'null');
let lastEventSeq = -1;
let lastChatId = 0;
let pollTimer = null;
let unlocked = false;

function unlockAudioOnce() {
    if (unlocked) return;
    unlocked = true;
    PokerSound.unlock();
}
document.addEventListener('click', unlockAudioOnce, { once: true });

function saveSession(s) {
    session = s;
    localStorage.setItem('poker_session', JSON.stringify(s));
}
function clearSession() {
    session = null;
    localStorage.removeItem('poker_session');
}

async function apiPost(path, body) {
    const res = await fetch('api/' + path, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(body || {}),
    });
    const data = await res.json().catch(() => ({}));
    if (!res.ok) throw new Error(data.error || 'Request failed');
    return data;
}
async function apiGet(path, params) {
    const qs = new URLSearchParams(params || {}).toString();
    const res = await fetch('api/' + path + (qs ? '?' + qs : ''));
    const data = await res.json().catch(() => ({}));
    if (!res.ok) throw new Error(data.error || 'Request failed');
    return data;
}

function showScreen(name) {
    document.querySelectorAll('.screen').forEach(el => el.classList.remove('visible'));
    document.getElementById('screen-' + name).classList.add('visible');
}

function cardHTML(card, big) {
    if (!card) return `<div class="card back${big ? ' big' : ''}"></div>`;
    const rank = RANK_LABEL[card[0]] || card[0];
    const suit = card[1];
    const color = (suit === 'H' || suit === 'D') ? 'red' : 'black';
    return `<div class="card ${color}${big ? ' big' : ''}">${rank}${SUIT_SYMBOL[suit]}</div>`;
}

function escapeHtml(s) {
    return s.replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

function renderChat(messages, containerIds) {
    containerIds.forEach(id => {
        const el = document.getElementById(id);
        if (!el) return;
        const atBottom = el.scrollTop + el.clientHeight >= el.scrollHeight - 20;
        messages.forEach(m => {
            const div = document.createElement('div');
            div.className = 'msg' + (m.is_system == 1 ? ' system' : '');
            div.innerHTML = m.is_system == 1
                ? escapeHtml(m.message)
                : `<span class="sender">${escapeHtml(m.sender_name)}:</span> ${escapeHtml(m.message)}`;
            el.appendChild(div);
        });
        if (messages.length && (atBottom || messages.length > 0)) el.scrollTop = el.scrollHeight;
    });
}

/* ---------------- Home screen ---------------- */
document.getElementById('btn-create').addEventListener('click', async () => {
    const name = document.getElementById('create-name').value.trim();
    if (!name) return showHomeError('Enter your name');
    try {
        const data = await apiPost('create_room.php', { name });
        saveSession({ token: data.token, room_code: data.room_code });
        lastChatId = 0; lastEventSeq = -1;
        startPolling();
    } catch (e) { showHomeError(e.message); }
});
document.getElementById('btn-join').addEventListener('click', async () => {
    const name = document.getElementById('join-name').value.trim();
    const code = document.getElementById('join-code').value.trim().toUpperCase();
    if (!name) return showHomeError('Enter your name');
    if (!code) return showHomeError('Enter a room code');
    try {
        const data = await apiPost('join_room.php', { name, room_code: code });
        saveSession({ token: data.token, room_code: data.room_code });
        lastChatId = 0; lastEventSeq = -1;
        startPolling();
    } catch (e) { showHomeError(e.message); }
});
function showHomeError(msg) { document.getElementById('home-error').textContent = msg; }

/* ---------------- Rules modal ---------------- */
function openRules() { document.getElementById('modal-rules').classList.add('visible'); }
function closeRules() { document.getElementById('modal-rules').classList.remove('visible'); }
['btn-open-rules', 'btn-open-rules-lobby', 'btn-open-rules-game'].forEach(id => {
    document.getElementById(id).addEventListener('click', openRules);
});
document.getElementById('btn-close-rules').addEventListener('click', closeRules);
document.getElementById('modal-rules').addEventListener('click', e => {
    if (e.target.id === 'modal-rules') closeRules();
});

/* ---------------- Kicked overlay ---------------- */
function showKicked(message) {
    stopPolling();
    document.getElementById('kick-message').textContent = message;
    document.getElementById('kick-overlay').classList.add('visible');
}
document.getElementById('btn-kick-ok').addEventListener('click', () => {
    document.getElementById('kick-overlay').classList.remove('visible');
    clearSession();
    showScreen('home');
});

/* ---------------- Lobby & Leave ---------------- */
document.getElementById('btn-leave-lobby').addEventListener('click', () => leaveRoom());
document.getElementById('btn-leave-game').addEventListener('click', () => leaveRoom());
async function leaveRoom() {
    stopPolling();
    try { await apiPost('leave_room.php', { token: session.token }); } catch (e) { /* ignore */ }
    clearSession();
    showScreen('home');
}

document.getElementById('btn-start-game').addEventListener('click', async () => {
    try { await apiPost('start_game.php', { token: session.token }); }
    catch (e) { document.getElementById('lobby-error').textContent = e.message; }
});

document.getElementById('btn-new-round').addEventListener('click', async () => {
    if (!confirm('Cancel this hand and deal a new one? All current bets will be refunded.')) return;
    try { await apiPost('new_round.php', { token: session.token }); } catch (e) { alert(e.message); }
});

/* ---------------- Chat forms ---------------- */
function wireChatForm(formId, inputId) {
    document.getElementById(formId).addEventListener('submit', async e => {
        e.preventDefault();
        const input = document.getElementById(inputId);
        const message = input.value.trim();
        if (!message) return;
        input.value = '';
        try { await apiPost('send_chat.php', { token: session.token, message }); } catch (e) { /* ignore */ }
    });
}
wireChatForm('lobby-chat-form', 'lobby-chat-input');
wireChatForm('game-chat-form', 'game-chat-input');

/* ---------------- Polling ---------------- */
function startPolling() {
    poll();
    if (pollTimer) clearInterval(pollTimer);
    pollTimer = setInterval(poll, 1200);
}
function stopPolling() {
    if (pollTimer) clearInterval(pollTimer);
    pollTimer = null;
}

async function poll() {
    if (!session) return;
    try {
        const data = await apiGet('get_state.php', { token: session.token, since_chat: lastChatId });
        if (data.kicked) {
            showKicked(data.message);
            return;
        }
        handleState(data);
    } catch (e) {
        stopPolling();
        clearSession();
        showScreen('home');
        showHomeError('Disconnected: ' + e.message);
    }
}

function handleState(data) {
    if (data.chat && data.chat.length) {
        renderChat(data.chat, ['lobby-chat-messages', 'game-chat-messages']);
        lastChatId = data.chat[data.chat.length - 1].id;
    }

    if (data.room.event_seq !== lastEventSeq) {
        if (lastEventSeq !== -1) playEventSound(data.room.event_type);
        lastEventSeq = data.room.event_seq;
    }

    if (data.room.status === 'lobby') {
        showScreen('lobby');
        renderLobby(data);
    } else {
        showScreen('game');
        renderGame(data);
    }
}

function playEventSound(type) {
    if (type === 'deal_hole') PokerSound.dealHole();
    else if (type === 'flop' || type === 'turn' || type === 'river') PokerSound.playCard();
    else if (type === 'showdown') PokerSound.showCards();
    else if (type === 'action') PokerSound.chipBet();
}

/* ---------------- Lobby rendering ---------------- */
let botControlsWired = false;

function renderLobby(data) {
    document.getElementById('lobby-code').textContent = data.room.code;

    if (!botControlsWired) {
        botControlsWired = true;
        document.getElementById('add-bot-btn').addEventListener('click', async () => {
            const btn = document.getElementById('add-bot-btn');
            btn.disabled = true;
            try {
                await apiPost('add_bot.php', { token: session.token, difficulty: document.getElementById('bot-difficulty').value });
            } catch (e) {
                alert(e.message);
            } finally {
                btn.disabled = false;
            }
        });
    }
    document.getElementById('lobby-bot-row').style.display = data.you.is_host ? 'flex' : 'none';

    const list = document.getElementById('lobby-players');
    list.innerHTML = '';
    data.players.forEach(p => {
        const li = document.createElement('li');
        const canKick = data.you.is_host && !p.is_me;
        li.innerHTML = `
            <span class="player-name-tag">
                ${escapeHtml(p.name)}
                ${p.is_host ? '<span class="host-badge">HOST</span>' : ''}
                ${p.is_me ? '<span class="you-badge">(you)</span>' : ''}
                ${p.is_bot ? `<span class="host-badge">🤖 ${escapeHtml(p.bot_difficulty || 'bot')}</span>` : ''}
            </span>
            <span style="display:flex;align-items:center;gap:10px">
                <span style="color:var(--gold-light);font-size:13px">${p.chips} chips</span>
                ${canKick ? `<button class="btn-danger btn-small" data-kick="${p.id}">Kick</button>` : ''}
            </span>`;
        list.appendChild(li);
    });
    list.querySelectorAll('[data-kick]').forEach(btn => {
        btn.addEventListener('click', async () => {
            if (!confirm('Kick this player?')) return;
            try { await apiPost('kick_player.php', { token: session.token, player_id: btn.dataset.kick }); }
            catch (e) { alert(e.message); }
        });
    });

    const startBtn = document.getElementById('btn-start-game');
    startBtn.style.display = data.you.is_host ? 'inline-block' : 'none';
    startBtn.disabled = data.players.length < 2;
    document.getElementById('btn-new-round').style.display = 'none';
}

/* ---------------- Game rendering ---------------- */
function renderGame(data) {
    document.getElementById('game-code').textContent = data.room.code;
    document.getElementById('btn-new-round').style.display = data.you.is_host ? 'inline-block' : 'none';

    document.getElementById('community-cards').innerHTML =
        data.room.community_cards.map(c => cardHTML(c, true)).join('');
    document.getElementById('pot-display').textContent = 'Pot: ' + data.room.pot;

    const phaseLabels = { waiting: 'Waiting for players', preflop: 'Pre-Flop', flop: 'Flop', turn: 'Turn', river: 'River', showdown: 'Showdown' };
    document.getElementById('phase-display').textContent = phaseLabels[data.room.phase] || data.room.phase;

    renderSeats(data);
    renderScoreboard(data.scoreboard);
    renderActionBar(data);
    renderTurnBanner(data);
}

function renderTurnBanner(data) {
    const el = document.getElementById('turn-banner');
    if (data.room.phase === 'showdown' && data.room.last_result) {
        el.textContent = data.room.last_result.text
            ? data.room.last_result.text
            : (data.room.last_result.winner + ' wins ' + data.room.last_result.amount + ' chips');
    } else if (data.you.status === 'spectating') {
        el.textContent = "You're spectating — you'll join the next hand.";
    } else if (data.you.status === 'folded') {
        el.textContent = 'You folded this hand.';
    } else if (data.you.is_your_turn) {
        el.textContent = 'Your turn!';
    } else if (data.room.current_turn_seat !== null) {
        const p = data.players.find(pl => pl.seat === data.room.current_turn_seat);
        el.textContent = p ? `Waiting for ${p.name}...` : '';
    } else {
        el.textContent = '';
    }
}

function renderSeats(data) {
    const table = document.getElementById('poker-table');
    table.querySelectorAll('.seat').forEach(s => s.remove());

    const seated = data.players.filter(p => p.seat !== null).sort((a, b) => a.seat - b.seat);
    if (seated.length === 0) return;

    let meIndex = seated.findIndex(p => p.is_me);
    if (meIndex === -1) meIndex = 0;
    const ordered = seated.slice(meIndex).concat(seated.slice(0, meIndex));
    const n = ordered.length;

    ordered.forEach((p, i) => {
        const angle = (90 + (360 / n) * i) * Math.PI / 180;
        const left = 50 + 45 * Math.cos(angle);
        const top = 50 + 40 * Math.sin(angle);

        const seatDiv = document.createElement('div');
        seatDiv.className = 'seat' + (p.seat === data.room.current_turn_seat ? ' turn' : '') +
            (p.status === 'folded' ? ' folded' : '') + (p.is_me ? ' me' : '');
        seatDiv.style.left = left + '%';
        seatDiv.style.top = top + '%';

        let cardsHtml = '';
        if (p.is_me && data.you.hole_cards) {
            cardsHtml = data.you.hole_cards.map(c => cardHTML(c)).join('');
        } else if (p.hole_cards) {
            cardsHtml = p.hole_cards.map(c => cardHTML(c)).join('');
        } else if (p.card_count > 0) {
            cardsHtml = cardHTML(null) + cardHTML(null);
        }

        const canKick = data.you.is_host && !p.is_me;
        seatDiv.innerHTML = `
            <div class="seat-box">
                ${p.seat === data.room.dealer_seat ? '<div class="dealer-chip">D</div>' : ''}
                ${canKick ? `<button class="btn-danger btn-small seat-kick" data-kick="${p.id}" title="Kick ${escapeHtml(p.name)}">Kick</button>` : ''}
                <div class="seat-name">${escapeHtml(p.name)}${p.is_me ? ' (you)' : ''}</div>
                <div class="seat-chips">${p.chips} chips</div>
                <div class="seat-action">${p.status === 'folded' ? 'Folded' : (p.last_action || '')}</div>
                <div class="seat-cards">${cardsHtml}</div>
                ${p.bet_this_round > 0 ? `<div class="seat-bet">${p.bet_this_round}</div>` : ''}
            </div>`;
        table.appendChild(seatDiv);
    });

    table.querySelectorAll('[data-kick]').forEach(btn => {
        btn.addEventListener('click', async (e) => {
            e.stopPropagation();
            if (!confirm('Kick this player?')) return;
            try { await apiPost('kick_player.php', { token: session.token, player_id: btn.dataset.kick }); }
            catch (err) { alert(err.message); }
        });
    });
}

function renderScoreboard(scoreboard) {
    const list = document.getElementById('scoreboard-list');
    list.innerHTML = scoreboard.map((p, i) => `
        <li><span><span class="rank">#${i + 1}</span>${escapeHtml(p.name)}</span><span>${p.chips}</span></li>
    `).join('');
}

let wasYourTurn = false;
function renderActionBar(data) {
    const bar = document.getElementById('action-bar');
    bar.innerHTML = '';

    if (!data.you.is_your_turn) {
        wasYourTurn = false;
        return;
    }
    if (!wasYourTurn) { PokerSound.yourTurn(); wasYourTurn = true; }

    const toCall = data.you.to_call;
    const chips = data.you.chips;

    const foldBtn = document.createElement('button');
    foldBtn.className = 'btn-secondary';
    foldBtn.textContent = 'Fold';
    foldBtn.onclick = () => sendAction('fold');
    bar.appendChild(foldBtn);

    const callBtn = document.createElement('button');
    callBtn.className = 'btn-primary';
    if (toCall === 0) {
        callBtn.textContent = 'Check';
        callBtn.onclick = () => sendAction('check');
    } else {
        callBtn.textContent = `Call ${Math.min(toCall, chips)}`;
        callBtn.onclick = () => sendAction('call');
    }
    bar.appendChild(callBtn);

    if (chips > toCall) {
        const maxTotal = chips + data.you.bet_this_round;
        const minTotal = Math.min(data.you.min_raise_total, maxTotal);
        const wrap = document.createElement('div');
        wrap.className = 'bet-input-wrap';
        const input = document.createElement('input');
        input.type = 'number';
        input.min = 1;
        input.max = maxTotal;
        input.value = minTotal;
        wrap.appendChild(input);

        const betBtn = document.createElement('button');
        betBtn.className = 'btn-primary';
        betBtn.textContent = data.room.current_bet > 0 ? 'Raise to' : 'Bet';
        betBtn.onclick = () => {
            const totalThisRound = parseInt(input.value, 10) || 0;
            sendAction(data.room.current_bet > 0 ? 'raise' : 'bet', totalThisRound);
        };
        wrap.appendChild(betBtn);
        bar.appendChild(wrap);
    }

    const allinBtn = document.createElement('button');
    allinBtn.className = 'btn-danger';
    allinBtn.textContent = 'All-In';
    allinBtn.onclick = () => sendAction('allin');
    bar.appendChild(allinBtn);
}

async function sendAction(action, amount) {
    try {
        await apiPost('player_action.php', { token: session.token, action, amount: amount || 0 });
    } catch (e) { alert(e.message); }
}

/* ---------------- Boot ---------------- */
if (session) {
    startPolling();
} else {
    showScreen('home');
}
