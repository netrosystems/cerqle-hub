import { describe, it, expect } from 'vitest';
import { X_MAX_IMAGES, xFilesError, xSharedMediaProblem } from '../Components/Social/xText';

const file = (type, mb = 1) => ({ type, size: mb * 1024 * 1024 });

// Cerqle's X caps: up to three images, or one video or GIF on its own.
// XContentValidator enforces the same on the server.
describe('X media caps', () => {
    it('allows up to three images', () => {
        expect(X_MAX_IMAGES).toBe(3);
        expect(xFilesError('images', [file('image/png'), file('image/jpeg'), file('image/png')])).toBeNull();
        expect(xFilesError('images', [file('image/png'), file('image/png'), file('image/png'), file('image/png')])).toBe('count');
    });

    it('allows one video or one GIF', () => {
        expect(xFilesError('video', [file('video/mp4', 100)])).toBeNull();
        expect(xFilesError('video', [file('image/gif', 10)])).toBeNull();
        expect(xFilesError('video', [file('image/gif'), file('image/gif')])).toBe('count');
    });

    it('refuses wrong types and oversized files', () => {
        expect(xFilesError('images', [file('image/gif')])).toBe('type');
        expect(xFilesError('images', [file('image/png', 6)])).toBe('type');
        expect(xFilesError('video', [file('image/gif', 16)])).toBe('type');
        expect(xFilesError('video', [file('video/quicktime')])).toBe('type');
    });

    it('warns about shared composer media that X would refuse', () => {
        expect(xSharedMediaProblem(['a.jpg', 'b.png', 'c.jpg'])).toBeNull();
        expect(xSharedMediaProblem(['a.jpg', 'b.png', 'c.jpg', 'd.jpg'])).toBe('too_many_images');
        expect(xSharedMediaProblem(['clip.mp4'])).toBeNull();
        expect(xSharedMediaProblem(['loop.gif?v=2', 'a.jpg'])).toBe('motion_alone');
        expect(xSharedMediaProblem(['', null, 'a.jpg'])).toBeNull();
    });
});
