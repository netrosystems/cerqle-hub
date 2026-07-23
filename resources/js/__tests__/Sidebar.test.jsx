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
