import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { YouTubeTagsInput } from '@/Components/YouTubeVideoSettings';

describe('YouTubeTagsInput', () => {
    it('keeps a typed comma visible while emitting clean tags', () => {
        const onChange = vi.fn();
        const { rerender } = render(
            <YouTubeTagsInput tags={[]} onChange={onChange} aria-label="YouTube tags" />,
        );
        const input = screen.getByRole('textbox', { name: 'YouTube tags' });

        fireEvent.change(input, { target: { value: 'esim,' } });
        expect(onChange).toHaveBeenLastCalledWith(['esim']);

        rerender(<YouTubeTagsInput tags={['esim']} onChange={onChange} aria-label="YouTube tags" />);
        expect(input).toHaveValue('esim,');

        fireEvent.change(input, { target: { value: 'esim, travel' } });
        expect(onChange).toHaveBeenLastCalledWith(['esim', 'travel']);
        expect(input).toHaveValue('esim, travel');
    });

    it('syncs tags changed outside the input', () => {
        const onChange = vi.fn();
        const { rerender } = render(
            <YouTubeTagsInput tags={['old']} onChange={onChange} aria-label="YouTube tags" />,
        );

        rerender(<YouTubeTagsInput tags={['new', 'tags']} onChange={onChange} aria-label="YouTube tags" />);
        expect(screen.getByRole('textbox', { name: 'YouTube tags' })).toHaveValue('new, tags');
    });
});
