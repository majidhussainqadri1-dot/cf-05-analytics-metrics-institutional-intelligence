#!/usr/bin/env python3
from __future__ import annotations
import hashlib,json,pathlib,zipfile
root=pathlib.Path(__file__).resolve().parents[1]
dist=root/'build'/'dist'; dist.mkdir(parents=True,exist_ok=True)
slug='sabri-analytics-institutional-intelligence'; version='1.0.0-rc.2'
archive=dist/f'CF-05-{slug}-{version}.zip'
include_roots=['assets','contracts','src']
files=[root/'sabri-analytics-institutional-intelligence.php',root/'readme.txt',root/'README.md',root/'CHANGELOG.md',root/'MANIFEST.json',root/'SECURITY.md',root/'uninstall.php']
for name in include_roots:
    files.extend(p for p in (root/name).rglob('*') if p.is_file())
files=sorted(set(files),key=lambda p:p.as_posix())
with zipfile.ZipFile(archive,'w',compression=zipfile.ZIP_DEFLATED,compresslevel=9) as z:
    for p in files:
        rel=pathlib.Path(slug)/p.relative_to(root)
        info=zipfile.ZipInfo(rel.as_posix(),(2026,8,5,0,0,0)); info.compress_type=zipfile.ZIP_DEFLATED; info.external_attr=0o100644<<16
        z.writestr(info,p.read_bytes(),compress_type=zipfile.ZIP_DEFLATED,compresslevel=9)
sha=hashlib.sha256(archive.read_bytes()).hexdigest()
(archive.with_suffix(archive.suffix+'.sha256')).write_text(f'{sha}  {archive.name}\n')
components=[]
for p in files:
    components.append({'type':'file','name':p.relative_to(root).as_posix(),'hashes':[{'alg':'SHA-256','content':hashlib.sha256(p.read_bytes()).hexdigest()}]})
sbom={'bomFormat':'CycloneDX','specVersion':'1.5','serialNumber':'urn:uuid:cf05-analytics-1-0-0-rc-2','version':1,'metadata':{'component':{'type':'application','name':slug,'version':version}},'components':components}
(dist/f'CF-05-{version}-sbom.cdx.json').write_text(json.dumps(sbom,indent=2,ensure_ascii=False)+'\n')
evidence={'module':'CF-05','version':version,'archive':archive.name,'sha256':sha,'file_count':len(files),'deterministic_timestamp':'2026-08-05T00:00:00Z','staging_accepted':False,'live_deployed':False,'operational':False}
(dist/f'CF-05-{version}-package-manifest.json').write_text(json.dumps(evidence,indent=2)+'\n')
print(archive); print(sha)
