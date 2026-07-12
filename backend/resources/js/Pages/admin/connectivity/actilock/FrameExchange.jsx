import { Head, usePage } from '@inertiajs/react';
import AppLayout from '../../../../layouts/AppLayout';

const DELIM = '`';

const FRAMES = [
    {
        code: '0x10',
        codeInt: 0x10,
        name: 'Start',
        direction: 'PLC → AEG',
        action: 'ACTILOCK_Start()',
        description: "Demande d'autorisation de debut d'operation.",
        payload: [
            { key: 'SITE', type: 'string', required: false, desc: 'Site (fallback config globale)' },
            { key: 'SFC', type: 'string', required: true, desc: 'Numero de serie produit (Shop Floor Code)' },
            { key: 'RESOURCE', type: 'string', required: true, desc: 'Ressource/station (ex: R_TF_20412)' },
            { key: 'OPERATION', type: 'string', required: true, desc: 'Operation (ex: OP_TF_20412)' },
            { key: 'USER', type: 'string', required: true, desc: 'Operateur (badge/login)' },
            { key: 'MANORDER', type: 'string', required: false, desc: "Ordre de fabrication" },
        ],
        examplePayload: 'SITE=USine_01`SFC=20412441680852`RESOURCE=R_TF_20412`OPERATION=OP_TF_20412`USER=operateur01`MANORDER=OF123',
        responses: [
            { value: 'READY', desc: 'Autorise — le produit peut demarrer' },
            { value: 'HOLD', desc: 'En attente — produit en attente interblocage' },
            { value: 'NOK', desc: 'Refuse — verification echouee' },
        ],
    },
    {
        code: '0x11',
        codeInt: 0x11,
        name: 'Complete',
        direction: 'PLC → AEG',
        action: 'ACTILOCK_Complete()',
        description: "Declaration de fin d'operation (succes).",
        payload: [
            { key: 'SITE', type: 'string', required: false, desc: 'Site' },
            { key: 'SFC', type: 'string', required: true, desc: 'Numero de serie' },
            { key: 'RESOURCE', type: 'string', required: true, desc: 'Ressource' },
            { key: 'OPERATION', type: 'string', required: true, desc: 'Operation' },
            { key: 'USER', type: 'string', required: true, desc: 'Operateur' },
        ],
        examplePayload: 'SITE=USine_01`SFC=20412441680852`RESOURCE=R_TF_20412`OPERATION=OP_TF_20412`USER=operateur01',
        responses: [
            { value: 'OK', desc: 'Operation terminee avec succes' },
            { value: 'NOK', desc: 'Echec de la completion' },
        ],
    },
    {
        code: '0x12',
        codeInt: 0x12,
        name: 'NcLogComplete',
        direction: 'PLC → AEG',
        action: 'ACTILOCK_NcLogComplete()',
        description: "Enregistrement d'un defaut specifique sur un produit.",
        payload: [
            { key: 'SITE', type: 'string', required: false, desc: 'Site' },
            { key: 'SFC', type: 'string', required: true, desc: 'Numero de serie' },
            { key: 'RESOURCE', type: 'string', required: true, desc: 'Ressource' },
            { key: 'OPERATION', type: 'string', required: true, desc: 'Operation' },
            { key: 'USER', type: 'string', required: true, desc: 'Operateur' },
            { key: 'NCCODE', type: 'string', required: true, desc: 'Code defaut (ex: NC_SOLDER)' },
            { key: 'LOCATION', type: 'string', required: false, desc: 'Repere topographique PCB (ex: R12)' },
            { key: 'NBDEFAULT', type: 'string', required: false, desc: 'Nombre de defauts' },
            { key: 'REFERENCE', type: 'string', required: false, desc: 'Reference composant' },
            { key: 'COMPONENT', type: 'string', required: false, desc: 'Nom du composant' },
        ],
        examplePayload: 'SITE=USine_01`SFC=20412441680852`RESOURCE=R_TF_20412`OPERATION=OP_TF_20412`USER=operateur01`NCCODE=NC_SOLDER`LOCATION=R12`NBDEFAULT=2`REFERENCE=REF_CAP_100UF`COMPONENT=C12',
        responses: [
            { value: 'OK', desc: 'Defaut enregistre dans ACTILOCK' },
            { value: 'NOK', desc: "Erreur d'enregistrement" },
        ],
    },
    {
        code: '0x13',
        codeInt: 0x13,
        name: 'ProductStatus',
        direction: 'PLC → AEG',
        action: 'ACTILOCK_ProductStatus()',
        description: "Consultation du statut actuel du produit dans le systeme.",
        payload: [
            { key: 'PARAMETER', type: 'string', required: true, desc: 'Type de requete (voir ci-dessous)' },
            { key: 'SFC', type: 'string', required: true, desc: 'Numero de serie' },
        ],
        parameters: [
            { value: 'STATUS', desc: 'Statut global du produit' },
            { value: 'CURRENTPOS', desc: 'Position courante (operation en cours)' },
            { value: 'NEXTOP', desc: 'Prochaine operation si passage OK' },
            { value: 'CURRENTLOOP', desc: 'Boucle courante (rebouclage)' },
            { value: 'NEXTOPIFPASS', desc: 'Prochaine operation si passage' },
            { value: 'NEXTOPIFFAIL', desc: 'Prochaine operation si echec' },
        ],
        examplePayload: 'PARAMETER=STATUS`SFC=20412441680852',
        responses: [
            { value: '<valeur>', desc: 'Valeur textuelle retournee par ACTILOCK (ex: LOCKED, UNLOCKED, etc.)' },
        ],
    },
    {
        code: '0x14',
        codeInt: 0x14,
        name: 'Init',
        direction: 'PLC → AEG',
        action: 'ACTILOCK_Init()',
        description: "Initialisation d'un produit dans le systeme sans le verrouiller.",
        payload: [
            { key: 'SITE', type: 'string', required: false, desc: 'Site' },
            { key: 'SFC', type: 'string', required: true, desc: 'Numero de serie' },
            { key: 'RESOURCE', type: 'string', required: true, desc: 'Ressource' },
            { key: 'OPERATION', type: 'string', required: true, desc: 'Operation' },
            { key: 'USER', type: 'string', required: true, desc: 'Operateur' },
            { key: 'MANORDER', type: 'string', required: false, desc: 'Ordre de fabrication' },
        ],
        examplePayload: 'SITE=USine_01`SFC=20412441680852`RESOURCE=R_TF_20412`OPERATION=OP_TF_20412`USER=operateur01',
        responses: [
            { value: 'OK', desc: 'Produit initialise' },
            { value: 'NOK', desc: "Echec de l'initialisation" },
        ],
    },
    {
        code: '0x15',
        codeInt: 0x15,
        name: 'InQueue',
        direction: 'PLC → AEG',
        action: 'ACTILOCK_InQueue()',
        description: "Mise en file d'attente interblocage pour un produit.",
        payload: [
            { key: 'SITE', type: 'string', required: false, desc: 'Site' },
            { key: 'SFC', type: 'string', required: true, desc: 'Numero de serie' },
            { key: 'RESOURCE', type: 'string', required: true, desc: 'Ressource' },
            { key: 'OPERATION', type: 'string', required: true, desc: 'Operation' },
            { key: 'USER', type: 'string', required: true, desc: 'Operateur' },
            { key: 'NCCODE', type: 'string', required: false, desc: 'Code defaut associe' },
        ],
        examplePayload: 'SITE=USine_01`SFC=20412441680852`RESOURCE=R_TF_20412`OPERATION=OP_TF_20412`USER=operateur01`NCCODE=NC_SOLDER',
        responses: [
            { value: 'OK', desc: 'Produit en file d\'attente' },
            { value: 'NOK', desc: 'Erreur de mise en file' },
        ],
    },
    {
        code: '0x16',
        codeInt: 0x16,
        name: 'QuickComplete',
        direction: 'PLC → AEG',
        action: 'ACTILOCK_QuickComplete()',
        description: "Completion acceleree — sans verification supplementaire.",
        payload: [
            { key: 'SITE', type: 'string', required: false, desc: 'Site' },
            { key: 'SFC', type: 'string', required: true, desc: 'Numero de serie' },
            { key: 'RESOURCE', type: 'string', required: true, desc: 'Ressource' },
            { key: 'OPERATION', type: 'string', required: true, desc: 'Operation' },
            { key: 'USER', type: 'string', required: true, desc: 'Operateur' },
        ],
        examplePayload: 'SITE=USine_01`SFC=20412441680852`RESOURCE=R_TF_20412`OPERATION=OP_TF_20412`USER=operateur01',
        responses: [
            { value: 'OK', desc: 'Quick completion reussie' },
            { value: 'NOK', desc: 'Echec' },
        ],
    },
    {
        code: '0x17',
        codeInt: 0x17,
        name: 'IsExpectedAt',
        direction: 'PLC → AEG',
        action: 'ACTILOCK_IsExpectedAt()',
        description: "Verification — le produit est-il attendu a cette operation ?",
        payload: [
            { key: 'SFC', type: 'string', required: true, desc: 'Numero de serie' },
            { key: 'OPERATION', type: 'string', required: true, desc: 'Operation a verifier' },
        ],
        examplePayload: 'SFC=20412441680852`OPERATION=OP_TF_20412',
        responses: [
            { value: 'TRUE', desc: 'Le produit est attendu a cette operation' },
            { value: 'FALSE', desc: 'Le produit n\'est pas attendu' },
        ],
    },
    {
        code: '0x18',
        codeInt: 0x18,
        name: 'IsItLockable',
        direction: 'PLC → AEG',
        action: 'ACTILOCK_IsItLockable()',
        description: "Verification — le produit est-il verrouillable ?",
        payload: [
            { key: 'SFC', type: 'string', required: true, desc: 'Numero de serie' },
        ],
        examplePayload: 'SFC=20412441680852',
        responses: [
            { value: 'TRUE', desc: 'Le produit peut etre verrouille' },
            { value: 'FALSE', desc: 'Le produit ne peut pas etre verrouille' },
        ],
    },
    {
        code: '0x19',
        codeInt: 0x19,
        name: 'NextOp',
        direction: 'PLC → AEG',
        action: 'ACTILOCK_NextOp()',
        description: "Calcul de la prochaine operation du routage.",
        payload: [
            { key: 'ROUTER', type: 'string', required: true, desc: 'Identifiant du routage' },
            { key: 'REVISION', type: 'string', required: true, desc: 'Revision du routage' },
            { key: 'OPERATION', type: 'string', required: true, desc: 'Operation courante' },
        ],
        examplePayload: 'ROUTER=ROUTER_01`REVISION=REV_A`OPERATION=OP_TF_20412',
        responses: [
            { value: '<operation>', desc: 'Nom de la prochaine operation' },
            { value: 'END', desc: 'Fin du routage — plus d\'operation' },
        ],
    },
];

const ERROR_CODES = [
    { code: 'PARSE_ERROR', desc: 'Trame generique mal formee' },
    { code: 'FRAME_TOO_SHORT', desc: 'Trame < 4 octets' },
    { code: 'MISSING_STX', desc: 'Octet de debut manquant (0x02 attendu)' },
    { code: 'MISSING_ETX', desc: 'Octet de fin manquant (0x03 attendu)' },
    { code: 'UNKNOWN_CODE', desc: 'Code fonction inconnu' },
    { code: 'LEN_MISMATCH', desc: "Longueur declaree LEN differente de la taille reelle du payload" },
    { code: 'EMPTY_PAYLOAD', desc: 'Payload vide (LEN=0)' },
    { code: 'ACTILOCK_ERROR', desc: 'Erreur retournee par le moteur ACTILOCK' },
    { code: 'DISPATCH_ERROR', desc: 'Erreur interne du bridge Python' },
];

function hexEncode(str) {
    return Array.from(str).map((c) => c.charCodeAt(0).toString(16).padStart(2, '0').toUpperCase()).join(' ');
}

function buildExampleHex(frame) {
    const payload = frame.examplePayload;
    const stx = '02';
    const code = frame.codeInt.toString(16).padStart(2, '0').toUpperCase();
    const len = payload.length.toString(16).padStart(2, '0').toUpperCase();
    const etx = '03';
    return `${stx} ${code} ${len} ${hexEncode(payload)} ${etx}`;
}

export default function FrameExchange() {
    const { connection } = usePage().props;

    return (
        <>
            <Head title="Frame Exchange — ISA-95 Protocol" />
            <div className="p-6 max-w-5xl">
                <div className="mb-6">
                    <a href={`/admin/connectivity/actilock/${connection.id}`}
                        className="text-sm text-om-muted hover:underline">
                        Back to {connection.name}
                    </a>
                    <h1 className="text-2xl font-bold text-om-ink mt-3">
                        Frame Exchange — ISA-95 Protocol
                    </h1>
                    <p className="text-sm text-om-faint mt-1">
                        Reference complete des trames TCP echangees entre le PLC et l'AEG.
                    </p>
                </div>

                {/* Frame Structure */}
                <Card title="Structure d'une trame">
                    <div className="bg-om-panel rounded-om p-4 font-mono text-sm overflow-x-auto">
                        <div className="flex items-center gap-0 text-center">
                            <HexByte label="STX" hex="02" color="bg-blue-600" />
                            <HexByte label="CODE" hex="XX" color="bg-purple-600" />
                            <HexByte label="LEN" hex="XX" color="bg-amber-600" />
                            <div className="bg-om-faint/20 border border-om-faint/30 px-4 py-2 rounded text-om-muted text-xs">
                                PAYLOAD (variable, backtick-delimited)
                            </div>
                            <HexByte label="ETX" hex="03" color="bg-blue-600" />
                        </div>
                    </div>
                    <div className="mt-3 grid grid-cols-5 gap-2 text-xs">
                        <Legend color="bg-blue-600" label="STX/ETX" desc="Delimitateurs de trame" />
                        <Legend color="bg-purple-600" label="CODE" desc="Fonction (0x10-0x19)" />
                        <Legend color="bg-amber-600" label="LEN" desc="Taille payload (1-255 octets)" />
                        <div className="col-span-2"><Legend color="bg-om-faint" label="PAYLOAD" desc="Paires cle=valeur separees par backtick" /></div>
                    </div>
                </Card>

                {/* Rules */}
                <Card title="Regles de gestion">
                    <div className="grid grid-cols-2 gap-3 text-sm">
                        <Row label="Encodage" value="UTF-8 pour toutes les chaines" />
                        <Row label="Taille max trame" value="1024 octets (payload max ~1019 octets)" />
                        <Row label="Timeout lecture" value="5 secondes — fermeture si pas de trame complete" />
                        <Row label="Timeout reponse" value="20 secondes — timeout ACTILOCK" />
                        <Row label="Multi-PLC" value="Max 50 connexions simultanees" />
                        <Row label="Sequencement" value="Mutex — appels sequentiels vers ACTILOCK" />
                    </div>
                </Card>

                {/* Summary Table */}
                <Card title="Codes fonction">
                    <div className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="border-b border-om-line2 bg-om-panel">
                                    <th className="text-left px-3 py-2 font-medium text-om-muted">Code</th>
                                    <th className="text-left px-3 py-2 font-medium text-om-muted">Nom</th>
                                    <th className="text-left px-3 py-2 font-medium text-om-muted">Direction</th>
                                    <th className="text-left px-3 py-2 font-medium text-om-muted">Fonction ACTILOCK</th>
                                    <th className="text-left px-3 py-2 font-medium text-om-muted">Description</th>
                                </tr>
                            </thead>
                            <tbody>
                                {FRAMES.map((f) => (
                                    <tr key={f.codeInt} className="border-b border-om-line2 last:border-0 hover:bg-om-panel/50">
                                        <td className="px-3 py-2 font-mono text-xs text-om-ink">{f.code}</td>
                                        <td className="px-3 py-2 font-medium text-om-ink">{f.name}</td>
                                        <td className="px-3 py-2 text-xs">
                                            <span className="inline-flex items-center gap-1 text-blue-600 font-medium">
                                                {f.direction}
                                            </span>
                                        </td>
                                        <td className="px-3 py-2 font-mono text-xs text-om-muted">{f.action}</td>
                                        <td className="px-3 py-2 text-xs text-om-faint">{f.description}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </Card>

                {/* Detailed Frames */}
                <h2 className="text-lg font-bold text-om-ink mt-8 mb-4">Trames detaillees</h2>
                {FRAMES.map((frame) => (
                    <FrameDetail key={frame.codeInt} frame={frame} />
                ))}

                {/* Error Codes */}
                <Card title="Codes d'erreur — reponse AEG → PLC">
                    <p className="text-sm text-om-faint mb-3">
                        En cas d'erreur de parsing ou d'appel ACTILOCK, l'AEG renvoie une trame d'erreur :
                    </p>
                    <div className="bg-om-panel rounded-om p-3 font-mono text-xs text-om-muted mb-4 overflow-x-auto">
                        STX(02) | CODE | LEN | ERROR`{'{error_code}'}`{'{message}'} | ETX(03)
                    </div>
                    <div className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="border-b border-om-line2 bg-om-panel">
                                    <th className="text-left px-3 py-2 font-medium text-om-muted">Error Code</th>
                                    <th className="text-left px-3 py-2 font-medium text-om-muted">Description</th>
                                </tr>
                            </thead>
                            <tbody>
                                {ERROR_CODES.map((e) => (
                                    <tr key={e.code} className="border-b border-om-line2 last:border-0">
                                        <td className="px-3 py-2 font-mono text-xs text-red-600">{e.code}</td>
                                        <td className="px-3 py-2 text-xs text-om-faint">{e.desc}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </Card>

                {/* Priority Chain */}
                <Card title="Chaine de priorite — Resolution des champs">
                    <p className="text-sm text-om-faint mb-3">
                        Pour chaque champ (SITE, RESOURCE, OPERATION, USER), la resolution suit cette hierarchie :
                    </p>
                    <div className="space-y-2">
                        <PriorityRow priority="1" label="Payload PLC" desc="Valeur envoyee directement par le PLC dans la trame" />
                        <PriorityRow priority="2" label="Config per-workstation" desc="Table workstation_actilock_configs — config specifique a une IP PLC" />
                        <PriorityRow priority="3" label="Defauts globaux" desc="Champs ressource/operation/user de la table actilock_connections" />
                    </div>
                </Card>
            </div>
        </>
    );
}

FrameExchange.layout = (page) => <AppLayout>{page}</AppLayout>;

function FrameDetail({ frame }) {
    const hexStr = buildExampleHex(frame);
    const payloadHex = hexEncode(frame.examplePayload);

    return (
        <div className="bg-om-card rounded-om border border-om-line2 p-5 mb-4">
            <div className="flex items-center gap-3 mb-4">
                <span className="font-mono text-sm bg-purple-100 text-purple-800 px-2 py-0.5 rounded">{frame.code}</span>
                <h3 className="text-base font-bold text-om-ink">{frame.name}</h3>
                <span className="text-xs text-blue-600 font-medium">{frame.direction}</span>
                <span className="text-xs text-om-faint">→ {frame.action}</span>
            </div>
            <p className="text-sm text-om-faint mb-4">{frame.description}</p>

            {/* Payload Fields */}
            <h4 className="text-xs font-semibold text-om-muted uppercase tracking-wider mb-2">Payload fields</h4>
            <div className="overflow-x-auto mb-4">
                <table className="w-full text-sm">
                    <thead>
                        <tr className="border-b border-om-line2 bg-om-panel">
                            <th className="text-left px-3 py-1.5 font-medium text-om-muted text-xs">Cle</th>
                            <th className="text-left px-3 py-1.5 font-medium text-om-muted text-xs">Type</th>
                            <th className="text-center px-3 py-1.5 font-medium text-om-muted text-xs">Req</th>
                            <th className="text-left px-3 py-1.5 font-medium text-om-muted text-xs">Description</th>
                        </tr>
                    </thead>
                    <tbody>
                        {frame.payload.map((p) => (
                            <tr key={p.key} className="border-b border-om-line2 last:border-0">
                                <td className="px-3 py-1.5 font-mono text-xs text-om-ink">{p.key}</td>
                                <td className="px-3 py-1.5 text-xs text-om-faint">{p.type}</td>
                                <td className="px-3 py-1.5 text-center">
                                    {p.required
                                        ? <span className="text-xs font-medium text-red-600">Oui</span>
                                        : <span className="text-xs text-om-faint">Non</span>}
                                </td>
                                <td className="px-3 py-1.5 text-xs text-om-faint">{p.desc}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>

            {/* ProductStatus parameters */}
            {frame.parameters && (
                <div className="mb-4">
                    <h4 className="text-xs font-semibold text-om-muted uppercase tracking-wider mb-2">Valeurs de PARAMETER</h4>
                    <div className="grid grid-cols-2 gap-1">
                        {frame.parameters.map((p) => (
                            <div key={p.value} className="flex items-center gap-2 text-xs">
                                <code className="font-mono text-om-ink bg-om-panel px-1.5 py-0.5 rounded">{p.value}</code>
                                <span className="text-om-faint">{p.desc}</span>
                            </div>
                        ))}
                    </div>
                </div>
            )}

            {/* Hex Example */}
            <h4 className="text-xs font-semibold text-om-muted uppercase tracking-wider mb-2">Exemple hex</h4>
            <div className="bg-om-panel rounded-om p-3 font-mono text-xs text-om-muted overflow-x-auto mb-2">
                {hexStr}
            </div>
            <div className="bg-om-panel rounded-om p-2 font-mono text-xs text-om-faint overflow-x-auto mb-4">
                Payload: {frame.examplePayload}
            </div>

            {/* Responses */}
            <h4 className="text-xs font-semibold text-om-muted uppercase tracking-wider mb-2">Reponses AEG → PLC</h4>
            <div className="space-y-1">
                {frame.responses.map((r) => (
                    <div key={r.value} className="flex items-center gap-3 text-xs">
                        <code className="font-mono text-green-700 bg-green-50 px-2 py-0.5 rounded border border-green-200">{r.value}</code>
                        <span className="text-om-faint">{r.desc}</span>
                    </div>
                ))}
            </div>
        </div>
    );
}

function Card({ title, children }) {
    return (
        <div className="bg-om-card rounded-om border border-om-line2 p-5 mb-4">
            <h3 className="text-sm font-semibold text-om-muted uppercase tracking-wider mb-3">{title}</h3>
            {children}
        </div>
    );
}

function Row({ label, value }) {
    return (
        <div className="flex justify-between text-sm">
            <dt className="text-om-faint">{label}</dt>
            <dd className="text-om-ink text-right">{value}</dd>
        </div>
    );
}

function HexByte({ label, hex, color }) {
    return (
        <div className={`${color} text-white px-3 py-2 rounded text-center min-w-[60px]`}>
            <div className="font-bold text-xs">{label}</div>
            <div className="font-mono text-xs mt-0.5">{hex}</div>
        </div>
    );
}

function Legend({ color, label, desc }) {
    return (
        <div className="flex items-center gap-2 text-xs">
            <span className={`w-3 h-3 rounded ${color}`} />
            <span className="font-medium text-om-ink">{label}</span>
            <span className="text-om-faint">— {desc}</span>
        </div>
    );
}

function PriorityRow({ priority, label, desc }) {
    return (
        <div className="flex items-start gap-3">
            <span className="flex items-center justify-center w-6 h-6 rounded-full bg-om-accent text-white text-xs font-bold shrink-0">
                {priority}
            </span>
            <div>
                <span className="text-sm font-medium text-om-ink">{label}</span>
                <p className="text-xs text-om-faint">{desc}</p>
            </div>
        </div>
    );
}
