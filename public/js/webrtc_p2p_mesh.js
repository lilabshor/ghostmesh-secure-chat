const WebRTCMesh = (() => {
    const ICE_SERVERS = [
        { urls: 'stun:stun.l.google.com:19302' },
        { urls: 'stun:stun1.l.google.com:19302' },
    ];

    let pc           = null;
    let localStream  = null;
    let sessionUuid  = null;
    let onCallState  = null;
    let onRemote     = null;

    function setCallStateCallback(fn) { onCallState = fn; }
    function setRemoteStreamCallback(fn) { onRemote = fn; }

    function buildPeerConnection() {
        const conn = new RTCPeerConnection({ iceServers: ICE_SERVERS });

        conn.onicecandidate = (e) => {
            if (e.candidate) {
                GhostMeshApp.sendIceCandidate(JSON.stringify(e.candidate));
            }
        };

        conn.ontrack = (e) => {
            if (onRemote) onRemote(e.streams[0]);
        };

        conn.onconnectionstatechange = () => {
            if (onCallState) onCallState(conn.connectionState);
            if (conn.connectionState === 'disconnected' || conn.connectionState === 'failed') {
                hangup();
            }
        };

        return conn;
    }

    async function startCall(targetRoutingToken) {
        localStream = await navigator.mediaDevices.getUserMedia({ audio: true, video: false });
        pc = buildPeerConnection();

        for (const track of localStream.getTracks()) {
            pc.addTrack(track, localStream);
        }

        const offer = await pc.createOffer();
        await pc.setLocalDescription(offer);

        const sdpJson      = JSON.stringify(offer);
        const encryptedSdp = await GhostMeshApp.encryptForPeer(sdpJson, targetRoutingToken);

        sessionUuid = await GhostMeshApp.sendWebRtcOffer(encryptedSdp, targetRoutingToken);
        return sessionUuid;
    }

    async function handleIncomingOffer(encryptedSdpHex, incomingSessionUuid) {
        sessionUuid = incomingSessionUuid;

        const sdpJson = await GhostMeshApp.decryptFromPeer(encryptedSdpHex);
        const offer   = JSON.parse(sdpJson);

        localStream = await navigator.mediaDevices.getUserMedia({ audio: true, video: false });
        pc = buildPeerConnection();

        for (const track of localStream.getTracks()) {
            pc.addTrack(track, localStream);
        }

        await pc.setRemoteDescription(new RTCSessionDescription(offer));

        const answer = await pc.createAnswer();
        await pc.setLocalDescription(answer);

        const encryptedAnswer = await GhostMeshApp.encryptForPeer(JSON.stringify(answer));
        await GhostMeshApp.sendWebRtcAnswer(encryptedAnswer, incomingSessionUuid);
    }

    async function handleAnswer(encryptedSdpHex) {
        if (!pc) return;
        const sdpJson = await GhostMeshApp.decryptFromPeer(encryptedSdpHex);
        const answer  = JSON.parse(sdpJson);
        await pc.setRemoteDescription(new RTCSessionDescription(answer));
    }

    async function handleIceCandidate(candidateJson) {
        if (!pc) return;
        try {
            const candidate = JSON.parse(candidateJson);
            await pc.addIceCandidate(new RTCIceCandidate(candidate));
        } catch {
            // stale or malformed candidate, ignore
        }
    }

    function hangup() {
        if (pc) {
            pc.close();
            pc = null;
        }
        if (localStream) {
            for (const track of localStream.getTracks()) track.stop();
            localStream = null;
        }
        sessionUuid = null;
        if (onCallState) onCallState('closed');
    }

    function isActive() { return pc !== null; }

    return {
        setCallStateCallback,
        setRemoteStreamCallback,
        startCall,
        handleIncomingOffer,
        handleAnswer,
        handleIceCandidate,
        hangup,
        isActive,
    };
})();