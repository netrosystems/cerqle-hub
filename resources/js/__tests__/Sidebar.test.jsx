import { fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import Sidebar from '@/Components/Sidebar';

vi.mock('react-i18next', () => ({
    useTranslation: () => ({ t: (key) => key }),
}));

const navItems = Array.from({ length: 20 }, (_, index) => ({
    key: `item-${index}`,
    label: `Item ${index}`,
    href: `/item-${index}`,
    active: false,
}));

describe('Sidebar scroll persistence', () => {
    beforeEach(() => {
        window.sessionStorage.clear();
    });

    it('restores its scroll position after the sidebar is remounted', () => {
        const firstRender = render(
            <Sidebar scrollKey="admin" navItems={navItems} showCreateButton={false} />,
        );
        const firstNav = screen.getByTestId('sidebar-scroll-desktop');

        firstNav.scrollTop = 420;
        fireEvent.scroll(firstNav);

        expect(window.sessionStorage.getItem('cerqle.sidebar.scroll.admin')).toBe('420');

        firstRender.unmount();
        render(<Sidebar scrollKey="admin" navItems={navItems} showCreateButton={false} />);

        expect(screen.getByTestId('sidebar-scroll-desktop').scrollTop).toBe(420);
    });

    it('keeps admin and client menu positions independent', () => {
        window.sessionStorage.setItem('cerqle.sidebar.scroll.admin', '510');
        window.sessionStorage.setItem('cerqle.sidebar.scroll.client', '275');

        const adminRender = render(
            <Sidebar scrollKey="admin" navItems={navItems} showCreateButton={false} />,
        );
        expect(screen.getByTestId('sidebar-scroll-desktop').scrollTop).toBe(510);

        adminRender.unmount();
        render(<Sidebar scrollKey="client" navItems={navItems} showCreateButton={false} />);

        expect(screen.getByTestId('sidebar-scroll-desktop').scrollTop).toBe(275);
    });

    it('restores the client position when the mobile drawer opens', () => {
        window.sessionStorage.setItem('cerqle.sidebar.scroll.client', '330');

        const { rerender } = render(
            <Sidebar scrollKey="client" navItems={navItems} showCreateButton={false} open={false} />,
        );

        rerender(
            <Sidebar scrollKey="client" navItems={navItems} showCreateButton={false} open onClose={() => {}} />,
        );

        expect(screen.getByTestId('sidebar-scroll-mobile').scrollTop).toBe(330);
    });
});

describe('Sidebar group prioritization', () => {
    beforeEach(() => {
        window.sessionStorage.clear();
    });

    it('keeps secondary groups collapsed until requested', () => {
        render(
            <Sidebar
                navGroups={[{
                    label: 'Setup',
                    defaultOpen: false,
                    items: [{ label: 'Email Setup', href: '/email-setup', active: false }],
                }]}
                showCreateButton={false}
            />,
        );

        expect(screen.queryByRole('link', { name: 'Email Setup' })).not.toBeInTheDocument();
        fireEvent.click(screen.getAllByRole('button', { name: 'Setup' })[0]);
        expect(screen.getAllByRole('link', { name: 'Email Setup' })).toHaveLength(1);
    });

    it('expands a collapsed group that contains the active page', () => {
        render(
            <Sidebar
                navGroups={[{
                    label: 'Setup',
                    defaultOpen: false,
                    items: [{ label: 'Email Setup', href: '/email-setup', active: true }],
                }]}
                showCreateButton={false}
            />,
        );

        // The current page has to be visible without a click, or the menu
        // highlights a link the user cannot see.
        expect(screen.getAllByRole('button', { name: 'Setup' })[0]).toHaveAttribute('aria-expanded', 'true');
        expect(screen.getAllByRole('link', { name: 'Email Setup' })[0]).toBeInTheDocument();
    });

    it('remembers which groups are open across a remount', () => {
        const groups = [{
            label: 'Setup',
            defaultOpen: false,
            items: [{ label: 'Email Setup', href: '/email-setup', active: false }],
        }];

        const first = render(<Sidebar scrollKey="client" navGroups={groups} showCreateButton={false} />);
        fireEvent.click(screen.getAllByRole('button', { name: 'Setup' })[0]);
        expect(screen.getAllByRole('link', { name: 'Email Setup' })[0]).toBeInTheDocument();
        first.unmount();

        // Inertia recreates the sidebar on every visit, so an expanded group
        // must survive navigating rather than snapping shut each time.
        render(<Sidebar scrollKey="client" navGroups={groups} showCreateButton={false} />);
        expect(screen.getAllByRole('link', { name: 'Email Setup' })[0]).toBeInTheDocument();
    });

    it('keeps a group the user collapsed shut after a remount', () => {
        const groups = [{
            label: 'Contacts',
            items: [{ label: 'Contact Lists', href: '/contact-lists', active: false }],
        }];

        const first = render(<Sidebar scrollKey="client" navGroups={groups} showCreateButton={false} />);
        fireEvent.click(screen.getAllByRole('button', { name: 'Contacts' })[0]);
        expect(screen.queryByRole('link', { name: 'Contact Lists' })).not.toBeInTheDocument();
        first.unmount();

        render(<Sidebar scrollKey="client" navGroups={groups} showCreateButton={false} />);
        expect(screen.queryByRole('link', { name: 'Contact Lists' })).not.toBeInTheDocument();
    });

    it('uses an inline accordion for the mobile drawer', () => {
        render(
            <Sidebar
                open
                onClose={() => {}}
                navGroups={[{
                    label: 'Setup',
                    defaultOpen: false,
                    items: [{ label: 'Email Setup', href: '/email-setup', active: false }],
                }]}
                showCreateButton={false}
            />,
        );

        const setupTriggers = screen.getAllByRole('button', { name: 'Setup' });
        fireEvent.click(setupTriggers[1]);
        expect(screen.getByRole('link', { name: 'Email Setup' })).toBeInTheDocument();
    });
});
