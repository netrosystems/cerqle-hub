import { describe, expect, it } from 'vitest';
import { buildSupervisorConfig } from '@/Pages/Admin/CronSetup/Index';

describe('Cron Setup worker configuration', () => {
    it('isolates every queue and provisions dedicated broadcast processes', () => {
        const config = buildSupervisorConfig({
            basePath: '/srv/cerqle',
            phpBinary: '/usr/bin/php',
            queueConnection: 'redis',
            queueNames: ['default', 'whatsapp', 'broadcast'],
        });

        expect(config).toContain('[program:cerqle-worker-default]');
        expect(config).toContain('--queue=default');
        expect(config).toContain('[program:cerqle-worker-whatsapp]');
        expect(config).toContain('--queue=whatsapp');
        expect(config).toContain('[program:cerqle-worker-broadcast]');
        expect(config).toContain('--queue=broadcast');
        expect(config).not.toContain('--queue=default,whatsapp,broadcast');
    });
});
