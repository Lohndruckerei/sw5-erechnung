import sys, re
from saxonche import PySaxonProcessor
xslt, files = sys.argv[1], sys.argv[2:]
with PySaxonProcessor(license=False) as p:
    x = p.new_xslt30_processor().compile_stylesheet(stylesheet_file=xslt)
    for f in files:
        out = x.transform_to_string(source_file=f)
        fails = re.findall(r'<svrl:failed-assert[^>]*?id="([^"]+)"[^>]*?flag="([^"]+)".*?<svrl:text>(.*?)</svrl:text>', out, re.S)
        print(f, "->", "OK (keine Fehler/Warnungen)" if not fails else "")
        for i, flag, t in fails: print("  ", flag, i, " ".join(t.split())[:220])
