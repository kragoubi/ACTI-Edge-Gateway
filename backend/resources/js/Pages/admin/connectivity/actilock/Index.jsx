import { Head } from '@inertiajs/react';
import { useMemo } from 'react';
import { useSyncedShape } from '../../../../lib/useSyncedShape';
import AppLayout from '../../../../layouts/AppLayout';

export default function ActilockIndex({ connections: serverConnections }) {
    const { data: liveConnections = [] } = useSyncedShape('actilock_connections');

    // Merge server data with live data — live wins when available
    const connections = useMemo(() => {
        if (liveConnections.length > 0) return liveConnections;
        return serverConnections;
    }, [liveConnections, serverConnections]);

    return (
        <>
            <Head title="ACTILOCK Connections" />
            <div className="p-6">
                <div className="flex items-center justify-between mb-6">
                    <div className="flex items-center gap-3">
                        <h1 className="text-2xl font-bold text-om-ink">ACTILOCK Connections</h1>
                        {liveConnections.length > 0 && (
                            <span className="inline-flex items-center gap-1.5 text-xs text-green-600 font-medium">
                                <span className="w-1.5 h-1.5 rounded-full bg-green-500 animate-pulse" />
                                Live
                            </span>
                        )}
                    </div>
                    <a href="/admin/connectivity/actilock/create"
                        className="px-4 py-2 bg-om-accent text-white rounded-om text-sm font-medium">
                        + New Connection
                    </a>
                </div>

                {connections.length === 0 ? (
                    <div className="bg-om-card rounded-om border border-om-line2 p-12 text-center">
                        <p className="text-om-muted mb-4">No ACTILOCK connections configured.</p>
                        <a href="/admin/connectivity/actilock/create"
                            className="text-om-accent hover:underline text-sm">
                            Create your first connection
                        </a>
                    </div>
                ) : (
                    <div className="bg-om-card rounded-om border border-om-line2 overflow-hidden">
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="border-b border-om-line2 bg-om-panel">
                                    <th className="text-left px-4 py-3 font-medium text-om-muted">Name</th>
                                    <th className="text-left px-4 py-3 font-medium text-om-muted">Status</th>
                                    <th className="text-left px-4 py-3 font-medium text-om-muted">Engine</th>
                                    <th className="text-left px-4 py-3 font-medium text-om-muted">Listen</th>
                                    <th className="text-right px-4 py-3 font-medium text-om-muted">Interlocks</th>
                                    <th className="text-left px-4 py-3 font-medium text-om-muted">Last Seen</th>
                                    <th className="px-4 py-3"></th>
                                </tr>
                            </thead>
                            <tbody>
                                {connections.map((c) => (
                                    <tr key={c.id} className="border-b border-om-line2 last:border-0 hover:bg-om-panel/50">
                                        <td className="px-4 py-3">
                                            <div className="font-medium text-om-ink">{c.name}</div>
                                            {c.description && (
                                                <div className="text-xs text-om-faint mt-0.5">{c.description}</div>
                                            )}
                                        </td>
                                        <td className="px-4 py-3">
                                            <span className={`inline-flex items-center gap-1.5 text-xs font-medium
                                                ${c.status === 'connected' ? 'text-green-600' :
                                                  c.status === 'error' ? 'text-red-600' :
                                                  c.status === 'connecting' ? 'text-yellow-600' :
                                                  'text-om-faint'}`}>
                                                <span className={`w-1.5 h-1.5 rounded-full
                                                    ${c.status === 'connected' ? 'bg-green-500' :
                                                      c.status === 'error' ? 'bg-red-500' :
                                                      c.status === 'connecting' ? 'bg-yellow-500 animate-pulse' :
                                                      'bg-om-faint'}`} />
                                                {c.status}
                                            </span>
                                        </td>
                                        <td className="px-4 py-3 font-mono text-xs text-om-muted">
                                            {c.engine_host}:{c.engine_port}
                                        </td>
                                        <td className="px-4 py-3 font-mono text-xs text-om-muted">
                                            {c.listen_host}:{c.listen_port}
                                        </td>
                                        <td className="px-4 py-3 text-right text-om-muted">
                                            {c.interlocks_total}
                                        </td>
                                        <td className="px-4 py-3 text-xs text-om-faint">
                                            {c.last_connected_at ?? 'Never'}
                                        </td>
                                        <td className="px-4 py-3 text-right">
                                            <a href={`/admin/connectivity/actilock/${c.id}`}
                                                className="text-om-accent hover:underline text-xs">
                                                View
                                            </a>
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

ActilockIndex.layout = (page) => <AppLayout>{page}</AppLayout>;
