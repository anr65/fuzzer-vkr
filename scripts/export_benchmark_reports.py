#!/usr/bin/env python3
"""Export docker-output/benchmark/* into benchmark-reports/ for git + visualization."""
from __future__ import annotations

import csv
import json
import os
import re
from datetime import datetime, timezone
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SRC = ROOT / "docker-output" / "benchmark"
OUT = ROOT / "benchmark-reports"

VARIANTS = [
    {
        "dir": "optimal-weights",
        "title": "Optimal mutator weights",
        "git_branch": "feature/optimal-mutator-weights",
    },
    {
        "dir": "mutator-combinations",
        "title": "Mutator combinations",
        "git_branch": "feature/mutator-combinations",
    },
    {
        "dir": "adaptive-mutations",
        "title": "Adaptive mutations",
        "git_branch": "feature/adaptive-mutations",
    },
    {
        "dir": "mutator-optimizations",
        "title": "Mutator optimizations (matator-optimizations)",
        "git_branch": "matator-optimizations",
    },
]

NEW_RE = re.compile(
    r"NEW\s+run:\s+(\d+)\s+\(\s*([\d.]+)/s\),\s*ft:\s+(\d+).*?t:\s+(\d+)s,\s*mem:\s+(\d+)mb"
)
ENTRYPOINT_RE = re.compile(
    r"^\[([^\]]+)\]\s+entrypoint:\s+(.+)$"
)
MEM_ABORT_RE = re.compile(r"Memory limit of (\d+) MB exceeded")


def load_stability_rows(path: Path) -> tuple[list[str], list[list[str]]]:
    with path.open(newline="", encoding="utf-8", errors="replace") as f:
        r = csv.reader(f)
        rows = list(r)
    if not rows:
        return [], []
    return rows[0], rows[1:]


def stability_peaks(header: list[str], data: list[list[str]]) -> dict:
    def col(name: str) -> list[float]:
        try:
            i = header.index(name)
        except ValueError:
            return []
        out = []
        for row in data:
            if i < len(row):
                try:
                    out.append(float(row[i]))
                except ValueError:
                    pass
        return out

    runs = col("runs")
    uf = col("unique_features")
    return {
        "peak_runs": int(max(runs)) if runs else None,
        "peak_unique_features": int(max(uf)) if uf else None,
        "row_count": len(data),
    }


def write_interval_metrics(header: list[str], data: list[list[str]], dest: Path) -> None:
    dest.parent.mkdir(parents=True, exist_ok=True)
    with dest.open("w", newline="", encoding="utf-8") as f:
        w = csv.writer(f)
        w.writerow(header)
        w.writerows(data)


def write_viz_runs_features(header: list[str], data: list[list[str]], dest: Path) -> None:
    keys = ["timestamp", "runs", "unique_features", "corpus_size"]
    idx = {}
    for k in keys:
        try:
            idx[k] = header.index(k)
        except ValueError:
            idx[k] = None
    with dest.open("w", newline="", encoding="utf-8") as f:
        w = csv.writer(f)
        w.writerow(keys)
        for row in data:
            w.writerow([(row[idx[k]] if idx[k] is not None and idx[k] < len(row) else "") for k in keys])


def parse_fuzzer_log(log_path: Path, max_samples: int = 3000) -> dict:
    events: list[dict] = []
    new_raw: list[dict] = []
    mem_aborts = 0
    if not log_path.is_file():
        return {
            "events": events,
            "new_samples": new_raw,
            "mem_aborts": 0,
            "new_line_count": 0,
            "progress_sample_stride": 1,
        }
    with log_path.open(encoding="utf-8", errors="replace") as f:
        for line in f:
            line = line.rstrip("\n")
            m = ENTRYPOINT_RE.match(line)
            if m:
                ts, msg = m.group(1), m.group(2)
                ev: dict = {"iso_time": ts, "message": msg}
                if "exit 42" in msg:
                    ev["kind"] = "memory_limit_restart"
                elif "code 255" in msg:
                    ev["kind"] = "exit_255_restart"
                else:
                    ev["kind"] = "supervisor_restart"
                events.append(ev)
            if MEM_ABORT_RE.search(line):
                mem_aborts += 1
            nm = NEW_RE.search(line)
            if nm:
                new_raw.append(
                    {
                        "run": int(nm.group(1)),
                        "exec_per_s": float(nm.group(2)),
                        "features_total": int(nm.group(3)),
                        "session_wall_s": int(nm.group(4)),
                        "rss_mb": int(nm.group(5)),
                    }
                )
    n = len(new_raw)
    step = max(1, (n + max_samples - 1) // max_samples) if n > max_samples else 1
    new_samples = new_raw[::step]
    return {
        "events": events,
        "new_samples": new_samples,
        "mem_aborts": mem_aborts,
        "new_line_count": n,
        "progress_sample_stride": step,
    }


def write_jsonl(events: list[dict], dest: Path) -> None:
    with dest.open("w", encoding="utf-8") as f:
        for e in events:
            f.write(json.dumps(e, ensure_ascii=False) + "\n")


def write_progress_sample(samples: list[dict], dest: Path) -> None:
    with dest.open("w", newline="", encoding="utf-8") as f:
        w = csv.DictWriter(
            f,
            fieldnames=["run", "exec_per_s", "features_total", "session_wall_s", "rss_mb"],
        )
        w.writeheader()
        w.writerows(samples)


def main() -> None:
    generated_at = datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")
    index_variants: list[dict] = []

    for v in VARIANTS:
        d = v["dir"]
        src_dir = SRC / d
        dst = OUT / d
        dst.mkdir(parents=True, exist_ok=True)

        stab = src_dir / "stability.csv"
        flog = src_dir / "fuzzer.log"

        header, data = load_stability_rows(stab) if stab.is_file() else ([], [])
        peaks = stability_peaks(header, data) if data else {}

        parsed = parse_fuzzer_log(flog, max_samples=3000)
        step = parsed["progress_sample_stride"]

        if header and data:
            write_interval_metrics(header, data, dst / "interval_metrics.csv")
            write_viz_runs_features(header, data, dst / "viz_runs_vs_features.csv")

        write_jsonl(parsed["events"], dst / "supervisor_events.jsonl")
        write_progress_sample(parsed["new_samples"], dst / "progress_sample.csv")

        crashes = sorted(src_dir.glob("crash-*.txt")) if src_dir.is_dir() else []
        summary = {
            "variant_dir": d,
            "title": v["title"],
            "git_branch": v["git_branch"],
            "generated_at_utc": generated_at,
            "source_stability_csv": str(stab.relative_to(ROOT)) if stab.is_file() else None,
            "source_fuzzer_log": str(flog.relative_to(ROOT)) if flog.is_file() else None,
            "stability_interval_metrics_rows": peaks.get("row_count"),
            "peak_runs": peaks.get("peak_runs"),
            "peak_unique_features": peaks.get("peak_unique_features"),
            "fuzzer_log_new_lines_total": parsed["new_line_count"],
            "progress_sample_rows": len(parsed["new_samples"]),
            "progress_sample_stride": step,
            "supervisor_events": len(parsed["events"]),
            "memory_soft_abort_messages": parsed["mem_aborts"],
            "crash_inputs_saved": [c.name for c in crashes],
        }
        (dst / "summary.json").write_text(
            json.dumps(summary, indent=2, ensure_ascii=False) + "\n", encoding="utf-8"
        )

        log_lines = [
            f"# Benchmark log — {v['title']}",
            f"Folder: benchmark-reports/{d}/",
            f"Git branch (build context): {v['git_branch']}",
            f"Export UTC: {generated_at}",
            "",
            "## Interval metrics (stability logger)",
            f"Rows in interval_metrics.csv: {peaks.get('row_count', 0)}",
            f"Peak runs: {peaks.get('peak_runs')}",
            f"Peak unique_features (edges): {peaks.get('peak_unique_features')}",
            "",
            "## Fuzzer process log (aggregate)",
            f"Lines starting with NEW (total): {parsed['new_line_count']}",
            f"progress_sample.csv: {len(parsed['new_samples'])} rows (stride every ~{step} NEW lines)",
            f"Supervisor / entrypoint events: {len(parsed['events'])}",
            f"Soft memory abort messages (1024 MB): {parsed['mem_aborts']}",
            "",
            "## Supervisor events",
        ]
        for ev in parsed["events"]:
            log_lines.append(f"  - {ev.get('iso_time')}: {ev.get('message')}")
        if not parsed["events"]:
            log_lines.append("  (none)")
        log_lines += ["", "## Crash inputs in docker-output (filenames only)", ""]
        log_lines += [f"  - {c.name}" for c in crashes] or ["  (none)"]
        log_lines.append("")
        (dst / "BENCHMARK_LOG.txt").write_text("\n".join(log_lines), encoding="utf-8")

        index_variants.append({"id": d, "summary": summary})

    # Master log
    master = [
        "# Master benchmark log (all four Docker benchmark variants)",
        f"Generated UTC: {generated_at}",
        "",
        "This export was produced from `docker-output/benchmark/*` (not committed) by",
        "`scripts/export_benchmark_reports.py`. Regenerate after new runs.",
        "",
        "## Layout",
        "",
        "| Folder | Branch / role |",
        "|--------|----------------|",
    ]
    for v in VARIANTS:
        master.append(f"| `{v['dir']}/` | `{v['git_branch']}` |")
    master += [
        "",
        "## Files (per variant)",
        "",
        "- `interval_metrics.csv` — full stability logger CSV (all columns, plotting time series).",
        "- `viz_runs_vs_features.csv` — slim CSV: timestamp, runs, unique_features, corpus_size.",
        "- `progress_sample.csv` — downsampled `NEW` lines from fuzzer.log (run rate, RSS, session wall s).",
        "- `supervisor_events.jsonl` — memory / exit-255 restarts from entrypoint (one JSON per line).",
        "- `summary.json` — machine-readable peaks and counts.",
        "- `BENCHMARK_LOG.txt` — human-readable log for this variant.",
        "",
        "## Cross-variant peaks (from interval metrics)",
        "",
        "| Variant | peak runs | peak unique_features |",
        "|---------|-----------|----------------------|",
    ]
    for iv in index_variants:
        s = iv["summary"]
        master.append(
            f"| {iv['id']} | {s.get('peak_runs')} | {s.get('peak_unique_features')} |"
        )
    master.append("")
    OUT.mkdir(parents=True, exist_ok=True)
    (OUT / "BENCHMARK_MASTER_LOG.md").write_text("\n".join(master), encoding="utf-8")
    (OUT / "README.md").write_text(
        "\n".join(
            [
                "# Benchmark reports (visualization export)",
                "",
                "Run `python3 scripts/export_benchmark_reports.py` after Docker benchmark runs.",
                "",
                "See `BENCHMARK_MASTER_LOG.md` for the consolidated log and column hints.",
                "",
            ]
        ),
        encoding="utf-8",
    )
    (OUT / "index.json").write_text(
        json.dumps(
            {"generated_at_utc": generated_at, "variants": index_variants},
            indent=2,
            ensure_ascii=False,
        )
        + "\n",
        encoding="utf-8",
    )


if __name__ == "__main__":
    main()
