# ACTI Edge Gateway (AEG) — Cahier des Charges Technique

**ACTI Edge Gateway (AEG)** est la passerelle de communication industrielle du
Serveur de Ligne. Positionnée en périphérie du réseau OT (Edge), elle assure
l'acquisition, la normalisation, le routage et la sécurisation des échanges
entre les équipements de production (PLC, bancs de tests, lecteurs, SCADA) et
les services centraux tels qu'ACTILOCK Engine, la traçabilité et les
applications de supervision.

Missions principales :

- Communication temps réel avec les PLC (TCP Socket)
- Serveur OPC UA pour la supervision
- Traduction et normalisation des protocoles industriels
- Interface avec ACTILOCK Engine
- Gestion des connexions simultanées
- Routage sécurisé des messages
- Journalisation et diagnostic
- Supervision de son état de fonctionnement
- Évolutivité vers MQTT, REST API et autres protocoles industriels

Cahier des charges complet pour l'implémentation d'AEG communiquant avec le
moteur d'interblocage ACTILOCK via `lib_actilock.so` (PHP FFI).

---

## Table of Contents

- [1. Objet et périmètre](#1-objet-et-périmètre)
- [2. Architecture technique](#2-architecture-technique)
- [3. Protocole TCP — Format des trames](#3-protocole-tcp--format-des-trames)
- [4. API native lib_actilock.so — Binding FFI](#4-api-native-lib_actilockso--binding-ffi)
- [5. Modèle de données](#5-modèle-de-données)
- [6. Composants logiciels](#6-composants-logiciels)
- [7. Interface admin](#7-interface-admin)
- [8. Routes](#8-routes)
- [9. Sécurité](#9-sécurité)
- [10. Performance](#10-performance)
- [11. Tests](#11-tests)
- [12. Plan d'implémentation](#12-plan-dimplémentation)
- [13. Risques et mitigations](#13-risques-et-mitigations)
- [A. Déploiement VM#2 Linux](#a-déploiement-vm2-linux)
- [B. Tolérance aux pannes](#b-tolérance-aux-pannes)
- [C. Cycle de vie complet — tous les cas](#c-cycle-de-vie-complet--tous-les-cas)
- [D. Audit trail et conformité ISA-95](#d-audit-trail-et-conformité-isa-95)
- [E. API REST pour intégrations tierces](#e-api-rest-pour-intégrations-tierces)
- [F. Mode dégradé / fallback](#f-mode-dégradé--fallback)
- [G. OPC UA Server (Phase 2)](#g-opc-ua-server-phase-2)
- [H. Migration depuis ICOM Traceability](#h-migration-depuis-icom-traceability)
- [I. Gestion de configuration](#i-gestion-de-configuration)
- [J. Cartographie complète des interactions VM#2](#j-cartographie-complète-des-interactions-vm2)
- [Related Documentation](#related-documentation)

---

## 1. Objet et périmètre

### 1.1 Objectif

**AEG** doit jouer le rôle de **Gateway OPC UA / TCP** (remplaçant le VM#2 de
l'architecture OT existante) et communiquer directement avec le moteur
d'interblocage **ACTILOCK** (VM#1) via la bibliothèque native `lib_actilock.so`,
appelée depuis PHP via l'extension **FFI**.

### 1.2 Périmètre

| Composant | Inclus | Exclus |
|---|---|---|
| Serveur TCP (réception trames PLC) | Oui | — |
| Parser de trames STX/CODE/LEN/PAYLOAD/ETX | Oui | — |
| FFI binding lib_actilock.so | Oui | — |
| Service métier ACTILOCK (Start/Complete/NcLogComplete) | Oui | — |
| Serveur OPC UA (pour SCADA) | Non | Phase 2 |
| Remplacement du VM#2 physique | Oui | — |
| Migration ICOM Traceability | Non | Phase 3 |

### 1.3 Cas d'usage cible

1. **Interlock temps réel** : un PLC envoie une trame TCP Start → AEG
   appelle ACTILOCK → retourne READY/HOLD au PLC (< 20ms)
2. **Reporting qualité** : le PLC envoie Complete ou NcLogComplete → AEG
   enregistre le résultat dans ACTILOCK et dans sa propre base
3. **Consultation statut** : le PLC interroge le statut d'un produit via
   ProductStatus
4. **Monitoring** : l'admin AEG visualise l'état de la connexion ACTILOCK,
   les dernières actions, les erreurs

---

## 2. Architecture technique

### 2.1 Schéma global

```
┌─────────────────────────────────────────────────────────────────┐
│                    OpenMES (Laravel 12 + PHP 8.3)              │
│                                                                 │
│  ┌───────────────────────────┐    ┌──────────────────────────┐ │
│  │    InterlockTcpServer     │    │    ActilockService       │ │
│  │    (artisan command)      │───→│    (logique métier)      │ │
│  │                           │    │                          │ │
│  │  stream_socket_server     │    │  connect()               │ │
│  │  tcp://0.0.0.0:5000       │    │  start()                 │ │
│  │                           │    │  complete()              │ │
│  │  Parse trames:            │    │  ncLogComplete()         │ │
│  │  STX|CODE|LEN|PAY|ETX    │    │  productStatus()         │ │
│  └─────────────┬─────────────┘    │  isExpectedAt()          │ │
│                │                   └───────────┬──────────────┘ │
│                │                               │                │
│  ┌─────────────┴───────────────────────────────┴──────────────┐ │
│  │              ActilockLibrary (FFI binding)                  │ │
│  │                                                             │ │
│  │  FFI::cdef($cdef, $libPath)                                │ │
│  │  → ACTILOCK_Connect()                                      │ │
│  │  → ACTILOCK_Start()                                        │ │
│  │  → ACTILOCK_Complete()                                     │ │
│  │  → ACTILOCK_NcLogComplete()                                │ │
│  │  → ACTILOCK_ProductStatus()                                │ │
│  │  → ACTILOCK_IsExpectedAt()                                 │ │
│  │  → ACTILOCK_NetPing()                                      │ │
│  │  → ACTILOCK_EngineVersion()                                │ │
│  │  → ACTILOCK_LibraryVersion()                               │ │
│  └──────────────────────────────┬─────────────────────────────┘ │
│                                 │                               │
│  ┌──────────────────────────────┴─────────────────────────────┐ │
│  │           MachineConnection (protocol = 'actilock')        │ │
│  │           ActilockConnection (config spécifique)            │ │
│  └────────────────────────────────────────────────────────────┘ │
└─────────────────────────────────┬───────────────────────────────┘
                                  │
                                  │ TCP (via lib_actilock.so)
                                  │ host:port/document
                                  ▼
                    ┌──────────────────────────┐
                    │  VM#1 — ACTILOCK Server   │
                    │  (moteur interblocage)    │
                    │                           │
                    │  Gère :                   │
                    │  - Verrous produit        │
                    │  - Routage opérations     │
                    │  - Historique décisions   │
                    └──────────────────────────┘
```

### 2.2 Flux temporel

```
T=0ms     PLC envoie trame TCP Start
T=0.x ms  InterlockTcpServer reçoit via stream_socket_accept/fread
T=1ms     Parser extrait CODE + PAYLOAD
T=2ms     ActilockService::start() appelé
T=3ms     ActilockLibrary via FFI → lib_actilock.so → TCP vers VM#1
T=15ms    VM#1 ACTILOCK répond READY/HOLD
T=16ms    ActilockService retourne le résultat
T=17ms    InterlockTcpServer construit la réponse TCP
T=18ms    fwrite() → PLC reçoit la réponse

Objectif : < 20ms bout en bout
```

### 2.3 Contraintes de déploiement

| Contrainte | Valeur | Justification |
|---|---|---|
| Latence totale | < 20 ms | Exigence OT temps réel |
| Connexions TCP simultanées | jusqu'à 50 PLC | Une ligne de production |
| Débit réseau | 1 Gb/s minimum | Ethernet OT |
| OS serveur | Linux (requis pour .so) | lib_actilock.so est natif Linux |
| PHP version | 8.3+ | Requis par OpenMES + FFI |
| Thread safety | Obligatoire | .so non réentrant |

---

## 3. Protocole TCP — Format des trames

### 3.1 Structure d'une trame

```
┌──────┬────────┬─────┬──────────┬──────┐
│ STX  │  CODE  │ LEN │ PAYLOAD  │  ETX │
│ 1oct │ 1 oct  │1 oct│ Variable │ 1 oct│
└──────┴────────┴─────┴──────────┴──────┘
```

| Champ | Taille | Valeur | Description |
|---|---|---|---|
| STX | 1 octet | `0x02` | Début de trame (Start of TeXt) |
| CODE | 1 octet | `0x10`–`0x13` | Code fonction (voir §3.2) |
| LEN | 1 octet | `0x01`–`0xFF` | Longueur du champ PAYLOAD en octets |
| PAYLOAD | Variable | `clé=valeur\`` séparé par backtick | Données métier (voir §3.3) |
| ETX | 1 octet | `0x03` | Fin de trame (End of TeXt) |

### 3.2 Codes fonction

| Code (Hex) | Nom | Direction | Description | Action ACTILOCK |
|---|---|---|---|---|
| `0x10` | **Start** | PLC → GW | Demande d'autorisation de début d'opération | `ACTILOCK_Start()` |
| `0x11` | **Complete** | PLC → GW | Déclaration de fin d'opération (OK) | `ACTILOCK_Complete()` |
| `0x12` | **NcLogComplete** | PLC → GW | Enregistrement d'un défaut spécifique | `ACTILOCK_NcLogComplete()` |
| `0x13` | **ProductStatus** | PLC → GW | Consultation du statut actuel du produit | `ACTILOCK_ProductStatus()` |

### 3.3 Format du PAYLOAD

Le PAYLOAD est une série de paires `clé=valeur` séparées par le caractère
backtick (`` ` ``).

#### Trame Start (`0x10`)

```
STX │ 0x10 │ LEN │ SFC`RESOURCE`OPERATION`USER`MANORDER │ ETX
```

| Clé | Type | Obligatoire | Description |
|---|---|---|---|
| `SFC` | string | Oui | Numéro de série du produit (Shop Floor Code) |
| `RESOURCE` | string | Oui | Identifiant de la ressource/station |
| `OPERATION` | string | Oui | Identifiant de l'opération |
| `USER` | string | Oui | Identifiant de l'opérateur |
| `MANORDER` | string | Non | Numéro d'ordre de fabrication |

**Exemple** : `` `20412441680852`R_TF_20412`OP_TF_20412`user`OF123` ``

#### Trame Complete (`0x11`)

```
STX │ 0x11 │ LEN │ SFC`RESOURCE`OPERATION`USER │ ETX
```

| Clé | Type | Obligatoire | Description |
|---|---|---|---|
| `SFC` | string | Oui | Numéro de série |
| `RESOURCE` | string | Oui | Ressource |
| `OPERATION` | string | Oui | Opération |
| `USER` | string | Oui | Opérateur |

#### Trame NcLogComplete (`0x12`)

```
STX │ 0x12 │ LEN │ SFC`RESOURCE`OPERATION`USER`NCCODE`LOCATION`NBDEFAULT`REFERENCE`COMPONENT │ ETX
```

| Clé | Type | Obligatoire | Description |
|---|---|---|---|
| `SFC` | string | Oui | Numéro de série |
| `RESOURCE` | string | Oui | Ressource |
| `OPERATION` | string | Oui | Opération |
| `USER` | string | Oui | Opérateur |
| `NCCODE` | string | Oui | Code défaut |
| `LOCATION` | string | Non | Localisation topographique (repère PCB) |
| `NBDEFAULT` | string | Non | Nombre de défauts |
| `REFERENCE` | string | Non | Référence composant |
| `COMPONENT` | string | Non | Nom du composant |

#### Trame ProductStatus (`0x13`)

```
STX │ 0x13 │ LEN │ PARAMETER`SFC │ ETX
```

| Clé | Type | Obligatoire | Description |
|---|---|---|---|
| `PARAMETER` | string | Oui | Type de requête (voir §3.4) |
| `SFC` | string | Oui | Numéro de série |

### 3.4 Paramètres ProductStatus

| Paramètre | Description |
|---|---|
| `STATUS` | Statut global du produit |
| `CURRENTPOS` | Position courante (opération en cours) |
| `NEXTOP` | Prochaine opération si passage OK |
| `CURRENTLOOP` | Boucle courante (rebouclage) |
| `NEXTOPIFPASS` | Prochaine opération si passage |
| `NEXTOPIFFAIL` | Prochaine opération si échec |

### 3.5 Format de la réponse

Le serveur TCP renvoie une trame au même format :

```
STX │ CODE │ LEN │ PAYLOAD │ ETX
```

- **CODE** : même code que la requête (`0x10`, `0x11`, `0x12`, `0x13`)
- **PAYLOAD** : réponse textuelle du moteur ACTILOCK (ex: `READY`, `HOLD`,
  `OK`, `NOK`, ou message d'erreur)
- Si erreur de parsing : `STX │ CODE │ LEN │ ERROR`message`` │ ETX`

### 3.6 Règles de gestion

| Règle | Description |
|---|---|
| **Encodage** | UTF-8 pour toutes les chaînes |
| **Taille max trame** | 1024 octets (PAYLOAD max ~1019 octets) |
| **Timeout lecture** | 5 secondes — si pas de trame complète, fermer la connexion |
| **Timeout réponse** | 20 secondes — timeout ACTILOCK → réponse erreur au PLC |
| **Reconnexion PLC** | Le serveur accepte les reconnexions automatiques |
| **Multi-PLC** | Plusieurs PLC peuvent se connecter simultanément (max 50) |
| **Séquencement** | Les appels vers ACTILOCK sont séquentiels (mutex) |

---

## 4. API native lib_actilock.so — Binding FFI PHP

### 4.1 Déclaration FFI

Le CDEF (C Function Definition) pour FFI :

```c
// Connexion
bool ACTILOCK_Connect(const char* host, unsigned short port,
                       const char* document, char** response);
bool ACTILOCK_NetPing(const char* host);

// Informations
bool ACTILOCK_EngineVersion(char** response);
bool ACTILOCK_LibraryVersion(char** response);

// Cycle de vie produit
bool ACTILOCK_Init(const char* site, const char* sfc, const char* resource,
                   const char* operation, const char* user,
                   const char* manorder, char** response);
bool ACTILOCK_Start(const char* site, const char* sfc, const char* resource,
                    const char* operation, const char* user,
                    const char* manorder, char** response);
bool ACTILOCK_Complete(const char* site, const char* sfc, const char* resource,
                       const char* operation, const char* user,
                       char** response);
bool ACTILOCK_QuickComplete(const char* site, const char* sfc,
                            const char* resource, const char* operation,
                            const char* user, char** response);

// Défauts
bool ACTILOCK_NcLogComplete(const char* site, const char* sfc,
                            const char* resource, const char* operation,
                            const char* user, const char* nccode,
                            const char* location, const char* nbdefault,
                            const char* reference, const char* component,
                            char** response);

// Consultation
bool ACTILOCK_ProductStatus(const char* parameter, const char* sfc,
                            char** response);
bool ACTILOCK_IsExpectedAt(const char* sfc, const char* operation,
                           char** response);
bool ACTILOCK_IsItLockable(const char* sfc, char** response);
bool ACTILOCK_NextOp(const char* router, const char* revision,
                     const char* operation, char** response);

// File d'attente
bool ACTILOCK_InQueue(const char* site, const char* sfc, const char* resource,
                      const char* operation, const char* user,
                      const char* nccode, char** response);

// Dernière réponse
const char* ACTILOCK_GetLastResponse(void);
```

### 4.2 Convention d'appel

Toutes les fonctions suivent la même convention :

- **Entrées** : N arguments `const char*` (chaînes UTF-8)
- **Sortie** : un pointeur `char**` que la bibliothèque remplit avec la
  réponse texte
- **Retour** : `bool` (true = succès, false = échec)

### 4.3 Contraintes du .so

| Contrainte | Description | Mitigation PHP |
|---|---|---|
| **Non réentrant** | Appels concurrents = segfault | Mutex via FFI ou process dédié |
| **Segfault possible** | Le .so peut crasher le process hôte | Isoler dans un process séparé |
| **Connexion requise** | Tout appel action sans `Connect()` = segfault | Vérifier `is_connected` avant chaque appel |
| **TCP probe** | `Connect()` sur host injoignable = crash | Tester TCP reachability avant (socket connect) |

### 4.4 Pattern d'isolation (recommandé)

```
┌─────────────────────────────────────────────────────┐
│  Process Principal (Laravel / web)                  │
│                                                     │
│  InterlockWorker (process séparé via pcntl_fork     │
│                   ou artisan command dédié)          │
│                                                     │
│  ┌─────────────────────────────────────────────┐    │
│  │  Chargement: FFI::cdef($cdef, $soPath)     │    │
│  │  Boucle: lecture pipe stdin → appel FFI     │    │
│  │          → écriture pipe stdout → réponse   │    │
│  │  Si segfault: process meurt, parent détecte │    │
│  │               → respawn automatique         │    │
│  └─────────────────────────────────────────────┘    │
└─────────────────────────────────────────────────────┘
```

---

## 5. Modèle de données

### 5.1 Table `actilock_connections`

```sql
CREATE TABLE actilock_connections (
    id              BIGSERIAL PRIMARY KEY,
    tenant_id       BIGINT NOT NULL REFERENCES tenants(id),
    machine_connection_id BIGINT NOT NULL REFERENCES machine_connections(id)
                          ON DELETE CASCADE,

    -- Connexion ACTILOCK
    host            VARCHAR(255) NOT NULL DEFAULT '',
    port            INTEGER NOT NULL DEFAULT 0,
    document        VARCHAR(255) NOT NULL DEFAULT '',

    -- Paramètres par défaut
    system          VARCHAR(255) NOT NULL DEFAULT '',
    site            VARCHAR(255) NOT NULL DEFAULT '',
    ressource       VARCHAR(255) NOT NULL DEFAULT '',
    operation       VARCHAR(255) NOT NULL DEFAULT '',
    user            VARCHAR(255) NOT NULL DEFAULT '',

    -- Configuration TCP serveur
    listen_host     VARCHAR(45) NOT NULL DEFAULT '0.0.0.0',
    listen_port     INTEGER NOT NULL DEFAULT 5000,
    max_connections INTEGER NOT NULL DEFAULT 50,
    timeout_seconds INTEGER NOT NULL DEFAULT 5,

    -- Statut
    status          VARCHAR(20) NOT NULL DEFAULT 'disconnected',
    status_message  TEXT,
    connected_at    TIMESTAMP NULL,
    version         VARCHAR(100) NULL,

    -- Soft deletes + audit
    deleted_at      TIMESTAMP NULL,
    deleted_by_id   BIGINT NULL REFERENCES users(id) ON DELETE SET NULL,
    created_at      TIMESTAMP NOT NULL,
    updated_at      TIMESTAMP NOT NULL,

    CONSTRAINT actilock_connections_machine_connection_id_unique
        UNIQUE (machine_connection_id)
);

CREATE INDEX idx_actilock_connections_tenant
    ON actilock_connections(tenant_id);
```

### 5.2 Migration `MachineConnection`

Ajouter la constante `PROTOCOL_ACTILOCK = 'actilock'` et le OneToOne :

```php
// MachineConnection.php
const PROTOCOL_ACTILOCK = 'actilock';

public function actilockConnection(): HasOne
{
    return $this->hasOne(ActilockConnection::class);
}
```

### 5.3 SoftDeleteRegistry

Ajouter :

```php
'actilock_connections' => Models\ActilockConnection::class,
```

### 5.4 ShapeRegistry (Electric sync)

Ajouter la shape `actilock_connections` pour le monitoring temps réel dans
l'admin UI.

---

## 6. Composants logiciels

### 6.1 ActilockLibrary — Binding FFI

**Fichier** : `app/Services/Connectivity/Actilock/ActilockLibrary.php`

Responsabilités :

- Charger `lib_actilock.so` via `FFI::cdef()`
- Déclarer toutes les fonctions (§4.1)
- Encapsuler chaque appel avec gestion d'erreur
- Gérer le TCP probe avant `Connect()`
- Thread-safe via mutex

```php
class ActilockLibrary
{
    private FFI $ffi;
    private bool $isConnected = false;
    private \FFI\CData $mutex;

    public function __construct(string $soPath);
    public function connect(string $host, int $port, string $document): array;
    public function start(string $site, string $sfc, string $resource,
        string $op, string $user, string $manorder = ''): array;
    public function complete(string $site, string $sfc, string $resource,
        string $op, string $user): array;
    public function ncLogComplete(string $site, string $sfc, string $resource,
        string $op, string $user, string $nccode, string $location,
        string $nbdefault, string $reference, string $component): array;
    public function productStatus(string $parameter, string $sfc): array;
    public function isExpectedAt(string $sfc, string $operation): array;
    public function netPing(string $host): array;
    public function engineVersion(): array;
    public function libraryVersion(): array;
    public function disconnect(): void;
    public function isConnected(): bool;
}
```

### 6.2 ActilockService — Logique métier

**Fichier** : `app/Services/Connectivity/Actilock/ActilockService.php`

Responsabilités :

- Ordonner les appels FFI (connector → start → complete)
- Gérer les valeurs par défaut (site, resource, operation depuis
  `ActilockConnection`)
- Logger chaque action dans `MachineEvent`
- Incrémenter `messages_received` sur `MachineConnection`
- Fournir le monitoring (status, version, dernière erreur)

### 6.3 InterlockTcpServer — Serveur TCP

**Fichier** : `app/Console/Commands/InterlockTcpServer.php`

```
Signature: interlock:serve
    {--connection= : ActilockConnection ID}
    {--host=0.0.0.0 : Adresse d'écoute}
    {--port=5000 : Port d'écoute}
```

Responsabilités :

- `stream_socket_server("tcp://{$host}:{$port}")` (pattern
  ModbusSimulateCommand)
- `stream_set_blocking($server, false)` pour I/O non-bloquant
- Boucle `stream_select()` pour multiplexer les connexions
- `stream_socket_accept()` pour accepter les nouveaux PLC
- `fread($sock, 1024)` pour lire les trames
- Parser STX|CODE|LEN|PAYLOAD|ETX
- Appeler `ActilockService` selon le CODE
- `fwrite($sock, $response)` pour répondre au PLC
- Gestion des erreurs : trame mal formée → réponse erreur
- Heartbeat vers `RuntimeMonitor` à chaque cycle
- Gestion SIGTERM/SIGINT pour arrêt propre

### 6.4 InterlockMonitorCommand — Monitor loop

**Fichier** : `app/Console/Commands/InterlockMonitorCommand.php`

```
Signature: interlock:monitor
    {--connection= : ActilockConnection ID}
    {--interval=5 : Intervalle en secondes}
```

Responsabilités :

- Boucle de monitoring (pattern `interlock_monitor_loop` Python)
- Vérifier la connectivité TCP toutes les N secondes
- Tenter `ACTILOCK_Connect()` si déconnecté
- Mettre à jour le status sur `ActilockConnection`
- Logger les changements d'état (connecté ↔ déconnecté)

---

## 7. Interface admin

### 7.1 Page de configuration

**Fichier** : `resources/js/Pages/admin/connectivity/ActilockShow.jsx`

Sections :

- **Connexion ACTILOCK** : host, port, document (champs éditables)
- **Paramètres par défaut** : system, site, ressource, operation, user
- **Configuration TCP** : listen_host, listen_port, max_connections, timeout
- **Bouton "Tester la connexion"** : appel API, affiche version/succès/erreur
- **Statut en temps réel** : connected/disconnected/error, version, dernière
  action

### 7.2 Monitoring

**Fichier** : `resources/js/Pages/admin/connectivity/ActilockMonitor.jsx`

- État de la connexion ACTILOCK (badge vert/rouge)
- Nombre de PLC connectés
- Dernières 50 actions (Start/Complete/NcLogComplete)
- Erreurs récentes
- Commande pour démarrer le daemon :
  `php artisan interlock:serve --connection=N`

### 7.3 Navigation

Ajouter dans la page Connectivity existante :

- Nouvel onglet "ACTILOCK" dans la vue d'ensemble
- Lien vers la page de configuration

---

## 8. Routes

```php
// Web (admin)
Route::get('/connectivity/actilock/{connection}',
    [ActilockConnectionController::class, 'show'])
    ->name('connectivity.actilock.show');
Route::put('/connectivity/actilock/{connection}',
    [ActilockConnectionController::class, 'update'])
    ->name('connectivity.actilock.update');
Route::post('/connectivity/actilock/{connection}/test',
    [ActilockConnectionController::class, 'test'])
    ->name('connectivity.actilock.test');

// API (monitoring temps réel)
Route::get('/api/v1/actilock-connections/{id}/status',
    [ActilockApiController::class, 'status']);
Route::get('/api/v1/actilock-connections/{id}/actions',
    [ActilockApiController::class, 'recentActions']);
```

---

## 9. Sécurité

| Risque | Mesure |
|---|---|
| Segfault du .so | Isolation process séparé (worker dédié) |
| Appel non autorisé | Middleware `auth` + role admin pour config |
| Injection dans PAYLOAD | Validation stricte du parser (taille, charset, regex) |
| DoS par connexions multiples | `max_connections` configurable, `stream_select` timeout |
| Crash du serveur TCP | Auto-respawn via supervisord ou systemd |
| Secrets en base | Pas de secrets dans `actilock_connections` |

---

## 10. Performance

| Métrique | Cible | Mesure |
|---|---|---|
| Latence parser trame | < 1 ms | Benchmark interne |
| Latence FFI call | < 5 ms | Benchmark interne |
| Latence totale PLC→réponse | < 20 ms | Mesure end-to-end |
| Connexions simultanées | 50 | Test de charge |
| Uptime daemon | 99.9% | Monitoring heartbeat |
| Memoire PHP | < 100 MB | Surveillance process |

---

## 11. Tests

### 11.1 Tests unitaires

| Test | Fichier |
|---|---|
| Parser trames TCP (valides + mal formées) | `tests/Unit/Services/ActilockTcpParserTest.php` |
| ActilockLibrary FFI mock | `tests/Unit/Services/ActilockLibraryTest.php` |
| ActilockService logique métier | `tests/Unit/Services/ActilockServiceTest.php` |

### 11.2 Tests feature

| Test | Fichier |
|---|---|
| Cycle complet Start→Complete | `tests/Feature/Machine/ActilockCycleTest.php` |
| NcLogComplete avec défauts | `tests/Feature/Machine/ActilockNcLogTest.php` |
| Admin config page | `tests/Feature/Web/ActilockConfigPageTest.php` |
| Connexion/refus max PLC | `tests/Feature/Machine/ActilockMaxConnectionsTest.php` |

### 11.3 Tests E2E

| Test | Fichier |
|---|---|
| PLC simulator → AEG → ACTILOCK mock | `tests/e2e/actilock-flow.spec.ts` |

---

## 12. Plan d'implémentation

### Phase 1 — Fondations (Semaine 1)

| # | Tâche | Fichiers |
|---|---|---|
| 1.1 | Activer PHP FFI (php.ini) | `php.ini` |
| 1.2 | Migration `actilock_connections` | `database/migrations/xxxx_*.php` |
| 1.3 | Model `ActilockConnection` | `app/Models/ActilockConnection.php` |
| 1.4 | Modifier `MachineConnection` | `app/Models/MachineConnection.php` |
| 1.5 | Ajouter au SoftDeleteRegistry | `app/Support/SoftDeleteRegistry.php` |

### Phase 2 — FFI + Service (Semaine 2)

| # | Tâche | Fichiers |
|---|---|---|
| 2.1 | `ActilockLibrary` (FFI binding) | `app/Services/Connectivity/Actilock/ActilockLibrary.php` |
| 2.2 | `ActilockService` (logique métier) | `app/Services/Connectivity/Actilock/ActilockService.php` |
| 2.3 | Tests unitaires FFI + Service | `tests/Unit/Services/Actilock*.php` |

### Phase 3 — Serveur TCP (Semaine 3)

| # | Tâche | Fichiers |
|---|---|---|
| 3.1 | Parser trames STX/CODE/LEN/PAYLOAD/ETX | `app/Services/Connectivity/Actilock/TcpFrameParser.php` |
| 3.2 | `InterlockTcpServer` (artisan command) | `app/Console/Commands/InterlockTcpServer.php` |
| 3.3 | `InterlockMonitorCommand` | `app/Console/Commands/InterlockMonitorCommand.php` |
| 3.4 | Intégrer `RuntimeMonitor` | Modifier `RuntimeMonitor.php` |

### Phase 4 — Admin UI (Semaine 4)

| # | Tâche | Fichiers |
|---|---|---|
| 4.1 | Routes | `routes/web.php` |
| 4.2 | Controller | `app/Http/Controllers/Web/Admin/ActilockConnectionController.php` |
| 4.3 | Page config | `resources/js/Pages/admin/connectivity/ActilockShow.jsx` |
| 4.4 | Page monitoring | `resources/js/Pages/admin/connectivity/ActilockMonitor.jsx` |
| 4.5 | i18n | `lang/en.json`, `lang/fr.json` |

### Phase 5 — Tests + Doc (Semaine 5)

| # | Tâche | Fichiers |
|---|---|---|
| 5.1 | Tests feature | `tests/Feature/Machine/Actilock*.php` |
| 5.2 | Tests E2E | `tests/e2e/actilock-flow.spec.ts` |
| 5.3 | Documentation | `docs/actilock-cdc.md` |
| 5.4 | Shape Electric | `app/Sync/ShapeRegistry.php` |

---

## 13. Risques et mitigations

| Risque | Probabilité | Impact | Mitigation |
|---|---|---|---|
| `lib_actilock.so` segfault le process PHP | Élevée | Critique | Isolation process séparé |
| .so non compatible avec version PHP/ZEND | Moyenne | Critique | Tester avec PHP 8.3 + ZTS |
| Latence FFI > 20ms | Moyenne | Élevé | Benchmark, optimisation |
| Multi-PLC = concurrence → corruption | Moyenne | Élevé | Mutex FFI + sérialisation |
| Trames PLC mal formées | Élevée | Moyen | Parser défensif, logging |
| WAMP incompatible avec .so Linux | Certaine | Moyen | Déployer sur Linux |

---

## A. Déploiement VM#2 Linux

### A.1 Spécifications VM#2

| Critère | Valeur | Notes |
|---|---|---|
| OS | Ubuntu Server 22.04 LTS (ou Debian 12) | LTS = 5 ans support sécurité |
| CPU | 4 cœurs minimum | 2 PHP-FPM, 1 Nginx, 1 libre |
| RAM | 8 Go minimum | PHP-FPM : 2-4 Go, OS : 2 Go |
| Stockage | SSD 100 Go minimum | Logs + cache + système |
| Réseau | 1 Gb/s, VLAN OT dédié | Pas d'accès Internet direct |
| Virtualisation | Proxmox VE 8.x | Cluster OT 3 nœuds |

### A.2 Stack logicielle VM#2

```
┌─────────────────────────────────────────────┐
│                VM#2 — Linux                 │
│                                             │
│  ┌─────────────────────────────────────┐    │
│  │  Nginx (reverse proxy + static)     │    │
│  │  port 80/443 → php-fpm:9000        │    │
│  └──────────────┬──────────────────────┘    │
│                 │                           │
│  ┌──────────────┴──────────────────────┐    │
│  │  PHP-FPM 8.3 (avec FFI enabled)    │    │
│  │  Laravel 12 + Octane (RoadRunner)   │    │
│  │  └─ artisan interlock:serve :5000   │    │
│  │  └─ artisan interlock:monitor       │    │
│  │  └─ artisan queue:work              │    │
│  └──────────────┬──────────────────────┘    │
│                 │                           │
│  ┌──────────────┴──────────────────────┐    │
│  │  PostgreSQL 17                      │    │
│  │  └─ openmmes (base MES)            │    │
│  │  └─ openmmes_testing (tests)        │    │
│  └─────────────────────────────────────┘    │
│                                             │
│  ┌─────────────────────────────────────┐    │
│  │  lib_actilock.so                    │    │
│  │  /opt/actilock/lib/lib_actilock.so  │    │
│  └─────────────────────────────────────┘    │
│                                             │
│  ┌─────────────────────────────────────┐    │
│  │  supervisord                        │    │
│  │  └─ php-fpm                         │    │
│  │  └─ nginx                           │    │
│  │  └─ interlock:serve                 │    │
│  │  └─ interlock:monitor               │    │
│  │  └─ queue:work                      │    │
│  └─────────────────────────────────────┘    │
└─────────────────────────────────────────────┘
```

### A.3 Installation système

```bash
# 1. OS + dépendances
sudo apt update && sudo apt upgrade -y
sudo apt install -y php8.3-fpm php8.3-cli php8.3-ffi php8.3-pgsql \
    php8.3-mbstring php8.3-xml php8.3-curl php8.3-zip php8.3-gd \
    nginx postgresql-17 supervisor git curl unzip

# 2. PostgreSQL
sudo -u postgres createuser openmmes_user
sudo -u postgres createdb openmmes -O openmmes_user
sudo -u postgres psql -c "ALTER USER openmmes_user WITH PASSWORD 'secret';"

# 3. PHP FFI
sudo sed -i 's/;extension=ffi/extension=ffi/' /etc/php/8.3/fpm/php.ini
sudo sed -i 's/ffi.enable=preload/ffi.enable=true/' /etc/php/8.3/fpm/php.ini
sudo sed -i 's/;extension=ffi/extension=ffi/' /etc/php/8.3/cli/php.ini

# 4. OpenMES
cd /var/www
git clone https://github.com/openmes/openmes.git
cd openmes/backend
cp .env.example .env
composer install --no-dev --optimize-autoloader
php artisan key:generate
php artisan migrate --force
php artisan db:seed --force
npm ci && npm run build

# 5. lib_actilock.so
sudo mkdir -p /opt/actilock/lib
sudo cp lib_actilock.so /opt/actilock/lib/
sudo chmod 755 /opt/actilock/lib/lib_actilock.so

# 6. Permissions
sudo chown -R www-data:www-data /var/www/openmes/backend/storage
sudo chown -R www-data:www-data /var/www/openmes/backend/bootstrap/cache
```

### A.4 Configuration Nginx

```nginx
# /etc/nginx/sites-available/openmes
server {
    listen 443 ssl http2;
    server_name mes.actia.local;

    ssl_certificate     /etc/ssl/openmes.crt;
    ssl_certificate_key /etc/ssl/openmes.key;

    root /var/www/openmes/backend/public;
    index index.php;

    add_header X-Frame-Options "SAMEORIGIN" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header X-XSS-Protection "1; mode=block" always;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\. { deny all; }
}
```

### A.5 Configuration supervisord

```ini
# /etc/supervisor/conf.d/openmes.conf
[program:php-fpm]
command=/usr/sbin/php-fpm8.3 --nodaemonize
autostart=true
autorestart=true
stdout_logfile=/var/log/openmes/php-fpm.log

[program:nginx]
command=/usr/sbin/nginx -g "daemon off;"
autostart=true
autorestart=true

[program:openmes-interlock]
command=php /var/www/openmes/backend/artisan interlock:serve --connection=1
directory=/var/www/openmes/backend
autostart=true
autorestart=true
stdout_logfile=/var/log/openmes/interlock-serve.log
stderr_logfile=/var/log/openmes/interlock-serve-error.log

[program:openmes-monitor]
command=php /var/www/openmes/backend/artisan interlock:monitor --connection=1
directory=/var/www/openmes/backend
autostart=true
autorestart=true
stdout_logfile=/var/log/openmes/interlock-monitor.log

[program:openmes-queue]
command=php /var/www/openmes/backend/artisan queue:work --sleep=3 --tries=3
directory=/var/www/openmes/backend
autostart=true
autorestart=true
stdout_logfile=/var/log/openmes/queue.log
```

### A.6 Pare-feu (iptables)

```bash
sudo iptables -A INPUT -i lo -j ACCEPT
sudo iptables -A INPUT -m state --state ESTABLISHED,RELATED -j ACCEPT

# SSH depuis zone admin uniquement
sudo iptables -A INPUT -p tcp --dport 22 -s 10.0.1.0/24 -j ACCEPT

# HTTPS depuis réseau interne
sudo iptables -A INPUT -p tcp --dport 443 -s 10.0.0.0/8 -j ACCEPT

# TCP:5000 depuis PLCs uniquement
sudo iptables -A INPUT -p tcp --dport 5000 -s 10.0.2.0/24 -j ACCEPT

# PostgreSQL depuis localhost uniquement
sudo iptables -A INPUT -p tcp --dport 5432 -s 127.0.0.1 -j ACCEPT

# Tout le reste → DROP
sudo iptables -A INPUT -j DROP
```

### A.7 Mise à jour et backup

```bash
# Backup quotidien (cron)
0 2 * * * /var/www/openmes/backend/artisan backup:run --quiet
0 3 * * * pg_dump openmmes | gzip > /backup/openmmes_$(date +\%Y\%m\%d).sql.gz

# Mise à jour code
cd /var/www/openmes
git pull origin main
cd backend
composer install --no-dev --optimize-autoloader
php artisan migrate --force
npm ci && npm run build
sudo supervisorctl restart openmes-*
```

---

## B. Tolérance aux pannes

### B.1 Matrice de pannes

| Panne | Détection | Impact | Récupération |
|---|---|---|---|
| `lib_actilock.so` segfault | Process worker meurt (exit code negatif) | Appels interlock bloqués | Auto-respawn par supervisord |
| ACTILOCK VM#1 down | TCP probe échoue | Pas d'autorisation Start/Complete | Mode dégradé (§F) + alerte |
| PLC déconnecté | `fread()` retourne false/empty | Un PLC en moins | Accept automatique reconnexion |
| PostgreSQL down | Exception Eloquent | Pas de logging MES | Retry 5s, alerte si > 60s |
| FFI timeout | `stream_select()` sur pipe expire | Réponse tardive au PLC | Kill worker, respawn, réponse erreur |
| AEG down | Supervisord restart | Tous les services down | Bascule HA vers Baie 2 |
| RAM insuffisante | OOM killer tue PHP | Process principal meurt | Supervisord restart, alerte |
| Disque plein | Écriture log échoue | Pas de logs, pas de cache | Rotation logs, alerte |

### B.2 Isolation du process FFI

```
Process Principal (Laravel)
    │
    │  pipe (stdin/stdout)
    ▼
InterlockWorker (process séparé)
    │
    │  FFI::cdef()
    ▼
lib_actilock.so
    │
    │  TCP interne
    ▼
VM#1 ACTILOCK Server
```

### B.3 Mécanisme de respawn

```php
class InterlockWorker
{
    private $process;
    private $pipes;

    public function ensureWorker(): void
    {
        if ($this->process && $this->isAlive()) {
            return;
        }
        $this->process = proc_open(
            'php artisan interlock:worker --connection=' . $this->connectionId,
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $this->pipes
        );
    }

    public function call(string $method, array $args): array
    {
        $this->ensureWorker();
        fwrite($this->pipes[0],
            json_encode(['method' => $method, 'args' => $args]) . "\n");
        $response = fgets($this->pipes[1]);
        return json_decode($response, true);
    }

    public function isAlive(): bool
    {
        $status = proc_get_status($this->process);
        return $status['running'];
    }

    public function respawn(): void
    {
        $this->kill();
        $this->ensureWorker();
    }
}
```

### B.4 Monitoring de santé

| Check | Fréquence | Action si échec |
|---|---|---|
| TCP probe vers VM#1 | Toutes les 5s | Passer status = `error` |
| Worker FFI vivant | Chaque appel | Respawn automatique |
| PostgreSQL connectable | Toutes les 5s | Log erreur, retry |
| Queue workers actifs | Toutes les 30s | Restart automatique |
| Espace disque | Toutes les 60s | Alerte si < 10% |
| Mémoire utilisée | Toutes les 60s | Alerte si > 80% |

---

## C. Cycle de vie complet — tous les cas

### C.1 Scénario normal : Start → Operation → Complete

```
Step 1: PLC envoie Start
┌─────────────────────────────────────────────────────────────┐
│ Trame: 0x02 0x10 [LEN] SFC`RESOURCE`OP`USER`MANORDER 0x03 │
└───────────────────────────┬─────────────────────────────────┘
                            │
                            ▼
Step 2: InterlockTcpServer parse
┌──────────────────────────────────────┐
│ CODE=0x10 → Start                    │
│ PAYLOAD = "SFC`RES`OP`USER`OF"      │
│ Parse: ['SFC','RES','OP','USER','OF']│
└───────────────────────────┬──────────┘
                            │
                            ▼
Step 3: ActilockService::start()
┌──────────────────────────────────────┐
│ 1. Vérifier connexion (isConnected)  │
│ 2. Appliquer defaults (ActilockConn) │
│ 3. Appel FFI: ACTILOCK_Start()       │
│    → lib_actilock.so → TCP → VM#1    │
│ 4. VM#1 vérifie:                     │
│    - SFC existe dans le routing?     │
│    - Opération attendue?             │
│    - Ressource autorisée?            │
│    - Pas de verrou existant?         │
│ 5. Réponse: READY ou HOLD            │
└───────────────────────────┬──────────┘
                            │
                            ▼
Step 4: Réponse au PLC
┌──────────────────────────────────────┐
│ Trame: 0x02 0x10 [LEN] READY 0x03   │
└───────────────────────────┬──────────┘
                            │
                            ▼
Step 5: PLC exécute l'opération
    ... temps de production ...
                            │
                            ▼
Step 6: PLC envoie Complete
┌──────────────────────────────────────┐
│ Trame: 0x02 0x11 [LEN]              │
│        SFC`RES`OP`USER 0x03         │
└───────────────────────────┬──────────┘
                            │
                            ▼
Step 7: ActilockService::complete()
┌──────────────────────────────────────┐
│ ACTILOCK_Complete() → VM#1           │
│ VM#1: libère le verrou               │
│ Réponse: OK                          │
└───────────────────────────┬──────────┘
                            │
                            ▼
Step 8: Logging
┌──────────────────────────────────────┐
│ - MachineEvent créé (type: interlock)│
│ - messages_received++                │
│ - Cache: dernières actions           │
└──────────────────────────────────────┘
```

### C.2 Scénario NOK : Start → NcLogComplete

```
PLC envoie Start → OPENMES retourne READY
    ... opération ...
PLC détecte un défaut
    │
    ▼
PLC envoie NcLogComplete
┌──────────────────────────────────────────────────────────────────┐
│ Trame: 0x02 0x12 [LEN] SFC`RES`OP`USER`NCCODE`LOC`NB`REF`COMP 0x03 │
└──────────────────────────────────────┬───────────────────────────┘
                                       │
                                       ▼
ActilockService::ncLogComplete()
┌──────────────────────────────────────┐
│ ACTILOCK_NcLogComplete() → VM#1      │
│ VM#1: enregistre le défaut           │
│ VM#1: met à jour le routing NOK      │
│ Réponse: OK                          │
└──────────────────────────────────────┘
```

### C.3 Scénario HOLD

```
PLC envoie Start
    │
    ▼
VM#1 retourne HOLD
┌──────────────────────────────────────┐
│ Raison possible:                     │
│ - SFC pas attendu à cette station    │
│ - Opération précédente non complétée │
│ - Verrou existant sur le produit     │
│ - Routage non configuré             │
└───────────────────────────┬──────────┘
                            │
                            ▼
Réponse au PLC: HOLD
```

### C.4 Scénario timeout ACTILOCK

```
PLC envoie Start
    │
    ▼
ActilockService::start() → FFI call
    │
    │  ... pas de réponse pendant 20s ...
    ▼
Timeout détecté
┌──────────────────────────────────────┐
│ 1. Tuer le worker FFI                │
│ 2. Respawn un nouveau worker         │
│ 3. Logger erreur: "ACTILOCK timeout" │
│ 4. Réponse au PLC: ERROR             │
│    0x02 0x10 [LEN] ERROR`TIMEOUT 0x03│
│ 5. Marquer ActilockConnection: error │
│ 6. Déclencher alerte                 │
└──────────────────────────────────────┘
```

### C.5 Scénario segfault worker

```
ActilockService::start() → FFI call
    │
    │  ... lib_actilock.so segfault ...
    ▼
Process worker meurt (exit code = -11 SIGSEGV)
┌──────────────────────────────────────┐
│ 1. Parent détecte proc_get_status()  │
│    running=false, exitcode=-11       │
│ 2. Logger: "Worker crashed (SIGSEGV)"│
│ 3. Respawner automatiquement         │
│ 4. Réponse au PLC: ERROR`CRASH       │
│ 5. Incrémenter compteur crash        │
│ 6. Si > 3 crashes/heure → alerte     │
└──────────────────────────────────────┘
```

### C.6 Scénario trame mal formée

```
PLC envoie trame invalide
┌──────────────────────────────────────┐
│ Cas possibles:                       │
│ - STX manquant (pas 0x02)            │
│ - ETX manquant (pas 0x03)            │
│ - LEN ≠ longueur PAYLOAD réelle      │
│ - CODE inconnu (pas 0x10-0x13)       │
│ - PAYLOAD vide ou tronqué            │
│ - Caractères non-UTF-8               │
└───────────────────────────┬──────────┘
                            │
                            ▼
Parser retourne erreur
┌──────────────────────────────────────┐
│ 1. Logger la trame brute (debug)     │
│ 2. Réponse: ERROR`PARSE              │
│ 3. Ne PAS appeler ACTILOCK           │
│ 4. Ne PAS fermer la connexion PLC    │
└──────────────────────────────────────┘
```

### C.7 Scénario reconnexion PLC

```
PLC se déconnecte (network timeout / reboot)
┌──────────────────────────────────────┐
│ 1. fread() retourne false            │
│ 2. Fermer socket côté serveur        │
│ 3. Retirer de la liste clients       │
│ 4. Logger: "PLC déconnecté"          │
│ 5. Ne PAS toucher à ACTILOCK         │
└──────────────────────────────────────┘
    ...
PLC se reconnecte
┌──────────────────────────────────────┐
│ 1. stream_socket_accept()            │
│ 2. Nouveau client ajouté             │
│ 3. Logger: "PLC reconnecté"          │
│ 4. Prêt à recevoir des trames        │
└──────────────────────────────────────┘
```

### C.8 Scénario ProductStatus

```
PLC interroge statut produit
┌──────────────────────────────────────┐
│ Trame: 0x02 0x13 [LEN] STATUS`SFC 0x03│
└───────────────────────────┬──────────┘
                            │
                            ▼
ActilockService::productStatus()
┌──────────────────────────────────────┐
│ ACTILOCK_ProductStatus("STATUS", SFC)│
│ VM#1 retourne le statut:             │
│ - "IN_PROGRESS"                      │
│ - "COMPLETED"                        │
│ - "REJECTED"                         │
│ - "NOT_FOUND"                        │
└───────────────────────────┬──────────┘
                            │
                            ▼
Réponse au PLC: 0x02 0x13 [LEN] IN_PROGRESS 0x03
```

---

## D. Audit trail et conformité ISA-95

### D.1 Journalisation des actions ACTILOCK

Chaque appel vers ACTILOCK est enregistré dans `machine_events` :

```php
MachineEvent::create([
    'machine_connection_id' => $connection->id,
    'workstation_id'        => $workstation?->id,
    'event_type'            => 'interlock',
    'signal_type'           => $code,
    'value'                 => $response,
    'metadata'              => [
        'sfc'         => $sfc,
        'resource'    => $resource,
        'operation'   => $operation,
        'user'        => $user,
        'man_order'   => $manorder,
        'nc_code'     => $nccode,
        'raw_payload' => $rawPayload,
        'plc_ip'      => $clientIp,
        'latency_ms'  => $latencyMs,
    ],
    'created_at'            => now(),
]);
```

### D.2 Champs d'audit obligatoires

| Champ | Source | Description |
|---|---|---|
| `timestamp` | `now()` | Horodatage UTC de l'action |
| `sfc` | Trame PLC | Numéro de série du produit |
| `resource` | Trame PLC | Station de travail |
| `operation` | Trame PLC | Opération en cours |
| `user` | Trame PLC | Opérateur identifié |
| `plc_ip` | Socket client | Adresse IP du PLC |
| `latency_ms` | Calcul | Temps de réponse total |
| `response` | ACTILOCK | Réponse du moteur |
| `raw_payload` | Trame TCP | Trame brute (pour audit) |

### D.3 Rétention des données

| Type de donnée | Rétention | Stockage |
|---|---|---|
| MachineEvents (interlock) | 60 mois | PostgreSQL (table principale) |
| MachineMessages | 24 mois | PostgreSQL |
| Logs application | 30 jours | Fichiers rotatifs |
| Trames brutes (debug) | 7 jours | Fichiers (désactivé en prod) |
| Métriques agrégées | Indéfiniment | PostgreSQL (tables agrégées) |

### D.4 Conformité ISA-95

| Niveau ISA-95 | Fonction OpenMES | Données |
|---|---|---|
| Niveau 4 (ERP) | API REST vers ERP | OF, FPY, OEE |
| Niveau 3 (MES) | OpenMES core | Production, qualité, maintenance |
| Niveau 2 (Supervision) | SCADA (Phase 2) via OPC UA | KPIs, alarmes |
| Niveau 1 (Contrôle) | Gateway TCP | Start/Complete/NcLog |
| Niveau 0 (Process) | PLC/Senseurs | Signaux bruts |

### D.5 Traçabilité complète

Chaque produit a un historique complet :

```
SFC 20412441680852
  ├─ 08:00:01 — Start — Station R_IDENT — Op OP_IDENT
  ├─ 08:00:15 — Complete — Station R_IDENT — Op OP_IDENT (OK)
  ├─ 08:01:02 — Start — Station R_TF_20412 — Op OP_TF_20412
  ├─ 08:05:30 — NcLogComplete — Station R_TF_20412 — NC: def_20412
  ├─ 08:06:00 — Start — Station R_TF_20412 — Op OP_TF_20412 (rework)
  └─ 08:10:15 — Complete — Station R_TF_20412 — Op OP_TF_20412 (OK)
```

---

## E. API REST pour intégrations tierces

### E.1 Endpoints

| Méthode | Endpoint | Description | Auth |
|---|---|---|---|
| `GET` | `/api/v1/actilock/{id}/status` | État de la connexion ACTILOCK | Token |
| `GET` | `/api/v1/actilock/{id}/actions` | Dernières actions interlock | Token |
| `GET` | `/api/v1/actilock/{id}/product/{sfc}` | Statut d'un produit | Token |
| `POST` | `/api/v1/actilock/{id}/start` | Démarrer une opération | Token |
| `POST` | `/api/v1/actilock/{id}/complete` | Compléter une opération | Token |
| `POST` | `/api/v1/actilock/{id}/nc-log` | Logger un défaut | Token |
| `GET` | `/api/v1/actilock/{id}/health` | Health check | Aucun |

### E.2 Format de réponse

```json
// GET /api/v1/actilock/1/status
{
    "status": "connected",
    "version": "2.1.0",
    "connected_at": "2026-07-11T08:00:00Z",
    "plc_count": 3,
    "last_action": {
        "type": "start",
        "sfc": "20412441680852",
        "response": "READY",
        "latency_ms": 12,
        "at": "2026-07-11T10:30:15Z"
    }
}
```

### E.3 Cas d'usage API

| Système | Usage | Endpoint |
|---|---|---|
| **SCADA** | Interroger statut produit | `GET /product/{sfc}` |
| **ERP** | Synchroniser ordres fabrication | `POST /start` |
| **Dashboard externe** | Monitoring santé Gateway | `GET /health` |
| **Script maintenance** | Test connectivité | `GET /status` |
| **ICOM Traceability** | Migration progressive | `POST /start`, `POST /complete` |

### E.4 Rate limiting

| Limite | Valeur | Raison |
|---|---|---|
| Requêtes/seconde | 100 | Éviter surcharge ACTILOCK |
| Requêtes/minute | 1000 | Protection DoS |
| Connexions simultanées | 50 | Capacité VM#2 |

---

## F. Mode dégradé / fallback

### F.1 Définition

Le mode dégradé s'active quand AEG ne peut pas communiquer avec
ACTILOCK (VM#1 down, segfaults récurrents, timeout).

### F.2 Stratégies de fallback

| Stratégie | Comportement | Quand l'utiliser |
|---|---|---|
| **File d'attente replay** | Bufferiser les appels, réexécuter quand ACTILOCK revient | Panne temporaire (< 1h) |
| **Mode lecture seule** | ProductStatus continue de fonctionner (cached) | Panne ACTILOCK, besoin info |
| **Mode bypass** | PLC reçoit OK sans validation ACTILOCK | **INTERDIT** en production |
| **Arrêt contrôlé** | Refuser toutes les trames, PLC en HOLD | Panne longue, sécurité |

### F.3 File d'attente replay

```
┌─────────────────────────────────────────────────────┐
│  interlock_queue (table PostgreSQL)                 │
│                                                     │
│  id | sfc | action | payload | status | attempts    │
│  1  | SFC1| start  | {...}   | pending| 0           │
│  2  | SFC2| complete| {...}  | pending| 0           │
│  3  | SFC3| nc_log | {...}   | failed | 3           │
└─────────────────────────────────────────────────────┘

Flow:
1. ACTILOCK down → ajouter à interlock_queue (status=pending)
2. Répondre au PLC: "QUEUED"
3. Monitor loop détecte ACTILOCK revient
4. Worker replay: exécuter les pending en ordre
5. Mettre à jour status: success/failed
```

### F.4 Seuils de basculement

| Seuil | Action |
|---|---|
| 1 échec | Retry immédiat |
| 3 échecs consécutifs | Passer en mode dégradé |
| > 10 échecs/heure | Alerte critique + arrêt acceptation nouvelles trames |
| ACTILOCK revient | Replay automatique, retour mode normal |

---

## G. OPC UA Server (Phase 2)

### G.1 Objectif

Exposer les données AEG vers les systèmes SCADA via OPC UA.

### G.2 Architecture cible

```
┌─────────────────────────────────────────────────┐
│  VM#2 — AEG                                      │
│                                                  │
│  ┌────────────────────┐  ┌────────────────────┐ │
│  │ InterlockTcpServer │  │ OPC UA Server      │ │
│  │ (PHP artisan)      │  │ (Node.js sidecar)  │ │
│  │ :5000 TCP          │  │ :4840 OPC UA       │ │
│  └────────────────────┘  └────────────────────┘ │
└──────────────────────────────────┬───────────────┘
                                   │
                                   │ OPC UA :4840
                                   ▼
                          ┌────────────────┐
                          │ VM#7 — SCADA   │
                          └────────────────┘
```

### G.3 Nodes OPC UA exposés

| Node ID | Nom | Type | Description |
|---|---|---|---|
| `ns=2;s=Machine.{id}.State` | État machine | String | RUNNING/IDLE/FAULT |
| `ns=2;s=Machine.{id}.GoodCount` | Compteur bon | Int32 | Pièces conformes |
| `ns=2;s=Machine.{id}.RejectCount` | Compteur rebut | Int32 | Pièces rebutées |
| `ns=2;s=Machine.{id}.Temperature` | Température | Double | Télémétrie |
| `ns=2;s=Interlock.Status` | Statut ACTILOCK | String | connected/disconnected |
| `ns=2;s=Interlock.LastAction` | Dernière action | String | Start/Complete/NcLog |
| `ns=2;s=Interlock.ProductStatus` | Statut produit | String | Dernier ProductStatus |

### G.4 Technologies envisagées

| Option | Avantage | Inconvénient |
|---|---|---|
| `node-opcua` (Node.js) | Mature, bien documenté | Process séparé |
| `freeopcua` (Python) | Similaire au wrapper existant | Python supplémentaire |
| `php-opcua` (extension) | Natif PHP | Peu mature |
| **Recommandé** : Node.js sidecar | Pattern existant | Un process de plus |

---

## H. Migration depuis ICOM Traceability

### H.1 État actuel (ICOM)

```
ICOM Traceability (Python)
  ├─ Watchdog: surveillance dossier
  ├─ Parser: extraction SFC + status
  ├─ ACTILOCK: Start/Complete/NcLog via ctypes
  ├─ PostgreSQL: UPDATE timestamp
  ├─ SQLite: statut fichiers
  └─ Dashboard: HTTP 127.0.0.1:8000
```

### H.2 Comparaison fonctionnelle

| Fonction | ICOM (Python) | ACTI Edge Gateway (AEG) | Gap |
|---|---|---|---|
| Réception données | Watchdog fichier | TCP serveur | Différent |
| Parsing | Regex texte | Parser trames TCP | Différent |
| Appel ACTILOCK | ctypes (.so) | FFI PHP (.so) | Identique |
| Base de données | PostgreSQL | PostgreSQL (Eloquent) | Similaire |
| Monitoring | Dashboard HTTP | Admin UI React | Supérieur |
| Audit trail | Fichiers log | MachineEvent + SQL | Supérieur |
| Multi-PLC | Non (1 poste) | Oui (50 PLC) | Supérieur |
| HA | Non | Supervisord + HA | Supérieur |

### H.3 Plan de migration

```
Phase 1: Coexistence (Semaines 1-4)
┌──────────────────────────────────────────┐
│  VM#2                                    │
│  ├─ ICOM Traceability (port 8000)        │
│  └─ ACTI Edge Gateway (AEG) (port 5000, :443)    │
│                                          │
│  Les deux tournent en parallèle          │
│  ICOM gère les fichiers existants        │
│  AEG gère les nouvelles connexions       │
└──────────────────────────────────────────┘

Phase 2: Bascule (Semaines 5-6)
┌──────────────────────────────────────────┐
│  PLC reconfiguré → AEG :5000             │
│  ICOM conservé en backup (arrêté)        │
│  Vérification: tous les SFC traités      │
└──────────────────────────────────────────┘

Phase 3: Décommission (Semaine 7)
┌──────────────────────────────────────────┐
│  ICOM arrêté définitivement              │
│  Logs archivés                           │
│  Dashboard ICOM désactivé                │
│  AEG = Gateway unique                    │
└──────────────────────────────────────────┘
```

### H.4 Points de vigilance

| Risque | Mitigation |
|---|---|
| PLC configuré pour fichiers (pas TCP) | Adapter PLC ou bridge fichier→TCP |
| SFC mapping différent | Synchroniser les mappings avant bascule |
| Perte de données pendant migration | Double traitement pendant coexistence |

---

## I. Gestion de configuration

### I.1 Fichier `.env` (OpenMES standard)

```env
# Base de données
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=openmmes
DB_USERNAME=openmmes_user
DB_PASSWORD=secret

# ACTILOCK Gateway
ACTILOCK_SO_PATH=/opt/actilock/lib/lib_actilock.so
ACTILOCK_TCP_HOST=0.0.0.0
ACTILOCK_TCP_PORT=5000
ACTILOCK_MAX_CONNECTIONS=50
ACTILOCK_TIMEOUT_SECONDS=20
ACTILOCK_FFI_ENABLED=true

# Sécurité
ACTILOCK_ENCRYPTION_KEY=base64:...
APP_ENV=production
APP_DEBUG=false
```

### I.2 Table `system_settings` (en base)

| Clé | Valeur par défaut | Description |
|---|---|---|
| `actilock_host` | `""` | Host ACTILOCK VM#1 |
| `actilock_port` | `0` | Port ACTILOCK VM#1 |
| `actilock_document` | `""` | Document path |
| `actilock_site` | `""` | Site par défaut |
| `actilock_enabled` | `true` | Activer/désactiver |
| `actilock_mode` | `strict` | `strict` ou `degraded` |

### I.3 Variables d'environnement par composant

| Composant | Variable | Description |
|---|---|---|
| `interlock:serve` | `--connection=N` | ID de la ActilockConnection |
| `interlock:serve` | `--host=0.0.0.0` | Adresse d'écoute TCP |
| `interlock:serve` | `--port=5000` | Port d'écoute TCP |
| `interlock:monitor` | `--connection=N` | ID de la ActilockConnection |
| `interlock:monitor` | `--interval=5` | Intervalle en secondes |
| Queue worker | `--queue=interlock` | File dédiée interlock |

### I.4 Gestion des secrets

| Secret | Stockage | Chiffrement |
|---|---|---|
| `DB_PASSWORD` | `.env` | Hors git |
| `APP_KEY` | `.env` | Généré par `artisan key:generate` |
| `ACTILOCK_ENCRYPTION_KEY` | `.env` | Clé de chiffrement FFI |
| `lib_actilock.so` | `/opt/actilock/lib/` | Permissions 755 (root) |

### I.5 Hiérarchie de configuration

```
1. Variables d'environnement (.env)     ← Priorité haute
2. system_settings (base de données)    ← Priorité moyenne
3. Defaults codés (ActilockConnection)  ← Priorité basse
```

---

## J. Cartographie complète des interactions VM#2

### J.1 Vue globale

```
                          ════════════════════════════════════════════════
                          ║           VM#2 — LINUX (Ubuntu 22.04)       ║
                          ║         ACTI Edge Gateway (AEG) + ACTILOCK           ║
════════════════════════════════════════════════════════════════════════════════════
                          ║                                             ║
  ┌──────────┐            ║  ┌──────────────────────────────────────┐   ║
  │          │ TCP:5000   ║  │  INTERLOCK TCP SERVER                │   ║
  │ PLC #1   │───────────→║  │  (artisan interlock:serve)           │   ║
  │ Siemens  │←───────────║  │                                      │   ║
  │          │            ║  │  ┌────────────┐  ┌────────────────┐  │   ║
  └──────────┘            ║  │  │ TCP Parser │  │ Frame Builder  │  │   ║
                          ║  │  │ STX|CODE|  │  │ STX|CODE|LEN|  │  │   ║
  ┌──────────┐            ║  │  │ LEN|PAY|ETX│  │ PAY|ETX        │  │   ║
  │          │ TCP:5000   ║  │  └─────┬──────┘  └───────▲────────┘  │   ║
  │ PLC #2   │───────────→║  │        │                 │           │   ║
  │ Siemens  │←───────────║  │  ┌─────▼─────────────────┴────────┐  │   ║
  │          │            ║  │  │     ActilockService             │  │   ║
  └──────────┘            ║  │  │  (logique métier interlock)     │  │   ║
                          ║  │  │                                  │  │   ║
  ┌──────────┐            ║  │  │  start() / complete()           │  │   ║
  │          │ TCP:5000   ║  │  │  ncLogComplete()                │  │   ║
  │ PLC #N   │───────────→║  │  │  productStatus()                │  │   ║
  │ (max 50) │←───────────║  │  │  isExpectedAt()                 │  │   ║
  │          │            ║  │  └─────────────┬───────────────────┘  │   ║
  └──────────┘            ║  └────────────────┼──────────────────────┘   ║
                          ║                   │                          ║
                          ║                   │ FFI (FFI::cdef)          ║
                          ║                   ▼                          ║
                          ║  ┌──────────────────────────────────────┐   ║
                          ║  │  INTERLOCK WORKER (process séparé)   │   ║
                          ║  │  (artisan interlock:worker)          │   ║
                          ║  │                                      │   ║
                          ║  │  ┌──────────────────────────────┐   │   ║
                          ║  │  │     lib_actilock.so           │   │   ║
                          ║  │  │  (FFI → ctypes → .so natif)  │   │   ║
                          ║  │  │                               │   │   ║
                          ║  │  │  ACTILOCK_Connect()           │   │   ║
                          ║  │  │  ACTILOCK_Start()             │   │   ║
                          ║  │  │  ACTILOCK_Complete()          │   │   ║
                          ║  │  │  ACTILOCK_NcLogComplete()     │   │   ║
                          ║  │  │  ACTILOCK_ProductStatus()     │   │   ║
                          ║  │  │  ACTILOCK_IsExpectedAt()      │   │   ║
                          ║  │  │  ACTILOCK_NetPing()           │   │   ║
                          ║  │  │  ACTILOCK_EngineVersion()     │   │   ║
                          ║  │  │  ACTILOCK_LibraryVersion()    │   │   ║
                          ║  │  └──────────────┬───────────────┘   │   ║
                          ║  │                 │ TCP interne        │   ║
                          ║  └─────────────────┼───────────────────┘   ║
                          ║                    │                        ║
════════════════════════════════════════════════╪═══════════════════════════
                          ║                    │                        ║
  ┌───────────────────────╫─────────────────────┼────────────────────┐   ║
  │  VM#1 — ACTILOCK      │                    │                    │   ║
  │  (Moteur interblocage)│◄───────────────────┘                    │   ║
  │                       │     TCP (via lib_actilock.so)            │   ║
  └───────────────────────╫──────────────────────────────────────────┘   ║
                          ║                                             ║
════════════════════════════════════════════════════════════════════════════════════
                          ║                                             ║
  ┌───────────────────────╫──────────────────────────────────────────┐   ║
  │  PHP-FPM 8.3 (Laravel 12)                                      │   ║
  │                                                                  │   ║
  │  ┌────────────────────────────────────────────────────────────┐  │   ║
  │  │  OPENMES CORE                                              │  │   ║
  │  │  Controllers │ Models │ Services                            │  │   ║
  │  │  └─ MachineSignalIngestor (routing protocole-agnostique)   │  │   ║
  │  └─────────────────────────────┬──────────────────────────────┘  │   ║
  │                                │                                  │   ║
  │  ┌─────────────────────────────┼──────────────────────────────┐  │   ║
  │  │  CONNECTIVITY DAEMONS        │                              │  │   ║
  │  │  InterlockTcpServer    ──→   │  lib_actilock.so (FFI)      │  │   ║
  │  │  InterlockMonitor      ──→   │  TCP probe VM#1             │  │   ║
  │  │  MqttListenCommand     ──→   │  Broker MQTT (optionnel)    │  │   ║
  │  │  ModbusPollCommand     ──→   │  PLC/Equipment (optionnel)  │  │   ║
  │  │  QueueWorker           ──→   │  Jobs async                 │  │   ║
  │  └─────────────────────────────┼──────────────────────────────┘  │   ║
  └────────────────────────────────┼─────────────────────────────────┘   ║
                                   │                                     ║
                          ┌────────▼────────┐                            ║
                          │   PostgreSQL 17  │                            ║
                          │   TCP:5432       │                            ║
                          │   openmmes       │                            ║
                          └─────────────────┘                            ║
                                                                      ║
════════════════════════════════════════════════════════════════════════════════════
                          ║                                             ║
                          ║  ┌──────────────────────────────────────┐   ║
                          ║  │  NGINX → HTTPS:443                   │   ║
                          ║  │  ADMIN UI (React + Inertia)          │   ║
                          ║  └──────────────────────────────────────┘   ║
                          ║                                             ║
                          ║  ┌──────────────────────────────────────┐   ║
                          ║  │  OPC UA SIDECAR (Node.js :4840)      │   ║
                          ║  │  → SCADA VM#7 (Phase 2)              │   ║
                          ║  └──────────────────────────────────────┘   ║
                          ║                                             ║
                          ║  ┌──────────────────────────────────────┐   ║
                          ║  │  SUPERVISORD (gestion process)       │   ║
                          ║  └──────────────────────────────────────┘   ║
                          ║                                             ║
                          ║  ┌──────────────────────────────────────┐   ║
                          ║  │  ELECTRIC SQL (sync → navigateurs)   │   ║
                          ║  └──────────────────────────────────────┘   ║
                          ════════════════════════════════════════════════
```

### J.2 Tableau matrice des flux

| # | Source | Destination | Protocole | Port | Priorité | Latence |
|---|---|---|---|---|---|---|
| **F1** | PLC Siemens | InterlockTcpServer | TCP (STX/CODE/LEN/PAY/ETX) | 5000 | Haute | < 20ms |
| **F2** | InterlockTcpServer | PLC Siemens | TCP (réponse) | 5000 | Haute | < 20ms |
| **F3** | InterlockWorker | lib_actilock.so | FFI (PHP) | interne | Haute | < 5ms |
| **F4** | lib_actilock.so | VM#1 ACTILOCK | TCP (interne .so) | ??? | Haute | < 15ms |
| **F5** | VM#1 ACTILOCK | lib_actilock.so | TCP (réponse) | ??? | Haute | < 15ms |
| **F6** | InterlockMonitor | VM#1 ACTILOCK | TCP probe | ??? | Moyenne | 5s cycle |
| **F7** | AEG | PostgreSQL | TCP (pg wire) | 5432 | Haute | < 10ms |
| **F8** | PostgreSQL | AEG | TCP (réponse) | 5432 | Haute | < 10ms |
| **F9** | Admin UI | Nginx | HTTPS | 443 | Moyenne | < 100ms |
| **F10** | Nginx | PHP-FPM | FastCGI (unix socket) | 9000 | Haute | < 5ms |
| **F11** | AEG | Electric SQL | WAL stream | ??? | Basse | async |
| **F12** | Electric SQL | Navigateurs | SSE/HTTP2 | ??? | Basse | < 1s |
| **F13** | MqttListenCommand | Broker MQTT | MQTT (subscribe) | 1883 | Moyenne | < 100ms |
| **F14** | Broker MQTT | MqttListenCommand | MQTT (messages) | 1883 | Moyenne | < 100ms |
| **F15** | ModbusPollCommand | PLC/Equipment | Modbus TCP | 502 | Moyenne | < 100ms |
| **F16** | OPC UA Sidecar | SCADA | OPC UA | 4840 | Basse | 100-300ms |
| **F17** | SCADA | OPC UA Sidecar | OPC UA | 4840 | Basse | 100-300ms |
| **F18** | OPC UA Sidecar | AEG API | HTTP POST | 443 | Moyenne | < 50ms |
| **F19** | supervisord | Tous process | Signal (SIGTERM) | interne | Haute | immédiat |
| **F20** | AEG | Webhooks externes | HTTP POST | 443 | Basse | < 5s |

### J.3 Diagramme des couches réseau

```
┌─────────────────────────────────────────────────────────────────┐
│  COUCHE 7 — APPLICATION                                        │
│  React Inertia │ API REST │ Electric SQL │ Admin UI             │
├─────────────────────────────────────────────────────────────────┤
│  COUCHE 6 — SÉCURITÉ                                          │
│  HTTPS │ Sanctum │ TLS │ CORS                                   │
├─────────────────────────────────────────────────────────────────┤
│  COUCHE 5 — TRANSPORT                                          │
│  TCP:5000 │ TCP:5432 │ HTTP/2 SSE │ MQTT:1883                   │
├─────────────────────────────────────────────────────────────────┤
│  COUCHE 4 — RÉSEAU                                             │
│  VLAN OT (10.0.2.0/24) — 1 Gb/s — Pare-feu INPUT DROP         │
├─────────────────────────────────────────────────────────────────┤
│  COUCHE 3 — APPLICATION INTERLOCK                               │
│  InterlockTcpServer → ActilockService → ActilockLibrary (FFI)  │
├─────────────────────────────────────────────────────────────────┤
│  COUCHE 2 — DONNÉES                                            │
│  PostgreSQL 17: machine_connections, actilock_connections,      │
│  machine_events, machine_tags, interlock_queue...               │
├─────────────────────────────────────────────────────────────────┤
│  COUCHE 1 — MATÉRIEL                                          │
│  VM#2 Proxmox VE 8.x — 4 vCPU — 8 Go RAM — 100 Go SSD        │
└─────────────────────────────────────────────────────────────────┘
```

### J.4 Tableau des ports

| Port | Protocole | Direction | Composant | Description |
|---|---|---|---|---|
| **5000** | TCP | Entrant | InterlockTcpServer | Trames PLC |
| **443** | HTTPS | Entrant | Nginx | Admin UI + API |
| **5432** | TCP | Sortant | PostgreSQL | Base MES |
| **4840** | OPC UA | Sortant (Phase 2) | OPC UA Sidecar | Vers SCADA |
| **1883** | MQTT | Sortant (optionnel) | MqttListenCommand | Broker MQTT |
| **502** | Modbus TCP | Sortant (optionnel) | ModbusPollCommand | PLC/Equipment |
| **9000** | FastCGI | Interne | PHP-FPM | unix socket |
| **8080** | HTTP | Interne | Reverb WebSocket | Broadcast |

### J.5 Flux de sécurité — Couches de protection

```
┌─────────────────────────────────────────────────────────────────┐
│  APPLICATION: Validation stricte trames, Rate limiting,        │
│               Auth Sanctum, Rôle admin, React escaping         │
├─────────────────────────────────────────────────────────────────┤
│  MÉTIER: SSRF guard, DNS rebinding pinning, Secrets chiffrés,  │
│          Thread safety FFI, Process isolation                   │
├─────────────────────────────────────────────────────────────────┤
│  TRANSPORT: TLS 1.3, Pas credentials dans trames TCP           │
├─────────────────────────────────────────────────────────────────┤
│  RÉSEAU: VLAN OT dédié, iptables INPUT DROP, SSH limité,       │
│          TCP:5000 ouvert PLCs uniquement                        │
├─────────────────────────────────────────────────────────────────┤
│  SYSTÈME: Pas de root pour PHP/Nginx, Mises à jour auto,       │
│           Backup quotidien chiffré, Logs chiffrés               │
└─────────────────────────────────────────────────────────────────┘
```

### J.6 Flux de données détaillé — Trame PLC complète

```
PLC Siemens                          VM#2 — AEG                            VM#1 — ACTILOCK
    │                                      │                                      │
    │  1. Trame TCP Start                  │                                      │
    │  0x02|0x10|LEN|SFC`RES`OP`USR|0x03  │                                      │
    │─────────────────────────────────────→│                                      │
    │                                      │  2. stream_socket_accept/fread       │
    │                                      │  3. Parser: STX=0x02 ✓ CODE=0x10    │
    │                                      │  4. ActilockService::start()         │
    │                                      │  5. Worker → FFI → lib_actilock.so   │
    │                                      │                    6. TCP → VM#1      │
    │                                      │─────────────────────────────────────→│
    │                                      │                    7. Vérifie routing │
    │                                      │                    8. Réponse: READY  │
    │                                      │←─────────────────────────────────────│
    │                                      │  9. FFI retourne (true, "READY")     │
    │                                      │  10. Construit réponse TCP           │
    │  11. Réponse TCP: READY              │                                      │
    │←─────────────────────────────────────│                                      │
    │                                      │                                      │
    │  ══════════ Opération ══════════     │                                      │
    │                                      │                                      │
    │  12. Trame Complete                  │                                      │
    │─────────────────────────────────────→│                                      │
    │                                      │  13. Parser → CODE=0x11              │
    │                                      │  14. complete() → FFI → .so          │
    │                                      │─────────────────────────────────────→│
    │                                      │←─────────────────────────────────────│
    │  15. Réponse: OK                     │                                      │
    │←─────────────────────────────────────│                                      │
```

---

## Related Documentation

- [Machine Connectivity](machine-connectivity.md) — Signal pipeline, protocols
- [MQTT Connectivity](mqtt-connectivity.md) — MQTT setup guide
- [Technical Documentation](development.md) — Architecture and modules
- [ISA-95 Compliance](isa95.md) — ISA-95 / IEC 62264 mapping

---

*Cahier des charges rédigé pour le projet **ACTI Edge Gateway (AEG)** —
implémentation basée sur OpenMES (Laravel 12 + PHP 8.3 + FFI).*
