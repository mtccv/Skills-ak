#!/usr/bin/env python3
"""Bounded, measured HTTP latency on the dedicated synthetic local QA site."""
import argparse
import concurrent.futures
import json
import math
import os
import platform
import statistics
import time
import urllib.error
import urllib.parse
import urllib.request


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--base", default="http://localhost:18087")
    parser.add_argument("--fixture", required=True)
    parser.add_argument("--output", required=True)
    parser.add_argument("--requests", type=int, default=12)
    args = parser.parse_args()
    target = urllib.parse.urlsplit(args.base)
    if target.scheme != "http" or target.hostname not in ("localhost", "127.0.0.1") or not 4 <= args.requests <= 40:
        raise SystemExit("Only bounded requests to the dedicated local HTTP target are allowed")
    with open(args.fixture, encoding="utf-8") as stream:
        fixture = json.load(stream)
    fixture = fixture.get("fixture", fixture)
    routes = {
        "home_en": "/en/",
        "home_zh": "/zh/",
        "list": "/en/skills/",
        "detail": f"/en/skills/{fixture['last_id']}/{fixture['last_slug']}/",
        "search": "/en/search/?q=benchmark",
        "combined": "/en/skills/?" + urllib.parse.urlencode({"q": "benchmark", "category": fixture["category"], "tag": fixture["tag"], "platform": fixture["platform"]}),
        "pagination": "/en/skills/?page=20",
        "tutorials": "/en/tutorials/",
    }

    def fetch(path):
        opener = urllib.request.build_opener(urllib.request.ProxyHandler({}))
        request = urllib.request.Request(args.base + path, headers={"User-Agent": "AI-Skills-Hub-Final-QA-Performance"})
        started = time.perf_counter()
        try:
            try:
                response = opener.open(request, timeout=45)
            except urllib.error.HTTPError as error:
                response = error
            with response:
                body = response.read(4 * 1024 * 1024)
                status = response.status
            diagnostic = b"Fatal error" in body or b"WordPress database error" in body or b"<b>Warning</b>" in body
            return {"ms": round((time.perf_counter() - started) * 1000, 3), "status": status, "bytes": len(body), "diagnostic": diagnostic}
        except (OSError, urllib.error.URLError) as error:
            return {"ms": round((time.perf_counter() - started) * 1000, 3), "status": 0, "bytes": 0, "diagnostic": False, "error_type": type(error).__name__}

    groups = []
    for name, path in routes.items():
        warmup = fetch(path)
        for concurrency in (1, 4):
            started = time.perf_counter()
            with concurrent.futures.ThreadPoolExecutor(max_workers=concurrency) as workers:
                samples = list(workers.map(fetch, [path] * args.requests))
            elapsed = time.perf_counter() - started
            values = sorted(sample["ms"] for sample in samples)
            successful = sum(sample["status"] == 200 and not sample["diagnostic"] for sample in samples)
            groups.append({"route": name, "path": path, "concurrency": concurrency, "requests": len(samples), "successful": successful,
                           "warmup_status": warmup["status"], "wall_seconds": round(elapsed, 4), "observed_completed_requests_per_second": round(len(samples) / elapsed, 3),
                           "latency_ms": {"median": round(statistics.median(values), 3), "mean": round(statistics.mean(values), 3), "p95_nearest_rank": values[math.ceil(len(values) * .95) - 1], "max": max(values)}, "samples": samples})

    def local_file(path):
        try:
            with open(path, encoding="utf-8") as stream:
                return stream.read(256).strip()
        except OSError:
            return None

    result = {"kind": "actual_bounded_local_http_measurement", "base": args.base, "fixture": fixture,
              "runner_environment": {"platform": platform.platform(), "cpu_count": os.cpu_count(), "cpu_affinity_count": len(os.sched_getaffinity(0)) if hasattr(os, "sched_getaffinity") else None, "cgroup_cpu_max": local_file("/sys/fs/cgroup/cpu.max"), "cgroup_memory_max": local_file("/sys/fs/cgroup/memory.max")},
              "groups": groups, "total_measured_requests": sum(group["requests"] for group in groups), "successful_requests": sum(group["successful"] for group in groups),
              "limits": ["Synthetic QA corpus; not real visitors or a production capacity guarantee.", "Concurrency levels 1 and 4; one untimed warmup per route; no cold-cache claim.", "Latency includes local HTTP transport and complete HTML response; no browser rendering or external network.", "Observed request rate is measured count/wall time for each short group, not a maximum throughput estimate.", "Other QA containers may share host resources; runner CPU/memory reporting is not a dedicated-server allocation."]}
    with open(args.output, "w", encoding="utf-8") as stream:
        json.dump(result, stream, ensure_ascii=False, indent=2)
    print(json.dumps({key: result[key] for key in ("total_measured_requests", "successful_requests")}))
    raise SystemExit(0 if result["successful_requests"] == result["total_measured_requests"] else 1)


if __name__ == "__main__":
    main()
