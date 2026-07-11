import { useState } from 'react';
import { useForm } from '@inertiajs/react';

export default function ActilockConnectionForm({
    action, method, submitLabel, cancelHref, connection = null, onDelete
}) {
    const al = connection?.actilock ?? null;

    const form = useForm({
        name:                      connection?.name ?? '',
        description:               connection?.description ?? '',
        is_active:                 connection?.is_active ?? false,
        document:                  al?.document ?? '',
        site:                      al?.site ?? '',
        system:                    al?.system ?? '',
        listen_host:               al?.listen_host ?? '0.0.0.0',
        listen_port:               String(al?.listen_port ?? 5000),
        max_plc_connections:       String(al?.max_plc_connections ?? 50),
        engine_host:               al?.engine_host ?? '192.168.1.1',
        engine_port:               String(al?.engine_port ?? 5000),
        lib_path:                  al?.lib_path ?? '/usr/lib/lib_actilock.so',
        ffi_timeout_seconds:       String(al?.ffi_timeout_seconds ?? 5),
        tcp_read_timeout_seconds:  String(al?.tcp_read_timeout_seconds ?? 5),
    });

    const { data, setData, errors, processing } = form;

    const submit = (e) => {
        e.preventDefault();
        form.submit(method, action);
    };

    return (
        <form onSubmit={submit} className="space-y-6">
            {/* General */}
            <Section title="General">
                <Field label="Name" required error={errors.name}>
                    <input type="text" value={data.name}
                        onChange={(e) => setData('name', e.target.value)}
                        required className="form-input w-full" />
                </Field>
                <Field label="Description" error={errors.description}>
                    <textarea value={data.description}
                        onChange={(e) => setData('description', e.target.value)}
                        rows={2} className="form-input w-full" />
                </Field>
                <div className="flex items-center gap-2">
                    <input type="checkbox" id="is_active" checked={data.is_active}
                        onChange={(e) => setData('is_active', e.target.checked)}
                        className="rounded border-om-line2 text-om-accent" />
                    <label htmlFor="is_active" className="text-sm text-om-muted">Active</label>
                </div>
            </Section>

            {/* ACTILOCK Defaults */}
            <Section title="ACTILOCK Defaults">
                <p className="text-xs text-om-faint mb-3">
                    Global defaults applied to all stations. Resource, Operation and User are configured per workstation.
                </p>
                <div className="grid grid-cols-2 gap-4">
                    <Field label="Site" error={errors.site}>
                        <input type="text" value={data.site}
                            onChange={(e) => setData('site', e.target.value)}
                            placeholder="FACTORY_01" className="form-input w-full font-mono" />
                    </Field>
                    <Field label="System" error={errors.system}>
                        <input type="text" value={data.system}
                            onChange={(e) => setData('system', e.target.value)}
                            className="form-input w-full font-mono" />
                    </Field>
                    <Field label="Document" error={errors.document}>
                        <input type="text" value={data.document}
                            onChange={(e) => setData('document', e.target.value)}
                            className="form-input w-full font-mono" />
                    </Field>
                </div>
            </Section>

            {/* TCP Server (PLC listener) */}
            <Section title="TCP Server (PLC Listener)">
                <p className="text-xs text-om-faint mb-3">
                    The gateway listens for incoming TCP connections from PLCs on this address.
                </p>
                <div className="grid grid-cols-3 gap-4">
                    <div className="col-span-2">
                        <Field label="Listen Host" required error={errors.listen_host}>
                            <input type="text" value={data.listen_host}
                                onChange={(e) => setData('listen_host', e.target.value)}
                                placeholder="0.0.0.0" required className="form-input w-full font-mono" />
                        </Field>
                    </div>
                    <div>
                        <Field label="Listen Port" required error={errors.listen_port}>
                            <input type="number" value={data.listen_port}
                                onChange={(e) => setData('listen_port', e.target.value)}
                                min="1" max="65535" required className="form-input w-full font-mono" />
                        </Field>
                    </div>
                </div>
                <Field label="Max PLC Connections" required error={errors.max_plc_connections}>
                    <input type="number" value={data.max_plc_connections}
                        onChange={(e) => setData('max_plc_connections', e.target.value)}
                        min="1" max="200" required className="form-input w-32 font-mono" />
                </Field>
            </Section>

            {/* ACTILOCK Engine (VM#1) */}
            <Section title="ACTILOCK Engine (VM#1)">
                <p className="text-xs text-om-faint mb-3">
                    FFI connection to the ACTILOCK server via lib_actilock.so.
                </p>
                <div className="grid grid-cols-3 gap-4">
                    <div className="col-span-2">
                        <Field label="Engine Host" required error={errors.engine_host}>
                            <input type="text" value={data.engine_host}
                                onChange={(e) => setData('engine_host', e.target.value)}
                                placeholder="192.168.1.1" required className="form-input w-full font-mono" />
                        </Field>
                    </div>
                    <div>
                        <Field label="Engine Port" required error={errors.engine_port}>
                            <input type="number" value={data.engine_port}
                                onChange={(e) => setData('engine_port', e.target.value)}
                                min="1" max="65535" required className="form-input w-full font-mono" />
                        </Field>
                    </div>
                </div>
                <Field label="Library Path (.so)" required error={errors.lib_path}>
                    <input type="text" value={data.lib_path}
                        onChange={(e) => setData('lib_path', e.target.value)}
                        required className="form-input w-full font-mono" />
                </Field>
            </Section>

            {/* Timeouts */}
            <Section title="Timeouts">
                <div className="grid grid-cols-2 gap-4">
                    <Field label="FFI Timeout (seconds)" required error={errors.ffi_timeout_seconds}>
                        <input type="number" value={data.ffi_timeout_seconds}
                            onChange={(e) => setData('ffi_timeout_seconds', e.target.value)}
                            min="1" max="30" required className="form-input w-32 font-mono" />
                    </Field>
                    <Field label="TCP Read Timeout (seconds)" required error={errors.tcp_read_timeout_seconds}>
                        <input type="number" value={data.tcp_read_timeout_seconds}
                            onChange={(e) => setData('tcp_read_timeout_seconds', e.target.value)}
                            min="1" max="30" required className="form-input w-32 font-mono" />
                    </Field>
                </div>
            </Section>

            {/* Actions */}
            <div className="flex gap-3 pt-2">
                <button type="submit" disabled={processing}
                    className="px-5 py-2 bg-om-accent text-white rounded-om font-medium disabled:opacity-50">
                    {processing ? 'Saving...' : submitLabel}
                </button>
                <a href={cancelHref}
                    className="px-5 py-2 bg-om-chip text-om-muted rounded-om">
                    Cancel
                </a>
                {onDelete && (
                    <button type="button" onClick={onDelete}
                        className="px-5 py-2 bg-red-600 text-white rounded-om ml-auto">
                        Delete Connection
                    </button>
                )}
            </div>
        </form>
    );
}

function Section({ title, children }) {
    return (
        <div className="bg-om-card rounded-om border border-om-line2 p-5 space-y-4">
            <h2 className="text-sm font-semibold text-om-muted uppercase tracking-wider">{title}</h2>
            {children}
        </div>
    );
}

function Field({ label, required, error, children }) {
    return (
        <div>
            <label className="block text-sm font-medium text-om-muted mb-1">
                {label} {required && <span className="text-om-blocked">*</span>}
            </label>
            {children}
            {error && <p className="mt-1 text-xs text-om-blocked">{error}</p>}
        </div>
    );
}
