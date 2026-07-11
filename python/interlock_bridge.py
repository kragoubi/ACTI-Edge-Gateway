#!/usr/bin/env python3
"""
ACTI Edge Gateway — Interlock Bridge
=====================================

Python TCP server + FFI bridge for lib_actilock.so.

Architecture:
  PLCs → TCP :5000 → interlock_bridge.py → ctypes → lib_actilock.so → VM#1 ACTILOCK
                             ↓
                      HTTP POST → Laravel API (audit trail + counters)

Based on ICOM Traceability patterns (interlock.py) and ISA-95 protocol spec.
"""

import ctypes
import json
import logging
import multiprocessing
import os
import signal
import socket
import struct
import sys
import threading
import time
import urllib.request
import urllib.error
from dataclasses import dataclass, field
from typing import Optional

# =============================================================================
# CONFIGURATION
# =============================================================================

@dataclass
class BridgeConfig:
    """Configuration loaded from environment or .env file."""
    # TCP Server (PLC listener)
    listen_host: str = "0.0.0.0"
    listen_port: int = 5000
    max_plc_connections: int = 50

    # ACTILOCK Engine (VM#1)
    engine_host: str = "192.168.1.1"
    engine_port: int = 3129
    engine_document: str = ""

    # Defaults (from ActilockConnection)
    default_site: str = ""
    default_system: str = ""
    default_ressource: str = ""
    default_operation: str = ""
    default_user: str = ""

    # FFI
    lib_path: str = "/usr/lib/lib_actilock.so"
    ffi_timeout: float = 10.0

    # Laravel API
    laravel_url: str = "http://127.0.0.1:8000"
    api_token: str = ""

    # Logging
    log_level: str = "INFO"
    log_file: str = "/var/log/aeg/interlock_bridge.log"

    # Timeouts
    tcp_read_timeout: float = 5.0
    tcp_write_timeout: float = 5.0
    monitor_interval: float = 30.0

    # Connection ID (for API calls)
    connection_id: int = 0

    @classmethod
    def from_env(cls) -> "BridgeConfig":
        """Load configuration from environment variables."""
        return cls(
            listen_host=os.environ.get("AEG_LISTEN_HOST", "0.0.0.0"),
            listen_port=int(os.environ.get("AEG_LISTEN_PORT", "5000")),
            max_plc_connections=int(os.environ.get("AEG_MAX_CONNECTIONS", "50")),
            engine_host=os.environ.get("AEG_ENGINE_HOST", "192.168.1.1"),
            engine_port=int(os.environ.get("AEG_ENGINE_PORT", "3129")),
            engine_document=os.environ.get("AEG_ENGINE_DOCUMENT", ""),
            default_site=os.environ.get("AEG_DEFAULT_SITE", ""),
            default_system=os.environ.get("AEG_DEFAULT_SYSTEM", ""),
            default_ressource=os.environ.get("AEG_DEFAULT_RESOURCE", ""),
            default_operation=os.environ.get("AEG_DEFAULT_OPERATION", ""),
            default_user=os.environ.get("AEG_DEFAULT_USER", ""),
            lib_path=os.environ.get("AEG_LIB_PATH", "/usr/lib/lib_actilock.so"),
            ffi_timeout=float(os.environ.get("AEG_FFI_TIMEOUT", "10")),
            laravel_url=os.environ.get("AEG_LARAVEL_URL", "http://127.0.0.1:8000"),
            api_token=os.environ.get("AEG_API_TOKEN", ""),
            log_level=os.environ.get("AEG_LOG_LEVEL", "INFO"),
            log_file=os.environ.get("AEG_LOG_FILE", "/var/log/aeg/interlock_bridge.log"),
            tcp_read_timeout=float(os.environ.get("AEG_TCP_READ_TIMEOUT", "5")),
            tcp_write_timeout=float(os.environ.get("AEG_TCP_WRITE_TIMEOUT", "5")),
            monitor_interval=float(os.environ.get("AEG_MONITOR_INTERVAL", "30")),
            connection_id=int(os.environ.get("AEG_CONNECTION_ID", "0")),
        )

    @classmethod
    def from_file(cls, path: str) -> "BridgeConfig":
        """Load configuration from a JSON file, falling back to env for missing keys."""
        config = cls.from_env()
        if os.path.exists(path):
            with open(path, "r") as f:
                data = json.load(f)
            for key, value in data.items():
                if hasattr(config, key):
                    setattr(config, key, value)
        return config


# =============================================================================
# ISA-95 TCP FRAME PARSER
# =============================================================================

STX = 0x02
ETX = 0x03
CODE_START = 0x10
CODE_COMPLETE = 0x11
CODE_NCLOGCOMPLETE = 0x12
CODE_PRODUCTSTATUS = 0x13
VALID_CODES = {CODE_START, CODE_COMPLETE, CODE_NCLOGCOMPLETE, CODE_PRODUCTSTATUS}
MAX_FRAME_SIZE = 1024
DELIMITER = "`"

CODE_NAMES = {
    CODE_START: "Start",
    CODE_COMPLETE: "Complete",
    CODE_NCLOGCOMPLETE: "NcLogComplete",
    CODE_PRODUCTSTATUS: "ProductStatus",
}


def decode_frame(raw: bytes) -> dict:
    """
    Decode an ISA-95 TCP frame.

    Returns:
        {"valid": True, "code": int, "payload": str, "fields": dict}
        or
        {"valid": False, "error": str}
    """
    if len(raw) < 4:
        return {"valid": False, "error": f"FRAME_TOO_SHORT: {len(raw)} bytes"}

    if raw[0] != STX:
        return {"valid": False, "error": f"MISSING_STX: got 0x{raw[0]:02X}"}

    if raw[-1] != ETX:
        return {"valid": False, "error": f"MISSING_ETX: got 0x{raw[-1]:02X}"}

    code = raw[1]
    if code not in VALID_CODES:
        return {"valid": False, "error": f"UNKNOWN_CODE: 0x{code:02X}"}

    declared_len = raw[2]
    payload_bytes = raw[3:-1]

    if len(payload_bytes) != declared_len:
        return {"valid": False, "error": f"LEN_MISMATCH: declared={declared_len} actual={len(payload_bytes)}"}

    if declared_len == 0:
        return {"valid": False, "error": "EMPTY_PAYLOAD"}

    try:
        payload = payload_bytes.decode("utf-8")
    except UnicodeDecodeError:
        return {"valid": False, "error": "ENCODING_ERROR: payload is not valid UTF-8"}

    fields = parse_payload(payload)

    return {
        "valid": True,
        "code": code,
        "code_name": CODE_NAMES.get(code, "UNKNOWN"),
        "payload": payload,
        "fields": fields,
    }


def parse_payload(payload: str) -> dict:
    """Parse backtick-delimited key=value pairs."""
    if not payload:
        return {}
    result = {}
    for part in payload.split(DELIMITER):
        if not part:
            continue
        eq_pos = part.find("=")
        if eq_pos == -1:
            result[part] = ""
        else:
            result[part[:eq_pos]] = part[eq_pos + 1:]
    return result


def encode_frame(code: int, payload: str) -> bytes:
    """Build an ISA-95 TCP frame."""
    payload_bytes = payload.encode("utf-8")
    if len(payload_bytes) > 255:
        raise ValueError(f"Payload too large: {len(payload_bytes)} bytes (max 255)")
    return bytes([STX, code, len(payload_bytes)]) + payload_bytes + bytes([ETX])


def encode_response(code: int, response_text: str) -> bytes:
    """Build a response frame echoing the request code."""
    return encode_frame(code, response_text)


def encode_error(code: int, error_code: str, message: str = "") -> bytes:
    """Build an error response frame."""
    payload = f"ERROR{DELIMITER}{error_code}"
    if message:
        payload += f"{DELIMITER}{message}"
    return encode_frame(code, payload)


# =============================================================================
# ACTILOCK FFI CLIENT
# =============================================================================

def load_shared_library(path: str) -> ctypes.CDLL:
    """Load lib_actilock.so via ctypes."""
    if not path:
        raise FileNotFoundError("Interlock library path is empty")
    expanded = os.path.expandvars(os.path.expanduser(path))
    abs_path = expanded if os.path.isabs(expanded) else os.path.abspath(expanded)
    if not os.path.exists(abs_path):
        raise FileNotFoundError(f"Interlock library not found: {abs_path}")

    try:
        mode = None
        if hasattr(ctypes, "RTLD_GLOBAL"):
            mode = ctypes.RTLD_GLOBAL
        if mode is not None and not sys.platform.startswith("win"):
            return ctypes.CDLL(abs_path, mode=mode)
        return ctypes.CDLL(abs_path)
    except Exception as e:
        raise RuntimeError(f"Failed to load interlock library: {abs_path} | {e}") from e


def _to_bytes(value) -> bytes:
    """Convert a value to bytes for FFI."""
    if value is None:
        return b""
    if isinstance(value, bytes):
        return value
    return str(value).encode("utf-8")


def _decode_response(raw) -> str:
    """Decode a C char* response to Python string."""
    if not raw:
        return ""
    if isinstance(raw, (bytes, bytearray)):
        return bytes(raw).decode("utf-8", errors="ignore")
    try:
        return ctypes.cast(raw, ctypes.c_char_p).value.decode("utf-8", errors="ignore")
    except Exception:
        return str(raw)


class ActilockClient:
    """
    ctypes wrapper for lib_actilock.so.

    Thread-safe via RLock (the .so is non-reentrant).
    """

    def __init__(self, lib: ctypes.CDLL):
        self.lib = lib
        self.is_connected = False
        self._lock = threading.RLock()
        self._bind_functions()

    def _bind_functions(self):
        """Declare ctypes signatures for all ACTILOCK_* functions."""
        try:
            # ACTILOCK_Connect(host, port, document, &response) -> bool
            self._fn_connect = self.lib.ACTILOCK_Connect
            self._fn_connect.argtypes = [
                ctypes.c_char_p, ctypes.c_ushort, ctypes.c_char_p,
                ctypes.POINTER(ctypes.c_char_p)
            ]
            self._fn_connect.restype = ctypes.c_bool

            # ACTILOCK_Start(site, sfc, resource, operation, user, manorder, &response) -> bool
            self._fn_start = self.lib.ACTILOCK_Start
            self._fn_start.argtypes = [
                ctypes.c_char_p, ctypes.c_char_p, ctypes.c_char_p,
                ctypes.c_char_p, ctypes.c_char_p, ctypes.c_char_p,
                ctypes.POINTER(ctypes.c_char_p)
            ]
            self._fn_start.restype = ctypes.c_bool

            # ACTILOCK_Complete(site, sfc, resource, operation, user, &response) -> bool
            self._fn_complete = self.lib.ACTILOCK_Complete
            self._fn_complete.argtypes = [
                ctypes.c_char_p, ctypes.c_char_p, ctypes.c_char_p,
                ctypes.c_char_p, ctypes.c_char_p,
                ctypes.POINTER(ctypes.c_char_p)
            ]
            self._fn_complete.restype = ctypes.c_bool

            # ACTILOCK_NcLogComplete(site, sfc, resource, operation, user,
            #                        nccode, location, nbdefault, reference, component,
            #                        &response) -> bool
            self._fn_nclogcomplete = self.lib.ACTILOCK_NcLogComplete
            self._fn_nclogcomplete.argtypes = [
                ctypes.c_char_p, ctypes.c_char_p, ctypes.c_char_p,
                ctypes.c_char_p, ctypes.c_char_p, ctypes.c_char_p,
                ctypes.c_char_p, ctypes.c_char_p, ctypes.c_char_p,
                ctypes.c_char_p,
                ctypes.POINTER(ctypes.c_char_p)
            ]
            self._fn_nclogcomplete.restype = ctypes.c_bool

            # ACTILOCK_ProductStatus(parameter, sfc, &response) -> bool
            self._fn_productstatus = self.lib.ACTILOCK_ProductStatus
            self._fn_productstatus.argtypes = [
                ctypes.c_char_p, ctypes.c_char_p,
                ctypes.POINTER(ctypes.c_char_p)
            ]
            self._fn_productstatus.restype = ctypes.c_bool

            # ACTILOCK_NetPing(host) -> bool
            self._fn_netping = self.lib.ACTILOCK_NetPing
            self._fn_netping.argtypes = [ctypes.c_char_p]
            self._fn_netping.restype = ctypes.c_bool

            # ACTILOCK_EngineVersion(&response) -> bool
            self._fn_engineversion = self.lib.ACTILOCK_EngineVersion
            self._fn_engineversion.argtypes = [ctypes.POINTER(ctypes.c_char_p)]
            self._fn_engineversion.restype = ctypes.c_bool

            # ACTILOCK_LibraryVersion(&response) -> bool
            self._fn_libraryversion = self.lib.ACTILOCK_LibraryVersion
            self._fn_libraryversion.argtypes = [ctypes.POINTER(ctypes.c_char_p)]
            self._fn_libraryversion.restype = ctypes.c_bool

            # ACTILOCK_IsExpectedAt(sfc, operation, &response) -> bool
            self._fn_isexpectedat = self.lib.ACTILOCK_IsExpectedAt
            self._fn_isexpectedat.argtypes = [
                ctypes.c_char_p, ctypes.c_char_p,
                ctypes.POINTER(ctypes.c_char_p)
            ]
            self._fn_isexpectedat.restype = ctypes.c_bool

            # ACTILOCK_IsItLockable(sfc, &response) -> bool
            self._fn_isitlockable = self.lib.ACTILOCK_IsItLockable
            self._fn_isitlockable.argtypes = [
                ctypes.c_char_p,
                ctypes.POINTER(ctypes.c_char_p)
            ]
            self._fn_isitlockable.restype = ctypes.c_bool

            # ACTILOCK_NextOp(router, revision, operation, &response) -> bool
            self._fn_nextop = self.lib.ACTILOCK_NextOp
            self._fn_nextop.argtypes = [
                ctypes.c_char_p, ctypes.c_char_p, ctypes.c_char_p,
                ctypes.POINTER(ctypes.c_char_p)
            ]
            self._fn_nextop.restype = ctypes.c_bool

            # ACTILOCK_GetLastResponse() -> const char*
            self._fn_getlastresponse = self.lib.ACTILOCK_GetLastResponse
            self._fn_getlastresponse.argtypes = []
            self._fn_getlastresponse.restype = ctypes.c_char_p

        except AttributeError as e:
            raise RuntimeError(f"Missing symbol in lib_actilock.so: {e}") from e

    def _call(self, func, args: list, check_connected: bool = True) -> tuple:
        """
        Call a native function with thread safety and response handling.

        Returns: (success: bool, response: str)
        """
        with self._lock:
            if check_connected and not self.is_connected:
                raise RuntimeError("Interlock is not connected")

            resp = ctypes.c_char_p()
            native_args = [_to_bytes(a) for a in args]
            ok = bool(func(*native_args, ctypes.byref(resp)))
            response = _decode_response(resp.value) if resp.value else ""
            return ok, response

    def connect(self, host: str, port: int, document: str) -> tuple:
        """Connect to ACTILOCK engine."""
        with self._lock:
            resp = ctypes.c_char_p()
            ok = bool(self._fn_connect(
                _to_bytes(host),
                ctypes.c_ushort(int(port)),
                _to_bytes(document),
                ctypes.byref(resp)
            ))
            response = _decode_response(resp.value) if resp.value else ""
            self.is_connected = ok
            return ok, response

    def start(self, site: str, sfc: str, resource: str, operation: str,
              user: str, manorder: str = "") -> tuple:
        return self._call(self._fn_start, [site, sfc, resource, operation, user, manorder])

    def complete(self, site: str, sfc: str, resource: str, operation: str,
                 user: str) -> tuple:
        return self._call(self._fn_complete, [site, sfc, resource, operation, user])

    def nc_log_complete(self, site: str, sfc: str, resource: str, operation: str,
                        user: str, nccode: str, location: str, nbdefault: str,
                        reference: str, component: str) -> tuple:
        return self._call(self._fn_nclogcomplete, [
            site, sfc, resource, operation, user,
            nccode, location, nbdefault, reference, component
        ])

    def product_status(self, parameter: str, sfc: str) -> tuple:
        return self._call(self._fn_productstatus, [parameter, sfc])

    def net_ping(self, host: str) -> tuple:
        with self._lock:
            ok = bool(self._fn_netping(_to_bytes(host)))
            return ok, ""

    def engine_version(self) -> tuple:
        return self._call(self._fn_engineversion, [], check_connected=False)

    def library_version(self) -> tuple:
        return self._call(self._fn_libraryversion, [], check_connected=False)

    def is_expected_at(self, sfc: str, operation: str) -> tuple:
        return self._call(self._fn_isexpectedat, [sfc, operation])

    def is_it_lockable(self, sfc: str) -> tuple:
        return self._call(self._fn_isitlockable, [sfc])

    def next_op(self, router: str, revision: str, operation: str) -> tuple:
        return self._call(self._fn_nextop, [router, revision, operation])

    def get_last_response(self) -> str:
        with self._lock:
            raw = self._fn_getlastresponse()
            return _decode_response(raw) if raw else ""

    def shutdown(self):
        """Clean shutdown."""
        pass


# =============================================================================
# INTERLOCK WORKER (process isolation for segfault safety)
# =============================================================================

def _worker_main(conn, config: BridgeConfig):
    """
    Entry point for the dedicated Interlock worker subprocess.

    Loads lib_actilock.so and runs an ActilockClient entirely inside this
    process. If a call segfaults, this whole process dies — the parent
    detects that via the broken pipe/exit code and treats it as a normal
    Interlock failure instead of dying itself.
    """
    logger = logging.getLogger("worker")
    client = None
    load_error = ""

    try:
        lib = load_shared_library(config.lib_path)
        client = ActilockClient(lib)
    except Exception as e:
        load_error = str(e)
        logger.error("Failed to load library: %s", e)

    while True:
        try:
            req = conn.recv()
        except (EOFError, OSError):
            return
        if req is None:
            return

        method = req.get("method")
        kwargs = req.get("kwargs") or {}

        try:
            if client is None:
                raise RuntimeError(load_error or "Interlock library not loaded")
            result = getattr(client, method)(**kwargs)
            conn.send({"ok": True, "result": result})
        except Exception as e:
            try:
                conn.send({"ok": False, "error": str(e)})
            except (BrokenPipeError, OSError):
                return


class InterlockProcessClient:
    """
    Drop-in replacement for ActilockClient that runs the real client in a
    dedicated subprocess. If a call segfaults, only the worker dies.
    """

    def __init__(self, config: BridgeConfig, call_timeout: float = 20.0):
        self._config = config
        self._call_timeout = call_timeout
        self._lock = threading.RLock()
        self._proc: Optional[multiprocessing.process.BaseProcess] = None
        self._conn = None

    def _ensure_worker(self):
        if self._proc is not None and self._proc.is_alive():
            return
        parent_conn, child_conn = multiprocessing.Pipe()
        proc = multiprocessing.Process(
            target=_worker_main, args=(child_conn, self._config), daemon=True
        )
        proc.start()
        child_conn.close()
        self._proc = proc
        self._conn = parent_conn

    def _kill_worker(self) -> Optional[int]:
        proc, self._proc = self._proc, None
        self._conn = None
        if proc is None:
            return None
        try:
            if proc.is_alive():
                proc.terminate()
            proc.join(timeout=2)
        except Exception:
            pass
        return proc.exitcode

    def _call(self, method: str, **kwargs):
        with self._lock:
            self._ensure_worker()
            try:
                self._conn.send({"method": method, "kwargs": kwargs})
            except (BrokenPipeError, OSError) as e:
                exitcode = self._kill_worker()
                raise RuntimeError(
                    f"Interlock worker died before {method} could be sent (exit={exitcode}): {e}"
                ) from e

            if not self._conn.poll(self._call_timeout):
                logging.getLogger("bridge").error(
                    "Interlock worker unresponsive during %s — restarting", method
                )
                self._kill_worker()
                raise RuntimeError(f"Interlock worker timed out during {method}")

            try:
                resp = self._conn.recv()
            except (EOFError, OSError):
                exitcode = self._kill_worker()
                raise RuntimeError(f"Interlock worker crashed during {method} (exit={exitcode})")

            if not resp.get("ok"):
                raise RuntimeError(resp.get("error") or f"{method} failed")
            return resp.get("result")

    # Proxy all client methods
    def connect(self, host, port, document):
        return self._call("connect", host=host, port=port, document=document)

    def start(self, site, sfc, resource, operation, user, manorder=""):
        return self._call("start", site=site, sfc=sfc, resource=resource,
                         operation=operation, user=user, manorder=manorder)

    def complete(self, site, sfc, resource, operation, user):
        return self._call("complete", site=site, sfc=sfc, resource=resource,
                         operation=operation, user=user)

    def nc_log_complete(self, site, sfc, resource, operation, user,
                       nccode, location, nbdefault, reference, component):
        return self._call("nc_log_complete", site=site, sfc=sfc, resource=resource,
                         operation=operation, user=user, nccode=nccode,
                         location=location, nbdefault=nbdefault,
                         reference=reference, component=component)

    def product_status(self, parameter, sfc):
        return self._call("product_status", parameter=parameter, sfc=sfc)

    def net_ping(self, host):
        return self._call("net_ping", host=host)

    def engine_version(self):
        return self._call("engine_version")

    def library_version(self):
        return self._call("library_version")

    def is_expected_at(self, sfc, operation):
        return self._call("is_expected_at", sfc=sfc, operation=operation)

    def is_it_lockable(self, sfc):
        return self._call("is_it_lockable", sfc=sfc)

    def next_op(self, router, revision, operation):
        return self._call("next_op", router=router, revision=revision, operation=operation)

    def get_last_response(self):
        return self._call("get_last_response")

    def shutdown(self):
        with self._lock:
            if self._conn is not None:
                try:
                    self._conn.send(None)
                except Exception:
                    pass
            self._kill_worker()


# =============================================================================
# LARAVEL API CLIENT
# =============================================================================

class LaravelClient:
    """HTTP client for communicating with Laravel backend."""

    def __init__(self, config: BridgeConfig):
        self.base_url = config.laravel_url.rstrip("/")
        self.token = config.api_token
        self.connection_id = config.connection_id
        self.logger = logging.getLogger("laravel")

    def _request(self, method: str, path: str, data: dict = None) -> dict:
        """Make an HTTP request to Laravel API."""
        url = f"{self.base_url}/api/v1{path}"
        headers = {
            "Content-Type": "application/json",
            "Accept": "application/json",
        }
        if self.token:
            headers["Authorization"] = f"Bearer {self.token}"

        body = json.dumps(data).encode("utf-8") if data else None
        req = urllib.request.Request(url, data=body, headers=headers, method=method)

        try:
            with urllib.request.urlopen(req, timeout=10) as resp:
                return json.loads(resp.read().decode("utf-8"))
        except urllib.error.HTTPError as e:
            self.logger.error("Laravel API error: %s %s -> %s", method, path, e.code)
            return {"error": str(e)}
        except Exception as e:
            self.logger.error("Laravel API request failed: %s", e)
            return {"error": str(e)}

    def log_event(self, frame_code: int, code_name: str, fields: dict,
                  result: dict, duration_ms: int, plc_ip: str, plc_port: int,
                  correlation_id: str, raw_request: str, raw_response: str):
        """Log an interlock event to Laravel for audit trail."""
        return self._request("POST", "/actilock/events", {
            "connection_id": self.connection_id,
            "frame_code": frame_code,
            "frame_label": code_name,
            "plc_ip": plc_ip,
            "plc_port": plc_port,
            "sfc": fields.get("SFC"),
            "result": result.get("response"),
            "operation": fields.get("OPERATION") or fields.get("OP"),
            "user": fields.get("USER"),
            "is_accepted": result.get("success", False),
            "actilock_response": result.get("response"),
            "actilock_error": result.get("error"),
            "duration_ms": duration_ms,
            "ffi_success": result.get("success", False),
            "raw_request": raw_request,
            "raw_response": raw_response,
            "correlation_id": correlation_id,
        })

    def update_status(self, status: str, message: str = None,
                      version: str = None, connected: bool = False):
        """Update connection status in Laravel."""
        return self._request("POST", "/actilock/status", {
            "connection_id": self.connection_id,
            "status": status,
            "status_message": message,
            "version": version,
            "connected": connected,
        })

    def health_check(self) -> dict:
        """Check if Laravel is reachable."""
        return self._request("GET", "/actilock/health")


# =============================================================================
# INTERLOCK TCP SERVER
# =============================================================================

class InterlockTcpServer:
    """
    TCP server that accepts PLC connections and dispatches frames
    to the ACTILOCK engine via the InterlockProcessClient.
    """

    def __init__(self, config: BridgeConfig):
        self.config = config
        self.logger = logging.getLogger("tcp_server")
        self.client: Optional[InterlockProcessClient] = None
        self.laravel: Optional[LaravelClient] = None
        self._running = False
        self._server_socket: Optional[socket.socket] = None
        self._active_connections: dict[int, socket.socket] = {}
        self._conn_lock = threading.Lock()
        self._connection_counter = 0
        # Cache for per-workstation configs: {plc_ip: (config_dict, timestamp)}
        self._ws_config_cache: dict[str, tuple[dict, float]] = {}
        self._ws_cache_ttl = 60  # seconds

    def start(self):
        """Initialize the server and start listening."""
        self.logger.info("Starting Interlock TCP Server on %s:%d",
                        self.config.listen_host, self.config.listen_port)

        # Initialize the FFI client (with process isolation)
        self.client = InterlockProcessClient(self.config, self.config.ffi_timeout)

        # Initialize Laravel API client
        self.laravel = LaravelClient(self.config)

        # Connect to ACTILOCK engine
        self._connect_to_engine()

        # Create TCP server socket
        self._server_socket = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
        self._server_socket.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
        self._server_socket.settimeout(1.0)  # 1s accept timeout for graceful shutdown

        try:
            self._server_socket.bind((self.config.listen_host, self.config.listen_port))
            self._server_socket.listen(self.config.max_plc_connections)
        except OSError as e:
            self.logger.error("Failed to bind TCP server: %s", e)
            raise

        self._running = True
        self.logger.info("TCP Server listening on %s:%d (max %d connections)",
                        self.config.listen_host, self.config.listen_port,
                        self.config.max_plc_connections)

        # Start health monitor thread
        monitor_thread = threading.Thread(target=self._monitor_loop, daemon=True)
        monitor_thread.start()

        # Accept loop (main thread)
        self._accept_loop()

    def _accept_loop(self):
        """Main loop: accept PLC connections and spawn handler threads."""
        while self._running:
            try:
                client_socket, client_addr = self._server_socket.accept()
            except socket.timeout:
                continue
            except OSError:
                if self._running:
                    self.logger.error("Server socket error", exc_info=True)
                break

            plc_ip, plc_port = client_addr
            self.logger.info("PLC connected: %s:%d", plc_ip, plc_port)

            with self._conn_lock:
                self._connection_counter += 1
                conn_id = self._connection_counter
                self._active_connections[conn_id] = client_socket

            # Spawn handler thread for this PLC
            handler = threading.Thread(
                target=self._handle_plc,
                args=(client_socket, plc_ip, plc_port, conn_id),
                daemon=True,
            )
            handler.start()

    def _handle_plc(self, sock: socket.socket, plc_ip: str, plc_port: int, conn_id: int):
        """Handle a single PLC connection."""
        logger = logging.getLogger(f"plc.{plc_ip}:{plc_port}")
        logger.info("Handler started for PLC %s:%d", plc_ip, plc_port)

        try:
            sock.settimeout(self.config.tcp_read_timeout)
            while self._running:
                try:
                    data = sock.recv(MAX_FRAME_SIZE)
                except socket.timeout:
                    continue
                except ConnectionResetError:
                    logger.info("PLC disconnected (reset): %s:%d", plc_ip, plc_port)
                    break
                except OSError:
                    logger.info("PLC connection closed: %s:%d", plc_ip, plc_port)
                    break

                if not data:
                    logger.info("PLC disconnected (EOF): %s:%d", plc_ip, plc_port)
                    break

                # Process the frame
                response = self._process_frame(data, plc_ip, plc_port, logger)

                # Send response back to PLC
                if response:
                    try:
                        sock.sendall(response)
                    except OSError:
                        logger.error("Failed to send response to PLC %s:%d", plc_ip, plc_port)
                        break

        except Exception as e:
            logger.error("Handler error for PLC %s:%d: %s", plc_ip, plc_port, e, exc_info=True)
        finally:
            with self._conn_lock:
                self._active_connections.pop(conn_id, None)
            try:
                sock.close()
            except Exception:
                pass
            logger.info("Handler closed for PLC %s:%d", plc_ip, plc_port)

    def _process_frame(self, raw: bytes, plc_ip: str, plc_port: int,
                      logger: logging.Logger) -> Optional[bytes]:
        """
        Process a single TCP frame from a PLC.

        Returns: response frame bytes, or None if no response needed.
        """
        import uuid

        correlation_id = str(uuid.uuid4())
        start_time = time.monotonic()

        # Decode the frame
        parsed = decode_frame(raw)
        if not parsed["valid"]:
            logger.warning("Malformed frame from %s:%d: %s", plc_ip, plc_port, parsed["error"])
            self._increment_rejected()
            return encode_error(0x10, "PARSE_ERROR", parsed["error"])

        code = parsed["code"]
        code_name = parsed["code_name"]
        fields = parsed["fields"]

        logger.info("Frame from %s:%d: %s (SFC=%s)", plc_ip, plc_port, code_name,
                    fields.get("SFC", "?"))

        # Dispatch to ACTILOCK
        try:
            result = self._dispatch_by_code(code, fields, plc_ip)
        except Exception as e:
            logger.error("Dispatch failed for %s from %s:%d: %s",
                        code_name, plc_ip, plc_port, e, exc_info=True)
            result = (False, f"DISPATCH_ERROR: {e}")

        duration_ms = int((time.monotonic() - start_time) * 1000)
        success, response_text = result

        # Build response frame
        if success:
            response_frame = encode_response(code, response_text)
        else:
            response_frame = encode_error(code, "ACTILOCK_ERROR", response_text)

        # Log to Laravel (async, don't block PLC response)
        raw_request = raw.hex() if len(raw) < 512 else raw[:256].hex() + "..."
        raw_response = response_frame.hex() if len(response_frame) < 512 else response_frame[:256].hex() + "..."

        threading.Thread(
            target=self._log_to_laravel,
            args=(code, code_name, fields, {"success": success, "response": response_text},
                  duration_ms, plc_ip, plc_port, correlation_id, raw_request, raw_response),
            daemon=True,
        ).start()

        # Update counters locally
        self._update_counters(code, success)

        return response_frame

    def _dispatch_by_code(self, code: int, fields: dict, plc_ip: str = "") -> tuple:
        """Dispatch a frame to the appropriate ACTILOCK function."""
        config = self.config

        # Resolve per-workstation config from Laravel API
        ws = self._resolve_workstation_config(plc_ip)

        # Priority: PLC payload > per-workstation config > global defaults
        site = fields.get("SITE") or ws.get("site") or config.default_site
        resource = fields.get("RESOURCE") or ws.get("resource") or config.default_ressource
        operation = fields.get("OPERATION") or ws.get("operation") or config.default_operation
        user = fields.get("USER") or ws.get("user") or config.default_user

        if code == CODE_START:
            return self.client.start(
                site=site,
                sfc=fields.get("SFC", ""),
                resource=resource,
                operation=operation,
                user=user,
                manorder=fields.get("MANORDER", ""),
            )

        elif code == CODE_COMPLETE:
            return self.client.complete(
                site=site,
                sfc=fields.get("SFC", ""),
                resource=resource,
                operation=operation,
                user=user,
            )

        elif code == CODE_NCLOGCOMPLETE:
            return self.client.nc_log_complete(
                site=site,
                sfc=fields.get("SFC", ""),
                resource=resource,
                operation=operation,
                user=user,
                nccode=fields.get("NCCODE", ""),
                location=fields.get("LOCATION", ""),
                nbdefault=fields.get("NBDEFAULT", ""),
                reference=fields.get("REFERENCE", ""),
                component=fields.get("COMPONENT", ""),
            )

        elif code == CODE_PRODUCTSTATUS:
            return self.client.product_status(
                parameter=fields.get("PARAMETER", "STATUS"),
                sfc=fields.get("SFC", ""),
            )

        else:
            raise ValueError(f"Unknown frame code: 0x{code:02X}")

    def _resolve_workstation_config(self, plc_ip: str) -> dict:
        """
        Resolve per-workstation ACTILOCK config from Laravel API.

        Queries GET /api/v1/actilock/{connectionId}/workstation-config/{plcIp}
        to get resource/operation/user overrides for this specific PLC.

        Uses a 60-second cache to avoid querying Laravel on every frame.

        Returns empty dict if not found (caller falls back to global defaults).
        """
        if not plc_ip or not self.config.connection_id:
            return {}

        # Check cache first
        now = time.monotonic()
        if plc_ip in self._ws_config_cache:
            cached, ts = self._ws_config_cache[plc_ip]
            if now - ts < self._ws_cache_ttl:
                return cached

        try:
            url = (
                f"{self.config.laravel_url.rstrip('/')}/api/v1/actilock/"
                f"{self.config.connection_id}/workstation-config/{plc_ip}"
            )
            headers = {"Accept": "application/json"}
            if self.config.api_token:
                headers["Authorization"] = f"Bearer {self.config.api_token}"

            req = urllib.request.Request(url, headers=headers, method="GET")
            with urllib.request.urlopen(req, timeout=3) as resp:
                data = json.loads(resp.read().decode("utf-8"))
                # Cache the result (even if not found, to avoid re-querying)
                self._ws_config_cache[plc_ip] = (data, now)
                if data.get("found"):
                    self.logger.info(
                        "Resolved workstation config for PLC %s: resource=%s op=%s user=%s",
                        plc_ip, data.get("resource"), data.get("operation"), data.get("user"),
                    )
                return data
        except Exception as e:
            self.logger.debug("Could not resolve workstation config for PLC %s: %s", plc_ip, e)
            return {}

    def _connect_to_engine(self):
        """Connect to the ACTILOCK engine."""
        self.logger.info("Connecting to ACTILOCK engine at %s:%d...",
                        self.config.engine_host, self.config.engine_port)

        # TCP probe first
        try:
            with socket.create_connection(
                (self.config.engine_host, self.config.engine_port),
                timeout=3.0
            ):
                pass
        except OSError as e:
            self.logger.error("ACTILOCK engine unreachable: %s", e)
            self.laravel.update_status("error", f"Engine unreachable: {e}")
            return

        # Connect via FFI
        try:
            ok, response = self.client.connect(
                self.config.engine_host,
                self.config.engine_port,
                self.config.engine_document,
            )
            if ok:
                self.logger.info("Connected to ACTILOCK engine: %s", response)
                version = ""
                try:
                    _, version = self.client.engine_version()
                except Exception:
                    pass
                self.laravel.update_status("connected", response, version, True)
            else:
                self.logger.error("ACTILOCK Connect failed: %s", response)
                self.laravel.update_status("error", f"Connect failed: {response}")
        except Exception as e:
            self.logger.error("ACTILOCK Connect error: %s", e)
            self.laravel.update_status("error", str(e))

    def _monitor_loop(self):
        """Periodic health check of the ACTILOCK connection."""
        while self._running:
            time.sleep(self.config.monitor_interval)
            if not self._running:
                break

            try:
                ok, version = self.client.engine_version()
                if ok:
                    self.logger.debug("Health check OK: %s", version)
                else:
                    self.logger.warning("Health check: engine_version failed")
                    self._reconnect_to_engine()
            except Exception as e:
                self.logger.error("Health check failed: %s", e)
                self._reconnect_to_engine()

    def _reconnect_to_engine(self):
        """Attempt to reconnect to the ACTILOCK engine."""
        self.logger.info("Attempting to reconnect to ACTILOCK engine...")
        try:
            self._connect_to_engine()
        except Exception as e:
            self.logger.error("Reconnection failed: %s", e)

    def _log_to_laravel(self, code, code_name, fields, result, duration_ms,
                        plc_ip, plc_port, correlation_id, raw_request, raw_response):
        """Send event log to Laravel API (background)."""
        try:
            self.laravel.log_event(
                frame_code=code,
                code_name=code_name,
                fields=fields,
                result=result,
                duration_ms=duration_ms,
                plc_ip=plc_ip,
                plc_port=plc_port,
                correlation_id=correlation_id,
                raw_request=raw_request,
                raw_response=raw_response,
            )
        except Exception as e:
            self.logger.error("Failed to log event to Laravel: %s", e)

    def _increment_rejected(self):
        """Increment rejected counter (local + Laravel)."""
        try:
            self.laravel._request("POST", "/actilock/increment", {
                "connection_id": self.config.connection_id,
                "field": "interlocks_rejected",
            })
        except Exception:
            pass

    def _update_counters(self, code: int, success: bool):
        """Update frame counters (local + Laravel)."""
        field_map = {
            CODE_START: "start_count",
            CODE_COMPLETE: "complete_count",
            CODE_NCLOGCOMPLETE: "nclog_count",
        }
        field = field_map.get(code)
        if field:
            try:
                self.laravel._request("POST", "/actilock/increment", {
                    "connection_id": self.config.connection_id,
                    "field": field,
                })
            except Exception:
                pass

        if not success:
            self._increment_rejected()

    def stop(self):
        """Graceful shutdown."""
        self.logger.info("Stopping Interlock TCP Server...")
        self._running = False

        if self._server_socket:
            try:
                self._server_socket.close()
            except Exception:
                pass

        with self._conn_lock:
            for conn_id, sock in self._active_connections.items():
                try:
                    sock.close()
                except Exception:
                    pass
            self._active_connections.clear()

        if self.client:
            self.client.shutdown()

        if self.laravel:
            try:
                self.laravel.update_status("disconnected", "Server stopped")
            except Exception:
                pass

        self.logger.info("Interlock TCP Server stopped.")


# =============================================================================
# MAIN ENTRY POINT
# =============================================================================

def setup_logging(config: BridgeConfig):
    """Configure logging for the bridge."""
    log_dir = os.path.dirname(config.log_file)
    if log_dir and not os.path.exists(log_dir):
        os.makedirs(log_dir, exist_ok=True)

    handlers = [logging.StreamHandler(sys.stdout)]

    if config.log_file:
        handlers.append(logging.FileHandler(config.log_file))

    logging.basicConfig(
        level=getattr(logging, config.log_level.upper(), logging.INFO),
        format="%(asctime)s [%(name)s] %(levelname)s: %(message)s",
        datefmt="%Y-%m-%d %H:%M:%S",
        handlers=handlers,
    )


def main():
    """Main entry point."""
    # Load configuration
    config_file = os.environ.get("AEG_CONFIG_FILE", "")
    if config_file:
        config = BridgeConfig.from_file(config_file)
    else:
        config = BridgeConfig.from_env()

    setup_logging(config)
    logger = logging.getLogger("bridge")

    logger.info("=" * 60)
    logger.info("ACTI Edge Gateway — Interlock Bridge")
    logger.info("=" * 60)
    logger.info("Listen: %s:%d", config.listen_host, config.listen_port)
    logger.info("Engine: %s:%d", config.engine_host, config.engine_port)
    logger.info("Library: %s", config.lib_path)
    logger.info("Laravel: %s", config.laravel_url)
    logger.info("Max connections: %d", config.max_plc_connections)
    logger.info("=" * 60)

    # Create server
    server = InterlockTcpServer(config)

    # Handle signals for graceful shutdown
    def signal_handler(signum, frame):
        logger.info("Received signal %d, shutting down...", signum)
        server.stop()
        sys.exit(0)

    signal.signal(signal.SIGTERM, signal_handler)
    signal.signal(signal.SIGINT, signal_handler)

    # Start server (blocks until shutdown)
    try:
        server.start()
    except KeyboardInterrupt:
        logger.info("Keyboard interrupt, shutting down...")
        server.stop()
    except Exception as e:
        logger.error("Fatal error: %s", e, exc_info=True)
        server.stop()
        sys.exit(1)


if __name__ == "__main__":
    main()
