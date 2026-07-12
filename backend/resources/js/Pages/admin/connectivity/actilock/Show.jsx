import { Head, router, usePage } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import { useSyncedShape } from '../../../../lib/useSyncedShape';
import AppLayout from '../../../../layouts/AppLayout';

export default function ActilockShow() {
    const { connection } = usePage().props;
    const al = connection.actilock;
    const [testResult, setTestResult] = useState(null);
    const [testing, setTesting] = useState(false);

    // Live connection status + counters
    const { data: allConnections = [] } = useSyncedShape('actilock_connections');
    const liveConn = useMemo(
        () => allConnections.find((c) => c.id === al?.id) ?? al,
        [allConnections, al],
    );

    // Live interlock logs for this connection
    const { data: allLogs = [] } = useSyncedShape('actilock_interlock_logs');
    const recentLogs = useMemo(
        () => allLogs
            .filter((l) => l.actilock_connection_id === al?.id)
            .sort((a, b) => (b.id ?? 0) - (a.id ?? 0))
            .slice(0, 50),
        [allLogs, al],
    );

    const testConnection = async () => {
        setTesting(true);
        setTestResult(null);
        try {
            const res = await fetch(`/api/v1/actilock/${connection.id}/test`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                },
            });
            const data = await res.json();
            setTestResult(data);
        } catch (err) {
            setTestResult({ status: 'error', message: err.message });
        } finally {
            setTesting(false);
        }
    };

    const statusColor = liveConn?.status === 'connected' ? 'green'
        : liveConn?.status === 'error' ? 'red'
        : liveConn?.status === 'connecting' ? 'yellow'
        : 'slate';

    return (
        <>
            <Head title={connection.name} />
            <div className="p-6 max-w-5xl">
                <div className="mb-6">
                    <a href="/admin/connectivity/actilock"
                        className="text-sm text-om-muted hover:underline">
                        Back to ACTILOCK Connections
                    </a>
                    <div className="flex items-center justify-between mt-3">
                        <h1 className="text-2xl font-bold text-om-ink">{connection.name}</h1>
                        <div className="flex items-center gap-3">
                            <span className="inline-flex items-center gap-1.5 text-xs text-green-600 font-medium">
                                <span className="w-1.5 h-1.5 rounded-full bg-green-500 animate-pulse" />
                                Live
                            </span>
                            <button
                                onClick={testConnection}
                                disabled={testing}
                                className="px-4 py-2 bg-blue-600 text-white rounded-om text-sm hover:bg-blue-700 disabled:opacity-50">
                                {testing ? 'Testing...' : 'Test Connection'}
                            </button>
                            <a href={`/admin/connectivity/actilock/${connection.id}/edit`}
                                className="px-4 py-2 bg-om-chip text-om-muted rounded-om text-sm">
                                Edit
                            </a>
                        </div>
                    </div>
                </div>

                {/* Live Status Card */}
                <div className="bg-om-card rounded-om border border-om-line2 p-5 mb-6">
                    <div className="flex items-center gap-3">
                        <span className={`w-2.5 h-2.5 rounded-full
                            ${statusColor === 'green' ? 'bg-green-500' :
                              statusColor === 'red' ? 'bg-red-500' :
                              statusColor === 'yellow' ? 'bg-yellow-500 animate-pulse' :
                              'bg-om-faint'}`} />
                        <span className="font-medium text-om-ink capitalize">{liveConn?.status}</span>
                        {liveConn?.status_message && (
                            <span className="text-xs text-om-faint ml-2">— {liveConn.status_message}</span>
                        )}
                    </div>
                </div>

                {/* Config Details */}
                <div className="grid grid-cols-2 gap-4 mb-6">
                    <Card title="ACTILOCK Defaults">
                        <Row label="Site" value={al?.site || '—'} />
                        <Row label="System" value={al?.system || '—'} />
                        <Row label="Document" value={al?.document || '—'} />
                    </Card>

                    <Card title="Engine (VM#1)">
                        <Row label="Host" value={al?.engine_host || '—'} mono />
                        <Row label="Port" value={al?.engine_port || '—'} mono />
                        <Row label="Library" value={al?.lib_path || '—'} mono />
                        <Row label="FFI Timeout" value={al?.ffi_timeout_seconds ? `${al.ffi_timeout_seconds}s` : '—'} />
                        <Row label="TCP Read Timeout" value={al?.tcp_read_timeout_seconds ? `${al.tcp_read_timeout_seconds}s` : '—'} />
                    </Card>

                    <Card title="TCP Server">
                        <Row label="Listen Host" value={al?.listen_host || '—'} mono />
                        <Row label="Listen Port" value={al?.listen_port || '—'} mono />
                        <Row label="Max Connections" value={al?.max_plc_connections || '—'} />
                    </Card>

                    {/* Live Counters */}
                    <Card title="Counters" live>
                        <Row label="Total Interlocks" value={liveConn?.interlocks_total ?? 0} />
                        <Row label="Rejected" value={liveConn?.interlocks_rejected ?? 0} />
                        <Row label="Start (0x10)" value={liveConn?.start_count ?? 0} />
                        <Row label="Complete (0x11)" value={liveConn?.complete_count ?? 0} />
                        <Row label="NcLog (0x12)" value={liveConn?.nclog_count ?? 0} />
                        <Row label="Last Connected" value={liveConn?.last_connected_at || 'Never'} />
                    </Card>
                </div>

                {/* Workstation Configs link */}
                <a href={`/admin/connectivity/actilock/${connection.id}/workstation-configs`}
                    className="block bg-om-card rounded-om border border-om-line2 p-5 mb-6 hover:border-om-accent transition-colors">
                    <div className="flex items-center justify-between">
                        <div>
                            <h3 className="text-sm font-semibold text-om-muted uppercase tracking-wider">Per-Workstation Configs</h3>
                            <p className="text-xs text-om-faint mt-1">
                                Map PLC IPs to resource, operation and user for this connection.
                            </p>
                        </div>
                        <span className="text-om-accent text-sm">Manage &rarr;</span>
                    </div>
                </a>

                {/* Frame Exchange link */}
                <a href={`/admin/connectivity/actilock/${connection.id}/frames`}
                    className="block bg-om-card rounded-om border border-om-line2 p-5 mb-6 hover:border-om-accent transition-colors">
                    <div className="flex items-center justify-between">
                        <div>
                            <h3 className="text-sm font-semibold text-om-muted uppercase tracking-wider">Frame Exchange — ISA-95 Protocol</h3>
                            <p className="text-xs text-om-faint mt-1">
                                Reference complete des trames TCP echangees entre le PLC et l'AEG.
                            </p>
                        </div>
                        <span className="text-om-accent text-sm">View &rarr;</span>
                    </div>
                </a>

                {/* Launch command hint */}
                <div className="bg-om-card rounded-om border border-om-line2 p-5 mb-6">
                    <h3 className="text-sm font-semibold text-om-muted uppercase tracking-wider mb-3">Python Bridge (Recommended)</h3>
                    <code className="block bg-om-panel rounded-om p-3 text-xs font-mono text-om-ink overflow-x-auto">
                        AEG_CONNECTION_ID={connection.id} python3 /opt/aeg/python/interlock_bridge.py
                    </code>
                    <p className="text-xs text-om-faint mt-2">
                        Or install as systemd service: <code>sudo bash deploy-bridge.sh</code>
                    </p>
                </div>

                {/* Test result */}
                {testResult && (
                    <div className={`rounded-om border p-4 mb-6 ${
                        testResult.status === 'ok'
                            ? 'bg-green-50 border-green-200 text-green-800'
                            : 'bg-red-50 border-red-200 text-red-800'
                    }`}>
                        <p className="font-medium">{testResult.message}</p>
                    </div>
                )}

                {/* Live Recent Interlocks */}
                <div className="bg-om-card rounded-om border border-om-line2 overflow-hidden mb-6">
                    <div className="px-5 py-4 border-b border-om-line2 flex items-center justify-between">
                        <h3 className="text-sm font-semibold text-om-muted uppercase tracking-wider">Recent Interlocks</h3>
                        <span className="inline-flex items-center gap-1.5 text-xs text-green-600 font-medium">
                            <span className="w-1.5 h-1.5 rounded-full bg-green-500 animate-pulse" />
                            Live ({recentLogs.length})
                        </span>
                    </div>
                    {recentLogs.length === 0 ? (
                        <div className="p-8 text-center text-om-faint text-sm">
                            No interlocks recorded yet. Start the Python bridge to receive frames.
                        </div>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className="border-b border-om-line2 bg-om-panel">
                                        <th className="text-left px-4 py-2 font-medium text-om-muted text-xs">Time</th>
                                        <th className="text-left px-4 py-2 font-medium text-om-muted text-xs">Frame</th>
                                        <th className="text-left px-4 py-2 font-medium text-om-muted text-xs">PLC IP</th>
                                        <th className="text-left px-4 py-2 font-medium text-om-muted text-xs">SFC</th>
                                        <th className="text-left px-4 py-2 font-medium text-om-muted text-xs">Operation</th>
                                        <th className="text-left px-4 py-2 font-medium text-om-muted text-xs">User</th>
                                        <th className="text-center px-4 py-2 font-medium text-om-muted text-xs">Result</th>
                                        <th className="text-right px-4 py-2 font-medium text-om-muted text-xs">Duration</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {recentLogs.map((log) => (
                                        <tr key={log.id} className="border-b border-om-line2 last:border-0 hover:bg-om-panel/50">
                                            <td className="px-4 py-2 text-xs text-om-faint whitespace-nowrap">
                                                {log.event_timestamp
                                                    ? new Date(log.event_timestamp).toLocaleTimeString()
                                                    : '—'}
                                            </td>
                                            <td className="px-4 py-2">
                                                <span className={`inline-flex items-center gap-1 text-xs font-medium
                                                    ${log.frame_code === 0x10 ? 'text-blue-600' :
                                                      log.frame_code === 0x11 ? 'text-green-600' :
                                                      log.frame_code === 0x12 ? 'text-purple-600' :
                                                      'text-om-muted'}`}>
                                                    <span className={`w-1.5 h-1.5 rounded-full
                                                        ${log.frame_code === 0x10 ? 'bg-blue-500' :
                                                          log.frame_code === 0x11 ? 'bg-green-500' :
                                                          log.frame_code === 0x12 ? 'bg-purple-500' :
                                                          'bg-om-faint'}`} />
                                                    {log.frame_label}
                                                </span>
                                            </td>
                                            <td className="px-4 py-2 font-mono text-xs text-om-muted">{log.plc_ip}</td>
                                            <td className="px-4 py-2 font-mono text-xs text-om-ink">{log.sfc || '—'}</td>
                                            <td className="px-4 py-2 text-xs text-om-ink">{log.operation || '—'}</td>
                                            <td className="px-4 py-2 text-xs text-om-ink">{log.user || '—'}</td>
                                            <td className="px-4 py-2 text-center">
                                                {log.is_accepted === true && (
                                                    <span className="inline-flex items-center text-xs font-medium text-green-600">Accepted</span>
                                                )}
                                                {log.is_accepted === false && (
                                                    <span className="inline-flex items-center text-xs font-medium text-red-600">Rejected</span>
                                                )}
                                                {log.is_accepted === null && (
                                                    <span className="text-xs text-om-faint">—</span>
                                                )}
                                            </td>
                                            <td className="px-4 py-2 text-right text-xs text-om-faint">
                                                {log.duration_ms != null ? `${log.duration_ms}ms` : '—'}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </div>
            </div>
        </>
    );
}

ActilockShow.layout = (page) => <AppLayout>{page}</AppLayout>;

function Card({ title, children, live }) {
    return (
        <div className="bg-om-card rounded-om border border-om-line2 p-5">
            <div className="flex items-center justify-between mb-3">
                <h3 className="text-sm font-semibold text-om-muted uppercase tracking-wider">{title}</h3>
                {live && (
                    <span className="inline-flex items-center gap-1 text-xs text-green-600 font-medium">
                        <span className="w-1 h-1 rounded-full bg-green-500 animate-pulse" />
                        Live
                    </span>
                )}
            </div>
            <dl className="space-y-1.5">{children}</dl>
        </div>
    );
}

function Row({ label, value, mono }) {
    return (
        <div className="flex justify-between text-sm">
            <dt className="text-om-faint">{label}</dt>
            <dd className={`text-om-ink ${mono ? 'font-mono text-xs' : ''}`}>{value}</dd>
        </div>
    );
}
