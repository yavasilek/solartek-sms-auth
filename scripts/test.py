import json, os, pathlib, subprocess, sys, tempfile
ROOT = pathlib.Path(__file__).resolve().parents[1]
def run(args, env=None):
    result = subprocess.run(args, cwd=ROOT, env=env, capture_output=True, text=True, encoding='utf-8', errors='replace')
    if result.returncode:
        print(result.stdout[-6000:]); print(result.stderr[-6000:]); raise SystemExit(result.returncode)
    return result.stdout
lint = list((ROOT/'plugin').rglob('*.php')) + list((ROOT/'tests').rglob('*.php'))
for file in lint: run(['php','-l',str(file)])
for file in (ROOT/'plugin').rglob('*.js'): run(['node','--check',str(file)])
results=[]
for name in ['test','antibot','security-audit','legacy-identity','identity-merge','profile','account-layout']:
    with tempfile.TemporaryDirectory(prefix='ssa-regression-') as temp:
        env={**os.environ,'MOTO_PHONE_FIXTURE_DB':str(pathlib.Path(temp)/'fixture.sqlite')}
        data=json.loads(run(['php','tests/phone-auth/'+name+'.php'],env))
        results.append({'suite':name,'checks':data.get('passed',len(data.get('checks',[])))})
for name in ['settings','updater']:
    file=ROOT/'tests'/('test-'+name+'.php')
    if file.exists(): results.append(json.loads(run(['php',str(file)])))
print(json.dumps({'ok':True,'php_lint':len(lint),'results':results},ensure_ascii=False))

