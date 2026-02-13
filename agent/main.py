#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""
Project file system Agent (configurable)
- Watches the project directory using watchdog
- Prints and logs file changes (created/modified/deleted)
- Logs structured JSONL and maintains a status heartbeat
- Fully configurable via agent/config.yml with live reload
"""

from __future__ import annotations

import argparse
import json
import logging
import os
import sys
import threading
import time
from dataclasses import dataclass
from datetime import datetime, timezone
from pathlib import Path, PurePosixPath
from typing import Optional, Set, Dict, Any, Tuple

try:
    from watchdog.observers import Observer
    from watchdog.events import FileSystemEvent, FileSystemEventHandler
except ImportError:
    sys.stderr.write(
        "watchdog غير مثبت. قم بتثبيته أولاً:\n"
        "  pip install watchdog\n"
    )
    sys.exit(1)

try:
    import yaml  # type: ignore
except ImportError:
    yaml = None  # Config will fall back to defaults if PyYAML not installed


@dataclass
class RuntimeConfig:
    # resolved absolute paths
    watch_path: Path
    recursive: bool
    ignore_paths: Set[Path]
    ignore_globs: Set[str]
    # features
    feature_console_log: bool
    feature_text_log: bool
    feature_jsonl_log: bool
    feature_status: bool
    event_types: Set[str]
    # files
    log_path: Path
    jsonl_path: Path
    status_path: Path
    # intervals
    status_interval: float
    # commands channel
    commands_enabled: bool
    inbox_dir: Path
    outbox_dir: Path
    command_poll_interval: float
    # noise reduction
    debounce_ms: float
    aggregate_window_ms: float
    # diagnostics
    aggregate_include_debounced: bool


def load_config(agent_dir: Path, project_root: Path) -> RuntimeConfig:
    # Defaults that match safe behavior
    defaults: Dict[str, Any] = {
        "watch": {"path": str(project_root), "recursive": True},
        "ignore": {
            "paths": [
                str(agent_dir / "events.log"),
                str(agent_dir / "events.jsonl"),
                str(agent_dir / "status.json"),
                str(agent_dir / "status.json.tmp"),
                str(agent_dir / "stop_agent.ps1"),
                str(agent_dir / "commands"),
            ],
            "globs": [".git/**", "agent/commands/**"],
        },
        "features": {
            "console_log": True,
            "text_log": True,
            "jsonl_log": True,
            "status": True,
            "event_types": ["created", "modified", "deleted"],
            "debounce_ms": 0,
            "aggregate_window_ms": 0,
            "aggregate_include_debounced": False,
        },
        "logging": {"level": "INFO", "file": str(agent_dir / "events.log")},
        "jsonl": {"file": str(agent_dir / "events.jsonl")},
        "status": {"file": str(agent_dir / "status.json"), "interval_sec": 5.0},
        "commands": {
            "enabled": False,
            "inbox": str(agent_dir / "commands" / "inbox"),
            "outbox": str(agent_dir / "commands" / "outbox"),
            "poll_interval_ms": 500,
        },
    }

    cfg_path = agent_dir / "config.yml"
    data: Dict[str, Any] = defaults.copy()
    if cfg_path.exists() and yaml is not None:
        try:
            with cfg_path.open("r", encoding="utf-8") as f:
                file_cfg = yaml.safe_load(f) or {}
            # Shallow merge by sections
            for k, v in file_cfg.items():
                if isinstance(v, dict) and isinstance(defaults.get(k), dict):
                    merged = {**defaults[k], **v}
                    data[k] = merged
                else:
                    data[k] = v
        except Exception as exc:
            sys.stderr.write(f"Failed to read config.yml, using defaults. Error: {exc}\n")
    elif cfg_path.exists() and yaml is None:
        sys.stderr.write("PyYAML غير مثبت. تجاهل config.yml واستخدام الإعدادات الافتراضية.\n"
                         "قم بالتثبيت: pip install pyyaml\n")

    # Resolve
    watch_path = Path(data["watch"]["path"]).resolve()
    recursive = bool(data["watch"].get("recursive", True))

    log_path = Path(data["logging"]["file"]).resolve()
    jsonl_path = Path(data["jsonl"]["file"]).resolve()
    status_path = Path(data["status"]["file"]).resolve()

    ignore_paths = {Path(p).resolve() for p in data["ignore"].get("paths", [])}
    ignore_globs = set(map(str, data["ignore"].get("globs", [])))

    features = data["features"]

    return RuntimeConfig(
        watch_path=watch_path,
        recursive=recursive,
        ignore_paths=ignore_paths,
        ignore_globs=ignore_globs,
        feature_console_log=bool(features.get("console_log", True)),
        feature_text_log=bool(features.get("text_log", True)),
        feature_jsonl_log=bool(features.get("jsonl_log", True)),
        feature_status=bool(features.get("status", True)),
        event_types=set(map(str, features.get("event_types", ["created", "modified", "deleted"]))),
        log_path=log_path,
        jsonl_path=jsonl_path,
        status_path=status_path,
        status_interval=float(data["status"].get("interval_sec", 5.0)),
        commands_enabled=bool(data.get("commands", {}).get("enabled", False)),
        inbox_dir=Path(data.get("commands", {}).get("inbox", str(agent_dir / "commands" / "inbox"))).resolve(),
        outbox_dir=Path(data.get("commands", {}).get("outbox", str(agent_dir / "commands" / "outbox"))).resolve(),
        command_poll_interval=float(data.get("commands", {}).get("poll_interval_ms", 500)) / 1000.0,
        debounce_ms=float(features.get("debounce_ms", 0)),
        aggregate_window_ms=float(features.get("aggregate_window_ms", 0)),
        aggregate_include_debounced=bool(features.get("aggregate_include_debounced", False)),
    )


class AgentState:
    def __init__(self) -> None:
        self.start_time: float = time.time()
        self.pid: int = os.getpid()
        self.last_event_ts: Optional[str] = None
        self.watch_path: Optional[Path] = None
        self.recursive: bool = True
        # Base ignored from config
        self.ignored_paths: Set[Path] = set()
        self.ignore_globs: Set[str] = set()
        # Extra ignored that can be adjusted via commands
        self.extra_ignored_paths: Set[Path] = set()
        self.extra_ignore_globs: Set[str] = set()
        # Pause/resume state
        self.paused: bool = False
        # Noise reduction state
        self._debounce_last: Dict[str, float] = {}
        self._agg_counts: Dict[str, int] = {"created": 0, "modified": 0, "deleted": 0}
        self._agg_debounced_skipped: int = 0
        self._agg_lock = threading.Lock()
        self._agg_window_start: float = time.time()
        # Commands transient state
        self.command_retries: Dict[str, int] = {}
        self.version: str = "1.3.1-stage4"
        self._lock = threading.Lock()
        self.config: Optional[RuntimeConfig] = None

    def update_last_event(self, iso_ts: str) -> None:
        with self._lock:
            self.last_event_ts = iso_ts

    def update_config(self, cfg: RuntimeConfig) -> None:
        with self._lock:
            self.config = cfg
            self.watch_path = cfg.watch_path
            self.recursive = cfg.recursive
            self.ignored_paths = set(cfg.ignore_paths)
            self.ignore_globs = set(cfg.ignore_globs)

    def snapshot(self) -> dict:
        with self._lock:
            return {
                "alive": True,
                "pid": self.pid,
                "uptime_sec": int(time.time() - self.start_time),
                "watch_path": str(self.watch_path) if self.watch_path else None,
                "recursive": self.recursive,
                "paused": self.paused,
                "ignored": [str(p) for p in sorted(self.ignored_paths)],
                "ignored_extra": [str(p) for p in sorted(self.extra_ignored_paths)],
                "last_event_ts": self.last_event_ts,
                "version": self.version,
            }


def write_json_atomic(path: Path, data: dict) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    tmp = path.with_suffix(path.suffix + ".tmp")
    with tmp.open("w", encoding="utf-8") as f:
        json.dump(data, f, ensure_ascii=False)
    os.replace(str(tmp), str(path))


class ProjectEventHandler(FileSystemEventHandler):
    """Custom handler for file system events with filtering and robust logging."""

    def __init__(self, logger: logging.Logger, base_path: Path, jsonl_path: Path, state: AgentState) -> None:
        super().__init__()
        self.logger = logger
        self.base_path = base_path.resolve()
        self.jsonl_path = jsonl_path
        self.state = state

    def _normalized(self, p: str | Path) -> Path:
        # Normalize Windows extended-length paths (\\?\ prefix) to avoid mismatches
        s = str(p)
        if s.startswith('\\\\?\\'):
            s = s[4:]
        return Path(s).resolve()

    def _is_ignored(self, path: str | Path) -> bool:
        try:
            np = self._normalized(path)
            # Combine base ignores with extra ignores (from commands)
            combined_paths = set(self.state.ignored_paths)
            try:
                combined_paths |= set(self.state.extra_ignored_paths)
            except Exception:
                pass
            for ip in combined_paths:
                if np == ip:
                    return True
                try:
                    if np.is_relative_to(ip):
                        return True
                except Exception:
                    if str(np).startswith(str(ip) + os.sep):
                        return True
            # Glob ignores (use relative posix path from base)
            try:
                rel = self._normalized(path).relative_to(self.base_path)
                rel_posix = str(PurePosixPath(rel.as_posix()))
                combined_globs = set(self.state.ignore_globs)
                try:
                    combined_globs |= set(self.state.extra_ignore_globs)
                except Exception:
                    pass
                for pattern in combined_globs:
                    if PurePosixPath(rel_posix).match(pattern):
                        return True
            except Exception:
                pass
            return False
        except Exception:
            # If normalization fails for any reason, do not ignore by default
            return False

    def _rel(self, p: str | Path) -> str:
        try:
            return str(self._normalized(p).relative_to(self.base_path))
        except Exception:
            return str(p)

    def _append_jsonl(self, payload: dict) -> None:
        if not (self.state.config and self.state.config.feature_jsonl_log):
            return
        try:
            self.jsonl_path.parent.mkdir(parents=True, exist_ok=True)
            with self.jsonl_path.open("a", encoding="utf-8") as jf:
                json.dump(payload, jf, ensure_ascii=False)
                jf.write("\n")
        except Exception as exc:
            self.logger.exception("Failed to append JSONL event: %s", exc)

    def _emit(self, event: FileSystemEvent, change: str) -> None:
        # Respect pause state
        if getattr(self.state, "paused", False):
            return
        if self._is_ignored(event.src_path):
            return
        try:
            now_ts = time.time()
            now_iso = datetime.now(timezone.utc).isoformat()
            file_abs = str(self._normalized(event.src_path))
            file_rel = self._rel(event.src_path)

            # Debounce per (change:path) key if enabled
            debounce_ms = 0.0
            if self.state.config:
                try:
                    debounce_ms = float(getattr(self.state.config, "debounce_ms", 0.0) or 0.0)
                except Exception:
                    debounce_ms = 0.0
            if debounce_ms > 0:
                key = f"{change}:{file_rel.lower()}"
                last = self.state._debounce_last.get(key)
                if last is not None and (now_ts - last) < (debounce_ms / 1000.0):
                    try:
                        with self.state._agg_lock:
                            self.state._agg_debounced_skipped += 1
                    except Exception:
                        pass
                    return
                self.state._debounce_last[key] = now_ts

            # Console output (configurable)
            if self.state.config and self.state.config.feature_console_log:
                print(f"File: {file_rel}")
                print(f"Change: {change}")

            # Log to file with timestamp (configurable)
            if self.state.config and self.state.config.feature_text_log:
                self.logger.info("%s - %s", change.upper(), file_rel)

            # Structured JSON event
            payload = {
                "ts": now_iso,
                "event": change,
                "path_rel": file_rel,
                "path_abs": file_abs,
                "is_dir": False,
            }
            self._append_jsonl(payload)

            # Aggregate counts
            try:
                with self.state._agg_lock:
                    if change in self.state._agg_counts:
                        self.state._agg_counts[change] += 1
                    else:
                        self.state._agg_counts[change] = 1
            except Exception:
                pass

            # Update state
            self.state.update_last_event(now_iso)
        except Exception as exc:
            # Ensure handler exceptions don't crash the observer
            self.logger.exception("Error while handling event %s for %s: %s", change, event.src_path, exc)

    # Only handle the required three types explicitly
    def on_created(self, event: FileSystemEvent) -> None:
        if not event.is_directory and (not self.state.config or "created" in self.state.config.event_types):
            self._emit(event, "created")

    def on_modified(self, event: FileSystemEvent) -> None:
        if not event.is_directory and (not self.state.config or "modified" in self.state.config.event_types):
            self._emit(event, "modified")

    def on_deleted(self, event: FileSystemEvent) -> None:
        if not event.is_directory and (not self.state.config or "deleted" in self.state.config.event_types):
            self._emit(event, "deleted")


def setup_logger() -> logging.Logger:
    logger = logging.getLogger("project_agent")
    logger.propagate = False
    return logger


def reconfigure_logger(logger: logging.Logger, cfg: RuntimeConfig) -> None:
    # Remove all existing handlers
    for h in list(logger.handlers):
        try:
            logger.removeHandler(h)
            try:
                h.flush()
                if hasattr(h, 'close'):
                    h.close()
            except Exception:
                pass
        except Exception:
            pass

    # Set level
    level = getattr(logging, "INFO", logging.INFO)
    logger.setLevel(level)

    # Conditionally add handlers
    fmt = logging.Formatter("%(asctime)s - %(levelname)s - %(message)s")

    if cfg.feature_text_log:
        try:
            cfg.log_path.parent.mkdir(parents=True, exist_ok=True)
            fh = logging.FileHandler(cfg.log_path, encoding="utf-8")
            fh.setLevel(level)
            fh.setFormatter(fmt)
            logger.addHandler(fh)
        except Exception as exc:
            sys.stderr.write(f"Failed to set file handler: {exc}\n")

    if cfg.feature_console_log:
        ch = logging.StreamHandler(sys.stdout)
        ch.setLevel(level)
        ch.setFormatter(fmt)
        logger.addHandler(ch)


def parse_args(default_watch: Path) -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Project file system agent.")
    parser.add_argument(
        "--path",
        type=str,
        default=str(default_watch),
        help="Override watch path (otherwise from config.yml)",
    )
    parser.add_argument(
        "--no-recursive",
        action="store_true",
        help="Override to disable recursive watching",
    )
    return parser.parse_args()


def main() -> int:
    try:
        agent_dir = Path(__file__).resolve().parent
        project_root = agent_dir.parent  # Project root
        args = parse_args(project_root)

        # Load config and allow CLI overrides
        cfg = load_config(agent_dir, project_root)
        # CLI overrides if provided
        if args.path:
            cfg.watch_path = Path(args.path).resolve()
        if args.no_recursive:
            cfg.recursive = False

        # Validate watch path
        watch_path = cfg.watch_path
        if not watch_path.exists() or not watch_path.is_dir():
            sys.stderr.write(f"Invalid watch path: {watch_path}\n")
            return 2

        # Prepare logger based on config
        logger = setup_logger()
        reconfigure_logger(logger, cfg)

        # Agent state
        state = AgentState()
        state.update_config(cfg)

        event_handler = ProjectEventHandler(
            logger=logger,
            base_path=watch_path,
            jsonl_path=cfg.jsonl_path,
            state=state,
        )
        observer = Observer()
        observer.schedule(event_handler, str(watch_path), recursive=state.recursive)

        logger.info("Agent starting. Watching: %s (recursive=%s)", watch_path, state.recursive)
        if cfg.feature_console_log:
            print("Agent is running. Use agent/stop_agent.ps1 to stop.")

        # Status heartbeat and config reload threads
        stop_event = threading.Event()

        def status_worker() -> None:
            while not stop_event.is_set():
                if state.config and state.config.feature_status:
                    payload = state.snapshot()
                    try:
                        write_json_atomic(state.config.status_path, payload)
                    except Exception as exc:
                        logger.exception("Failed to write status.json: %s", exc)
                # Wait with wakeup on stop
                interval = 5.0
                if state.config:
                    interval = max(1.0, float(state.config.status_interval))
                stop_event.wait(timeout=interval)

        def config_worker() -> None:
            cfg_path = agent_dir / "config.yml"
            last_mtime = cfg_path.stat().st_mtime if cfg_path.exists() else 0
            while not stop_event.is_set():
                try:
                    if cfg_path.exists():
                        mtime = cfg_path.stat().st_mtime
                        if mtime != last_mtime and yaml is not None:
                            new_cfg = load_config(agent_dir, project_root)
                            # Apply overrides from current CLI state
                            if args.path:
                                new_cfg.watch_path = Path(args.path).resolve()
                            if args.no_recursive:
                                new_cfg.recursive = False
                            # Reconfigure logger if needed
                            reconfigure_logger(logger, new_cfg)
                            state.update_config(new_cfg)
                            last_mtime = mtime
                            logger.info("Config reloaded from agent/config.yml")
                    stop_event.wait(timeout=1.0)
                except Exception as exc:
                    logger.exception("Error while reloading config: %s", exc)
                    stop_event.wait(timeout=2.0)

        t_status = threading.Thread(target=status_worker, name="agent-status", daemon=True)
        t_status.start()
        t_config = threading.Thread(target=config_worker, name="agent-config", daemon=True)
        t_config.start()

        def aggregate_worker() -> None:
            while not stop_event.is_set():
                try:
                    cfg_local = state.config
                    if not cfg_local or not cfg_local.feature_jsonl_log:
                        stop_event.wait(timeout=1.0)
                        continue
                    window_ms = 0.0
                    try:
                        window_ms = float(getattr(cfg_local, "aggregate_window_ms", 0.0) or 0.0)
                    except Exception:
                        window_ms = 0.0
                    if window_ms <= 0:
                        stop_event.wait(timeout=1.0)
                        continue
                    # Wait for the window duration or stop
                    if stop_event.wait(timeout=max(0.1, window_ms / 1000.0)):
                        break
                    now_iso = datetime.now(timezone.utc).isoformat()
                    include_debounced = False
                    try:
                        include_debounced = bool(getattr(cfg_local, "aggregate_include_debounced", False))
                    except Exception:
                        include_debounced = False
                    with state._agg_lock:
                        counts = dict(state._agg_counts)
                        debounced_skipped = int(getattr(state, "_agg_debounced_skipped", 0))
                        state._agg_counts = {"created": 0, "modified": 0, "deleted": 0}
                        state._agg_debounced_skipped = 0
                        window_start = state._agg_window_start
                        state._agg_window_start = time.time()
                    # Only write if there were events
                    if sum(counts.values()) > 0:
                        try:
                            cfg_local.jsonl_path.parent.mkdir(parents=True, exist_ok=True)
                            with cfg_local.jsonl_path.open("a", encoding="utf-8") as jf:
                                payload = {
                                    "ts": now_iso,
                                    "event": "aggregate",
                                    "window_ms": window_ms,
                                    "window_start_ts": datetime.fromtimestamp(window_start, tz=timezone.utc).isoformat(),
                                    "window_end_ts": now_iso,
                                    "counts": counts,
                                }
                                if include_debounced:
                                    payload["debounced_skipped"] = debounced_skipped
                                json.dump(payload, jf, ensure_ascii=False)
                                jf.write("\n")
                        except Exception as exc:
                            logger.exception("Failed to append aggregate JSONL: %s", exc)
                except Exception as exc:
                    logger.exception("Aggregation worker error: %s", exc)
                    stop_event.wait(timeout=1.0)

        def command_worker() -> None:
            while not stop_event.is_set():
                cfg_local = state.config
                if not cfg_local or not cfg_local.commands_enabled:
                    stop_event.wait(timeout=1.0)
                    continue
                inbox = cfg_local.inbox_dir
                outbox = cfg_local.outbox_dir
                poll = max(0.1, float(cfg_local.command_poll_interval))
                try:
                    inbox.mkdir(parents=True, exist_ok=True)
                    outbox.mkdir(parents=True, exist_ok=True)
                    processed_dir = inbox / "processed"
                    invalid_dir = inbox / "invalid"
                    processed_dir.mkdir(parents=True, exist_ok=True)
                    invalid_dir.mkdir(parents=True, exist_ok=True)
                    for cmd_file in inbox.glob("*.json"):
                        try:
                            st = cmd_file.stat()
                            if (time.time() - st.st_mtime) < 0.05:
                                continue
                            with cmd_file.open("r", encoding="utf-8") as f:
                                data = json.load(f)
                            # Reset retries on successful load
                            try:
                                state.command_retries.pop(cmd_file.name, None)
                            except Exception:
                                pass
                            cmd_id = str(data.get("id") or cmd_file.stem)
                            op = str(data.get("op") or "").lower()
                            resp: Dict[str, Any] = {
                                "id": cmd_id,
                                "op": op,
                                "ts": datetime.now(timezone.utc).isoformat(),
                            }
                            ok = True
                            msg: Optional[str] = None
                            if op == "pause":
                                state.paused = True
                                msg = "paused"
                            elif op == "resume":
                                state.paused = False
                                msg = "resumed"
                            elif op == "ping":
                                resp["pong"] = True
                                resp["status"] = state.snapshot()
                            elif op == "set_ignored":
                                paths = data.get("paths", [])
                                globs = data.get("globs", [])
                                try:
                                    new_extra_paths = {Path(p).resolve() for p in paths}
                                except Exception:
                                    new_extra_paths = set()
                                new_extra_globs = set(map(str, globs))
                                state.extra_ignored_paths = new_extra_paths
                                state.extra_ignore_globs = new_extra_globs
                                msg = "ignored rules updated"
                                resp["ignored_paths"] = [str(p) for p in sorted(new_extra_paths)]
                                resp["ignored_globs"] = sorted(new_extra_globs)
                            elif op == "add_ignored":
                                paths = data.get("paths", [])
                                globs = data.get("globs", [])
                                try:
                                    add_paths = {Path(p).resolve() for p in paths}
                                except Exception:
                                    add_paths = set()
                                add_globs = set(map(str, globs))
                                state.extra_ignored_paths |= add_paths
                                state.extra_ignore_globs |= add_globs
                                msg = "ignored rules added"
                                resp["ignored_paths"] = [str(p) for p in sorted(state.extra_ignored_paths)]
                                resp["ignored_globs"] = sorted(state.extra_ignore_globs)
                            elif op == "clear_ignored":
                                state.extra_ignored_paths = set()
                                state.extra_ignore_globs = set()
                                msg = "ignored rules cleared"
                                resp["ignored_paths"] = []
                                resp["ignored_globs"] = []
                            elif op == "get_ignored":
                                resp["base_ignored_paths"] = [str(p) for p in sorted(state.ignored_paths)]
                                resp["base_ignored_globs"] = sorted(state.ignore_globs)
                                resp["extra_ignored_paths"] = [str(p) for p in sorted(state.extra_ignored_paths)]
                                resp["extra_ignored_globs"] = sorted(state.extra_ignore_globs)
                                msg = "ignored rules returned"
                            elif op == "rotate_logs":
                                try:
                                    # Close and remove existing handlers
                                    for h in list(logger.handlers):
                                        try:
                                            logger.removeHandler(h)
                                            try:
                                                h.flush()
                                                if hasattr(h, 'close'):
                                                    h.close()
                                            except Exception:
                                                pass
                                        except Exception:
                                            pass
                                    ts_suffix = datetime.now().strftime("%Y%m%d-%H%M%S")
                                    rotated = {}
                                    if cfg_local.log_path.exists():
                                        rotated_log = cfg_local.log_path.with_name(cfg_local.log_path.stem + f".{ts_suffix}" + cfg_local.log_path.suffix)
                                        cfg_local.log_path.rename(rotated_log)
                                        rotated["log"] = str(rotated_log)
                                    if cfg_local.jsonl_path.exists():
                                        rotated_jsonl = cfg_local.jsonl_path.with_name(cfg_local.jsonl_path.stem + f".{ts_suffix}" + cfg_local.jsonl_path.suffix)
                                        cfg_local.jsonl_path.rename(rotated_jsonl)
                                        rotated["jsonl"] = str(rotated_jsonl)
                                    reconfigure_logger(logger, cfg_local)
                                    msg = "logs rotated"
                                    resp["rotated"] = rotated
                                except Exception as e:
                                    ok = False
                                    msg = f"rotate failed: {e}"
                            else:
                                ok = False
                                msg = "unknown op"
                            resp["ok"] = ok
                            if msg:
                                resp["msg"] = msg
                            out = outbox / f"{cmd_id}.response.json"
                            write_json_atomic(out, resp)
                            dest = processed_dir / (cmd_file.name + ".done")
                            try:
                                cmd_file.replace(dest)
                            except Exception:
                                try:
                                    cmd_file.unlink()
                                except Exception:
                                    pass
                        except json.JSONDecodeError as exc:
                            # Retry and after threshold move to invalid with error report
                            try:
                                key = cmd_file.name
                                cnt = state.command_retries.get(key, 0) + 1
                                state.command_retries[key] = cnt
                                if cnt >= 3:
                                    err = {
                                        "ts": datetime.now(timezone.utc).isoformat(),
                                        "filename": cmd_file.name,
                                        "error": "json_decode",
                                        "msg": str(exc),
                                    }
                                    err_path = (inbox / "invalid" / f"{cmd_file.stem}.error.json")
                                    write_json_atomic(err_path, err)
                                    dest = inbox / "invalid" / cmd_file.name
                                    try:
                                        cmd_file.replace(dest)
                                    except Exception:
                                        try:
                                            cmd_file.unlink()
                                        except Exception:
                                            pass
                                    # Reset retries
                                    state.command_retries.pop(key, None)
                            except Exception:
                                pass
                            continue
                        except UnicodeDecodeError as exc:
                            # Treat as invalid JSON as well
                            try:
                                key = cmd_file.name
                                cnt = state.command_retries.get(key, 0) + 1
                                state.command_retries[key] = cnt
                                if cnt >= 3:
                                    err = {
                                        "ts": datetime.now(timezone.utc).isoformat(),
                                        "filename": cmd_file.name,
                                        "error": "unicode_decode",
                                        "msg": str(exc),
                                    }
                                    err_path = (inbox / "invalid" / f"{cmd_file.stem}.error.json")
                                    write_json_atomic(err_path, err)
                                    dest = inbox / "invalid" / cmd_file.name
                                    try:
                                        cmd_file.replace(dest)
                                    except Exception:
                                        try:
                                            cmd_file.unlink()
                                        except Exception:
                                            pass
                                    state.command_retries.pop(key, None)
                            except Exception:
                                pass
                            continue
                        except Exception as exc:
                            logger.exception("Failed processing command %s: %s", cmd_file, exc)
                    stop_event.wait(timeout=poll)
                except Exception as exc:
                    logger.exception("Command worker error: %s", exc)
                    stop_event.wait(timeout=1.0)

        t_agg = threading.Thread(target=aggregate_worker, name="agent-aggregate", daemon=True)
        t_agg.start()
        t_cmd = threading.Thread(target=command_worker, name="agent-commands", daemon=True)
        t_cmd.start()

        try:
            observer.start()
            while True:
                time.sleep(1.0)
        except KeyboardInterrupt:
            logger.info("Shutdown requested by user (KeyboardInterrupt)")
        except Exception as exc:
            logger.exception("Unhandled error in agent loop: %s", exc)
            return 1
        finally:
            try:
                observer.stop()
                observer.join(timeout=5.0)
            except Exception as exc:
                logger.exception("Error during observer shutdown: %s", exc)
            finally:
                stop_event.set()
                try:
                    t_status.join(timeout=5.0)
                except Exception:
                    pass
                try:
                    t_config.join(timeout=5.0)
                except Exception:
                    pass
                try:
                    t_agg.join(timeout=5.0)
                except Exception:
                    pass
                try:
                    t_cmd.join(timeout=5.0)
                except Exception:
                    pass

        logger.info("Agent stopped.")
        return 0

    except Exception as exc:
        ts = datetime.now().isoformat()
        sys.stderr.write(f"[" + ts + "] Fatal agent error: " + str(exc) + "\n")
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
