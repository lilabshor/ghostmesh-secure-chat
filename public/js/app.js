const GhostMeshApp = (() => {
    function getWebSocketUrl() {
        if (location.port && location.port !== '80' && location.port !== '443') {
            return `ws://${location.hostname}:9000`;
        }
        const proto = location.protocol === 'https:' ? 'wss:' : 'ws:';
        return `${proto}//${location.host}/ws`;
    }

    const WS_URL = getWebSocketUrl();

    // Strict in-memory state (Zero Trace, nothing written to disk/localStorage)
    const state = {
        myKeyPair: null,
        myRoutingToken: null,
        myFingerprint: null,
        activePeerToken: null,
        filterQuery: '',
        conversations: {}
    };

    let ws = null;
    let callStart = null;
    let callInterval = null;

    function el(id) {
        return document.getElementById(id);
    }

    function setStatus(statusClass, text) {
        const dot = el('status-dot');
        const label = el('status-text');
        if (dot) dot.className = statusClass;
        if (label) label.textContent = text;
    }

    function toast(msg, type = '') {
        const container = el('toast-container');
        if (!container) return;

        const div = document.createElement('div');
        div.className = 'toast' + (type ? ' ' + type : '');
        div.textContent = msg;
        container.appendChild(div);

        setTimeout(() => {
            div.style.animation = 'toast-out 200ms ease forwards';
            setTimeout(() => div.remove(), 210);
        }, 3000);
    }

    function formatTime(timestamp) {
        return new Date(timestamp).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
    }

    function burnLabel(policy) {
        const map = {
            'BURN_ON_READ': 'burns 5s',
            'BURN_30S': 'burns 30s',
            'BURN_5M': 'burns 5m',
            'NEVER': ''
        };
        return map[policy] || '';
    }

    // ------------------------------------------------------------------
    // Identity Initialization
    // ------------------------------------------------------------------
    async function initIdentity() {
        state.myKeyPair = await CryptoEngine.generateKeyPair();
        state.myRoutingToken = CryptoEngine.randomHex(16);
        state.myFingerprint = (await CryptoEngine.sha256Hex(state.myKeyPair.publicKeyHex)).slice(0, 16);

        el('my-token-display').textContent = state.myRoutingToken;
        el('fingerprint-badge').textContent = state.myFingerprint;
        el('fingerprint-badge').style.display = 'block';
    }

    // ------------------------------------------------------------------
    // WebSocket Transport
    // ------------------------------------------------------------------
    function connectRelay() {
        setStatus('connecting', 'connecting');
        ws = new WebSocket(WS_URL);

        ws.onopen = () => {};

        ws.onmessage = (e) => {
            let msg;
            try {
                msg = JSON.parse(e.data);
            } catch {
                return;
            }
            handleRelayMessage(msg);
        };

        ws.onclose = () => {
            el('pow-overlay').classList.add('hidden');
            setStatus('error', 'disconnected');
            setTimeout(connectRelay, 3000);
        };

        ws.onerror = () => {
            el('pow-overlay').classList.add('hidden');
            setStatus('error', 'error');
        };
    }

    function send(payload) {
        if (ws && ws.readyState === WebSocket.OPEN) {
            ws.send(JSON.stringify(payload));
        }
    }

    async function handleRelayMessage(msg) {
        switch (msg.type) {
            case 'POW_CHALLENGE':
                await handlePoWChallenge(msg.challenge_seed, msg.difficulty_bits);
                break;

            case 'POW_ACCEPTED':
                el('pow-overlay').classList.add('hidden');
                setStatus('connected', 'relay connected');
                send({ type: 'REGISTER_INBOX', routing_token: state.myRoutingToken });
                break;

            case 'INBOX_REGISTERED':
                break;

            case 'INCOMING_MESSAGE':
                await handleIncomingEnvelope(msg.envelope);
                break;

            case 'WEBRTC_OFFER':
                await WebRTCMesh.handleIncomingOffer(msg.encrypted_offer, msg.session_uuid);
                break;

            case 'WEBRTC_ANSWER':
                await WebRTCMesh.handleAnswer(msg.encrypted_answer);
                break;

            case 'WEBRTC_ICE':
                await WebRTCMesh.handleIceCandidate(msg.candidate);
                break;

            case 'ERROR':
                toast('relay: ' + msg.code, 'error');
                break;

            default:
                break;
        }
    }

    async function handlePoWChallenge(seed, bits) {
        el('pow-overlay').classList.remove('hidden');
        el('pow-progress-bar').style.width = '0%';

        let iteration = 0;
        const updateUi = (nonce) => {
            iteration++;
            const pct = Math.min(95, iteration / 100 * 100);
            el('pow-progress-bar').style.width = pct + '%';
            el('pow-nonce-display').textContent = 'nonce: ' + nonce.toString(16);
        };

        const nonce = await CryptoEngine.solvePoW(seed, bits, updateUi);
        el('pow-progress-bar').style.width = '100%';
        send({ type: 'POW_SOLUTION', nonce });
    }

    // ------------------------------------------------------------------
    // ------------------------------------------------------------------
    // Multi-Conversation Management
    // ------------------------------------------------------------------
    function getContactDisplay(conv) {
        let hash = 0;
        const token = conv.peerToken || '00';
        for (let i = 0; i < Math.min(token.length, 8); i++) {
            hash = (hash * 31 + token.charCodeAt(i)) >>> 0;
        }
        const hue = hash % 360;
        const color = `hsl(${hue}, 90%, 65%)`;
        const bgColor = `hsla(${hue}, 90%, 65%, 0.12)`;
        const glow = `0 0 8px hsla(${hue}, 90%, 65%, 0.3)`;

        let monogram = '';
        if (conv.isCustomAlias && conv.alias) {
            const words = conv.alias.trim().split(/\s+/);
            if (words.length > 1) {
                monogram = (words[0][0] + words[1][0]).toUpperCase();
            } else {
                monogram = conv.alias.slice(0, 2).toUpperCase();
            }
        } else {
            // First 2 hex digits of the peer token (e.g. 60, 83)
            monogram = token.slice(0, 2).toUpperCase();
        }

        return {
            monogram: monogram || 'AN',
            color: color,
            bgColor: bgColor,
            glow: glow
        };
    }

    function getOrCreateConversation(peerToken, aliasHint = '') {
        if (!state.conversations[peerToken]) {
            const hasCustom = Boolean(aliasHint && !aliasHint.startsWith('Node ') && !aliasHint.startsWith('Anonymous'));
            const defaultAlias = 'Anonymous #' + peerToken.slice(0, 6);
            const shortAlias = hasCustom ? aliasHint : defaultAlias;
            state.conversations[peerToken] = {
                peerToken: peerToken,
                peerPublicKeyHex: null,
                alias: shortAlias,
                isCustomAlias: hasCustom,
                sessionKey: null,
                status: 'pending',
                curve: 'X25519',
                burnPolicy: 'BURN_30S',
                unreadCount: 0,
                lastUpdated: Date.now(),
                lastSnippet: 'Channel created',
                messages: []
            };
        }
        return state.conversations[peerToken];
    }

    function scheduleMessageBurn(convToken, msgId, policy) {
        let delayMs = 0;
        if (policy === 'BURN_ON_READ') delayMs = 5000;
        else if (policy === 'BURN_30S') delayMs = 30000;
        else if (policy === 'BURN_5M') delayMs = 300000;
        else return;

        setTimeout(() => {
            const conv = state.conversations[convToken];
            if (conv) {
                conv.messages = conv.messages.filter(m => m.id !== msgId);
            }

            const row = document.querySelector(`[data-msg-id="${msgId}"]`);
            if (row) {
                row.style.transition = 'opacity 400ms ease';
                row.style.opacity = '0';
                setTimeout(() => row.remove(), 420);
            }
        }, delayMs);
    }

    async function handleIncomingEnvelope(envelope) {
        if (!envelope || !envelope.sender_token) return;

        const senderToken = envelope.sender_token;

        // Case A: Handshake initiation from a new peer
        if (envelope.type === 'HANDSHAKE_INIT') {
            const conv = getOrCreateConversation(senderToken, envelope.sender_alias);
            conv.peerPublicKeyHex = envelope.sender_pub;

            try {
                const peerPub = await CryptoEngine.importPeerPublicKey(envelope.sender_pub);
                const sharedBits = await CryptoEngine.deriveSharedBits(state.myKeyPair.privateKey, peerPub);
                conv.sessionKey = await CryptoEngine.hkdfDeriveKey(sharedBits);
                conv.status = 'established';
                conv.lastSnippet = 'Session established';
                conv.lastUpdated = Date.now();

                const sysMsg = {
                    id: CryptoEngine.randomHex(8),
                    direction: 'system',
                    text: 'session established — forward secrecy active',
                    timestamp: Date.now()
                };
                conv.messages.push(sysMsg);

                if (state.activePeerToken !== senderToken) {
                    conv.unreadCount += 1;
                }

                // Reply with HANDSHAKE_RESP
                send({
                    type: 'SEND_MESSAGE',
                    routing_token: senderToken,
                    nonce: CryptoEngine.randomHex(12),
                    envelope: {
                        type: 'HANDSHAKE_RESP',
                        sender_token: state.myRoutingToken,
                        sender_pub: state.myKeyPair.publicKeyHex,
                        sender_alias: 'Anonymous #' + state.myRoutingToken.slice(0, 6)
                    }
                });

                renderSidebar();
                if (state.activePeerToken === senderToken) {
                    renderActiveChat();
                }
                toast('connected with ' + conv.alias, 'success');
            } catch (err) {
                console.error('Handshake init error:', err);
                toast('handshake failed with ' + senderToken.slice(0, 8), 'error');
            }
            return;
        }

        // Case B: Peer responded to our handshake
        if (envelope.type === 'HANDSHAKE_RESP') {
            const conv = state.conversations[senderToken];
            if (!conv) return;

            conv.peerPublicKeyHex = envelope.sender_pub;

            try {
                const peerPub = await CryptoEngine.importPeerPublicKey(envelope.sender_pub);
                const sharedBits = await CryptoEngine.deriveSharedBits(state.myKeyPair.privateKey, peerPub);
                conv.sessionKey = await CryptoEngine.hkdfDeriveKey(sharedBits);
                conv.status = 'established';
                conv.lastSnippet = 'Session established';
                conv.lastUpdated = Date.now();

                conv.messages.push({
                    id: CryptoEngine.randomHex(8),
                    direction: 'system',
                    text: 'peer verified — session established',
                    timestamp: Date.now()
                });

                renderSidebar();
                if (state.activePeerToken === senderToken) {
                    renderActiveChat();
                }
                toast('connected with ' + conv.alias, 'success');
            } catch (err) {
                console.error('Handshake resp error:', err);
                toast('handshake derivation failed', 'error');
            }
            return;
        }

        // Case C: Encrypted text message
        if (envelope.type === 'MSG') {
            const conv = state.conversations[senderToken];
            if (!conv || !conv.sessionKey) {
                toast('received message before key exchange', 'error');
                return;
            }

            try {
                const plaintext = await CryptoEngine.decrypt(
                    envelope.ciphertext,
                    envelope.nonce,
                    conv.sessionKey,
                    senderToken
                );

                const msgObj = {
                    id: CryptoEngine.randomHex(8),
                    direction: 'incoming',
                    text: plaintext,
                    timestamp: Date.now(),
                    policy: envelope.burn_policy
                };

                conv.messages.push(msgObj);
                conv.lastSnippet = plaintext;
                conv.lastUpdated = Date.now();

                if (state.activePeerToken === senderToken) {
                    appendMessageBubble(msgObj);
                    conv.unreadCount = 0;
                } else {
                    conv.unreadCount += 1;
                    toast(conv.alias + ': ' + (plaintext.length > 24 ? plaintext.slice(0, 24) + '...' : plaintext));
                }

                if (envelope.burn_policy === 'BURN_ON_READ') {
                    send({ type: 'BURN_ACK', routing_token: state.myRoutingToken });
                }

                scheduleMessageBurn(senderToken, msgObj.id, envelope.burn_policy);
                renderSidebar();
            } catch (err) {
                console.error('Decryption error:', err);
                toast('decryption failed for incoming message', 'error');
            }
        }
    }

    // ------------------------------------------------------------------
    // Channel Initiation (New Chat)
    // ------------------------------------------------------------------
    function initiateNewChat(targetToken, aliasInput = '') {
        const token = targetToken.trim();
        if (!token || token.length < 16) {
            toast('invalid routing token (minimum 16 hex chars)', 'error');
            return false;
        }

        if (token === state.myRoutingToken) {
            toast('cannot establish channel with your own token', 'error');
            return false;
        }

        let conv = state.conversations[token];
        if (!conv) {
            const hasCustom = Boolean(aliasInput && aliasInput.trim());
            const alias = hasCustom ? aliasInput.trim() : ('Anonymous #' + token.slice(0, 6));
            conv = getOrCreateConversation(token, alias);
            conv.isCustomAlias = hasCustom;
            conv.messages.push({
                id: CryptoEngine.randomHex(8),
                direction: 'system',
                text: 'initiating zero-knowledge channel to ' + token.slice(0, 12) + '...',
                timestamp: Date.now()
            });

            // Dispatch handshake init via relay
            send({
                type: 'SEND_MESSAGE',
                routing_token: token,
                nonce: CryptoEngine.randomHex(12),
                envelope: {
                    type: 'HANDSHAKE_INIT',
                    sender_token: state.myRoutingToken,
                    sender_pub: state.myKeyPair.publicKeyHex,
                    sender_alias: 'Anonymous #' + state.myRoutingToken.slice(0, 6)
                }
            });
        }

        selectConversation(token);
        toast('handshake transmitted', 'success');
        return true;
    }

    // ------------------------------------------------------------------
    // Send Outgoing Message
    // ------------------------------------------------------------------
    async function sendMessage(plaintext) {
        if (!state.activePeerToken) return;
        const conv = state.conversations[state.activePeerToken];
        if (!conv || !conv.sessionKey) {
            toast('cannot send: session key not established yet', 'error');
            return;
        }

        try {
            const { ciphertextHex, nonceHex } = await CryptoEngine.encrypt(
                plaintext,
                conv.sessionKey,
                state.myRoutingToken
            );

            const msgObj = {
                id: CryptoEngine.randomHex(8),
                direction: 'outgoing',
                text: plaintext,
                timestamp: Date.now(),
                policy: conv.burnPolicy
            };

            conv.messages.push(msgObj);
            conv.lastSnippet = plaintext;
            conv.lastUpdated = Date.now();

            send({
                type: 'SEND_MESSAGE',
                routing_token: conv.peerToken,
                nonce: CryptoEngine.randomHex(12),
                envelope: {
                    type: 'MSG',
                    sender_token: state.myRoutingToken,
                    ciphertext: ciphertextHex,
                    nonce: nonceHex,
                    burn_policy: conv.burnPolicy
                }
            });

            appendMessageBubble(msgObj);
            scheduleMessageBurn(conv.peerToken, msgObj.id, conv.burnPolicy);
            renderSidebar();
        } catch (err) {
            console.error('Send error:', err);
            toast('encryption or send failed', 'error');
        }
    }

    // ------------------------------------------------------------------
    // UI Rendering
    // ------------------------------------------------------------------
    function renderSidebar() {
        const listContainer = el('conversation-list');
        const emptyState = el('conv-empty-state');
        const countBadge = el('conv-count');

        const tokens = Object.keys(state.conversations);
        if (countBadge) countBadge.textContent = String(tokens.length);

        if (tokens.length === 0) {
            listContainer.innerHTML = '';
            if (emptyState) listContainer.appendChild(emptyState);
            return;
        }

        // Filter and sort conversations by last activity descending
        const q = state.filterQuery.toLowerCase();
        const filtered = tokens
            .map(t => state.conversations[t])
            .filter(c => c.alias.toLowerCase().includes(q) || c.peerToken.toLowerCase().includes(q))
            .sort((a, b) => b.lastUpdated - a.lastUpdated);

        listContainer.innerHTML = '';

        if (filtered.length === 0) {
            const noMatch = document.createElement('div');
            noMatch.className = 'conv-empty';
            noMatch.textContent = 'No matching channels found.';
            listContainer.appendChild(noMatch);
            return;
        }

        filtered.forEach(conv => {
            const item = document.createElement('div');
            item.className = 'conv-item' + (conv.peerToken === state.activePeerToken ? ' active' : '');
            item.dataset.token = conv.peerToken;

            const visual = getContactDisplay(conv);

            let statusTagHtml = '';
            if (conv.status === 'established') {
                statusTagHtml = '<span class="conv-status-tag established">e2ee</span>';
            } else {
                statusTagHtml = '<span class="conv-status-tag">pending</span>';
            }

            let unreadHtml = '';
            if (conv.unreadCount > 0) {
                unreadHtml = `<span class="conv-unread">${conv.unreadCount}</span>`;
            }

            item.innerHTML = `
                <div class="conv-avatar" style="color: ${visual.color}; border-color: ${visual.color}; background: ${visual.bgColor}; box-shadow: ${visual.glow};">${visual.monogram}</div>
                <div class="conv-body">
                    <div class="conv-header">
                        <span class="conv-title">${escapeHtml(conv.alias)}</span>
                        <span class="conv-time">${formatTime(conv.lastUpdated)}</span>
                    </div>
                    <div class="conv-sub">
                        <span class="conv-preview">${escapeHtml(conv.lastSnippet)}</span>
                        ${statusTagHtml}
                        ${unreadHtml}
                    </div>
                </div>
            `;

            item.addEventListener('click', () => {
                selectConversation(conv.peerToken);
            });

            listContainer.appendChild(item);
        });
    }

    function selectConversation(peerToken) {
        state.activePeerToken = peerToken;
        const conv = state.conversations[peerToken];
        if (conv) {
            conv.unreadCount = 0;
        }

        renderSidebar();
        renderActiveChat();
    }

    function renderActiveChat() {
        const header = el('chat-header');
        const emptyState = el('empty-state');
        const messagesContainer = el('messages');
        const composeInput = el('compose-input');
        const btnSend = el('btn-send');

        if (!state.activePeerToken || !state.conversations[state.activePeerToken]) {
            header.classList.add('hidden');
            messagesContainer.innerHTML = '';
            if (emptyState) messagesContainer.appendChild(emptyState);
            composeInput.disabled = true;
            btnSend.disabled = true;
            composeInput.placeholder = 'select a channel to message';
            return;
        }

        const conv = state.conversations[state.activePeerToken];
        header.classList.remove('hidden');

        const visual = getContactDisplay(conv);
        const avatarEl = el('chat-peer-avatar');
        avatarEl.textContent = visual.monogram;
        avatarEl.style.color = visual.color;
        avatarEl.style.borderColor = visual.color;
        avatarEl.style.background = visual.bgColor;
        avatarEl.style.boxShadow = visual.glow;

        el('chat-peer-name').textContent = conv.alias;
        el('chat-peer-name').title = 'Click to rename alias';
        el('chat-peer-token').textContent = 'Token: ' + conv.peerToken.slice(0, 16) + '... (click to copy)';

        // Update burn chips selection
        document.querySelectorAll('#chat-burn-chips .burn-chip').forEach(chip => {
            chip.classList.toggle('active', chip.dataset.policy === conv.burnPolicy);
        });

        const isReady = conv.status === 'established';
        composeInput.disabled = !isReady;
        btnSend.disabled = !isReady;
        composeInput.placeholder = isReady ? 'type a message — end-to-end encrypted' : 'awaiting peer key exchange...';

        // Render message list
        messagesContainer.innerHTML = '';
        if (conv.messages.length === 0) {
            const noMsg = document.createElement('div');
            noMsg.className = 'conv-empty';
            noMsg.textContent = 'Channel initiated. Say hello!';
            messagesContainer.appendChild(noMsg);
        } else {
            conv.messages.forEach(msg => appendMessageBubble(msg));
        }

        messagesContainer.scrollTop = messagesContainer.scrollHeight;
    }

    function appendMessageBubble(msg) {
        const messagesContainer = el('messages');
        if (!messagesContainer) return;

        const empty = messagesContainer.querySelector('.conv-empty') || el('empty-state');
        if (empty) empty.remove();

        const row = document.createElement('div');
        row.className = 'msg-row ' + msg.direction;
        row.dataset.msgId = msg.id;

        const bubble = document.createElement('div');
        bubble.className = 'msg-bubble';
        bubble.textContent = msg.text;

        if (msg.direction !== 'system') {
            const metaDiv = document.createElement('div');
            metaDiv.className = 'msg-meta';

            const time = document.createElement('span');
            time.className = 'msg-time';
            time.textContent = formatTime(msg.timestamp);

            const badge = document.createElement('span');
            badge.className = 'msg-e2ee-badge';
            badge.textContent = 'e2ee';

            metaDiv.appendChild(time);
            metaDiv.appendChild(badge);

            if (msg.policy) {
                const bLabel = burnLabel(msg.policy);
                if (bLabel) {
                    const burnSpan = document.createElement('span');
                    burnSpan.className = 'msg-burn';
                    burnSpan.textContent = bLabel;
                    metaDiv.appendChild(burnSpan);
                }
            }

            row.appendChild(bubble);
            row.appendChild(metaDiv);
        } else {
            row.appendChild(bubble);
        }

        messagesContainer.appendChild(row);
        messagesContainer.scrollTop = messagesContainer.scrollHeight;
    }

    function burnActiveConversation() {
        if (!state.activePeerToken) return;
        const conv = state.conversations[state.activePeerToken];
        if (!conv) return;

        if (!confirm(`Burn channel with ${conv.alias}? All keys and messages will be permanently purged from memory.`)) {
            return;
        }

        if (WebRTCMesh.isActive()) {
            WebRTCMesh.hangup();
        }

        // Wipe sensitive references from memory
        conv.sessionKey = null;
        conv.messages = [];
        delete state.conversations[state.activePeerToken];
        state.activePeerToken = null;

        renderSidebar();
        renderActiveChat();
        toast('channel burned from memory', 'success');
    }

    function burnAllChannels() {
        const count = Object.keys(state.conversations).length;
        if (count === 0) {
            toast('no channels to burn');
            return;
        }

        if (!confirm(`Burn all ${count} active channels? All keys and messages will be purged from memory.`)) {
            return;
        }

        if (WebRTCMesh.isActive()) {
            WebRTCMesh.hangup();
        }

        for (const token of Object.keys(state.conversations)) {
            state.conversations[token].sessionKey = null;
            state.conversations[token].messages = [];
        }

        state.conversations = {};
        state.activePeerToken = null;

        renderSidebar();
        renderActiveChat();
        toast('all channels burned from memory', 'success');
    }

    function escapeHtml(str) {
        if (!str) return '';
        return str
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    // ------------------------------------------------------------------
    // WebRTC Helpers
    // ------------------------------------------------------------------
    async function encryptForPeer(text) {
        if (!state.activePeerToken) return text;
        const conv = state.conversations[state.activePeerToken];
        if (!conv || !conv.sessionKey) return text;

        const { ciphertextHex, nonceHex } = await CryptoEngine.encrypt(text, conv.sessionKey, state.myRoutingToken);
        return JSON.stringify({ ciphertextHex, nonceHex });
    }

    async function decryptFromPeer(text) {
        if (!state.activePeerToken) return text;
        const conv = state.conversations[state.activePeerToken];
        if (!conv || !conv.sessionKey) return text;

        const { ciphertextHex, nonceHex } = JSON.parse(text);
        return CryptoEngine.decrypt(ciphertextHex, nonceHex, conv.sessionKey, conv.peerToken);
    }

    async function sendWebRtcOffer(encryptedSdp, targetToken) {
        const uuid = 'WTC-' + CryptoEngine.randomHex(16);
        send({
            type: 'WEBRTC_OFFER',
            initiator_hash: state.myRoutingToken,
            peer_hash: targetToken,
            encrypted_offer: encryptedSdp,
        });
        return uuid;
    }

    async function sendWebRtcAnswer(encryptedAnswer, sessionUuid) {
        send({
            type: 'WEBRTC_ANSWER',
            session_uuid: sessionUuid,
            encrypted_answer: encryptedAnswer,
        });
    }

    function sendIceCandidate(candidateJson) {
        if (!state.activePeerToken) return;
        send({ type: 'WEBRTC_ICE', target_hash: state.activePeerToken, candidate: candidateJson });
    }

    // ------------------------------------------------------------------
    // Event Listeners
    // ------------------------------------------------------------------
    function bindEvents() {
        // Copy own token
        el('my-token-display').addEventListener('click', async () => {
            try {
                await navigator.clipboard.writeText(state.myRoutingToken);
                toast('token copied to clipboard');
            } catch {
                toast('failed to copy token', 'error');
            }
        });

        // Copy active peer token
        el('chat-peer-token').addEventListener('click', async () => {
            if (!state.activePeerToken) return;
            try {
                await navigator.clipboard.writeText(state.activePeerToken);
                toast('peer token copied');
            } catch {
                toast('failed to copy', 'error');
            }
        });

        // Rename active peer alias (local in-memory only)
        el('chat-peer-name').addEventListener('click', () => {
            if (!state.activePeerToken) return;
            const conv = state.conversations[state.activePeerToken];
            if (!conv) return;

            const newAlias = prompt('Enter alias for this peer:', conv.alias);
            if (newAlias !== null && newAlias.trim()) {
                conv.alias = newAlias.trim();
                conv.isCustomAlias = true;
                renderSidebar();
                renderActiveChat();
                toast('alias updated');
            }
        });

        // Search/Filter contacts
        el('contact-search-input').addEventListener('input', (e) => {
            state.filterQuery = e.target.value.trim();
            renderSidebar();
        });

        // Modal: Open New Chat
        el('btn-open-new-chat').addEventListener('click', () => {
            el('new-chat-token').value = '';
            el('new-chat-alias').value = '';
            el('modal-new-chat').classList.remove('hidden');
            el('new-chat-token').focus();
        });

        // Modal: Close
        const closeModal = () => el('modal-new-chat').classList.add('hidden');
        el('btn-modal-close').addEventListener('click', closeModal);
        el('btn-modal-cancel').addEventListener('click', closeModal);

        // Modal: Submit New Chat
        el('btn-modal-submit').addEventListener('click', () => {
            const token = el('new-chat-token').value;
            const alias = el('new-chat-alias').value;
            if (initiateNewChat(token, alias)) {
                closeModal();
            }
        });

        el('new-chat-token').addEventListener('keydown', (e) => {
            if (e.key === 'Enter') el('btn-modal-submit').click();
            if (e.key === 'Escape') closeModal();
        });

        // Burn chips for active conversation
        document.querySelectorAll('#chat-burn-chips .burn-chip').forEach(chip => {
            chip.addEventListener('click', () => {
                if (!state.activePeerToken) return;
                const conv = state.conversations[state.activePeerToken];
                if (!conv) return;

                conv.burnPolicy = chip.dataset.policy;
                document.querySelectorAll('#chat-burn-chips .burn-chip').forEach(c => c.classList.remove('active'));
                chip.classList.add('active');
                toast('burn policy: ' + conv.burnPolicy);
            });
        });

        // Compose & Send
        el('btn-send').addEventListener('click', () => {
            const text = el('compose-input').value.trim();
            if (!text) return;
            sendMessage(text);
            el('compose-input').value = '';
            el('compose-input').style.height = '';
        });

        el('compose-input').addEventListener('keydown', (e) => {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                el('btn-send').click();
            }
        });

        el('compose-input').addEventListener('input', function () {
            this.style.height = '';
            this.style.height = Math.min(this.scrollHeight, 160) + 'px';
        });

        // Burn active channel
        el('btn-burn-active-chat').addEventListener('click', burnActiveConversation);

        // Burn all channels
        el('btn-burn-all').addEventListener('click', burnAllChannels);

        // Regenerate Identity
        el('btn-regenerate-keys').addEventListener('click', async () => {
            if (!confirm('Regenerate identity? All current channels and keys will be destroyed from memory.')) {
                return;
            }
            if (WebRTCMesh.isActive()) WebRTCMesh.hangup();

            state.conversations = {};
            state.activePeerToken = null;

            await initIdentity();
            send({ type: 'REGISTER_INBOX', routing_token: state.myRoutingToken });

            renderSidebar();
            renderActiveChat();
            toast('new ephemeral identity created', 'success');
        });

        // WebRTC audio call triggers
        el('btn-call').addEventListener('click', async () => {
            if (!state.activePeerToken) {
                toast('no active channel selected', 'error');
                return;
            }
            const conv = state.conversations[state.activePeerToken];
            if (!conv || conv.status !== 'established') {
                toast('channel must be established first', 'error');
                return;
            }

            try {
                await WebRTCMesh.startCall(state.activePeerToken);
                conv.messages.push({
                    id: CryptoEngine.randomHex(8),
                    direction: 'system',
                    text: 'audio call initiated',
                    timestamp: Date.now()
                });
                renderActiveChat();
            } catch {
                toast('microphone access denied or call failed', 'error');
            }
        });

        el('btn-hangup').addEventListener('click', () => {
            WebRTCMesh.hangup();
        });

        WebRTCMesh.setCallStateCallback((callState) => {
            if (callState === 'connected') {
                el('btn-call').style.display = 'none';
                el('btn-hangup').classList.add('visible');
                el('webrtc-panel').classList.add('visible');
                el('call-timer').classList.add('visible');
                callStart = Date.now();
                callInterval = setInterval(() => {
                    const secs = Math.floor((Date.now() - callStart) / 1000);
                    const m = String(Math.floor(secs / 60)).padStart(2, '0');
                    const s = String(secs % 60).padStart(2, '0');
                    el('call-timer').textContent = m + ':' + s;
                }, 1000);
            } else if (callState === 'closed' || callState === 'disconnected' || callState === 'failed') {
                el('btn-call').style.display = '';
                el('btn-hangup').classList.remove('visible');
                el('webrtc-panel').classList.remove('visible');
                el('call-timer').classList.remove('visible');
                if (callInterval) {
                    clearInterval(callInterval);
                    callInterval = null;
                }
            }
        });

        WebRTCMesh.setRemoteStreamCallback((stream) => {
            const audio = el('remote-audio');
            if (audio) audio.srcObject = stream;
        });
    }

    async function boot() {
        try {
            await initIdentity();
            bindEvents();
            renderSidebar();
            renderActiveChat();
            connectRelay();
        } catch (e) {
            console.error('Boot error:', e);
            setStatus('error', 'init failed');
        }
    }

    return {
        encryptForPeer,
        decryptFromPeer,
        sendWebRtcOffer,
        sendWebRtcAnswer,
        sendIceCandidate,
        boot,
    };
})();

document.addEventListener('DOMContentLoaded', () => GhostMeshApp.boot());
