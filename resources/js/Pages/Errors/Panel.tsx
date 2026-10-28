import { LayoutDashboard, SearchX, ShieldAlert } from 'lucide-react';
import WorkspaceLayout from '../../Components/Layout/WorkspaceLayout';
import type { WorkspaceArea } from '../../Components/Workspace/navigation';
import EmptyState from '../../Components/Workspace/ui/EmptyState';
import PillButton from '../../Components/Workspace/ui/PillButton';

type Props = {
    status: 403 | 404;
    title: string;
    message: string;
    area: WorkspaceArea;
    home: string;
    requiredRole: string | null;
};

/** 404 and 403 pages for /staff/* and /admin/*, kept inside the panel layout (#354). */
export default function PanelError({ status, title, message, area, home, requiredRole }: Props) {
    return (
        <WorkspaceLayout area={area} title={title} documentTitle={`${status} ${title}`}>
            <EmptyState
                icon={status === 403 ? ShieldAlert : SearchX}
                title={status === 403 ? (requiredRole ? `${requiredRole} role required` : 'Not available for your role') : `Error ${status}`}
                description={message}
                action={
                    <PillButton href={home} icon={LayoutDashboard}>
                        Go to the {area} dashboard
                    </PillButton>
                }
            />
        </WorkspaceLayout>
    );
}
