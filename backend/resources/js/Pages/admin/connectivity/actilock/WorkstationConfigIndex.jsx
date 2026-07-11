import { Head, router, usePage } from '@inertiajs/react';
import AppLayout from '../../../../layouts/AppLayout';

export default function WorkstationConfigIndex() {
    const { connection, configs } = usePage().props;

    return (
        <>
            <Head title={`Workstation Configs — ${connection.name}`} />
            <div className="p-6 max-w-5xl">
                <div className="mb-6">
                    <a href={`/admin/connectivity/actilock/${connection.id}`}
                        className="text-sm text-om-muted hover:underline">
                        Back to {connection.name}
                    </a>
                    <div className="flex items-center justify-between mt-3">
                        <h1 className="text-2xl font-bold text-om-ink">Workstation Configs</h1>
                        <a href={`/admin/connectivity/actilock/${connection.id}/workstation-configs/create`}
                            className="px-4 py-2 bg-om-accent text-white rounded-om text-sm font-medium">
                            + Add Config
                        </a>
                    </div>
                    <p className="text-sm text-om-faint mt-1">
                        Map PLC IPs to resource, operation and user for this ACTILOCK connection.
                    </p>
                </div>

                {configs.length === 0 ? (
                    <div className="bg-om-card rounded-om border border-om-line2 p-12 text-center">
                        <p className="text-om-faint">No workstation configs yet.</p>
                    </div>
                ) : (
                    <div className="bg-om-card rounded-om border border-om-line2 overflow-hidden">
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="border-b border-om-line2 bg-om-panel">
                                    <th className="px-4 py-3 text-left font-medium text-om-muted">PLC IP</th>
                                    <th className="px-4 py-3 text-left font-medium text-om-muted">Resource</th>
                                    <th className="px-4 py-3 text-left font-medium text-om-muted">Operation</th>
                                    <th className="px-4 py-3 text-left font-medium text-om-muted">User</th>
                                    <th className="px-4 py-3 text-left font-medium text-om-muted">Site</th>
                                    <th className="px-4 py-3 text-center font-medium text-om-muted">Active</th>
                                    <th className="px-4 py-3 text-right font-medium text-om-muted">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                {configs.map((cfg) => (
                                    <tr key={cfg.id} className="border-b border-om-line2 last:border-0 hover:bg-om-panel/50">
                                        <td className="px-4 py-3 font-mono text-om-ink">{cfg.plc_ip}</td>
                                        <td className="px-4 py-3 text-om-muted">{cfg.resource || '—'}</td>
                                        <td className="px-4 py-3 text-om-muted">{cfg.operation || '—'}</td>
                                        <td className="px-4 py-3 text-om-muted">{cfg.user || '—'}</td>
                                        <td className="px-4 py-3 text-om-muted">{cfg.site || '—'}</td>
                                        <td className="px-4 py-3 text-center">
                                            <span className={`inline-block w-2 h-2 rounded-full ${cfg.is_active ? 'bg-green-500' : 'bg-om-faint'}`} />
                                        </td>
                                        <td className="px-4 py-3 text-right space-x-2">
                                            <a href={`/admin/connectivity/actilock/${connection.id}/workstation-configs/${cfg.id}/edit`}
                                                className="text-om-accent hover:underline text-xs">
                                                Edit
                                            </a>
                                            <button
                                                onClick={() => {
                                                    if (confirm(`Delete config for ${cfg.plc_ip}?`)) {
                                                        router.delete(`/admin/connectivity/actilock/${connection.id}/workstation-configs/${cfg.id}`, {
                                                            preserveScroll: true,
                                                        });
                                                    }
                                                }}
                                                className="text-red-500 hover:underline text-xs">
                                                Delete
                                            </button>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}
            </div>
        </>
    );
}

WorkstationConfigIndex.layout = (page) => <AppLayout>{page}</AppLayout>;
