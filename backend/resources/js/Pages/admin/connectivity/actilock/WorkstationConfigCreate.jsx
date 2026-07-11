import { Head, usePage } from '@inertiajs/react';
import AppLayout from '../../../../layouts/AppLayout';
import WorkstationConfigForm from './WorkstationConfigForm';

export default function WorkstationConfigCreate() {
    const { connection } = usePage().props;

    return (
        <>
            <Head title={`New Workstation Config — ${connection.name}`} />
            <div className="p-6 max-w-3xl">
                <div className="mb-6">
                    <a href={`/admin/connectivity/actilock/${connection.id}/workstation-configs`}
                        className="text-sm text-om-muted hover:underline">
                        Back to Workstation Configs
                    </a>
                    <h1 className="text-2xl font-bold text-om-ink mt-3">New Workstation Config</h1>
                </div>

                <WorkstationConfigForm
                    action={`/admin/connectivity/actilock/${connection.id}/workstation-configs`}
                    method="post"
                    submitLabel="Create Config"
                    cancelHref={`/admin/connectivity/actilock/${connection.id}/workstation-configs`}
                />
            </div>
        </>
    );
}

WorkstationConfigCreate.layout = (page) => <AppLayout>{page}</AppLayout>;
