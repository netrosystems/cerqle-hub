import { act, cleanup, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, afterEach, expect, it, vi } from 'vitest';
import PlanTable from '@/Pages/Admin/Plans/PlanTable';

vi.mock('react-i18next', () => ({ useTranslation: () => ({ t: key => key }) }));
vi.mock('@inertiajs/react', () => ({ router: {} }));
beforeEach(() => vi.stubGlobal('ResizeObserver', class { observe() {} unobserve() {} disconnect() {} }));
afterEach(() => { cleanup(); vi.unstubAllGlobals(); });

it('portals the last-row actions outside the table and edits the correct plan', async () => {
    const user = userEvent.setup();
    const plan = { id: 4, name: 'Enterprise', enabled: true };
    const onEdit = vi.fn();
    const { container } = render(<PlanTable plans={[plan]} onEdit={onEdit} />);
    await user.click(screen.getByRole('button', { name: 'admin.plan_actions' }));
    const menu = await screen.findByRole('menu');
    expect(container.contains(menu)).toBe(false);
    await user.click(screen.getByRole('menuitem', { name: 'admin.edit_plan' }));
    expect(onEdit).toHaveBeenCalledWith(plan);
    expect(screen.queryByRole('menu')).not.toBeInTheDocument();
});

it('opens by keyboard and closes with Escape', async () => {
    const user = userEvent.setup();
    render(<PlanTable plans={[{ id: 4, name: 'Enterprise' }]} />);
    const trigger = screen.getByRole('button', { name: 'admin.plan_actions' });
    act(() => trigger.focus());
    await user.keyboard('{Enter}');
    expect(await screen.findByRole('menu')).toBeInTheDocument();
    await user.keyboard('{Escape}');
    expect(screen.queryByRole('menu')).not.toBeInTheDocument();
    await waitFor(() => expect(trigger).toHaveFocus());
});
