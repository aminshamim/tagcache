# Massive Hot-Set TCP Workload Test

This document explains the `tcp_massive_dataset_hotset` performance test, how to run it safely, interpret its output, and tune parameters to maximize hit rate while sustaining high write and invalidation throughput.

## Purpose
Simulate extremely large key populations ("lakh" scale) with a skewed access pattern:
- Large total key space (cold + warm + hot tiers)
- High read ratio favoring a hot subset
- Ongoing writes updating both hot and selected warm keys
- Periodic tag invalidations plus targeted repair to preserve locality
- Optional self-heal on GET miss

## Command Example
```
MASSIVE=1 TOTAL_KEYS=200000 VALUE_SIZE=10000 HOT_RATIO=0.05 \
CONNS=16 PIPELINE_DEPTH=64 GET_PCT=85 PUT_PCT=10 INV_PCT=5 MISS_PCT=0 \
REPOP_PER_INVALIDATE=8 REPAIR_MODE=targeted REPAIR_LIMIT=512 MIN_HIT_RATIO=0.85 \
WARM_PUT_PCT=20 SELF_HEAL_ON_MISS=1 DURATION_SECS=30 SERVER_ADDR=127.0.0.1:1984 \
cargo test --release --test perf_tests -- tcp_massive_dataset_hotset --ignored --nocapture
```

## Example Output (before tuning for higher hit ratio)
```
[massive] server_addr=127.0.0.1:1984 total_keys=200000 (~1.86 GB payload) value_size=10000 hot_keys=10000 tag_mod=1000 conns=16 pipeline_depth=64
[massive] mix: GET=85 PUT=10 (warm_put_pct=20%) INV=5 miss_pct=0 repop_per_inval=8 repair_mode=targeted repair_limit=512 self_heal_on_miss=true min_hit_ratio=0.85
[massive] prepopulation complete: keys=200000 time_s=0.76 rate=261785 puts/s
[massive][progress] elapsed_s=5  total_ops=1598232 int_ops=1598232 int_ops_per_sec=319366 hit_ratio=0.6365
[massive][progress] elapsed_s=10 total_ops=3201142 int_ops=1602910 int_ops_per_sec=320297 hit_ratio=0.6363
[massive][progress] elapsed_s=15 total_ops=4804137 int_ops=1602995 int_ops_per_sec=320492 hit_ratio=0.6362
[massive][progress] elapsed_s=20 total_ops=6386123 int_ops=1581986 int_ops_per_sec=316293 hit_ratio=0.6367
[massive][progress] elapsed_s=25 total_ops=7984219 int_ops=1598096 int_ops_per_sec=319361 hit_ratio=0.6365
[massive][progress] elapsed_s=30 total_ops=9564104 int_ops=1579885 int_ops_per_sec=315658 hit_ratio=0.6365
[massive][summary] duration_s=30 total_ops=9564104 get_ops=6183992 put_ops=3016208 inv_tag_ops=363904 hits=3936259 misses=2247733 hit_ratio=0.6365
```
The test failed because the achieved hit ratio (0.6365) was below the target MIN_HIT_RATIO=0.85.

## Why The Hit Ratio Fell Short
1. Hot set size HOT_RATIO=0.05 produced 10,000 hot keys; invalidations (5%) continuously purge segments before fully repaired.
2. REPAIR_LIMIT=512 may be insufficient vs. invalidation churn × CONNS × INV_PCT; some hot keys remain cold each cycle.
3. PUT_PCT=10 spreads write bandwidth between hot and warm updates; not all invalidated hot keys are promptly restored.
4. WARM_PUT_PCT=20 diverts a portion of write budget away from strictly reinforcing hot keys.
5. Self-heal on miss originally not inserting (prior version); new code now heals misses immediately, which will improve ratio gradually if misses occur early.

## Key Environment Variables
- TOTAL_KEYS: Total key population. Memory usage ≈ TOTAL_KEYS * VALUE_SIZE (plus overhead). Start small.
- VALUE_SIZE: Bytes per value. Large values rapidly magnify RAM usage.
- HOT_RATIO: Fraction of keys forming hot set (primary read focus). Lower value concentrates hits, but increases sensitivity to invalidations.
- GET_PCT / PUT_PCT / INV_PCT: Operation mix. More invalidations lower hit rate unless repair capacity increases.
- REPOP_PER_INVALIDATE / REPAIR_MODE / REPAIR_LIMIT: Control post-invalidation repair intensity.
- WARM_PUT_PCT: Portion of PUTs refreshing non-hot keys; improves broader residency but competes with hot repairs.
- PIPELINE_DEPTH: Batch size; increases throughput but can cause bursty invalidation effects before repairs land.
- SELF_HEAL_ON_MISS: When enabled, each GET miss triggers on-the-fly PUT to reseed the key.
- MIN_HIT_RATIO: Assertion threshold; adjust as you experiment.

## Tuning Strategies to Raise Hit Ratio
1. Reduce INV_PCT (e.g. from 5 to 2-3) to decrease hot eviction frequency.
2. Increase REPAIR_LIMIT (e.g. 2048 or 4096) so each invalidation repairs more of its group immediately.
3. Increase PUT_PCT modestly (e.g. 12–15) to allocate more bandwidth to restoration.
4. Lower WARM_PUT_PCT (e.g. 5–10) so more writes reinforce hot set stability.
5. Slightly raise HOT_RATIO (e.g. 0.07–0.10) if your memory budget allows; larger hot set dilutes per-tag invalidation impact.
6. Enable/keep SELF_HEAL_ON_MISS=1; observed misses will replenish quickly.
7. If still low: set REPAIR_MODE=random for broader scatter, or combine targeted + higher REPOP_PER_INVALIDATE.

## Recommended Next Attempt
```
MASSIVE=1 TOTAL_KEYS=200000 VALUE_SIZE=10000 HOT_RATIO=0.05 \
CONNS=16 PIPELINE_DEPTH=64 GET_PCT=85 PUT_PCT=12 INV_PCT=3 MISS_PCT=0 \
REPOP_PER_INVALIDATE=16 REPAIR_MODE=targeted REPAIR_LIMIT=4096 MIN_HIT_RATIO=0.80 \
WARM_PUT_PCT=5 SELF_HEAL_ON_MISS=1 DURATION_SECS=30 SERVER_ADDR=127.0.0.1:1984 \
cargo test --release --test perf_tests -- tcp_massive_dataset_hotset --ignored --nocapture
```
Adjust MIN_HIT_RATIO downward temporarily while searching for stable parameters, then raise it again once consistent.

## Interpreting Progress Lines
- total_ops: Cumulative operations (GET+PUT+INV + any repair/self-heal puts included when responses read).
- int_ops / int_ops_per_sec: Throughput in the last interval (5s bucket).
- hit_ratio: Rolling cumulative hits / (hits + misses) observed locally in the test harness.

## Failure Modes & Mitigations
| Symptom | Likely Cause | Mitigation |
|---------|--------------|-----------|
| Hit ratio drifting downward steadily | Repair backlog; invalidations outpace PUT repair | Raise REPAIR_LIMIT, decrease INV_PCT, raise PUT_PCT |
| Memory exhaustion / OOM | TOTAL_KEYS * VALUE_SIZE too large | Scale down TOTAL_KEYS first; validate throughput, then scale up |
| Low throughput | PIPELINE_DEPTH too small, CONNS too few, CPU saturated | Increase pipeline or connections; check CPU/IO metrics |
| High misses early then improves | Self-heal ON; warm population still forming | Allow warm-up period or pre-populate larger hot set |

## Implementation Notes
- GET miss self-heal sends an immediate PUT of that key, increasing put_ops and total_ops.
- Targeted repair enumerates tag group keys via stride of TAG_MOD; random mode samples across hot/warm sets.
- Hit ratio assertion is performed after workload completion (not sliding window).

## Safety Checklist Before Large Runs
1. Confirm available RAM: target peak usage < 70% of system memory.
2. Start with TOTAL_KEYS ≤ 50k to validate stability.
3. Increase gradually (50k → 100k → 200k → 500k → 2M) while monitoring memory and hit ratio.
4. Adjust MIN_HIT_RATIO only after parameter tuning.

## Future Enhancements (TODO)
- Track per-tag invalidation & repair efficiency metrics.
- Latency histograms per op type (GET/PUT/INV) for tail analysis.
- Adaptive controller adjusting REPAIR_LIMIT and PUT_PCT dynamically to hold target hit ratio.
- Export JSON summary for automated CI dashboards.

---
Maintainer Tip: Keep this document updated when workload logic changes to avoid stale guidance.
