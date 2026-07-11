import { Head, router, usePage } from '@inertiajs/react';
import AppLayout from '../../../../layouts/AppLayout';
import ActilockConnectionForm from './ActilockConnectionForm';

export default function ActilockEdit() {
    const { connection } = usePage().props;

    const handleDelete = () => {
        if (confirm('Delete this ACTILOCK connection and all interlock logs?')) {
            router.delete(`/admin/connectivity/actilock/${connection.id}`);
        }
    };

    return (
        <>
            <Head title={`Edit ${connection.name}`} />
            <div className="p-6 max-w-2xl">
                <div className="mb-6">
                    <a href={`/admin/connectivity/actilock/${connection.id}`}
                        className="text-sm text-om-muted hover:underline">
                        Back to {connection.name}
                    </a>
                    <h1 className="mt-3 text-2xl font-bold text-om-ink">Edit {connection.name}</h1>
                </div>

                <ActilockConnectionForm
                    action={`/admin/connectivity/actilock/${connection.id}`}
                    method="put"
                    submitLabel="Save Changes"
                    cancelHref={`/admin/connectivity/actilock/${connection.id}`}
                    connection={connection}
                    onDelete={handleDelete}
                />
            </div>
        </>
    );
}

ActilockEdit.layout = (page) => <AppLayout>{page}</AppLayout>;
