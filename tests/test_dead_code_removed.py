#!/usr/bin/env python3
"""Do not ship the former no-op tracking database/reporting subsystem."""
from __future__ import annotations

import pathlib


ROOT = pathlib.Path(__file__).resolve().parents[1]


def main() -> int:
    for relative in ("src/tracker_db.cpp", "include/tracker_db.h", "src/tracking.cpp", "include/tracking.h"):
        if (ROOT / relative).exists():
            raise AssertionError(f"unused stub subsystem still present: {relative}")
    makefile = (ROOT / "Makefile").read_text(encoding="utf-8")
    if "tracker_db.cpp" in makefile or "tracking.cpp" in makefile:
        raise AssertionError("unused tracker sources are still compiled")
    main_source = (ROOT / "src/main.cpp").read_text(encoding="utf-8")
    if "tracker_db" in main_source or "send_periodic_reports" in main_source:
        raise AssertionError("main still references no-op reporting code")
    print("dead code removal tests ok")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
