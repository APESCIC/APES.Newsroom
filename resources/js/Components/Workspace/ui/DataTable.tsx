import type { ReactNode } from 'react';

export type Column<Row> = {
    key: string;
    header: ReactNode;
    /** Label shown beside the value when rows stack on phones. */
    label?: string;
    cell: (row: Row) => ReactNode;
    /** The row's main cell: shown full width without a label on phones. */
    primary?: boolean;
    className?: string;
};

/** Table inside a frosted card; rows stack into labelled cards on phones (#520). */
export default function DataTable<Row>({
    label,
    columns,
    rows,
    rowKey,
    rowClassName,
    rowTestId,
}: {
    label: string;
    columns: Column<Row>[];
    rows: Row[];
    rowKey: (row: Row) => string | number;
    rowClassName?: (row: Row) => string;
    rowTestId?: string;
}) {
    return (
        <div className="sg-card overflow-hidden">
            <table aria-label={label} className="sg-table">
                <thead>
                    <tr>
                        {columns.map((column) => (
                            <th key={column.key} scope="col" className={column.className}>
                                {column.header}
                            </th>
                        ))}
                    </tr>
                </thead>
                <tbody>
                    {rows.map((row) => (
                        <tr key={rowKey(row)} className={rowClassName?.(row)} data-testid={rowTestId}>
                            {columns.map((column) => (
                                <td
                                    key={column.key}
                                    className={column.className}
                                    data-primary={column.primary ? '' : undefined}
                                    data-label={column.primary ? undefined : (column.label ?? (typeof column.header === 'string' ? column.header : undefined))}
                                >
                                    {column.cell(row)}
                                </td>
                            ))}
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}
