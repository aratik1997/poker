// Small synthesized sound effects (Web Audio API) - no external audio files needed.
const PokerSound = (() => {
    let ctx = null;

    function ensureCtx() {
        if (!ctx) {
            const AC = window.AudioContext || window.webkitAudioContext;
            ctx = new AC();
        }
        if (ctx.state === 'suspended') ctx.resume();
        return ctx;
    }

    function tone(freq, start, duration, type = 'sine', gainPeak = 0.18) {
        const c = ensureCtx();
        const osc = c.createOscillator();
        const gain = c.createGain();
        osc.type = type;
        osc.frequency.value = freq;
        gain.gain.setValueAtTime(0, c.currentTime + start);
        gain.gain.linearRampToValueAtTime(gainPeak, c.currentTime + start + 0.01);
        gain.gain.exponentialRampToValueAtTime(0.001, c.currentTime + start + duration);
        osc.connect(gain).connect(c.destination);
        osc.start(c.currentTime + start);
        osc.stop(c.currentTime + start + duration + 0.02);
    }

    // A short percussive "snap" - used to imitate a card sliding/flipping.
    function cardSnap(start = 0, gainPeak = 0.22) {
        const c = ensureCtx();
        const bufferSize = c.sampleRate * 0.06;
        const buffer = c.createBuffer(1, bufferSize, c.sampleRate);
        const data = buffer.getChannelData(0);
        for (let i = 0; i < bufferSize; i++) {
            data[i] = (Math.random() * 2 - 1) * (1 - i / bufferSize);
        }
        const noise = c.createBufferSource();
        noise.buffer = buffer;
        const filter = c.createBiquadFilter();
        filter.type = 'highpass';
        filter.frequency.value = 1200;
        const gain = c.createGain();
        gain.gain.setValueAtTime(gainPeak, c.currentTime + start);
        gain.gain.exponentialRampToValueAtTime(0.001, c.currentTime + start + 0.08);
        noise.connect(filter).connect(gain).connect(c.destination);
        noise.start(c.currentTime + start);
    }

    return {
        unlock() { ensureCtx(); },
        // Drawing/dealing hole cards to players.
        dealHole() {
            for (let i = 0; i < 4; i++) cardSnap(i * 0.09, 0.16);
        },
        // Playing a community card onto the table (flop/turn/river).
        playCard() {
            cardSnap(0, 0.24);
            tone(520, 0.03, 0.12, 'triangle', 0.08);
        },
        // Revealing hands at showdown.
        showCards() {
            cardSnap(0, 0.2);
            tone(660, 0.05, 0.18, 'sine', 0.12);
            tone(880, 0.15, 0.22, 'sine', 0.1);
        },
        chipBet() {
            tone(300, 0, 0.09, 'square', 0.06);
            tone(420, 0.05, 0.09, 'square', 0.05);
        },
        fold() {
            tone(220, 0, 0.16, 'sine', 0.08);
        },
        yourTurn() {
            tone(740, 0, 0.1, 'sine', 0.1);
            tone(950, 0.12, 0.14, 'sine', 0.1);
        },
    };
})();
