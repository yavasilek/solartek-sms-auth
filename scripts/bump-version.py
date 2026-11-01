import os,pathlib,re,subprocess
root=pathlib.Path(__file__).resolve().parents[1];entry=root/'plugin/solartek-sms-auth/solartek-sms-auth.php';source=entry.read_text(encoding='utf-8')
current=re.search(r'^ \* Version: (\d+\.\d+\.\d+)$',source,re.M)[1]
key=lambda v:tuple(map(int,v.split('.')))
tags=subprocess.check_output(['git','tag','--list','v*'],cwd=root,text=True).splitlines();versions=[t[1:] for t in tags if re.fullmatch(r'v\d+\.\d+\.\d+',t)]
latest=max(versions,key=key) if versions else None
version=current
if latest and key(current)<=key(latest):
    major,minor,patch=key(latest);version=f'{major}.{minor}.{patch+1}'
source=source.replace(' * Version: '+current,' * Version: '+version).replace("define('SSA_VERSION', '"+current+"');","define('SSA_VERSION', '"+version+"');")
entry.write_text(source,encoding='utf-8')
if os.getenv('GITHUB_OUTPUT'):
    with open(os.environ['GITHUB_OUTPUT'],'a',encoding='utf-8') as file:file.write('version='+version+'\n')
print(version)

