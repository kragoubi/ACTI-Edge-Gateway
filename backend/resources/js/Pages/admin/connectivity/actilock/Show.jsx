import { Head, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import AppLayout from '../../../../layouts/AppLayout';

export default function ActilockShow() {
    const { connection } = usePage().props;
    const al = connection.actilock;
    const [testResult, setTestResult] = useState(null);
    const [testing, setTesting] = useState(false);

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

    return (
        <>
            <Head title={connection.name} />
            <div className="p-6 max-w-3xl">
                <div className="mb-6">
                    <a href="/admin/connectivity/actilock"
                        className="text-sm text-om-muted hover:underline">
                        Back to ACTILOCK Connections
                    </a>
                    <div className="flex items-center justify-between mt-3">
                        <h1 className="text-2xl font-bold text-om-ink">{connection.name}</h1>
                        <div className="flex gap-2">
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

                {/* Status Card */}
                <div className="bg-om-card rounded-om border border-om-line2 p-5 mb-6">
                    <div className="flex items-center gap-3">
                        <span className={`w-2.5 h-2.5 rounded-full
                            ${connection.status_color === 'green' ? 'bg-green-500' :
                              connection.status_color === 'red' ? 'bg-red-500' :
                              connection.status_color === 'yellow' ? 'bg-yellow-500' :
                              'bg-om-faint'}`} />
                        <span className="font-medium text-om-ink capitalize">{connection.status}</span>
                        {connection.status_message && (
                            <span className="text-xs text-om-faint ml-2">— {connection.status_message}</span>
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

                    <Card title="Counters">
                        <Row label="Total Interlocks" value={al?.interlocks_total ?? 0} />
                        <Row label="Rejected" value={al?.interlocks_rejected ?? 0} />
                        <Row label="Start (0x10)" value={al?.start_count ?? 0} />
                        <Row label="Complete (0x11)" value={al?.complete_count ?? 0} />
                        <Row label="NcLog (0x12)" value={al?.nclog_count ?? 0} />
                        <Row label="Last Connected" value={al?.last_connected_at || 'Never'} />
                    </Card>
                </div>

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
            </div>
        </>
    );
}

ActilockShow.layout = (page) => <AppLayout>{page}</AppLayout>;

function Card({ title, children }) {
    return (
        <div className="bg-om-card rounded-om border border-om-line2 p-5">
            <h3 className="text-sm font-semibold text-om-muted uppercase tracking-wider mb-3">{title}</h3>
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
