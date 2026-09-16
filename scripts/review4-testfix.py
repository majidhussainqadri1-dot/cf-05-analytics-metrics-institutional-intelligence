#!/usr/bin/env python3
from pathlib import Path
p=Path('tests/future40.php')
s=p.read_text(encoding='utf-8')
old="f40_truth(($r['correlation']??1)===null&&($r['reason']??'')==='zero_variance','Undefined correlation must remain unavailable.');"
new="f40_truth(array_key_exists('correlation',$r)&&$r['correlation']===null&&($r['reason']??'')==='zero_variance','Undefined correlation must remain unavailable.');"
if old not in s:
    raise SystemExit('review4 correlation assertion anchor missing')
p.write_text(s.replace(old,new,1),encoding='utf-8')
print('Review-4 zero-variance regression assertion corrected.')
