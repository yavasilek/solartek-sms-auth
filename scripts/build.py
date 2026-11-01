import hashlib, json, pathlib, re, subprocess, zipfile
ROOT=pathlib.Path(__file__).resolve().parents[1]
PLUGIN=ROOT/'plugin/solartek-sms-auth'
source=(PLUGIN/'solartek-sms-auth.php').read_text(encoding='utf-8')
version=re.search(r'^ \* Version: ([0-9.]+)$',source,re.M)[1]
assert "define('SSA_VERSION', '"+version+"');" in source
out=ROOT/'dist';out.mkdir(exist_ok=True)
archive=out/'solartek-sms-auth.zip'
with zipfile.ZipFile(archive,'w',compression=zipfile.ZIP_DEFLATED,compresslevel=9) as target:
    for file in sorted(PLUGIN.rglob('*'), key=lambda p:p.relative_to(PLUGIN).as_posix()):
        if not file.is_file():continue
        assert file.suffix in ['.php','.js','.css','.ttf','.txt'],file
        name='solartek-sms-auth/'+file.relative_to(PLUGIN).as_posix()
        info=zipfile.ZipInfo(name,(2026,1,1,0,0,0));info.create_system=3;info.compress_type=zipfile.ZIP_DEFLATED;info.external_attr=0o100644<<16
        target.writestr(info,file.read_bytes(),compress_type=zipfile.ZIP_DEFLATED,compresslevel=9)
sha=hashlib.sha256(archive.read_bytes()).hexdigest()
revision=subprocess.run(['git','rev-parse','HEAD'],cwd=ROOT,capture_output=True,text=True)
commit=revision.stdout.strip() if revision.returncode==0 else 'uncommitted'
manifest={'slug':'solartek-sms-auth','version':version,'requires':'6.5','requires_php':'8.1','sha256':sha,'size':archive.stat().st_size,'commit':commit}
(out/'release.json').write_text(json.dumps(manifest,indent=2)+'\n',encoding='utf-8')
(out/'SHA256SUMS').write_text(sha+'  solartek-sms-auth.zip\n',encoding='ascii')
print(json.dumps(manifest))

