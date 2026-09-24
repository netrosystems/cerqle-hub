import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import axios from 'axios';
import NewConversationModal from '@/Components/Inbox/NewConversationModal';

vi.mock('axios', () => ({ default: { get: vi.fn(), post: vi.fn(), put: vi.fn() } }));
vi.mock('react-i18next', () => ({ useTranslation: () => ({ t: key => key }) }));

describe('NewConversationModal contact pagination', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        axios.get.mockImplementation((_url, { params }) => Promise.resolve({
            data: params.page === 1
                ? [{ id: 2, first_name: 'Latest contact', custom_fields: {} }]
                : [{ id: 1, first_name: 'Older contact', custom_fields: {} }],
            headers: {
                'x-pagination-current-page': String(params.page),
                'x-pagination-last-page': '2',
            },
        }));
    });

    it('loads and appends the next contact page when the list reaches the bottom', async () => {
        render(<NewConversationModal onClose={vi.fn()} />);

        expect(await screen.findByText('Latest contact')).toBeInTheDocument();
        const list = screen.getByTestId('contact-results');
        Object.defineProperties(list, {
            scrollHeight: { configurable: true, value: 600 },
            clientHeight: { configurable: true, value: 300 },
            scrollTop: { configurable: true, value: 260 },
        });

        fireEvent.scroll(list);

        expect(await screen.findByText('Older contact')).toBeInTheDocument();
        expect(screen.getByText('Latest contact')).toBeInTheDocument();
        await waitFor(() => expect(axios.get).toHaveBeenLastCalledWith(
            expect.any(String),
            { params: { q: '', page: 2 } },
        ));
    });

    it('inserts a selected quick reply into the opening message', async () => {
        axios.get.mockImplementation((url) => {
            if (url.includes('channel-accounts')) {
                return Promise.resolve({ data: [{ id: 8, channel: 'whatsapp', display_name: 'Support WhatsApp' }] });
            }
            if (url.includes('canned-replies.list')) {
                return Promise.resolve({ data: [{ id: 4, shortcut: 'hello', body: 'Hello from support.' }] });
            }
            return Promise.resolve({
                data: [{ id: 2, first_name: 'Latest contact', phone_e164: '+14155552671', custom_fields: {} }],
                headers: { 'x-pagination-current-page': '1', 'x-pagination-last-page': '1' },
            });
        });

        render(<NewConversationModal onClose={vi.fn()} />);

        fireEvent.click(await screen.findByText('Latest contact'));
        fireEvent.click(await screen.findByText('Support WhatsApp'));
        fireEvent.click(screen.getByText('nav.quick_replies'));
        fireEvent.click(await screen.findByText('Hello from support.'));

        expect(screen.getByPlaceholderText('inbox.opening_message')).toHaveValue('Hello from support.');
    });
});
