import { useForm } from '@inertiajs/react';

export default function WorkstationConfigForm({
    action, method, submitLabel, cancelHref, config = null,
}) {
    const form = useForm({
        plc_ip:          config?.plc_ip ?? '',
        workstation_id:  config?.workstation_id ?? '',
        resource:        config?.resource ?? '',
        operation:       config?.operation ?? '',
        user:            config?.user ?? '',
        sfc_prefix:      config?.sfc_prefix ?? '',
        site:            config?.site ?? '',
        system:          config?.system ?? '',
        is_active:       config?.is_active ?? true,
    });

    const { data, setData, errors, processing } = form;

    const submit = (e) => {
        e.preventDefault();
        form.submit(method, action);
    };

    return (
        <form onSubmit={submit} className="space-y-6">
            {/* PLC Identification */}
            <Section title="PLC Identification">
                <p className="text-xs text-om-faint mb-3">
                    The PLC IP is the unique key used by the Python bridge to resolve this config.
                </p>
                <Field label="PLC IP" required error={errors.plc_ip}>
                    <input
                        type="text"
                        value={data.plc_ip}
                        onChange={(e) => setData('plc_ip', e.target.value)}
                        placeholder="192.168.10.51"
                        required
                        className="form-input w-full font-mono"
                    />
                </Field>
                <Field label="Linked Workstation (optional)" error={errors.workstation_id}>
                    <input
                        type="number"
                        value={data.workstation_id}
                        onChange={(e) => setData('workstation_id', e.target.value)}
                        placeholder="Leave empty if not linked"
                        className="form-input w-full"
                    />
                </Field>
            </Section>

            {/* ISA-95 Mapping */}
            <Section title="ISA-95 Mapping">
                <p className="text-xs text-om-faint mb-3">
                    These values override the global defaults from the ACTILOCK connection when set.
                </p>
                <div className="grid grid-cols-2 gap-4">
                    <Field label="Resource" error={errors.resource}>
                        <input
                            type="text"
                            value={data.resource}
                            onChange={(e) => setData('resource', e.target.value)}
                            placeholder="STATION_01"
                            className="form-input w-full font-mono"
                        />
                    </Field>
                    <Field label="Operation" error={errors.operation}>
                        <input
                            type="text"
                            value={data.operation}
                            onChange={(e) => setData('operation', e.target.value)}
                            placeholder="ASSEMBLY"
                            className="form-input w-full font-mono"
                        />
                    </Field>
                    <Field label="User" error={errors.user}>
                        <input
                            type="text"
                            value={data.user}
                            onChange={(e) => setData('user', e.target.value)}
                            placeholder="OPERATOR_01"
                            className="form-input w-full font-mono"
                        />
                    </Field>
                    <Field label="SFC Prefix" error={errors.sfc_prefix}>
                        <input
                            type="text"
                            value={data.sfc_prefix}
                            onChange={(e) => setData('sfc_prefix', e.target.value)}
                            placeholder="SFC"
                            className="form-input w-full font-mono"
                        />
                    </Field>
                </div>
            </Section>

            {/* Site / System Override */}
            <Section title="Site &amp; System Override">
                <p className="text-xs text-om-faint mb-3">
                    Leave empty to use the global defaults from the ACTILOCK connection.
                </p>
                <div className="grid grid-cols-2 gap-4">
                    <Field label="Site" error={errors.site}>
                        <input
                            type="text"
                            value={data.site}
                            onChange={(e) => setData('site', e.target.value)}
                            placeholder="Use global default"
                            className="form-input w-full font-mono"
                        />
                    </Field>
                    <Field label="System" error={errors.system}>
                        <input
                            type="text"
                            value={data.system}
                            onChange={(e) => setData('system', e.target.value)}
                            placeholder="Use global default"
                            className="form-input w-full font-mono"
                        />
                    </Field>
                </div>
            </Section>

            {/* Status */}
            <Section title="Status">
                <div className="flex items-center gap-2">
                    <input
                        type="checkbox"
                        id="is_active"
                        checked={data.is_active}
                        onChange={(e) => setData('is_active', e.target.checked)}
                        className="rounded border-om-line2 text-om-accent"
                    />
                    <label htmlFor="is_active" className="text-sm text-om-muted">Active</label>
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
