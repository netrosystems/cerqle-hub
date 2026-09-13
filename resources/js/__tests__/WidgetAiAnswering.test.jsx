import { useState } from 'react';
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, expect, it, vi } from 'vitest';
import WidgetAiAnswering, { defaultWidgetHours } from '@/Components/Inbox/WidgetAiAnswering';

vi.mock('react-i18next', () => ({ useTranslation: () => ({ t: (_, options) => options.defaultValue }) }));
afterEach(cleanup);
function Harness({ errors = {} }) {
    const [data, set] = useState({ ai_mode: 'off', ai_enabled: false, ai_chatbot_id: 1, ai_timezone: 'Asia/Dhaka', ai_weekly_hours: defaultWidgetHours() });
    const setData = (key, value) => set((previous) => typeof key === 'function' ? key(previous) : { ...previous, [key]: value });
    return <><WidgetAiAnswering data={data} setData={setData} chatbots={[{ id: 1, name: 'Support' }]} errors={errors} /><output data-testid="state">{JSON.stringify(data)}</output></>;
}

it('switches modes without discarding bot or hours', () => {
    render(<Harness />);
    expect(screen.queryByLabelText('Chatbot')).not.toBeInTheDocument();
    fireEvent.click(screen.getByLabelText('Permanent'));
    expect(screen.getByLabelText('Chatbot')).toHaveValue('1');
    expect(screen.queryByText(/Edit hours/)).not.toBeInTheDocument();
    fireEvent.click(screen.getByLabelText('Scheduled'));
    expect(screen.getByText(/Edit hours/).closest('details')).not.toHaveAttribute('open');
    fireEvent.click(screen.getByLabelText('Off', { exact: true }));
    expect(JSON.parse(screen.getByTestId('state').textContent).ai_chatbot_id).toBe(1);
    expect(JSON.parse(screen.getByTestId('state').textContent).ai_weekly_hours[0].windows[0].start).toBe('09:00');
});

it('supports split hours, copying weekdays, removing and limiting windows', () => {
    render(<Harness />);
    fireEvent.click(screen.getByLabelText('Scheduled'));
    fireEvent.click(screen.getByText(/Edit hours/));
    fireEvent.click(screen.getAllByRole('button', { name: 'Add hours' })[0]);
    fireEvent.change(screen.getByLabelText('Monday start 2'), { target: { value: '18:00' } });
    fireEvent.change(screen.getByLabelText('Monday end 2'), { target: { value: '22:00' } });
    fireEvent.click(screen.getByRole('button', { name: 'Copy Monday to weekdays' }));
    expect(screen.getByLabelText('Friday start 2')).toHaveValue('18:00');
    fireEvent.click(screen.getByRole('button', { name: 'Remove hours Monday 2' }));
    expect(screen.queryByLabelText('Monday start 2')).not.toBeInTheDocument();
    for (let i = 0; i < 4; i++) fireEvent.click(screen.getAllByRole('button', { name: 'Add hours' })[0]);
    expect(screen.getAllByRole('button', { name: 'Add hours' })[0]).toBeDisabled();
    fireEvent.click(screen.getAllByLabelText('All day')[0]);
    expect(screen.queryByLabelText('Monday start 1')).not.toBeInTheDocument();
});

it('shows inline server configuration errors', () => {
    render(<Harness errors={{ ai_weekly_hours: 'Hours overlap.', ai_chatbot_id: 'Select a chatbot.' }} />);
    expect(screen.getAllByRole('alert')).toHaveLength(2);
});
