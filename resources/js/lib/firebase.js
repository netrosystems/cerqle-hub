import { initializeApp, getApps } from 'firebase/app';
import { getAuth, GoogleAuthProvider, signInWithPopup } from 'firebase/auth';

let _app = null;
let _auth = null;

export function initFirebase(config) {
    if (getApps().length === 0) {
        _app = initializeApp(config);
    } else {
        _app = getApps()[0];
    }
    _auth = getAuth(_app);
}

export async function signInWithGoogle() {
    if (!_auth) throw new Error('Firebase not initialized');
    const provider = new GoogleAuthProvider();
    const result = await signInWithPopup(_auth, provider);

    const credential = GoogleAuthProvider.credentialFromResult(result);
    if (credential?.idToken) {
        return credential.idToken;
    }

    return result.user.getIdToken();
}
