import re, io, difflib, sys

en = io.open(r"E:\GhRepo\leantime-forked\CLAUDE.md", encoding="utf-8").read().splitlines()
zh = io.open(r"E:\GhRepo\leantime-forked\.docs\initial-0910\CLAUDE.zh-CN.md", encoding="utf-8").read().splitlines()

out = []
def p(*a):
    out.append(" ".join(str(x) for x in a))

ident = re.compile(r'`([^`\n]+)`')

# 1) find best constant offset using backticked identifier tokens
def tok(line):
    return set(ident.findall(line))

best = None
for off in range(-5, 6):
    score = 0
    n = 0
    for i in range(len(en)):
        j = i + off
        if 0 <= j < len(zh):
            a = tok(en[i])
            if a:
                n += 1
                score += len(a & tok(zh[j])) / len(a)
    if n:
        r = score / n
        if best is None or r > best[1]:
            best = (off, r, n)
p("best constant offset en->zh:", best)

# 2) per-line comparison with that offset, report backticked tokens in EN missing in ZH
off = best[0]
for i in range(len(en)):
    j = i + off
    if not (0 <= j < len(zh)):
        p("LINE en %d: no zh counterpart" % (i + 1))
        continue
    a, b = en[i], zh[j]
    a_t, b_t = tok(a), tok(b)
    miss = [t for t in a_t if t not in b_t]
    if miss:
        p("EN %d / ZH %d MISSING-IDENT %r" % (i + 1, j + 1, miss))
        p("   EN:", a[:180])
        p("   ZH:", b[:180])

# 3) blocks (paragraphs / list items) present in EN but with no plausible zh match
def blocks(lines):
    bl = []
    cur = []
    for k, l in enumerate(lines):
        if l.strip() == "":
            if cur:
                bl.append((cur[0][0], "\n".join(x[1] for x in cur)))
                cur = []
        else:
            cur.append((k + 1, l))
    if cur:
        bl.append((cur[0][0], "\n".join(x[1] for x in cur)))
    return bl

eb = blocks(en)
zb = blocks(zh)
p("en blocks", len(eb), "zh blocks", len(zb))

def sig(t):
    return sorted(set(re.findall(r'[A-Za-z_][A-Za-z0-9_\\\.\*\{\}\-]{2,}', t)))

sm = difflib.SequenceMatcher(None, [sig(t) for _, t in eb], [sig(t) for _, t in zb], autojunk=False)
for tag, i1, i2, j1, j2 in sm.get_opcodes():
    if tag == "equal":
        continue
    p("BLOCK %s en[%d-%d] zh[%d-%d]" % (tag, eb[i1][0] if i1 < len(eb) else -1,
                                        eb[i2 - 1][0] if i2 - 1 < len(eb) and i2 > i1 else -1,
                                        zb[j1][0] if j1 < len(zb) else -1,
                                        zb[j2 - 1][0] if j2 - 1 < len(zb) and j2 > j1 else -1))
    for k in range(i1, min(i2, i1 + 6)):
        p("   EN-L%d:" % eb[k][0], eb[k][1][:160].replace("\n", " | "))
    for k in range(j1, min(j2, j1 + 6)):
        p("   ZH-L%d:" % zb[k][0], zb[k][1][:160].replace("\n", " | "))

io.open(r"E:\GhRepo\leantime-forked\.docs\initial-0910\_qa_out.txt", "w", encoding="utf-8").write("\n".join(out))
print("done", len(out), "report lines")
