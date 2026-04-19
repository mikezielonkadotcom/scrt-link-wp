/**
 * Frontend view module — Interactivity API store for the scrt-request block.
 *
 * Encryption: done entirely in the browser using WebCrypto (AES-GCM + PBKDF2 +
 * ECDSA P-384 for key material), mirroring scrt.link's published client module.
 * Ciphertext is POSTed to the WordPress REST proxy, which adds the site's Bearer
 * token and forwards to scrt.link. The API key never reaches the browser.
 */
import { store, getContext } from '@wordpress/interactivity';

const NS = 'scrt-link-wp/scrt-request';

// --- crypto helpers (port of scrt.link's /api/v1/client-module) ---

const SECRET_ID_CHARS =
	'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789$~-_.';

const randomBytes = ( n = 16 ) => crypto.getRandomValues( new Uint8Array( n ) );

const randomString = ( n = 36 ) =>
	Array.from( randomBytes( n ), ( b ) => SECRET_ID_CHARS[ b % SECRET_ID_CHARS.length ] ).join( '' );

const utf8 = ( s ) => new TextEncoder().encode( s );

const sha256Hex = async ( s ) => {
	const h = await crypto.subtle.digest( 'SHA-256', utf8( s ) );
	return Array.from( new Uint8Array( h ), ( b ) => b.toString( 16 ).padStart( 2, '0' ) ).join( '' );
};

const bytesToB64 = ( bytes ) =>
	btoa( String.fromCharCode( ...new Uint8Array( bytes ) ) );

const blobToDataUrl = ( blob ) =>
	new Promise( ( resolve, reject ) => {
		const r = new FileReader();
		r.onloadend = () =>
			typeof r.result === 'string' ? resolve( r.result ) : reject( new Error( 'base64 encode failed' ) );
		r.readAsDataURL( blob );
	} );

const deriveKey = async ( secret ) => {
	const salt = randomBytes( 16 );
	const raw = await crypto.subtle.importKey( 'raw', utf8( secret ), 'PBKDF2', false, [ 'deriveKey' ] );
	const key = await crypto.subtle.deriveKey(
		{ name: 'PBKDF2', salt, iterations: 100000, hash: 'SHA-256' },
		raw,
		{ name: 'AES-GCM', length: 256 },
		false,
		[ 'encrypt' ]
	);
	return { key, salt };
};

const encryptWithSecret = async ( plaintext, secret ) => {
	const { key, salt } = await deriveKey( secret );
	const iv = randomBytes( 16 );
	const buf = utf8( plaintext ).buffer;
	const ciphertext = await crypto.subtle.encrypt( { name: 'AES-GCM', iv }, key, buf );
	return blobToDataUrl( new Blob( [ salt, iv, ciphertext ] ) );
};

const exportPublicKeyPem = async ( key ) => {
	const raw = await crypto.subtle.exportKey( 'spki', key );
	const b64 = bytesToB64( raw );
	return `-----BEGIN PUBLIC KEY-----\n${ b64 }\n-----END PUBLIC KEY-----`;
};

const buildSecret = async ( { content, publicNote, password, expiresIn } ) => {
	const secretId = randomString( 36 );
	const secretIdHash = await sha256Hex( secretId.substring( 28 ) );

	const keypair = await crypto.subtle.generateKey(
		{ name: 'ECDSA', namedCurve: 'P-384' },
		true,
		[ 'sign', 'verify' ]
	);
	const publicKey = await exportPublicKeyPem( keypair.publicKey );

	let meta = JSON.stringify( { secretType: 'text' } );
	let contentEnc = content;

	if ( password ) {
		meta = await encryptWithSecret( meta, password );
		contentEnc = await encryptWithSecret( contentEnc, password );
	}

	meta = await encryptWithSecret( meta, secretId );
	contentEnc = await encryptWithSecret( contentEnc, secretId );

	const payload = {
		secretIdHash,
		meta,
		content: contentEnc,
		publicKey,
		publicNote: publicNote || undefined,
		expiresIn,
		password: password || undefined,
	};

	const payloadJson = JSON.stringify( payload );
	const checksum = await sha256Hex( payloadJson );

	return { secretId, payloadJson, checksum };
};

store( NS, {
	state: {
		get isBusy() {
			const s = getContext().status;
			return s === 'encrypting' || s === 'sending';
		},
		get isSuccess() {
			return getContext().status === 'success';
		},
		get isError() {
			return getContext().status === 'error';
		},
		get showForm() {
			return getContext().status !== 'success';
		},
	},
	actions: {
		setSecret( event ) { getContext().secret = event.target.value; },
		setNote( event ) { getContext().note = event.target.value; },
		setPassword( event ) { getContext().password = event.target.value; },

		*submit( event ) {
			event.preventDefault();
			const ctx = getContext();

			if ( ! ctx.secret || ctx.secret.trim() === '' ) {
				ctx.status = 'error';
				ctx.errorMessage = ctx.labelRequired;
				return;
			}

			ctx.status = 'encrypting';
			ctx.errorMessage = '';

			try {
				const config = yield fetch( ctx.restConfigUrl, { credentials: 'same-origin' } )
					.then( ( r ) => r.json() );

				const expiresIn = ctx.expiresIn > 0 ? ctx.expiresIn : config.expiresIn;

				const { secretId, payloadJson, checksum } = yield buildSecret( {
					content: ctx.secret,
					publicNote: ctx.note || undefined,
					password: ctx.password || undefined,
					expiresIn,
				} );

				ctx.status = 'sending';

				const res = yield fetch( ctx.restSubmitUrl, {
					method: 'POST',
					credentials: 'same-origin',
					headers: {
						'Content-Type': 'application/json',
						'X-WP-Nonce': ctx.nonce,
						'X-Scrt-Secret-Id': secretId,
						'X-Scrt-Checksum': checksum,
					},
					body: payloadJson,
				} );

				if ( ! res.ok ) {
					const body = yield res.json().catch( () => ( {} ) );
					throw new Error( body?.message || `HTTP ${ res.status }` );
				}

				ctx.status = 'success';
				ctx.secret = '';
				ctx.note = '';
				ctx.password = '';
			} catch ( err ) {
				ctx.status = 'error';
				ctx.errorMessage = err?.message || ctx.labelUnknownError;
			}
		},
	},
} );
