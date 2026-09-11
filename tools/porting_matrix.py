import subprocess, collections, re, json

REPO = {'vtos':'/home/claude/upstream/vtos','wb':'/home/claude/upstream/wunderbyte','ralf':'/home/claude/upstream/ralf'}
REFS = [('UP','vtos','origin/MOODLE_500'),('UP404','vtos','origin/MOODLE_404'),('ALISE','wb','origin/alise_adaptivequiz'),
        ('V300','wb','origin/version_3.0.0'),('ACV3','wb','origin/adaptive-catquiz-v3'),
        ('FORK','ralf','origin/v-3.0')]
def tree(repo, ref):
    out = subprocess.run(['git','-C',REPO[repo],'ls-tree','-r',ref],capture_output=True,text=True).stdout
    d={}
    for line in out.splitlines():
        meta,path=line.split('\t',1); d[path]=meta.split()[2]
    return d
T={k:tree(r,ref) for k,r,ref in REFS}; RO={k:r for k,r,ref in REFS}
def blob(k,p):
    s=T[k].get(p)
    return None if not s else subprocess.run(['git','-C',REPO[RO[k]],'cat-file','-p',s],capture_output=True).stdout

def diffinfo(p, left='UP'):
    a,b=blob(left,p),blob('FORK',p)
    open('/tmp/_a','wb').write(a); open('/tmp/_b','wb').write(b)
    out=subprocess.run(['diff','-u','/tmp/_a','/tmp/_b'],capture_output=True,text=True,errors='replace').stdout
    n=sum(1 for l in out.splitlines() if l[:1] in '+-' and not l.startswith(('+++','---')))
    fn=set()
    for l in out.splitlines():
        if l[:1] in '+-' and not l.startswith(('+++','---')):
            m=re.search(r'function\s+([a-zA-Z_][\w]*)\s*\(', l)
            if m: fn.add(m.group(1))
    return n, sorted(fn)[:6]

CQ=re.compile(rb'local_catquiz|catquiz_handler|catscale|adaptivequizcatmodel_catquiz|START\.SMART|\bALiSe\b',re.I)
API=re.compile(r'catmodel|subplugins\.json|plugininfo/adaptivequizcatmodel')
TXT=('.php','.js','.json','.xml','.feature','.mustache','.css','.md','.txt')

allp=sorted(set().union(*[set(v) for v in T.values()]))
rows=[]
for p in allp:
    inn={k:(p in T[k]) for k in T}
    hist=[k for k in ('ALISE','V300','ACV3') if inn[k]]
    in404=inn['UP404']
    same=inn['UP'] and inn['FORK'] and T['UP'][p]==T['FORK'][p]
    src=[k for k in hist if inn['FORK'] and T[k][p]==T['FORK'][p]]
    content=blob('FORK',p) if inn['FORK'] and p.endswith(TXT) else None
    cq=bool(content and CQ.search(content)); api=bool(API.search(p))
    n,fns=(0,[]); n404=None
    if inn['UP'] and inn['FORK'] and not same and p.endswith(TXT): n,fns=diffinfo(p)
    if inn['UP404'] and inn['FORK'] and p.endswith(TXT):
        n404 = 0 if T['UP404'][p]==T['FORK'][p] else diffinfo(p,'UP404')[0]
    if not inn['UP'] and not inn['FORK']:
        kind='nur historisch'; dec='E'; note='in keiner der beiden Zielbasen – vermutlich überholt'
    elif inn['UP'] and not inn['FORK']:
        if not inn['UP404']:
            kind='neu im 5.0-Commit'; dec='A'; note='kam mit a769068 (qbank) – 5.0-only'
        elif hist:
            kind='nur Upstream'; dec='F'; note='Fork hat diese Upstream-Datei entfernt – Grund klären'
        else:
            kind='nur Upstream'; dec='A'; note='Upstream-Datei, im Fork nie vorhanden'
    elif inn['FORK'] and not inn['UP']:
        kind='nur Fork'
        if api: dec='C'; note='Catmodel-/Subplugin-Konstrukt'
        elif cq: dec='D'; note='CATquiz-Referenzen – gehört in den Adapter'
        else: dec='B'; note='ALiSe-Ergänzung ohne CATquiz-Bezug'
    elif same:
        kind='identisch'; dec='A'; note='keine Aktion'
    else:
        kind='divergiert'
        if n404==0:
            dec='A'; note='Fork = MOODLE_404; Divergenz stammt allein aus dem 5.0-Commit'
        elif cq: dec='D'; note='CATquiz-Referenzen im Upstream-Code'
        elif api: dec='C'; note='Catmodel-Hook in Upstream-Datei'
        else: dec='F'; note='inhaltlich prüfen'
    rows.append(dict(path=p,kind=kind,dec=dec,note=note,n=n,fns=fns,src=','.join(src) or ('/'.join(hist) if hist else '-'),in404=in404,
                     only500=inn['UP'] and not inn['UP404'],n404=n404,cq=cq,api=api))
json.dump(rows,open('/tmp/rows2.json','w'))
c=collections.Counter(r['dec'] for r in rows); k=collections.Counter(r['kind'] for r in rows)
print('Dateien:',len(rows)); print('Kategorien:',dict(sorted(c.items()))); print('Arten:',dict(k))
