import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, expect, it } from 'vitest';
import InstagramAttachments, { instagramAttachments } from '@/Components/Inbox/InstagramAttachments';

afterEach(cleanup);
const attachment = (type, url = 'https://example.com/media') => ({ type, payload: { url } });

it('reads legacy text rows but never interprets other channels as Instagram', () => {
    const msg = { channel: 'instagram', type: 'text', payload: { message: { attachments: [attachment('image')] } } };
    expect(instagramAttachments(msg)).toHaveLength(1);
    expect(instagramAttachments({ ...msg, channel: 'whatsapp' })).toEqual([]);
    expect(instagramAttachments({ channel: 'instagram' })).toEqual([]);
});
it('renders all images, video and audio with controls', () => {
    render(<InstagramAttachments attachments={['image', 'video', 'audio'].map(t => attachment(t))} />);
    expect(screen.getByRole('img')).toHaveAttribute('src', 'https://example.com/media');
    expect(screen.getByLabelText('Instagram video')).toHaveAttribute('controls');
    expect(screen.getByLabelText('Instagram audio')).toHaveAttribute('controls');
});
it('shows shares as safe links rather than broken images', () => {
    render(<InstagramAttachments attachments={[attachment('share')]} />);
    expect(screen.getByRole('link')).toHaveAttribute('rel', 'noopener noreferrer');
    expect(screen.queryByRole('img')).toBeNull();
});
it('rejects unsafe and missing URLs', () => {
    render(<InstagramAttachments attachments={[attachment('image', 'javascript:alert(1)'), {}]} />);
    expect(screen.getAllByRole('status')).toHaveLength(2);
    expect(screen.queryByRole('link')).toBeNull();
});
it('explains failed or expired media', () => {
    render(<InstagramAttachments attachments={[attachment('image')]} />);
    fireEvent.error(screen.getByRole('img'));
    expect(screen.getByRole('status')).toHaveTextContent('Instagram media unavailable');
});
