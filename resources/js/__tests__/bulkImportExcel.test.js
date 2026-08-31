import ExcelJS from 'exceljs';
import { describe, expect, it } from 'vitest';
import { parseWorkbookToMatrix } from '@/Pages/Contacts/bulkImportExcel';

describe('contact Excel import compatibility', () => {
    it('writes and reads a workbook with the secured UUID dependency', async () => {
        const workbook = new ExcelJS.Workbook();
        const sheet = workbook.addWorksheet('Contacts');
        sheet.addRow(['Name', 'Phone', 'Contact list', 'Segment']);
        sheet.addRow(['Jane Doe', '+15551234567', 'Customers', 'VIP']);
        sheet.addRow(['John Smith', '+447700900123', '2', '']);

        const buffer = await workbook.xlsx.writeBuffer();
        const rows = await parseWorkbookToMatrix(
            buffer,
            [{ id: 2, name: 'Leads' }, { id: 3, name: 'Customers' }],
            [{ id: 8, name: 'VIP' }],
        );

        expect(rows).toEqual([
            ['Jane Doe', '+15551234567', 'Customers', 'VIP'],
            ['John Smith', '+447700900123', 'Leads', ''],
        ]);
    });
});
