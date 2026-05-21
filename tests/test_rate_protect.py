#!/usr/bin/env python3
from __future__ import annotations

import pathlib
import subprocess
import tempfile


ROOT = pathlib.Path(__file__).resolve().parents[1]


def main() -> int:
    source = r'''
#include "rate_protect.h"
#include <iostream>

int main() {
    RateProtector rp;
    rp.init(2, 60, 2);
    if (!rp.check("a")) return 1;
    if (!rp.check("a")) return 2;
    if (rp.check("a")) return 3;
    if (!rp.check("b")) return 4;
    if (!rp.check("c")) return 5;
    rp.reset("a");
    if (!rp.check("a")) return 6;
    return 0;
}
'''
    with tempfile.TemporaryDirectory() as tmp:
        tmp_path = pathlib.Path(tmp)
        test_cpp = tmp_path / "rate_protect_test.cpp"
        binary = tmp_path / "rate_protect_test"
        test_cpp.write_text(source, encoding="utf-8")
        subprocess.check_call([
            "g++",
            "-std=c++11",
            "-Wall",
            "-I",
            str(ROOT / "include"),
            str(test_cpp),
            str(ROOT / "src" / "rate_protect.cpp"),
            "-o",
            str(binary),
        ])
        subprocess.check_call([str(binary)])
    print("rate_protect tests ok")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
