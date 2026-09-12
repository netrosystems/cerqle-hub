import { render, screen, fireEvent } from '@testing-library/react';
import { describe, it, expect, vi } from 'vitest';
import MediaPreviewImage from '@/Components/Inbox/MediaPreviewImage';

describe('MediaPreviewImage', () => {
    it('recovers a broken saved URL using the authenticated endpoint', () => {
        const onError = vi.fn();
        render(<MediaPreviewImage src="/stale.png" fallbackSrc="/media/1" alt="Preview" onError={onError} />);
        fireEvent.error(screen.getByAltText('Preview'));
        expect(screen.getByAltText('Preview')).toHaveAttribute('src', '/media/1');
        expect(onError).not.toHaveBeenCalled();
        fireEvent.error(screen.getByAltText('Preview'));
        expect(onError).toHaveBeenCalledTimes(1);
    });

    it('uses the endpoint directly without a saved URL', () => {
        render(<MediaPreviewImage fallbackSrc="/media/2" alt="Preview" />);
        expect(screen.getByAltText('Preview')).toHaveAttribute('src', '/media/2');
    });
});
