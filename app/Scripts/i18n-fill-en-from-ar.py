#!/usr/bin/env python3
"""
Fill English locale TODO placeholders from Arabic locale values.

Usage:
  python app/Scripts/i18n-fill-en-from-ar.py
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
CACHE_PATH = ROOT / "storage" / "i18n" / "en-translation-cache.json"

TODO_EN_PREFIX = "__TODO_EN__"
TODO_AR_PREFIX = "__TODO_AR__"

PLACEHOLDER_RE = re.compile(r"{{\s*[^}]+\s*}}")
HTML_TAG_RE = re.compile(r"</?[^>]+>")
HAS_ARABIC_RE = re.compile(r"[\u0600-\u06FF]")


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


def should_skip_source(ar_value: str) -> bool:
    if not isinstance(ar_value, str):
        return True
    stripped = ar_value.strip()
    if stripped == "":
        return True
    if stripped.startswith(TODO_AR_PREFIX):
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
    en_files = sorted(EN_DIR.glob("*.json"))
    if not en_files:
        print("No EN locale files found.", file=sys.stderr)
        return 1

    cache = load_json(CACHE_PATH)
    translator = GoogleTranslator(source="ar", target="en")

    total_todo = 0
    total_filled = 0
    updated_files = 0

    for en_path in en_files:
        ns = en_path.name
        ar_path = AR_DIR / ns
        ar_data = load_json(ar_path)
        en_data = load_json(en_path)

        file_todo = 0
        file_filled = 0
        changed = False

        for key, en_val in list(en_data.items()):
            if not isinstance(en_val, str) or not en_val.strip().startswith(TODO_EN_PREFIX):
                continue

            file_todo += 1
            total_todo += 1
            ar_val = ar_data.get(key, "")
            if should_skip_source(ar_val):
                continue

            cache_key = f"{ns}|{key}|{ar_val}"
            translated = cache.get(cache_key)
            if not translated:
                # If no Arabic characters, keep as-is (useful for codes/latin literals).
                if HAS_ARABIC_RE.search(ar_val) is None:
                    translated = ar_val.strip()
                else:
                    translated = translate_with_retry(translator, ar_val)
                cache[cache_key] = translated

            if isinstance(translated, str) and translated.strip():
                en_data[key] = translated.strip()
                file_filled += 1
                total_filled += 1
                changed = True

        if changed:
            save_json(en_path, en_data)
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
