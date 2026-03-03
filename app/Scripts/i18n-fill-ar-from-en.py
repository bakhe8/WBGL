#!/usr/bin/env python3
"""
Fill Arabic locale TODO placeholders from English locale values.

Usage:
  python app/Scripts/i18n-fill-ar-from-en.py
"""

from __future__ import annotations

import json
import re
import sys
import time
from pathlib import Path
from typing import Dict, List, Tuple

from deep_translator import GoogleTranslator


ROOT = Path(__file__).resolve().parents[2]
AR_DIR = ROOT / "public" / "locales" / "ar"
EN_DIR = ROOT / "public" / "locales" / "en"
CACHE_PATH = ROOT / "storage" / "i18n" / "ar-translation-cache.json"

TODO_AR_PREFIX = "__TODO_AR__"
TODO_EN_PREFIX = "__TODO_EN__"

PLACEHOLDER_RE = re.compile(r"{{\s*[^}]+\s*}}")
HTML_TAG_RE = re.compile(r"</?[^>]+>")
HAS_LATIN_RE = re.compile(r"[A-Za-z]")
TECHNICAL_RE = re.compile(
    r"(document\.getElementById|TableManager|event\.preventDefault|input\[|to_char\(|ENT_QUOTES|"
    r"button\.|\.print-icon-btn|Y-m-d|H:i|this\.checked|dispatchEvent|click\(\)|style\.display|"
    r"CAST\(|timestamp|selectall|prefers-color-scheme|HH24)",
    re.IGNORECASE,
)


def load_json(path: Path) -> Dict[str, str]:
    if not path.exists():
        return {}
    with path.open("r", encoding="utf-8") as f:
        data = json.load(f)
        if isinstance(data, dict):
            return data
    return {}


def save_json(path: Path, payload: Dict[str, str]) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    with path.open("w", encoding="utf-8", newline="\n") as f:
        json.dump(payload, f, ensure_ascii=False, indent=4)
        f.write("\n")


def mask_tokens(text: str) -> Tuple[str, List[str]]:
    tokens: List[str] = []

    def _mask(match: re.Match[str]) -> str:
        token = match.group(0)
        idx = len(tokens)
        tokens.append(token)
        return f"@@WBGL_TOKEN_{idx}@@"

    masked = PLACEHOLDER_RE.sub(_mask, text)
    masked = HTML_TAG_RE.sub(_mask, masked)
    return masked, tokens


def unmask_tokens(text: str, tokens: List[str]) -> str:
    output = text
    for i, token in enumerate(tokens):
        output = output.replace(f"@@WBGL_TOKEN_{i}@@", token)
    return output


def should_skip_source(en_value: str) -> bool:
    if not isinstance(en_value, str):
        return True
    stripped = en_value.strip()
    if stripped == "":
        return True
    if stripped.startswith(TODO_EN_PREFIX):
        return True
    return False


def is_technical_literal(value: str) -> bool:
    v = value.strip()
    if v == "":
        return True
    if TECHNICAL_RE.search(v):
        return True
    if re.fullmatch(r"[A-Z]{2,6}", v):
        return True
    if re.fullmatch(r"[A-Za-z0-9_.:-]+", v) and "." in v:
        return True
    return False


def translate_with_retry(translator: GoogleTranslator, text: str, retries: int = 3) -> str:
    masked, tokens = mask_tokens(text)
    last_error: Exception | None = None
    for attempt in range(1, retries + 1):
        try:
            translated = translator.translate(masked) or ""
            translated = translated.strip()
            translated = unmask_tokens(translated, tokens)
            return translated
        except Exception as exc:  # noqa: BLE001
            last_error = exc
            time.sleep(0.35 * attempt)
    if last_error is not None:
        raise last_error
    return text


def main() -> int:
    ar_files = sorted(AR_DIR.glob("*.json"))
    if not ar_files:
        print("No AR locale files found.", file=sys.stderr)
        return 1

    cache = load_json(CACHE_PATH)
    translator = GoogleTranslator(source="en", target="ar")

    total_todo = 0
    total_filled = 0
    updated_files = 0

    for ar_path in ar_files:
        ns = ar_path.name
        en_path = EN_DIR / ns
        ar_data = load_json(ar_path)
        en_data = load_json(en_path)

        file_todo = 0
        file_filled = 0
        changed = False

        for key, ar_val in list(ar_data.items()):
            if not isinstance(ar_val, str) or not ar_val.strip().startswith(TODO_AR_PREFIX):
                continue

            file_todo += 1
            total_todo += 1
            en_val = en_data.get(key, "")
            if should_skip_source(en_val):
                continue

            en_text = en_val.strip()
            if is_technical_literal(en_text) or HAS_LATIN_RE.search(en_text) is None:
                translated = en_text
            else:
                cache_key = f"{ns}|{key}|{en_text}"
                translated = cache.get(cache_key)
                if not translated:
                    translated = translate_with_retry(translator, en_text)
                    cache[cache_key] = translated

            if isinstance(translated, str) and translated.strip():
                ar_data[key] = translated.strip()
                file_filled += 1
                total_filled += 1
                changed = True

        if changed:
            save_json(ar_path, ar_data)
            updated_files += 1

        if file_todo > 0:
            print(f"{ns}: todo={file_todo}, filled={file_filled}")

    save_json(CACHE_PATH, cache)
    print("---")
    print(f"FILES_UPDATED: {updated_files}")
    print(f"TOTAL_TODO_FOUND: {total_todo}")
    print(f"TOTAL_FILLED: {total_filled}")
    print(f"CACHE_PATH: {CACHE_PATH}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
