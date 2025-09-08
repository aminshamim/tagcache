import { describe, it, expect } from 'vitest';

function calcHitRatio(hits: number, misses: number) {
  if (hits + misses === 0) return 0;
  return hits / (hits + misses);
}

describe('calcHitRatio', () => {
  it('returns 0 when no traffic', () => {
    expect(calcHitRatio(0,0)).toBe(0);
  });
  it('returns correct ratio', () => {
    expect(calcHitRatio(2,2)).toBe(0.5);
  });
});
