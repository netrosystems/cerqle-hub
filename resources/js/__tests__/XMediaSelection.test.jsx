import { useState } from 'react';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import axios from 'axios';
import XMediaSelection from '@/Components/Social/XMediaSelection';

vi.mock('axios', () => ({ default: { post: vi.fn() } }));
vi.mock('react-i18next', () => ({ useTranslation: () => ({ t: key => key }) }));

function Harness({ initial, onChange, onStorageChange }) {
    const [payload, setPayload] = useState(initial);
    return <XMediaSelection payload={payload} onStorageChange={onStorageChange} onChange={next => {
        onChange(next);
        setPayload(current => ({ ...current, ...next }));
    }} />;
}

const image = (name = 'photo.png') => new globalThis.File(['image'], name, { type: 'image/png' });
const video = () => new globalThis.File(['video'], 'clip.mp4', { type: 'video/mp4' });
function oversized(file, megabytes) {
    Object.defineProperty(file, 'size', { value: megabytes * 1024 * 1024 + 1 });
    return file;
}
function mount(type, extra = {}) {
    const onChange = vi.fn();
    const onStorageChange = vi.fn();
    render(<Harness initial={{ options: { media_type: type }, media_urls: [], media_ids: [], ...extra }} onChange={onChange} onStorageChange={onStorageChange} />);
    return { onChange, onStorageChange };
}
const selectFiles = files => fireEvent.change(screen.getByLabelText('social.x_upload_limits'), { target: { files } });

describe('XMediaSelection', () => {
    beforeEach(() => vi.clearAllMocks());

    it.each([['images', 'video'], ['video', 'images'], ['images', 'text']])('clears incompatible media when changing %s to %s', (from, to) => {
        const { onChange } = mount(from, { options: { media_type: from, preserved: true }, media_urls: ['/old'], media_ids: [12] });
        fireEvent.change(screen.getByRole('combobox'), { target: { value: to } });
        expect(onChange).toHaveBeenCalledWith({ options: { media_type: to, preserved: true }, media_urls: [], media_ids: [] });
        expect(screen.queryByRole('button', { name: 'common.remove' })).not.toBeInTheDocument();
        if (to === 'text') expect(screen.queryByLabelText('social.x_upload_limits')).not.toBeInTheDocument();
    });

    it.each([
        ['images', () => [oversized(image(), 5)]],
        ['video', () => [oversized(video(), 500)]],
        ['images', () => [image(), video()]],
        ['video', () => [video(), image()]],
        ['images', () => Array.from({ length: 5 }, (_, index) => image(`${index}.png`))],
        ['video', () => [video(), video()]],
    ])('rejects invalid files in %s mode before uploading', (type, files) => {
        const { onChange } = mount(type);
        selectFiles(files());
        expect(screen.getByRole('alert')).toHaveTextContent('social.x_upload_limits');
        expect(axios.post).not.toHaveBeenCalled();
        expect(onChange).not.toHaveBeenCalled();
    });

    it.each(['images', 'video'])('persists successful %s upload URLs and IDs and reports storage', async type => {
        const { onChange, onStorageChange } = mount(type);
        const files = type === 'images' ? [image('first.png'), image('second.png')] : [video()];
        files.forEach((_, index) => axios.post.mockResolvedValueOnce({ data: { url: `/uploads/${index}`, media_id: 40 + index, storage: { remaining_bytes: 100 - index } } }));
        selectFiles(files);
        await waitFor(() => expect(onChange).toHaveBeenCalledWith({ media_urls: files.map((_, index) => `/uploads/${index}`), media_ids: files.map((_, index) => 40 + index) }));
        expect(axios.post).toHaveBeenCalledTimes(files.length);
        files.forEach((file, index) => {
            const [url, form] = axios.post.mock.calls[index];
            expect(url).toBe('/client.media.store');
            expect(form.get('file')).toBe(file);
            expect(form.get('collection')).toBe(type === 'video' ? 'social-video' : 'social');
            expect(onStorageChange).toHaveBeenNthCalledWith(index + 1, { remaining_bytes: 100 - index });
        });
        expect(screen.getAllByRole('button', { name: 'common.remove' })).toHaveLength(files.length);
    });

    it('removes a media URL and its matching ID while preserving other uploads', () => {
        const { onChange } = mount('images', { media_urls: ['/first', '/second'], media_ids: [11, 22] });
        fireEvent.click(screen.getAllByRole('button', { name: 'common.remove' })[0]);
        expect(onChange).toHaveBeenCalledWith({ media_urls: ['/second'], media_ids: [22] });
        fireEvent.click(screen.getByRole('button', { name: 'common.remove' }));
        expect(onChange).toHaveBeenLastCalledWith({ media_urls: [], media_ids: [] });
        expect(screen.queryByRole('button', { name: 'common.remove' })).not.toBeInTheDocument();
    });
});
