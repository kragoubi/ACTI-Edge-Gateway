import { Head, usePage } from '@inertiajs/react';
import AppLayout from '../../../../layouts/AppLayout';
import WorkstationConfigForm from './WorkstationConfigForm';

export default function WorkstationConfigEdit() {
    const { connection, config } = usePage().props;

    return (
        <>
            <Head title={`Edit ${config.plc_ip} — ${connection.name}`} />
            <div className="p-6 max-w-3xl">
                <div className="mb-6">
                    <a href={`/admin/connectivity/actilock/${connection.id}/workstation-configs`}
                        className="text-sm text-om-muted hover:underline">
                        Back to Workstation Configs
                    </a>
                    <h1 className="text-2xl font-bold text-om-ink mt-3">
                        Edit Config — {config.plc_ip}
                    </h1>
                </div>

                <WorkstationConfigForm
                    action={`/admin/connectivity/actilock/${connection.id}/workstation-configs/${config.id}`}
                    method="put"
                    submitLabel="Save Changes"
                    cancelHref={`/admin/connectivity/actilock/${connection.id}/workstation-configs`}
                    config={config}
                />
            </div>
        </>
    );
}

WorkstationConfigEdit.layout = (page) => <AppLayout>{page}</AppLayout>;
