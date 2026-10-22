const CryptoEngine = (() => {
    const subtle = window.crypto.subtle;
    const HKDF_SALT = new TextEncoder().encode('GHOSTMESH-V1-HKDF-SALT');
    const HKDF_INFO = new TextEncoder().encode('ghostmesh-v1-message-key');

    let curveMode = 'X25519';

    async function generateKeyPair() {
        try {
            const kp = await subtle.generateKey({ name: 'X25519' }, true, ['deriveKey', 'deriveBits']);
            const pubRaw = await subtle.exportKey('raw', kp.publicKey);
            curveMode = 'X25519';
            return {
                publicKey: kp.publicKey,
                privateKey: kp.privateKey,
                publicKeyHex: bufToHex(pubRaw),
                curve: 'X25519'
            };
        } catch {
            const kp = await subtle.generateKey({ name: 'ECDH', namedCurve: 'P-256' }, true, ['deriveKey', 'deriveBits']);
            const pubRaw = await subtle.exportKey('raw', kp.publicKey);
            curveMode = 'P-256';
            return {
                publicKey: kp.publicKey,
                privateKey: kp.privateKey,
                publicKeyHex: bufToHex(pubRaw),
                curve: 'P-256'
            };
        }
    }

    async function importPeerPublicKey(hexOrBuffer) {
        const buf = typeof hexOrBuffer === 'string' ? hexToBuf(hexOrBuffer) : hexOrBuffer;
        if (curveMode === 'X25519') {
            return subtle.importKey('raw', buf, { name: 'X25519' }, false, []);
        }
        return subtle.importKey('raw', buf, { name: 'ECDH', namedCurve: 'P-256' }, false, []);
    }

    async function deriveSharedBits(myPrivateKey, peerPublicKey) {
        if (curveMode === 'X25519') {
            return subtle.deriveBits({ name: 'X25519', public: peerPublicKey }, myPrivateKey, 256);
        }
        return subtle.deriveBits({ name: 'ECDH', public: peerPublicKey }, myPrivateKey, 256);
    }

    async function hkdfDeriveKey(sharedBits) {
        const baseKey = await subtle.importKey('raw', sharedBits, 'HKDF', false, ['deriveKey']);
        return subtle.deriveKey(
            {
                name: 'HKDF',
                hash: 'SHA-256',
                salt: HKDF_SALT,
                info: HKDF_INFO,
            },
            baseKey,
            { name: 'AES-GCM', length: 256 },
            false,
            ['encrypt', 'decrypt']
        );
    }

    async function encrypt(plaintext, cryptoKey, associatedData) {
        const iv = window.crypto.getRandomValues(new Uint8Array(12));
        const enc = new TextEncoder().encode(plaintext);
        const ad = associatedData ? new TextEncoder().encode(associatedData) : undefined;

        const cipherBuf = await subtle.encrypt(
            { name: 'AES-GCM', iv, additionalData: ad },
            cryptoKey,
            enc
        );

        return {
            ciphertextHex: bufToHex(cipherBuf),
            nonceHex: bufToHex(iv),
        };
    }

    async function decrypt(ciphertextHex, nonceHex, cryptoKey, associatedData) {
        const iv = hexToBuf(nonceHex);
        const cipher = hexToBuf(ciphertextHex);
        const ad = associatedData ? new TextEncoder().encode(associatedData) : undefined;

        const plain = await subtle.decrypt(
            { name: 'AES-GCM', iv, additionalData: ad },
            cryptoKey,
            cipher
        );

        return new TextDecoder().decode(plain);
    }

    async function sha256Hex(text) {
        const buf = new TextEncoder().encode(text);
        const digest = await subtle.digest('SHA-256', buf);
        return bufToHex(digest);
    }

    function bufToHex(buf) {
        return Array.from(new Uint8Array(buf))
            .map(b => b.toString(16).padStart(2, '0'))
            .join('');
    }

    function hexToBuf(hex) {
        const cleanHex = hex.trim();
        const bytes = new Uint8Array(cleanHex.length / 2);
        for (let i = 0; i < cleanHex.length; i += 2) {
            bytes[i / 2] = parseInt(cleanHex.slice(i, i + 2), 16);
        }
        return bytes.buffer;
    }

    function randomHex(bytes) {
        return bufToHex(window.crypto.getRandomValues(new Uint8Array(bytes)));
    }

    async function solvePoW(seed, difficultyBits, onProgress) {
        const requiredZeroNibbles = Math.ceil(difficultyBits / 4);
        const prefix = '0'.repeat(requiredZeroNibbles);
        let nonce = 0;

        while (true) {
            const candidate = nonce.toString(16);
            const hashBuf = await subtle.digest('SHA-256', new TextEncoder().encode(seed + '|' + candidate));
            const hashHex = bufToHex(hashBuf);
            if (hashHex.startsWith(prefix)) {
                return candidate;
            }
            nonce++;

            if (nonce % 200 === 0) {
                if (typeof onProgress === 'function') {
                    onProgress(nonce);
                }
                await new Promise(r => setTimeout(r, 0));
            }
        }
    }

    return {
        generateKeyPair,
        importPeerPublicKey,
        deriveSharedBits,
        hkdfDeriveKey,
        encrypt,
        decrypt,
        sha256Hex,
        bufToHex,
        hexToBuf,
        randomHex,
        solvePoW,
    };
})();