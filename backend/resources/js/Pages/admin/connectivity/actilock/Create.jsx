import { Head } from '@inertiajs/react';
import AppLayout from '../../../../layouts/AppLayout';
import ActilockConnectionForm from './ActilockConnectionForm';

export default function ActilockCreate() {
    return (
        <>
            <Head title="New ACTILOCK Connection" />
            <div className="p-6 max-w-2xl">
                <div className="mb-6">
                    <a href="/admin/connectivity/actilock" className="text-sm text-om-muted hover:underline">
                        Back to ACTILOCK Connections
                    </a>
                    <h1 className="mt-3 text-2xl font-bold text-om-ink">New ACTILOCK Connection</h1>
                </div>

                <ActilockConnectionForm
                    action="/admin/connectivity/actilock"
                    method="post"
                    submitLabel="Create Connection"
                    cancelHref="/admin/connectivity/actilock"
                />
            </div>
        </>
    );
}

ActilockCreate.layout = (page) => <AppLayout>{page}</AppLayout>;
