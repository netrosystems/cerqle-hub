import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import PostMediaPreview, { resolveMediaKind } from '@/Components/Social/PostMediaPreview';

describe('PostMediaPreview', () => {
    it('uses MIME metadata for extensionless uploaded videos', () => {
        const { container } = render(
            <PostMediaPreview url="https://cerqle.test/storage/media/uuid" mimeType="video/mp4" />,
        );

        expect(container.querySelector('video')).toBeInTheDocument();
        expect(container.querySelector('img')).not.toBeInTheDocument();
    });

    it('recognizes durable image thumbnails even for YouTube posts', () => {
        expect(resolveMediaKind('https://i.ytimg.com/vi/video/hqdefault.jpg', null, true)).toBe('image');
    });

    it('shows a clean fallback when an image cannot load', () => {
        const { container } = render(<PostMediaPreview url="https://cdn.example.com/image.jpg" />);
        fireEvent.error(container.querySelector('img'));

        expect(screen.getByText('Media preview unavailable')).toBeInTheDocument();
        expect(container.querySelector('img')).not.toBeInTheDocument();
    });
});
